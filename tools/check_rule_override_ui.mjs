// 관리자 규칙 켜기·끄기 브라우저 점검(P22B, 헤드리스 Chrome). 격리 서버·DB 사본(016 + tools/rules_load.php --apply)에서만 쓴다 — 실제로 규칙을 바꾸고 되돌린다.
//   PJG_PASSWORD=... node tools/check_rule_override_ui.mjs <기본 주소> <일반 사용자 로그인> [관리자 로그인]
// 확인(1280·1024px):
//   - admin 상세 "분석 적용": 값·기본값·관리자 변경·적용 범위, 끄기 버튼 → 확인 창(포커스는 사유 칸, Esc는 요청 없이 닫힘, 변경 내용·서버 전체 열린 결함 수),
//     사유 입력·확인(연타해도 요청 하나) → 꺼짐 + "관리자 변경" 배지(아이콘 + 글자, 툴팁 기본값) + 마지막 변경(변경자)·사유 + "기본값으로 되돌리기"
//   - 켤 수 없는 규칙(타입 정보·미리보기·옵션 필요): 켜기 버튼 비활성 + 이유 글자
//   - 409: 다른 관리자가 먼저 바꾸면 최신 상태로 다시 그리고 안내
//   - 목록: "분석 적용" 열(꺼짐 + 배지), 조건 "분석 적용"·"관리자 변경", 가로 스크롤 0
//   - 일반 사용자: 같은 상세에 배지는 보이지만 버튼·변경자·사유 없음, 변경 API 403, 관리 화면 접근 불가
//   - admin "규칙 설정": 요약(변경 수·해시·업로드 설정 프로젝트 수), 표, 한 건 되돌리기, 도구별 전체 되돌리기(도구 이름 입력), 빈 목록
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P22B_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, adminLogin = 'admin'] = process.argv;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_rule_override_ui.mjs <기본 주소> <일반 사용자> [관리자]');
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
const EXPECTED_STATUS = new Set([403, 409, 422]); // 점검이 일부러 내는 응답(아래에서 따로 확인)

async function session(login) {
  const page = await (await browser.createBrowserContext()).newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (403|409|422)/.test(m.text())) return;
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
const detailReady = (p) => p.waitForFunction(() => !document.querySelector('[data-body]').hidden || !document.querySelector('[data-error-box]').hidden, { timeout: 20000 });
const openDetail = async (p, tool, rule) => {
  await p.goto(`${BASE}/rules/${tool}/${rule.split('/').map(encodeURIComponent).join('/')}`, { waitUntil: 'networkidle0' });
  await detailReady(p);
};
/** 상세 "분석 적용" 구역 상태 */
const analysis = (p) => p.evaluate(() => {
  const sec = document.querySelector('.rule-analysis');
  if (!sec) return null;
  const dd = [...sec.querySelectorAll('dt')].map((dt) => [dt.textContent, dt.nextElementSibling?.textContent.trim()]);
  const badge = sec.querySelector('.rule-overridden');
  return {
    state: sec.dataset.analysis, overridden: sec.dataset.overridden, rows: Object.fromEntries(dd),
    badge: badge ? { text: badge.textContent.trim(), icon: badge.querySelector('svg') !== null, title: badge.getAttribute('title') } : null,
    buttons: [...sec.querySelectorAll('[data-override]')].map((b) => [b.dataset.override, b.disabled, b.textContent.trim()]),
    block: sec.querySelector('[data-block]')?.textContent.trim() ?? null,
  };
});
const pageScroll = (p) => p.evaluate(() => {
  const box = document.querySelector('.table-scroll:has(table:not([hidden]))');
  return { page: document.documentElement.scrollWidth - document.documentElement.clientWidth, table: box ? box.scrollWidth - box.clientWidth : 0 };
});
const api = (p, method, url, body) => p.evaluate(async (m, u, b) => {
  const res = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: b === null ? undefined : JSON.stringify(b) });
  return { status: res.status, body: await res.json().catch(() => null) };
}, method, url, body ?? null);

const admin = await session(adminLogin);
let puts = 0;
admin.on('request', (q) => { if (['PUT', 'DELETE', 'POST'].includes(q.method()) && q.url().includes('/override')) puts += 1; });

// ---- 1. admin: 기본 켜짐 규칙 끄기(no-undef) ----
await openDetail(admin, 'eslint', 'no-undef');
let a = await analysis(admin);
r.before = a;
check(a && a.state === 'on' && a.overridden === '0' && a.badge === null && a.buttons.map((b) => b[0]).join() === 'disable'
  && /모든 JavaScript\/TypeScript 프로젝트/.test(a.rows['적용 범위'] ?? ''), 'admin 상세: 적용 중·끄기 버튼·적용 범위', a);
const total = await admin.evaluate(async () => (await (await fetch(`/api/v1/rules/eslint/no-undef`)).json()).data.defects.total);
await admin.click('[data-override="disable"]');
await admin.waitForSelector('dialog[open]');
r.dialog = await admin.evaluate(() => ({ title: document.querySelector('dialog[open] .dialog__title').textContent,
  msg: [...document.querySelectorAll('dialog[open] [id$="-msg"], dialog[open] .rule-dialog-line')].map((n) => n.textContent).join(' / '),
  focus: document.activeElement?.name ?? document.activeElement?.tagName }));
check(r.dialog.focus === 'reason' && /켜짐 → 꺼짐/.test(r.dialog.msg) && r.dialog.msg.includes(`열린 결함: ${total.toLocaleString('ko-KR')}건`) && /이미 끝난 Run의 결함은 바뀌지 않으며/.test(r.dialog.msg),
  '확인 창: 포커스·변경 내용·서버 전체 열린 결함 수', { ...r.dialog, total });
await admin.screenshot({ path: path.join(OUT, 'P22B_dialog.png') });
await admin.keyboard.press('Escape');
await sleep(200);
check(puts === 0 && (await admin.$('dialog[open]')) === null, 'Esc: 요청 없이 닫힘', { puts });
await admin.click('[data-override="disable"]');
await admin.waitForSelector('dialog[open]');
await admin.type('dialog[open] input[name="reason"]', '레거시 코드 정리 전까지');
await admin.evaluate(() => { const b = document.querySelector('dialog[open] button[type="submit"]'); b.click(); b.click(); });
await admin.waitForFunction(() => document.querySelector('.rule-analysis')?.dataset.analysis === 'off', { timeout: 10000 });
await sleep(300);
a = await analysis(admin);
r.after = { ...a, puts };
check(puts === 1, '연타해도 요청 하나', { puts });
check(a.state === 'off' && a.overridden === '1' && a.badge?.text === '관리자 변경' && a.badge.icon && /기본값: 켜짐/.test(a.badge.title ?? '')
  && a.buttons.map((b) => b[0]).join() === 'enable,reset' && /admin|관리자/.test(a.rows['마지막 변경'] ?? '') && a.rows['사유'] === '레거시 코드 정리 전까지',
  '끈 뒤: 꺼짐·배지(아이콘+글자·툴팁)·되돌리기·변경자·사유', a);
await admin.screenshot({ path: path.join(OUT, 'P22B_detail_admin.png'), fullPage: true });

// ---- 2. 켤 수 없는 규칙: 비활성 + 이유 ----
for (const [tool, rule, pattern] of [['eslint', '@typescript-eslint/await-thenable', /타입 정보/], ['eslint', 'id-match', /옵션 없이는/], ['ruff', 'TID251', /옵션 없이는/]]) {
  await openDetail(admin, tool, rule);
  a = await analysis(admin);
  r[`block:${rule}`] = a;
  check(a && a.buttons.length === 1 && a.buttons[0][0] === 'enable' && a.buttons[0][1] === true && pattern.test(a.block ?? ''), `켤 수 없는 규칙 ${rule}`, a);
}
await admin.screenshot({ path: path.join(OUT, 'P22B_detail_blocked.png') });
const preview = await admin.evaluate(async () => {
  for (let page = 1; page < 10; page++) {
    const j = await (await fetch(`/api/v1/rules?tool=ruff&size=200&page=${page}`)).json();
    const hit = j.data.find((x) => x.enable_block === 'preview' && !x.analysis_enabled);
    if (hit) return hit.rule;
    if (j.data.length < 200) return null;
  }
  return null;
});
if (preview) {
  await openDetail(admin, 'ruff', preview);
  a = await analysis(admin);
  r.preview = { rule: preview, ...a };
  check(a.buttons[0][1] === true && /미리보기/.test(a.block ?? ''), `미리보기 규칙 ${preview}`, a);
  const res = await api(admin, 'PUT', `/api/v1/admin/rules/ruff/${preview}/override`, { enabled: true, expected_enabled: false });
  check(res.status === 422 && res.body?.error?.details?.reason === 'preview', '서버도 미리보기 켜기 거부(422)', res);
}

// ---- 3. 켜기(기본 꺼짐 D100) + 409 ----
await openDetail(admin, 'ruff', 'D100');
a = await analysis(admin);
check(a.state === 'off' && /서버 기본 설정을 쓰는 Python 프로젝트만/.test(a.rows['적용 범위'] ?? ''), 'ruff 적용 범위 안내', a);
await admin.click('[data-override="enable"]');
await admin.waitForSelector('dialog[open]');
await admin.click('dialog[open] button[type="submit"]');
await admin.waitForFunction(() => document.querySelector('.rule-analysis')?.dataset.analysis === 'on', { timeout: 10000 });
await openDetail(admin, 'ruff', 'E501');
const admin2 = await session(adminLogin);
await admin2.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
const other = await api(admin2, 'PUT', '/api/v1/admin/rules/ruff/E501/override', { enabled: false, expected_enabled: true, reason: '다른 관리자' });
check(other.status === 200, '다른 관리자가 먼저 끔', other);
await admin.click('[data-override="disable"]');
await admin.waitForSelector('dialog[open]');
await admin.click('dialog[open] button[type="submit"]');
await admin.waitForFunction(() => /최신 상태로 다시 표시/.test(document.querySelector('#toasts')?.textContent ?? ''), { timeout: 10000 });
a = await analysis(admin);
r.conflict = a;
check(a.state === 'off' && a.rows['사유'] === '다른 관리자', '409: 최신 상태로 다시 그림 + 안내', a);

// ---- 4. 목록: 열·조건·가로 스크롤 ----
for (const width of [1280, 1024]) {
  await admin.setViewport({ width, height: 900 });
  await admin.goto(`${BASE}/rules?language=javascript&overridden=1`, { waitUntil: 'networkidle0' });
  await admin.waitForFunction(() => !document.querySelector('[data-rules]').hasAttribute('aria-busy') && document.querySelectorAll('[data-rows] tr').length > 0, { timeout: 20000 });
  const L = await admin.evaluate(() => ({ rows: [...document.querySelectorAll('[data-rows] tr')].map((tr) => [tr.querySelector('a.rule-key')?.textContent, tr.querySelector('.rcol-default')?.textContent.trim()]),
    head: [...document.querySelectorAll('.rules-table thead th')].map((th) => th.textContent.trim()), overriddenSel: document.querySelector('select[name="overridden"]').value,
    badge: document.querySelector('[data-rows] .rule-overridden')?.getAttribute('aria-label') ?? null,
    // 배지가 칸 안에 다 보이는가(말줄임으로 잘리지 않음)
    clipped: (() => { const td = document.querySelector('[data-rows] .rcol-default'); return td ? td.scrollWidth > td.clientWidth : true; })() }));
  const S = await pageScroll(admin);
  r[`list@${width}`] = { ...L, ...S };
  check(L.rows.length === 1 && L.rows[0][0] === 'no-undef' && /꺼짐\s*변경됨/.test(L.rows[0][1]) && L.badge === '관리자 변경 · 기본값: 켜짐' && !L.clipped && L.head.includes('분석 적용') && !L.head.includes('기본 활성')
    && L.overriddenSel === '1' && S.page === 0 && S.table === 0, `목록 ${width}: 분석 적용 열·관리자 변경 조건·가로 스크롤 0`, r[`list@${width}`]);
  await admin.screenshot({ path: path.join(OUT, `P22B_list_${width}.png`) });
}
await admin.goto(`${BASE}/rules?language=python&analysis=1&q=D100`, { waitUntil: 'networkidle0' });
await admin.waitForFunction(() => !document.querySelector('[data-rules]').hasAttribute('aria-busy'), { timeout: 20000 });
r.analysisFilter = await admin.evaluate(() => [...document.querySelectorAll('[data-rows] a.rule-key')].map((x) => x.textContent));
check(r.analysisFilter.includes('D100'), '분석 적용 = 적용 중 조건에 켠 D100', r.analysisFilter);
await admin.setViewport({ width: 1024, height: 900 });
await openDetail(admin, 'eslint', 'no-undef');
check((await pageScroll(admin)).page === 0, '상세 1024: 가로 스크롤 0', await pageScroll(admin));
await admin.setViewport({ width: 1280, height: 900 });

// ---- 5. 일반 사용자 ----
const user = await session(userLogin);
await openDetail(user, 'eslint', 'no-undef');
a = await analysis(user);
r.user = a;
const userText = await user.$eval('.rule-analysis', (n) => n.textContent);
check(a.state === 'off' && a.badge?.text === '관리자 변경' && a.buttons.length === 0 && !('마지막 변경' in a.rows) && !('사유' in a.rows) && !userText.includes('레거시 코드'),
  '일반 사용자: 배지만, 버튼·변경자·사유 없음', a);
await user.screenshot({ path: path.join(OUT, 'P22B_detail_user.png'), fullPage: true });
const u403 = await api(user, 'PUT', '/api/v1/admin/rules/eslint/no-undef/override', { enabled: true, expected_enabled: false });
const uList = await user.goto(`${BASE}/admin/rule-settings`, { waitUntil: 'networkidle0' });
r.userDenied = { put: u403.status, page: uList.status() };
check(u403.status === 403 && uList.status() !== 200, '일반 사용자: 변경 403·관리 화면 접근 불가', r.userDenied);

// ---- 6. admin "규칙 설정" ----
const settingsReady = (p) => p.waitForFunction(() => document.querySelector('[data-loading]').hidden && document.querySelector('[data-summary]').textContent !== '', { timeout: 20000 });
for (const width of [1280, 1024]) {
  await admin.setViewport({ width, height: 900 });
  await admin.goto(`${BASE}/admin/rule-settings`, { waitUntil: 'networkidle0' });
  await settingsReady(admin);
  const S = await pageScroll(admin);
  const T = await admin.evaluate(() => ({ summary: [...document.querySelectorAll('[data-summary] p')].map((p) => p.textContent), rows: [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.dataset.key),
    subnav: document.querySelector('.subnav [aria-current="page"]')?.textContent.trim(), count: document.querySelector('[data-count]').textContent,
    // 칸 밖으로 넘친 글(옆 칸과 겹침) — 긴 값은 칸 안에서 줄바꿈해야 한다
    overflow: [...document.querySelectorAll('[data-rows] td')].filter((td) => td.scrollWidth > td.clientWidth + 1).map((td) => td.className) }));
  r[`settings@${width}`] = { ...T, ...S };
  check(T.subnav === '규칙 설정' && T.rows.join() === 'ruff:D100,ruff:E501' && /ruff: 변경 2건\(켬 1 · 끔 1\)/.test(T.summary[0]) && /실효 설정 해시 [0-9a-f]{12}/.test(T.summary[0])
    && /ruff 설정을 직접 올려/.test(T.summary.at(-1)) && S.page === 0 && S.table === 0 && T.overflow.length === 0, `규칙 설정 ${width}: 요약·표·가로 스크롤 0`, r[`settings@${width}`]);
  await admin.screenshot({ path: path.join(OUT, `P22B_settings_${width}.png`), fullPage: true });
}
await admin.setViewport({ width: 1280, height: 900 });
// 한 건 되돌리기(D100)
await admin.click('[data-reset="ruff:D100"]');
await admin.waitForSelector('dialog[open]');
await admin.click('dialog[open] button[value="ok"]');
await admin.waitForFunction(() => [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.dataset.key).join() === 'ruff:E501', { timeout: 10000 });
// ESLint 전체 되돌리기(도구 이름 입력)
await admin.select('[data-tool]', 'eslint');
await admin.waitForFunction(() => [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.dataset.key).join() === 'eslint:no-undef', { timeout: 10000 });
await admin.click('[data-reset-all]');
await admin.waitForSelector('dialog[open]');
const okDisabledBefore = await admin.$eval('dialog[open] button[value="ok"]', (b) => b.disabled);
await admin.type('dialog[open] input', 'ruff');
const okDisabledWrong = await admin.$eval('dialog[open] button[value="ok"]', (b) => b.disabled);
await admin.$eval('dialog[open] input', (n) => { n.value = ''; });
await admin.type('dialog[open] input', 'ESLint');
const okEnabled = await admin.$eval('dialog[open] button[value="ok"]', (b) => !b.disabled);
await admin.screenshot({ path: path.join(OUT, 'P22B_reset_all.png') });
await admin.click('dialog[open] button[value="ok"]');
await admin.waitForFunction(() => !document.querySelector('[data-empty]').hidden, { timeout: 10000 });
r.resetAll = { okDisabledBefore, okDisabledWrong, okEnabled, empty: await admin.$eval('[data-empty-text]', (n) => n.textContent) };
// P22C: 빈 상태 문구가 바뀜 — ruff에 E501 변경이 남아 있어 "조건에 맞는 변경이 없습니다"(필터 때문에 빔)
check(okDisabledBefore && okDisabledWrong && okEnabled && r.resetAll.empty === '조건에 맞는 변경이 없습니다.', '전체 되돌리기: 도구 이름 입력 확인·빈 목록', r.resetAll);
// 마무리: 남은 E501 되돌리기(사본이라도 원래대로)
const left = await api(admin, 'DELETE', '/api/v1/admin/rules/ruff/E501/override', null);
check(left.status === 200 && left.body.data.cleared === true, '마무리 되돌리기', left);
const audit = await admin.evaluate(async () => (await (await fetch('/api/v1/admin/rules/overrides')).json()).summary);
check(audit.ruff.count === 0 && audit.eslint.count === 0, '모두 기본값', audit);

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
