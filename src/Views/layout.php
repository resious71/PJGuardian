<?php
/**
 * 로그인 후 공통 레이아웃: 상단 주 메뉴, (관리자만) 관리자 메뉴, 사용자 메뉴, 배너 영역, 본문, 푸터.
 * 관리자 메뉴는 role=admin일 때만 렌더링한다. 비관리자 HTML에는 관리자 메뉴 항목이 전혀 없다(명세 14, 16.4).
 * href가 없는 메뉴는 아직 화면이 없어 비활성 표시만 한다(링크를 만들지 않음).
 *
 * @var array{title:string, user:array{login_id:string,display_name:string,role:string}, active?:?string,
 *            banners?:list<array{level:string,message:string}>, body_class?:string} $page
 * @var string $content 본문 HTML(이미 이스케이프된 템플릿 결과)
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var string $appVersion
 * @var string $csrfToken 로그아웃 폼 숨은 필드
 * @var list<array{level:string,message:string}> $flashBanners 세션의 일회성 알림(App::view가 꺼냄)
 * @var array{admins:list<string>, self:bool, public:bool}|null $initialPassword 초기 비밀번호를 바꾸지 않은 관리자(P31, admin에게만 — 닫을 수 없는 경고 배너)
 * @var list<array{code:string,level:string,params:array<string,mixed>}> $healthAlerts 관리자 장애 경고(P30, admin에게만 — App::view가 SystemHealth 캐시에서)
 * @var bool $canSeeGroups "그룹" 메뉴 노출(App::view가 GroupPermission::managesAny로 계산)
 * @var bool $isGroupManager 역할 옆 "그룹 관리자" 배지(managesAny이고 admin이 아닐 때, App::view가 계산)
 */

use PJGuardian\Support\Navigation;

$user = $page['user'];
$isAdmin = ($user['role'] ?? '') === 'admin';
$active = $page['active'] ?? null;
$navItem = static function (array $item) use ($e, $t, $icons, $assets, $active): string {
    $current = $item['name'] === $active ? ' aria-current="page"' : '';
    if ($item['href'] === null) {
        // 준비 중 항목: 툴팁에도 메뉴 이름을 넣는다(아이콘만 보이는 폭에서 이름을 알 수 있게, P21C)
        return '<span class="nav-item is-disabled" aria-disabled="true" data-nav="' . $e($item['name']) . '" title="' . $e($icons->label($item['name']) . ' — ' . $t('layout.coming_soon')) . '">'
            . $icons->withLabel($item['name'], 16) . '</span>';
    }
    if ($item['name'] === 'nav.notifications') {
        // P24 알림: 안 읽은 개수 배지(숫자 글자, 0이면 숨김)와 미리보기 패널(ui/notification_bell.js). 스크립트가 없으면 목록 화면 링크로 동작한다
        return '<a class="nav-item nav-item--notif" href="' . $e($assets->page($item['href'])) . '" data-nav="' . $e($item['name']) . '" data-notif-bell'
            . ' aria-controls="notif-panel" title="' . $e($icons->label($item['name'])) . '"' . $current . '>'
            . $icons->withLabel($item['name'], 16) . '<span class="notif-badge" data-notif-badge aria-hidden="true" hidden></span></a>';
    }
    return '<a class="nav-item" href="' . $e($assets->page($item['href'])) . '" data-nav="' . $e($item['name']) . '" title="' . $e($icons->label($item['name'])) . '"' . $current . '>'
        . $icons->withLabel($item['name'], 16) . '</a>';
};
?>
<!doctype html>
<html lang="<?= $e($uiLang ?? 'ko') ?>"<?= ($uiTheme ?? 'system') !== 'system' ? ' data-theme="' . $e($uiTheme) . '"' : '' ?>>
<head>
<?= $view->partial('partials/head', ['page' => $page]) ?>
</head>
<body class="app<?= isset($page['body_class']) ? ' ' . $e($page['body_class']) : '' ?>">
<a class="skip-link" href="#main"><?= $e($t('layout.skip_to_content')) ?></a>
<header class="topbar">
  <a class="topbar__brand" href="<?= $e($assets->page(Navigation::HOME)) ?>"><?= $icons->icon('ui.logo', 24) ?><span class="topbar__name">PJGuardian</span></a>
  <nav class="topbar__nav" aria-label="<?= $e($t('layout.main_menu')) ?>">
    <ul class="nav-list">
      <?php foreach (Navigation::main(($canSeeGroups ?? false) && !$isAdmin) as $item): ?>
      <li><?= $navItem($item) ?></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php if ($isAdmin): ?>
  <nav class="topbar__nav topbar__nav--admin" aria-label="<?= $e($t('layout.admin_menu')) ?>">
    <span class="topbar__nav-title" aria-hidden="true"><?= $e($t('layout.admin_menu')) ?></span>
    <ul class="nav-list">
      <?php foreach (Navigation::admin() as $item): ?>
      <li><?= $navItem($item) ?></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>
  <?php $roleText = $t($isAdmin ? 'layout.role_admin' : 'layout.role_user'); ?>
  <div class="topbar__user" role="group" aria-label="<?= $e($t('layout.user_menu')) ?>">
    <?= $view->partial('partials/ui_switch') ?>
    <?php /* "내 정보"(X05): 새 메뉴 항목 대신 사용자 이름을 링크로. 좁은 폭에서 이름이 숨겨져도 아이콘이 남아 같은 링크로 들어간다 */ ?>
    <a class="topbar__account" href="<?= $e($assets->page('account')) ?>" aria-label="<?= $e($t('account.link_label')) ?>"
       title="<?= $e($t('account.link_title', ['name' => $user['display_name'], 'role' => $roleText])) ?>" data-role-text="<?= $e($roleText) ?>"<?= $active === 'account' ? ' aria-current="page"' : '' ?>>
      <?= $icons->icon('ui.user', 20) ?>
      <span class="topbar__username"><?= $e($user['display_name']) ?></span>
    </a>
    <span class="topbar__role"><?= $e($roleText) ?></span>
    <?php if ($isGroupManager ?? false): ?>
    <span class="topbar__badge" data-badge="group-manager" title="<?= $e($icons->label('ui.group_manager')) ?>"><?= $icons->withLabel('ui.group_manager', 16) ?></span>
    <?php endif; ?>
    <form class="inline-form" method="post" action="<?= $e($assets->page('logout')) ?>">
      <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
      <button type="submit" class="nav-item nav-item--button" data-nav="ui.logout" title="<?= $e($icons->label('ui.logout')) ?>"><?= $icons->withLabel('ui.logout', 16) ?></button>
    </form>
  </div>
  <div class="notif-panel" id="notif-panel" role="region" aria-label="<?= $e($t('notifications.title')) ?>" data-notif-panel hidden></div>
</header>
<div class="banners" role="region" aria-label="<?= $e($t('layout.banners')) ?>" id="banners">
  <?php if ($isAdmin && ($healthAlerts ?? []) !== []): ?>
  <?php $hFail = in_array('fail', array_column($healthAlerts, 'level'), true); $hFirst = $healthAlerts[0]; ?>
  <div class="banner banner--<?= $hFail ? 'error' : 'warn' ?> health-banner" data-health-banner data-count="<?= count($healthAlerts) ?>">
    <?= $icons->withLabel($hFail ? 'system.error' : 'system.warn', 16, $t('monitoring.banner', ['n' => count($healthAlerts), 'text' => $t('monitoring.alert.' . $hFirst['code'], (array) $hFirst['params'])])) ?>
    <a class="health-banner__link" href="<?= $e($assets->page('/admin/monitoring')) ?>"><?= $e($t('monitoring.banner_link')) ?></a>
  </div>
  <?php endif; ?>
  <?php if (($upgradeMaintenance ?? null) !== null): $um = $upgradeMaintenance; ?>
  <div class="banner banner--warn maintenance-banner" data-maintenance-banner="<?= $um['active'] ? 'on' : 'notice' ?>">
    <?= $icons->withLabel('system.warn', 16, $um['active'] ? $t($isAdmin ? 'maintenance.banner.on_admin' : 'maintenance.banner.on', ['message' => $um['message']]) : $t('maintenance.banner.notice', ['time' => (string) $um['notice'], 'message' => $um['message']])) ?>
  </div>
  <?php endif; ?>
  <?php if ($isAdmin && ($initialPassword ?? null) !== null): ?>
  <div class="banner banner--warn initial-password-banner" data-initial-password-banner data-public="<?= $initialPassword['public'] ? '1' : '0' ?>">
    <?= $icons->withLabel('system.warn', 16, $t($initialPassword['public'] ? 'security.initial_password.banner_public' : 'security.initial_password.banner',
      ['ids' => implode(', ', $initialPassword['admins'])])) ?>
    <a class="health-banner__link" href="<?= $e($assets->page($initialPassword['self'] ? '/password' : '/admin/users')) ?>"><?= $e($t($initialPassword['self'] ? 'security.initial_password.link_self' : 'security.initial_password.link_users')) ?></a>
  </div>
  <?php endif; ?>
  <?php foreach (array_merge($flashBanners ?? [], $page['banners'] ?? []) as $banner): ?>
  <div class="banner banner--<?= $e($banner['level']) ?>"><?= $icons->withLabel($banner['level'] === 'error' ? 'system.error' : ($banner['level'] === 'warn' ? 'system.warn' : 'ui.info'), 16, $banner['message']) ?></div>
  <?php endforeach; ?>
</div>
<main id="main" class="main" tabindex="-1">
<?php if (!empty($page['help'])): ?>
<div class="page-help"><?= $view->partial('partials/help_link', ['audience' => $page['help'][0], 'key' => $page['help'][1]]) ?></div>
<?php endif; ?>
<?= $content ?>
</main>
<footer class="footer">
  <span><?= $e($t('layout.app_version', ['version' => $appVersion])) ?></span>
  <a href="<?= $e($assets->page('guide')) ?>" data-footer-guide><?= $e($t('layout.footer_guide')) ?></a>
  <a href="<?= $e($assets->page('guide/licenses')) ?>" data-footer-licenses><?= $e($t('layout.footer_licenses')) ?></a>
</footer>
<div id="toasts" class="toast-region" role="status" aria-live="polite" aria-label="<?= $e($t('layout.notifications_region')) ?>"></div>
</body>
</html>
