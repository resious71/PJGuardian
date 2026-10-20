<?php

declare(strict_types=1);

namespace PJGuardian\Tools;

/**
 * 공개 전 비밀·개인 정보 스캐너(P33 2절) — tools/scan_release.php와 다른 도구(공개용 트리 내보내기, README 캡처 화면 글자 검사)가 함께 쓴다.
 * 앱 코드가 아니다(src/ 밖, 웹에서 쓰지 않음). 외부 패키지 없음.
 *
 * 검사 종류(kind):
 *   email        개인 이메일. 예약 도메인(example.com·.org·.net, *.example, *.invalid, *.test, *.localhost)과 규칙 파일의 allowed_emails는 통과
 *   abs_path     절대 경로: 드라이브 경로(X:\ 또는 X:/), /home/<이름>, /Users/<이름>. 규칙 파일 generic_paths(설치 위치 예시 C:\xampp 등)는 통과
 *   name         사용자 이름·다른 프로젝트 이름(규칙 파일 names, 대소문자 무시, 단어 경계)
 *   private_key  개인 키 PEM 블록(-----BEGIN … PRIVATE KEY-----)
 *   token        토큰 모양: GitHub(gh[pousr]_, github_pat_), AWS(AKIA), Slack(xox[bap]-), 일반 고엔트로피 40자 이상
 *   private_ip   사설 IPv4(10/8, 172.16/12, 192.168/16) 호스트 주소. 169.254.169.254(클라우드 메타데이터) 같은 링크 로컬은 잘 알려진 값이라 넘긴다
 *   internal_host 내부 호스트 이름(규칙 파일 internal_host_patterns — .local·.corp·.internal·.lan 등), 규칙 파일 allowed_hosts는 통과
 *   password_line 실제 비밀번호처럼 보이는 설정 줄(password·secret·token 등 = 값)
 *
 * 오탐 정책(일부러 넘기는 것 — 바꾸려면 여기와 시험을 함께 고친다):
 *   - 16진 다이제스트(40·64·128자 [0-9a-f]만)는 체크섬이라 token이 아니다(SHA256SUMS·매니페스트·잠금 파일·OSS_MANIFEST).
 *   - npm 무결성 값(sha1-/sha256-/sha384-/sha512- 뒤 base64)은 token이 아니다(package-lock.json·SOURCE.txt).
 *   - 일반 고엔트로피는 "영문 대문자·소문자·숫자가 모두 있고 섀넌 엔트로피 4.2 이상"인 40자 이상 연속 문자열만. 경로·식별자·문장은 대개 걸리지 않는다.
 *   - 사설 IP 범위 표기(끝이 .0 + /접두사, 예 10.0.0.0/8)는 범위 설명이라 호스트 주소가 아니다. 127.0.0.1·문서용 IP(192.0.2/24 등 RFC 5737)는 사설 아님.
 *   - 일반 고엔트로피에서 식별자 모양(영문 + 끝 숫자, camelCase 단어 이어 붙임)은 넘긴다(시험 메서드 이름 등).
 *   - 드라이브 경로는 구분자 뒤 이름 글자가 2개 이상일 때만(정규식 \d\d:\d\d·문자열 "a:
" 제외). 자리표시자 'D:\…'는 경로가 아니다.
 *   - 내부 호스트에서 코드 모양(호출 x.local(…), 문구·설정 키 'error.internal' =>, get('…'))과 *.example.* 예시 이름은 넘긴다.
 *   - 이메일 앞이 ':'이면 URL 사용자 정보(https://user:pass@host)라 이메일로 보지 않는다.
 *   - password_line은 코드 파일(php·js·mjs·ts·py)에서 따옴표로 감싼 값만 본다. 값이 코드·자리표시자처럼 보이면 넘긴다($변수, 함수 호출, <…>, ${…}, …, ***, 빈 값, true/false/null, 따옴표 없는 키 이름 참조).
 *   - 규칙 파일 path_rules로 경로(glob)별로 특정 종류를 끌 수 있다(예: 번들 서드파티 JS의 고엔트로피 — SOURCE.txt의 SHA-256으로 원본 동일성을 따로 확인). 이유 필수.
 * 그 밖의 예외는 허용 목록(tools/scan_release.allow.php)에 파일·줄(또는 일치 정규식)·이유와 함께 적는다. 이유 없는 허용은 거부한다.
 *
 * 발견 값은 절대 그대로 내보내지 않는다: mask() = 앞 3글자 + "…" + "(길이 N)".
 */
final class ReleaseScanner
{
    public const KINDS = ['email', 'abs_path', 'name', 'private_key', 'token', 'private_ip', 'internal_host', 'password_line'];

    /** 텍스트로 훑지 않고 목록(파일·크기)만 내는 확장자 */
    public const BINARY_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'pdf', 'zip', 'gz', 'tgz', 'xz', 'bz2', '7z', 'jar', 'exe', 'dll', 'so',
        'dylib', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'sqlite', 'db', 'pptx', 'docx', 'xlsx', 'mp4', 'webm', 'mp3', 'wav', 'pjgkey', 'bin'];

    /** 코드 파일: password_line은 따옴표로 감싼 문자열 값만 본다(따옴표 없는 값은 변수·식·타입) */
    public const CODE_EXT = ['php', 'js', 'mjs', 'cjs', 'ts', 'tsx', 'jsx', 'py'];

    /** 이 크기보다 큰 텍스트 파일은 훑지 않고 목록에 '크기 초과'로 올린다 */
    public const MAX_TEXT_BYTES = 8 * 1024 * 1024;

    /** @var list<string> */
    private array $names;
    /** @var list<string> */
    private array $allowedEmails;
    /** @var list<string> */
    private array $genericPaths;
    /** @var list<string> */
    private array $genericHomeNames;
    /** @var list<string> */
    private array $internalHostPatterns;
    /** @var list<string> */
    private array $allowedHosts;
    /** @var list<array{glob: string, kinds: list<string>, reason: string}> */
    private array $pathRules;
    /** @var list<array{file: string, line: int|string|null, match: string|null, kind: string|null, reason: string}> */
    private array $allow;

    /**
     * @param array<string, mixed> $rules tools/scan_release.rules.php 형식
     * @param list<array<string, mixed>> $allow tools/scan_release.allow.php 형식
     */
    public function __construct(array $rules, array $allow = [])
    {
        $list = static fn (string $k): array => array_values(array_map('strval', (array) ($rules[$k] ?? [])));
        $this->names = array_values(array_filter($list('names'), static fn (string $n): bool => trim($n) !== ''));
        $this->allowedEmails = array_map('strtolower', $list('allowed_emails'));
        $this->genericPaths = $list('generic_paths');
        $this->genericHomeNames = array_map('strtolower', $list('generic_home_names'));
        $this->internalHostPatterns = $list('internal_host_patterns');
        $this->allowedHosts = array_map('strtolower', $list('allowed_hosts'));
        foreach (array_merge($this->genericPaths, $this->internalHostPatterns) as $re) {
            if (@preg_match($re, '') === false) {
                throw new \InvalidArgumentException("규칙 파일의 정규식이 잘못됨: {$re}");
            }
        }
        $this->pathRules = [];
        foreach ((array) ($rules['path_rules'] ?? []) as $i => $r) {
            if (!is_array($r) || !isset($r['glob'], $r['kinds']) || trim((string) ($r['reason'] ?? '')) === '') {
                throw new \InvalidArgumentException("path_rules #{$i}: glob·kinds·reason(이유)가 모두 필요합니다");
            }
            $kinds = array_values(array_map('strval', (array) $r['kinds']));
            foreach ($kinds as $k) {
                if (!in_array($k, self::KINDS, true)) {
                    throw new \InvalidArgumentException("path_rules #{$i}: 모르는 종류 {$k}");
                }
            }
            $this->pathRules[] = ['glob' => (string) $r['glob'], 'kinds' => $kinds, 'reason' => (string) $r['reason']];
        }
        $this->allow = self::validateAllow($allow);
    }

    /**
     * 허용 목록 검사: 항목마다 file(glob), reason(이유, 비면 거부), 그리고 line(줄 번호 또는 '*' = 파일 전체) 또는 match(발견 값 정규식) 중 하나 이상.
     * kind는 선택(주면 그 종류만).
     *
     * @param list<array<string, mixed>> $allow
     * @return list<array{file: string, line: int|string|null, match: string|null, kind: string|null, reason: string}>
     */
    public static function validateAllow(array $allow): array
    {
        $out = [];
        foreach ($allow as $i => $a) {
            $n = is_int($i) ? $i + 1 : $i;
            if (!is_array($a) || trim((string) ($a['file'] ?? '')) === '') {
                throw new \InvalidArgumentException("허용 #{$n}: file이 필요합니다");
            }
            if (trim((string) ($a['reason'] ?? '')) === '') {
                throw new \InvalidArgumentException("허용 #{$n} ({$a['file']}): 이유(reason)가 없는 허용은 받지 않습니다");
            }
            $line = $a['line'] ?? null;
            $match = isset($a['match']) ? (string) $a['match'] : null;
            if ($line === null && $match === null) {
                throw new \InvalidArgumentException("허용 #{$n} ({$a['file']}): line(줄 번호 또는 '*') 또는 match(정규식)가 필요합니다");
            }
            if ($line !== null && $line !== '*' && !(is_int($line) && $line > 0)) {
                throw new \InvalidArgumentException("허용 #{$n} ({$a['file']}): line은 1 이상의 정수 또는 '*'");
            }
            if ($match !== null && @preg_match($match, '') === false) {
                throw new \InvalidArgumentException("허용 #{$n} ({$a['file']}): match 정규식이 잘못됨");
            }
            $kind = isset($a['kind']) ? (string) $a['kind'] : null;
            if ($kind !== null && !in_array($kind, self::KINDS, true)) {
                throw new \InvalidArgumentException("허용 #{$n} ({$a['file']}): 모르는 종류 {$kind}");
            }
            $out[] = ['file' => (string) $a['file'], 'line' => $line, 'match' => $match, 'kind' => $kind, 'reason' => (string) $a['reason']];
        }
        return $out;
    }

    /** 값 가리기: 앞 3글자 + … + (길이). 3글자 이하 값도 길이만 더해 원문 전체가 나오지 않게 한다 */
    public static function mask(string $value): string
    {
        $len = mb_strlen($value, 'UTF-8');
        $head = mb_substr($value, 0, $len > 3 ? 3 : max(0, $len - 1), 'UTF-8');
        return $head . '…(' . $len . ')';
    }

    /** 파일 경로(/로 통일, 루트 기준)에 glob(**·*·?) 일치 */
    public static function globMatch(string $glob, string $path): bool
    {
        $re = '';
        $n = strlen($glob);
        for ($i = 0; $i < $n; $i++) {
            $c = $glob[$i];
            if ($c === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $re .= '.*';
                    $i++;
                    if (($glob[$i + 1] ?? '') === '/') {
                        $re .= '/?';
                        $i++;
                    }
                } else {
                    $re .= '[^/]*';
                }
            } elseif ($c === '?') {
                $re .= '[^/]';
            } else {
                $re .= preg_quote($c, '#');
            }
        }
        return preg_match('#^' . $re . '$#', $path) === 1;
    }

    public static function isBinaryPath(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::BINARY_EXT, true);
    }

    /**
     * 글자열 하나를 훑는다.
     *
     * @return array{findings: list<array{file: string, line: int, kind: string, masked: string, detail: string}>, allowed: list<array{file: string, line: int, kind: string, reason: string}>}
     */
    public function scanText(string $text, string $file = '<stdin>'): array
    {
        $off = [];
        foreach ($this->pathRules as $r) {
            if (self::globMatch($r['glob'], $file)) {
                $off = array_merge($off, $r['kinds']);
            }
        }
        $codeFile = in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::CODE_EXT, true);
        $findings = [];
        $allowed = [];
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($line === '' || trim($line) === '') {
                continue;
            }
            foreach ($this->scanLine($line, $codeFile) as [$kind, $value, $detail]) {
                if (in_array($kind, $off, true)) {
                    continue;
                }
                $hit = ['file' => $file, 'line' => $i + 1, 'kind' => $kind, 'masked' => self::mask($value), 'detail' => $detail];
                $reason = $this->allowedBy($file, $i + 1, $kind, $value);
                if ($reason !== null) {
                    $allowed[] = ['file' => $file, 'line' => $i + 1, 'kind' => $kind, 'reason' => $reason];
                } else {
                    $findings[] = $hit;
                }
            }
        }
        return ['findings' => $findings, 'allowed' => $allowed];
    }

    /**
     * 파일 목록(루트 기준 상대 경로)을 훑는다. 이진·크기 초과 파일은 binaries에 목록으로만.
     *
     * @param list<string> $files
     * @return array{files_scanned: int, findings: list<array{file: string, line: int, kind: string, masked: string, detail: string}>, allowed: list<array{file: string, line: int, kind: string, reason: string}>, binaries: list<array{file: string, size: int, note: string}>}
     */
    public function scanFiles(string $root, array $files): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $res = ['files_scanned' => 0, 'findings' => [], 'allowed' => [], 'binaries' => []];
        sort($files, SORT_STRING);
        foreach ($files as $rel) {
            $rel = ltrim(str_replace('\\', '/', $rel), '/');
            $abs = $root . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $size = (int) filesize($abs);
            if (self::isBinaryPath($rel)) {
                $res['binaries'][] = ['file' => $rel, 'size' => $size, 'note' => 'binary'];
                continue;
            }
            if ($size > self::MAX_TEXT_BYTES) {
                $res['binaries'][] = ['file' => $rel, 'size' => $size, 'note' => 'too_large'];
                continue;
            }
            $text = (string) file_get_contents($abs);
            if (str_contains(substr($text, 0, 8192), "\0")) {
                $res['binaries'][] = ['file' => $rel, 'size' => $size, 'note' => 'binary'];
                continue;
            }
            $res['files_scanned']++;
            $r = $this->scanText($text, $rel);
            array_push($res['findings'], ...$r['findings']);
            array_push($res['allowed'], ...$r['allowed']);
        }
        return $res;
    }

    /** 폴더 아래 모든 파일(.git 폴더 제외)의 상대 경로 */
    public static function listTree(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $out = [];
        $stack = [''];
        while ($stack !== []) {
            $rel = array_pop($stack);
            $dir = $root . ($rel === '' ? '' : '/' . $rel);
            foreach (scandir($dir) ?: [] as $name) {
                if ($name === '.' || $name === '..' || ($rel === '' && $name === '.git')) {
                    continue;
                }
                $child = $rel === '' ? $name : $rel . '/' . $name;
                if (is_dir($root . '/' . $child) && !is_link($root . '/' . $child)) {
                    $stack[] = $child;
                } elseif (is_file($root . '/' . $child)) {
                    $out[] = $child;
                }
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function allowedBy(string $file, int $line, string $kind, string $value): ?string
    {
        foreach ($this->allow as $a) {
            if (!self::globMatch($a['file'], $file)) {
                continue;
            }
            if ($a['kind'] !== null && $a['kind'] !== $kind) {
                continue;
            }
            if ($a['line'] !== null && $a['line'] !== '*' && $a['line'] !== $line) {
                continue;
            }
            if ($a['match'] !== null && preg_match($a['match'], $value) !== 1) {
                continue;
            }
            return $a['reason'];
        }
        return null;
    }

    /** @return list<array{0: string, 1: string, 2: string}> [종류, 발견 값(가리기 전), 설명] */
    private function scanLine(string $line, bool $codeFile = false): array
    {
        $hits = [];
        // 개인 키
        if (preg_match('/-----BEGIN ((?:[A-Z0-9]+ )*)PRIVATE KEY-----/', $line, $m) === 1) {
            $hits[] = ['private_key', $m[0], 'PEM 개인 키 블록'];
        }
        // 토큰 모양(이름 있는 것)
        $named = [
            '/\b(gh[pousr]_[A-Za-z0-9]{30,})/' => 'GitHub 토큰',
            '/\b(github_pat_[A-Za-z0-9_]{40,})/' => 'GitHub 세분화 토큰',
            '/\b(AKIA[0-9A-Z]{16})\b/' => 'AWS 액세스 키 ID',
            '/\b(xox[bap]-[A-Za-z0-9-]{10,})/' => 'Slack 토큰',
        ];
        $tokenSpans = [];
        foreach ($named as $re => $label) {
            if (preg_match_all($re, $line, $mm) > 0) {
                foreach ($mm[1] as $v) {
                    $hits[] = ['token', $v, $label];
                    $tokenSpans[] = $v;
                }
            }
        }
        // 일반 고엔트로피 40자 이상
        if (preg_match_all('#(?<![A-Za-z0-9+/=_-])([A-Za-z0-9+/=_-]{40,})(?![A-Za-z0-9+/=_-])#', $line, $mm, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($mm[1] as [$v, $pos]) {
                if ($this->insideAny($v, $tokenSpans) || !self::looksLikeSecret($v, substr($line, max(0, $pos - 8), 8))) {
                    continue;
                }
                $hits[] = ['token', $v, '고엔트로피 문자열'];
            }
        }
        // 이메일
        // 앞이 ':'이면 URL 사용자 정보(https://user:pass@host)라 이메일이 아니다
        if (preg_match_all('/(?<![A-Za-z0-9._%+:-])[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', $line, $mm) > 0) {
            foreach ($mm[0] as $v) {
                if (!$this->emailAllowed($v)) {
                    $hits[] = ['email', $v, '개인 이메일'];
                }
            }
        }
        // 절대 경로: 드라이브
        // 구분자 뒤 이름 글자 2개 이상(정규식 \d\d:\d\d, 문자열 "a:\n\t" 같은 것 제외), PHP 문자열의 겹친 역슬래시(C:\\Windows) 허용
        if (preg_match_all('#(?<![A-Za-z0-9\\\\])([A-Za-z]:(?:\\\\{1,2}|/)[A-Za-z0-9._$~-]{2,}[^ \t"\'<>|*?`,;()\[\]{}]*)#', $line, $mm) > 0) {
            foreach ($mm[1] as $v) {
                if ($this->genericPath((string) preg_replace('#\\\\+#', '\\', $v))) {
                    continue;
                }
                $hits[] = ['abs_path', $v, '드라이브 절대 경로'];
            }
        }
        // 절대 경로: 홈 폴더
        if (preg_match_all('#(?<![A-Za-z0-9._~-])/(home|Users)/([A-Za-z0-9._-]+)#', $line, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                if (in_array(strtolower($m[2]), $this->genericHomeNames, true)) {
                    continue;
                }
                $hits[] = ['abs_path', $m[0], '홈 폴더 경로'];
            }
        }
        // 이름
        foreach ($this->names as $name) {
            if (preg_match_all('/(?<![A-Za-z0-9])' . preg_quote($name, '/') . '(?![A-Za-z0-9])/i', $line, $mm) > 0) {
                foreach ($mm[0] as $v) {
                    $hits[] = ['name', $v, '사용자·프로젝트 이름'];
                }
            }
        }
        // 사설 IP
        if (preg_match_all('#(?<![0-9.])((\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3}))(/\d{1,2})?(?![0-9.]*\d)#', $line, $mm, PREG_SET_ORDER) > 0) {
            foreach ($mm as $m) {
                $o = array_map('intval', [$m[2], $m[3], $m[4], $m[5]]);
                if (max($o) > 255) {
                    continue;
                }
                $private = $o[0] === 10 || ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31) || ($o[0] === 192 && $o[1] === 168);
                if (!$private) {
                    continue;
                }
                if (($m[6] ?? '') !== '' && $o[3] === 0) {
                    continue; // 범위 표기(10.0.0.0/8)
                }
                $hits[] = ['private_ip', $m[1] . ($m[6] ?? ''), '사설 IP'];
            }
        }
        // 내부 호스트
        if (preg_match_all('/(?<![A-Za-z0-9._-])((?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+)(?![A-Za-z0-9_-])/', $line, $mm, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($mm[1] as [$host, $pos]) {
                $lh = strtolower($host);
                if (in_array($lh, $this->allowedHosts, true) || preg_match('/(^|\.)example(\.|$)/', $lh) === 1) {
                    continue; // 문서용 예시 이름(*.example.*)
                }
                $after = substr($line, $pos + strlen($host), 8);
                $before = substr($line, max(0, $pos - 2), min(2, $pos));
                if (str_starts_with($after, '(') || preg_match('/^["\'][ \t]*(=>|:)/', $after) === 1 || preg_match('/\(["\']$/', $before) === 1) {
                    continue; // 코드: 호출 tags.local(…), 문구·설정 키 'error.internal' => …, get('…')
                }
                foreach ($this->internalHostPatterns as $re) {
                    if (preg_match($re, $lh) === 1) {
                        $hits[] = ['internal_host', $host, '내부 호스트 이름'];
                        break;
                    }
                }
            }
        }
        // 비밀번호 같은 설정 줄
        if (preg_match('/(?<![A-Za-z0-9_])((?:[A-Za-z0-9_]*_)?(?:password|passwd|pwd|secret|token|api_?key|access_?key|client_secret|private_key))["\']?[ \t]*(?:=>|=|:)[ \t]*(["\']?)([^"\'\r\n]*)\2/i', $line, $m) === 1) {
            $value = trim($m[3]);
            if ($m[2] === '') {
                $value = (string) preg_replace('/[^!-~].*$|[,;)}\]].*$/', '', $value); // 따옴표 없는 값은 구분자·ASCII 밖 글자까지
            }
            if (($m[2] !== '' || !$codeFile) && self::looksLikePasswordValue($value)) {
                $hits[] = ['password_line', $value, $m[1] . ' 값'];
            }
        }
        return $hits;
    }

    /** @param list<string> $spans */
    private function insideAny(string $v, array $spans): bool
    {
        foreach ($spans as $s) {
            if (str_contains($v, $s) || str_contains($s, $v)) {
                return true;
            }
        }
        return false;
    }

    private static function looksLikeSecret(string $v, string $before): bool
    {
        if (preg_match('/^[0-9a-f]+$/i', $v) === 1) {
            return false; // 16진 다이제스트(체크섬)
        }
        if (preg_match('/sha(1|256|384|512)-$/', $before) === 1 || preg_match('/^sha(1|256|384|512)-/', $v) === 1) {
            return false; // npm 무결성 값
        }
        if (preg_match('/[A-Z]/', $v) !== 1 || preg_match('/[a-z]/', $v) !== 1 || preg_match('/[0-9]/', $v) !== 1) {
            return false;
        }
        if (str_contains($v, '/') && preg_match_all('#(^|/)[a-z_]{3,}#', $v) >= 2) {
            return false; // 경로처럼 보임(src/Services/…, docs/results/P20_dashboard…)
        }
        if (preg_match('/^[A-Za-z_]+[0-9]{0,8}$/', $v) === 1 || (preg_match_all('/[a-z][A-Z]/', $v) >= 3 && preg_match_all('/[0-9]/', $v) <= 4)) {
            return false; // 식별자(camelCase 시험 이름 + 끝 번호 등)
        }
        return self::entropy($v) >= 4.2;
    }

    public static function entropy(string $s): float
    {
        $len = strlen($s);
        if ($len === 0) {
            return 0.0;
        }
        $h = 0.0;
        foreach (count_chars($s, 1) as $n) {
            $p = $n / $len;
            $h -= $p * log($p, 2);
        }
        return $h;
    }

    private static function looksLikePasswordValue(string $v): bool
    {
        if (strlen($v) < 4 || strlen($v) > 200) {
            return false;
        }
        $lv = strtolower($v);
        if (in_array($lv, ['true', 'false', 'null', 'none', 'yes', 'no', 'on', 'off', 'required', 'optional', 'string', 'text', 'password', 'secret', 'token', 'hidden', 'changeme'], true)) {
            return false;
        }
        // 코드·자리표시자
        if (preg_match('/^[$@%&{(\[<\/]|\||^\.\.\.|\*\*\*|\$\{|\{\{|[()]|->|::|^[A-Z_][A-Z0-9_]*$|^env\(|getenv|^process\.|^self\.|^this\.|…/', $v) === 1) {
            return false;
        }
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $v) === 1 && str_contains($v, '.')) {
            return false; // 설정 키·문구 키 참조(auth.password …)
        }
        if (preg_match('/[ \t]/', $v) === 1) {
            return false; // 문장(설명 문구)
        }
        // 비밀번호다운 값: 글자 종류 2가지 이상(영문+숫자/기호)
        $classes = (preg_match('/[a-z]/', $v) === 1 ? 1 : 0) + (preg_match('/[A-Z]/', $v) === 1 ? 1 : 0) + (preg_match('/[0-9]/', $v) === 1 ? 1 : 0)
            + (preg_match('/[^A-Za-z0-9]/', $v) === 1 ? 1 : 0);
        return $classes >= 2;
    }

    private function emailAllowed(string $email): bool
    {
        $e = strtolower($email);
        if (in_array($e, $this->allowedEmails, true)) {
            return true;
        }
        $domain = substr($e, (int) strrpos($e, '@') + 1);
        if (in_array($domain, ['example.com', 'example.org', 'example.net', 'example.invalid', 'example.test'], true)) {
            return true;
        }
        foreach (['.example', '.invalid', '.test', '.localhost', '.example.com', '.example.org', '.example.net'] as $suffix) {
            if (str_ends_with($domain, $suffix)) {
                return true;
            }
        }
        return in_array($domain, ['localhost', 'invalid', 'test', 'example'], true);
    }

    private function genericPath(string $path): bool
    {
        foreach ($this->genericPaths as $re) {
            if (preg_match($re, $path) === 1) {
                return true;
            }
        }
        return false;
    }
}
