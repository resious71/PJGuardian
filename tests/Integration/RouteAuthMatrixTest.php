<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Integration;

use PJGuardian\App;
use PJGuardian\Http\Middleware\CsrfMiddleware;
use PJGuardian\Http\Response;
use PJGuardian\Http\Router;
use PJGuardian\Tests\Support\TestApp;
use PJGuardian\Tests\Support\TestClient;
use PJGuardian\Tests\Support\TestDb;
use PJGuardian\Tests\TestCase;

/**
 * 권한·인증 행렬(P31 A-1): 등록된 모든 라우트 × 역할 5개(비로그인·임시 비밀번호·일반 사용자·그룹 관리자·admin)를 실제 요청으로 확인한다.
 *
 * - 기대값은 아래 EXPECTED 표다 — 라우트 등록(미들웨어 선언)을 읽어 만들지 않고 **손으로 적어 고정**한다. 새 라우트가 표에 없거나
 *   표에만 있는 라우트가 있으면 실패한다(누락 방지). 표를 고칠 때는 그 라우트가 누구에게 열려야 하는지를 먼저 정하고 적는다.
 *   P 공개 · L 로그인만(임시 비밀번호 사용자도) · A 로그인 + 비밀번호 변경 완료 · D admin
 *   J 프로젝트 보기(보이지 않으면 404) · JA 프로젝트 관리(보이지 않으면 404, 보이지만 admin이 아니면 403)
 * - 거부되어야 하는 역할은 **실제 핸들러까지** 보낸다(핸들러 안의 판정 — 그룹 권한·가이드 그림 등 — 도 확인, 거부는 부작용 전에 끝남).
 *   허용되어야 하는 역할은 부작용 없는 대역 핸들러로 바꾼 앱에 보내 미들웨어를 모두 통과하는지만 본다(같은 전역 미들웨어·세션·CSRF).
 * - HANDLER 표: 미들웨어는 통과하지만 핸들러가 역할별로 거부하는 라우트(손으로 적음).
 * - 프로젝트 1은 그룹 1(그룹 관리자 mgr)에 매핑, 일반 사용자 kim은 매핑 없음 → 프로젝트·자식(업로드·분석·결함) 경로는 kim에게 404.
 * - CSRF: 상태를 바꾸는 모든 라우트가 토큰 없이 403 csrf_invalid(예외는 CSRF_EXEMPT 표와 같아야 함).
 */
final class RouteAuthMatrixTest extends TestCase
{
    private const UPLOAD = '0123456789abcdef0123456789abcdef';
    private const STAGING = 'fedcba9876543210fedcba9876543210';

    /** 손으로 적은 기대 수준 */
    private const EXPECTED = [
        'GET /api/v1/meta/version' => 'P',
        'GET /api/v1/auth/csrf' => 'P',
        'POST /api/v1/auth/login' => 'P',
        'POST /api/v1/auth/logout' => 'P',
        'GET /api/v1/auth/me' => 'L',
        'POST /api/v1/auth/password' => 'L',
        'GET /api/v1/users/me' => 'A',
        'PATCH /api/v1/users/me' => 'A',
        'PUT /api/v1/users/me/preferences' => 'A',
        'GET /api/v1/users' => 'D',
        'POST /api/v1/users' => 'D',
        'GET /api/v1/users/{id}' => 'D',
        'PATCH /api/v1/users/{id}' => 'D',
        'PUT /api/v1/users/{id}/role' => 'D',
        'PUT /api/v1/users/{id}/status' => 'D',
        'POST /api/v1/users/{id}/temp-password' => 'D',
        'PUT /api/v1/users/{id}/password' => 'D',
        'POST /api/v1/users/{id}/unlock' => 'D',
        'POST /api/v1/users/{id}/approve' => 'D',
        'POST /api/v1/users/{id}/reject' => 'D',
        'DELETE /api/v1/users/{id}' => 'D',
        'PUT /api/v1/users/{id}/auth-type' => 'D',
        'GET /api/v1/admin/auth/ldap' => 'D',
        'PUT /api/v1/admin/auth/ldap/bind-password' => 'D',
        'DELETE /api/v1/admin/auth/ldap/bind-password' => 'D',
        'POST /api/v1/admin/auth/ldap/test' => 'D',
        'GET /api/v1/admin/auth/oidc' => 'D',
        'PUT /api/v1/admin/auth/oidc/client-secret' => 'D',
        'DELETE /api/v1/admin/auth/oidc/client-secret' => 'D',
        'POST /api/v1/admin/auth/oidc/test' => 'D',
        'GET /api/v1/admin/auth/saml' => 'D',
        'POST /api/v1/admin/auth/saml/sp-key' => 'D',
        'DELETE /api/v1/admin/auth/saml/sp-key' => 'D',
        'POST /api/v1/admin/auth/saml/metadata-import' => 'D',
        'POST /api/v1/admin/auth/saml/test' => 'D',
        'GET /api/v1/admin/alert-mail' => 'D',
        'PUT /api/v1/admin/alert-mail/password' => 'D',
        'DELETE /api/v1/admin/alert-mail/password' => 'D',
        'POST /api/v1/admin/alert-mail/test' => 'D',
        'GET /api/v1/admin/account-import/template' => 'D',
        'POST /api/v1/admin/account-import/preview' => 'D',
        'POST /api/v1/admin/account-import/execute' => 'D',
        'GET /api/v1/projects' => 'A',
        'POST /api/v1/projects' => 'D',
        'GET /api/v1/projects/default-excludes' => 'A',
        'GET /api/v1/projects/{id}' => 'J',
        'PATCH /api/v1/projects/{id}' => 'JA',
        'DELETE /api/v1/projects/{id}' => 'JA',
        'GET /api/v1/projects/{id}/deletion-info' => 'JA',
        'GET /api/v1/projects/{id}/access' => 'JA',
        'POST /api/v1/projects/{id}/access' => 'JA',
        'DELETE /api/v1/projects/{id}/access/{subjectType}/{subjectId}' => 'JA',
        'POST /api/v1/projects/{id}/uploads' => 'J',
        'GET /api/v1/uploads/{id}' => 'J',
        'DELETE /api/v1/uploads/{id}' => 'J',
        'GET /api/v1/uploads/{id}/status' => 'J',
        'PUT /api/v1/uploads/{id}/chunks/{index}' => 'J',
        'POST /api/v1/uploads/{id}/complete' => 'J',
        'POST /api/v1/uploads/{id}/confirm' => 'J',
        'GET /api/v1/projects/{id}/analyses' => 'J',
        'GET /api/v1/analyses/{id}' => 'J',
        'POST /api/v1/analyses/{id}/cancel' => 'J',
        'GET /api/v1/projects/{id}/defects' => 'J',
        'GET /api/v1/projects/{id}/defects/summary' => 'J',
        'GET /api/v1/defects/{id}' => 'J',
        'GET /api/v1/defects/{id}/history' => 'J',
        'GET /api/v1/defects/{id}/code' => 'J',
        'GET /api/v1/projects/{id}/history' => 'J',
        'GET /api/v1/analyses/{id}/comparison' => 'J',
        'GET /api/v1/defects/{id}/timeline' => 'J',
        'PATCH /api/v1/defects/{id}' => 'J',
        'POST /api/v1/projects/{id}/defects/bulk' => 'J',
        'GET /api/v1/projects/{id}/assignees' => 'J',
        'GET /api/v1/projects/{id}/runs' => 'J',
        'GET /api/v1/my-defects' => 'A',
        'GET /api/v1/my-defects/summary' => 'A',
        'POST /api/v1/projects/{id}/defects/export' => 'J',
        'POST /api/v1/my-defects/export' => 'A',
        'POST /api/v1/rules/export' => 'A',
        'GET /api/v1/dashboard/summary' => 'A',
        'GET /api/v1/dashboard/trend' => 'A',
        'GET /api/v1/dashboard/top-rules' => 'A',
        'GET /api/v1/dashboard/top-files' => 'A',
        'GET /api/v1/dashboard/tools' => 'A',
        'GET /api/v1/groups' => 'A',
        'POST /api/v1/groups' => 'A',
        'GET /api/v1/groups/{id}' => 'A',
        'PATCH /api/v1/groups/{id}' => 'A',
        'DELETE /api/v1/groups/{id}' => 'A',
        'GET /api/v1/groups/{id}/deletion-info' => 'A',
        'GET /api/v1/groups/{id}/members' => 'A',
        'POST /api/v1/groups/{id}/members' => 'A',
        'DELETE /api/v1/groups/{id}/members/{userId}' => 'A',
        'PUT /api/v1/groups/{id}/members/{userId}/manager' => 'A',
        'POST /api/v1/groups/{id}/accounts' => 'A',
        'GET /api/v1/users/search' => 'A',
        'GET /api/v1/admin/tools' => 'D',
        'POST /api/v1/admin/tools/node/refresh' => 'D',
        'POST /api/v1/admin/tools/{tool}/versions/{version}/block' => 'D',
        'POST /api/v1/admin/tools/{tool}/versions/{version}/unblock' => 'D',
        'POST /api/v1/admin/tools/{tool}/versions/{version}/pin' => 'D',
        'POST /api/v1/admin/tools/{tool}/versions/{version}/verify' => 'D',
        'POST /api/v1/admin/tools/{tool}/versions/{version}/removal' => 'D',
        'DELETE /api/v1/admin/tools/{tool}/versions/{version}/removal' => 'D',
        'POST /api/v1/admin/tools/{tool}/unpin' => 'D',
        'GET /api/v1/admin/tool-staging' => 'D',
        'POST /api/v1/admin/tool-staging' => 'D',
        'GET /api/v1/admin/tool-staging/{id}' => 'D',
        'PATCH /api/v1/admin/tool-staging/{id}' => 'D',
        'DELETE /api/v1/admin/tool-staging/{id}' => 'D',
        'PUT /api/v1/admin/tool-staging/{id}/chunks/{index}' => 'D',
        'POST /api/v1/admin/tool-staging/{id}/complete' => 'D',
        'GET /api/v1/admin/worker' => 'D',
        'POST /api/v1/admin/worker/stop' => 'D',
        'POST /api/v1/admin/worker/restart' => 'D',
        'POST /api/v1/admin/worker/start' => 'D',
        'GET /api/v1/admin/logs' => 'D',
        'GET /api/v1/admin/checks' => 'D',
        'POST /api/v1/admin/checks/disk-scan' => 'D',
        'POST /api/v1/admin/cleanup/run' => 'D',
        'GET /api/v1/admin/monitoring' => 'D',
        'GET /api/v1/admin/audit-logs' => 'D',
        'GET /api/v1/admin/audit-logs/integrity' => 'D',
        'GET /api/v1/admin/audit-logs/{id}' => 'D',
        'POST /api/v1/admin/audit-logs/export' => 'D',
        'GET /health' => 'P',
        'GET /api/v1/admin/projects/{id}/runs' => 'JA',
        'POST /api/v1/admin/projects/{id}/runs/delete-detail' => 'JA',
        'GET /api/v1/rules' => 'A',
        'GET /api/v1/rules/meta' => 'A',
        'GET /api/v1/rules/syntax-error' => 'A',
        'GET /api/v1/rules/{tool}/{rule}' => 'A',
        'POST /api/v1/admin/rules/reload' => 'D',
        'GET /api/v1/admin/rules/overrides' => 'D',
        'POST /api/v1/admin/rules/overrides/reset' => 'D',
        'POST /api/v1/admin/rules/overrides/bulk/preview' => 'D',
        'POST /api/v1/admin/rules/overrides/bulk' => 'D',
        'PUT /api/v1/admin/rules/syntax-error/override' => 'D',
        'DELETE /api/v1/admin/rules/syntax-error/override' => 'D',
        'PUT /api/v1/admin/rules/{tool}/{rule}/override' => 'D',
        'DELETE /api/v1/admin/rules/{tool}/{rule}/override' => 'D',
        'GET /api/v1/admin/settings' => 'D',
        'PATCH /api/v1/admin/settings' => 'D',
        'GET /api/v1/admin/config-settings' => 'D',
        'PUT /api/v1/admin/config-settings' => 'D',
        'POST /api/v1/admin/config-settings/validate' => 'D',
        'DELETE /api/v1/admin/config-settings/{key}' => 'D',
        'GET /api/v1/admin/backups' => 'D',
        'POST /api/v1/admin/backups' => 'D',
        'POST /api/v1/admin/backups/cancel' => 'D',
        'POST /api/v1/admin/backups/rehearse' => 'D',
        'POST /api/v1/admin/backups/key-export' => 'D',
        'GET /api/v1/admin/backups/download/{token}' => 'D',
        'POST /api/v1/admin/backups/{name}/verify' => 'D',
        'POST /api/v1/admin/backups/{name}/download' => 'D',
        'POST /api/v1/admin/backups/{name}/precheck' => 'D',
        'DELETE /api/v1/admin/backups/{name}' => 'D',
        'POST /api/v1/admin/restore' => 'D',
        'POST /api/v1/admin/backup-uploads' => 'D',
        'PUT /api/v1/admin/backup-uploads/{id}/chunks/{index}' => 'D',
        'POST /api/v1/admin/backup-uploads/{id}/complete' => 'D',
        'DELETE /api/v1/admin/backup-uploads/{id}' => 'D',
        'GET /api/v1/restore-status' => 'P',
        'GET /api/v1/projects/{id}/repo' => 'J',
        'PUT /api/v1/projects/{id}/repo' => 'JA',
        'DELETE /api/v1/projects/{id}/repo' => 'JA',
        'POST /api/v1/projects/{id}/repo/test' => 'JA',
        'POST /api/v1/projects/{id}/repo/fetch' => 'JA',
        'GET /api/v1/projects/{id}/schedule' => 'JA',
        'PUT /api/v1/projects/{id}/schedule' => 'JA',
        'GET /api/v1/admin/schedules' => 'D',
        'GET /api/v1/admin/repo-hosts' => 'D',
        'POST /api/v1/admin/repo-hosts' => 'D',
        'PATCH /api/v1/admin/repo-hosts/{id}' => 'D',
        'DELETE /api/v1/admin/repo-hosts/{id}' => 'D',
        'POST /api/v1/admin/repo-hosts/test-all' => 'D',
        'POST /api/v1/admin/repo-hosts/{id}/test' => 'D',
        'GET /api/v1/notifications' => 'A',
        'GET /api/v1/notifications/unread-count' => 'A',
        'GET /api/v1/notifications/preferences' => 'A',
        'PUT /api/v1/notifications/preferences' => 'A',
        'POST /api/v1/notifications/read-all' => 'A',
        'DELETE /api/v1/notifications/read' => 'A',
        'POST /api/v1/notifications/{id}/read' => 'A',
        'POST /api/v1/notifications/{id}/unread' => 'A',
        'DELETE /api/v1/notifications/{id}' => 'A',
        'GET /' => 'P',
        'GET /login' => 'P',
        'POST /login' => 'P',
        'POST /logout' => 'P',
        'GET /auth/oidc/start' => 'P',
        'GET /auth/oidc/callback' => 'P',
        'GET /auth/saml/start' => 'P',
        'POST /auth/saml/acs' => 'P',
        'GET /auth/saml/finish' => 'P',
        'GET /auth/saml/metadata' => 'P',
        'GET /password' => 'L',
        'POST /password' => 'L',
        'GET /home' => 'A',
        'GET /dashboard' => 'A',
        'GET /account' => 'A',
        'GET /admin/users' => 'D',
        'GET /admin/users/import' => 'D',
        'GET /admin/tools' => 'D',
        'GET /admin/worker' => 'D',
        'GET /admin/checks' => 'D',
        'GET /admin/runs' => 'D',
        'GET /admin/rule-settings' => 'D',
        'GET /admin/notifications' => 'D',
        'GET /admin/repo-hosts' => 'D',
        'GET /admin/schedules' => 'D',
        'GET /admin/config-settings' => 'D',
        'GET /admin/backups' => 'D',
        'GET /admin/monitoring' => 'D',
        'GET /admin/audit-logs' => 'D',
        'GET /restore-status' => 'P',
        'GET /notifications' => 'A',
        'GET /groups' => 'A',
        'GET /groups/new' => 'D',
        'GET /groups/{id}' => 'A',
        'GET /groups/{id}/edit' => 'D',
        'GET /groups/{id}/delete' => 'D',
        'GET /upload' => 'A',
        'GET /projects' => 'A',
        'GET /projects/new' => 'D',
        'GET /projects/{id}' => 'J',
        'GET /projects/{id}/edit' => 'JA',
        'GET /projects/{id}/delete' => 'JA',
        'GET /defects' => 'A',
        'GET /defects/{id}' => 'J',
        'GET /history' => 'A',
        'GET /rules' => 'A',
        'GET /rules/syntax-error' => 'A',
        'GET /rules/{tool}/{rule}' => 'A',
        'GET /projects/{id}/history' => 'J',
        'GET /my-defects' => 'A',
        'GET /analyses/{id}' => 'J',
        'GET /dev/styleguide' => 'P',
        'GET /guide' => 'A',
        'GET /guide/user' => 'A',
        'GET /guide/admin' => 'D',
        'GET /guide/errors' => 'A',
        'GET /guide/faq' => 'A',
        'GET /guide/licenses' => 'A',
        'GET /guide/img/{audience}/{slug}' => 'P',
        'GET /api/v1/guide/user' => 'A',
        'GET /api/v1/guide/user/search' => 'A',
        'GET /api/v1/guide/errors' => 'A',
        'GET /api/v1/guide/admin' => 'D',
        'GET /api/v1/guide/admin/search' => 'D',
        'GET /api/v1/faq' => 'A',
        'GET /api/v1/faq/{id}' => 'A',
        'POST /api/v1/admin/faq' => 'D',
        'POST /api/v1/admin/faq/reorder' => 'D',
        'PATCH /api/v1/admin/faq/{id}' => 'D',
        'DELETE /api/v1/admin/faq/{id}' => 'D',
    ];

    /**
     * 미들웨어는 통과하지만 핸들러가 역할별로 거부하는 라우트(손으로 적음): 역할 → 기대 상태 코드.
     * 여기 적힌 역할은 실제 핸들러로 보낸다(대역 아님).
     */
    private const HANDLER = [
        // 개발 전용 화면: app.env=dev가 아니면 누구에게나 404(시험 설정은 dev가 아님)
        'GET /dev/styleguide' => ['anon' => 404, 'temp' => 404, 'user' => 404, 'mgr' => 404, 'admin' => 404],
        // 가이드 그림: 로그인 필요(401), 관리자 가이드 그림은 admin만(403) — 경로는 공개, 핸들러가 판정
        'GET /guide/img/{audience}/{slug}' => ['anon' => 401, 'user' => 403, 'mgr' => 403],
        // 그룹(GroupPermission): 만들기는 admin만(403), 관리할 수 없는 그룹은 404, 그룹 관리자도 admin 전용 작업은 403
        'POST /api/v1/groups' => ['user' => 403, 'mgr' => 403],
        'GET /api/v1/groups/{id}' => ['user' => 404],
        'PATCH /api/v1/groups/{id}' => ['user' => 404, 'mgr' => 403],
        'DELETE /api/v1/groups/{id}' => ['user' => 404, 'mgr' => 403],
        'GET /api/v1/groups/{id}/deletion-info' => ['user' => 404, 'mgr' => 403],
        'GET /api/v1/groups/{id}/members' => ['user' => 404],
        'POST /api/v1/groups/{id}/members' => ['user' => 404],
        'DELETE /api/v1/groups/{id}/members/{userId}' => ['user' => 404],
        'PUT /api/v1/groups/{id}/members/{userId}/manager' => ['user' => 404], // 그룹 관리자는 지정·해제 가능(명세 3.1)
        'POST /api/v1/groups/{id}/accounts' => ['user' => 404],
        'GET /groups/{id}' => ['user' => 404],
        'GET /api/v1/users/search' => ['user' => 403],
    ];

    /** CSRF 검사를 하지 않는 경로(손으로 적음 — CsrfMiddleware::EXEMPT_PATHS와 같아야 함) */
    private const CSRF_EXEMPT = ['POST /auth/saml/acs'];

    private const ROLES = ['anon', 'temp', 'user', 'mgr', 'admin'];

    /** @var array<string, array{0:App, 1:array<string, TestClient>}> */
    private array $apps = [];

    private function setUpApp(bool $stub): array
    {
        $dir = $this->tempDir($stub ? 'mx_stub' : 'mx_real');
        $app = TestApp::make($dir, ['paths' => ['work_dir' => $dir . '/work']], ['encryption_key' => base64_encode(str_repeat('k', 32))]);
        $db = $app->db();
        $t = TestDb::T0;
        $admin = TestDb::user($db, 'admin', 'admin');
        $kim = TestDb::user($db, 'kim', 'user');
        $mgr = TestDb::user($db, 'mgr', 'user');
        $temp = TestDb::user($db, 'temp', 'user');
        $db->execute('UPDATE users SET must_change_password = 1 WHERE id = ?', [$temp]);
        $db->execute("INSERT INTO groups (name, created_at, updated_at) VALUES ('팀', ?, ?)", [$t, $t]);
        $db->execute('INSERT INTO group_members (group_id, user_id, is_manager, added_at) VALUES (1, ?, 1, ?)', [$mgr, $t]);
        $p1 = TestDb::project($db, 'p1');
        TestDb::project($db, 'p2');
        $db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES (?, 'group', 1, ?)", [$p1, $t]);
        $an = TestDb::analysis($db, $p1, $admin);
        TestDb::defect($db, $p1, $an);
        $db->execute("INSERT INTO uploads (id, user_id, project_id, file_name, total_size, chunk_size, total_chunks, last_activity_at, created_at) VALUES (?, ?, ?, 'a.zip', 10, 10, 1, ?, ?)",
            [self::UPLOAD, $mgr, $p1, $t, $t]);
        $clients = [];
        foreach (self::ROLES as $role) {
            $c = new TestClient($app);
            if ($role === 'anon') {
                $c->fetchCsrf();
            } else {
                $lr = $c->login($role === 'user' ? 'kim' : $role, 'Passw0rd!x');
                $this->assertSame(200, $lr->status, $role . ' ' . $lr->body);
            }
            $clients[$role] = $c;
        }
        // 로그인·토큰을 받은 뒤 핸들러를 대역으로 바꾼다(로그인 경로도 대역이 되므로)
        if ($stub) {
            $prop = new \ReflectionProperty(Router::class, 'routes');
            $routes = $prop->getValue($app->router);
            foreach ($routes as $i => $r) {
                $routes[$i]['handler'] = static fn (): Response => new Response(200, ['X-Matrix-Reached' => '1', 'Content-Type' => 'text/plain'], 'reached');
            }
            $prop->setValue($app->router, $routes);
        }
        return [$app, $clients];
    }

    private function client(bool $stub, string $role): TestClient
    {
        $k = $stub ? 'stub' : 'real';
        $this->apps[$k] ??= $this->setUpApp($stub);
        return $this->apps[$k][1][$role];
    }

    /** 경로 표기 → 실제 경로(fixture id) */
    private static function concrete(string $path): string
    {
        $map = [
            '#^/api/v1/uploads/\{id\}#' => fn () => '/api/v1/uploads/' . self::UPLOAD,
            '#^/api/v1/admin/tool-staging/\{id\}#' => fn () => '/api/v1/admin/tool-staging/' . self::STAGING,
            '#^/api/v1/admin/backup-uploads/\{id\}#' => fn () => '/api/v1/admin/backup-uploads/' . self::STAGING,
        ];
        foreach ($map as $re => $fn) {
            if (preg_match($re, $path) === 1) {
                $path = (string) preg_replace($re, $fn(), $path);
            }
        }
        return strtr($path, [
            '{id}' => '1', '{index}' => '0', '{subjectType}' => 'group', '{subjectId}' => '1', '{userId}' => '3', '{tool}' => 'ruff', '{version}' => '0.1.0',
            '{rule}' => 'F401', '{name}' => 'pjguardian-20261001-000000.pjgbak', '{token}' => str_repeat('a', 64), '{key}' => 'disk.warn_percent',
            '{audience}' => 'admin', '{slug}' => 'overview',
        ]);
    }

    private static function expected(string $level, string $role, bool $api): string
    {
        $deny = static fn (int $status): string => (string) $status;
        $login = $api ? '401' : '302:/login';
        $pw = $api ? '403' : '302:/password';
        return match ($level) {
            'P' => 'reach',
            'L' => $role === 'anon' ? $login : 'reach',
            'A' => match ($role) { 'anon' => $login, 'temp' => $pw, default => 'reach' },
            'D' => match ($role) { 'anon' => $login, 'temp' => $pw, 'user', 'mgr' => $deny(403), default => 'reach' },
            'J' => match ($role) { 'anon' => $login, 'temp' => $pw, 'user' => $deny(404), default => 'reach' },
            'JA' => match ($role) { 'anon' => $login, 'temp' => $pw, 'user' => $deny(404), 'mgr' => $deny(403), default => 'reach' },
        };
    }

    private static function outcome(Response $r): string
    {
        if ($r->header('X-Matrix-Reached') === '1') {
            return 'reach';
        }
        if ($r->status === 302) {
            $loc = (string) $r->header('Location');
            return '302:' . (string) parse_url($loc, PHP_URL_PATH);
        }
        return (string) $r->status;
    }

    private function send(TestClient $c, string $method, string $path, bool $csrf = true): Response
    {
        if ($method === 'PUT' && str_contains($path, '/chunks/')) {
            return $c->raw('PUT', $path, 'x', [], $csrf);
        }
        // 본문 검증보다 권한 판정을 보려고 형식이 맞는 본문을 준다(그룹 관리자 지정)
        $body = str_ends_with($path, '/manager') ? ['is_manager' => true] : [];
        return $c->request($method, $path, [], in_array($method, ['GET', 'HEAD'], true) ? null : $body, null, [], $csrf);
    }

    public function testTableCoversEveryRegisteredRoute(): void
    {
        [$app] = $this->apps['real'] ??= $this->setUpApp(false);
        $registered = array_map(static fn (array $r): string => $r['method'] . ' ' . $r['path'], $app->router->routes());
        $this->assertSame([], array_values(array_diff($registered, array_keys(self::EXPECTED))), '표에 없는 새 라우트(누가 써야 하는지 정해 EXPECTED에 적는다)');
        $this->assertSame([], array_values(array_diff(array_keys(self::EXPECTED), $registered)), '표에만 있는 라우트');
        $this->assertSame([], array_values(array_diff(array_keys(self::HANDLER), $registered)), 'HANDLER 표에만 있는 라우트');
        // 규칙: 관리자 경로는 admin만
        foreach (self::EXPECTED as $key => $level) {
            [, $path] = explode(' ', $key, 2);
            if (str_starts_with($path, '/admin') || str_starts_with($path, '/api/v1/admin/')) {
                $this->assertTrue(in_array($level, ['D', 'JA'], true), "{$key}: 관리자 경로는 D·JA");
            }
        }
    }

    public function testEveryRouteForEveryRole(): void
    {
        $bad = [];
        $count = 0;
        foreach (self::EXPECTED as $key => $level) {
            [$method, $path] = explode(' ', $key, 2);
            $concrete = self::concrete($path);
            $api = str_starts_with($path, '/api/') || $path === '/health';
            foreach (self::ROLES as $role) {
                $want = isset(self::HANDLER[$key][$role]) ? (string) self::HANDLER[$key][$role] : self::expected($level, $role, $api);
                $stub = $want === 'reach';
                $got = self::outcome($this->send($this->client($stub, $role), $method, $concrete));
                $count++;
                if ($got !== $want) {
                    $bad[] = "{$key} [{$role}] 기대 {$want}, 실제 {$got}";
                }
            }
        }
        $this->assertSame([], $bad, '권한 행렬 불일치');
        $this->assertTrue($count >= 1250, "요청 수 {$count}");
    }

    public function testStateChangingRoutesRequireCsrf(): void
    {
        $this->assertSame(self::CSRF_EXEMPT, array_map(static fn (string $p): string => 'POST ' . $p, CsrfMiddleware::EXEMPT_PATHS), 'CSRF 예외 표');
        $bad = [];
        foreach (array_keys(self::EXPECTED) as $key) {
            [$method, $path] = explode(' ', $key, 2);
            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }
            $r = $this->send($this->client(true, 'admin'), $method, self::concrete($path), false);
            $exempt = in_array($key, self::CSRF_EXEMPT, true);
            $code = $r->decoded()['error']['code'] ?? null;
            // API는 JSON 오류 코드, 화면 폼(/login 등)은 403 오류 페이지
            $api = str_starts_with($path, '/api/');
            if ($exempt ? self::outcome($r) !== 'reach' : !($r->status === 403 && (!$api || $code === 'csrf_invalid'))) {
                $bad[] = "{$key}: {$r->status} {$code}";
            }
        }
        $this->assertSame([], $bad, '토큰 없는 상태 변경 요청');
    }
}
