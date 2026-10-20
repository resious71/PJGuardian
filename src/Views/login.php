<?php
/**
 * 로그인 화면(비로그인 레이아웃). HTML 폼 POST /login, 숨은 필드 _csrf.
 * 오류 문구는 계정 존재 여부를 드러내지 않는 한 가지다(명세 10, P10 금지 사항).
 *
 * @var string $loginId 다시 채울 로그인 ID(비밀번호는 다시 채우지 않음)
 * @var string $next 로그인 후 돌아갈 앱 안 경로(검사를 통과한 값만)
 * @var string|null $notice 안내(세션 만료, 로그아웃)
 * @var string|null $error 오류 문장
 * @var array{kind:string, text:string, code:string}|null $account P28B 외부 인증을 통과했지만 쓸 수 없는 계정(승인 대기·거절·요청 받지 않음) 안내
 * @var list<array{id:string, label:string, href:string}> $ssoProviders P28B SSO 단추 자리(P28C OIDC, P28D SAML). href는 배포 경로 포함
 * @var string $csrfToken
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<section class="card auth-card">
  <h1 class="auth-card__title"><?= $e($t('login.title')) ?></h1>
  <?php if ($notice !== null): ?>
  <p class="notice notice--info" role="status"><?= $icons->withLabel('ui.info', 16, $notice) ?></p>
  <?php endif; ?>
  <?php if ($account !== null): ?>
  <p class="notice notice--<?= $e($account['kind']) ?>" role="status" id="login-account" data-account-state="<?= $e($account['code']) ?>"><?= $icons->withLabel($account['kind'] === 'info' ? 'ui.info' : 'system.warn', 16, $account['text']) ?></p>
  <?php endif; ?>
  <?php if ($error !== null): ?>
  <p class="notice notice--error" role="alert" id="login-error"><?= $icons->withLabel('system.error', 16, $error) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= $e($assets->page('login')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <?php if ($next !== ''): ?>
    <input type="hidden" name="next" value="<?= $e($next) ?>">
    <?php endif; ?>
    <div class="field">
      <label class="field__label" for="login-id"><?= $e($t('login.login_id')) ?></label>
      <input class="field__input" id="login-id" name="login_id" value="<?= $e($loginId) ?>" autocomplete="username" required
             autofocus<?= $error !== null ? ' aria-describedby="login-error"' : '' ?>>
    </div>
    <div class="field">
      <label class="field__label" for="login-password"><?= $e($t('login.password')) ?></label>
      <input class="field__input" id="login-password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <button type="submit" class="btn btn--primary btn--block"><?= $e($t('login.submit')) ?></button>
  </form>
  <?php if ($ssoProviders !== []): ?>
  <div class="auth-card__sso" data-sso>
    <p class="muted auth-card__sso-title"><?= $e($t('login.sso_title')) ?></p>
    <?php foreach ($ssoProviders as $p): ?>
    <a class="btn btn--block" href="<?= $e($p['href']) ?>" data-sso-provider="<?= $e($p['id']) ?>"><?= $e($p['label']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <p class="muted auth-card__help"><?= $e($t('login.help')) ?></p>
</section>
