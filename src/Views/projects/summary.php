<?php
/**
 * 프로젝트 상세 요약(명세 8, P19): 현재 결함 현황(심각도별·상태별·담당자별 건수)과 최근 분석 카드.
 * 건수는 DefectQuery::summary(필터 없음), 최근 분석은 AnalysisService::list 첫 행.
 *
 * @var array<string, mixed> $project
 * @var array<string, mixed> $summary
 * @var array<string, mixed>|null $latest
 * @var int|null $latestRun 최신 Run 번호(P20R): 이 요약은 항상 최신 Run(= 결함 표 현재 값) 기준이다
 * @var string $timezone
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$id = (int) $project['id'];
$fmt = static fn (?string $iso): string => $iso === null ? '' : (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
$link = static fn (string $q): string => $assets->page('projects/' . $id . '?' . $q);
?>
<div class="project-summary">
  <section class="card project-summary__defects" aria-labelledby="ps-defects">
    <h2 id="ps-defects"><?= $e($t('projects.summary_defects', ['total' => $summary['total']])) ?><?php if (($latestRun ?? null) !== null): ?> <span class="muted subtitle" data-latest-run><?= $e($t('runs.latest_basis', ['run' => $latestRun])) ?></span><?php endif; ?></h2>
    <h3 class="subhead"><?= $e($t('defects.facet_severity')) ?></h3>
    <ul class="count-list">
      <?php foreach ($summary['severity'] as $k => $n): ?>
      <li><a href="<?= $e($link('severity=' . $k)) ?>"><?= $view->partial('partials/badge', ['name' => 'severity.' . $k]) ?></a> <span class="count-list__n"><?= (int) $n ?></span></li>
      <?php endforeach; ?>
    </ul>
    <h3 class="subhead"><?= $e($t('defects.facet_status')) ?></h3>
    <ul class="count-list">
      <?php foreach ($summary['status'] as $k => $n): ?>
      <li><a href="<?= $e($link('status=' . $k)) ?>"><?= $view->partial('partials/badge', ['name' => 'defect_status.' . $k]) ?></a> <span class="count-list__n"><?= (int) $n ?></span></li>
      <?php endforeach; ?>
    </ul>
    <h3 class="subhead"><?= $e($t('defects.facet_assignee')) ?></h3>
    <?php if ($summary['assignees'] === []): ?>
    <p class="muted"><?= $e($t('projects.summary_no_defects')) ?></p>
    <?php else: ?>
    <ul class="count-list count-list--plain">
      <?php foreach ($summary['assignees'] as $a): ?>
      <li><a href="<?= $e($link('assignee=' . ($a['id'] === null ? 'none' : (int) $a['id']))) ?>"><?= $a['id'] === null ? $e($t('defects.unassigned')) : $e($a['display_name']) ?></a><?php if ($a['id'] !== null && !$a['active']): ?> <span class="tag tag--inactive"><?= $e($t('defects.inactive')) ?></span><?php endif; ?> <span class="count-list__n"><?= (int) $a['count'] ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>
  <section class="card project-summary__latest" aria-labelledby="ps-latest">
    <h2 id="ps-latest"><?= $e($t('projects.summary_latest')) ?></h2>
    <?php if ($latest === null): ?>
    <p class="muted"><?= $e($t('analyses.empty_body')) ?></p>
    <?php else: ?>
    <p><a href="<?= $e($assets->page('analyses/' . (int) $latest['id'])) ?>"><?= $e(isset($latest['run']) ? $t('runs.title_of', ['run' => $latest['run'], 'id' => $latest['id']]) : $t('analyses.title_of', ['id' => $latest['id']])) ?></a>
      <?= $view->partial('partials/badge', ['name' => 'analysis_status.' . (!empty($latest['unchanged']) ? 'unchanged' : $latest['status'])]) ?></p>
    <dl class="detail-list">
      <dt><?= $e($t('analyses.col_requested')) ?></dt><dd><time datetime="<?= $e($latest['queued_at']) ?>"><?= $e($fmt($latest['queued_at'])) ?></time></dd>
      <?php if ($latest['finished_at'] !== null): ?><dt><?= $e($t('analyses.col_finished')) ?></dt><dd><time datetime="<?= $e($latest['finished_at']) ?>"><?= $e($fmt($latest['finished_at'])) ?></time></dd><?php endif; ?>
      <?php if ($latest['counts'] !== null): ?>
      <dt><?= $e($t('analyses.col_defects')) ?></dt><dd><?= $e($t('analyses.counts_short', $latest['counts'])) ?></dd>
      <?php elseif ($latest['failure'] !== null): ?>
      <dt><?= $e($t('analyses.failure_title')) ?></dt><dd><?= $e($latest['failure']['title']) ?></dd>
      <?php endif; ?>
    </dl>
    <?php endif; ?>
  </section>
</div>
