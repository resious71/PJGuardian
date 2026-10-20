<?php
/**
 * 프로젝트 목록(홈, 명세 3.1, 4장). 볼 수 있는 프로젝트만. 관리자에게만 "새 프로젝트" 버튼과 접근 요약 열(그룹 n · 사용자 m, 없으면 "관리자만").
 * 프로젝트가 없으면 빈 상태(명세 5.4, 20.4): 관리자 = 생성 버튼, 일반 사용자 = 관리자에게 요청 안내.
 *
 * @var list<array<string, mixed>> $projects
 * @var array<int, array<string, mixed>> $schedules P26: 주기 분석 사용 중인 프로젝트 → {next_run_at, interval_days, consecutive_failures?(admin)}
 * @var int $total
 * @var int $pageNo
 * @var int $pages
 * @var bool $isAdmin
 * @var string $timezone
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$fmt = static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
$fmtShort = static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('y-m-d H:i');
$schedules ??= [];
?>
<div class="page-header page-header--actions">
  <div>
    <h1><?= $icons->withLabel('nav.projects', 24, $t('projects.title')) ?></h1>
    <p class="muted"><?= $e($t('projects.intro')) ?></p>
  </div>
  <?php if ($isAdmin && $projects !== []): ?>
  <a class="btn btn--primary" href="<?= $e($assets->page('projects/new')) ?>"><?= $e($t('projects.new_button')) ?></a>
  <?php endif; ?>
</div>

<?php if ($projects === []): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.projects',
      'title' => $t($isAdmin ? 'projects.empty_title' : 'projects.empty_title_user'),
      'body' => $t($isAdmin ? 'projects.empty_body_admin' : 'projects.empty_body_user'),
      'actionLabel' => $isAdmin ? $t('projects.new_button') : null,
      'actionHref' => $isAdmin ? $assets->page('projects/new') : null,
  ]) ?>
</section>
<?php else: ?>
<section class="card">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= $e($t('projects.col_name')) ?></th>
      <th scope="col"><?= $e($t('projects.col_language')) ?></th>
      <th scope="col"><?= $e($t('projects.col_description')) ?></th>
      <th scope="col"><?= $e($t('projects.col_created')) ?></th>
      <?php if ($isAdmin): ?><th scope="col"><?= $e($t('projects.col_access')) ?></th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php foreach ($projects as $p): ?>
      <tr>
        <td>
          <a href="<?= $e($assets->page('projects/' . (int) $p['id'])) ?>"><?= $e($p['name']) ?></a>
          <?php if (isset($schedules[(int) $p['id']])): $sc = $schedules[(int) $p['id']]; ?>
          <span class="tag project-schedule-tag" title="<?= $e($t('schedule.list_title', ['days' => $sc['interval_days']])) ?>"><?= $icons->withLabel('source.schedule', 16, $t('schedule.list_tag', ['next' => $sc['next_run_at'] !== null ? $fmtShort($sc['next_run_at']) : '—'])) ?></span>
          <?php if (($sc['consecutive_failures'] ?? 0) > 0): ?>
          <span class="tag tag--warn project-schedule-tag"><?= $icons->withLabel('system.warn', 16, $t('schedule.list_failing', ['n' => $sc['consecutive_failures']])) ?></span>
          <?php endif; ?>
          <?php endif; ?>
        </td>
        <td><?= $view->partial('partials/lang_badge', ['language' => $p['language']]) ?></td>
        <td class="cell-clip" title="<?= $e($p['description']) ?>"><?= $e($p['description']) ?></td>
        <td><time datetime="<?= $e($p['created_at']) ?>"><?= $e($fmt($p['created_at'])) ?></time></td>
        <?php if ($isAdmin): ?>
        <td><?php if ($p['access']['groups'] + $p['access']['users'] === 0): ?><span class="tag tag--inactive"><?= $e($t('projects.access_admin_only')) ?></span><?php else: ?><?= $e($t('projects.access_summary', $p['access'])) ?><?php endif; ?></td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <nav class="pagination" aria-label="<?= $e($t('projects.pagination')) ?>">
    <?php if ($pageNo > 1): ?><a class="btn btn--small" href="<?= $e($assets->page('projects?page=' . ($pageNo - 1))) ?>"><?= $e($t('icon.ui.prev')) ?></a><?php endif; ?>
    <span class="pagination__info"><?= $e($t('component.page_of', ['page' => $pageNo, 'pages' => $pages])) ?></span>
    <?php if ($pageNo < $pages): ?><a class="btn btn--small" href="<?= $e($assets->page('projects?page=' . ($pageNo + 1))) ?>"><?= $e($t('icon.ui.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
<?php endif; ?>
