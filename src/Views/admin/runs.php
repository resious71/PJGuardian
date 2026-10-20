<?php
/**
 * 관리자 "Run 관리"(P21B, admin 전용): 프로젝트별 Run 상세 보관 현황과 수동 상세 삭제.
 * 표·요약·선택은 pages/admin_runs.js가 API(/api/v1/admin/projects/{id}/runs)로 읽어 textContent로 그린다.
 * 삭제는 확인 대화상자에서 프로젝트 이름을 직접 입력해야 한다(되돌릴 수 없음). 최신 Run은 고를 수 없다.
 *
 * @var string $sub
 * @var list<array<string, mixed>> $projects
 * @var int $keep runs.detail_keep
 * @var int $max 한 번에 지울 수 있는 최대 Run 수
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_runs.intro', ['keep' => $keep])) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<section class="card admin-runs" data-admin-runs data-keep="<?= (int) $keep ?>" data-max="<?= (int) $max ?>">
  <div class="admin-runs__bar">
    <div class="field">
      <label class="field__label" for="ar-project"><?= $e($t('admin_runs.project_label')) ?></label>
      <select class="field__input" id="ar-project" data-project>
        <option value=""><?= $e($t('admin_runs.project_choose')) ?></option>
        <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>" data-name="<?= $e((string) $p['name']) ?>"><?= $e((string) $p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <p class="admin-runs__summary" data-summary aria-live="polite"></p>
  <div class="admin-runs__tools" data-tools hidden>
    <div class="field">
      <label class="field__label" for="ar-keep-n"><?= $e($t('admin_runs.select_old_label')) ?></label>
      <input class="field__input" id="ar-keep-n" type="number" min="1" max="10000" value="<?= (int) max(1, $keep) ?>" inputmode="numeric">
    </div>
    <button type="button" class="btn" data-select-old><?= $e($t('admin_runs.select_old')) ?></button>
    <button type="button" class="btn" data-select-none><?= $e($t('admin_runs.select_none')) ?></button>
    <button type="button" class="btn btn--danger" data-delete disabled><?= $icons->withLabel('action.delete', 16, $t('admin_runs.delete')) ?></button>
  </div>
  <p class="muted" data-loading hidden><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <div class="table-scroll">
    <table class="table admin-runs__table" data-table hidden>
      <caption class="sr-only"><?= $e($t('admin_runs.caption')) ?></caption>
      <thead><tr>
        <th scope="col"><span class="sr-only"><?= $e($t('admin_runs.col_select')) ?></span></th>
        <th scope="col"><?= $e($t('admin_runs.col_run')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_date')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_open')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_new')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_resolved')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_reopened')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_tool')) ?></th>
        <th scope="col"><?= $e($t('admin_runs.col_state')) ?></th>
      </tr></thead>
      <tbody data-rows></tbody>
    </table>
  </div>
  <nav class="pagination" data-pagination aria-label="<?= $e($t('admin_runs.pagination')) ?>" hidden></nav>
  <p class="notice notice--info admin-runs__result" data-result role="status" hidden></p>
  <p class="muted"><?= $e($t('admin_runs.vacuum_note')) ?></p>
</section>
