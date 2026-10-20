<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\Http\Response;
use PJGuardian\Services\Upload\DiskGuard;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 비밀 노출 스캔(P31 A-2): 시험 중에 **표식 비밀값**을 여러 경로로 넣고(로그인 비밀번호·새 비밀번호·저장소 토큰·LDAP 검색 계정 비밀번호·
 * OIDC 클라이언트 비밀·SMTP 비밀번호·암호화 키), 그 뒤에 나온 모든 것을 훑는다:
 *   주고받은 모든 응답 본문·머리글, 앱 로그·경고 로그 파일 전부, 감사 로그 행 전체, 화면 HTML(관리자 화면 포함), /health, CSV 내보내기,
 *   DB 평문(비밀은 app_secrets·repo_connections에 암호문으로만 — 표식이 그대로 든 열이 없어야 함).
 * 알려진 비밀 모양(개인 키 PEM, 세션 ID 64자리, 서버 절대 경로)도 응답에 없어야 한다(세션 쿠키 머리글·내려받기 본문 제외).
 */
final class SecretLeakScanTest extends TestCase
{
    private const KEY = 'cDMxLWxlYWstc2Nhbi1lbmNyeXB0aW9uLWtleS0zMmI=';
    private const MARK = [
        'login_pw' => 'PJGLEAK-login-Zx81!',
        'new_pw' => 'PJGLEAK-newpw-Qw72!',
        'repo_token' => 'PJGLEAK-repotoken-3f9a',
        'ldap_pw' => 'PJGLEAK-ldapbind-7c1d',
        'oidc_secret' => 'PJGLEAK-oidcsecret-2b6e',
        'smtp_pw' => 'PJGLEAK-smtp-9e4f',
    ];

    /** @var list<string> */
    private array $seen = [];

    private function keep(Response $r): Response
    {
        $h = '';
        foreach ($r->headers() as $k => $v) {
            if (strtolower($k) !== 'set-cookie') {
                $h .= "{$k}: {$v}\n";
            }
        }
        $this->seen[] = $h . $r->body;
        return $r;
    }

    public function testMarkedSecretsNeverShowUpOutsideEncryptedStorage(): void
    {
        $dir = $this->tempDir('leak');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work'], 'health' => ['allowed_ips' => '127.0.0.1'],
            'alert_mail' => ['smtp_host' => 'mail.example.test', 'from_address' => 'pjg@example.test', 'smtp_user' => 'mailer']], ['encryption_key' => self::KEY]);
        @mkdir($dir . '/work');
        $db = $app->db();
        $admin = TestDb::user($db, 'admin', 'admin');
        TestDb::user($db, 'kim', 'user');
        $p = TestDb::project($db, 'p');
        $an = TestDb::analysis($db, $p, $admin);
        TestDb::defect($db, $p, $an);
        $t = TestDb::T0;
        $db->execute("INSERT INTO repo_allowed_hosts (host, port, allow_http, github_links, note, created_at, updated_at) VALUES ('ghe.corp', 0, 0, 1, '', ?, ?)", [$t, $t]);
        $app->setDiskGuard(new DiskGuard($db, $app->uploadSettings(), $app->logger, static fn (): float => 1e12, static fn (): float => 2e12));

        // 1) 틀린 비밀번호 로그인(로그·감사에 비밀번호 없음)
        $anon = new TestClient($app);
        $anon->fetchCsrf();
        $this->keep($anon->post('/api/v1/auth/login', ['login_id' => 'admin', 'password' => self::MARK['login_pw']]));
        $this->keep($anon->post('/api/v1/auth/login', ['login_id' => self::MARK['login_pw'], 'password' => self::MARK['login_pw']]));

        $c = new TestClient($app);
        $this->keep($c->login('admin', 'Passw0rd!x'));
        $sessionId = (string) $c->sessionCookie();
        // 2) 비밀 저장 API들
        $this->keep($c->put("/api/v1/projects/{$p}/repo", ['kind' => 'git', 'url' => 'https://ghe.corp/team/app.git', 'branch' => 'main', 'secret' => self::MARK['repo_token']]));
        $this->keep($c->put('/api/v1/admin/auth/ldap/bind-password', ['password' => self::MARK['ldap_pw']]));
        $this->keep($c->put('/api/v1/admin/auth/oidc/client-secret', ['secret' => self::MARK['oidc_secret']]));
        $this->keep($c->put('/api/v1/admin/alert-mail/password', ['password' => self::MARK['smtp_pw']]));
        // 3) 오류 응답에 입력이 되돌아오지 않음
        $this->keep($c->put('/api/v1/admin/config-settings', ['changes' => ['disk.warn_percent' => self::MARK['smtp_pw']]]));
        $this->keep($c->post('/api/v1/users', ['login_id' => self::MARK['ldap_pw'] . '!!', 'display_name' => 'x']));
        // 4) 본인 비밀번호 변경(틀린 현재 비밀번호 → 맞는 것)
        $this->keep($c->post('/api/v1/auth/password', ['current_password' => self::MARK['login_pw'], 'new_password' => self::MARK['new_pw']]));
        $this->keep($c->post('/api/v1/auth/password', ['current_password' => 'Passw0rd!x', 'new_password' => self::MARK['new_pw']]));
        // 5) 읽기: 상태·설정·감사·모니터링·화면·내보내기·/health
        foreach (["/api/v1/projects/{$p}/repo", '/api/v1/admin/auth/ldap', '/api/v1/admin/auth/oidc', '/api/v1/admin/alert-mail', '/api/v1/admin/config-settings',
            '/api/v1/admin/audit-logs', '/api/v1/admin/monitoring', '/api/v1/admin/checks', '/api/v1/auth/me', '/api/v1/users', '/health', '/api/v1/meta/version',
            '/admin/config-settings', '/admin/monitoring', '/admin/audit-logs', '/admin/checks', "/projects/{$p}", '/account', '/api/v1/admin/logs'] as $path) {
            $this->keep($c->get($path));
        }
        foreach ($c->get('/api/v1/admin/audit-logs')->decoded()['data'] ?? [] as $row) {
            $this->keep($c->get('/api/v1/admin/audit-logs/' . $row['id']));
        }
        $csv = $this->keep($c->post('/api/v1/admin/audit-logs/export', ['format' => 'csv']));
        $this->assertSame(200, $csv->status, $csv->body);

        // 훑을 것: 응답 + 로그 파일 + 감사 행 + DB 평문(암호문 열 제외)
        $hay = implode("\n", $this->seen);
        $logs = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (preg_match('/\.(log|jsonl?)$/', $f->getFilename()) === 1) {
                $logs .= (string) file_get_contents($f->getPathname()) . "\n";
            }
        }
        $this->assertTrue(str_contains($logs, '로그인'), '로그가 실제로 쌓였음');
        $audit = json_encode($db->all('SELECT * FROM audit_log'), JSON_UNESCAPED_UNICODE);
        $plain = '';
        foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table'") as $tbl) {
            $plain .= json_encode($db->all('SELECT * FROM "' . str_replace('"', '', (string) $tbl['name']) . '"'), JSON_UNESCAPED_UNICODE);
        }
        foreach (self::MARK as $what => $secret) {
            $this->assertFalse(str_contains($hay, $secret), "응답에 {$what}");
            $this->assertFalse(str_contains($logs, $secret), "로그에 {$what}");
            $this->assertFalse(str_contains((string) $audit, $secret), "감사 로그에 {$what}");
            $this->assertFalse(str_contains($plain, $secret), "DB 평문에 {$what}");
        }
        $this->assertSame(self::MARK['smtp_pw'], $app->appSecrets()->get('mail.smtp_password'), '저장은 됐고 복호화로만 읽힘');
        $this->assertFalse(str_contains($hay . $logs, self::KEY), '암호화 키');
        $this->assertFalse($sessionId !== '' && str_contains($hay . $logs . $audit, $sessionId), '세션 ID(쿠키 머리글 외)');
        $this->assertFalse(str_contains($hay, '-----BEGIN') && str_contains($hay, 'PRIVATE KEY'), '개인 키 PEM');
        foreach ([PJG_ROOT, str_replace('/', '\\', PJG_ROOT), str_replace('\\', '/', $dir)] as $path) {
            $this->assertFalse(str_contains($hay, $path), "응답에 서버 절대 경로 {$path}");
        }
    }
}
