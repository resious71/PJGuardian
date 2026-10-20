<?php
/**
 * 규칙 설명 언어 탭(X08): Python · ruff / JavaScript/TypeScript · ESLint. 목록은 ARIA 탭(버튼, pages/rules.js가 ui/tabs.js의 initTabList로 연결),
 * 상세는 같은 모양의 이동 링크(그 언어의 목록 /rules?language=…). 탭 머리글의 규칙 수(활성 버전 기준 총수)는 화면 스크립트가 채운다.
 *
 * @var 'tabs'|'links' $mode
 * @var ?string $current python | javascript | null
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Assets $assets
 */
$langs = \PJGuardian\Support\ToolLanguage::TOOL_BY_LANGUAGE; // 언어 → 도구(한 곳)
?>
<div class="tabs rule-tabs" data-rule-tabs>
  <?php if ($mode === 'tabs'): ?>
  <div class="tabs__list" role="tablist" aria-label="<?= $e($t('rules.tabs_label')) ?>">
    <?php foreach ($langs as $lang => $tool): ?>
    <button type="button" role="tab" id="rules-tab-<?= $e($lang) ?>" aria-controls="rules-panel" aria-selected="false" tabindex="-1" data-language="<?= $e($lang) ?>">
      <span class="rule-tab__name"><?= $e($t('rules.tab.' . $lang)) ?></span><span class="rule-tab__tool"> · <?= $e($t('rules.tool.' . $tool)) ?></span><span class="rule-tab__count" data-tab-count></span>
    </button>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <nav class="tabs__list" aria-label="<?= $e($t('rules.tabs_label')) ?>">
    <?php foreach ($langs as $lang => $tool): ?>
    <a class="tab-link" href="<?= $e($assets->page('rules') . '?language=' . $lang) ?>" data-language="<?= $e($lang) ?>"<?= $current === $lang ? ' aria-current="true"' : '' ?>>
      <span class="rule-tab__name"><?= $e($t('rules.tab.' . $lang)) ?></span><span class="rule-tab__tool"> · <?= $e($t('rules.tool.' . $tool)) ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>
</div>
