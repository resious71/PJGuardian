<?php
/**
 * 도움말 링크(P29): "이 화면 도움말" — 같은 탭에서 가이드 절을 연다(뒤로 가기로 돌아옴). 아이콘은 가이드(nav.guide)와 같은 뜻.
 * 관리자 화면은 audience=admin(/guide/admin#<key>, P29C).
 *
 * @var string $audience user|admin
 * @var string $key 가이드 절 key
 * @var bool|null $inline 문장 안(오류 상자 등)에 붙이는 작은 모양
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<a class="help-link<?= !empty($inline) ? ' help-link--inline' : '' ?>" href="<?= $e($assets->page('guide/' . $audience) . '#' . $key) ?>" data-guide="<?= $e($key) ?>" data-guide-audience="<?= $e($audience) ?>"><?= $icons->withLabel('nav.guide', 16, $t('help.link')) ?></a>
