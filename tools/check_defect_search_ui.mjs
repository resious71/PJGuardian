// 결함 검색 영역·결함 표 열 너비 브라우저 점검(P21V, 헤드리스 Chrome). 격리 서버·DB(또는 개발 DB 사본)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_defect_search_ui.mjs <기본 주소> <프로젝트 id> [로그인]
// 확인:
//   - 정한 뒤 검색: 검색어·칩·규칙·정렬·Run 입력을 바꿔도 목록·요약 요청이 없고(네트워크 기록) 주소도 그대로, "조건이 변경되었습니다" 표시.
//     검색 버튼 → 요청 한 번(목록·요약 각 1), 주소에 적용 조건. 검색어 Enter → 검색. 선택 상자 Enter → 요청 없음.
//     쪽 이동·머리글 정렬 → 마지막 적용 조건으로 즉시 요청(고르던 검색어는 폼에 남고 요청에는 없음), 정렬 입력칸 동기화.
//     필터 지우기 → 즉시 적용. 뒤로 가기·새로 고침 → 주소의 조건이 폼에 복원.
//   - 배치(1920·1440·1280·1024px): 검색 버튼이 영역 맨 아래 오른쪽(오른쪽 끝 일치), Tab 순서 마지막(필터 지우기 다음),
//     규칙·담당자 상자 크기 일치, 규칙·담당자·정렬·순서 라벨과 입력칸 위쪽 일치, 페이지 가로 스크롤 없음
//   - 결함 표(P21B 7절이 P21V를 대체): 메시지·파일:줄 열 너비 일치(1px), 규칙 열 > 짧은 값 열, 머리글 한 줄, 표 상자 가로 스크롤 0,
//     잘린 파일:줄 행 수는 측정만(보고서), 잘린 파일:줄에 마우스를 올리면 전체 경로 툴팁
//   - 칩(P21B 6절): 심각도·상태 칩의 글자·화면 읽기용 이름에 숫자·괄호 없음
//   - 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21B_search_*.png(P21V 캡처 이름을 바꿈)
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, login = 'admin'] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};
const page = await (await browser.createBrowserContext()).newPage();
await page.setViewport({ width: 1280, height: 1100 });
await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
page.on('console', (m) => {
  if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
  else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
});
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
const requests = [];
page.on('request', (req) => {
  const u = new URL(req.url());
  if (u.pathname === `/api/v1/projects/${projectId}/defects` || u.pathname === `/api/v1/projects/${projectId}/defects/summary`) requests.push({ kind: u.pathname.endsWith('summary') ? 'summary' : 'list', q: Object.fromEntries(u.searchParams) });
});
const check = (ok, label, detail) => { if (!ok) problems.push(`${label}: ${JSON.stringify(detail)}`); };
const idle = () => page.waitForNetworkIdle({ idleTime: 300, timeout: 20000 });
const url = () => new URL(page.url()).search;
const ready = () => page.waitForFunction(() => document.querySelector('[data-rows] tr') && !document.querySelector('[data-defects]').hasAttribute('aria-busy'), { timeout: 20000 });
const dirtyText = () => page.$eval('[data-search-dirty]', (n) => n.textContent);

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('#login-id', login);
await page.type('#login-password', process.env.PJG_PASSWORD);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);

// ---- 배치·열 너비(1280·1024) ----
const layout = () => page.evaluate(() => {
  const box = (s) => document.querySelector(s).getBoundingClientRect();
  const form = document.querySelector('[data-filter-form]');
  const fs = getComputedStyle(form);
  const formInnerRight = form.getBoundingClientRect().right - parseFloat(fs.paddingRight) - parseFloat(fs.borderRightWidth);
  const btn = box('[data-search-submit]');
  // Tab 순서 = DOM 순서(tabindex 양수 없음). 필터가 없으면 '필터 지우기'는 비활성이라 건너뛰므로 비활성 여부와 관계없이 순서를 본다
  const focusables = [...form.querySelectorAll('input, select, button, [tabindex]')].filter((n) => n.offsetParent && n.tabIndex >= 0);
  const label = (id) => document.querySelector(`label[for="${id}"]`).getBoundingClientRect();
  const th = (sel) => document.querySelector(`table.defects-table thead th${sel}`).getBoundingClientRect().width;
  const ths = [...document.querySelectorAll('table.defects-table thead th')].filter((n) => !n.hidden);
  const tails = [...document.querySelectorAll('[data-rows] .defects-table__location .clip__tail')];
  return {
    searchRightGap: Math.round((formInnerRight - btn.right) * 10) / 10,
    searchIsBottom: btn.bottom >= Math.max(...[...form.querySelectorAll('.field, fieldset')].map((n) => n.getBoundingClientRect().bottom)),
    lastFocusables: focusables.slice(-2).map((n) => n.textContent.trim() || n.id),
    positiveTabindex: [...document.querySelectorAll('[tabindex]')].filter((n) => n.tabIndex > 0).length,
    tableScroll: (() => { const b = document.querySelector('.table-scroll:has(.defects-table)'); return b.scrollWidth - b.clientWidth; })(),
    rule: [box('#df-rule').width, box('#df-rule').height].map(Math.round),
    assignee: [box('#df-assignee').width, box('#df-assignee').height].map(Math.round),
    labelTops: ['df-rule', 'df-assignee', 'df-sort', 'df-order'].map((id) => Math.round(label(id).top)),
    inputTops: ['#df-rule', '#df-assignee', '#df-sort', '#df-order'].map((s) => Math.round(box(s).top)),
    pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    location: Math.round(th('.th-sortable[data-sort-col="file"]') * 10) / 10,
    message: Math.round(ths[ths.findIndex((n) => n.dataset.sortCol === 'file') + 1].getBoundingClientRect().width * 10) / 10,
    rule_col: Math.round(th('[data-sort-col="rule"]')),
    // P21B 7절: 짧은 값 열(체크·심각도·상태·담당자·Run·최근 감지) 중 가장 넓은 것
    short_max: Math.round(Math.max(...ths.filter((n) => !/col-(rule|location|message)/.test(n.className)).map((n) => n.getBoundingClientRect().width))),
    headWrapped: ths.filter((n) => n.getBoundingClientRect().height > 48).map((n) => n.textContent.trim()),
    tailsCut: tails.filter((n) => n.scrollWidth > n.clientWidth + 1).length,
    tails: tails.length,
  };
});
for (const width of [1920, 1440, 1280, 1024]) {
  await page.setViewport({ width, height: 1100 });
  await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
  await ready();
  const L = await layout();
  r[`layout@${width}`] = L;
  check(Math.abs(L.searchRightGap) <= 1 && L.searchIsBottom, `${width} 검색 버튼 맨 아래 오른쪽`, L);
  check(L.lastFocusables.length === 2 && L.lastFocusables[1].includes('검색') && L.lastFocusables[0].includes('필터 지우기') && L.positiveTabindex === 0, `${width} Tab 순서 마지막 = 필터 지우기, 검색`, L.lastFocusables);
  check(Math.abs(L.rule[0] - L.assignee[0]) <= 1 && Math.abs(L.rule[1] - L.assignee[1]) <= 1, `${width} 규칙·담당자 크기`, [L.rule, L.assignee]);
  check(new Set(L.labelTops).size === 1 && new Set(L.inputTops).size === 1, `${width} 라벨·입력칸 일직선`, [L.labelTops, L.inputTops]);
  check(L.pageOverflow === 0, `${width} 페이지 가로 스크롤`, L.pageOverflow);
  check(Math.abs(L.location - L.message) <= 1, `${width} 메시지 = 파일:줄 너비`, [L.location, L.message]);
  // P21B 7절(사용자 요청이 P21V보다 우선): 규칙 열 > 180px 대신 "짧은 값 열보다 넓음", 표 상자 가로 스크롤 0
  check(L.rule_col > L.short_max, `${width} 규칙 열 > 짧은 값 열`, [L.rule_col, L.short_max]);
  check(L.tableScroll === 0, `${width} 표 상자 가로 스크롤 없음`, L.tableScroll);
  check(L.headWrapped.length === 0, `${width} 머리글 한 줄`, L.headWrapped);
  // P21B 7절: 파일명:줄 "항상 보임"(P21V) 대신 스크롤바 제거가 우선 — 잘린 행 수는 측정만 하고(보고서), 잘린 값은 툴팁으로 확인(아래)
  r[`tailsCut@${width}`] = `${L.tailsCut}/${L.tails}`;
  await (await page.$('[data-filter-form]')).screenshot({ path: path.join(OUT, `P21B_search_${width}.png`) });
  // 표 상자 캡처(위쪽 일부만: 캡처 동안만 높이를 줄였다가 되돌림)
  const tableBox = await page.$('.table-scroll:has(.defects-table)');
  await tableBox.evaluate((n) => { n.dataset.shot = n.style.cssText; n.style.maxHeight = '480px'; n.style.overflowY = 'hidden'; });
  await tableBox.screenshot({ path: path.join(OUT, `P21B_search_table_${width}.png`) });
  await tableBox.evaluate((n) => { n.style.cssText = n.dataset.shot; delete n.dataset.shot; });
}
await page.setViewport({ width: 1024, height: 1100 });
// P21B 7절: 잘린 파일:줄 → 전체 경로 툴팁(1024px, 폴더 쪽부터 줄어듦)
{
  const cut = await page.evaluateHandle(() => [...document.querySelectorAll('[data-rows] .defects-table__location .clip')]
    .find((n) => { const h = n.querySelector('.clip__head'); const t = n.querySelector('.clip__tail'); return (h && h.scrollWidth > h.clientWidth + 1) || t.scrollWidth > t.clientWidth + 1; }) ?? null);
  if (await cut.evaluate((n) => n !== null)) {
    await cut.hover();
    await page.waitForSelector('#pjg-clip-tip:not([hidden])', { timeout: 5000 });
    r.pathTip = await page.evaluate((n) => ({ tip: document.querySelector('#pjg-clip-tip').textContent, full: n.textContent }), cut);
    check(r.pathTip.tip === r.pathTip.full && r.pathTip.tip.includes('/'), '잘린 파일:줄 → 전체 경로 툴팁', r.pathTip);
  } else {
    r.pathTip = '1024px에 잘린 파일:줄 없음(측정만)';
  }
  await page.mouse.move(0, 0);
}
// P21B 6절: 칩은 라벨만
r.chips = await page.evaluate(() => [...document.querySelectorAll('[data-facet] .chip')].map((c) => ({ text: c.textContent.trim(), aria: c.getAttribute('aria-label') })));
check(r.chips.length === 11 && r.chips.every((c) => !/[0-9()]/.test(c.text) && (c.aria === null || !/[0-9()]/.test(c.aria))), '칩에 건수(숫자·괄호) 없음', r.chips);
await page.setViewport({ width: 1280, height: 1100 });

// ---- 정한 뒤 검색 ----
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await ready();
const before = url();
requests.length = 0;
await page.type('#df-q', 'is not defined');
await page.click('[data-facet="severity"] .chip[data-value="high"]');
await page.click('[data-facet="status"] .chip[data-value="open"]');
await page.$eval('#df-rule', (s) => { s.options[0].selected = true; s.dispatchEvent(new Event('change', { bubbles: true })); });
await page.select('#df-sort', 'rule');
await page.type('#df-from-run', '1');
await new Promise((res) => setTimeout(res, 600));
r.beforeSearch = { requests: requests.length, url: url(), dirty: await dirtyText(), chipPressed: await page.$eval('[data-facet="severity"] .chip[data-value="high"]', (n) => n.getAttribute('aria-pressed')) };
check(r.beforeSearch.requests === 0 && r.beforeSearch.url === before && r.beforeSearch.dirty === '조건이 변경되었습니다' && r.beforeSearch.chipPressed === 'true', '입력만으로는 요청 없음 + 변경 표시', r.beforeSearch);
await (await page.$('[data-filter-form]')).screenshot({ path: path.join(OUT, 'P21B_search_dirty.png') });
await page.click('[data-search-submit]');
await idle();
r.afterSearch = { list: requests.filter((x) => x.kind === 'list').length, summary: requests.filter((x) => x.kind === 'summary').length, url: url(), dirty: await dirtyText(), q: requests[0]?.q };
const p = new URLSearchParams(r.afterSearch.url);
check(r.afterSearch.list === 1 && r.afterSearch.summary === 1 && r.afterSearch.dirty === '', '검색 = 목록·요약 각 한 번, 표시 꺼짐', r.afterSearch);
check(p.get('q') === 'is not defined' && p.get('severity') === 'high' && p.get('status') === 'open' && p.get('rule') && p.get('sort') === 'rule' && p.get('from_run') === '1', '주소에 적용 조건', r.afterSearch.url);
// 쪽 이동·머리글 정렬: 마지막 적용 조건으로 즉시, 고르던 검색어는 남김
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await ready();
await page.waitForSelector('[data-pagination] button, [data-pagination] a', { timeout: 10000 });
await page.type('#df-q', '아직검색안함');
requests.length = 0;
const pageBtn = await page.$('[data-pagination] [data-page="2"], [data-pagination] button:not([disabled])[aria-label*="2"]');
await (pageBtn ?? (await page.$$('[data-pagination] button:not([disabled])')).at(-1)).click();
await idle();
r.paging = { list: requests.filter((x) => x.kind === 'list').length, page: requests.find((x) => x.kind === 'list')?.q.page, q: requests.find((x) => x.kind === 'list')?.q.q ?? null, form: await page.$eval('#df-q', (n) => n.value), dirty: await dirtyText() };
check(r.paging.list === 1 && r.paging.page !== '1' && r.paging.q === null && r.paging.form === '아직검색안함' && r.paging.dirty !== '', '쪽 이동: 즉시, 적용 조건만, 입력은 남음', r.paging);
requests.length = 0;
await page.click('th[data-sort-col="run"] button');
await idle();
r.headerSort = { list: requests.filter((x) => x.kind === 'list').length, sort: requests.find((x) => x.kind === 'list')?.q.sort, q: requests.find((x) => x.kind === 'list')?.q.q ?? null, select: await page.$eval('#df-sort', (s) => s.value), form: await page.$eval('#df-q', (n) => n.value) };
check(r.headerSort.list === 1 && r.headerSort.sort === 'run' && r.headerSort.q === null && r.headerSort.select === 'run' && r.headerSort.form === '아직검색안함', '머리글 정렬: 즉시, 정렬 입력칸 동기화', r.headerSort);
// Enter: 검색어 입력칸은 검색, 선택 상자는 요청 없음
requests.length = 0;
await page.focus('#df-sort');
await page.keyboard.press('Enter');
await new Promise((res) => setTimeout(res, 500));
r.enterSelect = requests.length;
await page.focus('#df-q');
await page.keyboard.press('Enter');
await idle();
r.enterQ = { list: requests.filter((x) => x.kind === 'list').length, q: new URLSearchParams(url()).get('q') };
check(r.enterSelect === 0 && r.enterQ.list === 1 && r.enterQ.q === '아직검색안함', 'Enter: 선택 상자는 요청 없음, 검색어는 검색', [r.enterSelect, r.enterQ]);
// 뒤로 가기·새로 고침
const applied = url();
await page.goBack({ waitUntil: 'networkidle0' });
await ready();
r.back = { url: url(), form: await page.$eval('#df-q', (n) => n.value), dirty: await dirtyText() };
check(r.back.url !== applied && r.back.form === (new URLSearchParams(r.back.url).get('q') ?? '') && r.back.dirty === '', '뒤로 가기: 이전 적용 조건 복원', r.back);
await page.goForward({ waitUntil: 'networkidle0' });
await page.reload({ waitUntil: 'networkidle0' });
await ready();
r.reload = { form: await page.$eval('#df-q', (n) => n.value), sort: await page.$eval('#df-sort', (s) => s.value) };
check(r.reload.form === '아직검색안함' && r.reload.sort === 'run', '새로 고침: 주소의 조건이 폼에', r.reload);
// 필터 지우기: 즉시
requests.length = 0;
await page.click('[data-filter-form] [data-clear-filters]');
await idle();
r.clear = { list: requests.filter((x) => x.kind === 'list').length, url: url(), q: await page.$eval('#df-q', (n) => n.value) };
check(r.clear.list === 1 && !new URLSearchParams(r.clear.url).get('q') && r.clear.q === '', '필터 지우기: 즉시 적용', r.clear);
// 대시보드 → 결함 목록 링크(규칙 필터)가 이전처럼 적용됨
await page.goto(`${BASE}/projects/${projectId}?rule=eslint:no-undef`, { waitUntil: 'networkidle0' });
await ready();
r.link = await page.$eval('#df-rule', (s) => [...s.selectedOptions].map((o) => o.value));
check(r.link.includes('eslint:no-undef'), '링크의 조건이 적용되고 폼에 채워짐', r.link);

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? `: ${csp.join(' | ')}` : ''}`);
console.log(`문제 ${problems.length}건${problems.length ? `:\n- ${problems.join('\n- ')}` : ''}`);
await browser.close();
process.exit(csp.length || problems.length ? 1 : 0);
