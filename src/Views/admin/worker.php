<?php
/**
 * 관리자 "워커"(P20A, 명세 5.2, 14, 19.8). 상태·큐는 읽기 전용(관리자 분석 취소 없음). 정지·재시작은 파일 신호,
 * 시작은 설정 worker.start_command의 고정 명령(사전 등록 작업 실행)만. 내용은 pages/admin_worker.js가 그린다.
 *
 * @var string $sub
 * @var bool $supervised
 * @var int $logLines
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_worker.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<section data-admin-worker data-log-lines="<?= (int) $logLines ?>">
  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <p class="notice notice--error" data-no-worker role="alert" hidden><?= $icons->icon('system.error', 16) ?> <span data-no-worker-text></span></p>

  <section class="card" aria-labelledby="wk-title">
    <div class="card__header">
      <h2 id="wk-title"><?= $e($t('admin_worker.status_title')) ?> <span data-state-badge></span></h2>
      <div class="toolbar" data-actions>
        <button type="button" class="btn" data-stop hidden><?= $icons->withLabel('action.stop', 16, $t('admin_worker.stop')) ?></button>
        <button type="button" class="btn" data-restart hidden><?= $icons->withLabel('action.restart', 16, $t('admin_worker.restart')) ?></button>
        <button type="button" class="btn btn--primary" data-start hidden><?= $icons->withLabel('action.start', 16, $t('admin_worker.start')) ?></button>
      </div>
    </div>
    <p class="notice notice--warn" data-stop-pending hidden></p>
    <p class="notice notice--info" data-start-hint hidden></p>
    <p class="notice" data-start-result hidden aria-live="polite"></p>
    <dl class="detail-list" data-status></dl>
  </section>

  <section class="card" aria-labelledby="wk-run-title">
    <h2 id="wk-run-title"><?= $e($t('admin_worker.running_title')) ?></h2>
    <p class="muted" data-queue-summary></p>
    <table class="table" data-running-table hidden>
      <caption class="sr-only"><?= $e($t('admin_worker.running_title')) ?></caption>
      <thead><tr>
        <th scope="col"><?= $e($t('analyses.col_id')) ?></th>
        <th scope="col"><?= $e($t('admin_worker.col_project')) ?></th>
        <th scope="col"><?= $e($t('analyses.col_tool')) ?></th>
        <th scope="col"><?= $e($t('analyses.col_started')) ?></th>
        <th scope="col"><?= $e($t('admin_worker.col_elapsed')) ?></th>
      </tr></thead>
      <tbody data-running></tbody>
    </table>
  </section>

  <details class="card help" data-help>
    <summary><?= $icons->withLabel('ui.command', 16, $t('admin_worker.help_title')) ?></summary>
    <p><?= $e($t('admin_worker.help_console')) ?></p>
    <pre class="command">php tools/worker.php
php tools/worker.php --status
php tools/worker.php --stop</pre>
    <p><?= $e($t('admin_worker.help_windows')) ?></p>
    <p><?= $e($t('admin_worker.help_linux')) ?></p>
    <p><?= $e($t($supervised ? 'admin_worker.help_supervised_on' : 'admin_worker.help_supervised_off')) ?></p>
    <p><?= $e($t('admin_worker.help_start_command')) ?></p>
    <p class="muted"><?= $e($t('admin_worker.help_doc')) ?></p>
  </details>

  <section class="card" aria-labelledby="wk-log-title">
    <div class="card__header">
      <h2 id="wk-log-title"><?= $e($t('admin_worker.log_title')) ?></h2>
      <div class="toolbar">
        <label class="sr-only" for="wk-log-scope"><?= $e($t('admin_worker.log_scope')) ?></label>
        <select class="field__input" id="wk-log-scope" data-log-scope>
          <option value="worker"><?= $e($t('admin_worker.log_scope_worker')) ?></option>
          <option value="all"><?= $e($t('admin_worker.log_scope_all')) ?></option>
        </select>
        <button type="button" class="btn" data-log-refresh><?= $icons->withLabel('action.fetch_now', 16, $t('admin_worker.log_refresh')) ?></button>
      </div>
    </div>
    <p class="muted" data-log-info></p>
    <pre class="log-view" data-log tabindex="0" aria-label="<?= $e($t('admin_worker.log_title')) ?>"></pre>
  </section>
</section>
