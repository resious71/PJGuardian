<?php
/**
 * 복원 진행 독립 상태 페이지(P27B, 로그인·DB 없이). 유지보수 중에는 모든 화면 요청이 이 페이지(503)로 바뀐다.
 * pages/restore_status.js가 GET /api/v1/restore-status를 폴링해 단계·결과·수동 복구 안내를 textContent로 그린다.
 * 비밀·절대 경로·요청자 이름은 없다(수동 복구 안내는 프로젝트 기준 상대 경로).
 *
 * @var bool $maintenance
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<div class="restore-status" data-restore-status data-login-href="<?= $e($assets->page('login')) ?>">
  <h1><?= $icons->withLabel('action.restore', 24, $t('restore_status.title')) ?></h1>
  <p class="notice notice--warn" data-maintenance<?= $maintenance ? '' : ' hidden' ?>><?= $icons->icon('system.warn', 16) ?> <?= $e($t('restore_status.maintenance')) ?></p>
  <p class="muted"><?= $e($t('restore_status.intro')) ?></p>
  <p class="muted" data-loading role="status" aria-live="polite"><?= $e($t('restore_status.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>

  <section class="card" aria-labelledby="rs-state-title" data-card hidden>
    <h2 id="rs-state-title"><?= $e($t('restore_status.state_title')) ?></h2>
    <p class="restore-status__state" data-state role="status" aria-live="polite"></p>
    <dl class="detail-list" data-meta></dl>
    <ol class="restore-status__steps" data-steps aria-label="<?= $e($t('restore_status.steps_label')) ?>"></ol>
    <p class="notice notice--error" data-failure role="alert" hidden></p>
    <div data-result hidden>
      <h3><?= $e($t('restore_status.result_title')) ?></h3>
      <ul class="plain-list" data-result-list></ul>
    </div>
    <div class="notice notice--error" data-manual hidden>
      <p><?= $icons->withLabel('ui.danger', 16, $t('restore_status.manual_title')) ?></p>
      <p><?= $e($t('restore_status.manual_intro')) ?></p>
      <ol data-manual-list></ol>
    </div>
    <p data-login hidden><a class="btn btn--primary" href="<?= $e($assets->page('login')) ?>"><?= $e($t('restore_status.go_login')) ?></a></p>
  </section>
  <p class="muted" data-updated></p>
</div>
