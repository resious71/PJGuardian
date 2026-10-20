<?php
/**
 * 비밀번호 변경(로그인 후 레이아웃). 임시 비밀번호로 로그인했으면 이 화면 외에는 쓸 수 없다(명세 3, 13).
 *
 * @var bool $forced 임시 비밀번호 상태
 * @var bool $external 외부 인증 계정(P28B — 비밀번호는 조직의 디렉터리에서 바꾼다, 폼 없음)
 * @var string|null $error
 * @var string $policyText 비밀번호 정책 안내
 * @var string $csrfToken
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<div class="page-header">
  <h1><?= $e($t('password.title')) ?></h1>
</div>
<section class="card">
  <?php if ($external): ?>
  <p class="notice notice--info" role="status" id="password-external"><?= $icons->withLabel('ui.info', 16, $t('password.external_note')) ?></p>
</section>
  <?php return; endif; ?>
  <?php if ($forced): ?>
  <p class="notice notice--warn" role="status"><?= $icons->withLabel('system.warn', 16, $t('password.forced')) ?></p>
  <?php endif; ?>
  <?php if ($error !== null): ?>
  <p class="notice notice--error" role="alert" id="password-error"><?= $icons->withLabel('system.error', 16, $error) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= $e($assets->page('password')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="field">
      <label class="field__label" for="pw-current"><?= $e($t($forced ? 'password.current_temp' : 'password.current')) ?></label>
      <input class="field__input" id="pw-current" name="current_password" type="password" autocomplete="current-password" required autofocus>
    </div>
    <div class="field">
      <label class="field__label" for="pw-new"><?= $e($t('password.new')) ?></label>
      <input class="field__input" id="pw-new" name="new_password" type="password" autocomplete="new-password" required aria-describedby="pw-policy">
      <p class="field__help" id="pw-policy"><?= $e($policyText) ?></p>
    </div>
    <div class="field">
      <label class="field__label" for="pw-confirm"><?= $e($t('password.confirm')) ?></label>
      <input class="field__input" id="pw-confirm" name="new_password_confirm" type="password" autocomplete="new-password" required>
    </div>
    <button type="submit" class="btn btn--primary"><?= $e($t('password.submit')) ?></button>
  </form>
</section>
