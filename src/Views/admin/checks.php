<?php
/**
 * 관리자 "점검"(P20A, 읽기 전용, P30 시스템 모니터링이 확장): 설정(비밀값은 설정 여부만), 환경, 디스크, 정리 작업, 최근 7일 실패 분석.
 * 설정 변경은 화면에서 하지 않는다(서버에서 config.ini 편집 후 워커 재시작). 내용은 pages/admin_checks.js가 그린다.
 *
 * @var string $sub
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 * @var array<string, int|bool> $capacity
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_checks.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<section data-admin-checks>
  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>

  <section class="card" id="disk" aria-labelledby="ck-disk-title">
    <div class="card__header">
      <h2 id="ck-disk-title"><?= $e($t('admin_checks.disk_title')) ?></h2>
      <button type="button" class="btn" data-disk-scan><?= $icons->withLabel('action.fetch_now', 16, $t('admin_checks.disk_scan')) ?></button>
    </div>
    <div data-disk></div>
  </section>

  <section class="card capacity" id="capacity" aria-labelledby="ck-cap-title" data-capacity
    <?php foreach ($capacity as $k => $v): ?> data-<?= $e(strtolower((string) preg_replace('/[A-Z]/', '-$0', $k))) ?>="<?= $e(is_bool($v) ? ($v ? '1' : '0') : (string) $v) ?>"<?php endforeach; ?>>
    <h2 id="ck-cap-title"><?= $e($t('capacity.title')) ?></h2>
    <p class="muted"><?= $e($t('capacity.intro')) ?></p>
    <form class="capacity__form" data-capacity-form novalidate>
      <?php foreach (['projects' => [10, 1], 'defects_per_project' => [10000, 0], 'upload_mb' => [50, 1], 'backups_kept' => [(int) $capacity['backupsKept'], 0]] as $name => [$default, $min]): ?>
      <div class="field">
        <label class="field__label" for="cap-<?= $e($name) ?>"><?= $e($t('capacity.in.' . $name)) ?></label>
        <input class="field__input" type="number" inputmode="numeric" min="<?= (int) $min ?>" step="1" id="cap-<?= $e($name) ?>" name="<?= $e($name) ?>" value="<?= (int) $default ?>">
      </div>
      <?php endforeach; ?>
    </form>
    <table class="table capacity__result">
      <caption class="sr-only"><?= $e($t('capacity.result')) ?></caption>
      <tbody>
        <?php foreach (['work', 'snapshots', 'db', 'logs', 'dataDisk', 'backupDisk'] as $row): ?>
        <tr<?= in_array($row, ['dataDisk', 'backupDisk'], true) ? ' class="capacity__total"' : '' ?>><th scope="row"><?= $e($t('capacity.row.' . $row)) ?></th><td data-capacity-out="<?= $e($row) ?>">—</td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="field__help"><?= $e($t('capacity.basis', ['concurrent' => $capacity['maxConcurrent'], 'keep' => $capacity['snapshotKeep'], 'runs' => $capacity['runsKept'], 'audit' => $capacity['auditDays'], 'warn' => $capacity['warnPercent']])) ?></p>
    <p class="field__help"><?= $e($t('capacity.assumed')) ?></p>
  </section>

  <section class="card" aria-labelledby="ck-cleanup-title">
    <div class="card__header">
      <h2 id="ck-cleanup-title"><?= $e($t('admin_checks.cleanup_title')) ?></h2>
      <button type="button" class="btn" data-cleanup-run><?= $icons->withLabel('action.delete', 16, $t('admin_checks.cleanup_run')) ?></button>
    </div>
    <dl class="detail-list" data-cleanup></dl>
    <p class="muted"><?= $e($t('admin_checks.cleanup_schedule_help')) ?></p>
  </section>

  <section class="card" aria-labelledby="ck-fail-title">
    <h2 id="ck-fail-title"><?= $e($t('admin_checks.failures_title')) ?></h2>
    <div data-failures></div>
  </section>

  <section class="card" aria-labelledby="ck-env-title">
    <h2 id="ck-env-title"><?= $e($t('admin_checks.env_title')) ?></h2>
    <table class="table">
      <caption class="sr-only"><?= $e($t('admin_checks.env_title')) ?></caption>
      <thead><tr>
        <th scope="col"><?= $e($t('admin_checks.col_item')) ?></th>
        <th scope="col"><?= $e($t('admin_checks.col_state')) ?></th>
        <th scope="col"><?= $e($t('admin_checks.col_value')) ?></th>
      </tr></thead>
      <tbody data-env></tbody>
    </table>
  </section>

  <section class="card" aria-labelledby="ck-cfg-title">
    <h2 id="ck-cfg-title"><?= $e($t('admin_checks.config_title')) ?></h2>
    <p class="muted"><?= $e($t('admin_checks.config_intro')) ?></p>
    <table class="table">
      <caption class="sr-only"><?= $e($t('admin_checks.config_title')) ?></caption>
      <thead><tr>
        <th scope="col"><?= $e($t('admin_checks.col_key')) ?></th>
        <th scope="col"><?= $e($t('admin_checks.col_value')) ?></th>
        <th scope="col"><?= $e($t('admin_checks.col_default')) ?></th>
      </tr></thead>
      <tbody data-config></tbody>
    </table>
  </section>
</section>
