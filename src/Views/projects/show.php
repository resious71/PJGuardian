<?php
/**
 * 프로젝트 상세(빈 틀). 헤더(이름, 언어, 설명)와 이후 단계가 채울 탭 자리.
 * 구현된 탭이 없으면 탭 자체를 숨긴다("준비 중" 표시 없음). 결함(P17), 분석 이력(P15), 설정 탭이 $tabs를 채운다.
 *
 * @var array<string, mixed> $project
 * @var list<array{id:string, label:string, html:string}> $tabs
 * @var bool $isAdmin
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var array{n:int, warn:int}|null $defectWarn P19C: 현재 결함이 경고 기준을 넘음(경고 한 줄)
 */
$id = (int) $project['id'];
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('projects')) ?>"><?= $e($t('projects.title')) ?></a></nav>
<div class="page-header page-header--actions">
  <div>
    <h1 class="project-title"><?= $e($project['name']) ?> <?= $view->partial('partials/lang_badge', ['language' => $project['language']]) ?></h1>
    <?php if ($project['description'] !== ''): ?>
    <p class="project-description"><?= $e($project['description']) ?></p>
    <?php endif; ?>
  </div>
  <div class="toolbar">
    <a class="btn btn--primary" href="<?= $e($assets->page('upload?project=' . $id)) ?>"><?= $icons->withLabel('action.upload', 16, $t('upload.button')) ?></a>
    <a class="btn" href="<?= $e($assets->page('projects/' . $id . '/history')) ?>" data-history-link><?= $icons->withLabel('nav.history', 16, $t('history.title')) ?></a>
    <?php if ($isAdmin): ?>
    <a class="btn" href="<?= $e($assets->page('projects/' . $id . '/edit')) ?>"><?= $e($t('projects.edit_button')) ?></a>
    <a class="btn btn--danger-outline" href="<?= $e($assets->page('projects/' . $id . '/delete')) ?>"><?= $icons->withLabel('action.delete', 16, $t('projects.delete_button')) ?></a>
    <?php endif; ?>
  </div>
</div>

<?= $view->partial('projects/_repo_status', ['id' => $id, 'isAdmin' => $isAdmin]) ?>

<?php if ($isAdmin): $acc = $project['access']; ?>
<section class="card access-summary" data-access-summary aria-labelledby="acc-title">
  <h2 id="acc-title"><?= $e($t('access.title')) ?></h2>
  <?php if ($acc['groups'] + $acc['users'] === 0): ?>
  <p class="notice notice--warn access-warning"><?= $icons->icon('ui.info', 16) ?> <?= $e($t('access.none_warning')) ?></p>
  <?php else: ?>
  <p><?= $e($t('access.summary', $acc)) ?></p>
  <?php endif; ?>
  <a class="btn btn--small" href="<?= $e($assets->page('projects/' . $id . '/edit') . '#project-access') ?>"><?= $e($t('access.manage_link')) ?></a>
</section>
<?php endif; ?>

<?php if (isset($summary)): ?>
<?php if (!empty($defectWarn)): ?>
<p class="notice notice--warn" data-defect-count-warn role="note"><?= $icons->icon('system.warn', 16) ?> <?= $e($t('projects.defect_count_warn', ['n' => number_format($defectWarn['n']), 'warn' => number_format($defectWarn['warn'])])) ?></p>
<?php endif; ?>
<?= $view->partial('projects/summary', ['project' => $project, 'summary' => $summary, 'latest' => $latest ?? null, 'latestRun' => $latestRun ?? null]) ?>
<?php endif; ?>

<?php if ($tabs !== []): ?>
<div class="tabs" data-tabs>
  <div class="tabs__list" role="tablist">
    <?php foreach ($tabs as $i => $tab): ?>
    <button type="button" role="tab" id="tab-<?= $e($tab['id']) ?>" aria-controls="panel-<?= $e($tab['id']) ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= $e($tab['label']) ?></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($tabs as $i => $tab): ?>
  <div class="tabs__panel" role="tabpanel" id="panel-<?= $e($tab['id']) ?>" aria-labelledby="tab-<?= $e($tab['id']) ?>"<?= $i === 0 ? '' : ' hidden' ?>><?= $tab['html'] ?></div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'ui.empty',
      'title' => $t('projects.no_analysis_title'),
      'body' => $t('projects.no_analysis_body'),
      'actionLabel' => null,
      'actionHref' => null,
  ]) ?>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="excludes-title">
  <h2 id="excludes-title"><?= $e($t('projects.excludes_title')) ?></h2>
  <p class="muted"><?= $e($t('projects.excludes_intro')) ?></p>
  <h3 class="subhead"><?= $e($t('projects.excludes_default')) ?></h3>
  <ul class="chip-list">
    <?php foreach ($project['default_excludes'] as $name): ?><li><code><?= $e($name) ?></code></li><?php endforeach; ?>
  </ul>
  <h3 class="subhead"><?= $e($t('projects.excludes_custom')) ?></h3>
  <?php if ($project['exclude_patterns'] === []): ?>
  <p class="muted"><?= $e($t('projects.excludes_none')) ?></p>
  <?php else: ?>
  <ul class="chip-list">
    <?php foreach ($project['exclude_patterns'] as $pattern): ?><li><code><?= $e($pattern) ?></code></li><?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
