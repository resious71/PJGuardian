<?php
/**
 * 관리자 "시스템 관리 > 백업·복원"(P27B, admin 전용, 명세 14장·19.3·19.4).
 * 값은 pages/admin_backups.js가 GET /api/v1/admin/backups로 읽어 textContent로 그린다(경로·호스트 이름도 텍스트).
 *   - 요약: 마지막 백업·다음 예정·백업 폴더(같은 볼륨 경고, 폴더 변경은 설정 관리 "백업" 그룹 링크)·마지막 리허설·디스크 여유·키 지문, 경고 목록(아이콘 + 문구)
 *   - 동작: 지금 백업(스냅샷 포함) + 진행(aria-live), 복원 리허설, 키 내보내기, 묶음 올리기
 *   - 목록 표(최신순): 시각·종류·크기·스냅샷·앱/스키마·검증 상태·동작(검증·내려받기·복원·삭제)
 * 복원은 대화상자 흐름(사전 검증 → 미리보기 → 설정 → 키 → 확인) 뒤 워커가 별도 프로세스로 하고, 화면은 /restore-status로 이동한다.
 *
 * @var string $sub
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('backups.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<div class="admin-backups" data-admin-backups data-settings-href="<?= $e($assets->page('admin/config-settings')) ?>">
  <p class="muted" data-loading role="status" aria-live="polite"><?= $e($t('backups.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <a class="notice notice--warn admin-backups__restore-link" data-restore-link href="<?= $e($assets->page('restore-status')) ?>" hidden></a>
  <ul class="plain-list admin-backups__warnings" data-warnings aria-label="<?= $e($t('backups.warnings_label')) ?>" hidden></ul>

  <section class="card" aria-labelledby="ab-summary-title" data-summary-card hidden>
    <h2 id="ab-summary-title"><?= $e($t('backups.summary_title')) ?></h2>
    <dl class="detail-list" data-summary></dl>
  </section>

  <section class="card" aria-labelledby="ab-actions-title" data-actions-card hidden>
    <h2 id="ab-actions-title"><?= $e($t('backups.actions_title')) ?></h2>
    <div class="admin-backups__actions">
      <label class="checkbox"><input type="checkbox" data-snapshots checked> <?= $e($t('backups.snapshots_option')) ?></label>
      <button type="button" class="btn btn--primary" data-backup-now><?= $icons->withLabel('action.backup_now', 16) ?></button>
      <button type="button" class="btn" data-rehearse><?= $icons->withLabel('action.rehearse', 16) ?></button>
      <button type="button" class="btn" data-key-export><?= $icons->withLabel('action.export_key', 16) ?></button>
    </div>
    <div class="admin-backups__job" data-job hidden>
      <progress max="100" data-job-progress aria-labelledby="ab-job-text"></progress>
      <span id="ab-job-text" data-job-text role="status" aria-live="polite"></span>
      <button type="button" class="btn btn--small" data-job-cancel hidden><?= $icons->withLabel('action.cancel', 16, $t('backups.job.cancel')) ?></button>
    </div>
    <p class="muted" data-job-done role="status" aria-live="polite"></p>
    <p class="muted"><?= $e($t('backups.key_note')) ?></p>

    <form class="admin-backups__upload" data-upload-form novalidate>
      <h3><?= $icons->withLabel('action.upload', 16, $t('backups.upload.title')) ?></h3>
      <p class="muted" data-upload-help></p>
      <div class="field">
        <label class="field__label" for="ab-upload-file"><?= $e($t('backups.upload.file')) ?></label>
        <input class="field__input" type="file" id="ab-upload-file" name="file" accept=".pjgbak">
      </div>
      <button type="submit" class="btn"><?= $icons->withLabel('action.upload', 16, $t('backups.upload.submit')) ?></button>
      <progress max="100" value="0" data-upload-progress hidden></progress>
      <span class="muted" data-upload-text role="status" aria-live="polite"></span>
      <p class="field__error" data-upload-error role="alert" hidden></p>
    </form>
  </section>

  <section class="card" aria-labelledby="ab-list-title" data-list-card hidden>
    <h2 id="ab-list-title"><?= $e($t('backups.list_title')) ?></h2>
    <p class="muted" data-list-intro></p>
    <p class="muted" data-empty hidden><?= $icons->withLabel('ui.empty', 16, $t('backups.empty')) ?></p>
    <div class="table-scroll">
      <table class="table admin-backups__table" data-table hidden>
        <caption class="sr-only"><?= $e($t('backups.list_title')) ?></caption>
        <thead><tr>
          <th scope="col"><?= $e($t('backups.col.created')) ?></th>
          <th scope="col"><?= $e($t('backups.col.kind')) ?></th>
          <th scope="col" class="admin-backups__num"><?= $e($t('backups.col.size')) ?></th>
          <th scope="col"><?= $e($t('backups.col.snapshots')) ?></th>
          <th scope="col"><?= $e($t('backups.col.version')) ?></th>
          <th scope="col"><?= $e($t('backups.col.verify')) ?></th>
          <th scope="col"><?= $e($t('backups.col.actions')) ?></th>
        </tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>
  </section>
</div>
