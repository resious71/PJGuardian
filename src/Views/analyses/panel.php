<?php
/**
 * 프로젝트 상세의 "분석" 탭(명세 5.2, 8, P19): 분석 목록(최신순, 상태·대기 순번·요청자·시각·소요·도구·결함 건수, 취소).
 * 표는 pages/analyses.js가 API로 읽어 textContent로 그리고, 진행 중인 분석이 있을 때만 주기적으로 다시 읽는다(화면이 숨겨지면 멈춤).
 * P26: 실행 주체 조건(전체·사용자·주기 — 바꾸면 바로 적용), 주기 분석은 요청자 칸에 "주기 실행"(아이콘 + 글자), 재시도 대기 표시.
 *
 * @var array<string, mixed> $project
 * @var int $pageSize
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$id = (int) $project['id'];
?>
<section class="card" data-analyses data-project-id="<?= $id ?>" data-page-size="<?= (int) $pageSize ?>" data-detail-base="<?= $e($assets->page('analyses/')) ?>">
  <div class="analyses-filter">
    <p class="defects__count" data-count aria-live="polite"><?= $e($t('defects.loading')) ?></p>
    <label class="analyses-filter__trigger"><span><?= $e($t('analyses.filter_trigger')) ?></span>
      <select class="field__input" data-trigger>
        <option value=""><?= $e($t('analyses.trigger.all')) ?></option>
        <option value="user"><?= $e($t('analyses.trigger.user')) ?></option>
        <option value="schedule"><?= $e($t('analyses.trigger.schedule')) ?></option>
      </select>
    </label>
  </div>
  <p class="muted" data-empty-filtered hidden><?= $e($t('analyses.empty_filtered')) ?></p>
  <div data-empty hidden>
    <?= $view->partial('partials/empty_state', [
        'icon' => 'ui.empty',
        'title' => $t('analyses.empty_title'),
        'body' => $t('analyses.empty_body'),
        'actionLabel' => $t('upload.button'),
        'actionHref' => $assets->page('upload?project=' . $id),
    ]) ?>
  </div>
  <p class="notice notice--error" data-error hidden></p>
  <table class="table analyses-table" data-table hidden>
    <caption class="sr-only"><?= $e($t('analyses.table_caption')) ?></caption>
    <thead><tr>
      <th scope="col"><?= $e($t('analyses.col_status')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_id')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_source')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_requester')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_started')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_duration')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_tool')) ?></th>
      <th scope="col"><?= $e($t('analyses.col_defects')) ?></th>
      <th scope="col"><span class="sr-only"><?= $e($t('analyses.col_actions')) ?></span></th>
    </tr></thead>
    <tbody data-rows></tbody>
  </table>
  <nav class="pagination" data-pagination aria-label="<?= $e($t('analyses.pagination')) ?>" hidden></nav>
</section>
