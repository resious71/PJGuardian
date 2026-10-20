<?php
/**
 * 비로그인 레이아웃(로그인 화면 등, 메뉴 없음).
 *
 * @var array{title:string, body_class?:string} $page
 * @var string $content
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 * @var string $appVersion
 */
?>
<!doctype html>
<html lang="<?= $e($uiLang ?? 'ko') ?>"<?= ($uiTheme ?? 'system') !== 'system' ? ' data-theme="' . $e($uiTheme) . '"' : '' ?>>
<head>
<?= $view->partial('partials/head', ['page' => $page]) ?>
</head>
<body class="guest<?= isset($page['body_class']) ? ' ' . $e($page['body_class']) : '' ?>">
<a class="skip-link" href="#main"><?= $e($t('layout.skip_to_content')) ?></a>
<header class="guest__header"><?= $icons->icon('ui.logo', 24) ?><span class="topbar__name">PJGuardian</span><?= $view->partial('partials/ui_switch') ?></header>
<main id="main" class="guest__main" tabindex="-1">
<?= $content ?>
</main>
<footer class="footer"><span><?= $e($t('layout.app_version', ['version' => $appVersion])) ?></span></footer>
<div id="toasts" class="toast-region" role="status" aria-live="polite" aria-label="<?= $e($t('layout.notifications_region')) ?>"></div>
</body>
</html>
