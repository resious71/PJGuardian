<?php
/**
 * 계정 CSV 일괄 처리(P28A, admin 전용): 파일 선택 → 미리보기 → 결과 3단계. 동작은 pages/admin_users_import.js가 API로 한다.
 * 서버는 틀과 형식 안내만 그린다. 파일은 서버에 보관하지 않으며 실행 때 같은 파일을 다시 보낸다(미리보기 digest로 같은 판정인지 확인).
 * 임시 비밀번호는 결과 단계에서 한 번만 보인다(새로 고치면 사라짐).
 *
 * @var int $tempHours
 * @var int $maxRows
 * @var int|float $maxMib
 * @var list<string> $authTypes 켜진 인증 방식(AuthProviderRegistry::enabledTypes)
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
?>
<div class="page-header">
  <h1><?= $icons->withLabel('admin.users', 24, $t('account_import.title')) ?></h1>
  <p class="muted"><?= $e($t('account_import.intro')) ?></p>
  <p><a href="<?= $e($assets->page('admin/users')) ?>"><?= $e($t('account_import.back')) ?></a></p>
</div>

<div class="account-import" data-account-import data-temp-hours="<?= (int) $tempHours ?>">
  <ol class="account-import__steps" aria-label="<?= $e($t('account_import.steps_label')) ?>">
    <li data-step-mark="file" aria-current="step"><span class="account-import__step-no" aria-hidden="true">1</span> <?= $e($t('account_import.step_file')) ?></li>
    <li data-step-mark="preview"><span class="account-import__step-no" aria-hidden="true">2</span> <?= $e($t('account_import.step_preview')) ?></li>
    <li data-step-mark="result"><span class="account-import__step-no" aria-hidden="true">3</span> <?= $e($t('account_import.step_result')) ?></li>
  </ol>

  <section class="card account-import__step" data-step="file" aria-labelledby="ai-file-title">
    <h2 id="ai-file-title" tabindex="-1"><?= $e($t('account_import.step_file')) ?></h2>
    <div class="account-import__format">
      <h3><?= $e($t('account_import.format_title')) ?></h3>
      <ul>
        <li><?= $e($t('account_import.format_columns')) ?></li>
        <li><?= $e($t('account_import.format_values', ['types' => implode(', ', $authTypes)])) ?></li>
        <li><?= $e($t('account_import.format_limits', ['rows' => number_format($maxRows), 'mib' => $maxMib])) ?></li>
        <li><?= $e($t('account_import.temp_note', ['hours' => $tempHours])) ?></li>
      </ul>
      <p><a class="btn" href="<?= $e($assets->page('api/v1/admin/account-import/template')) ?>" download data-template><?= $e($t('account_import.template')) ?></a></p>
    </div>
    <form class="account-import__form" data-file-form novalidate>
      <div class="field">
        <label class="field__label" for="ai-file"><?= $e($t('account_import.file_label')) ?></label>
        <input class="field__input" type="file" id="ai-file" accept=".csv,text/csv" data-file aria-describedby="ai-file-error">
        <p class="field__error" id="ai-file-error" role="alert" data-file-error hidden></p>
      </div>
      <div class="field">
        <label class="field__label" for="ai-encoding"><?= $e($t('account_import.encoding_label')) ?></label>
        <select class="field__input account-import__encoding" id="ai-encoding" data-encoding>
          <option value="auto"><?= $e($t('account_import.encoding_auto')) ?></option>
          <option value="utf-8"><?= $e($t('account_import.encoding_utf8')) ?></option>
          <option value="cp949"><?= $e($t('account_import.encoding_cp949')) ?></option>
        </select>
      </div>
      <div class="account-import__actions">
        <button type="submit" class="btn btn--primary" data-preview-btn><?= $e($t('account_import.preview')) ?></button>
      </div>
    </form>
  </section>

  <section class="card account-import__step" data-step="preview" aria-labelledby="ai-preview-title" hidden>
    <h2 id="ai-preview-title" tabindex="-1"><?= $e($t('account_import.step_preview')) ?></h2>
    <div data-preview-body></div>
  </section>

  <section class="card account-import__step" data-step="result" aria-labelledby="ai-result-title" hidden>
    <h2 id="ai-result-title" tabindex="-1"><?= $e($t('account_import.step_result')) ?></h2>
    <div data-result-body></div>
  </section>

  <p class="muted" data-status role="status" aria-live="polite"></p>
  <p class="notice notice--error" data-error role="alert" hidden></p>
</div>
