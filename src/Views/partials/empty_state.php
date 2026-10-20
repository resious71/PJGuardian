<?php
/**
 * 빈 상태: 상황을 설명하는 아이콘 + 제목 + 설명 + 다음 행동 안내(명세 20.4).
 *
 * @var string $icon 아이콘 의미 이름(예: nav.projects, ui.empty)
 * @var string $title
 * @var string $body
 * @var string|null $actionLabel
 * @var string|null $actionHref 없으면 비활성 버튼
 * @var callable $e
 * @var \PJGuardian\Support\Icons $icons
 */
?>
<section class="empty-state">
  <div class="empty-state__icon"><?= $icons->icon($icon, 24) ?></div>
  <h3 class="empty-state__title"><?= $e($title) ?></h3>
  <p class="empty-state__body"><?= $e($body) ?></p>
  <?php if (($actionLabel ?? null) !== null): ?>
    <?php if (($actionHref ?? null) !== null): ?>
  <a class="btn btn--primary" href="<?= $e($actionHref) ?>"><?= $e($actionLabel) ?></a>
    <?php else: ?>
  <button type="button" class="btn" disabled><?= $e($actionLabel) ?></button>
    <?php endif; ?>
  <?php endif; ?>
</section>
