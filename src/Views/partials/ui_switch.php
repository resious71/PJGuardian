<?php
/**
 * 헤더 오른쪽 언어·테마 전환(P27D). 동작은 ui/ui_prefs.js(app.js가 모든 화면에서 불러옴) — 스크립트가 없으면 버튼만 보인다.
 *   - 언어 버튼: 아이콘만(X14 — 한/영 같은 모양, 헤더 폭), 누르면 다른 언어로(계정 저장 + 쿠키, 화면 다시 불러오기). aria-label·툴팁에 지금 언어와 바꿀 언어.
 *   - 테마 버튼: 아이콘만(지금 선택은 툴팁·접근 이름·메뉴의 선택 표시로), 누르면 3개 선택 메뉴(menuitemradio, aria-checked). 다시 불러오지 않고 바로 적용.
 *
 * @var string $uiLang ko|en
 * @var string $uiTheme light|dark|system
 * @var bool $uiLoggedIn 로그인 상태면 계정에도 저장
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
$nextLang = $uiLang === 'en' ? 'ko' : 'en';
$themeIcon = ['light' => 'ui.theme_light', 'dark' => 'ui.theme_dark', 'system' => 'ui.theme_system'];
?>
<div class="ui-switch" data-ui-switch data-logged-in="<?= $uiLoggedIn ? '1' : '0' ?>" data-lang="<?= $e($uiLang) ?>" data-theme-choice="<?= $e($uiTheme) ?>">
  <button type="button" class="nav-item nav-item--button ui-switch__lang" data-ui-lang data-next="<?= $e($nextLang) ?>"
          aria-label="<?= $e($t('ui_switch.lang_aria_' . $nextLang)) ?>" title="<?= $e($t('ui_switch.lang_aria_' . $nextLang)) ?>">
    <?= $icons->icon('ui.language', 16) ?>
  </button>
  <div class="ui-switch__theme">
    <button type="button" class="nav-item nav-item--button" data-ui-theme-btn aria-haspopup="menu" aria-expanded="false" aria-controls="ui-theme-menu"
            aria-label="<?= $e($t('ui_switch.theme_aria', ['current' => $t('ui_switch.theme.' . $uiTheme)])) ?>" title="<?= $e($t('ui_switch.theme_aria', ['current' => $t('ui_switch.theme.' . $uiTheme)])) ?>">
      <span data-ui-theme-current><?= $icons->withLabel($themeIcon[$uiTheme], 16) ?></span>
    </button>
    <ul class="ui-switch__menu" id="ui-theme-menu" role="menu" aria-label="<?= $e($t('ui_switch.theme_menu')) ?>" data-ui-theme-menu hidden>
      <?php foreach (['light', 'dark', 'system'] as $choice): ?>
      <li role="none"><button type="button" role="menuitemradio" class="ui-switch__item" data-theme-value="<?= $e($choice) ?>" aria-checked="<?= $choice === $uiTheme ? 'true' : 'false' ?>" tabindex="-1">
        <?= $icons->withLabel($themeIcon[$choice], 16, $t('ui_switch.theme.' . $choice)) ?></button></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
