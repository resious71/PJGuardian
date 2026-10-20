<?php
/**
 * 분석 상세(명세 5.2, 5.3, 8, 15.2, P19). 업로드가 queued가 되면 이 화면으로 온다.
 * 상태·결과·실패·안내는 pages/analysis_detail.js가 API(GET /api/v1/analyses/{id})로 읽어 textContent로 그리고,
 * queued·running이면 주기적으로 다시 읽는다(화면이 숨겨지면 멈춤). 실패한 분석은 다시 시도하지 않는다(다시 업로드 안내).
 *
 * @var array<string, mixed> $analysis
 * @var array<string, mixed> $project
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$pid = (int) $project['id'];
?>
<nav class="breadcrumb" aria-label="<?= $e($t('defects.breadcrumb')) ?>">
  <a href="<?= $e($assets->page('projects')) ?>"><?= $e($t('projects.title')) ?></a> ›
  <a href="<?= $e($assets->page('projects/' . $pid) . '#analyses') ?>"><?= $e($project['name']) ?></a> ›
  <span><?= $e($t('analyses.title_of', ['id' => $analysis['id']])) ?></span>
</nav>
<section data-analysis data-analysis-id="<?= (int) $analysis['id'] ?>" data-project-id="<?= $pid ?>"
  data-app-base="<?= $e($assets->basePath()) ?>" data-upload-href="<?= $e($assets->page('upload?project=' . $pid)) ?>">
  <div class="page-header page-header--actions">
    <h1 class="analysis-title"><span data-analysis-title><?= $e(isset($analysis['run']) ? $t('runs.title_of', ['run' => $analysis['run'], 'id' => $analysis['id']]) : $t('analyses.title_of', ['id' => $analysis['id']])) ?></span> <span data-status-badge></span></h1>
    <div class="toolbar">
      <button type="button" class="btn btn--danger-outline" data-cancel hidden><?= $icons->withLabel('action.cancel', 16, $t('analyses.cancel')) ?></button>
    </div>
  </div>
  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error hidden></p>
  <div data-notices></div>
  <section class="card analysis-failure" data-failure hidden aria-labelledby="af-title">
    <h2 id="af-title" data-failure-title></h2>
    <dl class="detail-list">
      <dt><?= $e($t('analyses.failure_cause')) ?></dt><dd data-failure-cause></dd>
      <dt><?= $e($t('analyses.failure_action')) ?></dt><dd data-failure-action></dd>
      <dt><?= $e($t('analyses.failure_code')) ?></dt><dd><a data-failure-code-link><code data-failure-code></code></a></dd>
    </dl>
    <p data-failure-guide hidden><a data-failure-guide-link><?= $icons->withLabel('nav.guide', 16, $t('guide.detail_link')) ?></a></p>
    <p><a class="btn btn--primary" href="<?= $e($assets->page('upload?project=' . $pid)) ?>"><?= $icons->withLabel('action.upload', 16, $t('analyses.reupload')) ?></a></p>
  </section>
  <section class="card" data-result hidden aria-labelledby="ar-title">
    <h2 id="ar-title"><?= $e($t('analyses.result_title')) ?></h2>
    <p data-baseline></p>
    <p><a href="#" data-run-defects hidden></a></p>
    <ul class="result-counts" data-counts></ul>
    <h3 class="subhead"><?= $e($t('analyses.severity_counts')) ?></h3>
    <ul class="count-list" data-severity></ul>
  </section>
  <section class="card" aria-labelledby="ai-title">
    <h2 id="ai-title"><?= $e($t('analyses.info_title')) ?></h2>
    <dl class="detail-list" data-info></dl>
  </section>
</section>
