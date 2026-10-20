<?php
/**
 * "결함" 메뉴(P17): 볼 수 있는 프로젝트를 고르면 프로젝트 상세의 "결함" 탭(같은 목록 화면)으로 간다.
 *
 * @var list<array<string, mixed>> $projects
 * @var array<int, array{open:int, total:int}> $counts
 * @var int $pageNo
 * @var int $pages
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('nav.defects', 24, $t('defects.picker_title')) ?></h1>
  <p class="muted"><?= $e($t('defects.picker_intro')) ?></p>
</div>
<?php if ($projects === []): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.projects',
      'title' => $t('defects.picker_empty_title'),
      'body' => $t('defects.picker_empty_body'),
      'actionLabel' => null,
      'actionHref' => null,
  ]) ?>
</section>
<?php else: ?>
<section class="card">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= $e($t('defects.picker_col_project')) ?></th>
      <th scope="col"><?= $e($t('defects.picker_col_language')) ?></th>
      <th scope="col"><?= $e($t('defects.picker_col_open')) ?></th>
      <th scope="col"><?= $e($t('defects.picker_col_total')) ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($projects as $p): $c = $counts[(int) $p['id']] ?? ['open' => 0, 'total' => 0]; ?>
      <tr>
        <td><a href="<?= $e($assets->page('projects/' . (int) $p['id'])) ?>"><?= $e($p['name']) ?></a></td>
        <td><?= $view->partial('partials/lang_badge', ['language' => $p['language']]) ?></td>
        <td><?= (int) $c['open'] ?></td>
        <td><?= (int) $c['total'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <nav class="pagination" aria-label="<?= $e($t('defects.picker_pagination')) ?>">
    <?php if ($pageNo > 1): ?><a class="btn btn--small" href="<?= $e($assets->page('defects?page=' . ($pageNo - 1))) ?>"><?= $e($t('icon.ui.prev')) ?></a><?php endif; ?>
    <span class="pagination__info"><?= $e($t('component.page_of', ['page' => $pageNo, 'pages' => $pages])) ?></span>
    <?php if ($pageNo < $pages): ?><a class="btn btn--small" href="<?= $e($assets->page('defects?page=' . ($pageNo + 1))) ?>"><?= $e($t('icon.ui.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
</section>
<?php endif; ?>
