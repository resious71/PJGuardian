// 결함 추적 브라우저 점검(P18, 헤드리스 Chrome). 두 브라우저(kim, lee)로 같은 결함을 동시에 수정해 충돌 대화상자와 재시도,
// 일괄 변경의 부분 성공, 비활성화된 담당자 표시, 분석의 자동 resolved 직후 수정 충돌, 내게 지정된 결함 화면을 확인한다.
// CSP 위반·콘솔 오류·예상 밖 HTTP 오류(409는 시험 대상이라 제외)를 모은다.
// 준비: puppeteer-core(data/work/puppeteer), 격리 점검 서버와 그 DB(사용자 admin·kim·lee·ghost, 프로젝트 1에 kim·lee·ghost 매핑, 분석 1회)
// 실행: PJG_PASSWORD=... PJG_REANALYZE='[["php","setup.php","upload","py","v2.zip"],["php","tools/worker.php","--config=…","--once"]]' \
//       node tools/check_defect_edit_ui.mjs <기본 주소> <프로젝트 id>
//   PJG_REANALYZE: 자동 resolved를 일으킬 명령(셸 없이 차례로 실행). 캡처는 PJG_SHOT_DIR(기본 docs/results), 이름 P18_*.png
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const PW = process.env.PJG_PASSWORD;
const REANALYZE = JSON.parse(process.env.PJG_REANALYZE ?? '[]');
if (!PW || !projectId) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_defect_edit_ui.mjs <기본 주소> <프로젝트 id>');
  process.exit(1);
}
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const problems = [];
const csp = [];
const result = {};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  await page.evaluateOnNewDocument(() => {
    document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`));
  });
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(`${login}: ${m.text()}`);
    else if (['error', 'warning'].includes(m.type()) && !/Failed to load resource/.test(m.text())) problems.push(`${login} console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (r) => { if (r.status() >= 400 && r.status() !== 409) problems.push(`${login} HTTP ${r.status()}: ${r.url()}`); });
  page.on('pageerror', (e) => problems.push(`${login} pageerror: ${e.message}`));
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}

/** 페이지 안에서 API 호출(쿠키·CSRF 메타 사용) */
function apiIn(page, method, url, body) {
  return page.evaluate(async (m, u, b) => {
    const token = document.querySelector('meta[name="csrf-token"]').content;
    const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': token }, body: b === null ? undefined : JSON.stringify(b) });
    return { status: r.status, json: await r.json().catch(() => null) };
  }, method, url, body ?? null);
}

async function openDefect(page, id) {
  await page.goto(`${BASE}/defects/${id}`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('[data-edit-form]');
}

async function waitToastOrDialog(page) {
  await page.waitForFunction(() => document.querySelector('dialog[open]') || document.querySelector('#toasts .toast'), { timeout: 10000 });
}

const kim = await session('kim');
const lee = await session('lee');
const admin = await session('admin');

const list = (await apiIn(kim, 'GET', `/api/v1/projects/${projectId}/defects?size=200`)).json.data;
const pick = (pred) => list.find(pred);
const A = pick((d) => d.file_path === 'utils/helpers.py');
const B = pick((d) => d.file_path === 'app/services.py');
const M = pick((d) => d.file_path === 'main.py');
result.targets = { A: `${A.id} ${A.file_path}:${A.line}`, B: `${B.id} ${B.file_path}:${B.line}`, M: `${M.id} ${M.file_path}:${M.line}` };

// 1. 두 브라우저가 같은 결함(A)을 연다 → kim 저장 → lee 저장은 충돌 대화상자 → "최신 값으로 다시 시도" → 저장 성공
await openDefect(kim, A.id);
await openDefect(lee, A.id);
const historyBefore = await kim.$$eval('[data-history-rows] tr', (xs) => xs.length);
result.saveDisabledInitially = await kim.$eval('[data-edit-save]', (b) => b.disabled);
await kim.select('#de-status', 'in_progress');
await kim.type('#de-memo', '<b>착수</b>');
result.saveEnabledAfterChange = await kim.$eval('[data-edit-save]', (b) => !b.disabled);
await kim.click('[data-edit-save]');
await waitToastOrDialog(kim);
await kim.waitForFunction((n) => document.querySelectorAll('[data-history-rows] tr').length === n + 1, {}, historyBefore);
result.kimSaved = await kim.evaluate(() => ({
  toast: document.querySelector('#toasts .toast')?.textContent.trim(),
  version: document.querySelector('[data-defect-summary]').dataset.version,
  history: [...document.querySelectorAll('[data-history-rows] tr:first-child td')].map((td) => td.textContent.trim()),
  memoCleared: document.querySelector('#de-memo').value === '',
}));

await lee.select('#de-severity', 'high');
await lee.type('#de-memo', '영향 큼');
await lee.click('[data-edit-save]');
await lee.waitForSelector('dialog[open] .conflict-table');
result.conflictDialog = await lee.evaluate(() => ({
  title: document.querySelector('dialog[open] .dialog__title').textContent,
  message: document.querySelector('dialog[open] p').textContent,
  rows: [...document.querySelectorAll('dialog[open] .conflict-table tbody tr')].map((tr) => [...tr.children].map((c) => c.textContent.trim())),
  buttons: [...document.querySelectorAll('dialog[open] .dialog__actions button')].map((b) => b.textContent.trim()),
}));
await lee.screenshot({ path: `${OUT}/P18_conflict_dialog.png` });
await lee.click('dialog[open] .dialog__actions button[type=submit]');
// 대화상자가 닫힌 뒤 최신 값(version)이 화면에 반영될 때까지
await lee.waitForFunction(() => !document.querySelector('dialog[open]') && document.querySelector('[data-defect-summary]').dataset.version !== '1');
result.afterRetryChoice = await lee.evaluate(() => ({
  severityKept: document.querySelector('#de-severity').value,
  statusFromLatest: document.querySelector('#de-status').value,
  memoKept: document.querySelector('#de-memo').value,
  statusShown: document.querySelector('[data-field="status"]').textContent.trim(),
  version: document.querySelector('[data-defect-summary]').dataset.version,
  saveEnabled: !document.querySelector('[data-edit-save]').disabled,
}));
await lee.click('[data-edit-save]');
await lee.waitForFunction((n) => document.querySelectorAll('[data-history-rows] tr').length === n + 2, {}, historyBefore);
result.leeRetrySaved = await lee.evaluate(() => ({
  version: document.querySelector('[data-defect-summary]').dataset.version,
  history: [...document.querySelectorAll('[data-history-rows] tr')].slice(0, 2).map((tr) => [...tr.children].map((c) => c.textContent.trim())),
}));
await lee.screenshot({ path: `${OUT}/P18_after_retry.png`, fullPage: true });

// 2. 일괄 변경의 부분 성공: kim이 현재 페이지(app/ 폴더)를 모두 고른 뒤, 적용 직전에 lee가 그중 하나를 바꾼다
await kim.goto(`${BASE}/projects/${projectId}?file=app%2F`, { waitUntil: 'networkidle0' });
await kim.waitForSelector('[data-rows] tr');
await kim.click('[data-select-page]');
await kim.select('#bulk-status', 'postponed');
await kim.type('#bulk-memo', '이번 릴리스 제외');
const selectedIds = await kim.$$eval('[data-rows] input[data-select]:checked', (xs) => xs.map((x) => Number(x.dataset.select)));
const victim = selectedIds[1];
const v = (await apiIn(lee, 'GET', `/api/v1/defects/${victim}`)).json.data.version;
result.leeInterfere = (await apiIn(lee, 'PATCH', `/api/v1/defects/${victim}`, { version: v, status: 'not_fix', memo: '먼저 바꿈' })).status;
await kim.click('[data-bulk-apply]');
await kim.waitForSelector('dialog[open]');
result.bulkConfirm = await kim.evaluate(() => [...document.querySelectorAll('dialog[open] h2, dialog[open] p')].map((n) => n.textContent));
await kim.screenshot({ path: `${OUT}/P18_bulk_confirm.png` });
await kim.click('dialog[open] .dialog__actions button.btn--primary');
await kim.waitForSelector('[data-bulk-result]:not([hidden]) .notice');
result.bulk = await kim.evaluate(() => ({
  selected: document.querySelectorAll('[data-rows] input[data-select]').length,
  summary: document.querySelector('[data-bulk-result] .notice').textContent.trim(),
  conflicts: [...document.querySelectorAll('[data-bulk-result] li')].map((li) => li.textContent.trim()),
  statuses: [...document.querySelectorAll('[data-rows] tr td:nth-child(3)')].map((td) => td.textContent.trim()),
}));
result.bulk.victim = victim;
await kim.screenshot({ path: `${OUT}/P18_bulk_result.png`, fullPage: true });

// 3. 비활성화된 담당자: admin이 B를 ghost에게 지정 → ghost 비활성화 → kim 화면에 담당자 유지 + 비활성 표시
const ghost = (await apiIn(admin, 'GET', `/api/v1/projects/${projectId}/assignees?q=ghost`)).json.data[0];
const vb = (await apiIn(admin, 'GET', `/api/v1/defects/${B.id}`)).json.data.version;
result.assignGhost = (await apiIn(admin, 'PATCH', `/api/v1/defects/${B.id}`, { version: vb, assignee_id: ghost.id })).status;
result.deactivate = (await apiIn(admin, 'PUT', `/api/v1/users/${ghost.id}/status`, { status: 'inactive' })).status;
await openDefect(kim, B.id);
result.inactive = await kim.evaluate(() => ({
  shown: document.querySelector('[data-field="assignee"]').textContent.replace(/\s+/g, ' ').trim(),
  tag: !!document.querySelector('[data-field="assignee"] [data-inactive]'),
  selected: document.querySelector('#de-assignee').selectedOptions[0].textContent.trim(),
  ghostSelectable: [...document.querySelectorAll('#de-assignee option')].filter((o) => !('keepOnly' in o.dataset) && /ghost/.test(o.textContent)).length,
}));
await kim.screenshot({ path: `${OUT}/P18_inactive_assignee.png` });
// 내 결함 화면용: app/models.py의 두 건을 kim에게
const kimId = (await apiIn(admin, 'GET', `/api/v1/projects/${projectId}/assignees?q=kim`)).json.data[0].id;
for (const d of list.filter((x) => x.file_path === 'app/models.py').slice(0, 2)) {
  const cur = (await apiIn(admin, 'GET', `/api/v1/defects/${d.id}`)).json.data.version;
  await apiIn(admin, 'PATCH', `/api/v1/defects/${d.id}`, { version: cur, assignee_id: kimId });
}

// 4. 자동 resolved 직후의 수정 충돌: kim이 M(main.py)을 연 뒤 main.py를 지운 코드로 분석 → kim 저장은 충돌, 최신 값 = 해결됨
await openDefect(kim, M.id);
for (const cmd of REANALYZE) execFileSync(cmd[0], cmd.slice(1), { cwd: ROOT, stdio: 'ignore' });
await kim.select('#de-status', 'intentional');
await kim.click('[data-edit-save]');
await kim.waitForSelector('dialog[open] .conflict-table');
const versionBeforeCancel = await kim.$eval('[data-defect-summary]', (x) => x.dataset.version);
result.autoConflict = await kim.evaluate(() => [...document.querySelectorAll('dialog[open] .conflict-table tbody tr')].map((tr) => [...tr.children].map((c) => c.textContent.trim())));
await kim.screenshot({ path: `${OUT}/P18_auto_conflict.png` });
await kim.click('dialog[open] .dialog__actions button:not([type=submit])');
await kim.waitForFunction((v) => !document.querySelector('dialog[open]') && document.querySelector('[data-defect-summary]').dataset.version !== v, {}, versionBeforeCancel);
await kim.waitForFunction(() => document.querySelectorAll('[data-history-rows] tr').length > 0);
result.afterCancel = await kim.evaluate(() => ({ status: document.querySelector('#de-status').value, saveDisabled: document.querySelector('[data-edit-save]').disabled,
  history: [...document.querySelectorAll('[data-history-rows] tr')].map((tr) => [...tr.children].map((c) => c.textContent.trim())) }));

// 5. 내게 지정된 결함
await kim.goto(`${BASE}/my-defects`, { waitUntil: 'networkidle0' });
await kim.waitForSelector('[data-rows] tr');
result.myDefects = await kim.evaluate(() => ({
  count: document.querySelector('[data-count]').textContent,
  chips: [...document.querySelectorAll('[data-facet="status"] .chip')].map((c) => c.textContent.trim()),
  rows: [...document.querySelectorAll('[data-rows] tr')].map((tr) => [...tr.children].slice(0, 5).map((c) => c.textContent.trim())),
  nav: document.querySelector('[data-nav="nav.my_defects"]')?.getAttribute('aria-current'),
}));
await kim.screenshot({ path: `${OUT}/P18_my_defects.png`, fullPage: true });
// P21B 6절: 상태 칩은 라벨만(건수 "(숫자)" 없음 — 결함 검색 칩과 같게)
for (const c of result.myDefects.chips) if (/[0-9()]/.test(c)) problems.push(`칩에 건수: ${c}`);
await sleep(200);
await browser.close();
console.log(JSON.stringify({ result, csp, problems }, null, 2));
process.exit(csp.length || problems.length ? 1 : 0);
