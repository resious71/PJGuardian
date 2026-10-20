<?php
/**
 * 규칙 설명 목록(P22, 명세 11장). 기준은 활성 도구 버전. 표·검색은 pages/rules.js가 API(/api/v1/rules, /api/v1/rules/meta)로 채운다(값은 textContent).
 * 검색 영역은 결함 검색(P21V)과 같은 규칙: 조건을 정한 뒤 검색 버튼(맨 아래 오른쪽)·검색어 칸 Enter로 적용, 변경 표시, 주소에는 적용 조건만.
 * 단일 선택 상자(카테고리 등)는 콤보박스(ui/combobox.js), 기본 심각도는 다중 선택 칩(건수 없음).
 * X08: 언어별 탭(Python · ruff / JavaScript/TypeScript · ESLint)이 도구 조건을 대신한다(도구 필터·도구 열 없음). 탭 = 표시 필터일 뿐 권한과 무관.
 * P22B: "기본 활성" 필터·열 → "분석 적용"(관리자 변경 반영: 적용 중·꺼짐) + "관리자 변경"(변경된 것만) 필터. 관리자 변경 행에는 배지(아이콘 + 글자).
 * P22C: "규칙 키 접두사" 조건. admin에게만(서버가 판정해 그린다) 선택 열·이 쪽 전체 선택·일괄 막대, "분석 적용" 칸의 스위치(pages/rules.js).
 *       일반 사용자 화면에는 이 요소들이 아예 없다(변경 API도 403).
 *
 * @var int $pageSize
 * @var bool $isAdmin
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
$yesNo = static function (string $id, string $name, string $labelKey) use ($e, $t): string {
    return '<div class="field"><label class="field__label" for="' . $e($id) . '">' . $e($t($labelKey)) . '</label>'
        . '<select class="field__input" id="' . $e($id) . '" name="' . $e($name) . '"><option value="">' . $e($t('rules.any')) . '</option>'
        . '<option value="1">' . $e($t('rules.yes')) . '</option><option value="0">' . $e($t('rules.no')) . '</option></select></div>';
};
?>
<div class="page-header page-header--actions">
  <div>
    <h1><?= $icons->withLabel('nav.rules', 24, $t('rules.title')) ?></h1>
    <p class="muted"><?= $e($t('rules.intro')) ?></p>
  </div>
  <?php if ($isAdmin): ?>
  <div class="toolbar">
    <button type="button" class="btn" data-rules-reload><?= $icons->withLabel('action.fetch_now', 16, $t('rules.reload')) ?></button>
  </div>
  <?php endif; ?>
</div>

<section class="rules" data-rules data-page-size="<?= (int) $pageSize ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>">
  <p class="rules-basis" data-basis aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--warn" data-stale hidden></p>
  <p class="muted" data-reload-status role="status" aria-live="polite"></p>

  <?= $view->partial('rules/_tabs', ['mode' => 'tabs', 'current' => null]) ?>

  <div id="rules-panel" role="tabpanel" aria-labelledby="rules-tab-python" data-rules-panel>

  <form class="card defect-filters rule-filters" data-filter-form role="search" aria-label="<?= $e($t('rules.filter_region')) ?>">
    <div class="defect-filters__grid rule-filters__grid--text">
      <div class="field defect-filters__search">
        <label class="field__label" for="rf-q"><?= $icons->withLabel('action.search', 16, $t('rules.search_label')) ?></label>
        <input class="field__input" id="rf-q" name="q" type="search" maxlength="100" placeholder="<?= $e($t('rules.search_placeholder')) ?>" autocomplete="off">
      </div>
      <div class="field">
        <label class="field__label" for="rf-prefix"><?= $e($t('rules.prefix_label')) ?></label>
        <input class="field__input" id="rf-prefix" name="prefix" type="text" maxlength="64" placeholder="<?= $e($t('rules.prefix_placeholder')) ?>" autocomplete="off" spellcheck="false">
      </div>
      <div class="field">
        <label class="field__label" for="rf-category"><?= $e($t('rules.col.category')) ?></label>
        <select class="field__input" id="rf-category" name="category"><option value=""><?= $e($t('rules.category_all')) ?></option></select>
      </div>
    </div>
    <fieldset class="chip-group" data-facet="severity">
      <legend class="field__label"><?= $e($t('rules.col.severity')) ?></legend>
      <div class="chip-group__items" data-facet-items></div>
    </fieldset>
    <div class="defect-filters__grid rule-filters__grid--select">
      <div class="field">
        <label class="field__label" for="rf-analysis"><?= $e($t('rules.filter_analysis')) ?></label>
        <select class="field__input" id="rf-analysis" name="analysis">
          <option value=""><?= $e($t('rules.any')) ?></option>
          <option value="1"><?= $e($t('rules.analysis_on')) ?></option>
          <option value="0"><?= $e($t('rules.analysis_off')) ?></option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="rf-overridden"><?= $e($t('rules.filter_overridden')) ?></label>
        <select class="field__input" id="rf-overridden" name="overridden">
          <option value=""><?= $e($t('rules.any')) ?></option>
          <option value="1"><?= $e($t('rules.overridden_only')) ?></option>
        </select>
      </div>
      <?= $yesNo('rf-fixable', 'fixable', 'rules.col.fixable') ?>
      <?= $yesNo('rf-deprecated', 'deprecated', 'rules.filter_deprecated') ?>
      <div class="field">
        <label class="field__label" for="rf-change"><?= $e($t('rules.filter_change')) ?></label>
        <select class="field__input" id="rf-change" name="change">
          <option value=""><?= $e($t('rules.any')) ?></option>
          <option value="new"><?= $e($t('rules.change.new')) ?></option>
          <option value="changed"><?= $e($t('rules.change.changed')) ?></option>
          <option value="unchanged"><?= $e($t('rules.change.unchanged')) ?></option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="rf-sort"><?= $e($t('defects.sort_label')) ?></label>
        <select class="field__input" id="rf-sort" name="sort">
          <?php foreach (\PJGuardian\Services\Rules\RuleQuery::SORTS as $s): ?>
          <option value="<?= $e($s) ?>"><?= $e($t('rules.sort.' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="rf-order"><?= $e($t('defects.order_label')) ?></label>
        <select class="field__input" id="rf-order" name="order">
          <option value="asc"><?= $e($t('defects.order.asc')) ?></option>
          <option value="desc"><?= $e($t('defects.order.desc')) ?></option>
        </select>
      </div>
    </div>
    <div class="defect-filters__actions">
      <p class="search-dirty" id="rf-search-dirty" data-search-dirty role="status" aria-live="polite"></p>
      <button type="button" class="btn" data-clear-filters><?= $icons->withLabel('action.filter', 16, $t('defects.clear_filters')) ?></button>
      <button type="submit" class="btn btn--primary" data-search-submit aria-describedby="rf-search-dirty"><?= $icons->withLabel('action.search', 16, $t('defects.apply')) ?></button>
    </div>
  </form>

  <section class="card">
    <?php /* P23: 건수(왼쪽) · 내보내기 버튼(오른쪽 맞춤) */ ?>
    <div class="table-head">
      <p class="defects__count" data-count aria-live="polite"><?= $e($t('defects.loading')) ?></p>
      <?= $view->partial('partials/export_bar') ?>
    </div>
    <p class="muted" data-empty hidden></p>
    <?php if ($isAdmin): ?>
    <div class="rule-bulk" data-bulk-bar hidden>
      <p class="rule-bulk__count" data-bulk-count role="status" aria-live="polite"></p>
      <button type="button" class="btn btn--link" data-bulk-all hidden></button>
      <div class="rule-bulk__actions">
        <button type="button" class="btn" data-bulk-action="enable"><?= $e($t('rules.bulk_enable')) ?></button>
        <button type="button" class="btn" data-bulk-action="disable"><?= $e($t('rules.bulk_disable')) ?></button>
        <button type="button" class="btn" data-bulk-action="reset"><?= $e($t('rules.bulk_reset')) ?></button>
        <button type="button" class="btn btn--link" data-bulk-clear><?= $e($t('rules.bulk_clear')) ?></button>
      </div>
    </div>
    <p class="muted rule-bulk__result" data-bulk-result role="status" aria-live="polite"></p>
    <?php endif; ?>
    <div class="table-scroll">
      <table class="table rules-table<?= $isAdmin ? ' rules-table--admin' : '' ?>" data-table hidden>
        <caption class="sr-only"><?= $e($t('rules.caption')) ?></caption>
        <thead><tr>
          <?php if ($isAdmin): ?>
          <th scope="col" class="rcol-select"><input type="checkbox" data-select-all aria-label="<?= $e($t('rules.select_page')) ?>"></th>
          <?php endif; ?>
          <th scope="col" class="rcol-rule"><span class="th-label" data-clip="text"><?= $e($t('rules.col.rule')) ?></span></th>
          <th scope="col" class="rcol-summary"><span class="th-label" data-clip="text"><?= $e($t('rules.col.summary')) ?></span></th>
          <th scope="col" class="rcol-category"><span class="th-label" data-clip="text"><?= $e($t('rules.col.category')) ?></span></th>
          <th scope="col" class="rcol-severity"><span class="th-label" data-clip="text"><?= $e($t('rules.col.severity')) ?></span></th>
          <th scope="col" class="rcol-default"><span class="th-label" data-clip="text"><?= $e($t('rules.col.analysis')) ?></span></th>
          <th scope="col" class="rcol-fix"><span class="th-label" data-clip="text"><?= $e($t('rules.col.fixable')) ?></span></th>
          <th scope="col" class="rcol-status"><span class="th-label" data-clip="text"><?= $e($t('rules.col.status')) ?></span></th>
        </tr></thead>
        <tbody data-rows></tbody>
      </table>
    </div>
    <nav class="pagination" data-pagination aria-label="<?= $e($t('rules.pagination')) ?>" hidden></nav>
  </section>
  </div>
</section>
