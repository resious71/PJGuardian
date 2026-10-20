<?php

declare(strict_types=1);

namespace PJGuardian\Services\Settings;

use PJGuardian\Services\Process\ProcessOptions;
use PJGuardian\Services\Process\ProcessResult;
use PJGuardian\Services\Process\ProcessRunner;
use PJGuardian\Support\Config;

/**
 * 경로 설정 값 검증(P27A — P27B 백업 폴더가 같은 클래스를 쓴다). 저장 전에 서버가 모두 검사하고, 하나라도 어기면 저장하지 않는다.
 *
 * 규칙(docs/settings.md "경로 규칙"):
 *  1. 절대 경로(Windows 드라이브 "D:\…", UNC "\\서버\공유\…", Linux "/…") 또는 프로젝트 루트 기준 상대 경로. "C:abc"·"\abc"(드라이브 없는 루트)는 거부.
 *  2. 길이 Config::PATH_MAX(200) 이하.
 *  3. ".."·"."을 풀고, 이미 있는 가장 깊은 상위 폴더를 realpath로 정규화한 결과(심볼릭 링크·정션을 따라간 실제 위치)로 금지 위치와 비교한다.
 *     금지: 드라이브 루트·"/" 자체, 프로젝트 폴더 전체(웹 문서 루트 public/, data/, bin/, config/, src/, migrations/, tests/ 포함),
 *     설정의 데이터 폴더(paths.* — 프로젝트 밖에 둔 경우도), 운영체제 폴더(%SystemRoot%·Program Files·ProgramData, /etc·/proc·/usr 등).
 *     예외(허용): 생성자의 $allowed(P27B: 프로젝트 바로 아래 backups/) 안 — 운영체제 폴더 규칙은 예외에도 적용.
 *  4. 존재해야 한다. 없으면 fail_not_found(can_create) → 화면이 묻고 $create=true로 다시 부르면 만든다.
 *  5. 쓰기 시험(임시 파일 생성·삭제)이 통과해야 한다. 여유 공간을 함께 돌려준다.
 *  6. 운영 데이터(data 폴더)와 같은 볼륨이면 warn_same_volume(저장은 가능 — 디스크 고장에 함께 잃음을 알림).
 *  UNC 경로는 접근이 오래 걸릴 수 있어 PHP 자식 프로세스(tools/lib/path_probe.php)로 확인하고 $uncTimeout(5초)를 넘으면 fail_unc_unreachable.
 */
final class PathPolicy
{
    public const CODES = ['ok', 'warn_same_volume', 'fail_not_absolute', 'fail_forbidden_location', 'fail_not_found', 'fail_not_writable', 'fail_too_long', 'fail_unc_unreachable'];

    /** @var list<string> 정규화한 금지 위치(비교용 형태) */
    private array $forbidden = [];
    /** @var list<string> 정규화한 예외 위치 */
    private array $allowed = [];
    /** @var list<string> 운영체제 폴더 — 예외에도 적용 */
    private array $system = [];

    /**
     * @param list<string> $forbidden 금지 위치(프로젝트 루트는 항상 포함)
     * @param list<string> $allowed 금지 위치 안이어도 허용할 폴더(P27B: <프로젝트>/backups)
     */
    public function __construct(
        private readonly string $root,
        array $forbidden,
        array $allowed,
        private readonly string $dataDir,
        private readonly ?ProcessRunner $runner = null,
        private readonly float $uncTimeout = 5.0,
        private readonly ?string $tmpDir = null,
        private readonly bool $windows = PHP_OS_FAMILY === 'Windows',
    ) {
        foreach ([$root, ...$forbidden] as $p) {
            $this->forbidden[] = $this->canonical($p);
        }
        foreach ($allowed as $p) {
            $this->allowed[] = $this->canonical($p);
        }
        foreach ($this->systemDirs() as $p) {
            $this->system[] = $this->canonical($p);
        }
    }

    /**
     * @return array{code: string, path: ?string, free_bytes: ?int, can_create: bool, created: bool}
     */
    public function check(string $input, bool $create = false): array
    {
        $result = static fn (string $code, ?string $path = null, ?int $free = null, bool $canCreate = false, bool $created = false): array
            => ['code' => $code, 'path' => $path, 'free_bytes' => $free, 'can_create' => $canCreate, 'created' => $created];
        $in = trim($input);
        if ($in === '') {
            return $result('fail_not_absolute');
        }
        if (strlen($in) > Config::PATH_MAX || preg_match('/["\x00-\x1f\x7f]/', $in) === 1) {
            return $result(strlen($in) > Config::PATH_MAX ? 'fail_too_long' : 'fail_not_absolute');
        }
        $lexical = $this->absolute($in);
        if ($lexical === null) {
            return $result('fail_not_absolute');
        }
        if (strlen($lexical) > Config::PATH_MAX) {
            return $result('fail_too_long');
        }
        if ($this->isForbidden($lexical)) {
            return $result('fail_forbidden_location', $this->display($lexical));
        }
        $unc = $this->isUnc($lexical);
        $probe = $unc ? $this->probeChild($lexical, $create) : self::probe($lexical, $create);
        if ($probe === null) {
            return $result('fail_unc_unreachable', $this->display($lexical));
        }
        // 정션·심볼릭 링크를 따라간 실제 위치로 다시 비교(정규화 후 비교 — 문자열만 보고 통과시키지 않는다)
        $real = $probe['real'] !== null ? $this->canonical($probe['real']) : $lexical;
        if ($this->isForbidden($real)) {
            if ($probe['created']) {
                @rmdir($lexical); // 금지 위치로 이어지는 경로에 방금 만든 폴더는 되돌린다
            }
            return $result('fail_forbidden_location', $this->display($real));
        }
        if ($unc && !$probe['share']) {
            return $result('fail_unc_unreachable', $this->display($lexical));
        }
        if (!$probe['exists']) {
            return $result($probe['create_failed'] ? 'fail_not_writable' : 'fail_not_found', $this->display($lexical), null, !$probe['create_failed']);
        }
        if (!$probe['writable']) {
            return $result('fail_not_writable', $this->display($real), $probe['free'], false, $probe['created']);
        }
        $code = $this->sameVolume($real) ? 'warn_same_volume' : 'ok';
        return $result($code, $this->display($real), $probe['free'], false, $probe['created']);
    }

    /**
     * 실제 확인(로컬은 이 프로세스, UNC는 자식 프로세스가 같은 함수를 부른다): 존재·만들기·실제 위치·쓰기 시험·여유 공간.
     *
     * @return array{exists: bool, real: ?string, writable: bool, free: ?int, created: bool, create_failed: bool, share: bool}
     */
    public static function probe(string $path, bool $create): array
    {
        $native = str_replace('/', DIRECTORY_SEPARATOR, $path);
        $out = ['exists' => false, 'real' => null, 'writable' => false, 'free' => null, 'created' => false, 'create_failed' => false, 'share' => true];
        if (str_starts_with($path, '//')) {
            $parts = explode('/', substr($path, 2));
            $share = '\\\\' . ($parts[0] ?? '') . '\\' . ($parts[1] ?? '');
            $out['share'] = @is_dir($share);
            if (!$out['share']) {
                return $out;
            }
        }
        // 이미 있는 가장 깊은 상위 폴더의 실제 위치(정션·링크를 따라감) + 나머지 이름
        $existing = $path;
        $rest = [];
        while (!@is_dir(str_replace('/', DIRECTORY_SEPARATOR, $existing))) {
            $parent = dirname($existing);
            if ($parent === $existing || $parent === '.' || $parent === '') {
                break;
            }
            array_unshift($rest, basename($existing));
            $existing = $parent;
        }
        $realBase = @realpath(str_replace('/', DIRECTORY_SEPARATOR, $existing));
        if ($realBase !== false) {
            $out['real'] = rtrim(str_replace('\\', '/', $realBase), '/') . ($rest === [] ? '' : '/' . implode('/', $rest));
        }
        if (@is_file($native)) {
            return $out; // 파일이 같은 이름으로 있으면 폴더로 쓸 수 없다(존재하지 않는 것으로 봄 — 만들기도 실패)
        }
        if (!@is_dir($native) && $create) {
            $out['created'] = @mkdir($native, 0750, true) || @is_dir($native);
            $out['create_failed'] = !$out['created'];
            if ($out['created'] && ($r = @realpath($native)) !== false) {
                $out['real'] = str_replace('\\', '/', $r);
            }
        }
        if (!@is_dir($native)) {
            return $out;
        }
        $out['exists'] = true;
        $test = $native . DIRECTORY_SEPARATOR . '.pjg-write-test-' . bin2hex(random_bytes(6));
        $h = @fopen($test, 'xb');
        if ($h !== false) {
            $out['writable'] = @fwrite($h, 'pjg') === 3;
            @fclose($h);
            $out['writable'] = @unlink($test) && $out['writable'];
        }
        $free = @disk_free_space($native);
        $out['free'] = $free === false ? null : (int) $free;
        return $out;
    }

    /** @return array{exists: bool, real: ?string, writable: bool, free: ?int, created: bool, create_failed: bool, share: bool}|null 시간 초과·실패면 null */
    private function probeChild(string $path, bool $create): ?array
    {
        if ($this->runner === null) {
            return null;
        }
        $r = $this->runner->run([PHP_BINARY, $this->root . '/tools/lib/path_probe.php', $path, $create ? '1' : '0'],
            new ProcessOptions(timeoutSeconds: $this->uncTimeout, outputLimit: 65536, outputDir: $this->tmpDir));
        try {
            if ($r->reason !== ProcessResult::NORMAL || $r->exitCode !== 0) {
                return null;
            }
            $data = json_decode(trim($r->stdout(65536)), true);
            return is_array($data) && isset($data['exists']) ? $data : null;
        } finally {
            $r->cleanup();
        }
    }

    /** 입력 → 정규화한 절대 경로(구분자 "/", ".."·"." 풀림). 절대·상대 규칙에 맞지 않으면 null */
    private function absolute(string $in): ?string
    {
        $p = str_replace('\\', '/', $in);
        if ($this->windows) {
            if (preg_match('#^//[^/]+/[^/]+#', $p) === 1) {
                $prefix = '//';
                $body = substr($p, 2);
                $minParts = 2; // 서버·공유 이름은 ".."로 넘을 수 없다
            } elseif (preg_match('#^[A-Za-z]:/#', $p) === 1) {
                $prefix = strtoupper($p[0]) . ':/';
                $body = substr($p, 3);
                $minParts = 0;
            } elseif (preg_match('#^[A-Za-z]:#', $p) === 1 || str_starts_with($p, '/')) {
                return null; // 드라이브 기준 상대("C:abc")·드라이브 없는 루트("\abc")는 뜻이 모호하다
            } else {
                return $this->absolute(rtrim($this->root, '/\\') . '/' . $p);
            }
        } elseif (str_starts_with($p, '/')) {
            $prefix = '/';
            $body = substr($p, 1);
            $minParts = 0;
        } else {
            return $this->absolute(rtrim($this->root, '/') . '/' . $p);
        }
        $parts = [];
        foreach (explode('/', $body) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                if (count($parts) <= $minParts) {
                    return null; // 루트·공유 위로 올라가는 경로
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $seg;
        }
        if (count($parts) < $minParts) {
            return null;
        }
        return $parts === [] ? $prefix : $prefix . implode('/', $parts);
    }

    private function isUnc(string $p): bool
    {
        return $this->windows && str_starts_with($p, '//');
    }

    private function isForbidden(string $p): bool
    {
        if ($p === '/' || preg_match('#^[A-Z]:/?$#i', $p) === 1) {
            return true; // 드라이브 루트·"/" 자체
        }
        foreach ($this->system as $s) {
            if ($this->within($p, $s)) {
                return true;
            }
        }
        foreach ($this->allowed as $a) {
            if ($this->within($p, $a)) {
                return false;
            }
        }
        foreach ($this->forbidden as $f) {
            if ($this->within($p, $f)) {
                return true;
            }
        }
        return false;
    }

    private function within(string $p, string $dir): bool
    {
        if ($this->windows) {
            $p = strtolower($p);
            $dir = strtolower($dir);
        }
        $dir = rtrim($dir, '/');
        return $p === $dir || str_starts_with($p, $dir . '/');
    }

    private function sameVolume(string $real): bool
    {
        $data = $this->canonical($this->dataDir);
        if ($this->windows) {
            return $this->volumeOf($real) !== null && strtolower((string) $this->volumeOf($real)) === strtolower((string) $this->volumeOf($data));
        }
        $a = @stat($real);
        $b = @stat($data);
        return $a !== false && $b !== false && $a['dev'] === $b['dev'];
    }

    private function volumeOf(string $p): ?string
    {
        if (preg_match('#^([A-Za-z]):#', $p, $m) === 1) {
            return $m[1] . ':';
        }
        if (preg_match('#^//([^/]+)/([^/]+)#', $p, $m) === 1) {
            return '//' . $m[1] . '/' . $m[2];
        }
        return null;
    }

    /** 비교용 형태: 구분자 "/", 실제로 있으면 realpath, 끝 "/" 없음 */
    private function canonical(string $p): string
    {
        $r = @realpath($p);
        $p = str_replace('\\', '/', $r === false ? $p : $r);
        if ($this->windows && preg_match('#^[a-z]:#', $p) === 1) {
            $p = strtoupper($p[0]) . substr($p, 1);
        }
        return strlen($p) > 3 ? rtrim($p, '/') : $p;
    }

    /** 화면 표시용(운영체제 구분자) */
    private function display(string $p): string
    {
        return $this->windows ? str_replace('/', '\\', $p) : $p;
    }

    /** @return list<string> */
    private function systemDirs(): array
    {
        if (!$this->windows) {
            return ['/bin', '/boot', '/dev', '/etc', '/lib', '/lib32', '/lib64', '/libx32', '/proc', '/run', '/sbin', '/sys', '/usr'];
        }
        $dirs = [];
        foreach (['SystemRoot', 'windir', 'ProgramFiles', 'ProgramFiles(x86)', 'ProgramW6432', 'ProgramData'] as $env) {
            $v = getenv($env);
            if (is_string($v) && $v !== '') {
                $dirs[] = $v;
            }
        }
        return array_values(array_unique([...$dirs, 'C:\\Windows', 'C:\\Program Files', 'C:\\Program Files (x86)', 'C:\\ProgramData']));
    }
}
