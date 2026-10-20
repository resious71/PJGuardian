<?php
/**
 * 프로젝트 편집 화면의 "주기 분석"(P26, 명세 18.2, admin 전용 — 편집 화면 자체가 admin만). "소스 저장소" 아래에 붙는다.
 * 사용 여부, 간격(일, "1주일" 빠른 선택), 실행 시각(KST 24시간제 HH:MM), 같은 시각 예약 수 안내, 다음 실행 예정, 마지막 결과·연속 실패.
 * 저장소 연결이 정상 설정이 아니면(연결 없음·허용 호스트 아님·자격 증명을 읽을 수 없음) 입력을 끄고 이유를 보인다.
 * 값은 pages/project_schedule.js가 GET /api/v1/projects/{id}/schedule로 채운다(textContent).
 *
 * @var array<string, mixed> $project
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
$pid = (int) $project['id'];
?>
<section class="card schedule-form" id="project-schedule" aria-labelledby="schedule-title" data-schedule-form data-project-id="<?= $pid ?>">
  <h2 id="schedule-title"><?= $icons->withLabel('source.schedule', 20, $t('schedule.title')) ?></h2>
  <p class="muted"><?= $e($t('schedule.intro')) ?></p>
  <p class="notice notice--warn" data-schedule-blocker role="note" hidden><?= $icons->icon('system.warn', 16) ?> <span data-schedule-blocker-text></span></p>
  <p class="notice notice--error" data-schedule-unavailable hidden><?= $e($t('error.schedule_unavailable')) ?></p>
  <form data-schedule-fields novalidate>
    <label class="checkbox schedule-form__enable"><input type="checkbox" name="enabled" value="1" data-schedule-enabled> <?= $e($t('schedule.field.enabled')) ?></label>
    <div class="schedule-form__row">
      <div class="field">
        <label class="field__label" for="schedule-days"><?= $e($t('schedule.field.interval_days')) ?></label>
        <div class="schedule-form__inline">
          <input class="field__input schedule-form__days" id="schedule-days" name="interval_days" type="number" min="1" max="365" step="1" inputmode="numeric" required aria-describedby="schedule-days-help schedule-days-error">
          <span><?= $e($t('schedule.days_unit')) ?></span>
          <button type="button" class="btn btn--small" data-schedule-week><?= $e($t('schedule.week_button')) ?></button>
        </div>
        <span class="muted repo-form__help" id="schedule-days-help"><?= $e($t('schedule.help.interval_days')) ?></span>
        <span class="field__error" id="schedule-days-error" data-error-for="interval_days" hidden></span>
      </div>
      <div class="field">
        <label class="field__label" for="schedule-time"><?= $e($t('schedule.field.run_time')) ?></label>
        <input class="field__input schedule-form__time mono" id="schedule-time" name="run_time" autocomplete="off" spellcheck="false" maxlength="5" inputmode="numeric" placeholder="03:00" required aria-describedby="schedule-time-help schedule-time-error schedule-same-time">
        <span class="muted repo-form__help" id="schedule-time-help"><?= $e($t('schedule.help.run_time')) ?></span>
        <span class="field__error" id="schedule-time-error" data-error-for="run_time" hidden></span>
      </div>
    </div>
    <p class="notice notice--info schedule-form__same" id="schedule-same-time" data-schedule-same-time role="status" aria-live="polite" hidden></p>
    <dl class="detail-list schedule-form__state">
      <dt><?= $e($t('schedule.field.next_run')) ?></dt><dd data-schedule-next>—</dd>
      <dt><?= $e($t('schedule.field.last_result')) ?></dt><dd data-schedule-last>—</dd>
      <dt><?= $e($t('schedule.field.failures')) ?></dt><dd data-schedule-failures>—</dd>
    </dl>
    <p class="notice notice--error" data-schedule-error role="alert" hidden></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--primary" data-schedule-save><?= $e($t('schedule.save')) ?></button>
    </div>
    <p class="repo-form__result" data-schedule-result role="status" aria-live="polite"></p>
  </form>
</section>
