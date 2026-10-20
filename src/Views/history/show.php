<?php
/**
 * 프로젝트 히스토리(P21, 명세 8): 추이 차트(신규·해결·재오픈, 열린 결함), 분석 이력 표, 직전 성공 분석 대비 비교 패널.
 * 진행·취소·실패 처리는 프로젝트의 "분석" 탭(P19)이 맡고, 여기는 시간 흐름과 변화 해석만 한다(같은 표를 두 번 만들지 않는다:
 * 이 화면은 분석 탭과 분석 상세로 가는 링크를 둔다). 본문은 pages/history.js가 API로 읽어 textContent로 그린다.
 * 주소: ?analysis=<분석 id>(비교 패널), page, n(차트 건수 10/20/50).
 *
 * @var array<string, mixed> $project
 * @var int $pageSize
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$pid = (int) $project['id'];
?>
<nav class="breadcrumb" aria-label="<?= $e($t('defects.breadcrumb')) ?>">
  <a href="<?= $e($assets->page('history')) ?>"><?= $e($t('history.title')) ?></a> ›
  <a href="<?= $e($assets->page('projects/' . $pid)) ?>"><?= $e($project['name']) ?></a>
</nav>
<section class="history" data-history-page data-project-id="<?= $pid ?>" data-page-size="<?= (int) $pageSize ?>"
  data-project-base="<?= $e($assets->page('projects/' . $pid)) ?>" data-analysis-base="<?= $e($assets->page('analyses/')) ?>" data-upload-href="<?= $e($assets->page('upload?project=' . $pid)) ?>">
  <div class="page-header page-header--actions">
    <h1><?= $icons->withLabel('nav.history', 24, $t('history.title')) ?> · <?= $e($project['name']) ?></h1>
    <div class="toolbar">
      <a class="btn" href="<?= $e($assets->page('projects/' . $pid) . '#analyses') ?>"><?= $e($t('history.to_analyses_tab')) ?></a>
    </div>
  </div>
  <p class="muted"><?= $e($t('history.intro')) ?></p>
  <p class="muted" data-history-status aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <div data-history-empty hidden></div>

  <section class="card dash-section" data-section="history-chart" aria-labelledby="hc-title" data-history-chart hidden>
    <div class="card__head">
      <h2 id="hc-title"><?= $e($t('history.chart_title')) ?></h2>
      <div class="toolbar">
        <label for="hc-n"><?= $e($t('history.chart_count')) ?></label>
        <select class="field__input" id="hc-n" data-history-n>
          <?php foreach ([10, 20, 50] as $n): ?><option value="<?= $n ?>"><?= $n ?></option><?php endforeach; ?>
        </select>
        <button type="button" class="btn btn--small" data-toggle-table aria-pressed="false"><?= $e($t('dashboard.show_table')) ?></button>
      </div>
    </div>
    <div data-history-figure></div>
  </section>

  <section class="card" aria-labelledby="hp-title" data-history-panel hidden>
    <div class="card__head">
      <h2 id="hp-title" data-panel-title></h2>
      <div class="toolbar">
        <button type="button" class="btn btn--small" data-panel-prev><?= $icons->withLabel('ui.prev', 16, $t('history.prev')) ?></button>
        <button type="button" class="btn btn--small" data-panel-next><?= $e($t('history.next')) ?> <?= $icons->icon('ui.next', 16) ?></button>
      </div>
    </div>
    <p data-panel-baseline></p>
    <div data-panel-notices></div>
    <div data-panel-body></div>
  </section>

  <section class="card" aria-labelledby="ht-title" data-history-list hidden>
    <div class="history-list__head">
      <h2 id="ht-title"><?= $e($t('history.list_title')) ?></h2>
      <?php /* P27: 성공(변경 없음) 행 숨기기(기본 보이기, 주소 unchanged=hide) */ ?>
      <label class="checkbox"><input type="checkbox" data-history-hide-unchanged> <?= $e($t('history.hide_unchanged')) ?></label>
    </div>
    <div class="table-scroll">
      <table class="table history-table">
        <caption class="sr-only"><?= $e($t('history.list_title')) ?></caption>
        <thead><tr>
          <th scope="col"><?= $e($t('history.col_run')) ?></th>
          <th scope="col"><?= $e($t('history.col_date')) ?></th>
          <th scope="col"><?= $e($t('history.col_status')) ?></th>
          <th scope="col"><?= $e($t('history.col_open')) ?></th>
          <th scope="col"><?= $e($t('history.col_new')) ?></th>
          <th scope="col"><?= $e($t('history.col_resolved')) ?></th>
          <th scope="col"><?= $e($t('history.col_reopened')) ?></th>
          <th scope="col"><?= $e($t('history.col_tool')) ?></th>
          <th scope="col"><?= $e($t('history.col_source')) ?></th>
          <th scope="col"><span class="sr-only"><?= $e($t('history.col_actions')) ?></span></th>
        </tr></thead>
        <tbody data-history-rows></tbody>
      </table>
    </div>
    <nav class="pagination" data-history-pagination aria-label="<?= $e($t('history.pagination')) ?>" hidden></nav>
  </section>
</section>
