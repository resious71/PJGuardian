<?php

declare(strict_types=1);

namespace PJGuardian\Services;

use PJGuardian\Support\Config;
use PJGuardian\Support\Paths;

/**
 * XAMPP·앱 하드닝 점검(명세 19.5). 최초 관리자 설치 스크립트(tools/create_admin.php)가 실행해 미충족 항목을 경고한다.
 * 체크리스트 원문은 docs/hardening.md.
 *
 * 결과 상태: OK(충족) | WARN(미충족) | INFO(자동으로 확인할 수 없어 안내만)
 * - php.ini 값은 CLI가 읽은 php.ini 파일 기준(XAMPP Windows는 CLI와 Apache가 같은 파일).
 * - HTTP 점검은 실제 서버에 요청해 응답 헤더·상태를 본다($fetch 주입). 접속하지 못하면 INFO로 남긴다.
 */
final class HardeningCheck
{
    /** @var list<array{status:string, item:string, detail:string}> */
    private array $results = [];

    /**
     * @param array<string, string> $phpIni php.ini 원본 값(parse_ini_file RAW)
     * @param (\Closure(string): ?array{status:int, headers:array<string,string>})|null $fetch 경로 → 응답(실패 시 null)
     */
    public function __construct(
        private readonly string $root,
        private readonly Config $config,
        private readonly Paths $paths,
        private readonly array $phpIni,
        private readonly ?\Closure $fetch = null,
        private readonly ?string $xamppDir = null,
        /** @var (\Closure(): list<string>)|null 초기 비밀번호를 바꾸지 않은 활성 관리자 로그인 ID 목록(X02) */
        private readonly ?\Closure $initialPasswordPending = null,
    ) {
    }

    /** @return list<array{status:string, item:string, detail:string}> */
    public function run(): array
    {
        $this->results = [];
        $this->phpSettings();
        $this->appSettings();
        $this->directories();
        $this->xamppSamples();
        $this->accounts();
        $this->http();
        $this->manual();
        return $this->results;
    }

    private function add(string $status, string $item, string $detail): void
    {
        $this->results[] = ['status' => $status, 'item' => $item, 'detail' => $detail];
    }

    private static function iniOff(?string $v): bool
    {
        return $v !== null && in_array(strtolower(trim($v)), ['0', 'off', 'false', 'no', '', 'stderr'], true);
    }

    private function phpSettings(): void
    {
        $de = $this->phpIni['display_errors'] ?? null;
        $this->add(self::iniOff($de) ? 'OK' : 'WARN', 'display_errors', self::iniOff($de)
            ? 'Off'
            : 'php.ini의 display_errors = ' . ($de ?? '(기본값 On)') . ' — Off로 바꾸세요(오류 내용이 화면에 노출됨)');
        $ep = $this->phpIni['expose_php'] ?? null;
        $this->add(self::iniOff($ep) ? 'OK' : 'WARN', 'expose_php', self::iniOff($ep)
            ? 'Off'
            : 'php.ini의 expose_php = ' . ($ep ?? '(기본값 On)') . ' — Off로 바꾸세요(PHP 버전 헤더 노출)');
        $log = $this->phpIni['log_errors'] ?? null;
        $this->add($log !== null && !self::iniOff($log) ? 'OK' : 'WARN', 'log_errors', $log !== null && !self::iniOff($log)
            ? 'On'
            : 'php.ini의 log_errors를 On으로(오류는 화면 대신 로그에)');
    }

    private function appSettings(): void
    {
        $https = $this->config->bool('security.require_https');
        $base = $this->config->string('app.base_url');
        $this->add($https && str_starts_with($base, 'https://') ? 'OK' : 'WARN', 'HTTPS 강제', $https
            ? "security.require_https = true, app.base_url = {$base}"
            : 'security.require_https = false — 운영에서는 true(명세 10: HTTPS만 허용, 세션 쿠키 Secure)');
        $hsts = $this->config->int('security.hsts_max_age');
        $this->add($hsts >= 180 * 86400 ? 'OK' : 'WARN', 'HSTS max-age', $hsts . '초' . ($hsts >= 180 * 86400 ? '' : ' — 180일 이상 권장'));
        $env = $this->config->string('app.env');
        $this->add($env === 'prod' ? 'OK' : 'WARN', 'app.env', $env === 'prod' ? 'prod' : 'dev — 운영에서는 prod(개발 전용 화면 /dev/styleguide가 열림)');
        $cost = $this->config->int('auth.bcrypt_cost');
        $this->add($cost >= 10 ? 'OK' : 'WARN', 'bcrypt 비용', $cost . ($cost >= 10 ? '' : ' — 10 이상(4~9는 테스트 전용)'));
    }

    private function directories(): void
    {
        $public = self::norm($this->root . '/public');
        $targets = [
            'data(DB·업로드·로그)' => $this->paths->dbFile(),
            'config' => $this->root . '/config',
            'bin' => $this->paths->bin(),
            'logs' => $this->paths->logs(),
            'uploads' => $this->paths->uploads(),
        ];
        $bad = [];
        foreach ($targets as $label => $path) {
            if (str_starts_with(self::norm($path) . '/', $public . '/')) {
                $bad[] = $label;
            }
        }
        $this->add($bad === [] ? 'OK' : 'WARN', '웹 루트 밖 저장', $bad === []
            ? 'DB·설정·실행 파일·로그·업로드가 모두 public/ 밖에 있음(명세 2, 10)'
            : implode(', ', $bad) . '이(가) public/ 안에 있음 — 웹 루트 밖으로 옮기세요');
        $extra = [];
        foreach (scandir($this->root . '/public') ?: [] as $f) {
            if (preg_match('/\.(sqlite|db|ini|log|zip|bak|sql)$/i', $f) === 1 || in_array($f, ['phpinfo.php', 'info.php', 'test.php'], true)) {
                $extra[] = $f;
            }
        }
        $this->add($extra === [] ? 'OK' : 'WARN', 'public/의 불필요한 파일', $extra === [] ? '없음' : implode(', ', $extra) . ' — 지우세요');
    }

    private function xamppSamples(): void
    {
        if ($this->xamppDir === null) {
            $this->add('INFO', 'XAMPP 샘플·관리 페이지', 'XAMPP 설치 폴더를 찾지 못함 — htdocs의 dashboard, xampp, phpMyAdmin, webalizer를 지우거나 막았는지 직접 확인하세요');
            return;
        }
        $found = [];
        foreach (['htdocs/dashboard', 'htdocs/xampp', 'htdocs/webalizer', 'phpMyAdmin', 'webdav'] as $rel) {
            if (is_dir($this->xamppDir . '/' . $rel)) {
                $found[] = $rel;
            }
        }
        $this->add($found === [] ? 'OK' : 'WARN', 'XAMPP 샘플·관리 페이지', $found === []
            ? '없음'
            : $this->xamppDir . '에 ' . implode(', ', $found) . ' 있음 — 지우거나 httpd-xampp.conf에서 막으세요(docs/hardening.md)');
    }

    private function http(): void
    {
        if ($this->fetch === null) {
            $this->add('INFO', 'HTTP 점검', '건너뜀(--skip-http). 보안 헤더와 디렉토리 차단은 브라우저나 curl로 직접 확인하세요');
            return;
        }
        $res = ($this->fetch)('/api/v1/meta/version');
        if ($res === null) {
            $this->add('INFO', 'HTTP 점검', $this->config->string('app.base_url') . '에 접속하지 못함 — 서버 실행, hosts, 인증서를 확인한 뒤 다시 실행하세요(--url, --resolve-local)');
            return;
        }
        $h = array_change_key_case($res['headers']);
        $need = [
            'content-security-policy' => static fn (string $v): bool => str_contains($v, "script-src 'self'") && !str_contains($v, 'unsafe-inline'),
            'x-frame-options' => static fn (string $v): bool => in_array(strtoupper($v), ['DENY', 'SAMEORIGIN'], true),
            'x-content-type-options' => static fn (string $v): bool => strtolower($v) === 'nosniff',
            'referrer-policy' => static fn (string $v): bool => $v !== '',
            'strict-transport-security' => static fn (string $v): bool => str_contains($v, 'max-age='),
        ];
        $missing = [];
        foreach ($need as $name => $ok) {
            if (!isset($h[$name]) || !$ok($h[$name])) {
                $missing[] = $name;
            }
        }
        $this->add($missing === [] ? 'OK' : 'WARN', '보안 헤더 응답', $missing === []
            ? 'CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, HSTS 확인'
            : '없거나 약함: ' . implode(', ', $missing));
        $this->add(isset($h['x-powered-by']) ? 'WARN' : 'OK', 'X-Powered-By 헤더', isset($h['x-powered-by']) ? '노출됨: ' . $h['x-powered-by'] : '없음');
        $server = $h['server'] ?? '';
        $this->add(preg_match('#/\d#', $server) === 1 ? 'WARN' : 'OK', 'Server 헤더', $server === '' ? '없음'
            : $server . (preg_match('#/\d#', $server) === 1 ? ' — 버전 노출(ServerTokens Prod, ServerSignature Off)' : ''));

        $exposed = [];
        foreach (['/config/config.ini', '/config/secrets.ini', '/data/db/pjguardian.sqlite', '/data/logs/', '/bin/', '/src/App.php', '/migrations/001_initial_schema.sql', '/.git/config'] as $path) {
            $r = ($this->fetch)($path);
            if ($r !== null && $r['status'] === 200 && !str_starts_with(strtolower($r['headers']['content-type'] ?? $r['headers']['Content-Type'] ?? ''), 'text/html')) {
                $exposed[] = $path;
            }
        }
        $this->add($exposed === [] ? 'OK' : 'WARN', 'data·config·bin 웹 접근 차단', $exposed === []
            ? '웹에서 열리지 않음(404 또는 앱 화면)'
            : '웹에서 열림: ' . implode(', ', $exposed) . ' — DocumentRoot를 public/으로(docs/xampp_vhost.md)');

        $samples = [];
        foreach (['/phpmyadmin/', '/dashboard/', '/xampp/', '/webalizer/', '/server-status', '/server-info'] as $path) {
            $r = ($this->fetch)($path);
            // 앱의 404 페이지는 text/html 404다. 200이거나 401(인증 요구)이면 다른 앱이 응답한 것
            if ($r !== null && in_array($r['status'], [200, 401], true)) {
                $samples[] = $path . ' (' . $r['status'] . ')';
            }
        }
        $this->add($samples === [] ? 'OK' : 'WARN', '관리·샘플 페이지 웹 노출', $samples === []
            ? '열리지 않음'
            : implode(', ', $samples) . ' — httpd-xampp.conf의 Alias·핸들러를 지우세요'
                . '(서버 자신에서 점검하면 Require local 설정도 열려 보이므로, 다른 PC에서 한 번 더 확인)');
    }

    /**
     * 최초 관리자의 고정 초기 비밀번호(X02)를 아직 바꾸지 않은 계정. 비밀번호 값을 비교하지 않고
     * 발급 방식(users.password_kind = 'initial')으로 판정한다.
     */
    private function accounts(): void
    {
        if ($this->initialPasswordPending === null) {
            $this->add('INFO', '관리자 초기 비밀번호', 'DB를 읽지 않아 확인하지 않음');
            return;
        }
        $pending = ($this->initialPasswordPending)();
        $this->add($pending === [] ? 'OK' : 'WARN', '관리자 초기 비밀번호', $pending === []
            ? '초기 비밀번호를 바꾸지 않은 활성 관리자 없음'
            : implode(', ', $pending) . (\PJGuardian\App::isLocalBaseUrl($this->config->string('app.base_url'))
                ? ' — 초기 비밀번호를 아직 바꾸지 않음. 운영에서는 즉시 로그인해 변경하세요(docs/hardening.md 0절)'
                : ' — 초기 비밀번호(admin)가 그대로이고 app.base_url이 이 PC 밖에서 접속하는 주소입니다. 누구나 알 수 있는 비밀번호이므로 지금 바로 바꾸세요(막지는 않음, docs/hardening.md 0절)'));
    }

    private function manual(): void
    {
        $this->add('INFO', '기본 계정', 'XAMPP MySQL/MariaDB를 쓰지 않으면 서비스를 끄고, 쓴다면 root 비밀번호를 설정하세요(PJGuardian은 SQLite만 사용)');
        $this->add('INFO', '불필요한 Apache 모듈', 'mod_status, mod_info, mod_autoindex, mod_userdir, WebDAV(mod_dav*) 등을 끄세요(docs/hardening.md)');
        $this->add('INFO', '보안 패치', 'PHP, Apache, Node.js, Python 보안 패치를 분기 1회 점검하세요(명세 19.5)');
    }

    private static function norm(string $path): string
    {
        $real = realpath($path);
        $p = str_replace('\\', '/', $real !== false ? $real : $path);
        $p = rtrim($p, '/');
        return PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p;
    }
}
