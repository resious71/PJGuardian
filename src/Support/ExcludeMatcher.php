<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 분석 제외 대상 판정기(명세 4장, 5.1, 19.10). **제외 판정은 이 클래스에서만 한다**:
 * zip 해제(P13), 신규/해결/범위 외 판정(P16), 브라우저 사전 제외 목록(P12)이 모두 이것을 쓴다.
 *
 * 입력 경로: 분석 루트 기준 상대 경로, '/' 구분(역슬래시는 안전을 위해 '/'로 바꿔 본다), NFC로 정규화해 비교.
 *
 * 1) 서버 기본 목록(설정 extract.default_excludes, 항상 적용)
 *    경로 조각 중 하나가 이름과 **정확히 같을 때만** 제외한다. 대소문자 구분.
 *    예) node_modules → 'web/node_modules/x.js' 제외, 'builder/a.py'·'src/dist.py'·'my_dist/a' 는 제외 아님.
 *
 * 2) 프로젝트 추가 패턴(관리자가 설정, 문법은 docs/architecture.md "제외 패턴")
 *    - 슬래시로 구분한 상대 경로에 대한 glob. '*' = '/'를 제외한 0자 이상, '?' = '/'를 제외한 한 글자,
 *      '**' = 경로 조각 단위로 0개 이상(한 조각 전체로만 쓸 수 있다. 예: docs 아래 전부, 어느 깊이의 fixtures).
 *    - 앞에 '/'가 있으면 루트 기준. 없으면 어느 깊이에서든 시작할 수 있다(앞에 '**' 조각을 붙인 것과 같다).
 *    - 끝에 '/'가 있으면 폴더에만 맞는다(같은 이름의 파일은 제외하지 않음).
 *    - 패턴은 경로 자체와 그 경로의 모든 상위 폴더에 맞춰 본다. 폴더가 맞으면 그 아래 전부가 제외된다.
 *    - 대소문자 구분, NFC 정규화.
 *    - 거부(저장 시 validatePattern): 빈 값, '.'·'..' 조각, 절대 경로(드라이브 문자, '//'), 역슬래시, 제어 문자,
 *      조각 일부로 쓴 '**', 지원하지 않는 문법('[' ']' '{' '}' '!'), 빈 조각('a//b'), 200자 초과.
 */
final class ExcludeMatcher
{
    public const MAX_PATTERN_LENGTH = 200;
    public const MAX_PATTERNS = 100;

    /** @var array<string, true> */
    private array $defaults = [];
    /** @var list<array{pattern:string, regex:string, dirOnly:bool}> */
    private array $patterns = [];

    /**
     * @param list<string> $defaults 서버 기본 목록(이름)
     * @param list<string> $patterns 프로젝트 추가 패턴(저장 시 검증된 값). 잘못된 패턴이면 InvalidArgumentException
     */
    public function __construct(array $defaults, array $patterns)
    {
        foreach ($defaults as $name) {
            $name = self::nfc(trim($name));
            if ($name !== '') {
                $this->defaults[$name] = true;
            }
        }
        foreach ($patterns as $p) {
            $reason = self::validatePattern($p);
            if ($reason !== null) {
                throw new \InvalidArgumentException("잘못된 제외 패턴({$reason}): {$p}");
            }
            $this->patterns[] = self::compile(self::nfc($p));
        }
    }

    public function matches(string $path): bool
    {
        return $this->explain($path) !== null;
    }

    /** 폴더 경로가 제외 대상인지(마지막 조각도 폴더로 본다. 폴더 전용 패턴 'out/'도 맞음). 도구 단계의 폴더 건너뛰기(P15) */
    public function matchesDir(string $path): bool
    {
        return $this->explain($path, true) !== null;
    }

    /**
     * 제외 이유. 'default:<이름>' 또는 'pattern:<패턴>', 제외 대상이 아니면 null.
     * 화면은 문자열 리소스로 바꿔 보여 주고(exclude.reason_*), 로그에는 그대로 남긴다.
     */
    public function explain(string $path, bool $isDirectory = false): ?string
    {
        $path = trim(self::nfc(str_replace('\\', '/', $path)), '/');
        if ($path === '') {
            return null;
        }
        $segments = explode('/', $path);
        foreach ($segments as $seg) {
            if (isset($this->defaults[$seg])) {
                return 'default:' . $seg;
            }
        }
        if ($this->patterns === []) {
            return null;
        }
        // 경로 자체와 모든 상위 폴더(접두사)에 맞춰 본다. 폴더 전용 패턴은 상위 폴더에만
        $count = count($segments);
        for ($i = 1; $i <= $count; $i++) {
            $candidate = implode('/', array_slice($segments, 0, $i));
            $isDir = $i < $count || $isDirectory;
            foreach ($this->patterns as $p) {
                if ($p['dirOnly'] && !$isDir) {
                    continue;
                }
                if (preg_match($p['regex'], $candidate) === 1) {
                    return 'pattern:' . $p['pattern'];
                }
            }
        }
        return null;
    }

    /** @return list<string> 기본 목록(정규화된 이름, 설정 순서) */
    public function defaults(): array
    {
        return array_keys($this->defaults);
    }

    /** @return list<string> 추가 패턴(저장된 형태) */
    public function patterns(): array
    {
        return array_map(static fn (array $p): string => $p['pattern'], $this->patterns);
    }

    /**
     * 저장 전 검사. 문제가 없으면 null, 있으면 이유 코드
     * (empty, too_long, control_char, backslash, absolute, dot_segment, empty_segment, bad_double_star, unsupported_syntax).
     */
    public static function validatePattern(string $pattern): ?string
    {
        if (trim($pattern) === '' || trim($pattern, '/') === '') {
            return 'empty';
        }
        if (mb_strlen($pattern, 'UTF-8') > self::MAX_PATTERN_LENGTH) {
            return 'too_long';
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $pattern) === 1) {
            return 'control_char';
        }
        if (str_contains($pattern, '\\')) {
            return 'backslash';
        }
        if (str_starts_with($pattern, '//') || preg_match('/^[A-Za-z]:/', $pattern) === 1) {
            return 'absolute';
        }
        if (strpbrk($pattern, '[]{}!') !== false) {
            return 'unsupported_syntax';
        }
        $body = $pattern;
        if (str_starts_with($body, '/')) {
            $body = substr($body, 1);
        }
        if (str_ends_with($body, '/')) {
            $body = substr($body, 0, -1);
        }
        foreach (explode('/', $body) as $seg) {
            if ($seg === '') {
                return 'empty_segment';
            }
            if ($seg === '.' || $seg === '..') {
                return 'dot_segment';
            }
            if ($seg !== '**' && str_contains($seg, '**')) {
                return 'bad_double_star';
            }
        }
        return null;
    }

    /**
     * 화면에서 받은 패턴 목록을 정리한다: 앞뒤 공백 제거, 빈 줄 무시, NFC, 중복 제거(처음 것을 남김).
     *
     * @param list<mixed> $raw
     * @return array{0: list<string>, 1: list<array{pattern:string, reason:string}>} [정리된 목록, 잘못된 패턴]
     */
    public static function normalizePatterns(array $raw): array
    {
        $list = [];
        $errors = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                $errors[] = ['pattern' => '', 'reason' => 'not_string'];
                continue;
            }
            $p = self::nfc(trim($item));
            if ($p === '') {
                continue;
            }
            $reason = self::validatePattern($p);
            if ($reason !== null) {
                $errors[] = ['pattern' => $p, 'reason' => $reason];
                continue;
            }
            if (!in_array($p, $list, true)) {
                $list[] = $p;
            }
        }
        return [$list, $errors];
    }

    /** @return array{pattern:string, regex:string, dirOnly:bool} */
    private static function compile(string $pattern): array
    {
        $anchored = str_starts_with($pattern, '/');
        $dirOnly = str_ends_with($pattern, '/');
        $body = trim($pattern, '/');
        $parts = [];
        foreach (explode('/', $body) as $seg) {
            if ($seg === '**') {
                $parts[] = null; // 0개 이상 조각
                continue;
            }
            $re = '';
            foreach (preg_split('//u', $seg, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
                $re .= match ($ch) {
                    '*' => '[^/]*',
                    '?' => '[^/]',
                    default => preg_quote($ch, '#'),
                };
            }
            $parts[] = $re;
        }
        // 조각을 이어 붙인다. '**'는 "(조각/)*" 또는 끝이면 "(/조각)*"로
        $regex = '';
        $n = count($parts);
        foreach ($parts as $i => $part) {
            if ($part === null) {
                // 끝의 '**'는 "그 아래 전부": 아래에 한 조각 이상 있어야 한다('docs/**'는 'docs'라는 파일을 제외하지 않음)
                $regex .= $i === $n - 1 ? ($i === 0 ? '.+' : '/.+') : '(?:[^/]+/)*';
                continue;
            }
            $regex .= $part;
            if ($i < $n - 1 && $parts[$i + 1] !== null) {
                $regex .= '/';
            } elseif ($i < $n - 1 && $parts[$i + 1] === null && $i + 1 < $n - 1) {
                $regex .= '/';
            }
        }
        $prefix = $anchored ? '^' : '^(?:[^/]+/)*';
        return ['pattern' => $pattern, 'regex' => '#' . $prefix . $regex . '$#u', 'dirOnly' => $dirOnly];
    }

    private static function nfc(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
        return $n === false ? $s : $n;
    }
}
