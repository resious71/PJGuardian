// 내보내기 브라우저 점검(P23, 헤드리스 Chrome). 격리 서버·DB 사본(+ 상한을 넘는 합성 프로젝트)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_export_ui.mjs <기본 주소> <일반 사용자> <결함 프로젝트(과거 Run 있음)> <상한 초과 프로젝트> [관리자]
// 확인(1280·1024px):
//   - 버튼 위치: 표 바로 위 한 줄(건수 왼쪽, Excel·CSV 오른쪽 맞춤) — 버튼 오른쪽 끝 = 표 상자 오른쪽 끝, 가로 스크롤 0
//   - 클릭 → 실제 파일 내려받기(Chrome 다운로드 이벤트·파일 크기·응답 Content-Disposition의 이름), 내려받은 CSV 행 수 = 화면 건수
//   - 검색 영역에 적용하지 않은 변경이 있으면 안내 + 보내는 조건은 마지막으로 검색한 조건
//   - 과거 Run(run=N): "Run N 기준" 안내·파일 이름 _RunN, 0건이면 버튼 꺼짐, 상한 초과 안내(건수·상한)
//   - 내게 지정된 결함·규칙 설명 목록 내보내기(탭 이름이 파일 이름에), 키보드(Enter)로 내보내기
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P23_*.png
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, projectId, bigProject, adminLogin = 'admin'] = process.argv;
if (!userLogin || !projectId || !bigProject || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_export_ui.mjs <기본 주소> <일반 사용자> <결함 프로젝트> <상한 초과 프로젝트> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const DL = fs.mkdtempSync(path.join(os.tmpdir(), 'pjg-export-'));
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of 413/.test(m.text())) return;
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400 && res.status() !== 413) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  const cdp = await browser.target().createCDPSession();
  const dir = fs.mkdtempSync(path.join(DL, `${login}-`));
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: dir, eventsEnabled: true });
  const done = [];
  cdp.on('Browser.downloadWillBegin', (e) => done.push({ guid: e.guid, name: e.suggestedFilename, state: 'begin' }));
  cdp.on('Browser.downloadProgress', (e) => { const d = done.find((x) => x.guid === e.guid); if (d && e.state !== 'inProgress') d.state = e.state; });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return { browser, page, dir, downloads: done };
}

/** 내보내기 버튼 클릭 → 응답(본문 조건·상태·이름)과 내려받은 파일 */
async function exportVia(s, selector, { keyboard = false } = {}) {
  const before = s.downloads.length;
  const reqP = s.page.waitForRequest((q) => q.url().includes('/export') && q.method() === 'POST');
  const resP = s.page.waitForResponse((q) => q.url().includes('/export') && q.request().method() === 'POST');
  if (keyboard) {
    await s.page.focus(selector);
    await s.page.keyboard.press('Enter');
  } else {
    await s.page.click(selector);
  }
  const req = await reqP;
  const res = await resP;
  const out = { body: JSON.parse(req.postData() ?? '{}'), status: res.status(), disposition: res.headers()['content-disposition'] ?? null };
  if (res.status() === 200) {
    const t0 = Date.now();
    while (Date.now() - t0 < 20000 && !(s.downloads.length > before && s.downloads[s.downloads.length - 1].state === 'completed')) await sleep(100);
    const d = s.downloads[s.downloads.length - 1];
    out.file = d?.name ?? null;
    out.bytes = d && d.state === 'completed' ? fs.statSync(path.join(s.dir, d.name)).size : 0;
    out.path = d ? path.join(s.dir, d.name) : null;
  } else {
    await s.page.waitForFunction(() => document.querySelector('#toasts .toast--error'), { timeout: 10000 });
    out.toast = await s.page.$eval('#toasts .toast--error', (n) => n.textContent.trim());
  }
  await s.page.waitForFunction(() => !document.querySelector('[data-export]')?.hasAttribute('aria-busy'), { timeout: 10000 });
  return out;
}
const csvRows = (p) => {
  const text = fs.readFileSync(p, 'utf8').replace(/^\uFEFF/, '');
  let rows = 0;
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    if (text[i] === '"') quoted = !quoted;
    else if (!quoted && text[i] === '\n') rows++;
  }
  return rows - 1; // 머리글 제외
};
const defectsReady = (p) => p.waitForFunction(() => !document.querySelector('[data-defects], [data-my-defects]')?.hasAttribute('aria-busy') && /결함 [\d,]+건|[\d,]+건/.test(document.querySelector('[data-count]')?.textContent ?? ''), { timeout: 30000 });
const screenCount = (p) => p.$eval('[data-count]', (n) => Number(n.textContent.replace(/[^\d]/g, '')));
const layout = (p) => p.evaluate(() => {
  const bar = document.querySelector('[data-export]').getBoundingClientRect();
  const box = document.querySelector('[data-export]').closest('.card').querySelector('.table-scroll').getBoundingClientRect();
  const count = document.querySelector('[data-count]').getBoundingClientRect();
  const btns = [...document.querySelectorAll('[data-export-format]')].map((b) => b.getBoundingClientRect());
  return { rightGap: Math.round(box.right - btns[btns.length - 1].right), sameLine: Math.abs((count.top + count.bottom) / 2 - (btns[0].top + btns[0].bottom) / 2) < 12,
    page: document.documentElement.scrollWidth - document.documentElement.clientWidth, bar: Math.round(bar.width) };
});

const user = await session(userLogin);
// ---- 1. 결함 목록: 위치·1280/1024 ----
for (const width of [1280, 1024]) {
  await user.page.setViewport({ width, height: 900 });
  await user.page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
  await defectsReady(user.page);
  const L = await layout(user.page);
  r[`layout@${width}`] = L;
  check(Math.abs(L.rightGap) <= 2 && L.sameLine && L.page === 0, `버튼 위치 ${width}: 표 오른쪽 끝·건수와 한 줄·가로 스크롤 0`, L);
  await user.page.screenshot({ path: path.join(OUT, `P23_defects_${width}.png`) });
}
await user.page.setViewport({ width: 1280, height: 900 });
// ---- 2. 내려받기 + 행 수 = 화면 건수 ----
const total = await screenCount(user.page);
const csv = await exportVia(user, '[data-export-format="csv"]');
r.csv = { ...csv, rows: csv.path ? csvRows(csv.path) : null, total };
check(csv.status === 200 && /^PJGuardian_.+_결함_\d{8}-\d{4}\.csv$/.test(csv.file ?? '') && csv.bytes > 0 && r.csv.rows === total, 'CSV 내려받기·행 수 = 화면 건수', r.csv);
const xlsx = await exportVia(user, '[data-export-format="xlsx"]', { keyboard: true });
r.xlsx = { status: xlsx.status, file: xlsx.file, bytes: xlsx.bytes, magic: xlsx.path ? fs.readFileSync(xlsx.path).subarray(0, 2).toString() : null };
check(xlsx.status === 200 && /\.xlsx$/.test(xlsx.file ?? '') && r.xlsx.magic === 'PK', 'Excel 내려받기(키보드 Enter, zip)', r.xlsx);
// ---- 3. 미적용 변경: 안내 + 마지막으로 검색한 조건만 ----
await user.page.goto(`${BASE}/projects/${projectId}?severity=high`, { waitUntil: 'networkidle0' });
await defectsReady(user.page);
await user.page.type('#df-q', 'zzzz-not-applied');
await sleep(200);
r.dirtyNote = await user.page.$eval('[data-export-note]', (n) => (n.hidden ? '' : n.textContent));
const dirty = await exportVia(user, '[data-export-format="csv"]');
r.dirtyBody = dirty.body;
check(r.dirtyNote === '마지막으로 검색한 조건으로 내보냅니다.' && dirty.body.severity === 'high' && dirty.body.q === undefined && dirty.status === 200, '미적용 변경: 안내 + 적용된 조건만', { note: r.dirtyNote, body: dirty.body, status: dirty.status });
await user.page.screenshot({ path: path.join(OUT, 'P23_dirty_note.png') });
// ---- 4. 과거 Run ----
await user.page.goto(`${BASE}/projects/${projectId}?run=3`, { waitUntil: 'networkidle0' });
await defectsReady(user.page);
r.runNote = await user.page.$eval('[data-export-note]', (n) => n.textContent);
const past = await exportVia(user, '[data-export-format="xlsx"]');
r.past = { body: past.body, file: past.file, status: past.status };
check(r.runNote === 'Run 3 기준' && past.body.run === '3' && /_Run3\.xlsx$/.test(past.file ?? ''), '과거 Run: 안내·파일 이름', r);
// ---- 5. 0건이면 꺼짐 ----
await user.page.goto(`${BASE}/projects/${projectId}?q=zzzz-nothing-matches`, { waitUntil: 'networkidle0' });
await user.page.waitForFunction(() => !document.querySelector('[data-defects]').hasAttribute('aria-busy') && !document.querySelector('[data-empty-filtered]').hidden, { timeout: 20000 });
r.empty = await user.page.$$eval('[data-export-format]', (bs) => bs.map((b) => [b.disabled, b.title]));
check(r.empty.every(([d, title]) => d && title === '내보낼 항목이 없습니다.'), '0건: 버튼 꺼짐 + 이유', r.empty);
// ---- 6. 상한 초과 안내 ----
await user.page.goto(`${BASE}/projects/${bigProject}`, { waitUntil: 'networkidle0' });
await defectsReady(user.page);
const big = await exportVia(user, '[data-export-format="xlsx"]');
r.big = { status: big.status, toast: big.toast };
check(big.status === 413 && /50,001건이라 한 번에 내보낼 수 없습니다\(상한 50,000건\)/.test(big.toast ?? ''), '상한 초과 안내', r.big);
await user.page.screenshot({ path: path.join(OUT, 'P23_too_large.png') });
// ---- 7. 내게 지정된 결함·규칙 설명 ----
await user.page.goto(`${BASE}/my-defects`, { waitUntil: 'networkidle0' });
await defectsReady(user.page);
const mineTotal = await screenCount(user.page);
const mine = await exportVia(user, '[data-export-format="csv"]');
r.mine = { status: mine.status, file: mine.file, rows: mine.path ? csvRows(mine.path) : null, total: mineTotal };
check(mine.status === 200 && /^PJGuardian_내결함_/.test(mine.file ?? '') && r.mine.rows === mineTotal, '내게 지정된 결함 내보내기', r.mine);
const ml = await layout(user.page);
check(Math.abs(ml.rightGap) <= 2 && ml.page === 0, '내 결함 버튼 위치', ml);
await user.page.screenshot({ path: path.join(OUT, 'P23_mine.png') });
for (const width of [1280, 1024]) {
  await user.page.setViewport({ width, height: 900 });
  await user.page.goto(`${BASE}/rules?language=javascript`, { waitUntil: 'networkidle0' });
  await user.page.waitForFunction(() => !document.querySelector('[data-rules]').hasAttribute('aria-busy') && /규칙 [\d,]+개/.test(document.querySelector('[data-count]').textContent), { timeout: 20000 });
  const RL = await layout(user.page);
  // 검색 영역의 입력 칸이 카드 밖으로 나가지 않음(P22C 7칸 줄 — 1024에서 넘쳤던 것)
  RL.formOverflow = await user.page.evaluate(() => {
    const card = document.querySelector('.rule-filters').getBoundingClientRect();
    return Math.max(0, ...[...document.querySelectorAll('.rule-filters .field__input')].map((n) => Math.round(n.getBoundingClientRect().right - card.right)));
  });
  r[`rulesLayout@${width}`] = RL;
  check(Math.abs(RL.rightGap) <= 2 && RL.page === 0 && RL.formOverflow <= 0, `규칙 목록 버튼 위치·검색 칸 ${width}`, RL);
  await user.page.screenshot({ path: path.join(OUT, `P23_rules_${width}.png`) });
}
await user.page.setViewport({ width: 1280, height: 900 });
const rulesTotal = await screenCount(user.page);
const rules = await exportVia(user, '[data-export-format="csv"]');
r.rules = { status: rules.status, file: rules.file, rows: rules.path ? csvRows(rules.path) : null, total: rulesTotal, body: rules.body };
check(rules.status === 200 && /^PJGuardian_rules_javascript_/.test(rules.file ?? '') && r.rules.rows === rulesTotal && rules.body.language === 'javascript', '규칙 목록 내보내기(탭)', r.rules);
await user.browser.close();

// ---- 8. admin ----
const admin = await session(adminLogin);
await admin.page.setViewport({ width: 1024, height: 900 });
await admin.page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await defectsReady(admin.page);
const AL = await layout(admin.page);
check(Math.abs(AL.rightGap) <= 2 && AL.page === 0, 'admin 1024 버튼 위치(선택 열 있는 표)', AL);
await admin.page.screenshot({ path: path.join(OUT, 'P23_admin_1024.png') });
const ax = await exportVia(admin, '[data-export-format="xlsx"]');
check(ax.status === 200 && ax.bytes > 0, 'admin 내려받기', { status: ax.status, bytes: ax.bytes });
await admin.browser.close();

fs.rmSync(DL, { recursive: true, force: true });
console.log(JSON.stringify(r, (k, v) => (k === 'path' ? undefined : v), 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
