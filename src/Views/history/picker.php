<?php
/**
 * "히스토리" 메뉴(P21): 볼 수 있는 프로젝트를 고르면 그 프로젝트의 히스토리(/projects/{id}/history). 결함 메뉴의 선택 화면과 같은 규칙
 * (보이는 프로젝트만, 프로젝트가 하나여도 바로 이동하지 않고 목록을 보인다).
 *
 * @var list<array<string, mixed>> $projects
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
  <h1><?= $icons->withLabel('nav.history', 24, $t('history.picker_title')) ?></h1>
  <p class="muted"><?= $e($t('history.picker_intro')) ?></p>
</div>
<section class="card">
  <?php if ($projects === []): ?>
  <?= $view->partial('partials/empty_state', ['icon' => 'nav.projects', 'title' => $t('defects.picker_empty_title'), 'body' => $t('defects.picker_empty_body'), 'actionLabel' => null, 'actionHref' => null]) ?>
  <?php else: ?>
  <table class="table" data-history-projects>
    <thead><tr>
      <th scope="col"><?= $e($t('defects.picker_col_project')) ?></th>
      <th scope="col"><?= $e($t('defects.picker_col_language')) ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($projects as $p): ?>
      <tr>
        <td><a href="<?= $e($assets->page('projects/' . (int) $p['id'] . '/history')) ?>"><?= $e($p['name']) ?></a></td>
        <td><?= $view->partial('partials/lang_badge', ['language' => $p['language']]) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
  <nav class="pagination" aria-label="<?= $e($t('defects.picker_pagination')) ?>">
    <?php if ($pageNo > 1): ?><a class="btn btn--small" href="<?= $e($assets->page('history?page=' . ($pageNo - 1))) ?>"><?= $e($t('icon.ui.prev')) ?></a><?php endif; ?>
    <span class="pagination__info"><?= $e($t('component.page_of', ['page' => $pageNo, 'pages' => $pages])) ?></span>
    <?php if ($pageNo < $pages): ?><a class="btn btn--small" href="<?= $e($assets->page('history?page=' . ($pageNo + 1))) ?>"><?= $e($t('icon.ui.next')) ?></a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</section>
