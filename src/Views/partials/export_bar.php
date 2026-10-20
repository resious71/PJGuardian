<?php
/**
 * 내보내기 버튼(P23): 표 바로 위 오른쪽 맞춤. "Excel로 내보내기"(주 버튼) · "CSV로 내보내기"(보조), 의미 아이콘 action.export(download).
 * 동작은 ui/export_buttons.js(마지막으로 적용한 조건을 POST, 응답 파일을 내려받음). 처음에는 꺼 둔다(목록을 읽은 뒤 켬).
 *
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 */
?>
<div class="export-bar" data-export role="group" aria-label="<?= $e($t('export.region')) ?>">
  <p class="export-bar__note muted" data-export-note hidden></p>
  <button type="button" class="btn btn--primary" data-export-format="xlsx" disabled><?= $icons->withLabel('action.export', 16, $t('export.xlsx')) ?></button>
  <button type="button" class="btn" data-export-format="csv" disabled><?= $icons->withLabel('action.export', 16, $t('export.csv')) ?></button>
</div>
