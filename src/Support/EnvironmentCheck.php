<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 환경 점검 항목(P20A로 tools/check_env.php에서 옮김). CLI(check_env.php)와 관리자 점검 화면이 같은 함수를 쓴다.
 * 여기 항목은 **프로세스를 띄우지 않는다**(웹에서도 부를 수 있게). Python·Node·도구 실행 확인처럼 프로세스가 필요한 항목은
 * check_env.php(CLI)에만 있고, 관리자 화면은 Node 상태를 워커의 생존 신호에서 읽는다.
 *
 * 항목: ['section','id','status'(ok|warn|fail),'value'(표시 값, 경로가 들어갈 수 있음 → 웹은 Redact),'note'(문자열 키|null),'params']
 * 라벨은 문자열 리소스 env.item.<id>, 설명은 env.note.<note>.
 */
final class EnvironmentCheck
{
    public const REQUIRED_EXTENSIONS = ['pdo_sqlite', 'zip', 'mbstring', 'openssl', 'fileinfo', 'intl'];

    /**
     * @param array<string, mixed> $params
     * @return array{section:string, id:string, status:string, value:string, note:?string, params:array<string, mixed>}
     */
    private static function item(string $section, string $id, string $status, string $value, ?string $note = null, array $params = []): array
    {
        return ['section' => $section, 'id' => $id, 'status' => $status, 'value' => $value, 'note' => $note, 'params' => $params];
    }

    /** @return list<array<string, mixed>> OS·PHP·확장·SQLite·proc_open */
    public static function platform(): array
    {
        $out = [];
        $os = in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true);
        $out[] = self::item('platform', 'os', $os ? 'ok' : 'fail', PHP_OS_FAMILY . ' (' . php_uname('r') . ')', $os ? null : 'unsupported_os');
        $php = PHP_VERSION_ID >= 80200;
        $out[] = self::item('platform', 'php_version', $php ? 'ok' : 'fail', PHP_VERSION . ' (' . PHP_SAPI . ')', $php ? null : 'php_too_old');
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $ok = extension_loaded($ext);
            $out[] = self::item('extensions', 'ext', $ok ? 'ok' : 'fail', $ext, $ok ? null : 'ext_missing', ['ext' => $ext]);
        }
        if (PHP_OS_FAMILY === 'Linux') {
            // 워커(CLI)가 쓰는 확장: posix(프로세스 그룹), pcntl(정지 신호). 웹 PHP와 CLI PHP의 설정이 다를 수 있다
            foreach (['posix', 'pcntl'] as $ext) {
                $ok = extension_loaded($ext);
                $out[] = self::item('extensions', 'ext', $ok ? 'ok' : 'warn', $ext, $ok ? null : 'ext_linux_worker', ['ext' => $ext]);
            }
        }
        if (extension_loaded('pdo_sqlite')) {
            $ver = (string) (new \PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
            $ok = Db::versionAtLeast($ver, Db::MIN_SQLITE_VERSION);
            $out[] = self::item('platform', 'sqlite_version', $ok ? 'ok' : 'fail', $ver, $ok ? null : 'sqlite_too_old', ['min' => Db::MIN_SQLITE_VERSION]);
        }
        $proc = self::procOpenAvailable();
        $out[] = self::item('platform', 'proc_open', $proc ? 'ok' : 'warn', $proc ? 'on' : 'off', $proc ? null : 'proc_open_disabled');
        return $out;
    }

    /** proc_open을 쓸 수 있는지(disable_functions에 없는지) */
    public static function procOpenAvailable(): bool
    {
        if (!function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map(static fn (string $f): string => strtolower(trim($f)), explode(',', (string) ini_get('disable_functions')));
        return !in_array('proc_open', $disabled, true);
    }

    /** php.ini 크기 표기(8M, 1G)를 바이트로. -1(무제한)은 -1 */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $num = (int) $value;
        return match (strtoupper(substr($value, -1))) {
            'G' => $num * 1024 ** 3,
            'M' => $num * 1024 ** 2,
            'K' => $num * 1024,
            default => $num,
        };
    }

    /** @return list<array<string, mixed>> 청크 업로드에 필요한 php.ini 값(이 PHP 기준: 웹에서 부르면 웹 PHP, CLI면 CLI PHP) */
    public static function ini(int $chunkBytes): array
    {
        $out = [];
        $upload = (string) ini_get('upload_max_filesize');
        $b = self::iniBytes($upload);
        $out[] = self::item('ini', 'upload_max_filesize', $b === -1 || $b > $chunkBytes ? 'ok' : 'warn', $upload, $b !== -1 && $b <= $chunkBytes ? 'ini_below_chunk' : null);
        $post = (string) ini_get('post_max_size');
        $b = self::iniBytes($post);
        $out[] = self::item('ini', 'post_max_size', $b <= 0 || $b > $chunkBytes ? 'ok' : 'warn', $post, $b > 0 && $b <= $chunkBytes ? 'ini_below_chunk' : null);
        $mem = (string) ini_get('memory_limit');
        $b = self::iniBytes($mem);
        $out[] = self::item('ini', 'memory_limit', $b === -1 || $b >= 4 * $chunkBytes ? 'ok' : 'warn', $mem, $b !== -1 && $b < 4 * $chunkBytes ? 'ini_memory_low' : null);
        // CLI는 max_execution_time을 0으로 강제하므로 php.ini 파일 값을 읽는다
        $iniFile = php_ini_loaded_file();
        $raw = $iniFile !== false ? (@parse_ini_file($iniFile, false, INI_SCANNER_RAW) ?: []) : [];
        $met = PHP_SAPI === 'cli' ? (isset($raw['max_execution_time']) ? (int) $raw['max_execution_time'] : 30) : (int) ini_get('max_execution_time');
        $out[] = self::item('ini', 'max_execution_time', $met === 0 || $met >= 120 ? 'ok' : 'warn', $met . 's', $met !== 0 && $met < 120 ? 'ini_exec_time' : null);
        return $out;
    }

    /**
     * 폴더 쓰기 권한. data 폴더들은 실제로 작은 파일을 써 보고 지운다. bin/은 쓰지 않고 권한만 본다:
     * 웹($forWeb)에서 bin/에 쓸 수 있으면 경고(웹 계정은 bin/에 쓸 수 없어야 정상, docs/tool_setup.md 5.1).
     *
     * @param array<string, string> $dirs id → 폴더(db, uploads, work, snapshots, backups, logs)
     * @return list<array<string, mixed>>
     */
    public static function directories(array $dirs, string $binDir, bool $forWeb): array
    {
        $out = [];
        foreach ($dirs as $id => $dir) {
            if (!is_dir($dir)) {
                $out[] = self::item('dirs', 'dir', 'fail', $dir, 'dir_missing', ['name' => $id]);
                continue;
            }
            $probe = rtrim($dir, '/\\') . '/.write_probe_' . bin2hex(random_bytes(4));
            $ok = @file_put_contents($probe, 'x') === 1;
            if ($ok) {
                @unlink($probe);
            }
            $out[] = self::item('dirs', 'dir', $ok ? 'ok' : 'fail', $dir, $ok ? 'dir_writable' : 'dir_not_writable', ['name' => $id]);
        }
        if (!is_dir($binDir)) {
            $out[] = self::item('dirs', 'dir', 'warn', $binDir, 'dir_missing', ['name' => 'bin']);
        } elseif ($forWeb) {
            $w = self::writable($binDir);
            $out[] = self::item('dirs', 'dir', $w ? 'warn' : 'ok', $binDir, $w ? 'bin_writable_web' : 'bin_readonly_web', ['name' => 'bin']);
        } else {
            $out[] = self::item('dirs', 'dir', 'ok', $binDir, 'bin_cli', ['name' => 'bin']);
        }
        return $out;
    }

    /** P31: public/ 안에 있으면 안 되는 이름(웹에 노출) */
    public const PUBLIC_FORBIDDEN = ['config.ini', 'secrets.ini', 'settings.local.ini', '.git', '.env', 'vendor', 'composer.json', 'composer.lock', 'data', 'backups', 'bin', 'src'];

    /**
     * 보안 점검 항목(P31 A-4) — **알리되 막지 않는다**. 상태: ok·info(허용된 방침)·warn·unknown(이 OS에서 확인 불가).
     * - config/ 폴더 쓰기: 쓸 수 있으면 info(X14 방침 — 설정 화면이 settings.local.ini를 씀). 완화책(config.ini·secrets.ini 파일 단위 읽기 전용)은 각 파일 줄에서.
     * - config.ini·secrets.ini 자체가 이 계정에 쓰기 가능이면 warn. secrets.ini가 웹 루트 안이면 fail, Linux에서 다른 사용자도 읽을 수 있으면(o+r) warn, Windows는 권한 unknown.
     * - public/ 안의 금지 이름(설정·.git·vendor·백업·데이터)이 있으면 fail.
     * - display_errors·expose_php 켜짐 warn, 세션은 DB(sessions 표)라 PHP 세션 파일 위치를 쓰지 않음(info).
     *
     * @return list<array<string, mixed>>
     */
    /**
     * 신뢰 프록시(P32B): 이 점검 요청을 기준으로 — 전달 헤더(X-Forwarded-For)가 왔는데 목록이 비어 있으면 "확인 필요"(정보 — 프록시 뒤라면 사용자 주소가
     * 프록시 주소로 보임), 목록이 있으면 이 요청이 그 프록시를 거쳤는지. 판정을 바꾸는 것은 아니다(알리기만).
     *
     * @param list<string> $trusted
     * @return list<array<string, mixed>>
     */
    public static function proxy(array $trusted, string $remote, bool $forwarded): array
    {
        $value = $trusted === [] ? '—' : implode(', ', $trusted);
        if ($trusted === []) {
            return [self::item('security', 'trusted_proxies', $forwarded ? 'info' : 'ok', $value, $forwarded ? 'proxy_headers_untrusted' : 'proxy_none')];
        }
        $via = \PJGuardian\Support\IpList::contains($remote, $trusted);
        return [self::item('security', 'trusted_proxies', $via ? 'ok' : 'info', $value, $via ? 'proxy_trusted' : 'proxy_not_via')];
    }

    public static function security(string $configDir, string $publicDir): array
    {
        $out = [];
        $configDir = rtrim(str_replace('\\', '/', $configDir), '/');
        $dirW = is_dir($configDir) && is_writable($configDir);
        $out[] = self::item('security', 'config_dir', $dirW ? 'info' : 'ok', 'config/', $dirW ? 'config_dir_writable_policy' : 'config_dir_readonly');
        foreach (['config.ini', 'secrets.ini'] as $name) {
            $file = $configDir . '/' . $name;
            if (!is_file($file)) {
                $out[] = self::item('security', 'config_file', $name === 'secrets.ini' ? 'info' : 'warn', $name, $name === 'secrets.ini' ? 'secrets_absent' : 'file_missing', ['name' => $name]);
                continue;
            }
            $w = is_writable($file);
            $out[] = self::item('security', 'config_file', $w ? 'warn' : 'ok', $name, $w ? 'config_file_writable' : 'config_file_readonly', ['name' => $name]);
        }
        $secrets = $configDir . '/secrets.ini';
        if (is_file($secrets)) {
            $real = self::norm((string) realpath($secrets));
            $pub = self::norm((string) realpath($publicDir));
            if ($pub !== '' && str_starts_with($real . '/', $pub . '/')) {
                $out[] = self::item('security', 'secrets_location', 'fail', 'secrets.ini', 'secrets_in_public');
            } else {
                $out[] = self::item('security', 'secrets_location', 'ok', 'secrets.ini', 'secrets_outside_public');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                $out[] = self::item('security', 'secrets_perm', 'unknown', 'secrets.ini', 'perm_unknown_windows');
            } else {
                $mode = fileperms($secrets) & 0o777;
                $wide = ($mode & 0o007) !== 0;
                $out[] = self::item('security', 'secrets_perm', $wide ? 'warn' : 'ok', sprintf('%04o', $mode), $wide ? 'secrets_perm_wide' : 'secrets_perm_ok');
            }
        }
        $found = [];
        foreach (self::PUBLIC_FORBIDDEN as $name) {
            if (file_exists(rtrim($publicDir, '/\\') . '/' . $name)) {
                $found[] = $name;
            }
        }
        foreach (glob(rtrim($publicDir, '/\\') . '/*.{pjgbak,sqlite,zip,bak,sql}', GLOB_BRACE) ?: [] as $f) {
            $found[] = basename($f);
        }
        $out[] = self::item('security', 'public_exposure', $found === [] ? 'ok' : 'fail', $found === [] ? 'public/' : implode(', ', $found),
            $found === [] ? 'public_clean' : 'public_exposed');
        $de = filter_var(ini_get('display_errors'), FILTER_VALIDATE_BOOLEAN) || in_array(strtolower((string) ini_get('display_errors')), ['stdout', 'stderr'], true);
        $out[] = self::item('security', 'display_errors', $de ? 'warn' : 'ok', $de ? 'On' : 'Off', $de ? 'display_errors_on' : null);
        $ep = filter_var(ini_get('expose_php'), FILTER_VALIDATE_BOOLEAN);
        $out[] = self::item('security', 'expose_php', $ep ? 'warn' : 'ok', $ep ? 'On' : 'Off', $ep ? 'expose_php_on' : null);
        $out[] = self::item('security', 'session_store', 'info', 'DB', 'session_in_db');
        return $out;
    }

    private static function norm(string $p): string
    {
        $p = rtrim(str_replace('\\', '/', $p), '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p;
    }

    /** 쓰기 권한 판정(파일을 만들지 않음). Windows에서 is_writable은 읽기 전용 속성만 보므로 ACL은 반영하지 못할 수 있다(미확인) */
    private static function writable(string $dir): bool
    {
        return is_writable($dir);
    }

    /**
     * @return array{free:?float, total:?float, used_percent:?float, status:string}
     */
    public static function disk(string $dir, int $warnPercent, int $blockPercent): array
    {
        $free = @disk_free_space($dir);
        $total = @disk_total_space($dir);
        if ($free === false || $total === false || $total <= 0) {
            return ['free' => null, 'total' => null, 'used_percent' => null, 'status' => 'warn'];
        }
        $pct = (1 - $free / $total) * 100;
        return ['free' => (float) $free, 'total' => (float) $total, 'used_percent' => round($pct, 1),
            'status' => $pct >= $blockPercent ? 'fail' : ($pct >= $warnPercent ? 'warn' : 'ok')];
    }
}
