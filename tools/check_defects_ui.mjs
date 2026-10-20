// 결함 목록·상세·코드 뷰어 브라우저 점검(P17, 헤드리스 Chrome). CSP 위반·콘솔 오류 수집, 코드 안 악성 문자열 실행 여부, 화면 캡처.
// 흐름: kim 로그인 → 프로젝트 결함 탭(목록) → 필터·검색(URL 반영, 새로고침 유지) → 필터 결과 없음 → 필터 지우기
//       → 상세(코드 하이라이트·스크롤) → 악성 문자열이 든 코드(실행되지 않음) → 전체 파일 → 발췌본 → TS 하이라이팅 → park은 404
// 준비: puppeteer-core(data/work/puppeteer, tools/check_styleguide.mjs 머리말), 점검 서버(격리: 별도 포트·DB, docs/results/P17.md 6절)
// 실행: PJG_LOGIN=kim PJG_PASSWORD=... PJG_OTHER_LOGIN=park node tools/check_defects_ui.mjs <기본 주소> <프로젝트 id> <악성 문자열 결함 id> <발췌 결함 id> <TS 결함 id>
//   비밀번호는 환경 변수로만 받는다. 캡처 위치는 PJG_SHOT_DIR(기본 docs/results), 파일 이름 P17_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, xssId, excerptId, tsId] = process.argv;
const { base: BASE, chromeArgs, rel } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const LOGIN = process.env.PJG_LOGIN ?? 'kim';
const PW = process.env.PJG_PASSWORD;
const OTHER = process.env.PJG_OTHER_LOGIN ?? 'park';
if (!PW || !projectId || !xssId || !excerptId || !tsId) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_defects_ui.mjs <기본 주소> <프로젝트 id> <악성 결함 id> <발췌 결함 id> <TS 결함 id>');
  process.exit(1);
}
const browser = await puppeteer.launch({
  executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  headless: true,
  args: chromeArgs,
});
const problems = [];
const csp = [];
const result = {};

async function newPage(context, allow404 = false) {
  const page = await context.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  // CSP 위반은 이벤트로 모은다(콘솔 문구보다 정확)
  await page.evaluateOnNewDocument(() => {
    window.__csp = [];
    document.addEventListener('securitypolicyviolation', (e) => window.__csp.push(`${e.violatedDirective} ${e.blockedURI || ''} ${e.sourceFile || ''}:${e.lineNumber || ''}`));
  });
  page.on('console', (m) => {
    if (/Content Security Policy/i.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/Failed to load resource/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (r) => { if (r.status() >= 400 && !(allow404 && r.status() === 404)) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  return page;
}

async function login(page, id, pw) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', id);
  await page.type('#login-password', pw);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
}

async function collectCsp(page) {
  const list = await page.evaluate(() => window.__csp ?? []);
  csp.push(...list);
  return list.length;
}

const ctx = await browser.createBrowserContext();
const page = await newPage(ctx);
await login(page, LOGIN, PW);

// 1. 목록
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await page.waitForSelector('[data-rows] tr');
result.list = await page.evaluate(() => ({
  count: document.querySelector('[data-count]').textContent,
  rows: document.querySelectorAll('[data-rows] tr').length,
  chips: [...document.querySelectorAll('[data-facet] .chip')].map((c) => c.textContent.trim()),
  firstRow: [...document.querySelectorAll('[data-rows] tr:first-child td')].map((td) => td.textContent.trim()),
}));
await page.screenshot({ path: `${OUT}/P17_list.png`, fullPage: true });
// P21B 6절: 칩은 라벨만(숫자·괄호 없음, 화면 읽기용 이름도)
result.chipNames = await page.evaluate(() => [...document.querySelectorAll('[data-facet] .chip')].map((c) => c.getAttribute('aria-label') ?? c.textContent.trim()));
for (const c of [...result.list.chips, ...result.chipNames]) if (/[0-9()]/.test(c)) problems.push(`칩에 건수: ${c}`);

// 2. 필터·검색 → URL 반영 → 새로고침해도 유지
await page.type('#df-file', 'xss');
await page.select('#df-file-mode', 'contains');
await Promise.all([page.waitForResponse((r) => r.url().includes('/defects?')), page.click('.defect-filters button[type=submit]')]);
await page.waitForFunction(() => document.querySelectorAll('[data-rows] tr').length > 0 && !document.querySelector('[data-defects]').hasAttribute('aria-busy'));
// P21V부터 칩은 고르기만 하고 검색 버튼으로 적용한다(칩만 눌러서는 요청이 없음)
await page.click('[data-facet="status"] .chip[data-value="open"]');
await Promise.all([page.waitForResponse((r) => r.url().includes('/defects?')), page.click('.defect-filters button[type=submit]')]);
await page.waitForFunction(() => !document.querySelector('[data-defects]').hasAttribute('aria-busy'));
result.filterUrl = rel(page.url(), true);
await page.reload({ waitUntil: 'networkidle0' });
await page.waitForFunction(() => !document.querySelector('[data-defects]').hasAttribute('aria-busy') && document.querySelectorAll('[data-rows] tr').length > 0);
result.afterReload = await page.evaluate(() => ({
  file: document.querySelector('#df-file').value,
  mode: document.querySelector('#df-file-mode').value,
  pressed: [...document.querySelectorAll('.chip[aria-pressed="true"]')].map((c) => c.dataset.value),
  rows: [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.querySelector('td:nth-child(4)').textContent.trim()),
  count: document.querySelector('[data-count]').textContent,
}));
await page.screenshot({ path: `${OUT}/P17_filter.png`, fullPage: true });

// 3. 필터 결과 없음 → 필터 지우기
await page.$eval('#df-q', (e) => { e.value = ''; });
await page.type('#df-q', '없는검색어%_\'');
await Promise.all([page.waitForResponse((r) => r.url().includes('/defects?')), page.click('.defect-filters button[type=submit]')]);
await page.waitForSelector('[data-empty-filtered]:not([hidden])');
result.emptyFiltered = await page.evaluate(() => document.querySelector('[data-empty-filtered]').textContent.replace(/\s+/g, ' ').trim());
await page.screenshot({ path: `${OUT}/P17_filter_empty.png` });
await Promise.all([page.waitForResponse((r) => r.url().includes('/defects?')), page.click('[data-empty-filtered] [data-clear-filters]')]);
await page.waitForFunction(() => document.querySelectorAll('[data-rows] tr').length > 0);
result.afterClear = { url: rel(page.url(), true), count: await page.$eval('[data-count]', (e) => e.textContent) };
await collectCsp(page);

// 4. 상세: 악성 문자열이 든 코드. 시간은 화면 이동 시작 → 뷰어(.cm-editor)와 결함 줄 표시까지
let t0 = Date.now();
await page.goto(`${BASE}/defects/${xssId}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.cm-editor .cm-defect-line');
result.viewerOpenMs = Date.now() - t0;
result.xss = await page.evaluate(() => ({
  executed: window.__pjg_xss ?? null,
  scriptsInEditor: document.querySelectorAll('.cm-editor script, .cm-editor img').length,
  defectLineText: document.querySelector('.cm-defect-line')?.textContent,
  hasMark: !!document.querySelector('.cm-defect-mark'),
  gutterFirst: document.querySelector('.cm-lineNumbers .cm-gutterElement:nth-child(2)')?.textContent,
  editorStyled: getComputedStyle(document.querySelector('.cm-editor')).display === 'flex' && getComputedStyle(document.querySelector('.cm-gutters')).display === 'flex',
  highlightBg: getComputedStyle(document.querySelector('.cm-defect-line')).backgroundColor,
  readOnly: document.querySelector('.cm-content').getAttribute('aria-readonly'),
  notices: [...document.querySelectorAll('[data-code-notices] .notice')].map((n) => n.textContent.trim()),
  source: document.querySelector('[data-code-source]').textContent,
  history: document.querySelector('[data-history-empty]')?.textContent,
}));
result.cspOnViewer = await collectCsp(page);
await page.screenshot({ path: `${OUT}/P17_detail_xss.png`, fullPage: true });
// 전체 파일
await Promise.all([page.waitForResponse((r) => r.url().includes('/code?mode=full')), page.click('[data-code-mode="full"]')]);
await page.waitForSelector('.cm-editor .cm-defect-line');
result.full = await page.evaluate(() => ({
  pressed: document.querySelector('[data-code-mode="full"]').getAttribute('aria-pressed'),
  lines: document.querySelectorAll('.cm-line').length,
  notices: [...document.querySelectorAll('[data-code-notices] .notice')].map((n) => n.textContent.trim()),
}));

// 5. 발췌본(스냅샷이 정리된 분석)
t0 = Date.now();
await page.goto(`${BASE}/defects/${excerptId}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.cm-editor .cm-defect-line');
result.excerptOpenMs = Date.now() - t0;
result.excerpt = await page.evaluate(() => ({
  notices: [...document.querySelectorAll('[data-code-notices] .notice')].map((n) => n.textContent.trim()),
  modeDisabled: [...document.querySelectorAll('[data-code-mode]')].map((b) => b.disabled),
  gutter: [...document.querySelectorAll('.cm-lineNumbers .cm-gutterElement')].map((g) => g.textContent).filter(Boolean),
  source: document.querySelector('[data-code-source]').textContent,
  status: document.querySelector('[data-field="status"]').textContent.trim(),
  history: [...document.querySelectorAll('[data-history-rows] tr')].map((tr) => [...tr.querySelectorAll('td')].map((td) => td.textContent.trim())),
}));
await collectCsp(page);
await page.screenshot({ path: `${OUT}/P17_excerpt.png`, fullPage: true });

// 6. TypeScript 하이라이팅
await page.goto(`${BASE}/defects/${tsId}`, { waitUntil: 'domcontentloaded' });
await page.waitForSelector('.cm-editor .cm-defect-line');
result.ts = await page.evaluate(() => ({
  tokens: document.querySelectorAll('.cm-content span[class]').length,
  firstVersion: document.querySelector('[data-field="first_seen"] [data-tool-version]').textContent,
}));
await collectCsp(page);
await page.screenshot({ path: `${OUT}/P17_ts.png` });

// 7. 접근할 수 없는 사용자: 결함 id를 알아도 404
const ctx2 = await browser.createBrowserContext();
const other = await newPage(ctx2, true);
await login(other, OTHER, PW);
const res = await other.goto(`${BASE}/defects/${xssId}`, { waitUntil: 'networkidle0' });
result.otherUser = { status: res.status(), title: await other.title() };

await browser.close();
console.log(JSON.stringify({ result, csp, problems }, null, 2));
process.exit(csp.length || problems.length ? 1 : 0);
