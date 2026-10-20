<?php
/**
 * 개발 전용 스타일 가이드(/dev/styleguide, app.env=dev). 모든 공통 컴포넌트와 아이콘을 한 화면에 보여 준다.
 * 동작(토스트, 확인 대화상자, 탭, 흑백 모드)은 /assets/js/pages/styleguide.js와 app.js가 붙인다.
 *
 * @var array $page
 * @var string $previewAs admin|user|guest
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var string $timezone 표시 시간대(app.display_timezone)
 */
$badge = static fn (string $name): string => $view->partial('partials/badge', ['name' => $name]);
// 시각: DB의 UTC를 서버가 표시 시간대로 먼저 그리고(스크립트 없이도 맞게), JS(core/time.js)가 다시 맞춘다.
$time = static fn (string $iso): string => '<time datetime="' . $e($iso) . '">'
    . $e((new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i')) . '</time>';
$iconButton = static fn (string $name): string =>
    '<button type="button" class="btn btn--icon" aria-label="' . $e($icons->aria($name)) . '" data-tooltip-trigger>'
    . $icons->icon($name, 20) . '<span class="tooltip" aria-hidden="true">' . $e($icons->label($name)) . '</span></button>';
?>
<div class="page-header">
  <h1><?= $e($t('styleguide.title')) ?></h1>
  <p class="muted"><?= $e($t('styleguide.intro')) ?></p>
  <div class="toolbar">
    <button type="button" class="btn" data-mono-toggle aria-pressed="false"><?= $e($t('styleguide.mono_toggle')) ?></button>
    <span class="muted"><?= $e($t('styleguide.mono_help')) ?></span>
  </div>
  <nav class="toolbar" aria-label="<?= $e($t('styleguide.preview_as')) ?>">
    <span><?= $e($t('styleguide.preview_as')) ?>:</span>
    <?php foreach (['admin', 'user', 'guest'] as $as): ?>
    <a class="btn btn--small" href="<?= $e($assets->page('dev/styleguide?as=' . $as)) ?>"<?= $previewAs === $as ? ' aria-current="page"' : '' ?>><?= $e($t('styleguide.as_' . $as)) ?></a>
    <?php endforeach; ?>
  </nav>
</div>

<section class="card" id="sg-badges">
  <h2><?= $e($t('styleguide.section_badges')) ?></h2>
  <div class="badge-row" data-badge-row="severity">
    <?php foreach ($icons->names('severity') as $n): ?><?= $badge($n) ?><?php endforeach; ?>
  </div>
  <div class="badge-row" data-badge-row="defect_status">
    <?php foreach ($icons->names('defect_status') as $n): ?><?= $badge($n) ?><?php endforeach; ?>
  </div>
  <div class="badge-row" data-badge-row="analysis_status">
    <?php foreach ($icons->names('analysis_status') as $n): ?><?= $badge($n) ?><?php endforeach; ?>
  </div>
  <div class="badge-row" data-badge-row="system">
    <?php foreach ($icons->names('system') as $n): ?><?= $badge($n) ?><?php endforeach; ?>
    <?php foreach ($icons->names('language') as $n): ?><span class="badge badge--neutral"><?= $icons->withLabel($n, 16) ?></span><?php endforeach; ?>
    <?php foreach ($icons->names('source') as $n): ?><span class="badge badge--neutral"><?= $icons->withLabel($n, 16) ?></span><?php endforeach; ?>
  </div>
</section>

<section class="card" id="sg-buttons">
  <h2><?= $e($t('styleguide.section_buttons')) ?></h2>
  <div class="toolbar">
    <button type="button" class="btn btn--primary"><?= $icons->withLabel('action.upload', 16, $t('styleguide.btn_primary')) ?></button>
    <button type="button" class="btn"><?= $icons->withLabel('action.export', 16, $t('styleguide.btn_default')) ?></button>
    <button type="button" class="btn btn--danger"><?= $icons->withLabel('action.delete', 16, $t('styleguide.btn_danger')) ?></button>
    <button type="button" class="btn" disabled><?= $icons->withLabel('action.fetch_now', 16) ?></button>
  </div>
  <div class="toolbar" id="sg-icon-buttons">
    <?php foreach (['action.filter', 'action.search', 'action.bulk_edit', 'action.copy', 'action.cancel', 'action.delete'] as $n): ?><?= $iconButton($n) ?><?php endforeach; ?>
  </div>
  <p class="muted"><?= $e($t('styleguide.icon_buttons_help')) ?></p>
</section>

<section class="card" id="sg-table">
  <h2><?= $e($t('styleguide.section_table')) ?></h2>
  <table class="table">
    <thead><tr>
      <th scope="col"><?= $e($t('styleguide.col_rule')) ?></th>
      <th scope="col"><?= $e($t('styleguide.col_file')) ?></th>
      <th scope="col"><?= $e($t('styleguide.col_severity')) ?></th>
      <th scope="col"><?= $e($t('styleguide.col_status')) ?></th>
      <th scope="col"><?= $e($t('styleguide.col_detected_at')) ?></th>
    </tr></thead>
    <tbody>
      <tr><td><code>ruff:F401</code></td><td><code>src/app/models.py:9</code></td><td><?= $badge('severity.high') ?></td><td><?= $badge('defect_status.open') ?></td><td><?= $time('2026-10-02T15:30:00Z') ?></td></tr>
      <tr><td><code>eslint:no-undef</code></td><td><code>web/index.js:12</code></td><td><?= $badge('severity.not_defined') ?></td><td><?= $badge('defect_status.in_progress') ?></td><td><?= $time('2026-10-01T23:05:00Z') ?></td></tr>
      <tr><td><code>syntax-error</code></td><td><code>src/broken.py:4</code></td><td><?= $badge('severity.critical') ?></td><td><?= $badge('defect_status.resolved') ?></td><td><?= $time('2026-09-30T08:00:00Z') ?></td></tr>
    </tbody>
  </table>
  <nav class="pagination" aria-label="<?= $e($t('component.page_of', ['page' => 2, 'pages' => 5])) ?>" data-pagination data-page="2" data-pages="5"></nav>
</section>

<section class="card" id="sg-form">
  <h2><?= $e($t('styleguide.section_form')) ?></h2>
  <div class="field field--invalid">
    <label class="field__label" for="sg-name"><?= $e($t('styleguide.field_name')) ?> <span class="field__required" aria-hidden="true">*</span></label>
    <input class="field__input" id="sg-name" name="name" value="demo" required aria-invalid="true" aria-describedby="sg-name-help sg-name-error">
    <p class="field__help" id="sg-name-help"><?= $e($t('styleguide.field_name_help')) ?></p>
    <p class="field__error" id="sg-name-error"><?= $icons->withLabel('system.error', 16, $t('styleguide.field_name_error')) ?></p>
  </div>
  <div class="field">
    <label class="field__label" for="sg-desc"><?= $e($t('styleguide.field_desc')) ?></label>
    <textarea class="field__input" id="sg-desc" rows="2"></textarea>
  </div>
</section>

<section class="card" id="sg-tabs">
  <h2><?= $e($t('styleguide.section_tabs')) ?></h2>
  <div class="tabs" data-tabs>
    <div class="tabs__list" role="tablist">
      <button type="button" role="tab" id="sg-tab-1" aria-controls="sg-panel-1" aria-selected="true"><?= $e($t('styleguide.tab_overview')) ?></button>
      <button type="button" role="tab" id="sg-tab-2" aria-controls="sg-panel-2" aria-selected="false" tabindex="-1"><?= $e($t('styleguide.tab_history')) ?></button>
      <button type="button" role="tab" id="sg-tab-3" aria-controls="sg-panel-3" aria-selected="false" tabindex="-1"><?= $e($t('styleguide.tab_code')) ?></button>
    </div>
    <div class="tabs__panel" role="tabpanel" id="sg-panel-1" aria-labelledby="sg-tab-1"><?= $e($t('styleguide.tab_overview_body')) ?></div>
    <div class="tabs__panel" role="tabpanel" id="sg-panel-2" aria-labelledby="sg-tab-2" hidden><?= $e($t('styleguide.tab_history_body')) ?></div>
    <div class="tabs__panel" role="tabpanel" id="sg-panel-3" aria-labelledby="sg-tab-3" hidden><?= $e($t('styleguide.tab_code_body')) ?></div>
  </div>
</section>

<section class="card" id="sg-progress">
  <h2><?= $e($t('styleguide.section_progress')) ?></h2>
  <label class="progress-field"><span><?= $e($t('styleguide.progress_label')) ?></span>
    <progress class="progress" max="100" value="37" data-progress-demo>37%</progress>
  </label>
  <p><?= $badge('analysis_status.running') ?></p>
</section>

<section class="card" id="sg-feedback">
  <h2><?= $e($t('styleguide.section_feedback')) ?></h2>
  <div class="toolbar">
    <button type="button" class="btn" data-demo-toast="success"><?= $e($t('styleguide.toast_success')) ?></button>
    <button type="button" class="btn" data-demo-toast="warn"><?= $e($t('styleguide.toast_warn')) ?></button>
    <button type="button" class="btn" data-demo-toast="error"><?= $e($t('styleguide.toast_error')) ?></button>
    <button type="button" class="btn btn--danger" data-demo-confirm><?= $icons->withLabel('action.delete', 16, $t('styleguide.open_confirm')) ?></button>
  </div>
  <p class="muted" id="sg-confirm-result" aria-live="polite"></p>
</section>

<section class="card" id="sg-empty">
  <h2><?= $e($t('styleguide.section_empty')) ?></h2>
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.projects',
      'title' => $t('styleguide.empty_projects_title'),
      'body' => $t('styleguide.empty_projects_body'),
      'actionLabel' => $t('styleguide.empty_projects_action'),
      'actionHref' => null,
  ]) ?>
</section>

<section class="card" id="sg-icons">
  <h2><?= $e($t('styleguide.section_icons')) ?></h2>
  <?= $view->partial('partials/icon_legend') ?>
</section>
