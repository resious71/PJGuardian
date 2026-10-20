// 규칙 설명 언어 탭 브라우저 점검(X08, 헤드리스 Chrome). 격리 서버·DB 사본(015 + tools/rules_load.php --apply)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_rules_tabs.mjs <기본 주소> <일반 사용자 로그인> [관리자 로그인]
// 확인:
//   - 첫 화면은 Python 탭(저장된 탭 없음) — 주소가 ?language=python으로 고쳐지고, 기준 줄·목록·카테고리가 ruff만, 탭 머리글에 총 규칙 수(meta와 같음)
//   - 도구 필터·도구 열 없음. 선택 탭은 색 말고도 굵기·밑줄로 구분, ARIA(tablist/tab/tabpanel, aria-selected, 로빙 tabindex)
//   - 탭 클릭: 페이지 다시 불러오지 않음, pushState(이동 기록 +1), 목록·기준 줄·카테고리·건수가 그 탭으로
//   - 키보드: ←/→/Home/End로 탭 이동·선택(포커스 따라감)
//   - 탭별 독립: Python에서 검색한 뒤 JavaScript로 가면 기본 조건, 뒤로 가기로 Python + 검색어 복원, 새로 고침하면 같은 탭
//   - 마지막 탭 기억(주소에 language 없을 때), 예전 주소 ?tool=eslint → JavaScript(replaceState, 이동 기록 그대로), language가 tool보다 우선, 잘못된 language 무시
//   - 빈 목록 안내(조건에 맞는 규칙 없음), 최신 아님 안내는 탭 도구 기준(meta와 일치), 적재 전 탭 안내(응답 바꿔치기로 ESLint만 적재 전), 포커스 링
//   - 상세: 주소 그대로(/rules/eslint/…), 링크 탭(현재 언어 aria-current, 활성 버전에 없는 규칙도), 다른 탭 링크 → 그 언어 목록, "목록으로"는 그 탭의 마지막 조건
//   - 결함 표 규칙 링크 → 상세(JavaScript 표시)
//   - 빠른 전환: ruff 응답을 늦춰도 마지막으로 고른 탭의 목록만 보인다
//   - 1280·1024px: 가로 스크롤 0, 탭 한 줄. admin도 탭 + 다시 불러오기 버튼
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 X08_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, adminLogin = 'admin'] = process.argv;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_rules_tabs.mjs <기본 주소> <일반 사용자> [관리자]');
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
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
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
const detailReady = (p) => p.waitForFunction(() => !document.querySelector('[data-body]').hidden || !document.querySelector('[data-error-box]').hidden, { timeout: 20000 });
/** 목록 화면 상태 한 번에 */
const listState = (p) => p.evaluate(() => {
  const tabs = [...document.querySelectorAll('[data-rule-tabs] [role="tab"]')];
  const sel = tabs.find((b) => b.getAttribute('aria-selected') === 'true');
  const tools = [...new Set([...document.querySelectorAll('[data-rows] a.rule-key')].map((a) => a.getAttribute('href').split('/rules/')[1].split('/')[0]))];
  return {
    search: location.search, hist: history.length, marker: window.__x08 ?? null,
    selected: sel?.dataset.language ?? null, focused: document.activeElement?.dataset?.language ?? null,
    tabindex: tabs.map((b) => b.tabIndex), labels: tabs.map((b) => b.textContent.replace(/\s+/g, ' ').trim()),
    panelBy: document.querySelector('[role="tabpanel"]')?.getAttribute('aria-labelledby'),
    basis: document.querySelector('[data-basis]').textContent, stale: document.querySelector('[data-stale]').hidden ? '' : document.querySelector('[data-stale]').textContent,
    tools, rows: document.querySelectorAll('[data-rows] tr').length, count: document.querySelector('[data-count]').textContent,
    empty: document.querySelector('[data-empty]').hidden ? '' : document.querySelector('[data-empty]').textContent,
    q: document.querySelector('#rf-q').value, categories: [...document.querySelectorAll('select[name="category"] option')].slice(1).map((o) => o.value),
    toolFilter: document.querySelector('#rf-tool, [name="tool"]') !== null, toolCol: document.querySelector('.rcol-tool') !== null,
  };
});
const layout = (p) => p.evaluate(() => {
  const box = document.querySelector('.table-scroll:has(.rules-table)');
  const tabs = [...document.querySelectorAll('[data-rule-tabs] [role="tab"], .tabs__list .tab-link')];
  const tops = new Set(tabs.map((b) => Math.round(b.getBoundingClientRect().top)));
  const tall = tabs.filter((b) => b.getBoundingClientRect().height > 48).length;
  return { scroll: box ? box.scrollWidth - box.clientWidth : 0, page: document.documentElement.scrollWidth - document.documentElement.clientWidth, tabRows: tops.size, tall };
});
const clickTab = async (p, lang, tool) => {
  await Promise.all([p.waitForResponse((x) => x.url().includes('/api/v1/rules?') && x.url().includes(`tool=${tool}`)), p.click(`[data-rule-tabs] [data-language="${lang}"]`)]);
  await listReady(p);
};

const user = await session(userLogin);
const meta = await user.evaluate(async () => (await fetch('/api/v1/rules/meta')).json().then((j) => j.data));
r.meta = { ruff: [meta.tools.ruff.version, meta.tools.ruff.rules, !!meta.tools.ruff.stale], eslint: [meta.tools.eslint.version, meta.tools.eslint.rules, !!meta.tools.eslint.stale] };
const fmt = (n) => Number(n).toLocaleString('ko-KR');

// ---- 1. 첫 화면: Python 탭 ----
await user.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
await listReady(user);
await user.evaluate(() => { window.__x08 = 'same-page'; });
let s = await listState(user);
r.first = s;
check(s.selected === 'python' && s.search === '?language=python' && s.panelBy === 'rules-tab-python' && s.tabindex.join() === '0,-1', '첫 화면 Python 탭·주소 고쳐 씀·ARIA', s);
check(/^기준: ruff [\d.]+ · 마지막 적재 /.test(s.basis) && !s.basis.includes('ESLint') && s.tools.join() === 'ruff', 'Python 탭: 기준 줄·목록이 ruff만', s);
check(s.labels[0] === `Python · ruff (${fmt(meta.tools.ruff.rules)})` && s.labels[1] === `JavaScript/TypeScript · ESLint (${fmt(meta.tools.eslint.rules)})`, '탭 머리글 총 규칙 수', s.labels);
check(!s.toolFilter && !s.toolCol, '도구 필터·도구 열 없음', s);
check(s.categories.length > 0 && s.categories.every((c) => meta.tools.ruff.categories.includes(c)), 'Python 탭 카테고리는 ruff만', s.categories);
check(s.stale === '' === !meta.tools.ruff.stale, '최신 아님 안내(ruff 기준)', s.stale);
r.look = await user.evaluate(() => {
  const [a, b] = [...document.querySelectorAll('[data-rule-tabs] [role="tab"]')].map((n) => getComputedStyle(n));
  return { weight: [a.fontWeight, b.fontWeight], border: [a.borderBottomWidth + ' ' + a.borderBottomStyle, b.borderBottomWidth + ' ' + b.borderBottomStyle], shadow: [a.boxShadow, b.boxShadow] };
});
check(r.look.weight[0] !== r.look.weight[1] || r.look.border[0] !== r.look.border[1] || r.look.shadow[0] !== r.look.shadow[1], '선택 탭은 색 말고도 구분(굵기·밑줄)', r.look);
for (const width of [1280, 1024]) {
  await user.setViewport({ width, height: 900 });
  await sleep(150);
  const L = await layout(user);
  r[`py@${width}`] = L;
  check(L.scroll === 0 && L.page === 0 && L.tabRows === 1 && L.tall === 0, `Python 탭 ${width}: 가로 스크롤 0·탭 한 줄`, L);
  await user.screenshot({ path: path.join(OUT, `X08_python_${width}.png`) });
}
await user.setViewport({ width: 1280, height: 900 });

// ---- 2. 탭 클릭: 다시 불러오지 않음, pushState ----
const hist0 = s.hist;
await clickTab(user, 'javascript', 'eslint');
s = await listState(user);
r.js = s;
check(s.marker === 'same-page' && s.hist === hist0 + 1 && s.search === '?language=javascript' && s.selected === 'javascript' && s.panelBy === 'rules-tab-javascript', 'JavaScript 탭: 같은 페이지·이동 기록 +1·주소', s);
check(/^기준: ESLint [\d.]+ · @typescript-eslint [\d.]+ · 마지막 적재 /.test(s.basis) && s.tools.join() === 'eslint' && s.count.includes(fmt(meta.tools.eslint.rules)), 'JavaScript 탭: 기준 줄·목록·건수', s);
check(s.categories.length > 0 && s.categories.every((c) => meta.tools.eslint.categories.includes(c)) && s.categories.join() !== r.first.categories.join(), 'JavaScript 탭 카테고리는 ESLint만', s.categories);
check(s.stale === '' === !meta.tools.eslint.stale, '최신 아님 안내(ESLint 기준)', s.stale);
for (const width of [1280, 1024]) {
  await user.setViewport({ width, height: 900 });
  await sleep(150);
  const L = await layout(user);
  r[`js@${width}`] = L;
  check(L.scroll === 0 && L.page === 0 && L.tabRows === 1, `JavaScript 탭 ${width}: 가로 스크롤 0·탭 한 줄`, L);
  await user.screenshot({ path: path.join(OUT, `X08_javascript_${width}.png`) });
}
await user.setViewport({ width: 1280, height: 900 });

// ---- 3. 키보드 ----
const key = async (k, tool) => {
  await Promise.all([user.waitForResponse((x) => x.url().includes(`tool=${tool}`)), user.keyboard.press(k)]);
  await listReady(user);
  return listState(user);
};
await user.focus('[data-rule-tabs] [aria-selected="true"]');
await user.keyboard.press('Shift'); // 키보드 포커스로 인식(:focus-visible)
r.focusRing = await user.evaluate(() => { const st = getComputedStyle(document.activeElement); return [st.outlineStyle, st.outlineWidth]; });
check(r.focusRing[0] !== 'none' && parseFloat(r.focusRing[1]) >= 2, '탭 포커스 링', r.focusRing);
const k1 = await key('ArrowLeft', 'ruff');
const k2 = await key('End', 'eslint');
const k3 = await key('Home', 'ruff');
const k4 = await key('ArrowRight', 'eslint');
r.keys = [k1, k2, k3, k4].map((x) => [x.selected, x.focused, x.tabindex.join('/'), x.tools.join()]);
check(r.keys.map((x) => x.join(' ')).join(' | ') === 'python python 0/-1 ruff | javascript javascript -1/0 eslint | python python 0/-1 ruff | javascript javascript -1/0 eslint', '키보드 ←/End/Home/→', r.keys);

// ---- 4. 탭별 독립·뒤로 가기·새로 고침 ----
await clickTab(user, 'python', 'ruff');
await user.type('#rf-q', 'unused');
await Promise.all([user.waitForResponse((x) => x.url().includes('q=unused')), user.keyboard.press('Enter')]);
await listReady(user);
const pySearch = (await listState(user)).search;
await clickTab(user, 'javascript', 'eslint');
s = await listState(user);
r.independent = { pySearch, js: s.search, q: s.q, tools: s.tools.join() };
check(pySearch === '?language=python&q=unused' && s.search === '?language=javascript' && s.q === '' && s.tools.join() === 'eslint', '탭별 독립(새 탭은 기본 조건)', r.independent);
await Promise.all([user.waitForResponse((x) => x.url().includes('q=unused')), user.goBack()]);
await listReady(user);
s = await listState(user);
r.back = { search: s.search, selected: s.selected, q: s.q, tools: s.tools.join(), marker: s.marker, basis: s.basis.slice(0, 12) };
check(s.search === '?language=python&q=unused' && s.selected === 'python' && s.q === 'unused' && s.tools.join() === 'ruff' && s.marker === 'same-page' && s.basis.startsWith('기준: ruff'), '뒤로 가기: Python + 검색어 복원', r.back);
await Promise.all([user.waitForResponse((x) => x.url().includes('tool=eslint')), user.goForward()]);
await listReady(user);
await user.reload({ waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.reload = { search: s.search, selected: s.selected, tools: s.tools.join() };
check(s.search === '?language=javascript' && s.selected === 'javascript' && s.tools.join() === 'eslint', '새로 고침: 같은 탭', r.reload);
// 빈 목록 안내
await user.type('#rf-q', 'zzqqxxnotarule');
await Promise.all([user.waitForResponse((x) => x.url().includes('zzqq')), user.keyboard.press('Enter')]);
await listReady(user);
s = await listState(user);
r.empty = s.empty;
check(s.empty === '조건에 맞는 규칙이 없습니다.' && s.rows === 0, '빈 목록 안내(탭 기준)', s.empty);

// ---- 5. 마지막 탭 기억·예전 주소·잘못된 값 ----
await user.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.remembered = { search: s.search, selected: s.selected };
check(s.search === '?language=javascript' && s.selected === 'javascript', '마지막 탭 기억', r.remembered);
await user.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
const histLegacy = await user.evaluate(() => history.length);
await user.goto(`${BASE}/rules?tool=eslint&q=undef`, { waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.legacy = { search: s.search, selected: s.selected, hist: s.hist - histLegacy, tools: s.tools.join() };
check(s.search === '?language=javascript&q=undef' && s.selected === 'javascript' && s.hist - histLegacy === 1 && s.tools.join() === 'eslint', '예전 주소 tool=eslint → JavaScript(replaceState)', r.legacy);
await user.goto(`${BASE}/rules?language=python&tool=eslint`, { waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.conflict = { search: s.search, selected: s.selected, tools: s.tools.join() };
check(s.search === '?language=python' && s.selected === 'python' && s.tools.join() === 'ruff', 'language가 tool보다 우선', r.conflict);
await user.goto(`${BASE}/rules?language=typescript`, { waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.invalid = { search: s.search, selected: s.selected };
check(s.search === '?language=python' && s.selected === 'python', '잘못된 language는 무시(마지막 탭)', r.invalid);

// ---- 6. 상세: 링크 탭·목록으로 ----
await user.goto(`${BASE}/rules?language=javascript&q=undef`, { waitUntil: 'networkidle0' });
await listReady(user);
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-rows] a.rule-key[href$="/rules/eslint/no-undef"]')]);
await detailReady(user);
const detailState = (p) => p.evaluate(() => ({
  path: location.pathname,
  current: [...document.querySelectorAll('.tabs__list .tab-link')].map((a) => [a.dataset.language, a.getAttribute('aria-current'), a.getAttribute('href')]),
  back: (() => { const u = new URL(document.querySelector('[data-back-to-list]').href); return u.pathname + u.search; })(),
  tabRole: document.querySelector('[role="tab"]') !== null,
}));
r.detail = await detailState(user);
check(r.detail.path === '/rules/eslint/no-undef' && r.detail.current.map((x) => x.map(String).join(' ')).join(' | ') === 'python null /rules?language=python | javascript true /rules?language=javascript'
  && r.detail.back === '/rules?language=javascript&q=undef' && !r.detail.tabRole, '상세: 주소 그대로·링크 탭·목록으로(탭 + 마지막 조건)', r.detail);
const dl = await layout(user);
check(dl.page === 0 && dl.tabRows === 1, '상세 탭 한 줄·가로 스크롤 0', dl);
await user.screenshot({ path: path.join(OUT, 'X08_detail_eslint.png') });
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-back-to-list]')]);
await listReady(user);
s = await listState(user);
check(s.search === '?language=javascript&q=undef' && s.q === 'undef' && s.selected === 'javascript', '목록으로 → 탭·조건 유지', s.search);
await user.goto(`${BASE}/rules/ruff/F401`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.detailRuff = await detailState(user);
check(r.detailRuff.current[0][1] === 'true' && r.detailRuff.current[1][1] === null && r.detailRuff.back === '/rules?language=python', 'ruff 상세: Python 표시·목록으로(저장 조건 없음 → 탭 기본)', r.detailRuff);
await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('.tabs__list .tab-link[data-language="javascript"]')]);
await listReady(user);
s = await listState(user);
check(s.search === '?language=javascript' && s.selected === 'javascript', '상세의 다른 탭 링크 → 그 언어 목록', s.search);
await user.goto(`${BASE}/rules/eslint/valid-jsdoc`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.missingDetail = { ...(await detailState(user)), notice: await user.evaluate(() => document.querySelector('[data-missing]')?.textContent.trim().slice(0, 40) ?? null) };
check(r.missingDetail.current[1][1] === 'true' && r.missingDetail.back === '/rules?language=javascript' /* 바로 전에 JavaScript 기본 목록을 열었음 */ && r.missingDetail.notice, '활성 버전에 없는 규칙: 같은 탭 표시', r.missingDetail);
await user.goto(`${BASE}/rules/syntax-error`, { waitUntil: 'networkidle0' });
await detailReady(user);
r.syntax = await detailState(user);
check(r.syntax.current.every((x) => x[1] === null), '문법 오류(특수 키): 선택된 탭 없음', r.syntax.current);

// ---- 7. 결함 표 규칙 링크 → 상세(JavaScript 표시) ----
const projects = await user.evaluate(async () => (await fetch('/api/v1/projects?size=200')).json().then((j) => j.data.map((p) => [p.id, p.language])));
const jsProject = projects.find((p) => p[1] === 'javascript')?.[0];
if (jsProject) {
  await user.goto(`${BASE}/projects/${jsProject}`, { waitUntil: 'networkidle0' });
  await user.waitForSelector('[data-rows] tr .defects-table__rule a', { timeout: 20000 });
  await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-rows] tr:first-child .defects-table__rule a')]);
  await detailReady(user);
  r.fromDefect = await detailState(user);
  check(r.fromDefect.path.startsWith('/rules/eslint/') && r.fromDefect.current[1][1] === 'true', '결함 표 규칙 링크 → 상세(JavaScript)', r.fromDefect);
} else {
  r.fromDefect = '볼 수 있는 JavaScript 프로젝트 없음(미확인)';
}

// ---- 8. 빠른 전환: 마지막 요청만 반영 ----
await user.goto(`${BASE}/rules?language=javascript`, { waitUntil: 'networkidle0' });
await listReady(user);
await user.setRequestInterception(true);
const slow = (q) => {
  if (q.url().includes('/api/v1/rules?') && q.url().includes('tool=ruff')) setTimeout(() => q.continue(), 1500);
  else q.continue();
};
user.on('request', slow);
await user.click('[data-rule-tabs] [data-language="python"]');
await sleep(100);
await user.click('[data-rule-tabs] [data-language="javascript"]');
await sleep(2500);
await listReady(user);
s = await listState(user);
r.rapid = { search: s.search, selected: s.selected, tools: s.tools.join(), basis: s.basis.slice(0, 12) };
check(s.search === '?language=javascript' && s.selected === 'javascript' && s.tools.join() === 'eslint' && s.basis.startsWith('기준: ESLint'), '빠른 전환: 마지막 탭만 반영(늦은 ruff 응답 버림)', r.rapid);
user.off('request', slow);

// ---- 8b. 적재 전 탭(응답 바꿔치기: ESLint 버전 없음, 목록 0건) — 탭 단위 판정 ----
const noEslint = async (q) => {
  if (q.url().includes('/api/v1/rules/meta')) {
    const m = structuredClone(meta);
    m.tools.eslint = { version: null, components: {}, categories: [], rules: 0, stale: false };
    await q.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: m }) });
  } else if (q.url().includes('/api/v1/rules?') && q.url().includes('tool=eslint')) {
    await q.respond({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [], page: { page: 1, size: 50, total: 0, total_pages: 0 } }) });
  } else q.continue();
};
user.on('request', noEslint);
await user.goto(`${BASE}/rules?language=javascript`, { waitUntil: 'networkidle0' });
await listReady(user);
s = await listState(user);
r.unloaded = { basis: s.basis, empty: s.empty, labels: s.labels };
check(s.basis === '기준: ESLint 규칙 데이터 없음(적재 전)' && /규칙 데이터가 아직 없습니다/.test(s.empty) && s.labels[1] === 'JavaScript/TypeScript · ESLint' && /\(\d/.test(s.labels[0]), '적재 전 탭 안내(탭 단위)', r.unloaded);
await clickTab(user, 'python', 'ruff');
s = await listState(user);
check(s.tools.join() === 'ruff' && s.empty === '' && s.basis.startsWith('기준: ruff'), '적재 전 탭 옆의 Python 탭은 정상', { basis: s.basis, empty: s.empty });
user.off('request', noEslint);
await user.setRequestInterception(false);

// ---- 9. admin ----
const admin = await session(adminLogin);
await admin.goto(`${BASE}/rules`, { waitUntil: 'networkidle0' });
await listReady(admin);
s = await listState(admin);
r.admin = { selected: s.selected, labels: s.labels, reload: await admin.$('[data-rules-reload]') !== null };
check(s.selected === 'python' && r.admin.reload && s.labels.length === 2, 'admin: 탭 + 다시 불러오기', r.admin);
await admin.setViewport({ width: 1024, height: 900 });
await sleep(150);
const al = await layout(admin);
check(al.scroll === 0 && al.page === 0 && al.tabRows === 1, 'admin 1024: 가로 스크롤 0·탭 한 줄', al);
await admin.screenshot({ path: path.join(OUT, 'X08_admin_1024.png') });

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
