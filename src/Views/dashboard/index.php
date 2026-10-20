<?php
/**
 * 대시보드(명세 9, P20). 구역마다 따로 API를 불러 그린다(pages/dashboard.js) — 느린 구역이 다른 구역을 막지 않게.
 * 집계는 보이는 프로젝트 범위만. 차트는 Chart.js(로컬 번들), 모든 차트에 "표로 보기"와 요약 aria-label.
 * 값은 모두 textContent로 넣는다(프로젝트 이름·파일 경로·규칙 키 포함). 규칙 키는 링크가 아닌 글자(P22가 설명 링크를 켠다).
 *
 * @var list<array{id:int, name:string, language:string}> $projects 보이는 프로젝트(프로젝트 선택 목록)
 * @var bool $isAdmin
 * @var int $trendProjects "프로젝트별 추이"의 프로젝트 수(admin 설정, P21C)
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 */
$projectOptions = static function (string $allKey, array $params = []) use ($projects, $e, $t): string {
    $html = '<option value="">' . $e($t($allKey, $params)) . '</option>';
    foreach ($projects as $p) {
        // data-language: 언어 필터(P21C)로 목록을 좁힐 때 쓴다
        $html .= '<option value="' . (int) $p['id'] . '" data-language="' . $e($p['language']) . '">' . $e($p['name']) . '</option>';
    }
    return $html;
};
$sectionHead = static function (string $id, string $titleKey, bool $chart) use ($e, $t, $icons): string {
    $html = '<div class="card__header"><h2 id="' . $e($id) . '-title">' . $e($t($titleKey)) . '</h2><div class="toolbar toolbar--compact">';
    if ($chart) {
        $html .= '<button type="button" class="btn btn--small" data-toggle-table aria-pressed="false">' . $e($t('dashboard.show_table')) . '</button>';
    }
    return $html . '</div></div>';
};
?>
<div class="page-header page-header--actions">
  <div>
    <h1><?= $icons->withLabel('nav.dashboard', 24, $t('dashboard.title')) ?></h1>
    <p class="muted"><?= $e($t('dashboard.intro')) ?></p>
  </div>
  <div class="toolbar">
    <span class="muted" data-as-of aria-live="polite"></span>
    <button type="button" class="btn" data-refresh><?= $icons->withLabel('action.fetch_now', 16, $t('dashboard.refresh')) ?></button>
  </div>
</div>

<div data-dashboard data-admin="<?= $isAdmin ? '1' : '0' ?>" data-app-base="<?= $e($assets->basePath()) ?>">
  <div data-empty hidden></div>

  <section class="card dash-section" data-section="summary" aria-labelledby="ds-summary-title" aria-busy="true">
    <?= $sectionHead('ds-summary', 'dashboard.summary_title', false) ?>
    <div class="skeleton" data-skeleton aria-hidden="true"></div>
    <div data-error-box hidden></div>
    <div class="stat-tiles" data-body hidden></div>
  </section>

  <?php /* 분석 언어 필터(P21C): 추이·규칙별 상위 10에 함께 적용(서버가 보이는 프로젝트 안에서 거름). 주소 ?language=python|javascript */ ?>
  <div class="card dash-filter" data-language-filter>
    <label for="ds-language"><?= $e($t('dashboard.language')) ?></label>
    <select class="field__input" id="ds-language" data-language>
      <option value=""><?= $e($t('dashboard.language_all')) ?></option>
      <option value="python"><?= $e($t('dashboard.language_python')) ?></option>
      <option value="javascript"><?= $e($t('dashboard.language_javascript')) ?></option>
    </select>
    <span class="muted" id="ds-language-help"><?= $e($t('dashboard.language_scope')) ?></span>
  </div>

  <section class="card dash-section" data-section="trend" aria-labelledby="ds-trend-title" aria-busy="true">
    <?= $sectionHead('ds-trend', 'dashboard.trend_title', true) ?>
    <div class="toolbar">
      <label for="ds-trend-project"><?= $e($t('dashboard.project')) ?></label>
      <select class="field__input" id="ds-trend-project" data-scope><?= $projectOptions('dashboard.trend_recent', ['n' => $trendProjects]) ?></select>
      <span class="muted"><?= $e($t('dashboard.trend_basis')) ?></span>
      <?php if ($isAdmin): ?>
      <button type="button" class="btn btn--small" data-trend-limit data-value="<?= (int) $trendProjects ?>"><?= $e($t('dashboard.trend_limit_button', ['n' => $trendProjects])) ?></button>
      <?php endif; ?>
    </div>
    <div class="skeleton" data-skeleton aria-hidden="true"></div>
    <div data-error-box hidden></div>
    <div data-body hidden>
      <div class="dash-charts" data-charts></div>
    </div>
  </section>

  <div class="dash-grid">
    <section class="card dash-section" data-section="top-rules" aria-labelledby="ds-rules-title" aria-busy="true">
      <?= $sectionHead('ds-rules', 'dashboard.top_rules_title', true) ?>
      <div class="toolbar">
        <label for="ds-rules-scope"><?= $e($t('dashboard.scope')) ?></label>
        <select class="field__input" id="ds-rules-scope" data-scope><?= $projectOptions('dashboard.scope_all') ?></select>
      </div>
      <div class="skeleton" data-skeleton aria-hidden="true"></div>
      <div data-error-box hidden></div>
      <div data-body hidden>
        <div class="chart-box chart-box--bars"><canvas data-canvas role="img"></canvas></div>
        <div class="table-scroll" data-table hidden></div>
      </div>
    </section>

    <section class="card dash-section" data-section="top-files" aria-labelledby="ds-files-title" aria-busy="true">
      <?= $sectionHead('ds-files', 'dashboard.top_files_title', true) ?>
      <div class="toolbar">
        <label for="ds-files-scope"><?= $e($t('dashboard.scope')) ?></label>
        <select class="field__input" id="ds-files-scope" data-scope><?= $projectOptions('dashboard.scope_all') ?></select>
      </div>
      <div class="skeleton" data-skeleton aria-hidden="true"></div>
      <div data-error-box hidden></div>
      <div data-body hidden>
        <div class="chart-box chart-box--bars"><canvas data-canvas role="img"></canvas></div>
        <div class="table-scroll" data-table hidden></div>
      </div>
    </section>
  </div>

  <section class="card dash-section" data-section="tools" aria-labelledby="ds-tools-title" aria-busy="true">
    <?= $sectionHead('ds-tools', 'dashboard.tools_title', false) ?>
    <div class="skeleton" data-skeleton aria-hidden="true"></div>
    <div data-error-box hidden></div>
    <div data-body hidden></div>
  </section>
</div>
