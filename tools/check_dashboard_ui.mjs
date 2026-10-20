// 대시보드 브라우저 점검(P20, 헤드리스 Chrome). 격리 서버·DB(또는 개발 DB 사본)에서만 쓴다.
// 구역별 로드, 차트 렌더(캔버스), "표로 보기" 전환(키보드), 변경 표식, 막대 클릭 → 결함 목록 이동, 점 클릭 → 분석 상세, 범위 선택,
// 콘솔 오류 0, CSP 위반 0을 확인하고 캡처를 남긴다.
//   PJG_PASSWORD=... node tools/check_dashboard_ui.mjs <기본 주소> [로그인 ID…]
//   캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P20_<로그인>_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, ...logins] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const result = {};
const csp = [];
const problems = [];

for (const login of logins.length ? logins : ['admin']) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1366, height: 1000 });
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(`${login}: ${m.text()}`);
    else if (['error', 'warning'].includes(m.type())) problems.push(`${login} console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (r) => { if (r.status() >= 400) problems.push(`${login} HTTP ${r.status()}: ${r.url()}`); });
  page.on('pageerror', (e) => problems.push(`${login} pageerror: ${e.message}`));
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  result[login] = { landing: new URL(page.url()).pathname };
  await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => [...document.querySelectorAll('[data-section]')].every((s) => s.getAttribute('aria-busy') === 'false'), { timeout: 30000 });
  const read = () => page.evaluate(() => ({
    asOf: document.querySelector('[data-as-of]').textContent,
    empty: document.querySelector('[data-empty]').hidden ? null : document.querySelector('[data-empty] [data-empty]')?.dataset.empty,
    tiles: [...document.querySelectorAll('.stat-tile')].map((t) => t.textContent.replace(/\s+/g, ' ').trim()),
    sections: Object.fromEntries([...document.querySelectorAll('[data-section]')].map((s) => [s.dataset.section, {
      hidden: s.hidden, error: !s.querySelector('[data-error-box]').hidden,
      canvases: [...s.querySelectorAll('canvas')].filter((c) => c.offsetParent !== null && c.width > 0).length,
      aria: [...s.querySelectorAll('canvas')].map((c) => c.getAttribute('aria-label')).filter(Boolean).slice(0, 2),
      text: s.querySelector('[data-body]')?.textContent.replace(/\s+/g, ' ').trim().slice(0, 300),
    }])),
    options: [...document.querySelectorAll('#ds-trend-project option')].map((o) => o.textContent),
  }));
  result[login].dashboard = await read();
  await page.screenshot({ path: `${OUT}/P20_${login}_dashboard.png`, fullPage: true });

  if (!result[login].dashboard.empty) {
    // 표로 보기: 키보드(Tab으로 버튼 포커스 → Enter)
    const toggle = await page.$('[data-section="trend"] [data-toggle-table]');
    await toggle.focus();
    await page.keyboard.press('Enter');
    result[login].trendTable = await page.evaluate(() => {
      const s = document.querySelector('[data-section="trend"]');
      return { pressed: s.querySelector('[data-toggle-table]').getAttribute('aria-pressed'), tableVisible: [...s.querySelectorAll('[data-table-part]')].some((p) => !p.hidden), // P21: 표는 프로젝트별 제목 아래
        rows: [...s.querySelectorAll('[data-table-part] tbody tr')].slice(0, 3).map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()),
        chartHidden: [...s.querySelectorAll('.chart-box')].every((c) => c.hidden) };
    });
    await page.screenshot({ path: `${OUT}/P20_${login}_trend_table.png`, fullPage: false, clip: await (await page.$('[data-section="trend"]')).boundingBox() });
    await page.keyboard.press('Enter'); // 다시 차트

    // 프로젝트 하나로 추이(변경 표식 확인용) → 마지막 점 클릭 → 분석 상세
    const firstProject = await page.$eval('#ds-trend-project option:nth-child(2)', (o) => o.value).catch(() => null);
    if (firstProject) {
      await page.select('#ds-trend-project', firstProject);
      await page.waitForFunction(() => document.querySelector('[data-section="trend"]').getAttribute('aria-busy') === 'false');
      await new Promise((r) => setTimeout(r, 300));
      const box = await (await page.$('[data-section="trend"] canvas')).boundingBox();
      result[login].trendSingle = await page.evaluate(() => document.querySelector('[data-section="trend"] canvas')?.getAttribute('aria-label'));
      // 차트의 마지막 데이터 점을 찾아 누른다(Chart.js 인스턴스에서 좌표를 읽음). 화면과 같은 모듈이어야 하므로 버전 경로(P21)로 불러온다
      const pt = await page.evaluate(async () => {
        const { Chart } = await import(document.querySelector('meta[name="icon-sprite"]').content.replace(/icons\/sprite\.svg.*$/, 'vendor/chartjs/chart.js'));
        const c = Chart.getChart(document.querySelector('[data-section="trend"] canvas'));
        const meta = c.getDatasetMeta(0);
        const p = meta.data[meta.data.length - 1];
        const r = c.canvas.getBoundingClientRect();
        return { x: r.left + p.x, y: r.top + p.y };
      });
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.mouse.click(pt.x, pt.y)]);
      result[login].trendClick = new URL(page.url()).pathname;
      await page.goBack({ waitUntil: 'networkidle0' });
      await page.waitForFunction(() => [...document.querySelectorAll('[data-section]')].every((s) => s.getAttribute('aria-busy') === 'false'));
      void box;
    }
    // 규칙 막대 첫 막대 클릭 → 결함 목록(규칙 + 열린 상태)
    const bar = await page.evaluate(async () => {
      const { Chart } = await import(document.querySelector('meta[name="icon-sprite"]').content.replace(/icons\/sprite\.svg.*$/, 'vendor/chartjs/chart.js'));
      const canvas = document.querySelector('[data-section="top-rules"] canvas');
      const c = Chart.getChart(canvas);
      if (!c) return null;
      const el = c.getDatasetMeta(0).data[0];
      canvas.scrollIntoView();
      const r = canvas.getBoundingClientRect();
      return { x: r.left + (el.x + el.base) / 2, y: r.top + el.y };
    });
    if (bar) {
      await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.mouse.click(bar.x, bar.y)]);
      const u = new URL(page.url());
      result[login].barClick = `${u.pathname}${u.search}`;
      await page.waitForFunction(() => document.querySelector('[data-count]')?.textContent.includes('건'), { timeout: 15000 }).catch(() => {});
      result[login].barClickCount = await page.evaluate(() => document.querySelector('[data-count]')?.textContent.trim() ?? null);
      await page.goBack({ waitUntil: 'networkidle0' });
    }
  }
  await context.close();
}
await browser.close();
result.csp = csp;
result.problems = problems;
console.log(JSON.stringify(result, null, 2));
