// 분석 화면 브라우저 점검(P19, 헤드리스 Chrome): 프로젝트 요약·"분석" 탭과 상태별(queued, running, succeeded, failed, cancelled) 분석 상세.
// 상태 아이콘+텍스트, 대기 순번, 취소 버튼, 결과 요약(신규·해결 링크, 비교 대상), 실패 원인·조치, 설정 무시됨·경고 확인 안내를 읽고 캡처한다.
// CSP 위반·콘솔 오류를 모은다.
// 실행: PJG_LOGIN=dev1 PJG_PASSWORD=... PJG_ANALYSES='{"queued":12,"running":11,"succeeded":2,"failed":9,"cancelled":8,"ignored":14}' \
//       node tools/check_analysis_ui.mjs <기본 주소> <프로젝트 id>
//   캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P19_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const ids = JSON.parse(process.env.PJG_ANALYSES ?? '{}');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const problems = [];
const csp = [];
const result = {};
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900 });
await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
page.on('console', (m) => {
  if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
  else if (['error', 'warning'].includes(m.type()) && !/Failed to load resource/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
});
page.on('response', (r) => { if (r.status() >= 400) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('#login-id', process.env.PJG_LOGIN ?? 'dev1');
await page.type('#login-password', process.env.PJG_PASSWORD);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);

// 1. 프로젝트 요약 + 분석 탭(#analyses로 바로 열림)
await page.goto(`${BASE}/projects/${projectId}#analyses`, { waitUntil: 'networkidle0' });
await page.waitForSelector('[data-analyses] [data-rows] tr');
result.projectPage = await page.evaluate(() => ({
  selectedTab: document.querySelector('[role="tab"][aria-selected="true"]').textContent.trim(),
  summary: document.querySelector('.project-summary__defects h2')?.textContent.trim(),
  latest: document.querySelector('.project-summary__latest')?.textContent.replace(/\s+/g, ' ').trim(),
  rows: [...document.querySelectorAll('[data-analyses] [data-rows] tr')].map((tr) => [...tr.children].map((c) => c.textContent.replace(/\s+/g, ' ').trim())),
}));
await page.screenshot({ path: `${OUT}/P19_project_analyses.png`, fullPage: true });

// 2. 상태별 분석 상세
for (const [state, id] of Object.entries(ids)) {
  await page.goto(`${BASE}/analyses/${id}`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-loading]').hidden);
  result[state] = await page.evaluate(() => ({
    status: document.querySelector('[data-status-badge]').textContent.trim(),
    cancelVisible: !document.querySelector('[data-cancel]').hidden,
    notices: [...document.querySelectorAll('[data-notices] .notice')].map((n) => n.textContent.trim()),
    failure: document.querySelector('[data-failure]').hidden ? null : {
      title: document.querySelector('[data-failure-title]').textContent, cause: document.querySelector('[data-failure-cause]').textContent,
      action: document.querySelector('[data-failure-action]').textContent, code: document.querySelector('[data-failure-code]').textContent,
    },
    result: document.querySelector('[data-result]').hidden ? null : {
      baseline: document.querySelector('[data-baseline]').textContent,
      counts: [...document.querySelectorAll('[data-counts] li')].map((li) => `${li.dataset.count}=${li.querySelector('.result-counts__n').textContent}${li.querySelector('a') ? '(링크)' : ''}`),
      newLink: document.querySelector('[data-count="new"] a')?.getAttribute('href') ?? null,
    },
    info: [...document.querySelectorAll('[data-info] dt')].map((dt) => `${dt.textContent}: ${dt.nextElementSibling.textContent}`),
  }));
  await page.screenshot({ path: `${OUT}/P19_analysis_${state}.png`, fullPage: true });
}

// 3. 결과 요약의 "신규" 링크 → 결함 목록이 그 분석의 신규만
if (result.succeeded?.result?.newLink) {
  await page.goto(`${BASE}${result.succeeded.result.newLink.replace(/^https?:\/\/[^/]+/, '')}`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => !document.querySelector('[data-defects]')?.hasAttribute('aria-busy') && document.querySelector('[data-count]').textContent.includes('건'));
  result.newLink = await page.evaluate(() => ({
    count: document.querySelector('[data-count]').textContent,
    filter: document.querySelector('[data-analysis-filter-text]').textContent,
    rows: [...document.querySelectorAll('[data-defects] [data-rows] tr td:nth-child(5)')].map((td) => td.textContent.trim()),
  }));
  await page.screenshot({ path: `${OUT}/P19_new_defects_link.png` });
}
await browser.close();
console.log(JSON.stringify({ result, csp, problems }, null, 2));
process.exit(csp.length || problems.length ? 1 : 0);
