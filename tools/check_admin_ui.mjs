// 관리자 도구·워커·점검 화면 브라우저 점검(P20A, 헤드리스 Chrome). 격리 환경(별도 포트·DB·임시 bin)에서만 쓴다.
// 단계별로 실행해 상태를 읽고 캡처한다. CSP 위반·콘솔 오류를 모은다.
//   PJG_PASSWORD=... node tools/check_admin_ui.mjs <기본 주소> <단계> [인자]
//   단계: tools [패키지 zip]   분석 도구 화면 읽기 → (zip이 있으면) 스테이징 올리기 → 삭제 예정 표시(0.16.11) 대화상자
//         tools-after         설치·삭제 뒤 목록 읽기
//         worker              워커 화면 읽기(실행 중이면 정지 요청 대화상자 → 요청)
//         worker-idle         워커가 없을 때 경고·시작 버튼 → 시작 결과(30초 확인)
//         checks              점검 화면 읽기(디스크 사용량 다시 계산·정리 실행 요청)
//         forbidden           kim(일반 사용자)·mgr(그룹 관리자)로 /admin/tools → 403, 메뉴 없음
//   캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P20A_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, step, arg] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const problems = [];
const csp = [];
const result = { step };

async function newPage(login) {
  // 로그인마다 별도 컨텍스트(쿠키 분리)
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/Failed to load resource/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (r) => { if (r.status() >= 400 && !r.url().includes('/admin/tools') && !r.url().includes('/api/v1/admin/worker/')) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}

const text = (s) => s.replace(/\s+/g, ' ').trim();

async function readTools(page) {
  return page.evaluate(() => ({
    node: [...document.querySelectorAll('[data-node] dt')].map((dt) => `${dt.textContent}: ${dt.nextElementSibling.textContent.trim()}`),
    tools: [...document.querySelectorAll('[data-tool]')].map((c) => ({
      tool: c.dataset.tool,
      selection: c.querySelector('[data-selection]').textContent.trim(),
      rows: [...c.querySelectorAll('tbody tr')].map((tr) => ({
        version: tr.dataset.version,
        state: [...tr.querySelectorAll('[data-state]')].map((b) => b.textContent.trim()).join(' / '),
        checked: tr.children[2].textContent.trim(),
        smoke: tr.children[3].textContent.trim().slice(0, 80),
        problems: tr.children[4].textContent.trim().slice(0, 80),
        actions: [...tr.querySelectorAll('[data-action]')].map((b) => b.dataset.action),
        command: tr.querySelector('code.command')?.textContent ?? null,
      })),
    })),
    staging: [...document.querySelectorAll('[data-staging]')].map((s) => ({ status: s.dataset.status, text: s.textContent.replace(/\s+/g, ' ').trim().slice(0, 400) })),
  }));
}

if (step === 'tools' || step === 'tools-after') {
  const page = await newPage('admin');
  await page.goto(`${BASE}/admin/tools`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-loading]').hidden);
  result.before = await readTools(page);
  result.menuActive = await page.evaluate(() => document.querySelector('[data-nav="admin.tools"]')?.getAttribute('aria-current'));
  await page.screenshot({ path: `${OUT}/P20A_tools${step === 'tools-after' ? '_after' : ''}.png`, fullPage: true });
  if (step === 'tools' && arg) {
    // 스테이징 올리기(파일 선택 → 전송 → 전체 해시)
    const input = await page.$('#stg-file');
    await input.uploadFile(arg);
    await page.click('[data-staging-form] button[type=submit]');
    await page.waitForFunction(() => document.querySelector('[data-staging][data-status="staged"]'), { timeout: 120000 });
    result.staged = await readTools(page).then((r) => r.staging);
    const el = await page.$('[data-staging][data-status="staged"]');
    await el.scrollIntoView();
    await page.screenshot({ path: `${OUT}/P20A_staging.png`, fullPage: true });
    // 삭제 예정 표시: 확인 대화상자(경고 아이콘 + 글자, 사유) → 키보드로 사유 입력, 확인
    await page.click('[data-version="0.16.11"] [data-action="mark_removal"]');
    await page.waitForSelector('dialog[open]');
    result.dialog = await page.evaluate(() => {
      const d = document.querySelector('dialog[open]');
      return { title: d.querySelector('.dialog__title').textContent, warn: d.querySelector('.notice--warn')?.textContent.trim(),
        warnIcon: d.querySelector('.notice--warn svg')?.dataset.icon, focus: document.activeElement?.tagName };
    });
    await page.keyboard.type('변조 확인된 사본');
    await page.screenshot({ path: `${OUT}/P20A_confirm_removal.png` });
    await page.click('dialog[open] button[type=submit]');
    await page.waitForFunction(() => document.querySelector('[data-version="0.16.11"] code.command'));
    result.afterMark = (await readTools(page)).tools.find((t) => t.tool === 'ruff').rows.find((r) => r.version === '0.16.11');
  }
  await page.close();
}

if (step === 'worker' || step === 'worker-idle') {
  const page = await newPage('admin');
  await page.goto(`${BASE}/admin/worker`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-loading]').hidden);
  const read = () => page.evaluate(() => ({
    state: document.querySelector('[data-state-badge]').textContent.trim(),
    status: [...document.querySelectorAll('[data-status] dt')].map((dt) => `${dt.textContent}: ${dt.nextElementSibling.textContent.trim()}`),
    noWorker: document.querySelector('[data-no-worker]').hidden ? null : document.querySelector('[data-no-worker]').textContent.trim(),
    helpOpen: document.querySelector('[data-help]').open,
    buttons: ['stop', 'restart', 'start'].filter((b) => !document.querySelector(`[data-${b}]`).hidden).map((b) => `${b}${document.querySelector(`[data-${b}]`).disabled ? '(비활성)' : ''}`),
    pending: document.querySelector('[data-stop-pending]').hidden ? null : document.querySelector('[data-stop-pending]').textContent.trim(),
    hint: document.querySelector('[data-start-hint]').hidden ? null : document.querySelector('[data-start-hint]').textContent.trim(),
    queue: document.querySelector('[data-queue-summary]').textContent.trim(),
    running: [...document.querySelectorAll('[data-running] tr')].map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()),
    startResult: document.querySelector('[data-start-result]').hidden ? null : document.querySelector('[data-start-result]').textContent.trim(),
    logInfo: document.querySelector('[data-log-info]').textContent.trim(),
    logSample: document.querySelector('[data-log]').textContent.split('\n').slice(-3),
  }));
  await page.waitForFunction(() => document.querySelector('[data-log-info]').textContent !== '');
  result.before = await read();
  await page.screenshot({ path: `${OUT}/P20A_worker${step === 'worker-idle' ? '_idle' : ''}.png`, fullPage: true });
  if (step === 'worker' && result.before.buttons.includes('stop')) {
    await page.click('[data-stop]');
    await page.waitForSelector('dialog[open]');
    result.dialog = await page.evaluate(() => ({ text: document.querySelector('dialog[open]').textContent.replace(/\s+/g, ' ').trim(), focus: document.activeElement?.textContent.trim() }));
    await page.screenshot({ path: `${OUT}/P20A_confirm_stop.png` });
    await page.click('dialog[open] .btn--danger');
    await page.waitForFunction(() => !document.querySelector('[data-stop-pending]').hidden || document.querySelector('[data-state-badge] [data-state="stopped"]'), { timeout: 20000 });
    result.afterRequest = await read();
    await page.waitForFunction(() => document.querySelector('[data-state-badge] [data-state="stopped"]'), { timeout: 60000 });
    result.afterStop = await read();
  }
  if (step === 'worker-idle' && result.before.buttons.some((b) => b.startsWith('start'))) {
    await page.click('[data-start]');
    await page.waitForSelector('dialog[open]');
    await page.click('dialog[open] .btn--primary');
    await page.waitForFunction(() => !document.querySelector('[data-start-result]').hidden, { timeout: 20000 });
    result.startSent = await read();
    await page.waitForFunction(() => /시작 확인 실패|시작되었습니다/.test(document.querySelector('[data-start-result]').textContent), { timeout: 60000 });
    result.startChecked = await read();
    await page.screenshot({ path: `${OUT}/P20A_worker_start.png`, fullPage: true });
  }
  await page.close();
}

if (step === 'checks') {
  const page = await newPage('admin');
  await page.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelector('[data-loading]').hidden);
  result.checks = await page.evaluate(() => ({
    disk: document.querySelector('[data-disk]').textContent.replace(/\s+/g, ' ').trim().slice(0, 600),
    cleanup: [...document.querySelectorAll('[data-cleanup] dt')].map((dt) => `${dt.textContent}: ${dt.nextElementSibling.textContent.trim()}`),
    failures: document.querySelector('[data-failures]').textContent.replace(/\s+/g, ' ').trim().slice(0, 500),
    env: [...document.querySelectorAll('[data-env] tr')].map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()),
    config: [...document.querySelectorAll('[data-config] tr')].map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()),
  }));
  await page.screenshot({ path: `${OUT}/P20A_checks.png`, fullPage: true });
  await page.close();
}

if (step === 'forbidden') {
  for (const login of ['kim', 'mgr']) {
    const page = await newPage(login);
    const res = await page.goto(`${BASE}/admin/tools`, { waitUntil: 'networkidle0' });
    result[login] = { status: res.status(), menu: await page.$('[data-nav="admin.tools"]') !== null };
    await page.close();
  }
}

await browser.close();
result.csp = csp;
result.problems = problems.filter((p) => !/HTTP 403: .*\/admin\/tools/.test(p));
console.log(JSON.stringify(result, null, 2));
