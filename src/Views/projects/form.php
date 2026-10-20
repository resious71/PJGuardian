<?php
/**
 * 프로젝트 생성·수정 폼(관리자). 언어는 생성 때만 고르고 수정 화면에서는 읽기 전용(명세 4장: 변경 불가).
 * 제외 패턴: 서버 기본 목록은 읽기 전용으로 함께 보이고, 추가 패턴은 한 줄에 하나(문법은 도움말, docs/architecture.md).
 * 제출은 pages/project_form.js가 API(POST /api/v1/projects, PATCH /api/v1/projects/{id})로 보낸다.
 *
 * @var array<string, mixed>|null $project null이면 생성
 * @var list<string> $defaults
 * @var array{groups: list<array<string, mixed>>, users: list<array<string, mixed>>, viewer_count: int}|null $access 수정 화면만(접근 권한 영역)
 * @var list<array<string, mixed>>|null $allGroups 수정 화면만(그룹 추가 선택 상자)
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$isNew = $project === null;
$back = $isNew ? $assets->page('projects') : $assets->page('projects/' . (int) $project['id']);
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('projects')) ?>"><?= $e($t('projects.title')) ?></a></nav>
<div class="page-header">
  <h1><?= $e($isNew ? $t('projects.new_title') : $t('projects.edit_title', ['name' => $project['name']])) ?></h1>
</div>
<section class="card">
  <form id="project-form" novalidate
        data-mode="<?= $isNew ? 'create' : 'update' ?>"
        data-project-id="<?= $isNew ? '' : (int) $project['id'] ?>"
        data-detail-base="<?= $e($assets->page('projects')) ?>">
    <div class="field">
      <label class="field__label" for="pf-name"><?= $e($t('projects.field_name')) ?> <span class="field__required" aria-hidden="true">*</span></label>
      <input class="field__input" id="pf-name" name="name" required maxlength="100" value="<?= $e($isNew ? '' : $project['name']) ?>" autocomplete="off">
    </div>
    <div class="field">
      <span class="field__label" id="pf-lang-label"><?= $e($t('projects.field_language')) ?><?= $isNew ? ' <span class="field__required" aria-hidden="true">*</span>' : '' ?></span>
      <?php if ($isNew): ?>
      <div class="radio-group" role="radiogroup" aria-labelledby="pf-lang-label">
        <label class="radio"><input type="radio" name="language" value="python" required> <?= $icons->withLabel('language.python', 16) ?></label>
        <label class="radio"><input type="radio" name="language" value="javascript"> <?= $icons->withLabel('language.javascript', 16) ?></label>
      </div>
      <p class="field__help"><?= $e($t('projects.language_help')) ?></p>
      <?php else: ?>
      <p class="readonly-value" aria-labelledby="pf-lang-label"><?= $view->partial('partials/lang_badge', ['language' => $project['language']]) ?> <span class="muted"><?= $e($t('projects.language_locked')) ?></span></p>
      <?php endif; ?>
    </div>
    <div class="field">
      <label class="field__label" for="pf-desc"><?= $e($t('projects.field_description')) ?></label>
      <textarea class="field__input" id="pf-desc" name="description" rows="3" maxlength="2000"><?= $e($isNew ? '' : $project['description']) ?></textarea>
    </div>
    <?php if (!$isNew): ?>
    <fieldset class="field field--wide">
      <legend class="field__label"><?= $e($t('projects.excludes_title')) ?></legend>
      <p class="field__help"><?= $e($t('projects.excludes_default_help')) ?></p>
      <ul class="chip-list" aria-label="<?= $e($t('projects.excludes_default')) ?>">
        <?php foreach ($defaults as $name): ?><li><code><?= $e($name) ?></code></li><?php endforeach; ?>
      </ul>
      <label class="field__label" for="pf-patterns"><?= $e($t('projects.excludes_custom')) ?></label>
      <textarea class="field__input field__input--mono" id="pf-patterns" name="exclude_patterns" rows="6" spellcheck="false" aria-describedby="pf-pattern-help"><?= $e(implode("\n", $project['exclude_patterns'])) ?></textarea>
      <div class="help-box" id="pf-pattern-help">
        <p><?= $e($t('projects.pattern_help_intro')) ?></p>
        <ul>
          <?php foreach (['star', 'question', 'doublestar', 'anchor', 'dir', 'case', 'reject'] as $k): ?>
          <li><?= $e($t('projects.pattern_help.' . $k)) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </fieldset>
    <?php endif; ?>
    <p class="field__error" id="pf-error" role="alert" hidden></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--primary"><?= $e($isNew ? $t('projects.create_submit') : $t('projects.save_submit')) ?></button>
      <a class="btn" href="<?= $e($back) ?>"><?= $e($t('component.cancel')) ?></a>
    </div>
    <?php if ($isNew): ?>
    <p class="muted"><?= $e($t('projects.excludes_after_create')) ?></p>
    <?php endif; ?>
  </form>
</section>
<?php if (!$isNew): ?>
<?php
$mappedGroupIds = array_column($access['groups'], 'group_id');
$addable = array_values(array_filter($allGroups ?? [], static fn (array $g): bool => !in_array((int) $g['id'], $mappedGroupIds, true)));
$noMapping = $access['groups'] === [] && $access['users'] === [];
?>
<section class="card" id="project-access" aria-labelledby="pa-title" data-project-id="<?= (int) $project['id'] ?>">
  <h2 id="pa-title"><?= $e($t('access.title')) ?></h2>
  <p class="muted"><?= $e($t('access.intro')) ?></p>
  <?php if ($noMapping): ?>
  <div class="notice notice--warn access-warning" role="status"><?= $icons->icon('ui.info', 16) ?> <?= $e($t('access.none_warning')) ?></div>
  <?php else: ?>
  <p class="access-count" data-viewer-count="<?= (int) $access['viewer_count'] ?>"><?= $e($t('access.viewer_count', ['n' => (int) $access['viewer_count']])) ?></p>
  <?php endif; ?>

  <h3 class="subhead"><?= $e($t('access.groups_title')) ?></h3>
  <?php if ($access['groups'] === []): ?>
  <p class="muted"><?= $e($t('access.groups_none')) ?></p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th scope="col"><?= $e($t('groups.col_name')) ?></th><th scope="col"><?= $e($t('groups.col_members')) ?></th><th scope="col"><?= $e($t('users.actions')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($access['groups'] as $g): ?>
      <tr>
        <td><?= $icons->icon('nav.groups', 16) ?> <?= $e($g['name']) ?></td>
        <td><?= $e($t('groups.count', ['n' => $g['member_count']])) ?></td>
        <td><button type="button" class="btn btn--small btn--danger-outline" data-action="remove" data-type="group" data-id="<?= (int) $g['group_id'] ?>" data-name="<?= $e($g['name']) ?>"><?= $e($t('access.remove')) ?></button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="toolbar">
    <label class="sr-only" for="pa-group"><?= $e($t('access.group_select')) ?></label>
    <select class="field__input" id="pa-group"<?= $addable === [] ? ' disabled' : '' ?>>
      <option value=""><?= $e($t($addable === [] ? 'access.group_select_empty' : 'access.group_select')) ?></option>
      <?php foreach ($addable as $g): ?>
      <option value="<?= (int) $g['id'] ?>"><?= $e($g['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="button" class="btn" data-action="add-group"<?= $addable === [] ? ' disabled' : '' ?>><?= $e($t('access.add_group')) ?></button>
  </div>

  <h3 class="subhead"><?= $e($t('access.users_title')) ?></h3>
  <?php if ($access['users'] === []): ?>
  <p class="muted"><?= $e($t('access.users_none')) ?></p>
  <?php else: ?>
  <table class="table">
    <thead><tr><th scope="col"><?= $e($t('users.login_id')) ?></th><th scope="col"><?= $e($t('users.display_name')) ?></th><th scope="col"><?= $e($t('users.status')) ?></th><th scope="col"><?= $e($t('users.actions')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($access['users'] as $u): ?>
      <tr class="<?= $u['active'] ? '' : 'is-inactive' ?>">
        <td><code><?= $e($u['login_id']) ?></code></td>
        <td><?= $e($u['display_name']) ?></td>
        <td><span class="tag tag--<?= $u['active'] ? 'active' : 'inactive' ?>"><?= $e($t($u['active'] ? 'users.status_active' : 'users.status_inactive')) ?></span></td>
        <td><button type="button" class="btn btn--small btn--danger-outline" data-action="remove" data-type="user" data-id="<?= (int) $u['user_id'] ?>" data-name="<?= $e($u['login_id']) ?>"><?= $e($t('access.remove')) ?></button></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  <div class="toolbar">
    <button type="button" class="btn" data-action="add-user"><?= $icons->withLabel('action.add_member', 16, $t('access.add_user')) ?></button>
  </div>
</section>
<?= $view->partial('projects/_repo_form', ['project' => $project]) ?>
<?= $view->partial('projects/_schedule_form', ['project' => $project]) ?>
<?php endif; ?>
