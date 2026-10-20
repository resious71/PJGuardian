<?php
/**
 * 가이드(P29, 명세 16장). 탭은 Services\Guide\GuideTabs 한 곳(관리자 가이드 탭은 admin에게만 그린다 — 비관리자 HTML에 없음).
 * 사용자·관리자 가이드: 목차 + 검색(결과는 절 목록) + 절 하나(X11 — 해시 #key로 한 번에 한 절, 본문은 pages/guide.js가 API로 받아 안전한 마크다운 렌더러로 — textContent만).
 * 오류 코드: 카탈로그 표(pages/guide.js). 라이선스: THIRD_PARTY_NOTICES를 글자 그대로(이스케이프).
 * 아래 공통: 한 줄 바닥글(앱·ruff·ESLint 버전 · 라이선스 고지 링크 · 아이콘 뜻 링크, X10). 아이콘 범례(icons.json에서 자동)는 사용자 가이드 "화면 읽는 법" 절 본문에만.
 *
 * @var string $tab user|admin|errors|licenses
 * @var list<array{id:string, label:string, icon:string, path:string, admin:bool}> $tabs
 * @var bool $isAdmin
 * @var array{app:string, ruff:string, eslint:string} $versions
 * @var string|null $licenses
 * @var callable $e
 * @var callable $t
 * @var \PJGuardian\Support\Icons $icons
 * @var \PJGuardian\Support\Assets $assets
 * @var \PJGuardian\Support\View $view
 * @var array<string, string> $iconGroups
 */
$current = $tab === 'licenses' ? null : $tab;
?>
<div class="page-header">
  <div>
    <h1><?= $icons->withLabel($tab === 'admin' ? 'admin.guide' : 'nav.guide', 24, $t($tab === 'admin' ? 'guide.title_admin' : 'guide.title')) ?></h1>
    <p class="muted"><?= $e($t('guide.intro')) ?></p>
  </div>
</div>

<nav class="tabs__list guide-tabs" aria-label="<?= $e($t('guide.tabs_label')) ?>">
  <?php foreach ($tabs as $tb): ?>
  <a class="tab-link" href="<?= $e($assets->page(ltrim($tb['path'], '/'))) ?>" data-guide-tab="<?= $e($tb['id']) ?>"<?= $current === $tb['id'] ? ' aria-current="page"' : '' ?>><?= $icons->withLabel($tb['icon'], 16, $t($tb['label'])) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'user' || $tab === 'admin'): ?>
<div class="guide" data-guide data-audience="<?= $e($tab) ?>">
  <aside class="guide__side">
    <div class="field guide__search">
      <label class="field__label" for="guide-search"><?= $e($t('guide.search_label')) ?></label>
      <input class="field__input" type="search" id="guide-search" autocomplete="off" spellcheck="false" maxlength="100" aria-describedby="guide-search-help guide-search-status" data-guide-search>
      <p class="field__help" id="guide-search-help"><?= $e($t('guide.search_help')) ?></p>
      <p class="muted guide__search-status" id="guide-search-status" role="status" aria-live="polite" data-guide-search-status></p>
      <ol class="guide__results" aria-label="<?= $e($t('guide.results_label')) ?>" data-guide-results hidden></ol>
    </div>
    <nav class="guide__toc" aria-label="<?= $e($t('guide.toc_label')) ?>">
      <ol data-guide-toc></ol>
    </nav>
  </aside>
  <p class="sr-only" aria-live="polite" data-guide-live></p>
  <div class="guide__body" data-guide-body>
    <p class="muted" data-guide-loading><?= $e($t('defects.loading')) ?></p>
  </div>
</div>
<dialog class="dialog guide-img-dialog" data-guide-img-dialog aria-label="<?= $e($t('guide.image_dialog')) ?>">
  <div class="guide-img-dialog__body"><img alt="" data-guide-img-full></div>
  <div class="dialog__actions"><button type="button" class="btn" data-guide-img-close><?= $e($t('guide.image_close')) ?></button></div>
</dialog>
<?php elseif ($tab === 'faq'): ?>
<?php /* P29B FAQ: 목록·검색은 pages/faq.js가 /api/v1/faq로(답변은 안전한 마크다운 렌더러 — textContent만). 편집 단추·대화상자는 admin에게만 만든다(data-admin) */ ?>
<section class="faq" data-faq data-admin="<?= $isAdmin ? '1' : '0' ?>" data-ready="<?= !empty($faqReady) ? '1' : '0' ?>" aria-labelledby="faq-title">
  <h2 id="faq-title"><?= $icons->withLabel('nav.faq', 20, $t('faq.title')) ?></h2>
  <?php if (empty($faqReady)): ?>
  <p class="notice notice--info"><?= $icons->icon('ui.info', 16) ?> <?= $e($t('faq.unavailable')) ?></p>
  <?php else: ?>
  <?php /* X15: 검색 입력 → 분류 선택 → 검색 단추를 한 줄(같은 높이). 라벨은 화면 읽기용(sr-only)으로 두고 입력은 placeholder, 선택 상자는 첫 항목 "모든 분류"가 보이는 이름. 도움말은 줄 아래 */ ?>
  <form class="faq__toolbar" data-faq-search role="search" aria-label="<?= $e($t('faq.search_label')) ?>">
    <div class="faq__search-row" data-faq-search-row>
      <label class="sr-only" for="faq-q"><?= $e($t('faq.search_label')) ?></label>
      <input class="field__input faq__q" type="search" id="faq-q" name="q" maxlength="100" autocomplete="off" placeholder="<?= $e($t('faq.search_label')) ?>" aria-describedby="faq-q-help">
      <label class="sr-only" for="faq-category"><?= $e($t('faq.category_filter')) ?></label>
      <select class="field__input faq__cat" id="faq-category" name="category" data-faq-category><option value=""><?= $e($t('faq.category_all')) ?></option></select>
      <button type="submit" class="btn faq__go" data-faq-go><?= $icons->withLabel('action.search', 16, $t('faq.search')) ?></button>
    </div>
    <p class="field__help faq__help" id="faq-q-help"><?= $e($t('faq.search_help')) ?></p>
  </form>
  <div class="faq__bar">
    <button type="button" class="btn btn--small" data-faq-expand-all><?= $e($t('faq.expand_all')) ?></button>
    <button type="button" class="btn btn--small" data-faq-collapse-all><?= $e($t('faq.collapse_all')) ?></button>
    <?php if ($isAdmin): ?>
    <button type="button" class="btn btn--primary btn--small" data-faq-add><?= $icons->withLabel('action.add', 16, $t('faq.add')) ?></button>
    <?php endif; ?>
    <p class="muted faq__status" role="status" aria-live="polite" data-faq-status></p>
  </div>
  <p class="notice notice--error" role="alert" data-faq-error hidden></p>
  <div class="faq__list" data-faq-list><p class="muted"><?= $e($t('defects.loading')) ?></p></div>
  <?php endif; ?>
</section>
<?php elseif ($tab === 'errors'): ?>
<section class="guide-errors" data-guide-errors aria-labelledby="guide-errors-title">
  <h2 id="guide-errors-title"><?= $e($t('guide.errors_title')) ?></h2>
  <p class="muted"><?= $e($t('guide.errors_intro')) ?></p>
  <div data-guide-errors-body><p class="muted"><?= $e($t('defects.loading')) ?></p></div>
</section>
<?php else: ?>
<section class="guide-licenses" aria-labelledby="guide-licenses-title">
  <h2 id="guide-licenses-title"><?= $e($t('guide.licenses_title')) ?></h2>
  <div class="field guide__search">
    <label class="field__label" for="guide-licenses-search"><?= $e($t('guide.licenses_search')) ?></label>
    <input class="field__input" type="search" id="guide-licenses-search" autocomplete="off" spellcheck="false" maxlength="100" aria-describedby="guide-licenses-status" data-licenses-search>
    <p class="muted" id="guide-licenses-status" role="status" aria-live="polite" data-licenses-status></p>
  </div>
  <pre class="guide-licenses__text" tabindex="0" data-licenses><?= $e((string) $licenses) ?></pre>
</section>
<?php endif; ?>

<?php /* X10: 한 줄 바닥글(버전 · 라이선스 · 아이콘 뜻 링크). 아이콘 범례는 사용자 가이드 "화면 읽는 법" 절 본문에만({{legend:icons}} 자리 — 아래 원본을 화면 스크립트가 복제) */ ?>
<footer class="guide-footer" aria-label="<?= $e($t('guide.footer_title')) ?>">
  <p class="guide-footer__line" data-guide-versions>
    <span title="<?= $e($t('guide.version_app')) ?>">PJGuardian <span class="mono"><?= $e($versions['app']) ?></span></span>
    <span aria-hidden="true">·</span>
    <span title="<?= $e($t('guide.version_ruff')) ?>">ruff <span class="mono" data-version="ruff"><?= $e($versions['ruff']) ?></span></span>
    <span aria-hidden="true">·</span>
    <span title="<?= $e($t('guide.version_eslint')) ?>">ESLint <span class="mono" data-version="eslint"><?= $e($versions['eslint']) ?></span></span>
    <span aria-hidden="true">·</span>
    <a href="<?= $e($assets->page('guide/licenses')) ?>" data-guide-licenses-link><?= $e($t('guide.licenses_link')) ?></a>
    <span data-guide-legend-link-wrap><span aria-hidden="true">·</span>
    <a href="<?= $e($assets->page('guide/user') . '#shortcuts-and-icons') ?>" data-guide-legend-link><?= $e($t('guide.legend_link')) ?></a></span>
  </p>
</footer>
<?php if ($tab === 'user'): ?>
<div class="guide-legend-source" data-guide-legend-source hidden>
  <?= $view->partial('partials/icon_legend', ['groups' => array_values(array_diff(array_keys($iconGroups), ['admin'])), 'guide' => true]) ?>
</div>
<?php endif; ?>
