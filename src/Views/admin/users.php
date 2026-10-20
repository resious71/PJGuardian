<?php
/**
 * 사용자 관리(관리자 전용, 명세 13). 서버는 틀만 그리고, 목록과 동작은 pages/admin_users.js가 API로 처리한다.
 * 파괴적 동작(비활성화, 임시 비밀번호 발급, 관리자 강등)은 확인 대화상자를 거친다(명세 20.4).
 *
 * @var int $tempHours 임시 비밀번호 유효 시간
 * @var string $policyText
 * @var int $selfId 현재 관리자 id(본인 비밀번호는 이 화면에서 바꾸지 않음)
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.users', 24, $t('users.title')) ?></h1>
  <p class="muted"><?= $e($t('users.intro')) ?></p>
  <?php /* P28A: CSV로 계정 일괄 생성·비활성화(admin 전용 화면) */ ?>
  <p><a class="btn" href="<?= $e($assets->page('admin/users/import')) ?>" data-import-link><?= $icons->withLabel('action.upload', 16, $t('account_import.open')) ?></a></p>
</div>

<section class="card" aria-labelledby="users-create-title">
  <h2 id="users-create-title"><?= $e($t('users.create_title')) ?></h2>
  <form id="user-create" class="form-grid" novalidate>
    <div class="field">
      <label class="field__label" for="uc-login"><?= $e($t('users.login_id')) ?> <span class="field__required" aria-hidden="true">*</span></label>
      <input class="field__input" id="uc-login" name="login_id" required maxlength="100" autocomplete="off" aria-describedby="uc-login-help">
      <p class="field__help" id="uc-login-help"><?= $e($t('users.login_id_help')) ?></p>
    </div>
    <div class="field">
      <label class="field__label" for="uc-name"><?= $e($t('users.display_name')) ?> <span class="field__required" aria-hidden="true">*</span></label>
      <input class="field__input" id="uc-name" name="display_name" required maxlength="100" autocomplete="off" aria-describedby="uc-name-error">
      <p class="field__error" id="uc-name-error" hidden></p>
    </div>
    <div class="field">
      <label class="field__label" for="uc-email"><?= $e($t('users.email')) ?></label>
      <input class="field__input" id="uc-email" name="email" type="email" maxlength="254" autocomplete="off" aria-describedby="uc-email-error">
      <p class="field__error" id="uc-email-error" hidden></p>
    </div>
    <div class="field">
      <label class="field__label" for="uc-role"><?= $e($t('users.role')) ?></label>
      <select class="field__input" id="uc-role" name="role">
        <option value="user"><?= $e($t('layout.role_user')) ?></option>
        <option value="admin"><?= $e($t('layout.role_admin')) ?></option>
      </select>
    </div>
    <p class="field__error" id="uc-error" role="alert" hidden></p>
    <div class="form-grid__actions">
      <button type="submit" class="btn btn--primary"><?= $e($t('users.create_submit')) ?></button>
      <span class="muted"><?= $e($t('users.create_help', ['hours' => $tempHours])) ?></span>
    </div>
  </form>
</section>

<section class="card" aria-labelledby="users-list-title">
  <h2 id="users-list-title"><?= $e($t('users.list_title')) ?></h2>
  <form id="user-filter" class="toolbar" role="search">
    <label class="sr-only" for="uf-q"><?= $e($t('users.search')) ?></label>
    <input class="field__input toolbar__search" id="uf-q" name="q" type="search" placeholder="<?= $e($t('users.search_placeholder')) ?>">
    <label class="sr-only" for="uf-role"><?= $e($t('users.role')) ?></label>
    <select class="field__input" id="uf-role" name="role">
      <option value=""><?= $e($t('users.all_roles')) ?></option>
      <option value="admin"><?= $e($t('layout.role_admin')) ?></option>
      <option value="user"><?= $e($t('layout.role_user')) ?></option>
    </select>
    <label class="sr-only" for="uf-status"><?= $e($t('users.status')) ?></label>
    <select class="field__input" id="uf-status" name="status">
      <option value=""><?= $e($t('users.all_statuses')) ?></option>
      <option value="active"><?= $e($t('users.status_active')) ?></option>
      <option value="inactive"><?= $e($t('users.status_inactive')) ?></option>
    </select>
    <?php /* P28B: 보기 — 계정(승인됨) / 승인 대기 / 거절됨(외부 인증 첫 로그인) */ ?>
    <label class="sr-only" for="uf-approval"><?= $e($t('users.approval_view')) ?></label>
    <select class="field__input" id="uf-approval" name="approval">
      <option value=""><?= $e($t('users.approval_view_accounts')) ?></option>
      <option value="pending"><?= $e($t('users.approval_pending')) ?></option>
      <option value="rejected"><?= $e($t('users.approval_rejected')) ?></option>
    </select>
    <button type="submit" class="btn"><?= $icons->withLabel('action.search', 16) ?></button>
  </form>
  <p class="notice notice--warn users-pending" id="users-pending" role="status" hidden></p>
  <table class="table" id="users-table" data-self-id="<?= (int) $selfId ?>" data-policy="<?= $e($policyText) ?>">
    <thead><tr>
      <th scope="col"><?= $e($t('users.login_id')) ?></th>
      <th scope="col"><?= $e($t('users.display_name')) ?></th>
      <th scope="col"><?= $e($t('users.email')) ?></th>
      <th scope="col"><?= $e($t('users.role')) ?></th>
      <th scope="col"><?= $e($t('users.status')) ?></th>
      <th scope="col"><?= $e($t('account.auth_type')) ?></th>
      <th scope="col"><?= $e($t('users.groups')) ?></th>
      <th scope="col"><?= $e($t('users.account_state')) ?></th>
      <th scope="col"><?= $e($t('users.actions')) ?></th>
    </tr></thead>
    <tbody id="users-body"></tbody>
  </table>
  <div id="users-empty" hidden>
    <?= $view->partial('partials/empty_state', ['icon' => 'ui.empty', 'title' => $t('users.empty_title'), 'body' => $t('users.empty_body'), 'actionLabel' => null, 'actionHref' => null]) ?>
  </div>
  <nav class="pagination" id="users-pagination" aria-label="<?= $e($t('users.pagination')) ?>"></nav>
</section>
