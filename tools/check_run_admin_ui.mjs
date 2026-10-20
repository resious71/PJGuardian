// Run 보관·관리자 상세 삭제 브라우저 점검(P21B, 헤드리스 Chrome). **삭제를 실제로 실행하므로 격리 서버·DB 사본에서만 쓴다**(개발 DB 금지).
//   PJG_PASSWORD=... node tools/check_run_admin_ui.mjs <기본 주소> <프로젝트 id> <일반 사용자 로그인(그 프로젝트를 볼 수 있음)> [관리자 로그인]
//   전제: 프로젝트에 상세가 있는 Run이 4개 이상(최신 Run 포함)이고 아직 삭제된 Run이 없다.
// 확인:
//   - 관리자 → 시스템 관리 → Run 관리 탭: 표(Run·날짜·건수·도구·상세 상태), 최신 Run 체크박스 꺼짐 + 툴팁, 요약 문장
//   - "오래된 Run 모두 선택(최근 N개 제외)" + 중간 Run 하나 직접 선택 → 버튼 글자에 개수
//   - 확인 대화상자: 대상 Run 목록, 취소가 기본 포커스, 확인 버튼은 프로젝트 이름을 정확히 입력해야 켜짐(틀린 이름·대소문자 다름은 꺼짐),
//     입력칸에서 Enter는 삭제하지 않음, Tab은 대화상자 안에서만 돈다, Esc로 닫히고 연 버튼으로 포커스 복귀 → 키보드로만 삭제
//   - 삭제 뒤: 결과 문장, 표의 상세 상태 "수동 삭제됨", 체크박스 꺼짐
//   - Run 선택기: 삭제된 Run은 보이되 고를 수 없음, 주소 run=N(삭제됨) → 결함 목록 대신 안내 + 최신 Run 링크, 결함 화면 410 안내
//   - 히스토리 표의 "상세 삭제됨" 표시(건수 그대로), 비교 패널은 안내 + 카드 링크 없음, 대시보드 추이 API의 detail_deleted
//   - 결함 표 가로 스크롤 0(1920·1440·1280·1024px, 과거 Run 보기·삭제된 Run 안내 화면, 관리자·일반 사용자)
//   - 일반 사용자: 관리자 메뉴·Run 관리 탭 없음, /admin/runs 403, 관리 API 403
//   - 콘솔 오류 0, CSP 위반 0(410·403은 시험 대상이라 HTTP 오류에서 뺌). 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21B_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, userLogin, adminLogin = 'admin'] = process.argv;
if (!projectId || !userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_run_admin_ui.mjs <기본 주소> <프로젝트 id> <일반 사용자 로그인> [관리자 로그인]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

async function session(login) {
  const page = await (await browser.createBrowserContext()).newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (410|403)/.test(m.text())) return; // 시험 대상(삭제된 Run 410, 권한 403)
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400 && ![403, 410].includes(res.status())) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const api = (page, url, init = {}) => page.evaluate(async (u, i) => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const res = await fetch(u, { ...i, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf, ...(i.headers ?? {}) } });
  return { status: res.status, body: await res.json().catch(() => null) };
}, url, init);
const tableScroll = (page) => page.evaluate(() => {
  const b = document.querySelector('.table-scroll:has(.defects-table)');
  const t = b?.querySelector('table');
  return { box: b && t && !t.hidden ? b.scrollWidth - b.clientWidth : null, page: document.documentElement.scrollWidth - document.documentElement.clientWidth };
});

const admin = await session(adminLogin);
// ---- 0. 준비: Run 목록(삭제 전) ----
const before = await api(admin, `${BASE}/api/v1/admin/projects/${projectId}/runs?size=200`);
check(before.status === 200, '관리 API 200', before.status);
const latest = before.body.data.summary?.latest ?? before.body.summary?.latest;
const summaryBefore = before.body.summary;
const runs = before.body.data.map((x) => x.run).sort((a, b) => a - b);
check(summaryBefore.deleted === 0 && runs.length >= 4, '전제: 삭제된 Run 없음, Run 4개 이상', summaryBefore);
const keepN = Math.max(1, latest - 2); // 오래된 Run 모두 선택 → Run 1·2
const middle = 4 <= latest - 1 ? 4 : latest - 1; // 중간 Run 하나(최신 아님)
const expected = [...new Set([...runs.filter((n) => n <= latest - keepN && n !== latest), middle])].sort((a, b) => a - b);
const historyBefore = await api(admin, `${BASE}/api/v1/projects/${projectId}/history?size=100`);
const trendBefore = await api(admin, `${BASE}/api/v1/dashboard/trend?project_id=${projectId}`);
const anyDefect = (await api(admin, `${BASE}/api/v1/projects/${projectId}/defects?run=${middle}&size=1`)).body.data[0]?.id;
const keptRun = runs.find((n) => !expected.includes(n) && n !== latest);

// ---- 1. Run 관리 화면 ----
await admin.goto(`${BASE}/admin/runs?project=${projectId}`, { waitUntil: 'networkidle0' });
await admin.waitForSelector('[data-admin-runs] [data-rows] tr');
r.page = await admin.evaluate(() => ({
  tab: document.querySelector('.subnav__item[aria-current="page"]')?.textContent.trim(),
  summary: document.querySelector('[data-summary]').textContent,
  rows: document.querySelectorAll('[data-rows] tr').length,
  latestDisabled: document.querySelector('[data-rows] tr:first-child input[type=checkbox]').disabled,
  latestTip: document.querySelector('[data-rows] tr:first-child .admin-runs__latest')?.dataset.tip,
  firstRow: [...document.querySelectorAll('[data-rows] tr:first-child td')].map((td) => td.textContent.trim()),
  deleteDisabled: document.querySelector('[data-delete]').disabled,
}));
check(r.page.tab === 'Run 관리' && r.page.rows === runs.length && r.page.latestDisabled && r.page.latestTip && r.page.deleteDisabled, 'Run 관리 화면', r.page);
await admin.screenshot({ path: path.join(OUT, 'P21B_admin_runs.png'), fullPage: true });

// ---- 2. 선택 ----
await admin.$eval('#ar-keep-n', (n) => { n.value = ''; });
await admin.type('#ar-keep-n', String(keepN));
await admin.click('[data-select-old]');
await admin.click(`[data-rows] input[data-run="${middle}"]`);
r.selected = await admin.evaluate(() => ({
  runs: [...document.querySelectorAll('[data-rows] input[data-run]:checked')].map((n) => Number(n.dataset.run)).sort((a, b) => a - b),
  button: document.querySelector('[data-delete]').textContent.trim(),
}));
check(JSON.stringify(r.selected.runs) === JSON.stringify(expected) && r.selected.button.includes(String(expected.length)), '선택(오래된 Run + 중간 Run)', [r.selected, expected]);

// ---- 3. 확인 대화상자: 키보드 ----
await admin.focus('[data-delete]');
await admin.keyboard.press('Enter');
await admin.waitForSelector('dialog[open]');
const dlg = () => admin.evaluate(() => {
  const d = document.querySelector('dialog[open]');
  if (!d) return null;
  const ok = d.querySelector('.dialog__actions .btn--danger');
  return { focus: document.activeElement?.textContent.trim() || document.activeElement?.tagName, okDisabled: ok.disabled,
    lines: [...d.querySelectorAll('.dialog__line')].map((n) => n.textContent), title: d.querySelector('.dialog__title').textContent };
});
r.dialogOpen = await dlg();
check(r.dialogOpen.focus === '취소' && r.dialogOpen.okDisabled && r.dialogOpen.lines[0].includes(expected.join(', ')), '대화상자: 취소 기본 포커스, 확인 꺼짐, 대상 Run', r.dialogOpen);
await admin.screenshot({ path: path.join(OUT, 'P21B_admin_runs_dialog.png') });
await admin.keyboard.press('Escape');
await admin.waitForFunction(() => !document.querySelector('dialog[open]'));
r.escFocus = await admin.evaluate(() => document.activeElement?.matches('[data-delete]'));
check(r.escFocus === true, 'Esc → 닫힘, 포커스 복귀', r.escFocus);
const nameOf = await admin.$eval('#ar-project', (s) => s.selectedOptions[0].dataset.name);
await admin.keyboard.press('Enter');
await admin.waitForSelector('dialog[open]');
await admin.keyboard.down('Shift'); await admin.keyboard.press('Tab'); await admin.keyboard.up('Shift'); // 취소 → 입력칸
const wrong = nameOf.toUpperCase() === nameOf ? `${nameOf}x` : nameOf.toUpperCase();
await admin.keyboard.type(wrong);
r.wrongName = (await dlg()).okDisabled;
await admin.keyboard.press('Enter'); // 입력칸 Enter는 삭제하지 않음
await sleep(300);
r.enterInInput = (await dlg()) !== null;
await admin.evaluate(() => { const i = document.querySelector('dialog[open] input'); i.select(); });
await admin.keyboard.type(nameOf);
r.rightName = (await dlg()).okDisabled;
// Tab 순환: 입력 → 취소 → 확인 → 입력
const order = [];
for (let i = 0; i < 3; i += 1) {
  await admin.keyboard.press('Tab');
  order.push(await admin.evaluate(() => (document.activeElement?.tagName === 'INPUT' ? 'input' : document.activeElement?.textContent.trim())));
}
r.tabOrder = order;
check(r.wrongName === true && r.enterInInput && r.rightName === false && order[2] === 'input' && order.every((x) => x), '이름 입력·Enter·Tab 순환', { wrong: r.wrongName, enter: r.enterInInput, right: r.rightName, order });
// 확인 버튼에 포커스를 두고 Enter(키보드로만 삭제)
await admin.evaluate(() => document.querySelector('dialog[open] .btn--danger').focus());
await Promise.all([admin.waitForResponse((x) => x.url().includes('/delete-detail')), admin.keyboard.press('Enter')]);
await admin.waitForSelector('[data-result]:not([hidden])');
await admin.waitForFunction((n) => document.querySelectorAll('[data-rows] .tag').length >= n, {}, expected.length);
r.after = await admin.evaluate(() => ({
  result: document.querySelector('[data-result]').textContent,
  states: Object.fromEntries([...document.querySelectorAll('[data-rows] tr')].map((tr) => [tr.dataset.run, tr.lastElementChild.textContent.trim()])),
  disabled: [...document.querySelectorAll('[data-rows] input[data-run]:disabled')].map((n) => Number(n.dataset.run)).sort((a, b) => a - b),
  summary: document.querySelector('[data-summary]').textContent,
}));
check(expected.every((n) => r.after.states[n]?.startsWith('수동 삭제됨')) && r.after.result.includes(`Run ${expected.length}개`), '삭제 결과', r.after);
await admin.screenshot({ path: path.join(OUT, 'P21B_admin_runs_after.png'), fullPage: true });

// ---- 4. 삭제된 Run을 만나는 화면 ----
const deletedRun = middle;
await admin.goto(`${BASE}/projects/${projectId}?run=${deletedRun}`, { waitUntil: 'networkidle0' });
await admin.waitForSelector('[data-run-deleted]');
r.listDeleted = await admin.evaluate(() => ({
  notice: document.querySelector('[data-run-deleted]').textContent.replace(/\s+/g, ' ').trim(),
  tableHidden: document.querySelector('table.defects-table').hidden,
  latestHref: document.querySelector('[data-run-deleted] a')?.getAttribute('href'),
  options: [...document.querySelectorAll('select option[data-detail-deleted]')].map((o) => ({ v: o.value, disabled: o.disabled, text: o.textContent })),
  // 선택기의 "주소의 Run 번호를 찾을 수 없어…" 경고는 겹쳐 띄우지 않는다(삭제 안내가 따로 있음)
  invalidWarn: [...document.querySelectorAll('.notice')].some((n) => n.textContent.includes('찾을 수 없어')),
}));
check(!r.listDeleted.invalidWarn, '삭제된 Run: "찾을 수 없음" 경고 없음', r.listDeleted.invalidWarn);
check(r.listDeleted.tableHidden && r.listDeleted.notice.includes(`Run ${deletedRun}`) && r.listDeleted.latestHref && !r.listDeleted.latestHref.includes('run='), '결함 목록: 삭제된 Run 안내', r.listDeleted);
check(r.listDeleted.options.length === expected.length && r.listDeleted.options.every((o) => o.disabled && o.text.includes('상세 삭제됨')), '선택기: 삭제된 Run은 고를 수 없음', r.listDeleted.options);
await admin.screenshot({ path: path.join(OUT, 'P21B_run_deleted_list.png') });
if (anyDefect) {
  const resp = await admin.goto(`${BASE}/defects/${anyDefect}?run=${deletedRun}`, { waitUntil: 'networkidle0' });
  r.defectDeleted = { status: resp.status(), text: await admin.$eval('[data-run-deleted]', (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null) };
  check(r.defectDeleted.status === 410 && r.defectDeleted.text?.includes(`Run ${deletedRun}`), '결함 화면 410 안내', r.defectDeleted);
  await admin.screenshot({ path: path.join(OUT, 'P21B_run_deleted_defect.png') });
}
r.api410 = await api(admin, `${BASE}/api/v1/projects/${projectId}/defects?run=${deletedRun}`);
check(r.api410.status === 410 && r.api410.body?.error?.code === 'run_detail_deleted', 'API 410', r.api410);
// 히스토리 표·비교 패널
const historyAfter = await api(admin, `${BASE}/api/v1/projects/${projectId}/history?size=100`);
const strip = (rows) => rows.map(({ detail_deleted: _d, ...rest }) => rest);
check(JSON.stringify(strip(historyAfter.body.data)) === JSON.stringify(strip(historyBefore.body.data)), '히스토리 표 값 불변(상세 상태 표시 제외)', null);
const trendAfter = await api(admin, `${BASE}/api/v1/dashboard/trend?project_id=${projectId}`);
const pts = (b) => (b.data.series ?? b.data).flatMap((s) => s.points ?? []);
check(JSON.stringify(pts(trendAfter.body).map(({ detail_deleted: _d, ...p }) => p)) === JSON.stringify(pts(trendBefore.body).map(({ detail_deleted: _d, ...p }) => p)), '대시보드 추이 값 불변', null);
r.trendDeleted = pts(trendAfter.body).filter((p) => p.detail_deleted).map((p) => p.run);
const deletedAnalysis = historyAfter.body.data.find((x) => x.run === deletedRun)?.analysis_id;
await admin.goto(`${BASE}/projects/${projectId}/history?analysis=${deletedAnalysis}`, { waitUntil: 'networkidle0' });
await admin.waitForSelector('[data-panel-notices] [data-notice="detail_deleted"]');
r.history = await admin.evaluate(() => ({
  marks: [...document.querySelectorAll('[data-history-rows] [data-detail-deleted]')].length,
  cardLinks: document.querySelectorAll('[data-panel-body] a').length,
  notice: document.querySelector('[data-notice="detail_deleted"]').textContent.trim(),
  pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
}));
check(r.history.pageOverflow === 0, '히스토리: 페이지 가로 스크롤 없음("상세 삭제됨" 표시로 표가 넓어져도)', r.history.pageOverflow);
check(r.history.marks === expected.length && r.history.cardLinks === 0, '히스토리: 상세 삭제 표시, 카드 링크 꺼짐', r.history);
await admin.screenshot({ path: path.join(OUT, 'P21B_history_deleted.png'), fullPage: true });

// ---- 5. 결함 표 가로 스크롤(관리자·일반 사용자, 과거 Run·삭제된 Run 안내) ----
const user = await session(userLogin);
r.widths = {};
for (const [who, page] of [['admin', admin], ['user', user]]) {
  for (const width of [1920, 1440, 1280, 1024]) {
    await page.setViewport({ width, height: 900 });
    for (const [kind, q] of [['latest', ''], ['past', `?run=${keptRun}`], ['deleted', `?run=${deletedRun}`]]) {
      await page.goto(`${BASE}/projects/${projectId}${q}`, { waitUntil: 'networkidle0' });
      await page.waitForFunction(() => document.querySelector('[data-run-deleted]') || document.querySelector('[data-rows] tr'), { timeout: 20000 });
      const s = await tableScroll(page);
      r.widths[`${who}:${kind}@${width}`] = s;
      check((s.box === null || s.box === 0) && s.page === 0, `가로 스크롤 ${who}:${kind}@${width}`, s);
    }
  }
}
await user.setViewport({ width: 1024, height: 900 });
await user.goto(`${BASE}/projects/${projectId}?run=${keptRun}`, { waitUntil: 'networkidle0' });
await user.waitForSelector('[data-rows] tr');
await user.screenshot({ path: path.join(OUT, 'P21B_user_past_run_1024.png') });

// ---- 6. 일반 사용자: 메뉴·화면·API 없음 ----
await user.setViewport({ width: 1280, height: 900 });
await user.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
r.userMenu = await user.evaluate(() => ({ adminLinks: [...document.querySelectorAll('a[href*="/admin/"]')].map((a) => a.getAttribute('href')) }));
const userPage = await user.goto(`${BASE}/admin/runs`, { waitUntil: 'networkidle0' });
r.userPage = userPage.status();
r.userApi = (await api(user, `${BASE}/api/v1/admin/projects/${projectId}/runs`)).status;
r.userDelete = (await api(user, `${BASE}/api/v1/admin/projects/${projectId}/runs/delete-detail`, { method: 'POST', body: JSON.stringify({ runs: [keptRun] }) })).status;
check(r.userMenu.adminLinks.length === 0 && r.userPage === 403 && r.userApi === 403 && r.userDelete === 403, '일반 사용자: 메뉴 없음, 화면·API 403', r);
const stillKept = await api(admin, `${BASE}/api/v1/admin/projects/${projectId}/runs?size=200`);
check(stillKept.body.data.find((x) => x.run === keptRun)?.detail_deleted === null, '일반 사용자 요청 뒤 Run 그대로', keptRun);

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
