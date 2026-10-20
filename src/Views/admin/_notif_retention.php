<?php
/**
 * 설정 관리 "알림" 탭 안의 "알림 보관 기간" 구역(X12 — 예전 P24 별도 화면 /admin/notifications를 옮김, admin 전용).
 * 값은 app_settings의 notifications.retain_read_days·retain_unread_days·max_per_user — 설정 관리(settings.local.ini)와 **저장소가 달라** 따로 저장한다:
 * GET/PATCH /api/v1/admin/settings(범위 밖 400, 감사 setting.update). 화면 스크립트 pages/admin_notifications.js.
 * 설정 관리 화면이 이 구역을 "알림" 탭 패널 끝으로 옮긴다(data-merge-into·data-merge-pos, pages/admin_config_settings.js).
 *
 * @var callable $e
 * @var callable $t
 */
$fields = ['notifications.retain_read_days' => 'retain_read_days', 'notifications.retain_unread_days' => 'retain_unread_days', 'notifications.max_per_user' => 'max_per_user'];
?>
<section class="config-merge admin-notif" data-merge-into="notification" data-merge-pos="end" aria-labelledby="admin-notif-title">
  <h3 class="config-merge__title" id="admin-notif-title"><?= $e($t('admin_notifications.title')) ?><span class="config-merge__dirty" data-merge-dirty hidden></span></h3>
  <p data-help><?= $e($t('admin_notifications.help')) ?></p>
  <form class="admin-notif__form" data-admin-notif novalidate>
    <?php foreach ($fields as $key => $name): ?>
    <div class="field">
      <label class="field__label" for="an-<?= $e($name) ?>"><?= $e($t('admin_notifications.' . $name)) ?></label>
      <input class="field__input" type="number" inputmode="numeric" step="1" id="an-<?= $e($name) ?>" data-setting="<?= $e($key) ?>"
             aria-describedby="an-<?= $e($name) ?>-range an-<?= $e($name) ?>-error" disabled>
      <span class="field__hint muted" id="an-<?= $e($name) ?>-range" data-range></span>
      <span class="field__error" id="an-<?= $e($name) ?>-error" data-field-error hidden></span>
    </div>
    <?php endforeach; ?>
    <p class="muted"><?= $e($t('admin_notifications.max_help')) ?></p>
    <div class="admin-notif__bar">
      <button type="submit" class="btn btn--primary" data-save disabled><?= $e($t('admin_notifications.save')) ?></button>
      <p class="muted" data-status role="status" aria-live="polite"></p>
    </div>
    <p class="notice notice--error" data-error role="alert" hidden></p>
  </form>
</section>
