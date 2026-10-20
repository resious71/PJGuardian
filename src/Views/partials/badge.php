<?php
/**
 * 심각도·상태 배지: 아이콘 모양 + 텍스트 + 색(명세 20.1: 색에만 의존하지 않는다).
 * 사용: $view->partial('partials/badge', ['name' => 'severity.critical'])
 *
 * @var string $name 아이콘 의미 이름(severity.*, defect_status.*, analysis_status.*, system.*)
 * @var callable $e
 * @var \PJGuardian\Support\Icons $icons
 */
$modifier = str_replace(['.', '_'], ['-', '-'], $name);
?>
<span class="badge badge--<?= $e($modifier) ?>" data-badge="<?= $e($name) ?>"><?= $icons->withLabel($name, 16) ?></span>
