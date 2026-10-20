<?php
/**
 * 내게 지정된 결함(명세 17, P18). 내가 담당이고 지금 볼 수 있는 프로젝트의 결함을 상태별로 모아 본다(읽기 위주).
 * 상태별 건수(칩)와 프로젝트 선택은 서버가 그리고, 목록은 pages/my_defects.js가 API(/api/v1/my-defects)로 읽어 P17 목록과 같은 행으로 그린다.
 * 필터 상태는 URL 쿼리(status, project, page).
 *
 * @var array{status: array<string, int>, projects: list<array{id:int, name:string, count:int}>, total:int} $summary
 * @var int $pageSize
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('nav.my_defects', 24, $t('defects.mine_title')) ?></h1>
  <p class="muted"><?= $e($t('defects.mine_intro')) ?></p>
</div>
<?php if ($summary['total'] === 0): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.my_defects',
      'title' => $t('defects.mine_empty_title'),
      'body' => $t('defects.mine_empty_body'),
      'actionLabel' => $t('defects.picker_title'),
      'actionHref' => $assets->page('defects'),
  ]) ?>
</section>
<?php else: ?>
<section class="defects" data-my-defects data-page-size="<?= (int) $pageSize ?>" data-detail-base="<?= $e($assets->page('defects/')) ?>">
  <div class="card defect-filters">
    <fieldset class="chip-group" data-facet="status">
      <legend class="field__label"><?= $e($t('defects.facet_status')) ?></legend>
      <div class="chip-group__items">
        <?php /* P21B 6절: 칩은 라벨만(건수 없음, 결함 검색 칩과 같게) */ foreach (array_keys($summary['status']) as $status): $name = 'defect_status.' . $status; ?>
        <button type="button" class="chip badge badge--<?= $e(str_replace(['.', '_'], '-', $name)) ?>" aria-pressed="false" data-value="<?= $e($status) ?>"><?= $icons->withLabel($name, 16) ?></button>
        <?php endforeach; ?>
      </div>
    </fieldset>
    <div class="defect-filters__row">
      <div class="field">
        <label class="field__label" for="md-project"><?= $e($t('defects.mine_project')) ?></label>
        <select class="field__input" id="md-project" name="project">
          <option value=""><?= $e($t('defects.mine_all_projects')) ?></option>
          <?php foreach ($summary['projects'] as $p): ?>
          <option value="<?= (int) $p['id'] ?>"><?= $e($p['name']) ?> (<?= (int) $p['count'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>
  <section class="card">
    <?php /* P23: 건수(왼쪽) · 내보내기 버튼(오른쪽 맞춤) */ ?>
    <div class="table-head">
      <p class="defects__count" data-count aria-live="polite"><?= $e($t('defects.loading')) ?></p>
      <?= $view->partial('partials/export_bar') ?>
    </div>
    <p class="muted" data-empty-filtered hidden><?= $e($t('defects.filtered_empty_title')) ?></p>
    <div class="table-scroll">
    <table class="table defects-table" data-table hidden>
      <caption class="sr-only"><?= $e($t('defects.mine_title')) ?></caption>
      <thead><tr>
        <th scope="col" class="col-project"><span class="th-label" data-clip="text"><?= $e($t('defects.col_project')) ?></span></th>
        <th scope="col" class="col-severity"><span class="th-label" data-clip="text"><?= $e($t('defects.col_severity')) ?></span></th>
        <th scope="col" class="col-status"><span class="th-label" data-clip="text"><?= $e($t('defects.col_status')) ?></span></th>
        <th scope="col" class="col-rule"><span class="th-label" data-clip="text"><?= $e($t('defects.col_rule')) ?></span></th>
        <th scope="col" class="col-location"><span class="th-label" data-clip="text"><?= $e($t('defects.col_location')) ?></span></th>
        <th scope="col" class="col-message"><span class="th-label" data-clip="text"><?= $e($t('defects.col_message')) ?></span></th>
        <th scope="col" class="col-assignee"><span class="th-label" data-clip="text"><?= $e($t('defects.col_assignee')) ?></span></th>
        <th scope="col" class="col-run"><span class="th-label" data-clip="text"><?= $e($t('defects.col_run')) ?></span></th>
        <th scope="col" class="col-seen"><span class="th-label" data-clip="text"><?= $e($t('defects.col_last_seen')) ?></span></th>
      </tr></thead>
      <tbody data-rows></tbody>
    </table>
    </div>
    <nav class="pagination" data-pagination aria-label="<?= $e($t('defects.pagination')) ?>" hidden></nav>
  </section>
</section>
<?php endif; ?>
