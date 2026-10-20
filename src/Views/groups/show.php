<?php
/**
 * 그룹 상세(명세 3.1): 구성원 표와 그룹 관리자의 세 가지 동작(구성원 추가·제거, 관리자 지정·해제, 계정 생성).
 * 그룹 관리자 표시는 아이콘 + 텍스트. 자기 자신의 제거·해제 버튼은 숨기고 이유를 도움말로 보여 준다.
 * 동작은 pages/group_detail.js가 API로 보내고, 서버가 권한과 대상 상태를 다시 확인한다.
 *
 * @var array<string, mixed> $group
 * @var list<array<string, mixed>> $members
 * @var int $selfId
 * @var bool $isAdmin
 * @var int $tempHours
 * @var string $policyText
 * @var string $timezone
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$id = (int) $group['id'];
$fmt = static fn (string $iso): string => (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
$selfIsMember = in_array($selfId, array_column($members, 'user_id'), true);
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('groups')) ?>"><?= $e($t('groups.title')) ?></a></nav>
<div class="page-header page-header--actions">
  <div>
    <h1><?= $e($group['name']) ?></h1>
    <?php if ($group['description'] !== ''): ?><p class="project-description"><?= $e($group['description']) ?></p><?php endif; ?>
  </div>
  <?php if ($isAdmin): ?>
  <div class="toolbar">
    <a class="btn" href="<?= $e($assets->page('groups/' . $id . '/edit')) ?>"><?= $e($t('groups.edit_button')) ?></a>
    <a class="btn btn--danger-outline" href="<?= $e($assets->page('groups/' . $id . '/delete')) ?>"><?= $icons->withLabel('action.delete', 16, $t('groups.delete_button')) ?></a>
  </div>
  <?php endif; ?>
</div>

<section class="card" aria-labelledby="members-title" id="group-detail" data-group-id="<?= $id ?>" data-self-id="<?= $selfId ?>" data-policy="<?= $e($policyText) ?>" data-temp-hours="<?= $tempHours ?>">
  <div class="card__head">
    <h2 id="members-title"><?= $e($t('groups.members_title', ['n' => count($members)])) ?></h2>
    <div class="toolbar">
      <button type="button" class="btn" data-action="add-member"><?= $icons->withLabel('action.add_member', 16) ?></button>
      <button type="button" class="btn btn--primary" data-action="create-account"><?= $icons->withLabel('action.create_account', 16) ?></button>
    </div>
  </div>
  <?php if ($selfIsMember && !$isAdmin): ?>
  <p class="muted help-note"><?= $e($t('groups.self_help')) ?></p>
  <?php endif; ?>
  <?php if ($members === []): ?>
  <?= $view->partial('partials/empty_state', ['icon' => 'ui.empty', 'title' => $t('groups.no_members_title'), 'body' => $t('groups.no_members_body'), 'actionLabel' => null, 'actionHref' => null]) ?>
  <?php else: ?>
  <table class="table" id="members-table">
    <thead><tr>
      <th scope="col"><?= $e($t('users.login_id')) ?></th>
      <th scope="col"><?= $e($t('users.display_name')) ?></th>
      <th scope="col"><?= $e($t('groups.col_role')) ?></th>
      <th scope="col"><?= $e($t('users.status')) ?></th>
      <th scope="col"><?= $e($t('groups.col_added')) ?></th>
      <th scope="col"><?= $e($t('users.actions')) ?></th>
    </tr></thead>
    <tbody>
      <?php foreach ($members as $m): $isSelf = $m['user_id'] === $selfId; ?>
      <tr class="<?= $m['active'] ? '' : 'is-inactive' ?>" data-user-id="<?= (int) $m['user_id'] ?>" data-login="<?= $e($m['login_id']) ?>" data-name="<?= $e($m['display_name']) ?>">
        <td><code><?= $e($m['login_id']) ?></code></td>
        <td><?= $e($m['display_name']) ?></td>
        <td><?= $m['is_manager'] ? '<span class="tag tag--admin">' . $icons->withLabel('ui.group_manager', 16) . '</span>' : $e($t('groups.role_member')) ?></td>
        <td><span class="tag tag--<?= $m['active'] ? 'active' : 'inactive' ?>"><?= $e($t($m['active'] ? 'users.status_active' : 'users.status_inactive')) ?></span></td>
        <td><time datetime="<?= $e($m['added_at']) ?>"><?= $e($fmt($m['added_at'])) ?></time></td>
        <td>
          <div class="row-actions">
            <?php if (!$isSelf || $isAdmin): ?>
              <?php if ($m['is_manager']): ?>
              <button type="button" class="btn btn--small" data-action="unset-manager"><?= $e($t('groups.action_unset_manager')) ?></button>
              <?php else: ?>
              <button type="button" class="btn btn--small" data-action="set-manager"><?= $e($t('groups.action_set_manager')) ?></button>
              <?php endif; ?>
              <button type="button" class="btn btn--small btn--danger-outline" data-action="remove-member"><?= $icons->withLabel('action.remove_member', 16) ?></button>
            <?php else: ?>
              <span class="muted"><?= $e($t('groups.self_row')) ?></span>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
