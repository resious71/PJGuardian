<?php
/**
 * 프로젝트 상세의 "결함" 탭(명세 6.4, 20.4, P17). 표·필터 칩·선택 목록은 화면 JS(pages/defects.js)가 API로 읽어 textContent로 채운다.
 * 서버는 틀과 빈 상태(분석 전 / 결함 0건)만 그린다. 필터 상태는 이 화면의 URL 쿼리(rule, severity, status, assignee, file, file_mode,
 * q, sort, order, page)에 들어간다.
 * 일괄 변경(P18): 행의 체크박스와 "현재 페이지 전체 선택"(필터 결과 전체 선택은 만들지 않는다), 선택하면 일괄 변경 막대가 나온다.
 * Run(P20R): 위쪽 Run 선택기(?run=N, 없으면 최신 Run). 과거 Run은 읽기 전용이라
 *   JS가 선택 열·일괄 변경을 숨기고 안내를 띄운다(판정은 요약 API의 run_view.read_only 한 곳). 서버의 쓰기 API는 Run을 받지 않는다.
 *
 *
 * @var array<string, mixed> $project
 * @var array{has_analysis:bool, total:int} $overview
 * @var int $pageSize
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$id = (int) $project['id'];
?>
<?php if (!$overview['has_analysis'] && $overview['total'] === 0): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'ui.empty',
      'title' => $t('defects.no_analysis_title'),
      'body' => $t('defects.no_analysis_body'),
      'actionLabel' => $t('upload.button'),
      'actionHref' => $assets->page('upload?project=' . $id),
  ]) ?>
</section>
<?php elseif ($overview['total'] === 0): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.defects',
      'title' => $t('defects.none_title'),
      'body' => $t('defects.none_body'),
      'actionLabel' => $t('upload.button'),
      'actionHref' => $assets->page('upload?project=' . $id),
  ]) ?>
</section>
<?php else: ?>
<section class="defects" data-defects data-project-id="<?= $id ?>" data-page-size="<?= (int) $pageSize ?>" data-detail-base="<?= $e($assets->page('defects/')) ?>">
  <div class="card run-bar" data-run-bar>
    <div data-run-selector></div>
    <div data-run-banner></div>
  </div>
  <?php /* P21V: 검색 영역의 모든 입력은 고르기만 하고 맨 아래 오른쪽 "검색"(또는 검색어·파일·Run 입력칸의 Enter)으로 한꺼번에 적용한다.
     줄 순서 = Tab 순서: 검색어·파일 → 심각도·상태 칩 → 규칙·담당자·정렬·순서(같은 격자, 라벨·입력칸 일직선) → Run 필터 → 필터 지우기·검색 */ ?>
  <form class="card defect-filters" data-filter-form role="search" aria-label="<?= $e($t('defects.filter_region')) ?>">
    <div class="defect-filters__grid defect-filters__grid--text">
      <div class="field defect-filters__search">
        <label class="field__label" for="df-q"><?= $icons->withLabel('action.search', 16, $t('defects.search_label')) ?></label>
        <input class="field__input" id="df-q" name="q" type="search" maxlength="200" placeholder="<?= $e($t('defects.search_placeholder')) ?>" autocomplete="off">
      </div>
      <div class="field">
        <label class="field__label" for="df-file"><?= $e($t('defects.file_label')) ?></label>
        <input class="field__input field__input--mono" id="df-file" name="file" type="text" maxlength="1000" placeholder="<?= $e($t('defects.file_placeholder')) ?>" autocomplete="off">
      </div>
      <div class="field">
        <label class="field__label" for="df-file-mode"><?= $e($t('defects.file_mode_label')) ?></label>
        <select class="field__input" id="df-file-mode" name="file_mode">
          <option value="prefix"><?= $e($t('defects.file_mode_prefix')) ?></option>
          <option value="contains"><?= $e($t('defects.file_mode_contains')) ?></option>
        </select>
      </div>
    </div>
    <fieldset class="chip-group" data-facet="severity">
      <legend class="field__label"><?= $e($t('defects.facet_severity')) ?></legend>
      <div class="chip-group__items" data-facet-items></div>
    </fieldset>
    <fieldset class="chip-group" data-facet="status">
      <legend class="field__label"><?= $e($t('defects.facet_status')) ?></legend>
      <div class="chip-group__items" data-facet-items></div>
    </fieldset>
    <div class="defect-filters__grid defect-filters__grid--select">
      <div class="field defect-filters__multi">
        <label class="field__label" for="df-rule"><?= $e($t('defects.facet_rule')) ?></label>
        <select class="field__input field__input--mono" id="df-rule" name="rule" multiple size="5" aria-describedby="df-multi-help"></select>
      </div>
      <div class="field defect-filters__multi">
        <label class="field__label" for="df-assignee"><?= $e($t('defects.facet_assignee')) ?></label>
        <select class="field__input" id="df-assignee" name="assignee" multiple size="5" aria-describedby="df-multi-help"></select>
      </div>
      <div class="field">
        <label class="field__label" for="df-sort"><?= $e($t('defects.sort_label')) ?></label>
        <select class="field__input" id="df-sort" name="sort">
          <?php foreach (\PJGuardian\Services\Defects\DefectFilter::SORTS as $s): ?>
          <option value="<?= $e($s) ?>"><?= $e($t('defects.sort.' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="df-order"><?= $e($t('defects.order_label')) ?></label>
        <select class="field__input" id="df-order" name="order">
          <option value="asc"><?= $e($t('defects.order.asc')) ?></option>
          <option value="desc"><?= $e($t('defects.order.desc')) ?></option>
        </select>
      </div>
    </div>
    <p class="field__help" id="df-multi-help"><?= $e($t('defects.multi_help')) ?></p>
    <div class="defect-filters__grid defect-filters__grid--run" data-run-filters>
      <div class="field">
        <label class="field__label" for="df-from-run"><?= $e($t('defects.filter_from_run')) ?></label>
        <input class="field__input" id="df-from-run" name="from_run" type="number" min="1" inputmode="numeric" placeholder="<?= $e($t('defects.filter_run_placeholder')) ?>">
      </div>
      <div class="field">
        <label class="field__label" for="df-in-from"><?= $e($t('defects.filter_in_run_from')) ?></label>
        <input class="field__input" id="df-in-from" name="in_run_from" type="number" min="1" inputmode="numeric" placeholder="<?= $e($t('defects.filter_run_placeholder')) ?>">
      </div>
      <div class="field">
        <label class="field__label" for="df-in-to"><?= $e($t('defects.filter_in_run_to')) ?></label>
        <input class="field__input" id="df-in-to" name="in_run_to" type="number" min="1" inputmode="numeric" placeholder="<?= $e($t('defects.filter_run_placeholder')) ?>">
      </div>
    </div>
    <p class="field__help"><?= $e($t('defects.filter_run_help')) ?></p>
    <p class="notice notice--info analysis-filter" data-analysis-filter hidden>
      <span data-analysis-filter-text></span>
      <button type="button" class="btn btn--small" data-analysis-filter-clear><?= $e($t('defects.analysis_filter_clear')) ?></button>
    </p>
    <div class="defect-filters__actions">
      <p class="search-dirty" id="df-search-dirty" data-search-dirty role="status" aria-live="polite"></p>
      <button type="button" class="btn" data-clear-filters><?= $icons->withLabel('action.filter', 16, $t('defects.clear_filters')) ?></button>
      <button type="submit" class="btn btn--primary" data-search-submit aria-describedby="df-search-dirty"><?= $icons->withLabel('action.search', 16, $t('defects.apply')) ?></button>
    </div>
  </form>

  <section class="card">
    <?php /* P23: 건수(왼쪽) · 내보내기 버튼(오른쪽 맞춤) */ ?>
    <div class="table-head">
      <p class="defects__count" data-count aria-live="polite"><?= $e($t('defects.loading')) ?></p>
      <?= $view->partial('partials/export_bar') ?>
    </div>
    <form class="bulk-bar" data-bulk-bar hidden aria-labelledby="bulk-title" novalidate>
      <p class="bulk-bar__title" id="bulk-title" data-bulk-count aria-live="polite"></p>
      <div class="bulk-bar__fields">
        <div class="field">
          <label class="field__label" for="bulk-assignee"><?= $e($t('defects.field_assignee')) ?></label>
          <select class="field__input" id="bulk-assignee" name="assignee_id">
            <option value="keep"><?= $e($t('defects.bulk_keep')) ?></option>
            <option value=""><?= $e($t('defects.unassigned')) ?></option>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="bulk-severity"><?= $e($t('defects.field_severity')) ?></label>
          <select class="field__input" id="bulk-severity" name="severity">
            <option value="keep"><?= $e($t('defects.bulk_keep')) ?></option>
            <?php foreach (['critical', 'high', 'medium', 'low', 'not_defined'] as $v): ?>
            <option value="<?= $e($v) ?>"><?= $e($icons->label('severity.' . $v)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="bulk-status"><?= $e($t('defects.field_status')) ?></label>
          <select class="field__input" id="bulk-status" name="status">
            <option value="keep"><?= $e($t('defects.bulk_keep')) ?></option>
            <?php foreach (['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'] as $v): ?>
            <option value="<?= $e($v) ?>"><?= $e($icons->label('defect_status.' . $v)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field bulk-bar__memo">
          <label class="field__label" for="bulk-memo"><?= $e($t('defects.memo_label')) ?></label>
          <input class="field__input" id="bulk-memo" name="memo" type="text" maxlength="1000" autocomplete="off">
        </div>
        <div class="bulk-bar__actions">
          <button type="submit" class="btn btn--primary" data-bulk-apply><?= $icons->withLabel('action.bulk_edit', 16, $t('defects.bulk_apply')) ?></button>
          <button type="button" class="btn" data-bulk-clear><?= $e($t('defects.bulk_clear')) ?></button>
        </div>
      </div>
      <p class="field__help bulk-bar__note" id="bulk-severity-note"><?= $e($t('defects.bulk_severity_note')) ?></p>
    </form>
    <div class="bulk-result" data-bulk-result hidden></div>
    <div data-empty-filtered hidden>
      <section class="empty-state">
        <div class="empty-state__icon"><?= $icons->icon('action.filter', 24) ?></div>
        <h3 class="empty-state__title"><?= $e($t('defects.filtered_empty_title')) ?></h3>
        <p class="empty-state__body"><?= $e($t('defects.filtered_empty_body')) ?></p>
        <button type="button" class="btn btn--primary" data-clear-filters><?= $e($t('defects.clear_filters')) ?></button>
      </section>
    </div>
    <div class="table-scroll">
    <table class="table defects-table" data-table hidden>
      <caption class="sr-only"><?= $e($t('defects.table_caption')) ?></caption>
      <thead><tr>
        <th scope="col" class="defects-table__select col-select"><input type="checkbox" data-select-page aria-label="<?= $e($t('defects.select_page')) ?>"></th>
        <?php /* P21U: 정렬할 수 있는 열은 머리글 버튼(정렬 선택 상자와 같은 값, aria-sort와 ▲▼ 표시는 defects.js) */ ?>
        <?php /* P21B 7절: 열 너비는 CSS 한 곳(.defects-table col-*). 머리글 글은 칸보다 길면 …으로 줄이고 전체는 툴팁(data-clip), 정렬 표시는 항상 보임 */ ?>
        <?php $sortTh = static fn (string $key, string $label, string $col, string $extra = ''): string => '<th scope="col" class="th-sortable ' . $e($col) . '" aria-sort="none" data-sort-col="' . $e($key) . '">'
            . '<button type="button" class="th-sort" data-sort-key="' . $e($key) . '" aria-label="' . $e($t('defects.sort_by', ['column' => $label])) . '"><span class="th-label" data-clip="text">' . $e($label) . '</span>'
            . '<span class="th-sort__mark" aria-hidden="true" data-sort-mark></span></button>' . $extra . '</th>'; ?>
        <?= $sortTh('severity', $t('defects.col_severity'), 'col-severity') ?>
        <?= $sortTh('status', $t('defects.col_status'), 'col-status') ?>
        <?= $sortTh('rule', $t('defects.col_rule'), 'col-rule') ?>
        <?= $sortTh('file', $t('defects.col_location'), 'col-location', ' <span class="muted th-note" data-latest-location hidden></span>') ?>
        <th scope="col" class="col-message"><span class="th-label" data-clip="text"><?= $e($t('defects.col_message')) ?></span> <span class="muted th-note" data-latest-location hidden></span></th>
        <th scope="col" class="col-assignee"><span class="th-label" data-clip="text"><?= $e($t('defects.col_assignee')) ?></span></th>
        <?= $sortTh('run', $t('defects.col_run'), 'col-run') ?>
        <?= $sortTh('last_seen', $t('defects.col_last_seen'), 'col-seen') ?>
      </tr></thead>
      <tbody data-rows></tbody>
    </table>
    </div>
    <nav class="pagination" data-pagination aria-label="<?= $e($t('defects.pagination')) ?>" hidden></nav>
  </section>
</section>
<?php endif; ?>
