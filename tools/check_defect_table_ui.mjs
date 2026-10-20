// 결함 표·표 머리글·대시보드 차트 모양 브라우저 점검(P21U, 헤드리스 Chrome). 격리 서버·DB(또는 개발 DB 사본)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_defect_table_ui.mjs <기본 주소> <프로젝트 id> <과거 Run 번호> [로그인(내 결함이 있는 사용자)] [관리자 로그인]
// 확인(1920·1440·1280·1024px):
//   - 결함 표(프로젝트 결함 탭 = 결함 메뉴, 내 결함): 열 머리글이 모두 한 줄(높이 = 한 줄), 정렬 표시(▲▼)가 머리글 글자와 같은 줄이고 칸 안에 보임,
//     페이지·표 상자 모두 가로 스크롤 없음(P21B 7절, P21V의 "표 상자만 스크롤"을 대체), 머리글과 첫 행 셀의 왼쪽 끝 일치,
//     "파일:줄"·"메시지"가 잘린 행 비율(측정값, 보고서용)과 파일:줄은 끝(파일명:줄)이 보이는지
//   - Run 열: "Run N" → /projects/{id}/history?analysis=<id>, 최근 감지 yy-mm-dd, 마우스·포커스 툴팁에 전체 일시, title 없음
//   - Run 머리글 버튼으로 정렬(주소 sort=run, aria-sort, 다시 누르면 내림차순, 행 순서가 Run 번호 순)
//   - 과거 Run 보기: Run 열이 그 Run 이하, 한정된 행의 툴팁 안내
//   - 다른 표(히스토리 이력, 대시보드 표 모드, 관리자 사용자 표)의 머리글도 한 줄
//   - 대시보드: 추이 범례 표식 ≤ 글자 크기, 상위 10 막대 색 = 토큰(--chart-bar, --chart-bar-border)
//   - 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21U_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, pastRun, login = 'admin', adminLogin = 'admin'] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};

async function session(user) {
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
  await page.type('#login-id', user);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}

/** 보이는 표들의 머리글: 한 줄인지(높이가 한 줄 높이의 1.5배 미만), 정렬 표시가 글자와 같은 줄인지, 머리글·첫 행 왼쪽 끝 일치 */
const headCheck = () => (s) => {
  const tables = [...document.querySelectorAll(s)].filter((t) => !t.hidden && t.offsetParent);
  return tables.map((t) => {
    const ths = [...t.querySelectorAll('thead th')].filter((th) => !th.hidden);
    const wrapped = [];
    for (const th of ths) {
      const range = document.createRange();
      range.selectNodeContents(th);
      const lines = new Set([...range.getClientRects()].filter((x) => x.width > 0).map((x) => Math.round(x.top + x.height / 2)));
      const mark = th.querySelector('[data-sort-mark]');
      const label = th.querySelector('.th-sort');
      // P21B: 정렬 표시는 칸 안에 보여야 한다(글자만 …으로 줄어듦)
      if (mark && mark.textContent && mark.getBoundingClientRect().right > th.getBoundingClientRect().right + 1) wrapped.push(`${th.textContent.trim()}(정렬 표시 잘림)`);
      const sameLine = !mark || !mark.textContent || Math.abs(mark.getBoundingClientRect().top - label.getBoundingClientRect().top) < 4
        || Math.abs((mark.getBoundingClientRect().top + mark.getBoundingClientRect().height / 2) - (label.getBoundingClientRect().top + label.getBoundingClientRect().height / 2)) < 4;
      // 글자 줄이 여러 개(세로 위치가 한 줄 높이 이상 차이)면 줄바꿈
      const tops = [...lines].sort((a, b) => a - b);
      const lh = parseFloat(getComputedStyle(th).fontSize) * 1.2;
      if ((tops.length && tops[tops.length - 1] - tops[0] > lh * 0.8) || !sameLine) wrapped.push(th.textContent.trim());
    }
    const row = t.querySelector('tbody tr');
    const misaligned = [];
    if (row) {
      const tds = [...row.children].filter((c) => !c.hidden);
      ths.forEach((th, i) => {
        if (tds[i] && Math.abs(th.getBoundingClientRect().left - tds[i].getBoundingClientRect().left) > 1) misaligned.push(th.textContent.trim());
      });
    }
    const box = t.closest('.table-scroll');
    return { cols: ths.length, wrapped, misaligned, overflow: box ? box.scrollWidth - box.clientWidth : null };
  });
};
const pageOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const waitRows = (page) => page.waitForFunction(() => document.querySelector('[data-rows] tr, table.defects-table tbody tr'), { timeout: 20000 });

/** P21B: 잘린 행 비율(파일:줄·메시지), 파일:줄 끝(파일명:줄)이 칸 안에 보이지 않는 행, 줄어든 머리글 */
const clipStats = () => {
  const over = (n) => n && n.scrollWidth > n.clientWidth + 1;
  const rows = [...document.querySelectorAll('table.defects-table tbody tr')];
  let loc = 0; let msg = 0; let tailHidden = 0;
  for (const tr of rows) {
    const l = tr.querySelector('.defects-table__location .clip');
    if (l && (over(l.querySelector('.clip__head')) || over(l.querySelector('.clip__tail')))) loc += 1;
    if (l && over(l.querySelector('.clip__tail'))) tailHidden += 1;
    if (over(tr.querySelector('.defects-table__message .clip'))) msg += 1;
  }
  const heads = [...document.querySelectorAll('table.defects-table thead .th-label')].filter((n) => over(n)).map((n) => n.textContent);
  return { rows: rows.length, locClipped: loc, msgClipped: msg, fileNameClipped: tailHidden, headClipped: heads };
};

const user = await session(login);
// ---- 결함 표: 1920·1440·1280·1024 ----
r.clip = {};
for (const width of [1920, 1440, 1280, 1024]) {
  await user.setViewport({ width, height: 900 });
  for (const [name, url] of [['project', `${BASE}/projects/${projectId}`], ['mine', `${BASE}/my-defects`]]) {
    await user.goto(url, { waitUntil: 'networkidle0' });
    await waitRows(user);
    const heads = await user.evaluate(headCheck(), 'table.defects-table');
    const scroll = await pageOverflow(user);
    // 표 상자를 끝까지 스크롤해도 머리글·열이 어긋나지 않는지
    await user.evaluate(() => { const b = document.querySelector('.table-scroll:has(.defects-table)'); b.scrollLeft = b.scrollWidth; });
    const scrolled = await user.evaluate(headCheck(), 'table.defects-table');
    await user.evaluate(() => { document.querySelector('.table-scroll:has(.defects-table)').scrollLeft = 0; });
    r[`${name}@${width}`] = { heads, scrolled: scrolled.map((h) => h.misaligned), pageOverflow: scroll };
    r.clip[`${name}@${width}`] = await user.evaluate(clipStats);
    const ok = heads.length === 1 && heads[0].wrapped.length === 0 && heads[0].misaligned.length === 0 && scrolled[0].misaligned.length === 0 && scroll === 0
      && heads[0].overflow === 0; // P21B: 표 상자 가로 스크롤도 없음
    if (!ok) problems.push(`${name}@${width} 머리글/스크롤: ${JSON.stringify(r[`${name}@${width}`])}`);
    const box = await user.$('.table-scroll:has(.defects-table)');
    const bb = await box.boundingBox();
    await user.screenshot({ path: path.join(OUT, `P21B_table_${name}_${width}.png`), clip: { x: 0, y: Math.max(0, bb.y - 60), width, height: Math.min(560, bb.height + 60) } });
  }
}
await user.setViewport({ width: 1280, height: 900 });

// ---- Run·최근 감지 열 값, 툴팁, 정렬 ----
await user.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await waitRows(user);
r.cells = await user.evaluate(() => [...document.querySelectorAll('[data-rows] tr')].slice(0, 3).map((tr) => {
  const run = tr.querySelector('.defects-table__run a, .defects-table__run span');
  const seen = tr.querySelector('.defects-table__seen span');
  return { run: run.textContent, href: run.getAttribute('href'), date: seen.textContent, titleAttrs: tr.querySelectorAll('[title]').length };
}));
for (const c of r.cells) {
  if (!/^Run \d+$/.test(c.run) || !/\/projects\/\d+\/history\?analysis=\d+$/.test(c.href ?? '')) problems.push(`Run 칸: ${JSON.stringify(c)}`);
  if (!/^\d{2}-\d{2}-\d{2}$/.test(c.date)) problems.push(`최근 감지 형식: ${c.date}`);
}
// 마우스 툴팁: 전체 일시
await user.hover('[data-rows] tr .defects-table__seen span');
await user.waitForSelector('#pjg-clip-tip:not([hidden])', { timeout: 5000 });
r.dateTip = await user.$eval('#pjg-clip-tip', (n) => n.textContent);
if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} · /.test(r.dateTip)) problems.push(`날짜 툴팁: ${r.dateTip}`);
// 키보드 포커스 툴팁(Run 링크)
await user.mouse.move(5, 5);
await user.focus('[data-rows] tr .defects-table__run a');
r.runTip = await user.$eval('#pjg-clip-tip', (n) => (n.hidden ? '' : n.textContent));
if (!r.runTip.includes('히스토리')) problems.push(`Run 툴팁(포커스): ${r.runTip}`);
await user.keyboard.press('Escape');
// Run 링크 → 히스토리 비교 패널
const runHref = await user.$eval('[data-rows] tr .defects-table__run a', (a) => a.href);
// 머리글 버튼 정렬
const runOrder = () => user.$$eval('[data-rows] tr .defects-table__run', (ns) => ns.map((n) => Number((n.textContent.match(/\d+/) ?? [0])[0])));
await user.click('th[data-sort-col="run"] button');
await user.waitForFunction(() => new URL(location.href).searchParams.get('sort') === 'run' && document.querySelector('th[data-sort-col="run"]').getAttribute('aria-sort') === 'ascending', { timeout: 10000 });
await user.waitForNetworkIdle();
const asc = await runOrder();
await user.click('th[data-sort-col="run"] button');
await user.waitForFunction(() => new URL(location.href).searchParams.get('order') === 'desc' && document.querySelector('th[data-sort-col="run"]').getAttribute('aria-sort') === 'descending', { timeout: 10000 });
await user.waitForNetworkIdle();
const desc = await runOrder();
r.sort = { asc: asc.slice(0, 5), desc: desc.slice(0, 5), mark: await user.$eval('th[data-sort-col="run"] [data-sort-mark]', (n) => n.textContent), select: await user.$eval('#df-sort', (s) => s.value) };
const sorted = (a, dir) => a.every((v, i) => i === 0 || (dir > 0 ? a[i - 1] <= v : a[i - 1] >= v));
if (!sorted(asc, 1) || !sorted(desc, -1) || r.sort.mark !== '▼' || r.sort.select !== 'run') problems.push(`Run 정렬: ${JSON.stringify(r.sort)}`);
await user.goto(runHref, { waitUntil: 'networkidle0' });
r.runLink = new URL(user.url()).pathname + new URL(user.url()).search;
await user.waitForFunction(() => !document.querySelector('[data-history-panel]')?.hidden, { timeout: 20000 });
r.historyHeads = await user.evaluate(headCheck(), '.table');

// ---- 과거 Run 보기: 한정 ----
await user.goto(`${BASE}/projects/${projectId}?run=${pastRun}&sort=run&order=desc`, { waitUntil: 'networkidle0' });
await waitRows(user);
const past = await user.evaluate(() => [...document.querySelectorAll('[data-rows] tr')].map((tr) => {
  const a = tr.querySelector('.defects-table__run a');
  return { run: a?.textContent, tip: a?.dataset.tip };
}));
for (const p of past) {
  if (Number(p.run?.replace('Run ', '')) > Number(pastRun)) problems.push(`과거 Run 한정: ${JSON.stringify(p)}`);
}
// 이 페이지(그 Run 이하 정렬의 첫 페이지)에 한정된 행(실제 마지막 감지가 더 뒤)이 있으면 그 툴팁에 안내
r.past = { rows: past.length, first: past[0], capped: past.filter((p) => p.tip?.includes(`Run ${pastRun} 이하`)).length };
if (!r.past.capped) problems.push(`과거 Run 한정 안내 툴팁 없음: ${JSON.stringify(r.past)}`);
// 한정된 행의 Run 툴팁을 띄운 채 표 위쪽을 캡처
const cappedIdx = past.findIndex((p) => p.tip?.includes(`Run ${pastRun} 이하`));
if (cappedIdx >= 0) {
  const link = (await user.$$('[data-rows] tr .defects-table__run a'))[cappedIdx];
  await link.scrollIntoView();
  await link.hover();
  await user.waitForSelector('#pjg-clip-tip:not([hidden])', { timeout: 5000 });
  await user.screenshot({ path: path.join(OUT, 'P21U_past_run.png') }); // 보이는 화면(툴팁 포함)
}

// ---- 대시보드: 범례·막대 색, 표 모드 머리글 ----
await user.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
await user.waitForFunction(() => document.querySelector('[data-section="trend"] canvas') && document.querySelector('[data-section="top-rules"] canvas'), { timeout: 20000 });
await new Promise((res) => setTimeout(res, 800));
r.charts = await user.evaluate(async () => {
  const { Chart } = await import(document.querySelector('meta[name="icon-sprite"]').content.replace(/icons\/sprite\.svg.*$/, 'vendor/chartjs/chart.js'));
  const trend = Chart.getChart(document.querySelector('[data-section="trend"] canvas'));
  const bars = Chart.getChart(document.querySelector('[data-section="top-rules"] canvas'));
  const files = Chart.getChart(document.querySelector('[data-section="top-files"] canvas'));
  const lab = trend.legend.options.labels;
  const css = getComputedStyle(document.documentElement);
  const v = (n) => css.getPropertyValue(n).trim();
  return {
    legend: { boxHeight: lab.boxHeight, mark: lab.boxHeight * Math.SQRT2, font: lab.font.size, padding: lab.padding, items: trend.legend.legendItems.map((i) => i.lineWidth) },
    bars: [bars, files].map((c) => ({ fill: c.data.datasets[0].backgroundColor, border: c.data.datasets[0].borderColor })),
    tokens: { fill: v('--chart-bar'), border: v('--chart-bar-border') },
  };
});
const L = r.charts.legend;
if (!(L.mark <= L.font) || L.items.some((w) => w > 1)) problems.push(`범례 표식: ${JSON.stringify(L)}`);
for (const b of r.charts.bars) if (b.fill !== r.charts.tokens.fill || b.border !== r.charts.tokens.border) problems.push(`막대 색: ${JSON.stringify(b)}`);
await (await user.$('[data-section="trend"]')).screenshot({ path: path.join(OUT, 'P21U_dashboard_legend.png') });
const grid = await user.$('[data-section="top-rules"]');
const gb = await grid.boundingBox();
const fb = await (await user.$('[data-section="top-files"]')).boundingBox();
await user.screenshot({ path: path.join(OUT, 'P21U_dashboard_bars.png'), clip: { x: gb.x, y: gb.y, width: fb.x + fb.width - gb.x, height: Math.max(gb.height, fb.height) } });
for (const b of await user.$$('[data-toggle-table]')) await b.click();
await new Promise((res) => setTimeout(res, 300));
r.dashboardHeads = await user.evaluate(headCheck(), '.table');

// ---- 관리자 표 ----
const admin = await session(adminLogin);
await admin.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
r.adminHeads = await admin.evaluate(headCheck(), '.table');

for (const [k, list] of [['history', r.historyHeads], ['dashboard', r.dashboardHeads], ['admin', r.adminHeads]]) {
  const bad = list.flatMap((h) => h.wrapped);
  if (!list.length || bad.length) problems.push(`${k} 머리글 줄바꿈: ${JSON.stringify(bad)} (표 ${list.length}개)`);
}
r.tables = { history: r.historyHeads.length, dashboard: r.dashboardHeads.length, admin: r.adminHeads.length };
delete r.historyHeads; delete r.dashboardHeads; delete r.adminHeads;

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? `: ${csp.join(' | ')}` : ''}`);
console.log(`문제 ${problems.length}건${problems.length ? `:\n- ${problems.join('\n- ')}` : ''}`);
await browser.close();
process.exit(csp.length || problems.length ? 1 : 0);
