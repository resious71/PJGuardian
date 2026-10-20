<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 한 줄 JSON 로거 (명세 14, 19.7)
 *
 * 형식: {"ts","level","request_id","job_id","user_id","message","context"}
 *   - ts: UTC ISO 8601, level: ERROR | WARN | INFO
 *   - request_id(웹)와 job_id(분석 작업, 워커)로 웹과 워커 로그를 이어 볼 수 있다.
 * 파일: <logs_dir>/app-YYYYMMDD.log (UTC 날짜)
 *
 * 마스킹: 비밀번호·토큰·시크릿·Authorization·쿠키 값은 키 이름과 메시지 문자열 양쪽에서 "***"로 바꾼다.
 * 보존 기간 삭제(purgeOlderThan)는 P14의 정리 작업에서 호출한다.
 */
final class Logger
{
    private const LEVELS = ['ERROR' => 3, 'WARN' => 2, 'INFO' => 1];
    public const MASK = '***';

    /** 값이 민감한 키 이름(부분 일치, 대소문자 무시) */
    private const SENSITIVE_KEY = '/pass(word|wd|phrase)?|secret|token|authorization|cookie|api[_-]?key|encryption[_-]?key|credential|csrf|private[_-]?key/i';

    /** @var array{request_id:?string, job_id:int|string|null, user_id:int|string|null} */
    private array $context = ['request_id' => null, 'job_id' => null, 'user_id' => null];

    public function __construct(
        private readonly string $dir,
        private readonly Clock $clock,
        private readonly string $minLevel = 'INFO',
        // P30: 파일 이름 앞부분 — app(앱 로그), alert(장애 경고 로그, Services\Monitoring\SystemHealth)
        private readonly string $prefix = 'app',
    ) {
    }

    public function withRequestId(?string $id): void
    {
        $this->context['request_id'] = $id;
    }

    /** 워커가 분석 작업을 처리하는 동안 설정한다. */
    public function withJobId(int|string|null $id): void
    {
        $this->context['job_id'] = $id;
    }

    public function withUserId(int|string|null $id): void
    {
        $this->context['user_id'] = $id;
    }

    public function requestId(): ?string
    {
        return $this->context['request_id'];
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warn(string $message, array $context = []): void
    {
        $this->log('WARN', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function log(string $level, string $message, array $context = []): void
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("알 수 없는 로그 레벨: {$level}");
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->minLevel]) {
            return;
        }
        $now = $this->clock->now();
        $record = [
            'ts' => SystemClock::iso($now),
            'level' => $level,
            'request_id' => $this->context['request_id'],
            'job_id' => $this->context['job_id'],
            'user_id' => $this->context['user_id'],
            'message' => self::maskString($message),
        ];
        if ($context !== []) {
            $record['context'] = self::maskArray($context);
        }
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
        // 실패해도 요청 처리를 멈추지 않는다. PHP error_log로 대신 남긴다.
        if (@file_put_contents($this->fileFor($now), $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log('[PJGuardian] 로그 파일 쓰기 실패: ' . $line);
        }
    }

    public function fileFor(\DateTimeImmutable $t): string
    {
        return $this->dir . '/' . $this->prefix . '-' . $t->setTimezone(new \DateTimeZone('UTC'))->format('Ymd') . '.log';
    }

    /**
     * 보존 기간이 지난 로그 파일을 지운다. 지운 파일 이름 목록을 돌려준다.
     *
     * @return list<string>
     */
    public function purgeOlderThan(int $retentionSeconds): array
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d seconds', $retentionSeconds))->format('Ymd');
        $deleted = [];
        foreach (Fs::listDir($this->dir) as $name) {
            if (preg_match('/^(?:app|alert)-(\d{8})\.log$/', $name, $m) === 1 && $m[1] < $cutoff) {
                if (@unlink($this->dir . '/' . $name)) {
                    $deleted[] = $name;
                }
            }
        }
        return $deleted;
    }

    // ---- 마스킹 ----

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function maskArray(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && preg_match(self::SENSITIVE_KEY, $k) === 1) {
                $out[$k] = self::MASK;
            } elseif (is_array($v)) {
                $out[$k] = self::maskArray($v);
            } elseif (is_string($v)) {
                $out[$k] = self::maskString($v);
            } elseif (is_scalar($v) || $v === null) {
                $out[$k] = $v;
            } else {
                $out[$k] = get_debug_type($v);
            }
        }
        return $out;
    }

    /** 문자열 안의 "password=...", "token: ...", "Authorization: Bearer ...", URL의 사용자:비밀번호를 가린다. */
    public static function maskString(string $s): string
    {
        $key = '(?:pass(?:word|wd|phrase)?|secret|token|api[_-]?key|encryption[_-]?key|csrf[_-]?token|cookie|session(?:id)?|PHPSESSID)';
        // Authorization: Bearer xxx / Basic xxx (헤더 형식)
        $s = (string) preg_replace('/(authorization\s*[:=]\s*)(?:(bearer|basic|token)\s+)?[^\s,;"\']+/i', '$1$2 ' . self::MASK, $s);
        $s = (string) preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 ' . self::MASK, $s);
        // JSON 형식 "password":"..."
        $s = (string) preg_replace('/("[^"]*' . $key . '[^"]*"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"' . self::MASK . '"', $s);
        // key=value, key: value
        $s = (string) preg_replace('/(\b[\w-]*' . $key . '[\w-]*\s*[:=]\s*)[^\s&,;"\']+/i', '$1' . self::MASK, $s);
        // URL 자격 증명 scheme://user:pass@host
        $s = (string) preg_replace('#(\b[a-z][a-z0-9+.-]*://[^/\s:@]+:)[^@\s/]+@#i', '$1' . self::MASK . '@', $s);
        return $s;
    }
}
