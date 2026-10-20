<?php
/**
 * 프로젝트 삭제 확인(관리자, 명세 4장, 20.4). 즉시 영구 삭제되는 분석 횟수·결함 건수·스냅샷 크기를 보여 주고,
 * 프로젝트 이름을 정확히 입력해야 삭제 버튼이 켜진다(버튼 비활성은 편의일 뿐, 서버가 이름을 다시 확인).
 * 경고 색과 전용 아이콘(ui.danger)으로 파괴적 동작임을 구분한다.
 *
 * @var array<string, mixed> $project
 * @var array{analyses:int, defects:int, snapshot_bytes:int, uploads:int} $info
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$id = (int) $project['id'];
$bytes = $info['snapshot_bytes'];
$size = match (true) {
    $bytes >= 1073741824 => number_format($bytes / 1073741824, 1) . ' GB',
    $bytes >= 1048576 => number_format($bytes / 1048576, 1) . ' MB',
    $bytes >= 1024 => number_format($bytes / 1024, 1) . ' KB',
    default => $bytes . ' B',
};
?>
<nav class="breadcrumb" aria-label="<?= $e($t('projects.breadcrumb')) ?>"><a href="<?= $e($assets->page('projects')) ?>"><?= $e($t('projects.title')) ?></a></nav>
<div class="page-header">
  <h1><?= $e($t('projects.delete_title', ['name' => $project['name']])) ?></h1>
</div>
<section class="card danger-zone" aria-labelledby="delete-warning">
  <div class="danger-zone__head">
    <?= $icons->meaningful('ui.danger', 24) ?>
    <h2 id="delete-warning"><?= $e($t('projects.delete_warning')) ?></h2>
  </div>
  <ul class="danger-zone__facts">
    <li><?= $e($t('projects.delete_fact_analyses', ['n' => number_format($info['analyses'])])) ?></li>
    <li><?= $e($t('projects.delete_fact_defects', ['n' => number_format($info['defects'])])) ?></li>
    <li><?= $e($t('projects.delete_fact_snapshots', ['size' => $size])) ?></li>
    <li><?= $e($t('projects.delete_fact_uploads', ['n' => number_format($info['uploads'])])) ?></li>
    <li><?= $e($t('projects.delete_fact_access', ['n' => number_format($info['access_mappings'])])) ?></li>
  </ul>
  <form id="project-delete" novalidate data-project-id="<?= $id ?>" data-confirm-name="<?= $e($project['name']) ?>" data-done-url="<?= $e($assets->page('projects')) ?>">
    <div class="field">
      <label class="field__label" for="pd-name"><?= $e($t('projects.delete_type_name')) ?> <code class="confirm-name"><?= $e($project['name']) ?></code></label>
      <input class="field__input" id="pd-name" name="confirm_name" autocomplete="off" spellcheck="false" aria-describedby="pd-help">
      <p class="field__help" id="pd-help"><?= $e($t('projects.delete_type_help')) ?></p>
    </div>
    <p class="field__error" id="pd-error" role="alert" hidden></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--danger" id="project-delete-submit" disabled><?= $icons->icon('action.delete', 16) ?> <?= $e($t('projects.delete_submit')) ?></button>
      <a class="btn" href="<?= $e($assets->page('projects/' . $id)) ?>"><?= $e($t('component.cancel')) ?></a>
    </div>
  </form>
</section>
