<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Actor;
use PJGuardian\Support\Lang;

/**
 * 소스 저장소 연동(P25, 명세 18). 프로젝트 라우트는 RequireProjectAccess(보이지 않으면 404)가 먼저, 변경·테스트·가져오기는 admin만(403).
 * 자격 증명은 어떤 응답에도 평문으로 내리지 않는다(secret_set만). 일반 사용자에게는 상태·출처·마지막 커밋만(RepoConnections::view).
 * 018 마이그레이션 전 DB(표 없음): 조회는 "업로드 전용", 변경은 503 repo_unavailable.
 */
final class RepoController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /api/v1/projects/{id}/repo */
    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        try {
            $view = $this->app->repoConnections()->view($id, self::isAdmin($request));
            // P26: 주기 분석 요약(사용 중 여부·다음 예정 — 일반 사용자도. admin이면 연속 실패도). 019 전 DB면 null
            $schedule = $this->app->schedules()->publicSummary($id, self::isAdmin($request));
            $view['schedule'] = $schedule;
            $view['next_run_at'] = $schedule['next_run_at'] ?? null;
            return Response::data($view);
        } catch (\PDOException $e) {
            if (self::missingTable($e)) {
                return Response::data(['configured' => false, 'kind' => 'none', 'status' => null, 'available' => false]);
            }
            throw $e;
        }
    }

    /** PUT /api/v1/projects/{id}/repo (admin) */
    public function save(Request $request): Response
    {
        $body = self::body($request);
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoConnections()->save(Actor::fromUser($user, $request->clientIp), (int) $user['id'], (int) $request->param('id'), $body)));
    }

    /** DELETE /api/v1/projects/{id}/repo (admin) — 업로드 전용으로 되돌림(쌓인 분석의 출처 기록은 남는다) */
    public function delete(Request $request): Response
    {
        $user = $request->attribute('user');
        $actor = Actor::fromUser($user, $request->clientIp);
        $this->guard(fn () => $this->app->repoConnections()->delete($actor, (int) $request->param('id')));
        // P26: 연결이 없으면 주기 분석을 할 수 없다 → 자동으로 끄고 감사 로그(schedule.auto_disable)
        $this->app->schedules()->disableForDisconnect($actor, (int) $request->param('id'));
        return Response::data(['deleted' => true]);
    }

    // ---- 주기 분석(P26, admin) ----

    /** GET /api/v1/projects/{id}/schedule */
    public function schedule(Request $request): Response
    {
        return Response::data($this->scheduleGuard(fn () => $this->app->schedules()->view((int) $request->param('id'))));
    }

    /** PUT /api/v1/projects/{id}/schedule 본문 {enabled, interval_days, run_time, revision?} */
    public function saveSchedule(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new ApiException(400, 'invalid_schedule_input', ['field' => 'body'], null, [], [], ['field' => 'body']);
        }
        $user = $request->attribute('user');
        return Response::data($this->scheduleGuard(fn () => $this->app->schedules()->save(Actor::fromUser($user, $request->clientIp), (int) $user['id'], (int) $request->param('id'), $body)));
    }

    /** GET /admin/schedules — 시스템 관리 > 주기 분석(시스템 모니터링 화면이 생기면 옮긴다) */
    public function schedulesPage(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('admin/schedules', [
            'title' => Lang::get('admin_schedules.title'), 'user' => $request->attribute('user'), 'active' => 'admin.tools',
            'scripts' => ['js/pages/admin_schedules.js'], 'body_class' => 'page-admin-system',
        ], ['sub' => 'schedules']));
    }

    /** GET /api/v1/admin/schedules — 주기 분석 현황(일정 표·최근 실패·최근 건너뜀·스케줄러 상태) */
    public function schedules(Request $request): Response
    {
        return Response::data($this->scheduleGuard(fn () => $this->app->scheduleStatus()->overview()));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function scheduleGuard(callable $fn): mixed
    {
        try {
            return $this->guard($fn);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'no such table: project_schedules') || str_contains($e->getMessage(), 'no such table: schedule_ticks')
                || str_contains($e->getMessage(), 'no such column: retry_')) {
                throw new ApiException(503, 'schedule_unavailable');
            }
            throw $e;
        }
    }

    /** POST /api/v1/projects/{id}/repo/test (admin) 본문 {draft?: 저장 전 입력} */
    public function test(Request $request): Response
    {
        $body = $request->json();
        $draft = is_array($body) && is_array($body['draft'] ?? null) ? $body['draft'] : null;
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoService()->test(Actor::fromUser($user, $request->clientIp), (int) $user['id'], (int) $request->param('id'), $draft)));
    }

    /** POST /api/v1/projects/{id}/repo/fetch (admin) — 지금 가져와 분석(큐 등록만, git·p4는 워커가 실행) */
    public function fetch(Request $request): Response
    {
        $user = $request->attribute('user');
        // P27: force = admin이 "변경이 없습니다. 그래도 분석할까요?"에서 확인(변경 없음 판단을 건너뛴 일반 분석). 라우트가 이미 admin 전용
        $body = $request->json();
        $force = $body['force'] ?? false;
        if (!is_bool($force)) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'force'], null, [], [], ['field' => 'force']);
        }
        return Response::data($this->guard(fn () => $this->app->repoService()->fetchNow(Actor::fromUser($user, $request->clientIp), (int) $user['id'], (int) $request->param('id'), $force)), 202);
    }

    // ---- 허용 호스트(admin) ----

    /** GET /admin/repo-hosts(admin, 옛 주소): X12에서 허용 호스트는 설정 관리 "저장소 연결" 탭의 구역으로 옮겼다 → 그 탭으로 이동($admin이 먼저 — 비관리자 403) */
    public function hostsPage(Request $request): Response
    {
        return Response::redirect($request->basePath . '/admin/config-settings#repository');
    }

    /** GET /api/v1/admin/repo-hosts */
    public function hosts(Request $request): Response
    {
        return Response::data($this->guard(fn () => $this->app->repoAllowedHosts()->list()));
    }

    /** POST /api/v1/admin/repo-hosts */
    public function addHost(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoAllowedHosts()->add(Actor::fromUser($user, $request->clientIp), (int) $user['id'], self::body($request))), 201);
    }

    /** PATCH /api/v1/admin/repo-hosts/{id} */
    public function updateHost(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoAllowedHosts()->update(Actor::fromUser($user, $request->clientIp), (int) $request->param('id'), self::body($request))));
    }

    /** DELETE /api/v1/admin/repo-hosts/{id} */
    public function deleteHost(Request $request): Response
    {
        $user = $request->attribute('user');
        $this->guard(fn () => $this->app->repoAllowedHosts()->delete(Actor::fromUser($user, $request->clientIp), (int) $request->param('id')));
        return Response::data(['deleted' => true]);
    }

    /** POST /api/v1/admin/repo-hosts/{id}/test — 등록된 호스트 하나의 연결 테스트(P26B) */
    public function testHost(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoHostTests()->testOne(Actor::fromUser($user, $request->clientIp), (int) $user['id'], (int) $request->param('id'))));
    }

    /** POST /api/v1/admin/repo-hosts/test-all — 등록된 호스트 차례로(최대 repository.host_test_max) */
    public function testAllHosts(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->repoHostTests()->testAll(Actor::fromUser($user, $request->clientIp), (int) $user['id'])));
    }

    /** @return array<string, mixed> */
    private static function body(Request $request): array
    {
        $b = $request->json();
        if (!is_array($b) || ($b !== [] && array_is_list($b))) {
            throw new ApiException(400, 'invalid_repo_input', ['field' => 'body'], null, [], [], ['field' => 'body']);
        }
        return $b;
    }

    private static function isAdmin(Request $request): bool
    {
        return ($request->attribute('user')['role'] ?? '') === 'admin';
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function guard(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            if (self::missingTable($e)) {
                throw new ApiException(503, 'repo_unavailable');
            }
            throw $e;
        } catch (\PJGuardian\Services\Repo\RepoException $e) {
            // 웹 요청에서 난 저장소 오류(연결 없음 등)는 409 + 코드
            throw new ApiException(409, $e->errorCode);
        }
    }

    private static function missingTable(\PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'no such table: project_repo') || str_contains($e->getMessage(), 'no such table: repo_allowed')
            || str_contains($e->getMessage(), 'no such column: source_url') || str_contains($e->getMessage(), 'no such column: last_test_at');
    }
}
