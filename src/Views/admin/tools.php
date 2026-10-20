<?php
/**
 * 관리자 "분석 도구"(P20A, 명세 12, 19.1, 19.10). 목록·동작은 pages/admin_tools.js가 API로 그린다(textContent만).
 * 웹은 bin/에 쓰지 않는다: 차단·고정은 DB 값, 재검증은 워커에 요청, 패키지는 보관만(설치·삭제는 서버 CLI 명령 안내).
 *
 * @var string $sub
 * @var int $maxBytes 패키지 크기 한도
 * @var int $retentionDays 스테이징 보관 일수
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\View $view
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.tools', 24, $t('admin_system.title')) ?></h1>
  <p class="muted"><?= $e($t('admin_tools.intro')) ?></p>
</div>
<?= $view->partial('admin/_subnav', ['sub' => $sub]) ?>

<section data-admin-tools data-max-bytes="<?= (int) $maxBytes ?>">
  <p class="muted" data-loading aria-live="polite"><?= $e($t('defects.loading')) ?></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>

  <section class="card" aria-labelledby="node-title">
    <div class="card__header">
      <h2 id="node-title"><?= $e($t('admin_tools.node_title')) ?></h2>
      <?php /* P21C: 워커를 다시 시작하지 않고 워커가 Node.js를 다시 확인(설정 파일도 다시 읽음) */ ?>
      <button type="button" class="btn btn--small" data-node-refresh aria-describedby="node-refresh-status"><?= $icons->withLabel('action.fetch_now', 16, $t('admin_tools.node_refresh')) ?></button>
    </div>
    <p class="muted node-refresh-status" id="node-refresh-status" data-node-refresh-status role="status" aria-live="polite"></p>
    <dl class="detail-list" data-node></dl>
  </section>

  <div data-tools></div>

  <section class="card" aria-labelledby="stg-title">
    <h2 id="stg-title"><?= $e($t('admin_tools.staging_title')) ?></h2>
    <p class="muted"><?= $e($t('admin_tools.staging_intro', ['days' => $retentionDays])) ?></p>
    <form class="form-grid" data-staging-form novalidate>
      <div class="field">
        <label class="field__label" for="stg-tool"><?= $e($t('admin_tools.staging_tool')) ?></label>
        <select class="field__input" id="stg-tool" name="tool">
          <option value="ruff">ruff</option>
          <option value="eslint">ESLint</option>
        </select>
      </div>
      <div class="field">
        <label class="field__label" for="stg-file"><?= $e($t('admin_tools.staging_file')) ?> <span class="field__required" aria-hidden="true">*</span></label>
        <input class="field__input" id="stg-file" name="file" type="file" accept=".zip,application/zip" required aria-describedby="stg-file-help">
        <p class="field__help" id="stg-file-help"><?= $e($t('admin_tools.staging_file_help')) ?></p>
      </div>
      <div class="field">
        <label class="field__label" for="stg-sha"><?= $e($t('admin_tools.staging_expected')) ?></label>
        <input class="field__input mono" id="stg-sha" name="expected_sha256" maxlength="64" autocomplete="off" spellcheck="false" aria-describedby="stg-sha-help">
        <p class="field__help" id="stg-sha-help"><?= $e($t('admin_tools.staging_expected_help')) ?></p>
      </div>
      <p class="field__error" data-staging-error role="alert" hidden></p>
      <div class="form-grid__actions">
        <button type="submit" class="btn btn--primary"><?= $icons->withLabel('action.upload', 16, $t('admin_tools.staging_submit')) ?></button>
        <progress max="100" value="0" data-staging-progress hidden aria-label="<?= $e($t('admin_tools.staging_progress')) ?>"></progress>
        <span class="muted" data-staging-progress-text aria-live="polite"></span>
      </div>
    </form>
    <div data-staging-list></div>
  </section>

  <details class="card help">
    <summary><?= $icons->withLabel('ui.command', 16, $t('admin_tools.cli_help_title')) ?></summary>
    <p><?= $e($t('admin_tools.cli_help_body')) ?></p>
    <pre class="command">php tools/tools_ctl.php list
php tools/tools_ctl.php install --staging=&lt;id&gt;
php tools/tools_ctl.php remove &lt;tool&gt; &lt;version&gt;</pre>
  </details>
</section>
