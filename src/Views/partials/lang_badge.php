<?php
/**
 * 프로젝트 언어 텍스트 배지(Py, JS/TS). 아이콘(언어 배지) + 짧은 텍스트, 전체 이름은 title과 접근성 라벨로.
 * 사용: $view->partial('partials/lang_badge', ['language' => 'python'])
 *
 * @var string $language python | javascript
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
$name = $language === 'javascript' ? 'language.javascript' : 'language.python';
?>
<span class="lang-badge lang-badge--<?= $e($language) ?>" title="<?= $e($icons->label($name)) ?>" aria-label="<?= $e($icons->aria($name)) ?>" role="img"><?= $icons->icon($name, 16) ?><span aria-hidden="true"><?= $e($t('projects.lang_short.' . ($language === 'javascript' ? 'javascript' : 'python'))) ?></span></span>
