<?php
/**
 * 프로젝트 편집 화면의 "소스 저장소" 영역(P25, admin 전용 — 편집 화면 자체가 admin만).
 * 유형(업로드 전용·Git·Perforce)에 따라 입력 항목이 바뀐다(pages/project_repo.js). 자격 증명은 **값을 보이지 않는다**:
 * "설정됨/미설정" 표시 + 새 값 입력(비우면 유지) + 삭제. 입력칸은 autocomplete="off"(붙여넣기 가능).
 * HTTP·비SSL이면 평문 전송 경고, 허용 호스트 목록에 없으면 등록 위치 안내. 연결 테스트는 저장 전 입력으로도 할 수 있다(읽기만).
 *
 * @var array<string, mixed> $project
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$pid = (int) $project['id'];
?>
<section class="card repo-form" id="project-repo" aria-labelledby="repo-title" data-repo-form data-project-id="<?= $pid ?>"
         data-hosts-url="<?= $e($assets->page('admin/config-settings') . '#repository') ?>">
  <h2 id="repo-title"><?= $e($t('repo.title')) ?></h2>
  <p class="muted"><?= $e($t('repo.intro')) ?></p>
  <p class="notice notice--warn" data-repo-key-missing hidden><?= $icons->icon('system.warn', 16) ?> <?= $e($t('repo.key_missing')) ?></p>
  <form data-repo-fields novalidate>
    <fieldset class="field repo-form__kind">
      <legend class="field__label"><?= $e($t('repo.field.kind')) ?></legend>
      <?php foreach (['none', 'git', 'perforce'] as $k): ?>
      <label class="radio"><input type="radio" name="kind" value="<?= $e($k) ?>"<?= $k === 'none' ? ' checked' : '' ?>> <?= $icons->withLabel($k === 'none' ? 'source.upload' : 'source.' . $k, 16, $t('repo.kind.' . $k)) ?></label>
      <?php endforeach; ?>
    </fieldset>

    <div class="field" data-field="url" hidden>
      <label class="field__label" for="repo-url"><?= $e($t('repo.field.url')) ?></label>
      <input class="field__input" id="repo-url" name="url" type="url" inputmode="url" autocomplete="off" spellcheck="false" maxlength="500" placeholder="https://github.example.com/team/app.git" aria-describedby="repo-url-help">
      <span class="muted repo-form__help" id="repo-url-help"><?= $e($t('repo.help.url')) ?></span>
    </div>
    <div class="field" data-field="port" hidden>
      <label class="field__label" for="repo-port"><?= $e($t('repo.field.port')) ?></label>
      <input class="field__input" id="repo-port" name="port" autocomplete="off" spellcheck="false" maxlength="300" placeholder="ssl:perforce.example.com:1666" aria-describedby="repo-port-help">
      <span class="muted repo-form__help" id="repo-port-help"><?= $e($t('repo.help.port')) ?></span>
    </div>
    <p class="notice notice--warn" data-plaintext-warning role="note" hidden><?= $icons->icon('system.warn', 16) ?> <span data-plaintext-text></span></p>
    <div class="field" data-field="branch" hidden>
      <label class="field__label" for="repo-branch"><?= $e($t('repo.field.branch')) ?></label>
      <input class="field__input" id="repo-branch" name="branch" autocomplete="off" spellcheck="false" maxlength="200" aria-describedby="repo-branch-help">
      <span class="muted repo-form__help" id="repo-branch-help"><?= $e($t('repo.help.branch')) ?></span>
    </div>
    <div class="field" data-field="path" hidden>
      <label class="field__label" for="repo-path" data-path-label-git><?= $e($t('repo.field.sub_path')) ?></label>
      <label class="field__label" for="repo-path" data-path-label-p4 hidden><?= $e($t('repo.field.depot_path')) ?></label>
      <input class="field__input" id="repo-path" name="path" autocomplete="off" spellcheck="false" maxlength="500" aria-describedby="repo-path-help">
      <span class="muted repo-form__help" id="repo-path-help" data-path-help></span>
    </div>
    <div class="field" data-field="username" hidden>
      <label class="field__label" for="repo-username"><?= $e($t('repo.field.username')) ?></label>
      <input class="field__input" id="repo-username" name="username" autocomplete="off" spellcheck="false" maxlength="200" aria-describedby="repo-username-help">
      <span class="muted repo-form__help" id="repo-username-help" data-username-help></span>
    </div>
    <div class="field" data-field="secret_kind" hidden>
      <span class="field__label" id="repo-sk-label"><?= $e($t('repo.field.secret_kind')) ?></span>
      <div role="radiogroup" aria-labelledby="repo-sk-label">
        <label class="radio"><input type="radio" name="secret_kind" value="password" checked> <?= $e($t('repo.secret_kind.password')) ?></label>
        <label class="radio"><input type="radio" name="secret_kind" value="ticket"> <?= $e($t('repo.secret_kind.ticket')) ?></label>
      </div>
    </div>
    <div class="field" data-field="secret" hidden>
      <label class="field__label" for="repo-secret" data-secret-label><?= $e($t('repo.field.secret')) ?></label>
      <p class="repo-form__secret-state" data-secret-state aria-live="polite"></p>
      <input class="field__input" id="repo-secret" name="secret" type="password" autocomplete="off" spellcheck="false" maxlength="4000" aria-describedby="repo-secret-help">
      <span class="muted repo-form__help" id="repo-secret-help"><?= $e($t('repo.help.secret')) ?></span>
      <label class="checkbox" data-clear-secret-wrap hidden><input type="checkbox" name="clear_secret" value="1"> <?= $e($t('repo.clear_secret')) ?></label>
    </div>
    <div class="field" data-field="fingerprint" hidden>
      <label class="field__label" for="repo-fingerprint"><?= $e($t('repo.field.fingerprint')) ?></label>
      <input class="field__input mono" id="repo-fingerprint" name="fingerprint" autocomplete="off" spellcheck="false" maxlength="200" aria-describedby="repo-fp-help">
      <span class="muted repo-form__help" id="repo-fp-help"><?= $e($t('repo.help.fingerprint')) ?></span>
    </div>
    <div class="field" data-field="fetch_timeout" hidden>
      <label class="field__label" for="repo-timeout"><?= $e($t('repo.field.fetch_timeout')) ?></label>
      <input class="field__input repo-form__narrow" id="repo-timeout" name="fetch_timeout" type="number" min="1" max="1440" step="1" inputmode="numeric" aria-describedby="repo-timeout-help">
      <span class="muted repo-form__help" id="repo-timeout-help"><?= $e($t('repo.help.fetch_timeout')) ?></span>
    </div>
    <p class="notice notice--error" data-repo-error role="alert" hidden></p>
    <p class="notice notice--warn" data-host-hint hidden><?= $icons->icon('ui.info', 16) ?> <span data-host-hint-text></span> <a href="<?= $e($assets->page('admin/config-settings') . '#repository') ?>"><?= $e($t('repo.hosts_link')) ?></a></p>
    <div class="toolbar">
      <button type="submit" class="btn btn--primary" data-repo-save><?= $e($t('repo.save')) ?></button>
      <button type="button" class="btn" data-repo-test><?= $e($t('repo.test')) ?></button>
      <button type="button" class="btn btn--danger-outline" data-repo-delete hidden><?= $e($t('repo.disconnect')) ?></button>
    </div>
    <p class="repo-form__result" data-repo-result role="status" aria-live="polite"></p>
    <p class="muted"><?= $e($t('repo.key_note')) ?></p>
  </form>
</section>
