<?php
/**
 * 내 정보(X05, 명세 19.10). 로그인 ID·역할·인증 방식은 읽기 전용, 표시 이름·이메일은 pages/account.js가 PATCH /api/v1/users/me로 저장한다.
 * LDAP 계정은 입력을 읽기 전용으로 두고 이유를 안내한다. 비밀번호 변경은 기존 화면(/password)으로 연결만 한다.
 *
 * @var array{id:int, login_id:string, display_name:string, email:?string, role:string, auth_type:string, editable:bool} $me
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$readonly = !$me['editable'];
?>
<div class="page-header">
  <h1><?= $icons->withLabel('ui.user', 24, $t('account.title')) ?></h1>
  <p class="muted"><?= $e($t('account.intro')) ?></p>
</div>

<section class="card" aria-labelledby="account-info-title">
  <h2 id="account-info-title"><?= $e($t('account.info_title')) ?></h2>
  <dl class="detail-list">
    <dt><?= $e($t('users.login_id')) ?></dt><dd><code><?= $e($me['login_id']) ?></code></dd>
    <dt><?= $e($t('users.role')) ?></dt><dd><?= $e($t($me['role'] === 'admin' ? 'layout.role_admin' : 'layout.role_user')) ?></dd>
    <dt><?= $e($t('account.auth_type')) ?></dt><dd><?= $e($t('account.auth_' . (in_array($me['auth_type'], ['local', 'ldap', 'oidc', 'saml'], true) ? $me['auth_type'] : 'local'))) ?></dd>
  </dl>
</section>

<section class="card" aria-labelledby="account-edit-title">
  <h2 id="account-edit-title"><?= $e($t('account.edit_title')) ?></h2>
  <?php if ($readonly): ?>
  <p class="notice notice--info" id="account-ldap-note"><?= $icons->withLabel('ui.info', 16, $t('account.ldap_readonly')) ?></p>
  <?php endif; ?>
  <form id="account-form" novalidate data-readonly="<?= $readonly ? '1' : '0' ?>">
    <div class="field">
      <label class="field__label" for="account-name"><?= $e($t('users.display_name')) ?> <span class="field__required" aria-hidden="true">*</span></label>
      <input class="field__input" id="account-name" name="display_name" required maxlength="100" autocomplete="name"
             value="<?= $e($me['display_name']) ?>" aria-describedby="account-name-help account-name-error"<?= $readonly ? ' readonly aria-readonly="true"' : '' ?>>
      <p class="field__help" id="account-name-help"><?= $e($t('account.name_help')) ?></p>
      <p class="field__error" id="account-name-error" hidden></p>
    </div>
    <div class="field">
      <label class="field__label" for="account-email"><?= $e($t('users.email')) ?></label>
      <input class="field__input" id="account-email" name="email" type="email" maxlength="254" autocomplete="email"
             value="<?= $e((string) ($me['email'] ?? '')) ?>" aria-describedby="account-email-help account-email-error"<?= $readonly ? ' readonly aria-readonly="true"' : '' ?>>
      <p class="field__help" id="account-email-help"><?= $e($t('account.email_help')) ?></p>
      <p class="field__error" id="account-email-error" hidden></p>
    </div>
    <p class="field__error" id="account-error" role="alert" hidden></p>
    <div class="toolbar">
      <?php if (!$readonly): ?>
      <button type="submit" class="btn btn--primary" id="account-save"><?= $e($t('account.save')) ?></button>
      <?php endif; ?>
      <p class="muted" id="account-status" role="status" aria-live="polite"></p>
    </div>
  </form>
  <?php if ($me['auth_type'] === 'local'): ?>
  <p class="field__help"><a href="<?= $e($assets->page('password')) ?>"><?= $e($t('account.password_link')) ?></a></p>
  <?php else: ?>
  <p class="field__help"><?= $e($t('password.external_note')) ?></p>
  <?php endif; ?>
</section>
