<?php
/**
 * 그룹 생성·수정 폼(admin). 제출은 pages/groups.js가 API(POST /api/v1/groups, PATCH /api/v1/groups/{id})로.
 *
 * @var array<string, mixed>|null $group null이면 생성
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Assets $assets
 */
$isNew = $group === null;
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('groups')) ?>"><?= $e($t('groups.title')) ?></a></nav>
<div class="page-header">
  <h1><?= $e($isNew ? $t('groups.new_title') : $t('groups.edit_title', ['name' => $group['name']])) ?></h1>
</div>
<section class="card">
  <form id="group-form" novalidate data-group-id="<?= $isNew ? '' : (int) $group['id'] ?>" data-detail-base="<?= $e($assets->page('groups')) ?>">
    <div class="field">
      <label class="field__label" for="gf-name"><?= $e($t('groups.field_name')) ?> <span class="field__required" aria-hidden="true">*</span></label>
      <input class="field__input" id="gf-name" name="name" required maxlength="100" autocomplete="off" value="<?= $e($isNew ? '' : $group['name']) ?>" aria-describedby="gf-name-help">
      <p class="field__help" id="gf-name-help"><?= $e($t('groups.name_help')) ?></p>
    </div>
    <div class="field">
      <label class="field__label" for="gf-desc"><?= $e($t('groups.field_description')) ?></label>
      <textarea class="field__input" id="gf-desc" name="description" rows="3" maxlength="2000"><?= $e($isNew ? '' : $group['description']) ?></textarea>
    </div>
    <p class="field__error" id="gf-error" role="alert" hidden></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--primary"><?= $e($isNew ? $t('groups.create_submit') : $t('projects.save_submit')) ?></button>
      <a class="btn" href="<?= $e($isNew ? $assets->page('groups') : $assets->page('groups/' . (int) $group['id'])) ?>"><?= $e($t('component.cancel')) ?></a>
    </div>
  </form>
</section>
