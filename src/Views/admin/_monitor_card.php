<?php
/**
 * 시스템 모니터링 카드 공용 틀(X18). 모든 카드가 이 하나로 그려진다 — 위에서 아래로
 *   머리(상태 아이콘 + 제목, 오른쪽 끝 상태 배지 — X19: 제목 앞 아이콘은 상태(정상 파랑·주의/오류 빨강), 카드 종류 아이콘은 쓰지 않음) → 핵심 값 줄 → 세부(라벨-값 2열 또는 같은 표 스타일) → 바닥 줄(상세 화면 링크 · 확인 시각).
 * 내용(배지·값·세부·확인 시각)은 pages/admin_monitoring.js가 textContent로 채운다. 링크가 없는 카드도 바닥 줄 자리는 남긴다(높이 유지).
 *
 * @var string $card 카드 이름(worker·queue·…)
 * @var string $span normal|wide(2열 폭)|wide-md(2열 격자에서만 2열 폭)
 * @var array{0:string, 1:string}|null $link [경로, 문구 키]
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$id = 'mon-' . $card;
?>
<section class="card mon-card<?= $span !== 'normal' ? ' mon-card--' . $e($span) : '' ?>" id="<?= $e($id) ?>" data-card="<?= $e($card) ?>" aria-labelledby="<?= $e($id) ?>-title">
  <header class="mon-card__head">
    <h2 class="mon-card__title" id="<?= $e($id) ?>-title"><span class="mon-card__state" data-state-icon></span><span class="mon-card__name"><?= $e($t('monitoring.card.' . $card)) ?></span></h2>
    <span class="mon-card__badge" data-badge></span>
  </header>
  <div class="mon-card__value" data-value></div>
  <div class="mon-card__detail" data-detail></div>
  <footer class="mon-card__foot">
    <span class="mon-card__link"><?php if ($link !== null): ?><a href="<?= $e($assets->page($link[0])) ?>" data-focus-key="<?= $e($card === 'backup' ? 'backups' : $card) ?>"><?= $e($t($link[1])) ?></a><?php endif; ?></span>
    <span class="mon-card__checked muted" data-checked></span>
  </footer>
</section>
