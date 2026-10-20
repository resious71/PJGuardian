// 화면 언어·테마 브라우저 점검(P27D, 헤드리스 Chrome). 격리 서버·DB 사본(025 적용)에서만 쓴다(관리자 계정의 화면 설정을 바꿨다 되돌린다).
//   PJG_PASSWORD=... node tools/check_i18n_theme_ui.mjs <점검 서버> [관리자] [--shots=a,b]
// 화면 × (ko, en) × (light, dark) × (1280, 1024): 로그인·업로드·프로젝트 목록/상세/편집·결함 목록/상세(코드 뷰어)·내 결함·분석 상세(성공·실패·변경 없음)·
//   대시보드·히스토리·규칙 목록/설명·알림·내 정보·그룹·관리자(사용자·도구·워커·점검·Run·규칙 설정·알림 보관·허용 호스트·주기 분석·설정 관리·백업·복원)·복원 진행 상태.
// 확인: 1280에서 가로 스크롤 0, 콘솔 오류 0, CSP 위반 0, 영어에서 화면 틀(머리글·메뉴·버튼·표 머리글·제목·라벨)에 한글 없음(사용자 입력·도구 원문은 제외 —
//   틀이 아닌 곳의 한글은 목록으로만 보고), 영어에서 버튼·메뉴·표 머리글·배지 글자 잘림(scrollWidth > clientWidth) 없음,
//   다크에서 body 배경이 어두운지(명시 다크·시스템 따라가기 에뮬레이션), 라이트는 data-theme=light일 때 밝은지. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P27D_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const [baseArg, adminLogin = 'admin'] = args;
const SHOTS = new Set((process.argv.find((a) => a.startsWith('--shots=')) ?? '--shots=').slice(8).split(',').filter(Boolean));
if (!baseArg || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_i18n_theme_ui.mjs <점검 서버> [관리자] [--shots=화면,…]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const problems = [];
const notes = [];
const csp = [];
const table = [];
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const page = await browser.newPage();
let current = '';
await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
page.on('console', (m) => {
  if (/CSP|Content Security Policy/.test(m.text())) csp.push(`${current}: ${m.text()}`);
  else if (/status of (401|403|404|409|410)/.test(m.text())) return; // 화면이 다루는 의도된 응답
  else if (m.type() === 'error') problems.push(`console.error ${current}: ${m.text()}`);
});
page.on('pageerror', (e) => problems.push(`pageerror ${current}: ${e.message}`));
await page.setViewport({ width: 1280, height: 900 });

const api = (method, url, body) => page.evaluate(async (m, u, b) => {
  const baseP = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const res = await fetch(baseP + u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: b === undefined ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body);

// 화면 틀(앱이 만드는 문구)에서 한글 찾기 — 사용자 입력·도구 원문이 들어갈 수 있는 곳은 제외하고, 그 밖은 참고 목록
let userTexts = []; // 사용자 입력(프로젝트·그룹 이름) — 틀 검사에서 빼고 본다
const scan = () => page.evaluate((userTexts) => {
  const strip = (v) => userTexts.reduce((acc, u) => acc.split(u).join(''), v);
  const H0 = /[\uAC00-\uD7A3]/;
  const H = { test: (v) => H0.test(strip(v)) };
  const frameSel = 'header .nav-item, header button, .subnav, h1, h2, h3, th, label, legend, .btn, button, .tabs__list, .field__label, .field__help, .page-header p, .notice, .empty, [data-empty], .footer, .dialog__title, summary';
  const frame = [];
  const other = [];
  const seen = new Set();
  for (const el of document.querySelectorAll(frameSel)) {
    if (el.closest('.code-viewer, .cm-editor, [data-user-content], select, option, td')) continue;
    const own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ');
    const text = (own || (el.children.length === 0 ? el.textContent.trim() : '')).slice(0, 80);
    if (text && H.test(text) && !seen.has(text)) { seen.add(text); frame.push(text); }
  }
  for (const el of document.querySelectorAll('[aria-label], [title]')) {
    for (const a of ['aria-label', 'title']) {
      const v = el.getAttribute(a);
      if (v && H.test(v) && !el.closest('td, option, .code-viewer') && !seen.has(v)) { seen.add(v); frame.push(`@${a}: ${v.slice(0, 80)}`); }
    }
  }
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const v = n.textContent.trim();
    if (v && H.test(v) && !seen.has(v) && !n.parentElement.closest('script, style')) { seen.add(v); other.push(v.slice(0, 60)); }
  }
  // 글자 잘림: 한 줄 버튼·메뉴·표 머리글·배지
  const clipped = [];
  for (const el of document.querySelectorAll('header .nav-item, .btn, th, .badge, .tag, .subnav__item, .tabs__list [role="tab"]')) {
    if (el.offsetParent === null) continue;
    const s = getComputedStyle(el);
    if (s.whiteSpace === 'nowrap' || s.textOverflow === 'ellipsis') {
      if (el.scrollWidth > el.clientWidth + 1 && s.overflow !== 'visible') clipped.push(el.textContent.trim().slice(0, 40));
    }
  }
  const bg = getComputedStyle(document.body).backgroundColor;
  const rgb = (bg.match(/\d+/g) ?? [255, 255, 255]).map(Number);
  const lum = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;
  return {
    frame, other: other.slice(0, 15), clipped, lum: Math.round(lum * 100) / 100,
    scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    lang: document.documentElement.lang, theme: document.documentElement.getAttribute('data-theme'),
    headerOverlap: (() => {
      const a = document.querySelector('.topbar__nav')?.getBoundingClientRect();
      const b = document.querySelector('.topbar__user')?.getBoundingClientRect();
      return a && b && a.top === b.top ? a.right > b.left + 1 : false;
    })(),
  };
}, userTexts);

async function visit(name, url, lang, theme, width) {
  current = `${name}[${lang}/${theme}/${width}]`;
  await page.setViewport({ width, height: 900 });
  let res = await page.goto(`${BASE}${url}`, { waitUntil: 'networkidle0', timeout: 60000 });
  // 같은 주소에서 해시만 다르면 이동 응답이 없다(X12의 설정 관리 #notification·#repository) — 새로 고침으로 화면을 다시 읽는다
  if (!res) res = await page.reload({ waitUntil: 'networkidle0', timeout: 60000 });
  await sleep(250);
  const r = await scan();
  const row = { name, lang, theme, width, status: res.status(), scroll: r.scroll, frame: r.frame.length, clipped: r.clipped.length, lum: r.lum };
  table.push(row);
  check(res.status() < 400 || name === 'forbidden', `HTTP ${current}`, res.status());
  check(r.lang === lang, `<html lang> ${current}`, r.lang);
  if (width === 1280) check(r.scroll <= 0, `가로 스크롤 ${current}`, r.scroll);
  if (lang === 'en') {
    check(r.frame.length === 0, `영어 화면 틀에 한글 ${current}`, r.frame.slice(0, 8));
    if (r.other.length) notes.push(`${current} 틀 밖 한글(사용자 입력·도구 원문인지 확인): ${r.other.slice(0, 5).join(' | ')}`);
    check(r.clipped.length === 0, `글자 잘림 ${current}`, r.clipped.slice(0, 5));
  }
  if (width === 1280) check(!r.headerOverlap, `헤더 겹침 ${current}`, true);
  check(theme === 'dark' ? r.lum < 0.25 : r.lum > 0.75, `테마 바탕 ${current}`, r.lum);
  const shot = `${name}_${lang}_${theme}_${width}`;
  if (SHOTS.has(name) || SHOTS.has(shot)) await page.screenshot({ path: path.join(OUT, `P27D_${shot}.png`), fullPage: true });
  return r;
}

const setPrefs = async (language, theme) => {
  const r = await api('PUT', '/api/v1/users/me/preferences', { language, theme });
  check(r.status === 200, 'preferences 저장', r);
};

try {
  // ---- 로그인 전(쿠키만) ----
  for (const lang of ['ko', 'en']) {
    for (const theme of ['light', 'dark']) {
      await page.setCookie({ name: 'pjg_lang', value: lang, url: BASE }, { name: 'pjg_theme', value: theme, url: BASE });
      for (const width of [1280, 1024]) await visit('login', '/login', lang, theme, width);
    }
  }
  // 로그인 화면 언어 버튼 → 쿠키만 바뀌고 다시 불러옴(서버에 쓰지 않음)
  await page.setCookie({ name: 'pjg_lang', value: 'ko', url: BASE }, { name: 'pjg_theme', value: 'light', url: BASE });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('[data-ui-lang]')]);
  check(await page.$eval('html', (h) => h.lang) === 'en', '로그인 화면 언어 버튼 → en', null);
  // 쿠키를 지우고 로그인(계정으로 옮겨지지 않게)
  await page.deleteCookie({ name: 'pjg_lang', url: BASE }, { name: 'pjg_theme', url: BASE });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', adminLogin);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  const before = (await api('GET', '/api/v1/users/me')).json?.data ?? {};

  // ---- 점검 대상 id ----
  const projects = (await api('GET', '/api/v1/projects?size=50')).json?.data ?? [];
  const pid = projects.find((p) => (p.defect_count ?? p.open_defects ?? 1) > 0)?.id ?? projects[0]?.id;
  const defects = pid ? ((await api('GET', `/api/v1/projects/${pid}/defects?size=5`)).json?.data ?? []) : [];
  const did = defects[0]?.id;
  const analyses = pid ? ((await api('GET', `/api/v1/projects/${pid}/analyses?size=200`)).json?.data ?? []) : [];
  const allAnalyses = [...analyses];
  for (const p of projects.slice(0, 10)) {
    if (p.id !== pid) allAnalyses.push(...((await api('GET', `/api/v1/projects/${p.id}/analyses?size=200`)).json?.data ?? []));
  }
  const okA = allAnalyses.find((a) => a.status === 'succeeded' && !a.no_change)?.id;
  const failA = allAnalyses.find((a) => a.status === 'failed')?.id;
  const sameA = allAnalyses.find((a) => a.no_change)?.id;
  const groups = (await api('GET', '/api/v1/groups?size=5')).json?.data ?? [];
  userTexts = [...projects.flatMap((p) => [p.name, p.description]), ...groups.flatMap((g) => [g.name, g.description])].filter(Boolean).sort((a, b) => b.length - a.length); // 긴 것부터(이름이 설명의 일부일 때)
  const pages = [
    ['upload', '/upload'], ['projects', '/projects'], ['project', pid && `/projects/${pid}`], ['project_edit', pid && `/projects/${pid}/edit`],
    ['defects', '/defects'], ['defect', did && `/defects/${did}`], ['my_defects', '/my-defects'],
    ['analysis_ok', okA && `/analyses/${okA}`], ['analysis_failed', failA && `/analyses/${failA}`], ['analysis_unchanged', sameA && `/analyses/${sameA}`],
    ['dashboard', '/dashboard'], ['history', '/history'], ['project_history', pid && `/projects/${pid}/history`],
    ['rules', '/rules'], ['rule', '/rules/ruff/F401'], ['rule_syntax', '/rules/syntax-error'], ['notifications', '/notifications'], ['account', '/account'],
    ['groups', '/groups'], ['group', groups[0] && `/groups/${groups[0].id}`],
    ['admin_users', '/admin/users'], ['admin_tools', '/admin/tools'], ['admin_worker', '/admin/worker'], ['admin_checks', '/admin/checks'], ['admin_runs', '/admin/runs'],
    ['admin_rule_settings', '/admin/rule-settings'], ['admin_notifications', '/admin/config-settings#notification'], ['admin_repo_hosts', '/admin/config-settings#repository'], // X12: 설정 관리 탭 구역
    ['admin_schedules', '/admin/schedules'], ['admin_settings', '/admin/config-settings'], ['admin_backups', '/admin/backups'], ['admin_monitoring', '/admin/monitoring'], ['admin_audit', '/admin/audit-logs'], ['restore_status', '/restore-status'],
  ].filter(([, u]) => u);
  for (const lang of ['ko', 'en']) {
    for (const theme of ['light', 'dark']) {
      await setPrefs(lang, theme);
      for (const [name, url] of pages) {
        for (const width of [1280, 1024]) await visit(name, url, lang, theme, width);
      }
    }
  }
  // ---- 시스템 따라가기: 계정 system + 운영체제 다크 에뮬레이션 → 다크, 라이트 에뮬레이션 → 라이트(JS 없이 CSS만) ----
  await setPrefs('en', 'system');
  for (const scheme of ['dark', 'light']) {
    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: scheme }]);
    const r = await visit('dashboard_system', '/dashboard', 'en', scheme, 1280);
    check(r.theme === null, '시스템 따라가기는 data-theme 없음', r.theme);
  }
  await page.emulateMediaFeatures([]);
  // ---- 헤더 테마 메뉴(키보드): 버튼 ↓ → 메뉴, ↓ ↓ Enter → 다음 선택, 다시 불러오지 않고 바로 적용 ----
  await setPrefs('ko', 'light');
  await page.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  await page.focus('[data-ui-theme-btn]');
  await page.keyboard.press('ArrowDown');
  const opened = await page.$eval('[data-ui-theme-menu]', (m) => !m.hidden);
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Enter');
  await sleep(300);
  const after = await page.evaluate(() => ({ theme: document.documentElement.getAttribute('data-theme'), checked: document.querySelector('[data-theme-value][aria-checked="true"]')?.dataset.themeValue, expanded: document.querySelector('[data-ui-theme-btn]').getAttribute('aria-expanded') }));
  check(opened && after.theme === 'dark' && after.checked === 'dark' && after.expanded === 'false', '테마 메뉴 키보드(↓ 열기 → 다크 선택, 바로 적용)', { opened, ...after });
  if (SHOTS.size) await page.screenshot({ path: path.join(OUT, 'P27D_theme_menu.png'), clip: { x: 640, y: 0, width: 640, height: 220 } });
  const saved = (await api('GET', '/api/v1/users/me')).json?.data;
  check(saved?.ui_theme === 'dark', '테마 계정 저장', saved?.ui_theme);
  // ---- 언어 버튼: 입력 중인 폼이 있으면 확인 대화상자(취소하면 그대로) ----
  await page.goto(`${BASE}/account`, { waitUntil: 'networkidle0' });
  const field = await page.$('main input[type="text"], main input:not([type])');
  if (field) {
    await field.type('x');
    await page.click('[data-ui-lang]');
    await page.waitForSelector('dialog[open]');
    const dlg = await page.$eval('dialog[open]', (d) => d.textContent);
    await page.keyboard.press('Escape');
    await sleep(200);
    check(/입력 중/.test(dlg) && await page.$eval('html', (h) => h.lang) === 'ko', '입력 중 언어 변경 확인(취소하면 그대로)', dlg.slice(0, 60));
  }
  // 되돌리기(점검 전 값)
  await setPrefs(before.ui_language ?? null, before.ui_theme ?? null);
} catch (e) {
  problems.push(`예외 ${current}: ${e.stack ?? e}`);
  await page.screenshot({ path: path.join(OUT, 'P27D_error.png'), fullPage: true }).catch(() => {});
} finally {
  await browser.close();
}

const summary = {};
for (const r of table) {
  const k = `${r.lang}/${r.theme}/${r.width}`;
  summary[k] ??= { pages: 0, scrollMax: 0, frameHangul: 0, clipped: 0 };
  summary[k].pages++;
  summary[k].scrollMax = Math.max(summary[k].scrollMax, r.scroll);
  summary[k].frameHangul += r.lang === 'en' ? r.frame : 0;
  summary[k].clipped += r.lang === 'en' ? r.clipped : 0;
}
console.log(JSON.stringify(summary, null, 1));
console.log(`방문 ${table.length}회, CSP 위반 ${csp.length}건${csp.length ? ': ' + csp.slice(0, 5).join(' | ') : ''}`);
if (notes.length) console.log(`참고(틀 밖 한글) ${notes.length}건:\n- ${[...new Set(notes)].slice(0, 25).join('\n- ')}`);
if (problems.length || csp.length) {
  console.log(`문제 ${problems.length}건:\n- ${problems.slice(0, 60).join('\n- ')}`);
  process.exit(1);
}
console.log('언어·테마 화면 점검 통과');
