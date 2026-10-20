// Run 화면 브라우저 점검(P20R, 헤드리스 Chrome). 격리 서버·DB(또는 개발 DB 사본)에서만 쓴다.
// 결함 탭의 Run 선택기(최신 기본, 키보드로 고름, 주소 ?run=N), 과거 Run 안내와 읽기 전용(선택 열·일괄 변경 없음), 건수 변화,
// 결함 상세의 과거 Run(수정 폼 대신 안내, 코드 "Run N 시점", 존재 구간), 그 Run에 없던 결함 안내, 최신 Run으로 이동,
// 분석 상세 제목 "Run N · 분석 #id", 대시보드 추이 표의 Run 링크, 기존 링크(필터·analysis+change) 호환, 콘솔 오류 0, CSP 위반 0.
//   PJG_PASSWORD=... node tools/check_run_ui.mjs <기본 주소> <프로젝트 id> <분석 id(성공)> [로그인 ID]
//   캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P20R_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, analysisId, login = 'admin'] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};

const page = await (await browser.createBrowserContext()).newPage();
await page.setViewport({ width: 1366, height: 1000 });
await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
page.on('console', (m) => {
  if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
  else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
});
page.on('response', (res) => { if (res.status() >= 400) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
const api = (p) => page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), `${BASE}${p}`);
const rel = () => { const u = new URL(page.url()); return u.pathname + u.search; };

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('#login-id', login);
await page.type('#login-password', process.env.PJG_PASSWORD);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);

const runs = (await api(`/api/v1/projects/${projectId}/runs?size=200`)).data;
const latest = runs[0].run;
const past = runs[runs.length - 1].run;

async function defectsTab(query = '') {
  await page.goto(`${BASE}/projects/${projectId}${query}`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-defects]')?.getAttribute('aria-busy') !== 'true' && document.querySelector('[data-count]')?.textContent.includes('건'), { timeout: 30000 });
  await page.waitForSelector('[data-run-selector] select, [data-run-selector] .run-selector__value', { timeout: 15000 });
  return page.evaluate(() => ({
    count: document.querySelector('[data-count]').textContent,
    options: [...document.querySelectorAll('[data-run-select] option')].map((o) => ({ value: o.value, text: o.textContent, selected: o.selected })),
    banner: document.querySelector('[data-run-banner] .run-banner')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
    selectColumn: !document.querySelector('[data-select-page]').closest('th').hidden,
    rowCheckboxes: document.querySelectorAll('[data-rows] input[data-select]').length,
    latestNote: [...document.querySelectorAll('[data-latest-location]')].filter((n) => !n.hidden).map((n) => n.textContent),
    firstLink: document.querySelector('[data-rows] a[href*="/defects/"]')?.getAttribute('href') ?? null,
    lastSeen: document.querySelector('[data-rows] tr td:last-child')?.textContent ?? null,
    label: document.querySelector('label[for^="run-select"]')?.textContent.trim() ?? null,
  }));
}

// 1. 최신 Run(기본)
r.latest = await defectsTab();
await page.screenshot({ path: `${OUT}/P20R_run_latest.png`, fullPage: false });
// 2. 키보드로 과거 Run 고르기: 포커스 → 화살표(이동하지 않음) → Enter로 이동
await page.focus('[data-run-select]');
for (let i = 0; i < runs.length - 1; i++) await page.keyboard.press('ArrowDown');
r.keyboardBeforeEnter = { url: rel(), value: await page.$eval('[data-run-select]', (s) => s.value) };
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
r.keyboardUrl = rel();
r.past = await defectsTab(new URL(page.url()).search);
await page.screenshot({ path: `${OUT}/P20R_run_past.png`, fullPage: false });
// 3. 과거 Run 결함 상세
await page.goto(`${BASE}${r.past.firstLink}`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-code-loading]')?.hidden, { timeout: 20000 });
r.detailPast = await page.evaluate(() => ({
  url: location.pathname + location.search,
  banner: document.querySelector('[data-run-banner]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
  editForm: Boolean(document.querySelector('[data-edit-form]')),
  readOnlyNote: document.querySelector('[data-read-only-edit]')?.textContent.trim() ?? null,
  runs: document.querySelector('[data-field="runs"]')?.textContent.trim(),
  firstSeen: document.querySelector('[data-field="first_seen"]')?.textContent.replace(/\s+/g, ' ').trim(),
  codeSource: document.querySelector('[data-code-source]')?.textContent,
  codeNotes: [...document.querySelectorAll('[data-code-notices] [data-notice]')].map((n) => n.dataset.notice),
  selector: document.querySelector('[data-run-select]')?.value ?? null,
}));
await page.screenshot({ path: `${OUT}/P20R_defect_past.png`, fullPage: true });
// 4. 그 Run에 없던 결함(최신에만 있는 결함 하나를 가장 오래된 Run으로)
const inPast = new Set((await api(`/api/v1/projects/${projectId}/defects?run=${past}&size=200&sort=last_seen&order=desc`)).data.map((d) => d.id));
const candidates = (await api(`/api/v1/projects/${projectId}/defects?size=200&sort=last_seen&order=desc`)).data.filter((d) => !inPast.has(d.id));
let notIn = null;
for (const d of candidates) {
  const detail = await api(`/api/v1/defects/${d.id}?run=${past}`);
  if (detail.data?.run_view && !detail.data.run_view.in_run) { notIn = d.id; break; }
}
if (notIn) {
  await page.goto(`${BASE}/defects/${notIn}?run=${past}`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-code-loading]')?.hidden, { timeout: 20000 });
  r.notInRun = await page.evaluate(() => ({
    notice: document.querySelector('[data-not-in-run]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
    code: document.querySelector('[data-code-empty]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
  }));
  await page.screenshot({ path: `${OUT}/P20R_defect_not_in_run.png`, fullPage: false });
  // 최신 Run으로 이동
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('[data-not-in-run] a.btn')]);
  r.goLatest = { url: rel(), editForm: await page.$('[data-edit-form]') !== null };
} else {
  r.notInRun = '대상 없음';
}
// 5. 분석 상세 제목
await page.goto(`${BASE}/analyses/${analysisId}`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-loading]')?.hidden !== false, { timeout: 15000 });
r.analysis = await page.evaluate(() => ({ title: document.querySelector('[data-analysis-title]')?.textContent, runLink: document.querySelector('[data-run-defects]')?.getAttribute('href'), baseline: document.querySelector('[data-baseline]')?.textContent }));
await page.screenshot({ path: `${OUT}/P20R_analysis_run.png`, fullPage: false });
// 6. 기존 링크 호환(P19 analysis+change, 필터)
r.legacy = await defectsTab(`?analysis=${analysisId}&change=new`);
r.legacy.filterText = await page.evaluate(() => document.querySelector('[data-analysis-filter-text]')?.textContent);
r.legacyStatus = (await defectsTab('?status=open')).count;
// 7. 대시보드 추이: 표로 보기의 첫 열 Run 링크
await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-section="trend"]')?.getAttribute('aria-busy') === 'false', { timeout: 30000 });
const toggle = await page.$('[data-section="trend"] [data-toggle-table]');
await toggle.focus();
await page.keyboard.press('Enter');
r.dashboard = await page.evaluate(() => [...document.querySelectorAll('[data-section="trend"] [data-table] tbody tr th a')].map((a) => `${a.textContent} → ${a.getAttribute('href')}`));
await page.screenshot({ path: `${OUT}/P20R_dashboard_runs.png`, fullPage: false });

console.log(JSON.stringify({ base: BASE, login, latest, past, result: r, csp, problems }, null, 2));
await browser.close();
process.exit(csp.length || problems.length ? 1 : 0);
