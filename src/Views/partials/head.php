<?php
/**
 * 공통 <head>. 인라인 스크립트·스타일 없이 파일만 불러온다(CSP).
 * meta: csrf-token(P10에서 채움), app-timezone(표시 시간대), app-base(앱 경로 접두사), icon-sprite(JS 아이콘용), app-env,
 *       notification-poll(P24: 로그인 화면만, 알림 개수 갱신 주기 초),
 *       csp-nonce(P17: 요청마다 새 값. CodeMirror가 넣는 <style>에 붙여 CSP style-src를 통과시킨다. 스크립트에는 쓰지 않는다)
 *
 * @var array $page
 * @var callable $e
 * @var \PJGuardian\Support\Assets $assets
 * @var string $timezone
 * @var string $appEnv
 * @var string $csrfToken
 * @var string $cspNonce
 * @var int $notificationPoll
 */
?>
<meta charset="utf-8">
<title><?= $e($page['title']) ?> · PJGuardian</title>
<meta name="csrf-token" content="<?= $e($csrfToken ?? '') ?>">
<meta name="csp-nonce" content="<?= $e($cspNonce ?? '') ?>">
<meta name="app-timezone" content="<?= $e($timezone) ?>">
<meta name="app-base" content="<?= $e($assets->basePath()) ?>">
<meta name="app-env" content="<?= $e($appEnv) ?>">
<?php if (isset($page['user'])): ?>
<meta name="notification-poll" content="<?= $e((string) ($notificationPoll ?? 60)) ?>">
<?php endif; ?>
<meta name="icon-sprite" content="<?= $e($assets->url('icons/sprite.svg')) ?>">
<link rel="icon" type="image/svg+xml" href="<?= $e($assets->url('icons/custom/pjg-logo.svg')) ?>">
<link rel="stylesheet" href="<?= $e($assets->url('css/tokens.css')) ?>">
<link rel="stylesheet" href="<?= $e($assets->url('css/base.css')) ?>">
<link rel="stylesheet" href="<?= $e($assets->url('css/components.css')) ?>">
<script type="module" src="<?= $e($assets->url('js/app.js')) ?>"></script>
<?php foreach ($page['scripts'] ?? [] as $script): ?>
<script type="module" src="<?= $e($assets->url($script)) ?>"></script>
<?php endforeach; ?>
