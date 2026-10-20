<?php
/**
 * 감사 로그 조회(P30, admin만, 읽기 전용). 조건(기간·행위자·작업·대상·검색)은 주소 질의 문자열과 같다(링크로 공유).
 * 목록·상세·무결성 검사·CSV 내보내기는 pages/admin_audit_logs.js가 API로 그린다(textContent만). 수정·삭제 단추는 없다.
 * X17: 작업 선택지는 분류 이름(audit.group.*)으로 묶고 사람이 읽는 이름(audit.act.*)과 작업 이름을 함께 보인다. 목록 "설명" 열 = AuditDescriber 한 문장.
 *
 * @var array<string, list<string>> $groups 작업 묶음 → 작업 이름(Support\AuditActions)
 * @var int $maxRows
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.audit', 24, $t('audit.title')) ?></h1>
  <p class="muted"><?= $e($t('audit.intro')) ?></p>
</div>

<section class="audit" data-audit data-max-rows="<?= (int) $maxRows ?>">
  <form class="card audit__filters" data-audit-filters role="search" aria-label="<?= $e($t('audit.filters_label')) ?>">
    <div class="audit__fields">
      <div class="field">
        <label class="field__label" for="audit-period"><?= $e($t('audit.filter.period')) ?></label>
        <select class="field__input" id="audit-period" name="days" data-period>
          <option value=""><?= $e($t('audit.period.all')) ?></option>
          <?php foreach ([1, 7, 30, 90] as $d): ?>
          <option value="<?= $d ?>"><?= $e($t('audit.period.days', ['n' => $d])) ?></option>
          <?php endforeach; ?>
          <option value="custom"><?= $e($t('audit.period.custom')) ?></option>
        </select>
      </div>
      <div class="field audit__custom" data-custom hidden>
        <label class="field__label" for="audit-from"><?= $e($t('audit.filter.from')) ?></label>
        <input class="field__input" type="date" id="audit-from" name="from" data-from>
      </div>
      <div class="field audit__custom" data-custom hidden>
        <label class="field__label" for="audit-to"><?= $e($t('audit.filter.to')) ?></label>
        <input class="field__input" type="date" id="audit-to" name="to" data-to>
      </div>
      <div class="field">
        <label class="field__label" for="audit-actor"><?= $e($t('audit.filter.actor')) ?></label>
        <input class="field__input" type="text" id="audit-actor" name="actor" maxlength="100" autocomplete="off" data-actor>
      </div>
      <div class="field">
        <label class="field__label" for="audit-action"><?= $e($t('audit.filter.action')) ?></label>
        <select class="field__input" id="audit-action" name="action" data-action>
          <option value=""><?= $e($t('audit.filter.action_all')) ?></option>
          <?php foreach ($groups as $prefix => $names): ?>
          <?php $group = $t("audit.group.{$prefix}"); ?>
          <optgroup label="<?= $e($group) ?>">
            <option value="<?= $e($prefix . '.') ?>"><?= $e($t('audit.filter.action_prefix', ['group' => $group, 'prefix' => $prefix])) ?></option>
            <?php foreach ($names as $name): ?>
            <option value="<?= $e($name) ?>"><?= $e($t('audit.filter.action_named', ['group' => $group, 'name' => $t("audit.act.{$name}"), 'action' => $name])) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="audit-target"><?= $e($t('audit.filter.target')) ?></label>
        <input class="field__input" type="text" id="audit-target" name="target" maxlength="100" autocomplete="off" data-target>
      </div>
      <div class="field">
        <label class="field__label" for="audit-q"><?= $e($t('audit.filter.q')) ?></label>
        <input class="field__input" type="search" id="audit-q" name="q" maxlength="100" autocomplete="off" data-q>
      </div>
    </div>
    <p class="muted audit__time-note"><?= $e($t('audit.time_note')) ?></p>
    <div class="audit__actions">
      <button type="submit" class="btn btn--primary"><?= $icons->withLabel('action.search', 16, $t('audit.apply')) ?></button>
      <button type="button" class="btn" data-reset><?= $e($t('audit.reset')) ?></button>
      <span class="audit__export" data-export-box><button type="button" class="btn" data-export-format="csv"><?= $icons->withLabel('action.export', 16, $t('audit.export')) ?></button><span class="muted" data-export-note hidden></span></span>
      <button type="button" class="btn" data-integrity><?= $icons->withLabel('action.verify', 16, $t('audit.integrity')) ?></button>
    </div>
  </form>

  <div class="card audit__integrity" data-integrity-box hidden>
    <h2 class="audit__integrity-title"><?= $e($t('audit.integrity_title')) ?></h2>
    <p data-integrity-status aria-live="polite"></p>
    <progress max="100" value="0" data-integrity-progress aria-label="<?= $e($t('audit.integrity_title')) ?>"></progress>
    <p class="muted" data-integrity-range></p>
  </div>

  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <p class="audit__count" data-audit-count aria-live="polite"></p>
  <div class="audit__table-wrap">
    <table class="table audit__table" data-table hidden>
      <caption class="sr-only"><?= $e($t('audit.title')) ?></caption>
      <thead><tr>
        <th scope="col"><?= $e($t('audit.col_rule')) ?></th>
        <th scope="col" class="audit__summary-col"><?= $e($t('audit.col_summary')) ?></th>
        <th scope="col"><?= $e($t('audit.col_actor')) ?></th>
        <th scope="col"><?= $e($t('audit.col_target')) ?></th>
        <th scope="col"><?= $e($t('audit.col_result')) ?></th>
        <th scope="col"><?= $e($t('audit.col_time')) ?></th>
        <th scope="col"><?= $e($t('audit.col_request')) ?></th>
        <th scope="col"><span class="sr-only"><?= $e($t('audit.col_detail')) ?></span></th>
      </tr></thead>
      <tbody data-rows></tbody>
    </table>
  </div>
  <p class="muted" data-empty hidden><?= $e($t('audit.empty')) ?></p>
  <nav class="pagination" data-pager aria-label="<?= $e($t('audit.pager_label')) ?>"></nav>
</section>
