<?php

declare(strict_types=1);

/**
 * 라우트 등록. 새 API를 추가하면 docs/openapi.yaml에도 같은 경로·메서드를 넣는다(OpenApiTest가 검사).
 * 규칙은 docs/architecture.md "라우트 등록" 절.
 *
 * 권한 미들웨어(라우트별, 순서대로 적용)
 *   $login : 로그인 필요(API 401, 화면 → 로그인)
 *   $auth  : 로그인 + 임시 비밀번호 변경 완료(대부분의 기능)
 *   $admin : $auth + 관리자(API 403, 화면 403 페이지). 화면 숨김과 관계없이 서버에서 항상 검사
 *   $projectView  : $auth + 프로젝트 접근 판정(RequireProjectAccess, 보이지 않으면 404)
 *   $projectAdmin : $projectView + 관리자. 접근 검사가 먼저라 보이지 않는 프로젝트는 404, 보이지만 관리자가 아니면 403
 * 프로젝트(또는 그 자식 리소스: 업로드·분석·결함·스냅샷 …)를 다루는 라우트는 반드시 RequireProjectAccess를 붙인다.
 * 붙이지 않는 경로는 tests/Integration/ProjectRouteAccessTest의 예외 목록에 이유와 함께 넣는다(docs/architecture.md 19절).
 * CSRF는 전역 미들웨어가 모든 POST/PUT/PATCH/DELETE에 적용한다(예외 경로 없음).
 */

use PJGuardian\App;
use PJGuardian\Controllers\AccountController;
use PJGuardian\Controllers\AdminCheckController;
use PJGuardian\Controllers\AuditLogController;
use PJGuardian\Controllers\MonitoringController;
use PJGuardian\Controllers\AdminRunController;
use PJGuardian\Controllers\NotificationController;
use PJGuardian\Controllers\RepoController;
use PJGuardian\Controllers\AdminRuleOverrideController;
use PJGuardian\Controllers\AdminSettingController;
use PJGuardian\Controllers\AdminConfigSettingController;
use PJGuardian\Controllers\AdminBackupController;
use PJGuardian\Controllers\RestoreStatusController;
use PJGuardian\Controllers\RuleController;
use PJGuardian\Controllers\AdminSystemPageController;
use PJGuardian\Controllers\AdminToolController;
use PJGuardian\Controllers\AccountImportController;
use PJGuardian\Controllers\AdminAlertMailController;
use PJGuardian\Controllers\AdminAuthController;
use PJGuardian\Controllers\AdminUserPageController;
use PJGuardian\Controllers\AdminWorkerController;
use PJGuardian\Controllers\AnalysisController;
use PJGuardian\Controllers\AnalysisPageController;
use PJGuardian\Controllers\AuthController;
use PJGuardian\Controllers\AuthPageController;
use PJGuardian\Controllers\DashboardController;
use PJGuardian\Controllers\DefectController;
use PJGuardian\Controllers\ExportController;
use PJGuardian\Controllers\DefectPageController;
use PJGuardian\Controllers\GroupController;
use PJGuardian\Controllers\GroupPageController;
use PJGuardian\Controllers\MetaController;
use PJGuardian\Controllers\ProjectController;
use PJGuardian\Controllers\ProjectPageController;
use PJGuardian\Controllers\StyleguideController;
use PJGuardian\Controllers\UploadController;
use PJGuardian\Controllers\UploadPageController;
use PJGuardian\Controllers\UserController;
use PJGuardian\Http\Middleware\RequireAdmin;
use PJGuardian\Http\Middleware\RequireLogin;
use PJGuardian\Http\Middleware\RequirePasswordChanged;
use PJGuardian\Http\Middleware\RequireProjectAccess;
use PJGuardian\Http\Router;

return static function (Router $router, App $app): void {
    $login = [new RequireLogin()];
    $auth = [new RequireLogin(), new RequirePasswordChanged()];
    $admin = [new RequireLogin(), new RequirePasswordChanged(), new RequireAdmin()];
    $projectAccess = new RequireProjectAccess(static fn () => $app->projectAccess());
    $projectView = [...$auth, $projectAccess];
    $projectAdmin = [...$auth, $projectAccess, new RequireAdmin()];

    $meta = new MetaController();
    $authApi = new AuthController($app);
    $users = new UserController($app);
    $pages = new AuthPageController($app);

    // ---- API (/api/v1, JSON) ----
    $router->get('/api/v1/meta/version', $meta->version(...));

    $router->get('/api/v1/auth/csrf', $authApi->csrf(...));
    $router->post('/api/v1/auth/login', $authApi->login(...));
    $router->post('/api/v1/auth/logout', $authApi->logout(...));
    $router->get('/api/v1/auth/me', $authApi->me(...), $login);
    $router->post('/api/v1/auth/password', $authApi->changePassword(...), $login);

    // 내 정보(X05): 본인의 표시 이름·이메일. 수정은 관리자 PATCH /users/{id}와 같은 UserService::updateProfile
    $account = new AccountController($app);
    $router->get('/api/v1/users/me', $account->show(...), $auth);
    $router->patch('/api/v1/users/me', $account->update(...), $auth);
    $router->put('/api/v1/users/me/preferences', $account->preferences(...), $auth); // P27D 화면 언어·테마(본인만)
    $router->get('/api/v1/users', $users->list(...), $admin);
    $router->post('/api/v1/users', $users->create(...), $admin);
    $router->get('/api/v1/users/{id:\d+}', $users->show(...), $admin);
    $router->patch('/api/v1/users/{id:\d+}', $users->update(...), $admin);
    $router->put('/api/v1/users/{id:\d+}/role', $users->role(...), $admin);
    $router->put('/api/v1/users/{id:\d+}/status', $users->status(...), $admin);
    $router->post('/api/v1/users/{id:\d+}/temp-password', $users->tempPassword(...), $admin);
    $router->put('/api/v1/users/{id:\d+}/password', $users->password(...), $admin);
    $router->post('/api/v1/users/{id:\d+}/unlock', $users->unlock(...), $admin);
    // P28B 승인 대기 계정(외부 인증 첫 로그인)·인증 방식 전환
    $router->post('/api/v1/users/{id:\d+}/approve', $users->approve(...), $admin);
    $router->post('/api/v1/users/{id:\d+}/reject', $users->reject(...), $admin);
    $router->delete('/api/v1/users/{id:\d+}', $users->deletePending(...), $admin);
    $router->put('/api/v1/users/{id:\d+}/auth-type', $users->authType(...), $admin);
    // P28B LDAP/AD 관리(연결 설정 값은 설정 관리 API)
    $authAdmin = new AdminAuthController($app);
    $router->get('/api/v1/admin/auth/ldap', $authAdmin->ldap(...), $admin);
    $router->put('/api/v1/admin/auth/ldap/bind-password', $authAdmin->putBindPassword(...), $admin);
    $router->delete('/api/v1/admin/auth/ldap/bind-password', $authAdmin->deleteBindPassword(...), $admin);
    $router->post('/api/v1/admin/auth/ldap/test', $authAdmin->test(...), $admin);
    // P28C SSO(OIDC): 클라이언트 비밀(값은 다시 내리지 않음)·연결 시험은 admin만. 로그인 시작·콜백은 로그인 전 화면 경로
    $router->get('/api/v1/admin/auth/oidc', $authAdmin->oidc(...), $admin);
    $router->put('/api/v1/admin/auth/oidc/client-secret', $authAdmin->putClientSecret(...), $admin);
    $router->delete('/api/v1/admin/auth/oidc/client-secret', $authAdmin->deleteClientSecret(...), $admin);
    $router->post('/api/v1/admin/auth/oidc/test', $authAdmin->oidcTest(...), $admin);
    // P28D SSO(SAML): SP 키 생성·삭제, IdP 메타데이터 가져오기(값만 돌려줌), 설정 확인 — admin만
    $router->get('/api/v1/admin/auth/saml', $authAdmin->saml(...), $admin);
    $router->post('/api/v1/admin/auth/saml/sp-key', $authAdmin->generateSpKey(...), $admin);
    $router->delete('/api/v1/admin/auth/saml/sp-key', $authAdmin->deleteSpKey(...), $admin);
    $router->post('/api/v1/admin/auth/saml/metadata-import', $authAdmin->importSamlMetadata(...), $admin);
    $router->post('/api/v1/admin/auth/saml/test', $authAdmin->samlTest(...), $admin);
    // P30B 관리자 장애 이메일: SMTP 비밀번호(값은 다시 내리지 않음)·상태·시험 발송 — admin만. 실제 발송은 워커만
    $alertMail = new AdminAlertMailController($app);
    $router->get('/api/v1/admin/alert-mail', $alertMail->status(...), $admin);
    $router->put('/api/v1/admin/alert-mail/password', $alertMail->putPassword(...), $admin);
    $router->delete('/api/v1/admin/alert-mail/password', $alertMail->deletePassword(...), $admin);
    $router->post('/api/v1/admin/alert-mail/test', $alertMail->test(...), $admin);
    // P28A 계정 CSV 일괄 처리(admin만)
    $accountImport = new AccountImportController($app);
    $router->get('/api/v1/admin/account-import/template', $accountImport->template(...), $admin);
    $router->post('/api/v1/admin/account-import/preview', $accountImport->preview(...), $admin);
    $router->post('/api/v1/admin/account-import/execute', $accountImport->execute(...), $admin);

    $projects = new ProjectController($app);
    $router->get('/api/v1/projects', $projects->list(...), $auth);
    $router->post('/api/v1/projects', $projects->create(...), $admin);
    $router->get('/api/v1/projects/default-excludes', $projects->defaultExcludes(...), $auth);
    $router->get('/api/v1/projects/{id:\d+}', $projects->show(...), $projectView);
    $router->patch('/api/v1/projects/{id:\d+}', $projects->update(...), $projectAdmin);
    $router->delete('/api/v1/projects/{id:\d+}', $projects->delete(...), $projectAdmin);
    $router->get('/api/v1/projects/{id:\d+}/deletion-info', $projects->deletionInfo(...), $projectAdmin);
    $router->get('/api/v1/projects/{id:\d+}/access', $projects->access(...), $projectAdmin);
    $router->post('/api/v1/projects/{id:\d+}/access', $projects->addAccess(...), $projectAdmin);
    $router->delete('/api/v1/projects/{id:\d+}/access/{subjectType:group|user}/{subjectId:\d+}', $projects->removeAccess(...), $projectAdmin);

    // 청크 업로드(명세 5.4, 19.10). init은 프로젝트 id, 나머지는 upload_id → 소유 프로젝트로 접근 판정(보이지 않으면 404).
    // 업로드 소유자 확인(다른 사용자 404)은 UploadService가 한다
    $uploads = new UploadController($app);
    $uploadAccess = [...$auth, new RequireProjectAccess(
        static fn () => $app->projectAccess(),
        'id',
        static fn (string $id): ?int => $app->uploads()->projectIdOf($id),
        'upload_not_found',
    )];
    $router->post('/api/v1/projects/{id:\d+}/uploads', $uploads->init(...), $projectView);
    $router->get('/api/v1/uploads/{id}', $uploads->show(...), $uploadAccess);
    $router->delete('/api/v1/uploads/{id}', $uploads->abort(...), $uploadAccess);
    $router->get('/api/v1/uploads/{id}/status', $uploads->status(...), $uploadAccess);
    $router->put('/api/v1/uploads/{id}/chunks/{index}', $uploads->chunk(...), $uploadAccess);
    $router->post('/api/v1/uploads/{id}/complete', $uploads->complete(...), $uploadAccess);
    $router->post('/api/v1/uploads/{id}/confirm', $uploads->confirm(...), $uploadAccess);

    // 분석 큐 조회·취소(명세 5.2, 5.4, P14). 목록은 프로젝트 id, 상세·취소는 분석 id → 소유 프로젝트로 접근 판정(보이지 않으면 404).
    // 취소 권한(요청한 사용자 본인 또는 admin, 다른 사용자 403)은 AnalysisService가 판정한다
    $analyses = new AnalysisController($app);
    $analysisAccess = [...$auth, new RequireProjectAccess(
        static fn () => $app->projectAccess(),
        'id',
        static fn (string $id): ?int => $app->analyses()->projectIdOf($id),
        'analysis_not_found',
    )];
    $router->get('/api/v1/projects/{id:\d+}/analyses', $analyses->list(...), $projectView);
    $router->get('/api/v1/analyses/{id}', $analyses->show(...), $analysisAccess);
    $router->post('/api/v1/analyses/{id}/cancel', $analyses->cancel(...), $analysisAccess);

    // 결함 조회(명세 6.4, 7, P17). 목록·요약은 프로젝트 id, 상세·이력·코드는 결함 id → 소유 프로젝트로 접근 판정(보이지 않으면 404).
    // 결함 값 수정(담당자·심각도·상태)은 P18
    $defects = new DefectController($app);
    $defectAccess = new RequireProjectAccess(
        static fn () => $app->projectAccess(),
        'id',
        static fn (string $id): ?int => $app->defectQuery()->projectIdOf($id),
        'defect_not_found',
    );
    $defectView = [...$auth, $defectAccess];
    $router->get('/api/v1/projects/{id:\d+}/defects', $defects->list(...), $projectView);
    $router->get('/api/v1/projects/{id:\d+}/defects/summary', $defects->summary(...), $projectView);
    $router->get('/api/v1/defects/{id}', $defects->show(...), $defectView);
    $router->get('/api/v1/defects/{id}/history', $defects->history(...), $defectView);
    $router->get('/api/v1/defects/{id}/code', $defects->code(...), $defectView);
    // 히스토리(P21): 분석 이력·직전 성공 대비 비교·결함 전체 이력
    $history = new \PJGuardian\Controllers\HistoryController($app);
    $router->get('/api/v1/projects/{id:\d+}/history', $history->list(...), $projectView);
    $router->get('/api/v1/analyses/{id}/comparison', $history->comparison(...), $analysisAccess);
    $router->get('/api/v1/defects/{id}/timeline', $history->timeline(...), $defectView);
    // 변경(P18): 로그인한 사용자 누구나(관리자 제한 없음), CSRF는 전역. 일괄은 프로젝트 id(그 프로젝트의 결함만)
    $router->patch('/api/v1/defects/{id}', $defects->update(...), $defectView);
    $router->post('/api/v1/projects/{id:\d+}/defects/bulk', $defects->bulk(...), $projectView);
    $router->get('/api/v1/projects/{id:\d+}/assignees', $defects->assignees(...), $projectView);
    // Run(P20R): 프로젝트를 볼 수 있으면
    $router->get('/api/v1/projects/{id:\d+}/runs', $defects->runs(...), $projectView);
    // 내게 지정된 결함(명세 17): 프로젝트 id를 받지 않는 목록이라 쿼리에서 ProjectAccess::scopeFor로 거른다
    $router->get('/api/v1/my-defects', $defects->mine(...), $auth);
    $router->get('/api/v1/my-defects/summary', $defects->mineSummary(...), $auth);
    // 내보내기(P23): POST + CSRF만. 결함은 목록과 같은 접근 판정(보이지 않는 프로젝트 404), 규칙은 로그인한 누구나
    $export = new ExportController($app);
    $router->post('/api/v1/projects/{id:\d+}/defects/export', $export->defects(...), $projectView);
    $router->post('/api/v1/my-defects/export', $export->mine(...), $auth);
    $router->post('/api/v1/rules/export', $export->rules(...), $auth);

    // 대시보드(명세 9, P20): 로그인한 모든 사용자. 집계는 보이는 프로젝트 범위만(DashboardService가 ProjectAccess::scopeFor로 거름),
    // project_id 쿼리는 ProjectAccess::requireView(보이지 않으면 404). 구역마다 따로 불러 느린 구역이 다른 구역을 막지 않는다
    $dashboard = new DashboardController($app);
    $router->get('/api/v1/dashboard/summary', $dashboard->summary(...), $auth);
    $router->get('/api/v1/dashboard/trend', $dashboard->trend(...), $auth);
    $router->get('/api/v1/dashboard/top-rules', $dashboard->topRules(...), $auth);
    $router->get('/api/v1/dashboard/top-files', $dashboard->topFiles(...), $auth);
    $router->get('/api/v1/dashboard/tools', $dashboard->tools(...), $auth);

    // 그룹(명세 3.1): 라우트는 로그인만 검사하고 그룹별 권한은 GroupService(GroupPermission)가 판정한다
    $groups = new GroupController($app);
    $router->get('/api/v1/groups', $groups->list(...), $auth);
    $router->post('/api/v1/groups', $groups->create(...), $auth);
    $router->get('/api/v1/groups/{id:\d+}', $groups->show(...), $auth);
    $router->patch('/api/v1/groups/{id:\d+}', $groups->update(...), $auth);
    $router->delete('/api/v1/groups/{id:\d+}', $groups->delete(...), $auth);
    $router->get('/api/v1/groups/{id:\d+}/deletion-info', $groups->deletionInfo(...), $auth);
    $router->get('/api/v1/groups/{id:\d+}/members', $groups->members(...), $auth);
    $router->post('/api/v1/groups/{id:\d+}/members', $groups->addMember(...), $auth);
    $router->delete('/api/v1/groups/{id:\d+}/members/{userId:\d+}', $groups->removeMember(...), $auth);
    $router->put('/api/v1/groups/{id:\d+}/members/{userId:\d+}/manager', $groups->setManager(...), $auth);
    $router->post('/api/v1/groups/{id:\d+}/accounts', $groups->createAccount(...), $auth);
    $router->get('/api/v1/users/search', $groups->searchUsers(...), $auth);

    // 관리자 시스템 관리(P20A, admin 전용). 웹은 bin/에 쓰지 않고, 도구·워커 프로그램을 직접 실행하거나 PID로 종료하지 않는다.
    // 재검증·정리·디스크 계산은 관리 작업(admin_tasks)으로 워커 자식이 하고, 워커 시작은 설정의 고정 명령(worker.start_command)만 실행한다
    $tools = new AdminToolController($app);
    $toolVersion = '/api/v1/admin/tools/{tool:ruff|eslint}/versions/{version:\d+\.\d+\.\d+}';
    $router->get('/api/v1/admin/tools', $tools->index(...), $admin);
    $router->post('/api/v1/admin/tools/node/refresh', $tools->nodeRefresh(...), $admin); // P21C: Node 상태 갱신(워커가 다시 확인)
    $router->post($toolVersion . '/block', $tools->block(...), $admin);
    $router->post($toolVersion . '/unblock', $tools->unblock(...), $admin);
    $router->post($toolVersion . '/pin', $tools->pin(...), $admin);
    $router->post($toolVersion . '/verify', $tools->verify(...), $admin);
    $router->post($toolVersion . '/removal', $tools->markRemoval(...), $admin);
    $router->delete($toolVersion . '/removal', $tools->unmarkRemoval(...), $admin);
    $router->post('/api/v1/admin/tools/{tool:ruff|eslint}/unpin', $tools->unpin(...), $admin);
    $staging = '/api/v1/admin/tool-staging/{id:[0-9a-f]+}';
    $router->get('/api/v1/admin/tool-staging', $tools->stagingList(...), $admin);
    $router->post('/api/v1/admin/tool-staging', $tools->stagingInit(...), $admin);
    $router->get($staging, $tools->stagingShow(...), $admin);
    $router->patch($staging, $tools->stagingUpdate(...), $admin);
    $router->delete($staging, $tools->stagingDiscard(...), $admin);
    $router->put($staging . '/chunks/{index:\d+}', $tools->stagingChunk(...), $admin);
    $router->post($staging . '/complete', $tools->stagingComplete(...), $admin);
    $worker = new AdminWorkerController($app);
    $router->get('/api/v1/admin/worker', $worker->show(...), $admin);
    $router->post('/api/v1/admin/worker/stop', $worker->stop(...), $admin);
    $router->post('/api/v1/admin/worker/restart', $worker->restart(...), $admin);
    $router->post('/api/v1/admin/worker/start', $worker->start(...), $admin);
    $router->get('/api/v1/admin/logs', $worker->logs(...), $admin);
    $checks = new AdminCheckController($app);
    $router->get('/api/v1/admin/checks', $checks->show(...), $admin);
    $router->post('/api/v1/admin/checks/disk-scan', $checks->diskScan(...), $admin);
    $router->post('/api/v1/admin/cleanup/run', $checks->cleanupRun(...), $admin);
    // P30 시스템 모니터링(admin만, 읽기 전용)·감사 로그 조회(admin만, 읽기 전용 — 수정·삭제 라우트 없음)
    $monitoring = new MonitoringController($app);
    $router->get('/api/v1/admin/monitoring', $monitoring->show(...), $admin);
    $auditLogs = new AuditLogController($app);
    $router->get('/api/v1/admin/audit-logs', $auditLogs->list(...), $admin);
    $router->get('/api/v1/admin/audit-logs/integrity', $auditLogs->integrity(...), $admin);
    $router->get('/api/v1/admin/audit-logs/{id:\d+}', $auditLogs->show(...), $admin);
    $router->post('/api/v1/admin/audit-logs/export', $auditLogs->export(...), $admin);
    // P30 헬스체크: 인증 없음, 허용 IP(health.allowed_ips, 접속 주소만)만 — 밖이면 없는 주소와 같은 404
    $router->get('/health', $monitoring->health(...));
    // Run 관리(P21B, admin 전용): 접근 판정 다음 관리자 확인(보이지 않는 프로젝트 404, admin이 아니면 403)
    $adminRuns = new AdminRunController($app);
    $router->get('/api/v1/admin/projects/{id:\d+}/runs', $adminRuns->list(...), $projectAdmin);
    $router->post('/api/v1/admin/projects/{id:\d+}/runs/delete-detail', $adminRuns->deleteDetail(...), $projectAdmin);
    // 규칙 설명(P22): 로그인한 모든 사용자. 규칙 이름에는 /·@가 들어간다(@typescript-eslint/no-unused-vars) → 마지막 인자는 여러 구간
    $rules = new RuleController($app);
    $router->get('/api/v1/rules', $rules->list(...), $auth);
    $router->get('/api/v1/rules/meta', $rules->meta(...), $auth);
    $router->get('/api/v1/rules/syntax-error', $rules->detail(...), $auth);
    $router->get('/api/v1/rules/{tool:ruff|eslint}/{rule:.+}', $rules->detail(...), $auth);
    $router->post('/api/v1/admin/rules/reload', $rules->reload(...), $admin);
    // 관리자 규칙 켜기·끄기(P22B, admin 전용, 016): 규칙별 변경·되돌리기, 변경 목록, 도구별 전체 되돌리기. 특수 키는 422
    $ruleOverrides = new AdminRuleOverrideController($app);
    $router->get('/api/v1/admin/rules/overrides', $ruleOverrides->list(...), $admin);
    $router->post('/api/v1/admin/rules/overrides/reset', $ruleOverrides->reset(...), $admin);
    // P22C: 규칙 목록 일괄 변경(미리보기 → 적용)
    $router->post('/api/v1/admin/rules/overrides/bulk/preview', $ruleOverrides->bulkPreview(...), $admin);
    $router->post('/api/v1/admin/rules/overrides/bulk', $ruleOverrides->bulk(...), $admin);
    $router->put('/api/v1/admin/rules/syntax-error/override', $ruleOverrides->special(...), $admin);
    $router->delete('/api/v1/admin/rules/syntax-error/override', $ruleOverrides->special(...), $admin);
    $router->put('/api/v1/admin/rules/{tool:ruff|eslint}/{rule:.+}/override', $ruleOverrides->set(...), $admin);
    $router->delete('/api/v1/admin/rules/{tool:ruff|eslint}/{rule:.+}/override', $ruleOverrides->clear(...), $admin);
    // 화면 설정(P21C, admin 전용, 014 app_settings): 대시보드 추이 프로젝트 수
    $adminSettings = new AdminSettingController($app);
    $router->get('/api/v1/admin/settings', $adminSettings->index(...), $admin);
    $router->patch('/api/v1/admin/settings', $adminSettings->update(...), $admin);
    // 설정 관리(P27A, admin 전용): 허용 목록 키만, 값은 config/settings.local.ini(위 DB 화면 설정과 별개)
    $configSettings = new AdminConfigSettingController($app);
    $router->get('/api/v1/admin/config-settings', $configSettings->index(...), $admin);
    $router->put('/api/v1/admin/config-settings', $configSettings->update(...), $admin);
    $router->post('/api/v1/admin/config-settings/validate', $configSettings->validate(...), $admin);
    $router->delete('/api/v1/admin/config-settings/{key:[a-z_]+\.[a-z_]+}', $configSettings->reset(...), $admin);
    // 백업·복원(P27B, admin 전용): 내려받기·삭제·복원·키 내보내기는 비밀번호 재입력. 복원은 워커가 별도 프로세스로(요청 파일)
    $backupsCtl = new AdminBackupController($app);
    // 경로 제약에는 중괄호를 쓰지 않는다(라우터가 {이름:제약}의 첫 } 에서 끝냄). 정확한 형식은 컨트롤러가 다시 검사(BundleFormat::validName, 48자 표)
    $bundle = '/api/v1/admin/backups/{name:pjguardian-[0-9a-z_.-]+\.pjgbak}';
    $router->get('/api/v1/admin/backups', $backupsCtl->index(...), $admin);
    $router->post('/api/v1/admin/backups', $backupsCtl->create(...), $admin);
    $router->post('/api/v1/admin/backups/cancel', $backupsCtl->cancel(...), $admin);
    $router->post('/api/v1/admin/backups/rehearse', $backupsCtl->rehearse(...), $admin);
    $router->post('/api/v1/admin/backups/key-export', $backupsCtl->keyExport(...), $admin);
    $router->get('/api/v1/admin/backups/download/{token:[0-9a-f]+}', $backupsCtl->download(...), $admin);
    $router->post($bundle . '/verify', $backupsCtl->verify(...), $admin);
    $router->post($bundle . '/download', $backupsCtl->downloadToken(...), $admin);
    $router->post($bundle . '/precheck', $backupsCtl->precheck(...), $admin);
    $router->delete($bundle, $backupsCtl->delete(...), $admin);
    $router->post('/api/v1/admin/restore', $backupsCtl->restore(...), $admin);
    $router->post('/api/v1/admin/backup-uploads', $backupsCtl->uploadInit(...), $admin);
    $router->put('/api/v1/admin/backup-uploads/{id:[0-9a-f]+}/chunks/{index:\d+}', $backupsCtl->uploadChunk(...), $admin);
    $router->post('/api/v1/admin/backup-uploads/{id:[0-9a-f]+}/complete', $backupsCtl->uploadComplete(...), $admin);
    $router->delete('/api/v1/admin/backup-uploads/{id:[0-9a-f]+}', $backupsCtl->uploadDiscard(...), $admin);
    // 복원 진행 상태(P27B): 로그인 없이·DB 없이(파일만). 유지보수 모드에서는 MaintenanceMiddleware가 먼저 응답한다
    $restoreStatus = new RestoreStatusController($app);
    $router->get('/api/v1/restore-status', $restoreStatus->api(...));
    // 소스 저장소 연동(P25): 프로젝트 접근 판정(보이지 않으면 404)이 먼저, 변경·테스트·가져오기는 admin만
    $repo = new RepoController($app);
    $router->get('/api/v1/projects/{id:\d+}/repo', $repo->show(...), $projectView);
    $router->put('/api/v1/projects/{id:\d+}/repo', $repo->save(...), $projectAdmin);
    $router->delete('/api/v1/projects/{id:\d+}/repo', $repo->delete(...), $projectAdmin);
    $router->post('/api/v1/projects/{id:\d+}/repo/test', $repo->test(...), $projectAdmin);
    $router->post('/api/v1/projects/{id:\d+}/repo/fetch', $repo->fetch(...), $projectAdmin);
    // 주기 분석(P26): 설정은 admin만(일반 사용자는 GET /repo의 schedule 요약만), 현황은 admin
    $router->get('/api/v1/projects/{id:\d+}/schedule', $repo->schedule(...), $projectAdmin);
    $router->put('/api/v1/projects/{id:\d+}/schedule', $repo->saveSchedule(...), $projectAdmin);
    $router->get('/api/v1/admin/schedules', $repo->schedules(...), $admin);
    $router->get('/api/v1/admin/repo-hosts', $repo->hosts(...), $admin);
    $router->post('/api/v1/admin/repo-hosts', $repo->addHost(...), $admin);
    $router->patch('/api/v1/admin/repo-hosts/{id:\d+}', $repo->updateHost(...), $admin);
    $router->delete('/api/v1/admin/repo-hosts/{id:\d+}', $repo->deleteHost(...), $admin);
    // P26B: 허용 호스트 연결 테스트(등록된 호스트만, 분당 제한은 저장소 연결 테스트와 공유 — 전체는 1회)
    $router->post('/api/v1/admin/repo-hosts/test-all', $repo->testAllHosts(...), $admin);
    $router->post('/api/v1/admin/repo-hosts/{id:\d+}/test', $repo->testHost(...), $admin);
    // 앱 안 알림(P24): 로그인한 누구나 **자기 알림만**(admin도 남의 알림은 못 본다). 프로젝트 접근 판정은 서비스가 알림마다 한다(보이지 않으면 404)
    $notifications = new NotificationController($app);
    $router->get('/api/v1/notifications', $notifications->index(...), $auth);
    $router->get('/api/v1/notifications/unread-count', $notifications->unreadCount(...), $auth);
    $router->get('/api/v1/notifications/preferences', $notifications->preferences(...), $auth);
    $router->put('/api/v1/notifications/preferences', $notifications->updatePreferences(...), $auth);
    $router->post('/api/v1/notifications/read-all', $notifications->readAll(...), $auth);
    $router->delete('/api/v1/notifications/read', $notifications->deleteRead(...), $auth);
    $router->post('/api/v1/notifications/{id:\d+}/read', $notifications->read(...), $auth);
    $router->post('/api/v1/notifications/{id:\d+}/unread', $notifications->unread(...), $auth);
    $router->delete('/api/v1/notifications/{id:\d+}', $notifications->delete(...), $auth);

    // ---- HTML 페이지 ----
    $router->get('/', $pages->root(...));
    $router->get('/login', $pages->loginForm(...));
    $router->post('/login', $pages->loginSubmit(...));
    $router->post('/logout', $pages->logoutSubmit(...));
    $oidcPages = new \PJGuardian\Controllers\OidcController($app);
    $router->get('/auth/oidc/start', $oidcPages->start(...));
    $router->get('/auth/oidc/callback', $oidcPages->callback(...));
    // P28D SSO(SAML): ACS는 CsrfMiddleware의 유일한 예외(IdP의 cross-site POST — SamlLogin이 요청 ID·RelayState·서명으로 검증)
    $samlPages = new \PJGuardian\Controllers\SamlController($app);
    $router->get('/auth/saml/start', $samlPages->start(...));
    $router->post('/auth/saml/acs', $samlPages->acs(...));
    $router->get('/auth/saml/finish', $samlPages->finish(...));
    $router->get('/auth/saml/metadata', $samlPages->metadata(...));
    $router->get('/password', $pages->passwordForm(...), $login);
    $router->post('/password', $pages->passwordSubmit(...), $login);
    $router->get('/home', $pages->home(...), $auth);
    $router->get('/dashboard', $dashboard->page(...), $auth);
    $router->get('/account', $account->page(...), $auth);
    $router->get('/admin/users', (new AdminUserPageController($app))->show(...), $admin);
    $router->get('/admin/users/import', $accountImport->page(...), $admin); // P28A
    $systemPages = new AdminSystemPageController($app);
    $router->get('/admin/tools', $systemPages->tools(...), $admin);
    $router->get('/admin/worker', $systemPages->worker(...), $admin);
    $router->get('/admin/checks', $systemPages->checks(...), $admin);
    $router->get('/admin/runs', $adminRuns->page(...), $admin);
    $router->get('/admin/rule-settings', $ruleOverrides->page(...), $admin);
    $router->get('/admin/notifications', $notifications->adminPage(...), $admin); // X12: 옛 주소 → 설정 관리 #notification
    $router->get('/admin/repo-hosts', $repo->hostsPage(...), $admin); // X12: 옛 주소 → 설정 관리 #repository
    $router->get('/admin/schedules', $repo->schedulesPage(...), $admin);
    $router->get('/admin/config-settings', $configSettings->page(...), $admin); // P27A 설정 관리
    $router->get('/admin/backups', $backupsCtl->page(...), $admin); // P27B 백업·복원(시스템 관리 탭)
    $router->get('/admin/monitoring', $monitoring->page(...), $admin); // P30 시스템 모니터링
    $router->get('/admin/audit-logs', $auditLogs->page(...), $admin); // P30 감사 로그
    $router->get('/restore-status', $restoreStatus->page(...)); // P27B 복원 진행(로그인 없이)
    $router->get('/notifications', $notifications->page(...), $auth);

    $groupPages = new GroupPageController($app);
    $router->get('/groups', $groupPages->list(...), $auth);
    $router->get('/groups/new', $groupPages->newForm(...), $admin);
    $router->get('/groups/{id:\d+}', $groupPages->show(...), $auth);
    $router->get('/groups/{id:\d+}/edit', $groupPages->editForm(...), $admin);
    $router->get('/groups/{id:\d+}/delete', $groupPages->deleteForm(...), $admin);

    $router->get('/upload', (new UploadPageController($app))->show(...), $auth);

    $projectPages = new ProjectPageController($app);
    $router->get('/projects', $projectPages->list(...), $auth);
    $router->get('/projects/new', $projectPages->newForm(...), $admin);
    $router->get('/projects/{id:\d+}', $projectPages->show(...), $projectView);
    $router->get('/projects/{id:\d+}/edit', $projectPages->editForm(...), $projectAdmin);
    $router->get('/projects/{id:\d+}/delete', $projectPages->deleteForm(...), $projectAdmin);

    // 결함(P17): "결함" 메뉴는 프로젝트 선택(목록은 scopeFor) → 프로젝트 상세의 결함 탭. 결함 상세는 결함 → 프로젝트로 판정
    $defectPages = new DefectPageController($app);
    $router->get('/defects', $defectPages->picker(...), $auth);
    $router->get('/defects/{id}', $defectPages->show(...), $defectView);
    $historyPages = new \PJGuardian\Controllers\HistoryPageController($app);
    $router->get('/history', $historyPages->picker(...), $auth);
    $router->get('/rules', $rules->listPage(...), $auth);
    $router->get('/rules/syntax-error', $rules->detailPage(...), $auth);
    $router->get('/rules/{tool:ruff|eslint}/{rule:.+}', $rules->detailPage(...), $auth);
    $router->get('/projects/{id:\d+}/history', $historyPages->show(...), $projectView);
    $router->get('/my-defects', $defectPages->mine(...), $auth);
    // 분석 상세(P19): 분석 id → 소유 프로젝트로 판정(보이지 않으면 없는 분석과 같은 404)
    $router->get('/analyses/{id}', (new AnalysisPageController($app))->show(...), $analysisAccess);

    // 개발 전용(app.env=dev가 아니면 404)
    $router->get('/dev/styleguide', (new StyleguideController($app))->show(...));

    // P29 가이드(명세 16장): 로그인한 모든 사용자, 관리자 가이드는 admin만(비관리자 403 — 본문·제목·건수 없음). 이미지는 컨트롤러가 직접 판정(401·403)
    $guide = new \PJGuardian\Controllers\GuideController($app);
    $router->get('/guide', $guide->userPage(...), $auth);
    $router->get('/guide/user', $guide->userPage(...), $auth);
    $router->get('/guide/admin', $guide->adminPage(...), $admin);
    $router->get('/guide/errors', $guide->errorsPage(...), $auth);
    $router->get('/guide/faq', $guide->faqPage(...), $auth); // P29B FAQ 탭(편집 요소는 admin에게만 그림)
    $router->get('/guide/licenses', $guide->licensesPage(...), $auth);
    $router->get('/guide/img/{audience:user|admin}/{slug:[a-z0-9-]+}', $guide->image(...));
    $router->get('/api/v1/guide/user', $guide->apiUser(...), $auth);
    $router->get('/api/v1/guide/user/search', $guide->apiUserSearch(...), $auth);
    $router->get('/api/v1/guide/errors', $guide->apiErrors(...), $auth);
    $router->get('/api/v1/guide/admin', $guide->apiAdmin(...), $admin);
    $router->get('/api/v1/guide/admin/search', $guide->apiAdminSearch(...), $admin);
    // P29B FAQ: 읽기는 로그인한 모든 사용자(비공개는 admin만), 쓰기는 admin만(/api/v1/admin/… — 관리자 라우트 시험이 자동으로 검사)
    $faq = new \PJGuardian\Controllers\FaqController($app);
    $router->get('/api/v1/faq', $faq->list(...), $auth);
    $router->get('/api/v1/faq/{id:\d+}', $faq->show(...), $auth);
    $router->post('/api/v1/admin/faq', $faq->create(...), $admin);
    $router->post('/api/v1/admin/faq/reorder', $faq->reorder(...), $admin);
    $router->patch('/api/v1/admin/faq/{id:\d+}', $faq->update(...), $admin);
    $router->delete('/api/v1/admin/faq/{id:\d+}', $faq->delete(...), $admin);
};
