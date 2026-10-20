<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 상단 메뉴 정의(명세 13, 14, 16.4, 20.3). 메뉴 이름은 아이콘 의미 이름과 같다(nav.*, admin.*).
 * href가 null인 항목은 아직 화면이 없는 메뉴로, 비활성 표시만 하고 링크를 만들지 않는다.
 * 관리자 메뉴는 role=admin일 때만 서버에서 렌더링한다(비관리자 HTML에 아예 없음).
 * 화면을 만드는 단계가 해당 항목의 href를 채운다.
 */
final class Navigation
{
    /**
     * 로그인 후 "홈" 경로(한 곳에서만 정한다). 루트(/)와 로그인 성공 후 이동이 이 값을 쓴다.
     * P10은 임시 홈(/home), P11부터 프로젝트 목록. 이후 대시보드(P20~)가 이 값을 바꿀 수 있다. 옛 /home은 홈으로 이동한다.
     */
    public const HOME = '/projects';

    /**
     * 로그인 후 돌아갈 주소(next)를 검사한다(열린 리다이렉트 방지). 앱 안의 화면 경로만 허용하고, 아니면 null.
     * 허용: '/'로 시작하는 경로(쿼리 포함). 거부: '//'·'/\'(다른 호스트), 스킴, 제어 문자, API 경로, 2000자 초과.
     */
    public static function safeNext(mixed $next): ?string
    {
        if (!is_string($next) || $next === '' || strlen($next) > 2000 || $next[0] !== '/') {
            return null;
        }
        if (str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\x00-\x1F\x7F]/', $next) === 1) {
            return null;
        }
        $parts = parse_url($next);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($path === '/api' || str_starts_with($path, '/api/') || $path === '/login' || $path === '/logout') {
            return null;
        }
        return $next;
    }

    /**
     * @param bool $groups 일반 메뉴에 "그룹"을 넣을지. **admin이 아닌** 그룹 관리자만(GroupPermission::managesAny, 명세 3.1).
     *                     admin은 관리자 메뉴의 "그룹 관리"(admin.groups)로 같은 화면에 가므로 여기에는 넣지 않는다(중복 방지).
     * @return list<array{name:string, href:?string}>
     */
    public static function main(bool $groups = false): array
    {
        return array_values(array_filter([
            ['name' => 'nav.dashboard', 'href' => '/dashboard'],
            ['name' => 'nav.projects', 'href' => '/projects'],
            $groups ? ['name' => 'nav.groups', 'href' => '/groups'] : null,
            ['name' => 'nav.defects', 'href' => '/defects'],
            ['name' => 'nav.my_defects', 'href' => '/my-defects'],
            ['name' => 'nav.rules', 'href' => '/rules'],
            ['name' => 'nav.history', 'href' => '/history'],
            // P24: 알림 목록. 레이아웃이 안 읽은 개수 배지를 붙이고, 스크립트가 누르면 미리보기 패널을 연다(스크립트 없으면 목록으로 이동)
            ['name' => 'nav.notifications', 'href' => '/notifications'],
            ['name' => 'nav.guide', 'href' => '/guide'], // P29
        ]));
    }

    /** @return list<array{name:string, href:?string}> */
    public static function admin(): array
    {
        return [
            ['name' => 'admin.users', 'href' => '/admin/users'],
            ['name' => 'admin.groups', 'href' => '/groups'],
            ['name' => 'admin.projects', 'href' => '/projects'],
            // "시스템 관리"(X10, 예전 P20A "도구·워커 관리"): 분석 도구(첫 탭) / 워커 / 점검 / Run 관리 / 규칙 설정 / 설정 관리(X12) / … — 탭은 admin/_subnav.
            // 내부 이름은 그대로 admin.tools(아이콘·active 키). 시스템 모니터링(admin.system)·감사 로그(admin.audit)는 P30에서 활성화
            ['name' => 'admin.tools', 'href' => '/admin/tools'],
            ['name' => 'admin.system', 'href' => '/admin/monitoring'],
            ['name' => 'admin.audit', 'href' => '/admin/audit-logs'],
            ['name' => 'admin.guide', 'href' => '/guide/admin'], // P29(admin만 — 관리자 메뉴)
        ];
    }
}
