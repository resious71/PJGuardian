<?php
/**
 * "시스템 관리" 하위 화면 이동(X10에서 P20A 화면 묶음의 이름을 바꿈): 분석 도구 / 워커 / 점검 / Run 관리(P21B) / 규칙 설정(P22B) / 설정 관리(P27A — X10 별도 메뉴에서 옮김, X12 규칙 설정 뒤로) / 주기 분석(P26) / 백업·복원(P27B).
 * 알림 보관(P24)·허용 호스트(P25) 화면은 X12에서 설정 관리의 "알림"·"저장소 연결" 탭 안 구역으로 옮겼다(옛 주소는 그 탭으로 이동). 현재 화면은 aria-current.
 *
 * @var string $sub tools | worker | checks | runs | rule-settings | config-settings | schedules | backups
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Assets $assets
 */
// X12: 설정 관리는 규칙 설정 바로 뒤, 알림 보관·허용 호스트 화면은 설정 관리의 "알림"·"저장소 연결" 탭 구역으로 옮겨 탭 없음
$items = ['tools' => 'admin_tools.title', 'worker' => 'admin_worker.title', 'checks' => 'admin_checks.title', 'runs' => 'admin_runs.title', 'rule-settings' => 'admin_rule_settings.title', 'config-settings' => 'config_settings.title', 'schedules' => 'admin_schedules.subnav', 'backups' => 'admin_backups.subnav'];
?>
<nav class="subnav" data-system-subnav aria-label="<?= $e($t('admin_system.subnav')) ?>">
  <ul class="subnav__list">
    <?php foreach ($items as $key => $label): ?>
    <li><a class="subnav__item" href="<?= $e($assets->page('admin/' . $key)) ?>"<?= $sub === $key ? ' aria-current="page"' : '' ?>><?= $e($t($label)) ?></a></li>
    <?php endforeach; ?>
  </ul>
</nav>
