<?php
/**
 * 규칙 상세(P22). pages/rule_detail.js가 /api/v1/rules/{tool}/{rule}로 채운다(설명은 안전한 마크다운 부분집합을 DOM으로, 원시 HTML 없음).
 *
 * @var string $tool ruff | eslint | special(문법 오류)
 * @var string $rule
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var bool $isAdmin P22B: 켜기·끄기 버튼 표시(서버가 다시 확인 — admin 아니면 403)
 */
?>
<?php /* X08: 언어 탭 표시(상세에서는 이동 링크 — 이 규칙의 언어가 선택된 모양, 다른 탭은 그 언어의 목록으로). 특수 키(문법 오류)는 선택 없음 */ ?>
<?= $view->partial('rules/_tabs', ['mode' => 'links', 'current' => \PJGuardian\Support\ToolLanguage::language($tool)]) ?>
<p class="breadcrumb"><a href="<?= $e($assets->page('rules') . (\PJGuardian\Support\ToolLanguage::language($tool) !== null ? '?language=' . \PJGuardian\Support\ToolLanguage::language($tool) : '')) ?>" data-back-to-list><?= $e($t('rules.back_to_list')) ?></a></p>
<article class="rule-detail" data-rule-detail data-tool="<?= $e($tool) ?>" data-rule="<?= $e($rule) ?>" data-admin="<?= !empty($isAdmin) ? '1' : '0' ?>">
  <p class="muted" data-loading><?= $e($t('defects.loading')) ?></p>
  <div data-error-box hidden></div>
  <div data-body hidden></div>
</article>
