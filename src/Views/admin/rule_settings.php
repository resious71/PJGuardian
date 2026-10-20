<?php
/**
 * 관리자 "규칙 설정"(P22B, admin 전용): 관리자가 켜거나 끈 규칙 목록과 되돌리기. 규칙 하나의 켜기·끄기는 규칙 설명 상세에서 한다.
 * 표·요약은 pages/admin_rule_settings.js가 API(/api/v1/admin/rules/overrides)로 읽어 textContent로 그린다.
 * 도구별 "모두 기본값으로 되돌리기"는 확인 창에서 도구 이름을 직접 입력해야 한다(한 번에 최대 $resetMax건).
 *
 * @var string $sub
 * @var int $resetMax
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_rule_settings.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<section class="card admin-rule-settings" data-admin-rule-settings data-reset-max="<?= (int) $resetMax ?>">
  <div class="admin-rule-settings__summary" data-summary aria-live="polite"></div>
  <div class="admin-rule-settings__bar">
    <div class="field">
      <label class="field__label" for="ars-tool"><?= $e($t('admin_rule_settings.tool_label')) ?></label>
      <select class="field__input" id="ars-tool" data-tool>
        <option value="ruff"><?= $e($t('rules.tool.ruff')) ?></option>
        <option value="eslint"><?= $e($t('rules.tool.eslint')) ?></option>
      </select>
    </div>
    <div class="field">
      <label class="field__label" for="ars-state"><?= $e($t('admin_rule_settings.state_label')) ?></label>
      <select class="field__input" id="ars-state" data-state>
        <option value=""><?= $e($t('rules.any')) ?></option>
        <?php foreach (['active', 'missing', 'not_enableable'] as $s): ?>
        <option value="<?= $e($s) ?>"><?= $e($t('admin_rule_settings.state.' . $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="button" class="btn btn--danger" data-reset-all disabled><?= $e($t('admin_rule_settings.reset_all')) ?></button>
  </div>
  <p class="defects__count" data-count aria-live="polite"></p>
  <p class="muted" data-loading hidden><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <?php /* P22C 빈 상태: 변경이 하나도 없으면 안내 + 규칙 설명으로 이동, 필터 때문에 비면 "조건에 맞는 변경이 없습니다" */ ?>
  <div class="admin-rule-settings__empty" data-empty hidden>
    <p data-empty-text><?= $e($t('admin_rule_settings.empty_none')) ?></p>
    <p data-empty-go><a href="<?= $e($assets->page('rules')) ?>" data-go-rules><?= $e($t('admin_rule_settings.go_rules')) ?></a></p>
  </div>
  <div class="table-scroll">
    <table class="table admin-rule-settings__table" data-table hidden>
      <caption class="sr-only"><?= $e($t('admin_rule_settings.caption')) ?></caption>
      <thead><tr>
        <th scope="col" class="arc-rule"><?= $e($t('admin_rule_settings.col_rule')) ?></th>
        <th scope="col" class="arc-name"><?= $e($t('admin_rule_settings.col_name')) ?></th>
        <th scope="col" class="arc-change"><?= $e($t('admin_rule_settings.col_change')) ?></th>
        <th scope="col" class="arc-base"><?= $e($t('admin_rule_settings.col_base')) ?></th>
        <th scope="col" class="arc-reason"><?= $e($t('admin_rule_settings.col_reason')) ?></th>
        <th scope="col" class="arc-by"><?= $e($t('admin_rule_settings.col_by')) ?></th>
        <th scope="col" class="arc-at"><?= $e($t('admin_rule_settings.col_at')) ?></th>
        <th scope="col" class="arc-state"><?= $e($t('admin_rule_settings.col_state')) ?></th>
        <th scope="col" class="arc-action"><?= $e($t('admin_rule_settings.col_action')) ?></th>
      </tr></thead>
      <tbody data-rows></tbody>
    </table>
  </div>
  <nav class="pagination" data-pagination aria-label="<?= $e($t('admin_rule_settings.caption')) ?>" hidden></nav>
</section>
