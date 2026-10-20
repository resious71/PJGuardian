// 히스토리·P21 화면 브라우저 점검(헤드리스 Chrome). 격리 서버·DB(또는 개발 DB 사본)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_history_ui.mjs <기본 주소> <프로젝트 id> <긴 셀 결함 id> [로그인]
// 확인: 정적 파일 버전(문자열 파일을 바꾼 뒤 일반 새로 고침만으로 새 문구 — 확인 뒤 원래대로 되돌림), 히스토리 이력 표·차트·표로 보기(키보드),
//   비교 패널(카드 건수 = 이동한 결함 목록 건수, 이전·다음, 첫 Run "비교 대상 없음"), 결함 전체 이력 창(열기·Esc 닫기·포커스 복귀),
//   긴 셀 말줄임과 hover·포커스 툴팁(전체 값, Esc 닫기, HTML 무해), 좁은 창, 대시보드 표로 보기(프로젝트별 제목 → 표, 전환 반복),
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21_*.png
import path from 'node:path';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, longDefect, login = 'admin'] = process.argv;
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
const rel = () => { const u = new URL(page.url()); return u.pathname + u.search; };
const ready = (sel, timeout = 20000) => page.waitForSelector(sel, { timeout });

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('#login-id', login);
await page.type('#login-password', process.env.PJG_PASSWORD);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);

const historyUrl = `${BASE}/projects/${projectId}/history`;
async function openHistory(url = historyUrl) {
  await page.goto(url, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-history-status]')?.textContent === '' && !document.querySelector('[data-history-panel]').hidden
    && document.querySelector('[data-panel-baseline]')?.textContent !== '불러오는 중…', { timeout: 30000 });
}

// 1. 정적 파일 버전: ko.js 문구를 바꾼 뒤 일반 새로 고침(page.reload)만으로 반영되는지
const koFile = path.join(ROOT, 'public/assets/js/i18n/ko.js');
const original = readFileSync(koFile, 'utf8');
try {
  await openHistory();
  const caption = () => page.$eval('[data-history-figure] figcaption', (n) => n.textContent);
  const before = await caption();
  writeFileSync(koFile, original.replace("'history.chart_caption': '최근 Run {n}개'", "'history.chart_caption': '최근 Run {n}개[갱신 확인]'"));
  await page.reload({ waitUntil: 'networkidle0' });
  await page.waitForSelector('[data-history-figure] figcaption');
  const after = await caption();
  writeFileSync(koFile, original);
  await page.reload({ waitUntil: 'networkidle0' });
  await page.waitForSelector('[data-history-figure] figcaption');
  const restored = await caption();
  const assets = await page.$$eval('script[src], link[href]', (ns) => ns.map((n) => n.getAttribute('src') ?? n.getAttribute('href')).filter((u) => u.includes('/assets/')));
  r.cache = { before, after, restored, versioned: assets.every((u) => /\/assets\/v\/[0-9a-f]{12}\//.test(u)), assets: assets.length };
} finally {
  writeFileSync(koFile, original);
}

// 2. 히스토리 화면
await openHistory();
r.history = await page.evaluate(() => ({
  url: location.pathname + location.search,
  rows: [...document.querySelectorAll('[data-history-rows] tr')].map((tr) => [...tr.children].map((c) => c.textContent.replace(/\s+/g, ' ').trim()).slice(0, 8).join(' | ')),
  panelTitle: document.querySelector('[data-panel-title]').textContent,
  baseline: document.querySelector('[data-panel-baseline]').textContent,
  cards: Object.fromEntries([...document.querySelectorAll('[data-card]')].map((c) => [c.dataset.card, c.querySelector('.result-counts__n').textContent])),
  notices: [...document.querySelectorAll('[data-panel-notices] [data-notice]')].map((n) => n.dataset.notice),
  tops: [...document.querySelectorAll('[data-top]')].map((s) => `${s.dataset.top}: ${s.querySelectorAll('li').length}`),
  canvas: document.querySelector('[data-history-figure] canvas')?.getAttribute('aria-label'),
}));
await page.screenshot({ path: `${OUT}/P21_history.png`, fullPage: true });
// 표로 보기(키보드)
await page.focus('[data-history-chart] [data-toggle-table]');
await page.keyboard.press('Enter');
r.chartTable = await page.evaluate(() => ({ pressed: document.querySelector('[data-history-chart] [data-toggle-table]').getAttribute('aria-pressed'),
  table: !document.querySelector('[data-history-figure] [data-table-part]').hidden, chart: !document.querySelector('[data-history-figure] .chart-box').hidden,
  focus: document.activeElement?.matches('[data-toggle-table]'), rows: document.querySelectorAll('[data-history-figure] tbody tr').length }));
await page.keyboard.press('Enter');
// 카드 → 결함 목록 건수 일치
// 해결 카드로 대조(점검용 사본에 넣은 긴 셀 결함은 Run 3의 신규로 잡혀 신규 목록이 저장 건수보다 1 많다)
const card = await page.$eval('[data-card="resolved"] a', (a) => ({ href: a.getAttribute('href'), n: a.textContent }));
await page.goto(`${BASE}${card.href}`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-count]')?.textContent.includes('건'), { timeout: 30000 });
r.cardList = { card: card.n, list: await page.$eval('[data-count]', (n) => n.textContent), filter: await page.$eval('[data-analysis-filter-text]', (n) => n.textContent) };
// 이전 Run → 첫 Run(비교 대상 없음)
await openHistory();
const prevs = [];
for (let i = 0; i < 5; i++) {
  const disabled = await page.$eval('[data-panel-prev]', (b) => b.disabled);
  if (disabled) break;
  const before = await page.evaluate(() => location.search);
  await page.click('[data-panel-prev]');
  await page.waitForFunction((u) => location.search !== u, {}, before);
  await page.waitForFunction(() => document.querySelector('[data-panel-baseline]').textContent !== '불러오는 중…');
  prevs.push({ url: rel(), title: await page.$eval('[data-panel-title]', (n) => n.textContent), baseline: await page.$eval('[data-panel-baseline]', (n) => n.textContent) });
}
r.prev = prevs;
await page.screenshot({ path: `${OUT}/P21_history_first_run.png`, fullPage: false });

// 3. 결함 전체 이력 창
await page.goto(`${BASE}/defects/${longDefect}`, { waitUntil: 'networkidle0' });
r.detailLinks = await page.$$eval('[data-field="first_seen"] a', (as) => as.map((a) => a.getAttribute('href')));
await page.focus('[data-open-timeline]');
await page.keyboard.press('Enter');
await page.waitForSelector('dialog [data-timeline] tbody tr', { timeout: 15000 });
r.timeline = await page.evaluate(() => ({
  summary: document.querySelector('dialog [data-timeline-summary]').textContent,
  rows: [...document.querySelectorAll('dialog [data-timeline] tbody tr')].map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()),
  focus: document.activeElement?.matches('dialog [data-dialog-close]'),
}));
await page.screenshot({ path: `${OUT}/P21_timeline.png`, fullPage: false });
await page.keyboard.press('Escape');
await page.waitForFunction(() => !document.querySelector('dialog'));
r.timelineClosed = await page.evaluate(() => ({ dialog: Boolean(document.querySelector('dialog')), focusBack: document.activeElement?.matches('[data-open-timeline]') }));
// 실제 이력이 있는 결함(수동 변경 이력이 있는 결함 하나)
const withHistory = await page.evaluate(async (pid) => {
  const res = await (await fetch(`/api/v1/projects/${pid}/defects?status=resolved&size=1`, { headers: { Accept: 'application/json' } })).json();
  return res.data[0]?.id ?? null;
}, projectId);
if (withHistory) {
  await page.goto(`${BASE}/defects/${withHistory}`, { waitUntil: 'networkidle0' });
  await page.click('[data-open-timeline]');
  await page.waitForSelector('dialog [data-timeline] tbody tr', { timeout: 15000 });
  r.timelineReal = await page.evaluate(() => ({ summary: document.querySelector('dialog [data-timeline-summary]').textContent,
    rows: [...document.querySelectorAll('dialog [data-timeline] tbody tr')].map((tr) => [...tr.children].map((c) => c.textContent.trim()).join(' | ')) }));
  await page.screenshot({ path: `${OUT}/P21_timeline.png`, fullPage: false });
  await page.keyboard.press('Escape');
}

// 4. 긴 셀
await page.goto(`${BASE}/projects/${projectId}?q=${encodeURIComponent('very/long/directory')}`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-rows] tr'), { timeout: 30000 });
const cell = async () => page.evaluate(() => {
  const loc = document.querySelector('[data-rows] .defects-table__location [data-clip]');
  const msg = document.querySelector('[data-rows] .defects-table__message [data-clip]');
  const head = loc.querySelector('.clip__head');
  return { locText: loc.textContent, locTail: loc.querySelector('.clip__tail').textContent, tailVisible: loc.querySelector('.clip__tail').getBoundingClientRect().width > 0,
    headCut: head.scrollWidth > head.clientWidth, msgCut: msg.scrollWidth > msg.clientWidth, titleAttrs: document.querySelectorAll('[data-rows] .defects-table__location[title], [data-rows] .defects-table__message[title], [data-rows] [data-clip][title]').length,
    imgs: document.querySelectorAll('[data-rows] img').length, pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth };
});
r.long = await cell();
await page.hover('[data-rows] .defects-table__message [data-clip]');
await new Promise((res) => setTimeout(res, 200));
r.hoverTip = await page.evaluate(() => { const t = document.getElementById('pjg-clip-tip'); return t && !t.hidden ? t.textContent.slice(0, 60) : null; });
await page.screenshot({ path: `${OUT}/P21_long_cell.png`, fullPage: false });
await page.mouse.move(5, 5);
await page.focus('[data-rows] .defects-table__message [data-clip]');
r.focusTip = await page.evaluate(() => { const t = document.getElementById('pjg-clip-tip'); return { shown: t && !t.hidden, described: document.activeElement.getAttribute('aria-describedby') }; });
await page.keyboard.press('Escape');
r.escTip = await page.evaluate(() => document.getElementById('pjg-clip-tip')?.hidden ?? null);
await page.setViewport({ width: 800, height: 900 });
await page.reload({ waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-rows] tr'), { timeout: 30000 });
r.narrow = await cell();
await page.screenshot({ path: `${OUT}/P21_long_cell_narrow.png`, fullPage: false });
await page.setViewport({ width: 1366, height: 1000 });

// 5. 대시보드 표로 보기(프로젝트별)
await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-section="trend"]')?.getAttribute('aria-busy') === 'false', { timeout: 30000 });
const order = () => page.evaluate(() => {
  const out = [];
  for (const n of document.querySelectorAll('[data-section="trend"] [data-charts] figcaption, [data-section="trend"] [data-charts] table')) {
    if (n.closest('[hidden]')) continue;
    out.push(n.tagName === 'FIGCAPTION' ? `제목:${n.textContent}` : `표:${n.querySelector('caption').textContent}`);
  }
  return out;
});
const toggleDash = async () => { await page.focus('[data-section="trend"] [data-toggle-table]'); await page.keyboard.press('Enter'); };
await toggleDash();
r.dashTable = await order();
await page.screenshot({ path: `${OUT}/P21_dashboard_table_mode.png`, fullPage: false, clip: await page.$eval('[data-section="trend"]', (s) => { const b = s.getBoundingClientRect(); return { x: 0, y: b.top + window.scrollY, width: 1366, height: Math.min(b.height, 1600) }; }) });
for (let i = 0; i < 5; i++) await toggleDash();
r.dashAfterToggles = { order: await order(), figures: await page.$$eval('[data-section="trend"] figure', (f) => f.length), focus: await page.evaluate(() => document.activeElement?.matches('[data-toggle-table]')) };

// 6. 메뉴·선택 화면
await page.goto(`${BASE}/history`, { waitUntil: 'networkidle0' });
r.picker = await page.$$eval('[data-history-projects] tbody a', (as) => as.map((a) => `${a.textContent} → ${a.getAttribute('href')}`));
r.nav = await page.$eval('[data-nav="nav.history"]', (a) => a.getAttribute('href'));

console.log(JSON.stringify({ base: BASE, login, result: r, csp, problems }, null, 2));
await browser.close();
process.exit(csp.length || problems.length ? 1 : 0);
