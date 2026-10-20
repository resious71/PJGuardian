<?php
/**
 * 관리자 "주기 분석"(P26, admin 전용, 명세 18.5 — 관리자 시스템 모니터링 화면에 주기 분석의 다음 실행 시각과 최근 실패(다음 주기 재수행 예정 포함)).
 * 시스템 모니터링 화면(19장)이 아직 없어 시스템 관리의 탭으로 둔다. 데이터는 ScheduleStatus(화면과 분리)가 만들고
 * pages/admin_schedules.js가 GET /api/v1/admin/schedules로 채운다(textContent).
 *   - 스케줄러 상태(마지막 확인 시각·지연 경고)
 *   - 일정 표: 프로젝트, 주기, 실행 시각, 다음 실행, 마지막 결과, 연속 실패, 마지막 오류, 건너뜀
 *   - 최근 실패(재시도 대기 포함, 다음 주기 재수행 예정)와 최근 건너뜀
 *
 * @var string $sub
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_schedules.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<div class="admin-schedules" data-admin-schedules>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <section class="card" aria-labelledby="as-scheduler-title">
    <h2 id="as-scheduler-title"><?= $icons->withLabel('source.schedule', 20, $t('admin_schedules.scheduler_title')) ?></h2>
    <p data-scheduler-state>—</p>
    <p class="notice notice--warn" data-scheduler-delayed role="note" hidden><?= $icons->icon('system.warn', 16) ?> <span data-scheduler-delayed-text></span></p>
    <p class="muted"><?= $e($t('admin_schedules.spread_note')) ?></p>
  </section>

  <section class="card" aria-labelledby="as-list-title">
    <h2 id="as-list-title"><?= $e($t('admin_schedules.list_title')) ?></h2>
    <p class="muted" data-empty hidden><?= $e($t('admin_schedules.empty')) ?></p>
    <div class="table-scroll">
      <table class="table admin-schedules__table" data-table hidden>
        <caption class="sr-only"><?= $e($t('admin_schedules.list_title')) ?></caption>
        <thead><tr>
          <th scope="col"><?= $e($t('admin_schedules.col.project')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.interval')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.next_run')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.last_result')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.failures')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.last_error')) ?></th>
          <th scope="col"><?= $e($t('admin_schedules.col.skips')) ?></th>
        </tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>
  </section>

  <section class="card" aria-labelledby="as-fail-title">
    <h2 id="as-fail-title"><?= $e($t('admin_schedules.failures_title')) ?></h2>
    <p class="muted" data-failures-intro></p>
    <p class="muted" data-failures-empty hidden><?= $e($t('admin_schedules.failures_empty')) ?></p>
    <ul class="plain-list admin-schedules__events" data-failures></ul>
  </section>

  <section class="card" aria-labelledby="as-skip-title">
    <h2 id="as-skip-title"><?= $e($t('admin_schedules.skips_title')) ?></h2>
    <p class="muted" data-skips-empty hidden><?= $e($t('admin_schedules.skips_empty')) ?></p>
    <ul class="plain-list admin-schedules__events" data-skips></ul>
  </section>
</div>
