<?php
/**
 * 그룹 목록(명세 3.1). admin은 전체와 "새 그룹" 버튼, 그룹 관리자는 자기가 관리하는 그룹만.
 *
 * @var list<array<string, mixed>> $groups
 * @var bool $isAdmin
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header page-header--actions">
  <div>
    <h1><?= $icons->withLabel('nav.groups', 24, $t('groups.title')) ?></h1>
    <p class="muted"><?= $e($t($isAdmin ? 'groups.intro_admin' : 'groups.intro_manager')) ?></p>
  </div>
  <?php if ($isAdmin && $groups !== []): ?>
  <a class="btn btn--primary" href="<?= $e($assets->page('groups/new')) ?>"><?= $e($t('groups.new_button')) ?></a>
  <?php endif; ?>
</div>

<?php if ($groups === []): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'nav.groups',
      'title' => $t('groups.empty_title'),
      'body' => $t('groups.empty_body_admin'),
      'actionLabel' => $isAdmin ? $t('groups.new_button') : null,
      'actionHref' => $isAdmin ? $assets->page('groups/new') : null,
  ]) ?>
</section>
<?php else: ?>
<section class="card">
  <table class="table">
    <thead><tr>
      <th scope="col"><?= $e($t('groups.col_name')) ?></th>
      <th scope="col"><?= $e($t('groups.col_description')) ?></th>
      <th scope="col"><?= $e($t('groups.col_members')) ?></th>
      <th scope="col"><?= $e($t('groups.col_managers')) ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
      <tr>
        <td><a href="<?= $e($assets->page('groups/' . (int) $g['id'])) ?>"><?= $e($g['name']) ?></a></td>
        <td class="cell-clip" title="<?= $e($g['description']) ?>"><?= $e($g['description']) ?></td>
        <td><?= $e($t('groups.count', ['n' => (int) $g['member_count']])) ?></td>
        <td><?= $e($t('groups.count', ['n' => (int) $g['manager_count']])) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
