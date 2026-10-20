<?php

declare(strict_types=1);

namespace PJGuardian\Services\Tools;

/**
 * ruff 설정 선택과 extend 경로 검사(명세 5.3, 19.10). 프로토타입 tools/proto/ruff_config.php를 다시 썼다(require 없음).
 *
 * 선택: 분석 루트의 `.ruff.toml` > `ruff.toml` > `pyproject.toml`(ruff 설정이 있을 때) 하나를 `--config`로 넘긴다.
 *   `--config`를 주면 ruff는 하위 폴더 설정과 사용자 설정(~/.config/ruff 등)을 읽지 않는다(P03 확인).
 *   업로드 설정이 없거나 거부되면 서버 기본 설정(config/ruff.default.toml).
 * extend 검사(판단할 수 없으면 거부 = 서버 기본 설정으로 분석):
 *   - ruff 설정 위치의 extend 값이 한 줄 문자열이 아니면(인라인 테이블, 여러 줄 문자열) 거부.
 *   - 값에 `~`, `$`, `%`가 있으면 거부(ruff는 ~와 $VAR를 확장한다, P03 확인).
 *   - 상대 경로는 그 설정 파일 위치 기준. 실제 경로(realpath)가 분석 루트 밖이거나 파일이 없으면 거부. `..`로 나갔다 돌아오는 것도
 *     실제 경로로 판단한다. extend된 파일도 재귀 검사(10단계, 순환 거부).
 *   - 한계: TOML을 완전히 해석하지 않는다. 여러 줄 문자열 안의 "extend =" 같은 글자는 오탐(거부)이 될 수 있다(안전 쪽).
 * 설정 해시: 실제로 쓴 설정 파일들(extend 사슬 포함)의 내용(CRLF→LF)을 순서대로 이은 SHA-256 + 서버가 붙인 명령행 옵션.
 */
final class RuffConfigResolver
{
    private const MAX_DEPTH = 10;

    public function __construct(private readonly string $defaultConfig)
    {
    }

    /**
     * @return array{path:string, source:string, files:list<string>, ignored:list<array{file:string, reason:string, detail?:string}>}
     *   ignored: 루트 설정을 거부했을 때 그 사실(파일·사유 코드). 하위 폴더 설정은 ToolProjectScan이 따로 모은다
     */
    public function resolve(string $root): array
    {
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        $found = self::findRootConfig($root);
        $ignored = [];
        if ($found !== null) {
            $chain = $this->checkChain($root . '/' . $found, $root, 0, []);
            if ($chain['ok']) {
                return ['path' => $root . '/' . $found, 'source' => 'upload', 'files' => $chain['files'], 'ignored' => []];
            }
            $ignored[] = ['file' => $found, 'reason' => 'ruff_config_rejected', 'detail' => $chain['reason']];
        }
        $default = (string) realpath($this->defaultConfig);
        return ['path' => $default, 'source' => 'server_default', 'files' => [$default], 'ignored' => $ignored];
    }

    /** 루트의 ruff 설정 파일 이름 또는 null */
    public static function findRootConfig(string $root): ?string
    {
        foreach (['.ruff.toml', 'ruff.toml'] as $name) {
            if (is_file($root . '/' . $name)) {
                return $name;
            }
        }
        if (is_file($root . '/pyproject.toml') && self::pyprojectHasRuff($root . '/pyproject.toml')) {
            return 'pyproject.toml';
        }
        return null;
    }

    /** pyproject.toml에 ruff 설정([tool.ruff…] 표, [tool] 아래 ruff 키, tool.ruff… 점 표기)이 있는지 */
    public static function pyprojectHasRuff(string $file): bool
    {
        $table = '';
        foreach (self::lines($file) as $raw) {
            $line = self::stripComment($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[\[?\s*([^\]]+?)\s*\]\]?$/', $line, $m) === 1) {
                $table = self::keyPath($m[1]);
                if ($table === 'tool.ruff' || str_starts_with($table, 'tool.ruff.')) {
                    return true;
                }
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-."\' ]+?)\s*=/', $line, $m) === 1) {
                $key = ltrim(($table !== '' ? $table . '.' : '') . self::keyPath($m[1]), '.');
                if ($key === 'tool.ruff' || str_starts_with($key, 'tool.ruff.')) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return list<string> 파일의 줄(1MB 초과면 빈 목록 대신 예외: 설정으로 보기 어려움) */
    private static function lines(string $file): array
    {
        $size = @filesize($file);
        if ($size === false || $size > 1024 * 1024) {
            throw new \RuntimeException('설정 파일이 없거나 1MB를 넘음');
        }
        return preg_split('/\r\n|\n|\r/', (string) file_get_contents($file)) ?: [];
    }

    /** TOML 한 줄에서 문자열 밖의 # 주석을 지운다 */
    private static function stripComment(string $line): string
    {
        $out = '';
        $quote = null;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $c = $line[$i];
            if ($quote !== null) {
                $out .= $c;
                if ($c === '\\' && $quote === '"' && $i + 1 < $len) {
                    $out .= $line[++$i];
                } elseif ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === '#') {
                break;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
            }
            $out .= $c;
        }
        return trim($out);
    }

    private static function keyPath(string $key): string
    {
        return implode('.', array_map(static fn (string $p): string => trim(trim($p), "\"'"), explode('.', $key)));
    }

    /** @return array{ok:bool, values:list<string>, reason:string} 설정 파일 하나의 ruff 위치 extend 값 */
    private static function extendValues(string $file, bool $isPyproject): array
    {
        try {
            $lines = self::lines($file);
        } catch (\RuntimeException $e) {
            return ['ok' => false, 'values' => [], 'reason' => $e->getMessage()];
        }
        $values = [];
        $table = '';
        $ruffTable = $isPyproject ? 'tool.ruff' : '';
        foreach ($lines as $no => $raw) {
            $line = self::stripComment($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\[\[?\s*([^\]]+?)\s*\]\]?$/', $line, $m) === 1) {
                $table = self::keyPath($m[1]);
                continue;
            }
            // extend라는 키 조각이 없으면 관계없다(extend-select 같은 키는 '-'로 이어져 걸리지 않음)
            if (preg_match('/(^|[\s{,.])["\']?extend["\']?\s*=/', $line) !== 1) {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_\-."\' ]+?)\s*=\s*(.+)$/', $line, $m) !== 1) {
                return ['ok' => false, 'values' => [], 'reason' => sprintf('%d줄: extend 형식을 해석할 수 없음', $no + 1)];
            }
            $fullKey = ltrim(($table !== '' ? $table . '.' : '') . self::keyPath($m[1]), '.');
            $value = trim($m[2]);
            $target = $ruffTable === '' ? 'extend' : $ruffTable . '.extend';
            if ($fullKey !== $target) {
                if (str_contains($value, '{') || str_contains($m[1], '{')) {
                    return ['ok' => false, 'values' => [], 'reason' => sprintf('%d줄: 인라인 테이블 안의 extend는 허용하지 않음', $no + 1)];
                }
                if ($ruffTable !== '' && !str_starts_with($fullKey, $ruffTable)) {
                    continue; // 다른 도구의 설정(ruff가 읽지 않음)
                }
                return ['ok' => false, 'values' => [], 'reason' => sprintf('%d줄: 알 수 없는 위치의 extend(%s)', $no + 1, $fullKey)];
            }
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"$/', $value, $s) === 1) {
                $values[] = stripcslashes($s[1]);
            } elseif (preg_match("/^'([^']*)'$/", $value, $s) === 1) {
                $values[] = $s[1];
            } else {
                return ['ok' => false, 'values' => [], 'reason' => sprintf('%d줄: extend 값은 한 줄 문자열만 허용', $no + 1)];
            }
        }
        return ['ok' => true, 'values' => $values, 'reason' => ''];
    }

    private static function inside(string $path, string $root): bool
    {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }
        $key = static fn (string $p): string => PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', $p)) : $p;
        $r = rtrim($key($root), '/');
        $p = $key($real);
        return $p === $r || str_starts_with($p, $r . '/');
    }

    /**
     * @param array<string, true> $seen
     * @return array{ok:bool, reason:string, files:list<string>}
     */
    private function checkChain(string $file, string $root, int $depth, array $seen): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['ok' => false, 'reason' => 'extend 단계가 너무 깊음', 'files' => []];
        }
        $real = realpath($file);
        if ($real === false || !is_file($real) || !self::inside($real, $root)) {
            return ['ok' => false, 'reason' => '설정 파일이 프로젝트 디렉토리 밖에 있음', 'files' => []];
        }
        $key = PHP_OS_FAMILY === 'Windows' ? strtolower($real) : $real;
        if (isset($seen[$key])) {
            return ['ok' => false, 'reason' => 'extend 순환 참조', 'files' => []];
        }
        $seen[$key] = true;
        $found = self::extendValues($real, strtolower(basename($real)) === 'pyproject.toml');
        if (!$found['ok']) {
            return ['ok' => false, 'reason' => basename($real) . ': ' . $found['reason'], 'files' => []];
        }
        $files = [$real];
        foreach ($found['values'] as $value) {
            if (preg_match('/[~$%]/', $value) === 1) {
                return ['ok' => false, 'reason' => sprintf('extend "%s": ~, $, %% 확장은 허용하지 않음', $value), 'files' => []];
            }
            $abs = preg_match('#^([A-Za-z]:)?[/\\\\]#', $value) === 1;
            $target = $abs ? $value : dirname($real) . '/' . $value;
            if (realpath($target) === false || !is_file($target)) {
                return ['ok' => false, 'reason' => sprintf('extend "%s": 파일이 없음', $value), 'files' => []];
            }
            if (!self::inside($target, $root)) {
                return ['ok' => false, 'reason' => sprintf('extend "%s": 프로젝트 디렉토리 밖을 가리킴', $value), 'files' => []];
            }
            $sub = $this->checkChain($target, $root, $depth + 1, $seen);
            if (!$sub['ok']) {
                return $sub;
            }
            $files = [...$files, ...$sub['files']];
        }
        return ['ok' => true, 'reason' => '', 'files' => $files];
    }

    /** @param list<string> $files */
    public static function hashFiles(array $files, string $extra = ''): string
    {
        $ctx = hash_init('sha256');
        foreach ($files as $f) {
            hash_update($ctx, str_replace("\r\n", "\n", (string) file_get_contents($f)));
            hash_update($ctx, "\0");
        }
        hash_update($ctx, $extra);
        return hash_final($ctx);
    }
}
