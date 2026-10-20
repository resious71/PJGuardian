// 규칙 설명 브라우저 점검(P22, 헤드리스 Chrome). 격리 서버·DB 사본(015 + tools/rules_load.php --apply로 실제 도구 적재) + 격리 워커에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_rules_ui.mjs <기본 주소> <일반 사용자 로그인> <결함이 있는 JS 프로젝트 id> [관리자 로그인]
// 확인(1280·1024px):
//   - 주 메뉴 "규칙 설명"이 켜져 있고 /rules로 간다. 위쪽에 기준 버전("기준: ruff … · ESLint … · @typescript-eslint …")
//   - 목록: 표 가로 스크롤 0, 머리글 한 줄, 검색어 입력만으로는 요청 없음 → Enter로 검색(주소 반영), 언어 탭(X08: 도구 콤보박스 대신)·칩(심각도)·검색 버튼,
//     정렬, 쪽 이동, 새로 고침하면 조건 복원
//   - 상세: 마크다운 설명(제목·코드 블록), 기본 심각도와 근거, 변경 내역, 결함 수, 인코딩된 주소(@typescript-eslint/…),
//     활성 버전에 없는 규칙(설치된 이전 ESLint에만 있는 규칙)의 안내, 문법 오류(특수 키), 목록으로 돌아가기(조건 유지)
//   - 결함 표·결함 상세의 규칙 링크 → 규칙 상세. 키보드(Tab·Enter)로 규칙 링크 이동
//   - admin "규칙 데이터 다시 불러오기"(진행 표시 → 완료, 연타해도 요청 하나), 일반 사용자에게는 버튼 없음·API 403
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P22_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, jsProject, adminLogin = 'admin'] = process.argv;
if (!userLogin || !jsProject || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_rules_ui.mjs <기본 주소> <일반 사용자> <JS 프로젝트 id> [관리자]');
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
    else if (/status of 403/.test(m.text())) return;
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400 && res.status() !== 403) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const listReady = (p) => p.waitForFunction(() => !document.querySelector('[data-rules]').hasAttribute('aria-busy') && /규칙 [\d,]+개/.test(document.querySelector('[data-count]').textContent), { timeout: 20000 });
const detailReady = (p) => p.waitForFunction(() => !document.querySelector('[data-body]').hidden || !document.querySelector('[data-error-box]').hidden, { timeout: 20000 });
const layout = (p) => p.evaluate(() => {
  const box = document.querySelector('.table-scroll:has(.rules-table)');
  const ths = [...document.querySelectorAll('.rules-table thead th')];
  return { scroll: box.scrollWidth - box.clientWidth, page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    headTall: ths.filter((th) => th.getBoundingClientRect().height > 48).map((th) => th.textContent.trim()), rows: document.querySelectorAll('[data-rows] tr').length };
});

const user = await session(userLogin);
const apiReqs = [];
user.on('request', (q) => { if (q.url().includes('/api/v1/rules?')) apiReqs.push(q.url()); });

// ---- 메뉴 → 목록 ----
await user.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
r.menu = await user.$eval('a[data-nav="nav.rules"]', (a) => [a.getAttribute('href'), a.classList.contains('is-disabled')]).catch(() => null);
check(r.menu && r.menu[0].endsWith('/rules') && !r.menu[1], '메뉴 "규칙 설명" 켜짐', r.menu);
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('a[data-nav="nav.rules"]')]);
await listReady(user);
r.basis = await user.$eval('[data-basis]', (n) => n.textContent);
// X08: 기준 줄은 현재 탭(첫 화면은 Python)의 도구만
check(/^기준: ruff [\d.]+ · 마지막 적재 /.test(r.basis), '기준 버전 표시', r.basis);
for (const width of [1280, 1024]) {
  await user.setViewport({ width, height: 900 });
  await user.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
  await listReady(user);
  const L = await layout(user);
  r[`list@${width}`] = L;
  check(L.scroll === 0 && L.page === 0 && L.headTall.length === 0 && L.rows > 0, `목록 ${width}: 가로 스크롤 0·머리글 한 줄`, L);
  await user.screenshot({ path: path.join(OUT, `P22_list_${width}.png`), fullPage: false });
}
await user.setViewport({ width: 1280, height: 900 });

// ---- 검색: 입력만으로는 요청 없음 → Enter ----
apiReqs.length = 0;
await user.type('#rf-q', 'unused');
await sleep(500);
r.typingRequests = apiReqs.length;
await Promise.all([user.waitForResponse((x) => x.url().includes('/api/v1/rules?')), user.keyboard.press('Enter')]);
await listReady(user);
r.search = { url: new URL(user.url()).search, count: await user.$eval('[data-count]', (n) => n.textContent), dirty: await user.$eval('[data-search-dirty]', (n) => n.textContent) };
check(r.typingRequests === 0 && r.search.url.includes('q=unused') && r.search.dirty === '', '검색어 Enter로 적용', r);
// X08: 도구 콤보박스 대신 JavaScript 탭(바꾸면 그 탭의 기본 조건 — 검색어 없음) + 심각도 칩(높음) + 검색 버튼
await Promise.all([user.waitForResponse((x) => x.url().includes('tool=eslint')), user.click('[data-rule-tabs] [data-language="javascript"]')]);
await listReady(user);
await user.click('[data-facet="severity"] .chip[data-value="high"]');
r.dirtyBefore = await user.$eval('[data-search-dirty]', (n) => n.textContent);
await Promise.all([user.waitForResponse((x) => x.url().includes('/api/v1/rules?')), user.click('[data-search-submit]')]);
await listReady(user);
r.filtered = await user.evaluate(() => ({ url: location.search, tools: [...new Set([...document.querySelectorAll('[data-rows] a.rule-key')].map((a) => a.getAttribute('href').split('/rules/')[1].split('/')[0]))],
  sev: [...new Set([...document.querySelectorAll('[data-rows] .rcol-severity [data-badge]')].map((n) => n.dataset.badge))] }));
check(r.dirtyBefore === '조건이 변경되었습니다' && !r.filtered.url.includes('q=') && r.filtered.url.includes('language=javascript') && r.filtered.url.includes('severity=high')
  && r.filtered.tools.join() === 'eslint' && r.filtered.sev.join() === 'severity.high', '도구·심각도 필터', r);
await user.screenshot({ path: path.join(OUT, 'P22_list_filtered.png') });
// 새로 고침 → 복원
await user.reload({ waitUntil: 'networkidle0' });
await listReady(user);
r.restored = await user.evaluate(() => ({ q: document.querySelector('#rf-q').value, tool: document.querySelector('[data-rule-tabs] [aria-selected="true"]')?.dataset.language, high: document.querySelector('[data-facet="severity"] .chip[data-value="high"]').getAttribute('aria-pressed') }));
check(r.restored.q === '' && r.restored.tool === 'javascript' && r.restored.high === 'true', '주소에서 조건 복원', r.restored);
// 쪽 이동(조건 없이)
await user.goto(`${BASE}/rules?language=python&sort=severity`, { waitUntil: 'networkidle0' });
await listReady(user);
const firstSev = await user.$eval('[data-rows] tr [data-badge]', (n) => n.dataset.badge);
await Promise.all([user.waitForResponse((x) => x.url().includes('page=2')), user.click('[data-pagination] [data-page="2"], [data-pagination] button:not([disabled]):last-child')]);
await listReady(user);
r.paging = { url: new URL(user.url()).search, firstSev };
check(r.paging.url.includes('page=2') && firstSev === 'severity.critical', '정렬(심각도)·쪽 이동', r.paging);

// ---- 상세: 키보드로 규칙 링크 ----
await user.goto(`${BASE}/rules?language=python&q=F401`, { waitUntil: 'networkidle0' });
await listReady(user);
await user.focus('[data-rows] tr:first-child a.rule-key');
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.keyboard.press('Enter')]);
await detailReady(user);
r.f401 = await user.evaluate(() => ({ url: location.pathname, h3: document.querySelectorAll('.md h3').length, pre: document.querySelectorAll('.md pre').length,
  sev: document.querySelector('.rule-severity')?.textContent.trim(), hist: document.querySelector('[data-history]')?.textContent.trim().slice(0, 60),
  back: document.querySelector('[data-back-to-list]').getAttribute('href') }));
check(r.f401.url.endsWith('/rules/ruff/F401') && r.f401.h3 > 0 && r.f401.pre > 0 && /근거/.test(r.f401.sev ?? '') && r.f401.back.includes('q=F401'), 'ruff 상세(마크다운·심각도 근거·돌아가기 조건)', r.f401);
await user.screenshot({ path: path.join(OUT, 'P22_detail_ruff.png'), fullPage: true });
await user.goto(`${BASE}/rules/eslint/%40typescript-eslint/no-unused-vars`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.ts = await user.evaluate(() => ({ title: document.querySelector('.rule-title')?.textContent, docs: document.querySelector('.rule-summary a[rel="noopener noreferrer"]')?.getAttribute('href'),
  desc: document.querySelector('.rule-description')?.textContent.trim().slice(0, 80) }));
check(r.ts.title === 'eslint:@typescript-eslint/no-unused-vars' && (r.ts.docs ?? '').startsWith('https://') && /설명 없음/.test(r.ts.desc ?? ''), 'TS 규칙(인코딩된 주소·문서 링크·설명 없음)', r.ts);
await user.screenshot({ path: path.join(OUT, 'P22_detail_eslint_ts.png'), fullPage: true });
await user.goto(`${BASE}/rules/eslint/valid-jsdoc`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.missing = await user.evaluate(() => document.querySelector('[data-missing]')?.textContent.trim() ?? null);
check(/활성 버전 .*에는 없는 규칙입니다/.test(r.missing ?? ''), '활성 버전에 없는 규칙 안내', r.missing);
await user.screenshot({ path: path.join(OUT, 'P22_detail_missing.png'), fullPage: true });
await user.goto(`${BASE}/rules/eslint/no-new-symbol`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.hist = await user.$$eval('.rule-history tbody tr', (rows) => rows.map((tr) => tr.textContent.trim()));
await user.goto(`${BASE}/rules/syntax-error`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.syntax = await user.$eval('[data-body]', (n) => n.textContent.slice(0, 40));

// ---- 결함 표·상세의 규칙 링크 ----
await user.goto(`${BASE}/projects/${jsProject}`, { waitUntil: 'networkidle0' });
await user.waitForSelector('[data-rows] tr');
const link = await user.$eval('[data-rows] tr:first-child .defects-table__rule a', (a) => a.getAttribute('href'));
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-rows] tr:first-child .defects-table__rule a')]);
await detailReady(user);
r.fromTable = { link, at: new URL(user.url()).pathname, title: await user.$eval('.rule-title, h1', (n) => n.textContent) };
check(r.fromTable.link.includes('/rules/eslint/') && r.fromTable.at === decodeURI(r.fromTable.link) || r.fromTable.at === r.fromTable.link, '결함 표 규칙 링크', r.fromTable);
await user.goto(`${BASE}/projects/${jsProject}`, { waitUntil: 'networkidle0' });
await user.waitForSelector('[data-rows] tr');
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-rows] tr:first-child .defects-table__location a')]);
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('a.defect-rule')]);
await detailReady(user);
r.fromDetail = new URL(user.url()).pathname;
check(r.fromDetail.startsWith('/rules/'), '결함 상세 규칙 링크', r.fromDetail);
r.userReload = { button: await (await (async () => { await user.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' }); return user.$('[data-rules-reload]'); })()) === null,
  api: await user.evaluate(async () => (await fetch('/api/v1/admin/rules/reload', { method: 'POST', headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content } })).status) };
check(r.userReload.button && r.userReload.api === 403, '일반 사용자: 다시 불러오기 없음·403', r.userReload);

// ---- admin 다시 불러오기 ----
const admin = await session(adminLogin);
await admin.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
await listReady(admin);
let posts = 0;
admin.on('request', (q) => { if (q.method() === 'POST' && q.url().includes('/admin/rules/reload')) posts += 1; });
await admin.click('[data-rules-reload]');
r.reloadBusy = await admin.$eval('[data-rules-reload]', (b) => [b.disabled, b.getAttribute('aria-busy')]);
await admin.evaluate(() => document.querySelector('[data-rules-reload]').click());
await admin.waitForFunction(() => /다시 불러왔습니다|불러오지 못한|끝나지 않았습니다/.test(document.querySelector('[data-reload-status]').textContent), { timeout: 300000 });
r.reload = { posts, status: await admin.$eval('[data-reload-status]', (n) => n.textContent) };
check(r.reloadBusy[0] === true && posts === 1 && r.reload.status.includes('다시 불러왔습니다'), 'admin 다시 불러오기', { busy: r.reloadBusy, ...r.reload });
await admin.screenshot({ path: path.join(OUT, 'P22_admin_reload.png') });

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
