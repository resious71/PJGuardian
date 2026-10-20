<?php
/**
 * 그룹 삭제 확인(admin, 명세 3.1·20.4). 구성원 수와 매핑된 프로젝트 수를 보여 주고, 그룹 이름을 정확히 입력해야 버튼이 켜진다.
 * 사용자 계정은 지우지 않고 소속만 사라진다. 서버가 이름을 다시 확인한다.
 *
 * @var array<string, mixed> $group
 * @var array{members:int, projects:int} $info
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$id = (int) $group['id'];
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('groups')) ?>"><?= $e($t('groups.title')) ?></a></nav>
<div class="page-header">
  <h1><?= $e($t('groups.delete_title', ['name' => $group['name']])) ?></h1>
</div>
<section class="card danger-zone" aria-labelledby="gdelete-warning">
  <div class="danger-zone__head">
    <?= $icons->meaningful('ui.danger', 24) ?>
    <h2 id="gdelete-warning"><?= $e($t('groups.delete_warning')) ?></h2>
  </div>
  <ul class="danger-zone__facts">
    <li><?= $e($t('groups.delete_fact_members', ['n' => $info['members']])) ?></li>
    <li><?= $e($t('groups.delete_fact_projects', ['n' => $info['projects']])) ?></li>
  </ul>
  <form id="group-delete" novalidate data-group-id="<?= $id ?>" data-confirm-name="<?= $e($group['name']) ?>" data-done-url="<?= $e($assets->page('groups')) ?>">
    <div class="field">
      <label class="field__label" for="gd-name"><?= $e($t('groups.delete_type_name')) ?> <code class="confirm-name"><?= $e($group['name']) ?></code></label>
      <input class="field__input" id="gd-name" name="confirm_name" autocomplete="off" spellcheck="false" aria-describedby="gd-help">
      <p class="field__help" id="gd-help"><?= $e($t('projects.delete_type_help')) ?></p>
    </div>
    <p class="field__error" id="gd-error" role="alert" hidden></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--danger" id="group-delete-submit" disabled><?= $icons->icon('action.delete', 16) ?> <?= $e($t('groups.delete_submit')) ?></button>
      <a class="btn" href="<?= $e($assets->page('groups/' . $id)) ?>"><?= $e($t('component.cancel')) ?></a>
    </div>
  </form>
</section>
