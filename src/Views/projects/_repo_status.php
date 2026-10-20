<?php
/**
 * 프로젝트 화면의 소스 저장소 상태(P25, 명세 18.5): 출처·연결 상태(정상/오류/미확인)·마지막 가져오기 시각·마지막 분석한 커밋/changelist·
 * 마지막 확인(P27: 가져오지 않고 원격 리비전만 본 시각과 결과 — 변경 없음이면 분석을 생략)·
 * 주기 분석(P26: 사용 중 · N일마다 · 다음 예정, admin에게는 연속 실패 경고 — 아이콘 + 글자). admin에게는 "지금 가져와 분석" 버튼(조건이 안 맞으면 끄고 이유)과 연결 설정 링크.
 * 값은 pages/project_repo_status.js가 GET /api/v1/projects/{id}/repo로 읽어 textContent로 채운다(일반 사용자에게는 주소·자격 증명 없음).
 * 업로드 전용(연결 없음)이면 일반 사용자에게는 이 상자를 숨긴다.
 *
 * @var int $id
 * @var bool $isAdmin
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<section class="card repo-status" data-repo-status data-project-id="<?= $id ?>" data-admin="<?= $isAdmin ? '1' : '0' ?>" aria-labelledby="repo-status-title" hidden>
  <div class="repo-status__head">
    <h2 id="repo-status-title"><?= $e($t('repo.status_title')) ?></h2>
    <?php if ($isAdmin): ?>
    <div class="toolbar">
      <button type="button" class="btn btn--primary" data-repo-fetch disabled aria-describedby="repo-fetch-reason"><?= $icons->withLabel('action.fetch_now', 16, $t('repo.fetch_now')) ?></button>
      <a class="btn" href="<?= $e($assets->page('projects/' . $id . '/edit') . '#project-repo') ?>" data-repo-settings><?= $e($t('repo.settings_link')) ?></a>
    </div>
    <?php endif; ?>
  </div>
  <dl class="detail-list repo-status__list">
    <dt><?= $e($t('repo.field.source')) ?></dt><dd data-repo-source>—</dd>
    <dt><?= $e($t('repo.field.status')) ?></dt><dd data-repo-state>—</dd>
    <dt><?= $e($t('repo.field.last_fetch')) ?></dt><dd data-repo-last-fetch>—</dd>
    <dt><?= $e($t('repo.field.last_revision')) ?></dt><dd data-repo-last-revision>—</dd>
    <dt><?= $e($t('repo.field.last_check')) ?></dt><dd data-repo-last-check>—</dd>
    <dt><?= $e($t('repo.field.next_run')) ?></dt><dd data-repo-next-run>—</dd>
  </dl>
  <p class="notice notice--warn repo-status__failing" data-repo-schedule-failing role="note" hidden><?= $icons->icon('system.warn', 16) ?> <span data-repo-schedule-failing-text></span></p>
  <?php if ($isAdmin): ?>
  <p class="muted repo-status__reason" id="repo-fetch-reason" data-repo-fetch-reason></p>
  <?php endif; ?>
  <p class="muted" data-repo-message role="status" aria-live="polite"></p>
</section>
