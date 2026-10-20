// 규칙 목록 켜기·끄기·일괄 변경 브라우저 점검(P22C, 헤드리스 Chrome). 격리 서버·DB 사본(016 + tools/rules_load.php --apply)에서만 쓴다 — 실제로 바꾸고 끝에 모두 되돌린다.
//   PJG_PASSWORD=... node tools/check_rule_bulk_ui.mjs <기본 주소> <일반 사용자 로그인> [관리자 로그인]
// 확인(1280·1024px):
//   - "규칙 설정" 빈 상태: 안내 + "규칙 설명으로 이동"(마지막 언어 탭) 링크 동작
//   - admin 목록: 선택 열·스위치, 가로 스크롤 0, "분석 적용" 칸 안 잘림. 규칙 키 접두사 검색(주소 prefix=)
//   - 행 스위치: 확인 창 → 그 행만 갱신(주소·다른 행 그대로), 연타해도 요청 하나. 켤 수 없는 규칙은 스위치 비활성 + 이유(툴팁·화면 읽기 설명)
//   - 선택: 행·이 쪽 전체, 막대 문장, "검색 결과 전체 M개 선택", 쪽 이동·탭 변경이면 해제
//   - 일괄: prefix=D 검색 결과 전체 켜기 → 미리보기 수치 → 도구 이름 입력해야 확인 → 적용 → 목록 갱신·결과 알림·"규칙 설정"에 반영
//   - 409: 미리보기 뒤 다른 관리자가 바꾸면 알리고 다시 미리보기 → 적용
//   - 키보드만으로 선택·일괄 끄기, 일반 사용자에게 변경 UI 없음(배지만), 마지막에 "기본값으로" 일괄로 모두 되돌림
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P22C_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, adminLogin = 'admin'] = process.argv;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_rule_bulk_ui.mjs <기본 주소> <일반 사용자> [관리자]');
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
const EXPECTED_STATUS = new Set([403, 409]); // 점검이 일부러 내는 응답

async function session(login) {
  const page = await (await browser.createBrowserContext()).newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (403|409)/.test(m.text())) return;
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400 && !EXPECTED_STATUS.has(res.status())) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const listReady = (p) => p.waitForFunction(() => !document.querySelector('[data-rules]').hasAttribute('aria-busy')
  && (/규칙 [\d,]+개/.test(document.querySelector('[data-count]').textContent) || !document.querySelector('[data-empty]').hidden), { timeout: 20000 });
const openList = async (p, search) => { await p.goto(`${BASE}/rules${search}`, { waitUntil: 'networkidle0' }); await listReady(p); };
const api = (p, method, url, body) => p.evaluate(async (m, u, b) => {
  const res = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: b === null ? undefined : JSON.stringify(b) });
  return { status: res.status, body: await res.json().catch(() => null) };
}, method, url, body ?? null);
const rowState = (p, key) => p.evaluate((k) => {
  const tr = document.querySelector(`[data-rows] tr[data-rule="${k}"]`);
  if (!tr) return null;
  const sw = tr.querySelector('[role="switch"]');
  return { checked: sw?.getAttribute('aria-checked') ?? null, disabled: sw?.disabled ?? null, title: sw?.getAttribute('title') ?? null,
    desc: sw?.getAttribute('aria-describedby') ? document.getElementById(sw.getAttribute('aria-describedby'))?.textContent : null,
    label: sw?.getAttribute('aria-label') ?? null, text: tr.querySelector('.rcol-default')?.textContent.trim(), badge: tr.querySelector('.rule-overridden') !== null };
}, key);
const bulkState = (p) => p.evaluate(() => {
  const bar = document.querySelector('[data-bulk-bar]');
  const all = document.querySelector('[data-bulk-all]');
  return { shown: bar ? !bar.hidden : null, count: document.querySelector('[data-bulk-count]')?.textContent ?? null, allLink: all && !all.hidden ? all.textContent : null,
    selectAll: document.querySelector('[data-select-all]')?.checked ?? null, checked: document.querySelectorAll('[data-rows] [data-select]:checked').length,
    result: document.querySelector('[data-bulk-result]')?.textContent ?? '' };
});
const layout = (p) => p.evaluate(() => {
  // 잘림: "분석 적용" 칸과 선택 칸의 내용이 칸보다 넓음
  const box = document.querySelector('.table-scroll:has(.rules-table)');
  const clipped = [...document.querySelectorAll('[data-rows] .rcol-default, [data-rows] .rcol-select, .rules-table thead th')].filter((td) => td.scrollWidth > td.clientWidth + 1).length;
  return { page: document.documentElement.scrollWidth - document.documentElement.clientWidth, table: box ? box.scrollWidth - box.clientWidth : 0, clipped,
    headTall: [...document.querySelectorAll('.rules-table thead th')].filter((th) => th.getBoundingClientRect().height > 48).length };
});
const dialogText = (p) => p.evaluate(() => [...document.querySelectorAll('dialog[open] .dialog__title, dialog[open] [id$="-msg"], dialog[open] .rule-dialog-line')].map((n) => n.textContent).join(' / '));

const admin = await session(adminLogin);
let writes = 0;
admin.on('request', (q) => { if (['PUT', 'DELETE', 'POST'].includes(q.method()) && /\/override|\/overrides\/bulk$/.test(q.url())) writes += 1; });

// ---- 1. "규칙 설정" 빈 상태 → 규칙 설명으로 이동 ----
await admin.goto(`${BASE}/admin/rule-settings`, { waitUntil: 'networkidle0' });
await admin.waitForFunction(() => !document.querySelector('[data-empty]').hidden, { timeout: 15000 });
r.emptyState = await admin.evaluate(() => ({ text: document.querySelector('[data-empty-text]').textContent, go: !document.querySelector('[data-empty-go]').hidden,
  href: document.querySelector('[data-go-rules]').getAttribute('href') }));
check(/아직 관리자가 변경한 규칙이 없습니다/.test(r.emptyState.text) && r.emptyState.go && /\/rules\?language=python$/.test(r.emptyState.href), '규칙 설정 빈 상태 안내·링크', r.emptyState);
await admin.screenshot({ path: path.join(OUT, 'P22C_settings_empty.png') });
await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('[data-go-rules]')]);
await listReady(admin);
check(new URL(admin.url()).search === '?language=python', '링크 → Python 탭 목록', admin.url());

// ---- 2. admin 목록: 선택 열·스위치·가로 스크롤 ----
for (const width of [1280, 1024]) {
  await admin.setViewport({ width, height: 900 });
  await openList(admin, '?language=python');
  const L = await layout(admin);
  const M = await admin.evaluate(() => ({ selectAll: document.querySelector('[data-select-all]') !== null, switches: document.querySelectorAll('[data-rows] [role="switch"]').length,
    boxes: document.querySelectorAll('[data-rows] [data-select]').length }));
  r[`list@${width}`] = { ...L, ...M };
  check(L.page === 0 && L.table === 0 && L.clipped === 0 && L.headTall === 0 && M.selectAll && M.switches > 0 && M.switches === M.boxes, `admin 목록 ${width}: 선택 열·스위치·가로 스크롤 0·안 잘림`, r[`list@${width}`]);
  await admin.screenshot({ path: path.join(OUT, `P22C_list_admin_${width}.png`) });
}
await admin.setViewport({ width: 1280, height: 900 });

// ---- 3. 접두사 검색 + 행 스위치(E501 끄기) ----
await admin.type('#rf-prefix', 'e5');
await Promise.all([admin.waitForResponse((x) => x.url().includes('prefix=e5')), admin.keyboard.press('Enter')]);
await listReady(admin);
r.prefix = await admin.evaluate(() => ({ search: location.search, keys: [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.dataset.rule) }));
check(r.prefix.search.includes('prefix=e5') && r.prefix.keys.length > 0 && r.prefix.keys.every((k) => k.startsWith('ruff:E5')), '접두사 e5 → ruff E5… 만', r.prefix);
const before = await rowState(admin, 'ruff:E501');
const other = await rowState(admin, r.prefix.keys.find((k) => k !== 'ruff:E501'));
check(before.checked === 'true' && before.label === '규칙 ruff:E501 분석 적용', 'E501 스위치(켜짐·접근 가능한 이름)', before);
const urlBefore = admin.url();
writes = 0;
await admin.click('[data-rows] tr[data-rule="ruff:E501"] [role="switch"]');
await admin.waitForSelector('dialog[open]');
r.switchDialog = await dialogText(admin);
check(/eslint|ruff:E501 끄기/.test(r.switchDialog) && /켜짐 → 꺼짐/.test(r.switchDialog) && /열린 결함/.test(r.switchDialog), '스위치 확인 창(상세와 같은 창)', r.switchDialog);
await admin.type('dialog[open] input[name="reason"]', '긴 줄 허용');
await admin.evaluate(() => { const b = document.querySelector('dialog[open] button[type="submit"]'); b.click(); b.click(); });
await admin.waitForFunction(() => document.querySelector('[data-rows] tr[data-rule="ruff:E501"] [role="switch"]')?.getAttribute('aria-checked') === 'false', { timeout: 10000 });
await sleep(300);
const after = await rowState(admin, 'ruff:E501');
const otherAfter = await rowState(admin, other.label.replace('규칙 ', '').replace(' 분석 적용', ''));
r.switch = { after, writes, url: admin.url() === urlBefore, otherSame: JSON.stringify(other) === JSON.stringify(otherAfter) };
check(after.checked === 'false' && after.badge && /꺼짐/.test(after.text) && writes === 1 && r.switch.url && r.switch.otherSame, '스위치: 그 행만 갱신·요청 하나·주소 그대로', r.switch);
await admin.screenshot({ path: path.join(OUT, 'P22C_switch_after.png') });
// 켤 수 없는 규칙
await openList(admin, '?language=python&prefix=TID25');
const blocked = await rowState(admin, 'ruff:TID251');
r.blocked = blocked;
check(blocked.disabled === true && /켤 수 없음: 옵션 없이는/.test(blocked.title ?? '') && /켤 수 없음/.test(blocked.desc ?? ''), '켤 수 없는 규칙: 스위치 비활성 + 이유(툴팁·설명)', blocked);

// ---- 4. 선택·막대·쪽 이동·탭 변경 ----
await openList(admin, '?language=python&prefix=D');
const totalD = await admin.evaluate(() => Number(document.querySelector('[data-count]').textContent.replace(/[^\d]/g, '')));
await admin.click('[data-rows] tr:first-child [data-select]');
r.selOne = await bulkState(admin);
check(r.selOne.shown && r.selOne.count === '1개 선택됨' && r.selOne.allLink === null, '한 행 선택 → 막대', r.selOne);
await admin.click('[data-select-all]');
r.selPage = await bulkState(admin);
const pageRows = await admin.evaluate(() => document.querySelectorAll('[data-rows] tr').length);
check(r.selPage.count === `${pageRows}개 선택됨` && r.selPage.checked === pageRows && (totalD > pageRows ? r.selPage.allLink === `검색 결과 전체 ${totalD}개 선택` : r.selPage.allLink === null),
  '이 쪽 전체 선택 → 검색 결과 전체 링크', { ...r.selPage, totalD, pageRows });
await admin.screenshot({ path: path.join(OUT, 'P22C_bulk_bar.png') });
if (totalD > pageRows) {
  await Promise.all([admin.waitForResponse((x) => x.url().includes('page=2')), admin.click('[data-pagination] [data-page="2"], [data-pagination] button:not([disabled]):last-child')]);
  await listReady(admin);
  r.afterPage = await bulkState(admin);
  check(r.afterPage.shown === false && r.afterPage.checked === 0, '쪽 이동 → 선택 해제', r.afterPage);
}
await admin.click('[data-rows] tr:first-child [data-select]');
await Promise.all([admin.waitForResponse((x) => x.url().includes('tool=eslint')), admin.click('[data-rule-tabs] [data-language="javascript"]')]);
await listReady(admin);
r.afterTab = await bulkState(admin);
check(r.afterTab.shown === false, '탭 변경 → 선택 해제', r.afterTab);

// ---- 5. 검색 결과 전체(prefix=D) 켜기: 미리보기 → 도구 이름 입력 → 적용 ----
await openList(admin, '?language=python&prefix=D');
await admin.click('[data-select-all]');
if (totalD > pageRows) await admin.click('[data-bulk-all]');
r.selAll = await bulkState(admin);
check(r.selAll.count === (totalD > pageRows ? `검색 결과 전체 ${totalD}개 선택됨` : `${pageRows}개 선택됨`), '검색 결과 전체 선택', r.selAll);
const previewResp = admin.waitForResponse((x) => x.url().endsWith('/overrides/bulk/preview'));
await admin.click('[data-bulk-action="enable"]');
const pv = await (await previewResp).json();
await admin.waitForSelector('dialog[open]');
r.preview = { data: pv.data, text: await dialogText(admin), okBefore: await admin.$eval('dialog[open] button[type="submit"]', (b) => b.disabled) };
check(pv.data.total === totalD && r.preview.text.includes(`대상 ${totalD}개 중 실제로 바뀔 규칙 ${pv.data.changes}개`) && pv.data.skipped.preview > 0 && /미리보기 \d+/.test(r.preview.text)
  && r.preview.okBefore === true, '미리보기 수치·건너뜀 이유·확인 버튼 잠김', { total: pv.data.total, changes: pv.data.changes, skipped: pv.data.skipped, text: r.preview.text, okBefore: r.preview.okBefore });
await admin.type('dialog[open] input[name="reason"]', 'pydocstyle 일괄');
await admin.type('dialog[open] input[name="confirm"]', 'ruff');
await admin.screenshot({ path: path.join(OUT, 'P22C_bulk_dialog.png') });
const applyResp = admin.waitForResponse((x) => x.url().endsWith('/overrides/bulk'));
await admin.click('dialog[open] button[type="submit"]');
const ap = await (await applyResp).json();
await listReady(admin);
await admin.waitForFunction(() => /바꿨습니다/.test(document.querySelector('[data-bulk-result]').textContent), { timeout: 10000 });
r.applied = { data: { changes: ap.data.changes, skipped: ap.data.skipped_total, revision: ap.data.revision }, bar: await bulkState(admin), d100: await rowState(admin, 'ruff:D100'),
  layout1280: await layout(admin) };
await admin.setViewport({ width: 1024, height: 900 });
await sleep(200);
r.applied.layout1024 = await layout(admin);
await admin.setViewport({ width: 1280, height: 900 });
check(r.applied.layout1280.clipped === 0 && r.applied.layout1024.clipped === 0 && r.applied.layout1024.page === 0 && r.applied.layout1024.table === 0, '배지가 있는 행: 1280·1024 안 잘림·가로 스크롤 0', [r.applied.layout1280, r.applied.layout1024]);
check(ap.data.changes === pv.data.changes && ap.data.skipped_total === pv.data.skipped_total && r.applied.bar.shown === false && /바꿨습니다\(켬\)/.test(r.applied.bar.result)
  && r.applied.d100.checked === 'true' && r.applied.d100.badge, '적용 = 미리보기, 목록 갱신·선택 해제·결과 알림', r.applied);
await admin.screenshot({ path: path.join(OUT, 'P22C_bulk_done.png') });

// ---- 6. 409: 미리보기 뒤 다른 관리자가 바꿈 → 다시 미리보기 → 적용 ----
const admin2 = await session(adminLogin);
await admin2.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
await openList(admin, '?language=python&prefix=D10');
await admin.click('[data-select-all]');
await admin.click('[data-bulk-action="disable"]');
await admin.waitForSelector('dialog[open]');
const o2 = await api(admin2, 'PUT', '/api/v1/admin/rules/ruff/F841/override', { enabled: false, expected_enabled: true, reason: '다른 관리자' });
check(o2.status === 200, '다른 관리자 변경', o2);
await admin.click('dialog[open] button[type="submit"]');
await admin.waitForFunction(() => /다시 미리보기합니다/.test(document.querySelector('[data-bulk-result]').textContent), { timeout: 10000 });
await admin.waitForSelector('dialog[open]', { timeout: 10000 });
r.conflict = await dialogText(admin);
await admin.click('dialog[open] button[type="submit"]');
await admin.waitForFunction(() => /바꿨습니다\(끔\)/.test(document.querySelector('[data-bulk-result]').textContent), { timeout: 10000 });
check(/끄기/.test(r.conflict), '409 → 알림 + 다시 미리보기 → 적용', r.conflict);

// ---- 7. 키보드만: 선택(Space) → 막대 버튼(Enter) → 창(Enter로 확인) ----
await openList(admin, '?language=python&prefix=D10');
await admin.focus('[data-rows] tr:first-child [data-select]');
await admin.keyboard.press('Space');
await admin.focus('[data-bulk-action="enable"]');
await admin.keyboard.press('Enter');
await admin.waitForSelector('dialog[open]');
r.kbFocus = await admin.evaluate(() => document.activeElement?.name ?? null);
await admin.keyboard.type('키보드');
await admin.keyboard.press('Enter');
await admin.waitForFunction(() => /바꿨습니다\(켬\)/.test(document.querySelector('[data-bulk-result]').textContent), { timeout: 10000 });
check(r.kbFocus === 'reason', '키보드만으로 선택·일괄(창 포커스는 사유 칸, 20개 이하라 도구 이름 입력 없음)', r.kbFocus);

// ---- 8. 일반 사용자: 변경 UI 없음, 배지만 ----
const user = await session(userLogin);
await openList(user, '?language=python&prefix=D10');
r.user = await user.evaluate(() => ({ selectAll: document.querySelector('[data-select-all]'), bar: document.querySelector('[data-bulk-bar]'), switches: document.querySelectorAll('[role="switch"]').length,
  boxes: document.querySelectorAll('[data-select]').length, badges: document.querySelectorAll('.rule-overridden').length, admin: document.querySelector('[data-rules]').dataset.admin }));
r.userLayout = await layout(user);
check(r.userLayout.clipped === 0 && r.userLayout.page === 0, '일반 사용자 목록: "적용 중 + 변경됨" 안 잘림', r.userLayout);
const u403 = await api(user, 'POST', '/api/v1/admin/rules/overrides/bulk/preview', { tool: 'ruff', action: 'enable', target: { mode: 'keys', keys: ['D100'] } });
check(r.user.selectAll === null && r.user.bar === null && r.user.switches === 0 && r.user.boxes === 0 && r.user.badges > 0 && r.user.admin === '0' && u403.status === 403,
  '일반 사용자: 변경 UI 없음·배지 있음·API 403', { ...r.user, api: u403.status });
await user.screenshot({ path: path.join(OUT, 'P22C_list_user.png') });

// ---- 9. "규칙 설정"에 반영 → 기본값으로 일괄 되돌리기 → 빈 상태 ----
await admin.goto(`${BASE}/admin/rule-settings`, { waitUntil: 'networkidle0' });
await admin.waitForFunction(() => document.querySelector('[data-loading]').hidden && document.querySelectorAll('[data-rows] tr').length > 0, { timeout: 15000 });
r.settings = await admin.evaluate(() => ({ count: document.querySelector('[data-count]').textContent, reasons: [...new Set([...document.querySelectorAll('[data-rows] .arc-reason')].map((n) => n.textContent))] }));
check(r.settings.reasons.includes('pydocstyle 일괄'), '규칙 설정에 일괄 사유 반영', r.settings);
for (const lang of ['python', 'javascript']) {
  await openList(admin, `?language=${lang}&overridden=1`);
  const n = await admin.evaluate(() => document.querySelectorAll('[data-rows] tr').length);
  if (!n) continue;
  const total = await admin.evaluate(() => Number(document.querySelector('[data-count]').textContent.replace(/[^\d]/g, '')));
  await admin.click('[data-select-all]');
  if (total > n) await admin.click('[data-bulk-all]');
  await admin.click('[data-bulk-action="reset"]');
  await admin.waitForSelector('dialog[open]');
  const needType = await admin.$('dialog[open] input[name="confirm"]');
  if (needType) await admin.type('dialog[open] input[name="confirm"]', lang === 'python' ? 'ruff' : 'ESLint');
  await admin.click('dialog[open] button[type="submit"]');
  await admin.waitForFunction(() => /바꿨습니다\(되돌림\)/.test(document.querySelector('[data-bulk-result]').textContent), { timeout: 10000 });
}
const left = await admin.evaluate(async () => (await (await fetch('/api/v1/admin/rules/overrides')).json()).summary);
check(left.ruff.count === 0 && left.eslint.count === 0, '모두 기본값으로 되돌림', left);
await admin.goto(`${BASE}/admin/rule-settings`, { waitUntil: 'networkidle0' });
await admin.waitForFunction(() => !document.querySelector('[data-empty]').hidden, { timeout: 15000 });

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
