<?php
/**
 * 코드 업로드(명세 5.4, 19.10). 프로젝트 선택 목록은 볼 수 있는 프로젝트만. 업로드는 pages/upload.js가 API로 한다:
 * ZIP 파일 또는 폴더(브라우저에서 STORE zip으로 묶음) → 8MB 청크 + 청크별 SHA-256 → 완료 후 상태 폴링.
 * 해제·사전 검사·경고 확인·분석 연결은 P13에서 붙인다(이 단계는 "처리 중"까지 표시).
 *
 * @var list<array<string, mixed>> $projects
 * @var int $selected
 * @var bool $isAdmin
 * @var int $maxBytes
 * @var int $userId
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 */
$maxMb = intdiv($maxBytes, 1024 * 1024);
?>
<div class="page-header">
  <h1><?= $icons->withLabel('source.upload', 24, $t('upload.title')) ?></h1>
  <p class="muted"><?= $e($t('upload.intro', ['max' => $maxMb])) ?></p>
</div>

<?php if ($projects === []): ?>
<section class="card">
  <?= $view->partial('partials/empty_state', [
      'icon' => 'source.upload',
      'title' => $t('upload.no_projects_title'),
      'body' => $t($isAdmin ? 'upload.no_projects_admin' : 'upload.no_projects_user'),
      'actionLabel' => $isAdmin ? $t('projects.new_button') : null,
      'actionHref' => $isAdmin ? $assets->page('projects/new') : null,
  ]) ?>
</section>
<?php else: ?>
<section class="card" aria-labelledby="up-form-title">
  <h2 id="up-form-title" class="sr-only"><?= $e($t('upload.form_title')) ?></h2>
  <form id="upload-form" novalidate data-max-bytes="<?= $maxBytes ?>" data-user-id="<?= $userId ?>" data-project-base="<?= $e($assets->page('projects')) ?>" data-analysis-base="<?= $e($assets->page('analyses/')) ?>">
    <div class="field">
      <label class="field__label" for="up-project"><?= $e($t('upload.project')) ?></label>
      <select class="field__input" id="up-project" name="project" required>
        <?php foreach ($projects as $p): ?>
        <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $selected ? ' selected' : '' ?>><?= $e($p['name']) ?> (<?= $e($t($p['language'] === 'python' ? 'icon.language.python' : 'icon.language.javascript')) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <fieldset class="field field--wide">
      <legend class="field__label"><?= $e($t('upload.target')) ?></legend>
      <?php /* 순서: [ZIP 파일 선택] [폴더 선택] [선택 취소]. 폴더는 showDirectoryPicker(선택 창 버튼 "폴더 선택")를 쓰고,
           없는 브라우저(Firefox 등)·거부된 폴더면 webkitdirectory로 되돌린다(그때만 안내 문구). 고르는 것만으로는 전송하지 않는다 */ ?>
      <div class="toolbar upload-pickers">
        <input class="sr-only" type="file" id="up-file" accept=".zip,application/zip,application/x-zip-compressed">
        <label class="btn" for="up-file"><?= $e($t('upload.pick_file')) ?></label>
        <button type="button" class="btn" id="up-folder-btn" hidden><?= $e($t('upload.pick_folder')) ?></button>
        <input class="sr-only" type="file" id="up-folder" webkitdirectory multiple>
        <label class="btn" for="up-folder" id="up-folder-label"><?= $e($t('upload.pick_folder')) ?></label>
        <button type="button" class="btn" id="up-clear" disabled><?= $e($t('upload.clear_selection')) ?></button>
      </div>
      <p class="field__help" id="up-fallback-note" hidden><?= $e($t('upload.fallback_note')) ?></p>
      <label class="checkbox"><input type="checkbox" id="up-skip" checked> <?= $e($t('upload.skip_excluded')) ?></label>
      <p class="field__help" id="up-skip-help"><?= $e($t('upload.skip_excluded_help')) ?> <span id="up-excludes"></span></p>
      <div class="upload-summary" id="up-summary">
        <div aria-live="polite">
          <p class="upload-target" id="up-target"><?= $e($t('upload.no_target')) ?></p>
          <p class="upload-summary__stats" id="up-stats" hidden></p>
        </div>
        <details class="upload-files" id="up-files" hidden>
          <summary><?= $e($t('upload.files_toggle')) ?></summary>
          <ol class="upload-files__list" id="up-file-list"></ol>
          <p class="muted" id="up-file-more" hidden></p>
          <p class="field__help"><?= $e($t('upload.project_patterns_note')) ?></p>
        </details>
        <p class="field__help" id="up-path-help"><?= $e($t('upload.path_help')) ?></p>
      </div>
      <p class="field__error" id="up-block" role="alert" hidden></p>
    </fieldset>
    <div class="toolbar">
      <button type="submit" class="btn btn--primary" id="up-start" disabled><?= $icons->withLabel('action.upload', 16, $t('upload.start')) ?></button>
      <button type="button" class="btn" id="up-pause" disabled><?= $e($t('upload.pause')) ?></button>
      <button type="button" class="btn btn--danger-outline" id="up-abort" hidden><?= $icons->withLabel('action.cancel', 16, $t('upload.abort')) ?></button>
    </div>
  </form>
</section>

<section class="card upload-progress" id="up-progress" aria-labelledby="up-phase" hidden>
  <h2 class="upload-progress__phase" id="up-phase" aria-live="polite"><span id="up-phase-icon"></span><span id="up-phase-text"></span></h2>
  <div class="progress-field">
    <progress class="progress" id="up-bar" max="100" value="0" aria-labelledby="up-phase"></progress>
    <span id="up-percent">0%</span>
  </div>
  <ol class="upload-steps" id="up-steps" aria-label="<?= $e($t('upload.steps')) ?>">
    <?php foreach (['upload', 'assemble', 'extract', 'queue'] as $step): ?>
    <li class="upload-steps__item" data-step="<?= $step ?>"><span class="upload-steps__icon"></span><span><?= $e($t('upload.step.' . $step)) ?></span></li>
    <?php endforeach; ?>
  </ol>
  <section class="notice notice--warn upload-warnings" id="up-warnings" aria-labelledby="up-warnings-title" hidden>
    <h3 class="upload-warnings__title" id="up-warnings-title"><?= $e($t('upload.warnings_title')) ?></h3>
    <ul id="up-warning-list"></ul>
    <div class="toolbar">
      <button type="button" class="btn btn--primary" id="up-confirm"><?= $e($t('upload.confirm_continue')) ?></button>
      <button type="button" class="btn btn--danger-outline" id="up-cancel-warning"><?= $icons->withLabel('action.cancel', 16, $t('upload.abort')) ?></button>
    </div>
  </section>
  <?php /* P21C: 업로드 내용 요약(해제 결과의 숫자, 직전 업로드와 비교, 파일 해시). 값은 upload.js가 textContent로 넣는다 */ ?>
  <section class="card upload-summary" id="up-content" aria-labelledby="up-summary-title" hidden>
    <h3 id="up-summary-title"><?= $e($t('upload.summary_title')) ?></h3>
    <p class="upload-summary__previous" id="up-summary-previous" data-previous></p>
    <dl class="upload-stats" id="up-summary-list"></dl>
    <p class="upload-summary__hash">
      <span><?= $e($t('upload.summary_hash')) ?></span> <code id="up-summary-hash" data-hash></code>
      <button type="button" class="btn btn--small" id="up-summary-copy"><?= $icons->withLabel('action.copy', 16, $t('upload.summary_copy')) ?></button>
      <span class="muted" id="up-summary-copy-status" role="status" aria-live="polite"></span>
    </p>
  </section>
  <p class="notice notice--info" id="up-queued" hidden><span id="up-queued-text"></span> <a id="up-project-link" href="<?= $e($assets->page('projects')) ?>"><?= $e($t('upload.go_analysis')) ?></a></p>
  <dl class="upload-stats">
    <dt><?= $e($t('upload.stat_chunks')) ?></dt><dd id="up-chunks">-</dd>
    <dt><?= $e($t('upload.stat_speed')) ?></dt><dd id="up-speed">-</dd>
    <dt><?= $e($t('upload.stat_sha')) ?></dt><dd><code id="up-sha">-</code></dd>
  </dl>
  <p class="notice notice--error" id="up-error" role="alert" hidden></p>
  <p class="notice notice--info" id="up-error-action" hidden></p>
  <p id="up-error-guide" hidden><a id="up-error-guide-link"><?= $icons->withLabel('nav.guide', 16, $t('guide.detail_link')) ?></a> · <a id="up-error-code-link"></a></p>
  <p class="muted" id="up-note" hidden></p>
</section>
<?php endif; ?>
