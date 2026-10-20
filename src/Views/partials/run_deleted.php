<?php
/**
 * 상세가 삭제된 Run을 주소(?run=N)로 열었을 때(P21B, HTTP 410): 안내와 최신 Run으로 보는 링크.
 *
 * @var int $run
 * @var string $latestHref
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\View $view
 */
?>
<section class="card" data-run-deleted="<?= (int) $run ?>">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'ui.read_only',
      'title' => $t('runs.detail_deleted_title', ['run' => $run]),
      'body' => $t('runs.detail_deleted_body'),
      'actionLabel' => $t('runs.view_latest'),
      'actionHref' => $latestHref,
  ]) ?>
</section>
