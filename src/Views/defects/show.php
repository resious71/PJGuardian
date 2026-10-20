<?php
/**
 * 결함 상세(명세 6.4, 7, 8, P17). 읽기 전용 요약은 서버가 그린다(모든 값 이스케이프).
 * 코드 뷰어(CodeMirror 6)와 변경 이력은 pages/defect_detail.js가 API로 읽어 텍스트로만 그린다(코드는 HTML로 해석되지 않음).
 * 담당자·심각도·상태 수정(P18): [data-edit-slot]의 폼. 저장은 pages/defect_detail.js가 PATCH로(낙관적 잠금 값 data-version, 충돌이면 비교 대화상자).
 * 담당자 선택지는 이 프로젝트에 접근할 수 있는 활성 사용자만. 현재 담당자가 비활성·접근 상실이면 그 값만 표시용으로 남긴다.
 *
 * @var array<string, mixed> $defect DefectQuery::detail(과거 Run이면 run_view와 그 Run 시점 상태·담당자·심각도, P20R)
 * @var int|null $latestRun 최신 Run 번호(Run 선택기·안내)
 * @var array<string, mixed> $project
 * @var int $historyPageSize
 * @var list<array{id:int, login_id:string, display_name:string}> $assignees 담당자 후보(ProjectAccess::assignableUsers)
 * @var int $excerptContext 발췌 앞뒤 줄 수(설정 snapshot.excerpt_context_lines, 발췌본 안내 문구)
 * @var string $timezone
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */

use PJGuardian\Support\RuleLinks;

$d = $defect;
$fmt = static fn (?string $iso): string => $iso === null ? '' : (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
$ruleHref = RuleLinks::path($d['rule_key']);
if ($d['column'] !== null && $d['end_line'] !== null && $d['end_column'] !== null) {
    $position = $t('defects.position_range', ['line' => $d['line'], 'column' => $d['column'], 'end_line' => $d['end_line'], 'end_column' => $d['end_column']]);
} elseif ($d['column'] !== null) {
    $position = $t('defects.position_line_col', ['line' => $d['line'], 'column' => $d['column']]);
} else {
    $position = $t('defects.position_line', ['line' => $d['line']]);
}
$pid = (int) $project['id'];
$runHref = static fn (int $n): string => $assets->page('projects/' . $pid . '?run=' . $n);
// 처음 발생·마지막 감지: "Run N"(그 Run 시점의 결함 목록 링크) · 분석 #id · 시각 · 도구 버전. Run 번호가 없으면(되채움 전) 분석 #id만
$historyHref = static fn (int $analysisId): string => $assets->page('projects/' . $pid . '/history?analysis=' . $analysisId);
// 처음 발생: Run을 누르면 그 분석의 비교 패널(히스토리, P21). 마지막 감지: 그 Run 시점의 결함 목록
$seen = static function (array $s, bool $toHistory = false) use ($e, $t, $fmt, $runHref, $historyHref): string {
    $version = $s['tool_version'] !== null && $s['tool_version'] !== ''
        ? $t('defects.tool_version', ['version' => $s['tool_version']]) : $t('defects.tool_version_unknown');
    $time = $s['finished_at'] !== null ? ' · <time datetime="' . $e($s['finished_at']) . '">' . $e($fmt($s['finished_at'])) . '</time>' : '';
    $href = $toHistory ? $historyHref((int) $s['analysis_id']) : (isset($s['run']) ? $runHref((int) $s['run']) : null);
    $label = isset($s['run']) ? $t('runs.label', ['run' => $s['run']]) : null;
    $run = $label !== null && $href !== null ? '<a href="' . $e($href) . '" data-run-link' . ($toHistory ? ' data-history-link' : '') . '>' . $e($label) . '</a> · ' : '';
    return $run . $e($t('defects.analysis_ref', ['id' => $s['analysis_id']])) . $time . ' · <span data-tool-version>' . $e($version) . '</span>';
};
// 존재 구간: "Run 1~4, 6~현재"(각 구간의 시작 Run 링크)
$intervals = static function (array $list) use ($e, $t, $runHref): string {
    if ($list === []) {
        return $e($t('runs.intervals_none'));
    }
    $parts = [];
    foreach ($list as $i) {
        $label = $i['to'] === null ? $t('runs.interval_open', ['from' => $i['from']]) : ($i['to'] === $i['from'] ? (string) $i['from'] : $i['from'] . '~' . $i['to']);
        $parts[] = '<a href="' . $e($runHref((int) $i['from'])) . '">' . $e($label) . '</a>';
    }
    return $e($t('runs.label', ['run' => ''])) . implode(', ', $parts);
};
$runView = $d['run_view'] ?? null;
$readOnly = $runView !== null && $runView['read_only'];
$here = $assets->page('defects/' . (int) $d['id']);
?>
<nav class="breadcrumb" aria-label="<?= $e($t('defects.breadcrumb')) ?>">
  <a href="<?= $e($assets->page('projects')) ?>"><?= $e($t('projects.title')) ?></a> ›
  <a href="<?= $e($assets->page('projects/' . (int) $project['id'])) ?>" data-back-to-list><?= $e($project['name']) ?></a> ›
  <span><?= $e($t('defects.breadcrumb_defect', ['id' => $d['id']])) ?></span>
</nav>
<div class="card run-bar" data-run-bar>
  <div data-run-selector data-project-id="<?= $pid ?>" data-run="<?= $runView !== null ? (int) $runView['run'] : '' ?>"></div>
  <?php if ($readOnly): ?>
  <div class="notice notice--info run-banner" role="status" data-run-banner="<?= (int) $runView['run'] ?>">
    <p class="run-banner__title"><?= $icons->icon('ui.read_only', 16) ?> <strong><?= $e($t('runs.past_banner', ['run' => $runView['run']])) ?></strong> —
      <a href="<?= $e($here) ?>" data-go-latest><?= $e($t('runs.go_latest', ['run' => $latestRun])) ?></a></p>
    <p class="run-banner__help"><?= $e($t('runs.past_banner_help')) ?></p>
  </div>
  <?php endif; ?>
</div>
<?php if ($runView !== null && !$runView['in_run']): ?>
<section class="card notice notice--warn" data-not-in-run>
  <h2><?= $icons->icon('system.warn', 20) ?> <?= $e($t('runs.not_in_run_title', ['run' => $runView['run']])) ?></h2>
  <p><?= $e($t('runs.not_in_run_lead')) ?> <?= $intervals($d['runs'] ?? []) ?></p>
  <p><a class="btn" href="<?= $e($here) ?>"><?= $e($t('runs.go_latest', ['run' => $latestRun])) ?></a></p>
</section>
<?php endif; ?>
<div class="page-header">
  <h1 class="defect-title">
    <?php if ($ruleHref !== null): ?><a class="defect-rule" href="<?= $e($assets->page(ltrim($ruleHref, '/'))) ?>" title="<?= $e($t('rules.open_detail')) ?>"><code><?= $e($d['rule_key']) ?></code></a><?php else: ?><code class="defect-rule" data-rule-key><?= $e($d['rule_key']) ?></code><?php endif; ?>
    <?php if (!empty($d['rule_missing'])): ?><span class="tag rule-missing" data-rule-missing title="<?= $e($t('rules.missing_tip')) ?>"><?= $icons->withLabel('system.warn', 16, $t('rules.missing_short')) ?></span><?php endif; ?>
    <span class="defect-title__location"><code><?= $e($d['file_path']) ?>:<?= (int) $d['line'] ?></code></span>
  </h1>
  <div class="badge-row">
    <?= $view->partial('partials/badge', ['name' => 'severity.' . $d['severity']]) ?>
    <?= $view->partial('partials/badge', ['name' => 'defect_status.' . $d['status']]) ?>
  </div>
  <p class="defect-message"><?= $e($d['message']) ?></p>
</div>

<section class="card" aria-labelledby="defect-summary-title" data-defect-summary data-defect-id="<?= (int) $d['id'] ?>" data-version="<?= (int) $d['version'] ?>">
  <h2 id="defect-summary-title"><?= $e($t('defects.summary_title')) ?></h2>
  <dl class="detail-list">
    <dt><?= $e($t('defects.field_assignee')) ?></dt>
    <dd data-field="assignee"><?php if ($d['assignee'] === null): ?><span class="muted"><?= $e($t('defects.unassigned')) ?></span><?php else: ?><?= $e($d['assignee']['display_name']) ?> <code class="muted"><?= $e($d['assignee']['login_id']) ?></code><?php if (!$d['assignee']['active']): ?> <span class="tag tag--inactive" data-inactive><?= $e($t('defects.inactive')) ?></span><?php elseif ($d['assignee']['has_access'] === false): ?> <span class="tag tag--inactive" data-no-access><?= $e($t('defects.no_access')) ?></span><?php endif; ?><?php endif; ?></dd>
    <dt><?= $e($t('defects.field_severity')) ?></dt>
    <dd data-field="severity"><?= $view->partial('partials/badge', ['name' => 'severity.' . $d['severity']]) ?><?php $src = (string) ($d['severity_source'] ?? 'none'); ?>
      <span class="severity-source muted" data-severity-source<?= $src === 'none' ? ' hidden' : '' ?>><?= $src === 'none' ? '' : $e($t('defects.severity_source.' . $src)) ?></span></dd>
    <dt><?= $e($t('defects.field_status')) ?></dt>
    <dd data-field="status"><?= $view->partial('partials/badge', ['name' => 'defect_status.' . $d['status']]) ?></dd>
    <dt><?= $e($t('defects.field_rule')) ?></dt>
    <dd><code><?= $e($d['rule_key']) ?></code></dd>
    <dt><?= $e($t('defects.field_tool')) ?></dt>
    <dd><?= $e($d['tool']) ?> · <?= $e($t('defects.language.' . $d['language'])) ?></dd>
    <dt><?= $e($t('defects.field_file')) ?></dt>
    <dd><code class="defect-path"><?= $e($d['file_path']) ?></code></dd>
    <dt><?= $e($t('defects.field_position')) ?></dt>
    <dd><?= $e($position) ?></dd>
    <dt><?= $e($t('defects.field_first_seen')) ?></dt>
    <dd data-field="first_seen"><?= $seen($d['first_seen'], true) ?></dd>
    <dt><?= $e($t('defects.field_last_seen')) ?></dt>
    <dd data-field="last_seen"><?= $seen($d['last_seen']) ?></dd>
    <dt><?= $e($t('runs.field_intervals')) ?></dt>
    <dd data-field="runs"><?= $intervals($d['runs'] ?? []) ?></dd>
  </dl>
  <?php if ($readOnly): ?>
  <p class="notice notice--info" data-read-only-edit><?= $icons->icon('ui.read_only', 16) ?> <?= $e($t('runs.read_only_edit')) ?></p>
  <?php else: ?>
  <div class="defect-edit" data-edit-slot>
    <h3 class="subhead" id="edit-title"><?= $e($t('defects.edit_title')) ?></h3>
    <form class="defect-edit__form" data-edit-form aria-labelledby="edit-title" novalidate>
      <div class="defect-edit__row">
        <div class="field">
          <label class="field__label" for="de-assignee"><?= $e($t('defects.field_assignee')) ?></label>
          <select class="field__input" id="de-assignee" name="assignee_id">
            <option value=""><?= $e($t('defects.unassigned')) ?></option>
            <?php $listed = false; foreach ($assignees as $u): $listed = $listed || ($d['assignee'] !== null && $u['id'] === $d['assignee']['id']); ?>
            <option value="<?= (int) $u['id'] ?>"<?= $d['assignee'] !== null && $u['id'] === $d['assignee']['id'] ? ' selected' : '' ?>><?= $e($u['display_name']) ?> (<?= $e($u['login_id']) ?>)</option>
            <?php endforeach; ?>
            <?php if ($d['assignee'] !== null && !$listed): /* 비활성·접근 상실 담당자: 유지 표시용(새로 고를 수 없음) */ ?>
            <option value="<?= (int) $d['assignee']['id'] ?>" selected data-keep-only><?= $e($d['assignee']['display_name']) ?> (<?= $e($d['assignee']['login_id']) ?>) · <?= $e($t($d['assignee']['active'] ? 'defects.no_access' : 'defects.inactive')) ?></option>
            <?php endif; ?>
          </select>
        </div>
        <div class="field">
          <label class="field__label" for="de-severity"><?= $e($t('defects.field_severity')) ?></label>
          <select class="field__input" id="de-severity" name="severity" aria-describedby="de-severity-preview">
            <?php foreach (['critical', 'high', 'medium', 'low', 'not_defined'] as $v): ?>
            <option value="<?= $e($v) ?>"<?= $d['severity'] === $v ? ' selected' : '' ?>><?= $e($icons->label('severity.' . $v)) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="defect-edit__preview" id="de-severity-preview" data-preview="severity"><?= $view->partial('partials/badge', ['name' => 'severity.' . $d['severity']]) ?></span>
        </div>
        <div class="field">
          <label class="field__label" for="de-status"><?= $e($t('defects.field_status')) ?></label>
          <select class="field__input" id="de-status" name="status" aria-describedby="de-status-preview">
            <?php foreach (['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'] as $v): ?>
            <option value="<?= $e($v) ?>"<?= $d['status'] === $v ? ' selected' : '' ?>><?= $e($icons->label('defect_status.' . $v)) ?></option>
            <?php endforeach; ?>
          </select>
          <span class="defect-edit__preview" id="de-status-preview" data-preview="status"><?= $view->partial('partials/badge', ['name' => 'defect_status.' . $d['status']]) ?></span>
        </div>
      </div>
      <div class="field field--wide">
        <label class="field__label" for="de-memo"><?= $e($t('defects.memo_label')) ?></label>
        <textarea class="field__input defect-edit__memo" id="de-memo" name="memo" rows="2" maxlength="1000" aria-describedby="de-memo-help"></textarea>
        <p class="field__help" id="de-memo-help"><?= $e($t('defects.memo_help')) ?></p>
      </div>
      <div class="defect-edit__actions">
        <button type="submit" class="btn btn--primary" data-edit-save disabled><?= $e($t('defects.save')) ?></button>
        <span class="muted" data-edit-hint><?= $e($t('defects.save_hint_unchanged')) ?></span>
      </div>
    </form>
  </div>
  <?php endif; ?>
</section>

<section class="card code-card" aria-labelledby="code-title" data-code-viewer data-defect-id="<?= (int) $d['id'] ?>" data-run="<?= $readOnly ? (int) $runView['run'] : '' ?>" data-excerpt-context="<?= (int) $excerptContext ?>">
  <div class="card__head">
    <h2 id="code-title"><?= $e($t('defects.code_title')) ?></h2>
    <div class="toolbar code-card__tools">
      <div class="segmented" role="group" aria-label="<?= $e($t('defects.code_mode_label')) ?>">
        <button type="button" class="btn btn--small" data-code-mode="window" aria-pressed="true"><?= $e($t('defects.code_mode_window')) ?></button>
        <button type="button" class="btn btn--small" data-code-mode="full" aria-pressed="false"><?= $e($t('defects.code_mode_full')) ?></button>
      </div>
      <button type="button" class="btn btn--small" data-code-wrap aria-pressed="false"><?= $e($t('defects.code_wrap')) ?></button>
    </div>
  </div>
  <p class="muted code-card__source" data-code-source></p>
  <div class="code-card__notices" data-code-notices></div>
  <p class="muted" data-code-loading><?= $e($t('defects.code_loading')) ?></p>
  <div class="code-viewer" data-code-host></div>
  <div data-code-empty hidden></div>
</section>

<?php if (!empty($ruleGuide)): $inline = static fn (string $s): string => (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $e($s)); ?>

<details class="card rule-guide" data-rule-guide>
  <summary><h2 class="rule-guide__title"><?= $e($t('rules.guide.title')) ?> · <code><?= $e($d['rule_key']) ?></code></h2></summary>
  <p><?= $inline($ruleGuide['summary']) ?></p>
  <div class="rule-guide__examples">
    <figure class="rule-guide__example rule-guide__example--bad"><figcaption><?= $icons->icon('system.error', 16) ?> <?= $e($t('rules.guide.bad')) ?></figcaption><pre class="rule-guide__code"><code><?= $e($ruleGuide['bad']) ?></code></pre></figure>
    <figure class="rule-guide__example rule-guide__example--good"><figcaption><?= $icons->icon('system.ok', 16) ?> <?= $e($t('rules.guide.good')) ?></figcaption><pre class="rule-guide__code"><code><?= $e($ruleGuide['good']) ?></code></pre></figure>
  </div>
  <h3><?= $e($t('rules.guide.fix')) ?></h3>
  <p><?= $inline($ruleGuide['fix']) ?></p>
  <p class="field__help"><?= $e($t('rules.guide.checked', ['tool' => $t('rules.tool.' . $ruleGuide['checked']['tool']), 'version' => $ruleGuide['checked']['version']])) ?></p>
</details>
<?php endif; ?>

<section class="card" aria-labelledby="history-title" data-history data-defect-id="<?= (int) $d['id'] ?>" data-page-size="<?= (int) $historyPageSize ?>">
  <div class="card__head">
    <h2 id="history-title"><?= $e($t('defects.history_title')) ?></h2>
    <button type="button" class="btn btn--small" data-open-timeline><?= $icons->withLabel('action.timeline', 16, $t('icon.action.timeline')) ?></button>
  </div>
  <p class="muted" data-history-empty hidden></p>
  <table class="table table--compact" data-history-table hidden>
    <caption class="sr-only"><?= $e($t('defects.history_caption')) ?></caption>
    <thead><tr>
      <th scope="col"><?= $e($t('defects.history_col_time')) ?></th>
      <th scope="col"><?= $e($t('defects.history_col_by')) ?></th>
      <th scope="col"><?= $e($t('defects.history_col_field')) ?></th>
      <th scope="col"><?= $e($t('defects.history_col_change')) ?></th>
      <th scope="col"><?= $e($t('defects.history_col_kind')) ?></th>
      <th scope="col"><?= $e($t('defects.history_col_reason')) ?></th>
    </tr></thead>
    <tbody data-history-rows></tbody>
  </table>
  <nav class="pagination" data-history-pagination aria-label="<?= $e($t('defects.history_pagination')) ?>" hidden></nav>
</section>
