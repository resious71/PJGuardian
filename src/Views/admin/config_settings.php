<?php
/**
 * 관리자 "시스템 관리 > 설정 관리"(P27A, admin 전용 — X10에서 별도 메뉴를 시스템 관리의 첫 탭으로 옮김, 주소 /admin/config-settings 그대로): 허용 목록(ConfigSchema::EDITABLE) 운영 값의 확인·변경·기본값 복원·경로 확인·변경 이력.
 * 항목은 pages/admin_config_settings.js가 GET /api/v1/admin/config-settings로 읽어 textContent로 그린다(P27F: 묶음마다 탭 하나 — 탭 목록은 응답의 groups,
 * 주소 해시 #<묶음>, 한 열 폼 목록, 모든 탭이 한 폼 상태·검색은 탭을 가로질러).
 * 저장은 변경 미리보기 대화상자 → PUT(버전 표지, 전체 성공·전체 실패). 값은 config/settings.local.ini에만(config.ini는 그대로).
 * DB에 저장하는 화면 표시 설정(알림 보관·대시보드 추이 수)은 여기서 다루지 않고 안내 링크만 둔다(사용자 결정 2026-10-09).
 *
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var bool $allowLocalDev
 * @var int $hostTestMax
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('config_settings.intro')) ?></p>
  <p class="muted config-settings__db-note"><?= $e($t('config_settings.db_notice')) ?>
    <a href="#notification" data-notice-notifications><?= $e($t('config_settings.db_notice_notifications')) ?></a>,
    <a href="<?= $e($assets->page('dashboard')) ?>"><?= $e($t('config_settings.db_notice_dashboard')) ?></a></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => 'config-settings']) ?>

<?php /* P29C: 카테고리 탭 → 관리자 가이드 절(GuideLinks::CONFIG_TABS) — 화면 스크립트가 탭을 바꿀 때 머리의 "이 화면 도움말" 주소를 고친다 */ ?>
<div class="config-settings" data-config-settings data-help-tabs="<?= $e((string) json_encode(\PJGuardian\Support\GuideLinks::CONFIG_TABS)) ?>">
  <div class="notice notice--warn" data-warnings role="alert" hidden></div>
  <div class="notice notice--warn" data-restart-banner role="status" hidden></div>

  <div class="config-settings__toolbar">
    <div class="field config-settings__search">
      <label class="field__label" for="cs-search"><?= $icons->withLabel('action.search', 16) ?></label>
      <input class="field__input" type="search" id="cs-search" data-search autocomplete="off" disabled>
    </div>
    <button type="button" class="btn" data-reload disabled></button>
  </div>

  <p class="muted" data-loading role="status" aria-live="polite"><?= $e($t('config_settings.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <div class="tabs__list config-tabs" role="tablist" data-tablist hidden></div>
  <p class="muted" data-search-none hidden></p>
  <p class="config-settings__search-hint" data-search-hint role="status" aria-live="polite" hidden></p>
  <div class="config-settings__panels" data-groups></div>

  <div class="config-settings__bar" data-bar>
    <button type="button" class="btn btn--primary" data-preview disabled></button>
    <button type="button" class="btn" data-discard disabled></button>
    <p class="muted" data-status role="status" aria-live="polite"></p>
  </div>

  <?php /* X12: 저장소가 다른 구역 — 화면 스크립트가 해당 카테고리 탭 패널로 옮긴다(옮기기 전에는 숨김). 각자 자기 단추·API로 저장 */ ?>
  <div class="config-merge-sources" data-merge-sources hidden>
    <?= $view->partial('admin/_notif_retention') ?>
    <?= $view->partial('admin/_repo_hosts', ['allowLocalDev' => $allowLocalDev, 'hostTestMax' => $hostTestMax]) ?>
  </div>

  <section class="card config-settings__history" aria-labelledby="cs-history-title">
    <h2 id="cs-history-title" data-history-title></h2>
    <p class="muted" data-history-intro></p>
    <div class="table-wrap" data-history></div>
  </section>
</div>
