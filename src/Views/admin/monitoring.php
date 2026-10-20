<?php
/**
 * 시스템 모니터링(P30, admin만, 읽기 전용): 카드 9개(워커·큐·디스크·최근 실패 분석·백업·bin 도구·주기 분석·HTTPS 인증서·앱 정보)와 활성 경고.
 * 판정은 서버 SystemHealth 한 곳(/health·관리자 배너와 같음). 내용은 pages/admin_monitoring.js가 API로 읽어 textContent로 그린다.
 * 자동 갱신은 탭이 보일 때만(포커스·스크롤 유지), "지금 새로 고침"은 캐시를 쓰지 않고 다시 판정한다.
 *
 * @var int $refreshSeconds
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
// X18: 카드 순서는 중요도 순(위 줄 워커·큐·디스크 → 백업·bin/ 도구·인증서 → 주기 분석·최근 실패 분석 → 앱 정보), 모든 카드가 공용 틀 _monitor_card 하나.
// [카드, 폭(normal|wide = 2열 폭|wide-md = 2열 격자에서만 2열 폭 — 3열 격자에서는 최근 실패(2열)와 한 줄), 바닥 줄 링크(없으면 null)]
$cards = [
    ['worker', 'normal', ['/admin/worker', 'monitoring.link.worker']],
    ['queue', 'normal', ['/admin/worker', 'monitoring.link.worker']],
    ['disk', 'normal', ['/admin/checks', 'monitoring.link.checks']],
    ['backup', 'normal', ['/admin/backups', 'monitoring.link.backups']],
    ['tools', 'normal', ['/admin/tools', 'monitoring.link.tools']],
    ['cert', 'normal', null],
    ['schedules', 'wide-md', ['/admin/schedules', 'monitoring.link.schedules']],
    ['failures', 'wide', null],
    ['app', 'normal', null],
];
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.system', 24, $t('monitoring.title')) ?></h1>
  <p class="muted"><?= $e($t('monitoring.intro', ['n' => $refreshSeconds])) ?></p>
</div>

<section class="monitoring" data-monitoring data-refresh="<?= (int) $refreshSeconds ?>">
  <div class="monitoring__bar">
    <p class="monitoring__status" data-status aria-live="polite"></p>
    <p class="muted monitoring__updated" data-updated></p>
    <button type="button" class="btn" data-refresh-now><?= $icons->withLabel('action.fetch_now', 16, $t('monitoring.refresh_now')) ?></button>
  </div>
  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
  <ul class="monitoring__alerts" data-alerts aria-label="<?= $e($t('monitoring.alerts_label')) ?>" hidden></ul>
  <div class="monitoring__grid mon-grid">
    <?php foreach ($cards as [$card, $span, $link]): ?>
    <?= $view->partial('admin/_monitor_card', ['card' => $card, 'span' => $span, 'link' => $link]) ?>
    <?php endforeach; ?>
  </div>
</section>
