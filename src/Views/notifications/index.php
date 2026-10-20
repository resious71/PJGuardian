<?php
/**
 * 알림(P24): "알림 목록" 탭(필터·목록·쪽 나누기·모두 읽음·읽은 알림 삭제)과 "알림 설정" 탭(종류별 스위치).
 * 목록·설정은 pages/notifications.js가 API(/api/v1/notifications…)로 읽어 textContent로 그린다(알림 문구는 kind + params로 화면이 만든다).
 * 필터는 P21V 방식: 조건을 바꾼 뒤 "검색"을 눌러야 적용되고, 적용된 조건은 주소(kind=, unread=1, page=)에 남는다.
 *
 * @var int $pageSize
 * @var list<string> $kinds
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('nav.notifications', 24, $t('notifications.title')) ?></h1>
  <p class="muted"><?= $e($t('notifications.intro')) ?></p>
</div>

<div class="tabs" data-tabs>
  <div class="tabs__list" role="tablist" aria-label="<?= $e($t('notifications.tabs_label')) ?>">
    <button type="button" role="tab" id="tab-list" aria-controls="panel-list" aria-selected="true"><?= $e($t('notifications.tab_list')) ?></button>
    <button type="button" role="tab" id="tab-settings" aria-controls="panel-settings" aria-selected="false" tabindex="-1"><?= $e($t('notifications.tab_settings')) ?></button>
  </div>

  <div class="tabs__panel" role="tabpanel" id="panel-list" aria-labelledby="tab-list">
    <section class="card notif-page" data-notif-list data-page-size="<?= (int) $pageSize ?>">
      <form class="notif-filter" data-notif-filter>
        <div class="field">
          <label class="field__label" for="notif-kind"><?= $e($t('notifications.filter_kind')) ?></label>
          <select class="field__input" id="notif-kind" name="kind" data-kind>
            <option value=""><?= $e($t('notifications.kind_any')) ?></option>
            <?php foreach ($kinds as $k): ?>
            <option value="<?= $e($k) ?>"><?= $e($t('notifications.kind.' . $k)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label class="checkbox notif-filter__unread"><input type="checkbox" name="unread" value="1" data-unread> <?= $e($t('notifications.unread_only')) ?></label>
        <button type="submit" class="btn btn--primary" data-search><?= $icons->withLabel('action.search', 16, $t('notifications.search')) ?></button>
        <p class="notif-filter__dirty muted" data-dirty role="status" hidden></p>
      </form>
      <div class="notif-actions">
        <p class="defects__count" data-count aria-live="polite"></p>
        <div class="notif-actions__buttons">
          <button type="button" class="btn" data-read-all aria-describedby="notif-read-all-hint"><?= $e($t('notifications.read_all')) ?></button>
          <button type="button" class="btn btn--danger" data-delete-read><?= $icons->withLabel('action.delete', 16, $t('notifications.delete_read')) ?></button>
        </div>
      </div>
      <p class="muted notif-actions__hint" id="notif-read-all-hint"><?= $e($t('notifications.read_all_hint')) ?></p>
      <p class="muted" data-loading hidden><?= $e($t('notifications.loading')) ?></p>
      <p class="notice notice--error" data-error role="alert" hidden></p>
      <div class="notif-empty" data-empty hidden>
        <?= $view->partial('partials/empty_state', [
            'icon' => 'ui.empty',
            'title' => $t('notifications.empty_title'),
            'body' => $t('notifications.empty_body'),
            'actionLabel' => null,
            'actionHref' => null,
        ]) ?>
      </div>
      <p class="muted" data-empty-filtered hidden></p>
      <ul class="notif-list" data-items aria-label="<?= $e($t('notifications.list_label')) ?>"></ul>
      <nav class="pagination" data-pagination aria-label="<?= $e($t('notifications.pagination')) ?>" hidden></nav>
    </section>
  </div>

  <div class="tabs__panel" role="tabpanel" id="panel-settings" aria-labelledby="tab-settings" hidden>
    <section class="card notif-settings" data-notif-settings>
      <p><?= $e($t('notifications.settings_intro')) ?></p>
      <ul class="notif-settings__list">
        <?php foreach ($kinds as $k): ?>
        <li class="notif-settings__item">
          <button type="button" class="switch" role="switch" aria-checked="true" id="notif-pref-<?= $e($k) ?>" data-pref="<?= $e($k) ?>"
                  aria-labelledby="notif-pref-<?= $e($k) ?>-label" aria-describedby="notif-pref-<?= $e($k) ?>-desc" disabled></button>
          <div>
            <span class="notif-settings__label" id="notif-pref-<?= $e($k) ?>-label"><?= $e($t('notifications.kind.' . $k)) ?></span>
            <span class="muted notif-settings__desc" id="notif-pref-<?= $e($k) ?>-desc"><?= $e($t('notifications.kind_desc.' . $k)) ?></span>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <div class="notif-settings__bar">
        <button type="button" class="btn" data-pref-reset disabled><?= $e($t('notifications.settings_reset')) ?></button>
        <p class="muted" data-pref-status role="status" aria-live="polite"></p>
      </div>
      <p class="muted"><?= $e($t('notifications.retention_note')) ?></p>
    </section>
  </div>
</div>
