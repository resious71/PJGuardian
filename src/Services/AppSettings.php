<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Http\ApiException;
use PJGuardian\Support\Clock;
use PJGuardian\Support\Db;
use PJGuardian\Support\SystemClock;

/**
 * 화면에서 admin이 바꾸는 전체 공통 설정(P21C, 마이그레이션 014 app_settings). docs/architecture.md 36절.
 *
 * - 허용 키·형식·범위·기본값은 SCHEMA 한 곳. 행이 없으면 기본값. 바꾸면 감사 로그 setting.update({key, from, to}).
 * - 014를 적용하기 전 DB(표 없음)에서는 읽기는 기본값, 쓰기는 503 settings_unavailable(대시보드가 깨지지 않게. 다만 업로드 요약 uploads.summary도 014라
 *   P21C 코드는 014 적용을 전제로 한다 — docs/results/P21C.md 적용 순서).
 * - 운영 설정(config.ini)과 구분: 이 표는 표시 설정만 둔다(보안·자원 한도는 config.ini).
 */
final class AppSettings
{
    /** 키 → [형식, 최소, 최대, 기본] */
    public const SCHEMA = [
        // 대시보드 "프로젝트별 추이"에 보여 줄 프로젝트 수(최근 분석 순). 기본 5 = P20부터의 고정값, 최대 50 = 차트 50개(한 화면 스크롤 한계, 가정)
        'dashboard.trend_projects' => ['int', 1, 50, 5],
        // 알림 보관(P24): 읽은 알림은 읽은 뒤 N일, 안 읽은 알림은 만든 뒤 N일이 지나면 워커 정리가 지운다. 사용자당 최대 개수는 안전망(가정)
        'notifications.retain_read_days' => ['int', 1, 365, 7],
        'notifications.retain_unread_days' => ['int', 1, 365, 30],
        'notifications.max_per_user' => ['int', 100, 10000, 1000],
    ];

    /** @var array<string, int>|null */
    private ?array $memo = null;

    public function __construct(private readonly Db $db, private readonly AuditLog $audit, private readonly Clock $clock)
    {
    }

    public function int(string $key): int
    {
        $spec = self::SCHEMA[$key] ?? throw new \InvalidArgumentException('알 수 없는 설정: ' . $key);
        $all = $this->stored();
        return $all[$key] ?? $spec[3];
    }

    /** 저장값을 다음 읽기 때 다시 읽는다(오래 도는 워커가 관리자 변경을 다음 정리부터 반영하게, P24) */
    public function refresh(): void
    {
        $this->memo = null;
    }

    /** @return array<string, array{value:int, default:int, min:int, max:int}> */
    public function all(): array
    {
        $out = [];
        foreach (self::SCHEMA as $key => [, $min, $max, $default]) {
            $out[$key] = ['value' => $this->int($key), 'default' => $default, 'min' => $min, 'max' => $max];
        }
        return $out;
    }

    /**
     * 값 바꾸기(admin 확인은 라우트 미들웨어). 같은 값이면 쓰지 않고 감사 로그도 없다.
     *
     * @param array<string, mixed> $changes 키 → 값(정수 또는 정수 문자열)
     * @return array<string, array{value:int, default:int, min:int, max:int}>
     */
    public function update(Actor $actor, ?int $userId, array $changes): array
    {
        if ($changes === []) {
            throw new ApiException(400, 'invalid_setting', [], null, [], [], ['key' => null]);
        }
        $parsed = [];
        foreach ($changes as $key => $value) {
            $spec = self::SCHEMA[$key] ?? null;
            if ($spec === null) {
                throw new ApiException(400, 'invalid_setting', [], null, [], [], ['key' => (string) $key]);
            }
            [, $min, $max] = $spec;
            $int = is_int($value) ? $value : (is_string($value) && preg_match('/^-?[0-9]{1,9}$/', $value) === 1 ? (int) $value : null);
            if ($int === null || $int < $min || $int > $max) {
                throw new ApiException(400, 'invalid_setting_value', ['min' => $min, 'max' => $max], null, [], [], ['key' => (string) $key, 'min' => $min, 'max' => $max]);
            }
            $parsed[(string) $key] = $int;
        }
        $now = SystemClock::iso($this->clock->now());
        try {
            $this->db->transaction(function () use ($actor, $userId, $parsed, $now): void {
                foreach ($parsed as $key => $value) {
                    $old = $this->db->value('SELECT value FROM app_settings WHERE key = ?', [$key]);
                    $from = $old === null ? self::SCHEMA[$key][3] : (int) $old;
                    if ($old !== null && $from === $value) {
                        continue;
                    }
                    $this->db->execute('INSERT INTO app_settings (key, value, updated_by, updated_at) VALUES (?, ?, ?, ?)'
                        . ' ON CONFLICT (key) DO UPDATE SET value = excluded.value, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
                        [$key, (string) $value, $userId, $now]);
                    if ($from !== $value) {
                        $this->audit->record($actor, 'setting.update', 'setting', $key, ['key' => $key, 'from' => $from, 'to' => $value]);
                    }
                }
            });
        } catch (\PDOException $e) {
            if (self::missingTable($e)) {
                throw new ApiException(503, 'settings_unavailable');
            }
            throw $e;
        }
        $this->memo = null;
        return $this->all();
    }

    /** @return array<string, int> 저장된 값(형식이 틀린 행은 무시 → 기본값) */
    private function stored(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $out = [];
        try {
            foreach ($this->db->all('SELECT key, value FROM app_settings') as $r) {
                $spec = self::SCHEMA[(string) $r['key']] ?? null;
                $v = (string) $r['value'];
                if ($spec !== null && preg_match('/^-?[0-9]{1,9}$/', $v) === 1 && (int) $v >= $spec[1] && (int) $v <= $spec[2]) {
                    $out[(string) $r['key']] = (int) $v;
                }
            }
        } catch (\PDOException $e) {
            if (!self::missingTable($e)) {
                throw $e;
            }
        }
        return $this->memo = $out;
    }

    private static function missingTable(\PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'no such table: app_settings');
    }
}
