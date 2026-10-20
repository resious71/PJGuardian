<?php

declare(strict_types=1);

namespace PJGuardian\Controllers;

use PJGuardian\App;
use PJGuardian\Http\ApiException;
use PJGuardian\Http\Pagination;
use PJGuardian\Http\Request;
use PJGuardian\Http\Response;
use PJGuardian\Services\Notifications\NotificationInbox;
use PJGuardian\Services\Notifications\NotificationService;
use PJGuardian\Support\Lang;

/**
 * 앱 안 알림(P24): /notifications 화면과 /api/v1/notifications… API. 로그인 필요, 변경은 CSRF, **본인 알림만**(admin도 같다).
 * 017 마이그레이션 전 DB(표 없음): 개수는 0(헤더가 깨지지 않게), 나머지는 503 notifications_unavailable.
 */
final class NotificationController
{
    public function __construct(private readonly App $app)
    {
    }

    /** GET /notifications(목록 + "알림 설정" 탭) */
    public function page(Request $request): Response
    {
        return Response::html($this->app->view($request)->page('notifications/index', [
            'title' => Lang::get('notifications.title'), 'user' => $request->attribute('user'), 'active' => 'nav.notifications',
            'scripts' => ['js/pages/notifications.js'],
        ], ['pageSize' => $this->app->config->int('ui.defect_page_size'),
            'kinds' => NotificationService::kindsFor(($request->attribute('user')['role'] ?? '') === 'admin')]));
    }

    /**
     * GET /admin/notifications(admin, 옛 주소): X12에서 알림 보관 기간은 설정 관리 "알림" 탭의 구역으로 옮겼다 → 그 탭으로 이동.
     * 라우트 미들웨어($admin)가 먼저 판정하므로 비관리자에게는 지금처럼 403(이동 주소도 알리지 않음).
     */
    public function adminPage(Request $request): Response
    {
        return Response::redirect($request->basePath . '/admin/config-settings#notification');
    }

    /** GET /api/v1/notifications?kind=&unread=1&page=&size= */
    public function index(Request $request): Response
    {
        $kind = $request->query['kind'] ?? null;
        if ($kind === '') {
            $kind = null;
        }
        if ($kind !== null && !in_array($kind, NotificationService::KINDS, true)) {
            throw new ApiException(400, 'invalid_notification_filter', [], null, [], [], ['field' => 'kind']);
        }
        $unread = $request->query['unread'] ?? null;
        if (!in_array($unread, [null, '', '0', '1'], true)) {
            throw new ApiException(400, 'invalid_notification_filter', [], null, [], [], ['field' => 'unread']);
        }
        $p = Pagination::fromRequest($request, $this->app->config->int('ui.defect_page_size'), $this->app->config->int('ui.page_size_max'));
        [$items, $total] = $this->guard(fn () => $this->inbox()->list($request->attribute('user'), ['kind' => $kind, 'unread' => $unread === '1'], $p->size, $p->offset()));
        return Response::page($items, $p, $total);
    }

    /** GET /api/v1/notifications/unread-count → {count} */
    public function unreadCount(Request $request): Response
    {
        try {
            return Response::data(['count' => $this->inbox()->unreadCount($request->attribute('user'))]);
        } catch (\PDOException $e) {
            if (self::missingTable($e)) {
                return Response::data(['count' => 0, 'available' => false]);
            }
            throw $e;
        }
    }

    /** POST /api/v1/notifications/{id}/read */
    public function read(Request $request): Response
    {
        return Response::data($this->guard(fn () => $this->inbox()->markRead($request->attribute('user'), $this->id($request))));
    }

    /** POST /api/v1/notifications/{id}/unread */
    public function unread(Request $request): Response
    {
        return Response::data($this->guard(fn () => $this->inbox()->markUnread($request->attribute('user'), $this->id($request))));
    }

    /** POST /api/v1/notifications/read-all → {updated}(내 모든 알림, 필터와 무관) */
    public function readAll(Request $request): Response
    {
        return Response::data(['updated' => $this->guard(fn () => $this->inbox()->readAll($request->attribute('user')))]);
    }

    /** DELETE /api/v1/notifications/{id} */
    public function delete(Request $request): Response
    {
        $this->guard(fn () => $this->inbox()->delete($request->attribute('user'), $this->id($request)));
        return Response::data(['deleted' => true]);
    }

    /** DELETE /api/v1/notifications/read → {deleted}(내 읽은 알림 모두) */
    public function deleteRead(Request $request): Response
    {
        return Response::data(['deleted' => $this->guard(fn () => $this->inbox()->deleteRead($request->attribute('user')))]);
    }

    /** GET /api/v1/notifications/preferences → {종류: bool} */
    public function preferences(Request $request): Response
    {
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->notifications()->preferences((int) $user['id'], ($user['role'] ?? '') === 'admin')));
    }

    /** PUT /api/v1/notifications/preferences 본문 {종류: bool, …}(일부만 보내도 된다) → 바뀐 뒤 전체 */
    public function updatePreferences(Request $request): Response
    {
        $body = $request->json();
        if (!is_array($body) || $body === [] || array_is_list($body)) {
            throw new ApiException(400, 'invalid_notification_pref', [], null, [], [], ['kind' => null]);
        }
        $changes = [];
        foreach ($body as $kind => $on) {
            if (!in_array($kind, NotificationService::kindsFor(($request->attribute('user')['role'] ?? '') === 'admin'), true) || !is_bool($on)) {
                throw new ApiException(400, 'invalid_notification_pref', [], null, [], [], ['kind' => (string) $kind]);
            }
            $changes[(string) $kind] = $on;
        }
        $user = $request->attribute('user');
        return Response::data($this->guard(fn () => $this->app->notifications()->setPreferences((int) $user['id'], $changes, ($user['role'] ?? '') === 'admin')));
    }

    private function inbox(): NotificationInbox
    {
        return new NotificationInbox($this->app->db(), $this->app->clock, $this->app->projectAccess());
    }

    private function id(Request $request): int
    {
        $id = (string) $request->param('id');
        if (preg_match('/^[1-9][0-9]{0,17}$/', $id) !== 1) {
            throw new ApiException(404, 'notification_not_found');
        }
        return (int) $id;
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
                throw new ApiException(503, 'notifications_unavailable');
            }
            throw $e;
        }
    }

    private static function missingTable(\PDOException $e): bool
    {
        return str_contains($e->getMessage(), 'no such table: notification');
    }
}
