// 소스 저장소 연동 브라우저 점검(P25, 헤드리스 Chrome). 격리 서버·DB 사본(017·018 + 점검용 암호화 키) + 격리 워커 + 로컬 Git HTTP 저장소에서만 쓴다.
//   PJG_PASSWORD=... PJG_REPO_URL=<로컬 저장소 주소> PJG_REPO_TOKEN=<토큰> node tools/check_repo_ui.mjs <기본 주소> <프로젝트> <일반 사용자> [관리자]
// 확인(1280·1024px):
//   - 허용 호스트(X12부터 설정 관리 > 저장소 연결 탭 구역): 추가(HTTP 허용·GitHub 호환)·잘못된 호스트 오류·목록
//   - 프로젝트 편집 "소스 저장소": 유형 전환에 따른 입력 항목, HTTP 평문 경고, 잘못된 토큰 연결 테스트 실패 문장, 맞는 토큰 성공, 저장 → "설정됨(토큰)"(값은 다시 안 보임)
//   - 프로젝트 화면: 상태 상자, "지금 가져와 분석" → 워커가 처리 → 상태 정상·마지막 커밋, 분석 탭·분석 상세·히스토리에 출처(브랜치 @해시)와 커밋 링크(새 탭, noopener)
//   - 일반 사용자: 상태 상자만(지금 가져와 분석·연결 설정 없음), 응답·화면에 저장소 주소·토큰 없음
//   - 가로 스크롤 0, 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P25_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, userLogin, adminLogin = 'admin'] = process.argv;
const REPO_URL = process.env.PJG_REPO_URL;
const TOKEN = process.env.PJG_REPO_TOKEN;
if (!projectId || !userLogin || !process.env.PJG_PASSWORD || !REPO_URL || !TOKEN) {
  console.error('사용법: PJG_PASSWORD=... PJG_REPO_URL=... PJG_REPO_TOKEN=... node tools/check_repo_ui.mjs <기본 주소> <프로젝트> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
const repoHost = new URL(REPO_URL);

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (400|403|409)/.test(m.text())) return; // 의도한 오류(잘못된 호스트·일반 사용자 저장 403 등)
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return { browser, page };
}
const api = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: b === undefined ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json, text: JSON.stringify(json) };
}, method, url, body);
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

const admin = await session(adminLogin);
const a = admin.page;
try {
  // ---- 허용 호스트 ----
  await a.goto(`${BASE}/admin/config-settings#repository`, { waitUntil: 'networkidle0' });
  await a.type('#rh-host', '0x7f.1');
  await a.click('[data-host-add]');
  await a.waitForFunction(() => !document.querySelector('[data-repo-hosts] [data-error]').hidden);
  r.badHost = await a.$eval('[data-repo-hosts] [data-error]', (n) => n.textContent);
  await a.click('#rh-host', { clickCount: 3 });
  await a.type('#rh-host', repoHost.hostname);
  await a.type('#rh-port', repoHost.port);
  await a.click('input[name="allow_http"]');
  await a.click('input[name="github_links"]');
  await a.type('#rh-note', '점검용 로컬 Git');
  await a.click('[data-host-add]');
  await a.waitForSelector('[data-host-rows] tr');
  r.hosts = await a.$$eval('[data-host-rows] tr', (rows) => rows.map((tr) => [...tr.children].map((c) => c.textContent.trim() || (c.querySelector('input')?.checked ? 'on' : 'off'))));
  check(r.hosts.some((h) => h[0] === repoHost.hostname), '허용 호스트 추가', r.hosts);
  await a.screenshot({ path: path.join(OUT, 'P25_hosts_1280.png'), fullPage: true });

  // ---- 연결 설정 ----
  await a.goto(`${BASE}/projects/${projectId}/edit#project-repo`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => document.querySelector('#repo-url') !== null);
  r.noneFields = await a.$$eval('[data-repo-form] [data-field]', (fs) => fs.filter((f) => !f.hidden).map((f) => f.dataset.field));
  await a.click('input[name="kind"][value="perforce"]');
  r.p4Fields = await a.$$eval('[data-repo-form] [data-field]', (fs) => fs.filter((f) => !f.hidden).map((f) => f.dataset.field));
  await a.click('input[name="kind"][value="git"]');
  r.gitFields = await a.$$eval('[data-repo-form] [data-field]', (fs) => fs.filter((f) => !f.hidden).map((f) => f.dataset.field));
  check(r.noneFields.length === 0 && r.p4Fields.includes('port') && !r.p4Fields.includes('branch') && r.gitFields.includes('url') && !r.gitFields.includes('port'), '유형별 항목', r);
  await a.type('#repo-url', REPO_URL);
  r.plainWarn = await a.$eval('[data-plaintext-warning]', (n) => !n.hidden);
  check(r.plainWarn, 'HTTP 평문 경고');
  await a.type('#repo-secret', 'WRONG-token');
  await a.click('[data-repo-test]');
  await a.waitForFunction(() => document.querySelector('[data-repo-result]').dataset.ok !== undefined, { timeout: 40000 });
  r.testWrong = await a.$eval('[data-repo-result]', (n) => n.textContent);
  check(r.testWrong.includes('인증 실패'), '잘못된 토큰 → 인증 실패', r.testWrong);
  await a.screenshot({ path: path.join(OUT, 'P25_settings_test_fail.png'), clip: await a.$eval('[data-repo-form]', (n) => { const b = n.getBoundingClientRect(); return { x: 0, y: Math.max(0, b.top + window.scrollY - 10), width: 1280, height: Math.min(1400, b.height + 20) }; }), captureBeyondViewport: true });
  await a.click('#repo-secret', { clickCount: 3 });
  await a.type('#repo-secret', TOKEN);
  await a.evaluate(() => { delete document.querySelector('[data-repo-result]').dataset.ok; });
  await a.click('[data-repo-test]');
  await a.waitForFunction(() => document.querySelector('[data-repo-result]').dataset.ok !== undefined, { timeout: 40000 });
  r.testOk = await a.$eval('[data-repo-result]', (n) => n.textContent);
  check(r.testOk.startsWith('연결됨'), '맞는 토큰 → 연결됨', r.testOk);
  await a.click('[data-repo-save]');
  await a.waitForFunction(() => document.querySelector('[data-repo-result]').textContent.includes('저장'), { timeout: 15000 });
  r.secretState = await a.$eval('[data-secret-state]', (n) => n.textContent);
  r.secretInput = await a.$eval('#repo-secret', (n) => n.value);
  check(r.secretState === '설정됨(토큰)' && r.secretInput === '', '저장 뒤 설정됨·값 비표시', [r.secretState, r.secretInput]);
  r.html = await a.content();
  check(!r.html.includes(TOKEN), '편집 화면 HTML에 토큰 없음');
  await a.screenshot({ path: path.join(OUT, 'P25_settings_saved_1280.png'), clip: await a.$eval('[data-repo-form]', (n) => { const b = n.getBoundingClientRect(); return { x: 0, y: Math.max(0, b.top + window.scrollY - 10), width: 1280, height: Math.min(1400, b.height + 20) }; }), captureBeyondViewport: true });
  r.scrollEdit1280 = await scrollX(a);

  // ---- 지금 가져와 분석 ----
  await a.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden && !document.querySelector('[data-repo-fetch]').disabled, { timeout: 15000 });
  await a.screenshot({ path: path.join(OUT, 'P25_project_before.png'), clip: { x: 0, y: 0, width: 1280, height: 620 } });
  await a.click('[data-repo-fetch]');
  await a.waitForFunction(() => document.querySelector('[data-repo-message]').textContent.includes('대기열'), { timeout: 15000 });
  r.queuedMsg = await a.$eval('[data-repo-message]', (n) => n.textContent);
  r.reasonWhileBusy = await a.$eval('[data-repo-fetch-reason]', (n) => n.textContent);
  const aid = Number((/#(\d+)/.exec(r.queuedMsg) ?? [])[1]);
  let st = null;
  for (let i = 0; i < 120; i++) {
    st = (await api(a, 'GET', `/api/v1/analyses/${aid}`)).json?.data;
    if (st && ['succeeded', 'failed', 'cancelled'].includes(st.status)) break;
    await sleep(1000);
  }
  r.analysis = { id: aid, status: st?.status, kind: st?.source_kind, ref: st?.source_ref, rev: st?.source_revision, link: st?.source_link, failure: st?.failure_code };
  check(st?.status === 'succeeded' && st.source_link?.includes('/team/app/commit/'), '가져와 분석 성공·커밋 링크', r.analysis);
  await a.reload({ waitUntil: 'networkidle0' });
  await a.waitForFunction(() => document.querySelector('[data-repo-state] [data-repo-state]')?.dataset.repoState === 'ok', { timeout: 15000 });
  r.statusBox = await a.$eval('[data-repo-status]', (n) => n.innerText.replace(/\s+/g, ' '));
  await a.screenshot({ path: path.join(OUT, 'P25_project_after_1280.png'), clip: { x: 0, y: 0, width: 1280, height: 620 } });
  // 분석 탭의 출처
  await a.goto(`${BASE}/projects/${projectId}#analyses`, { waitUntil: 'networkidle0' });
  await a.waitForSelector(`[data-analysis-id="${aid}"] .source-cell a`, { timeout: 15000 });
  r.analysesLink = await a.$eval(`[data-analysis-id="${aid}"] .source-cell a`, (n) => ({ href: n.href, rel: n.rel, target: n.target, text: n.textContent }));
  check(r.analysesLink.rel === 'noopener noreferrer' && r.analysesLink.target === '_blank', '커밋 링크 새 탭·noopener', r.analysesLink);
  await a.screenshot({ path: path.join(OUT, 'P25_analyses_tab.png'), fullPage: false });
  await a.goto(`${BASE}/analyses/${aid}`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('.source-cell');
  r.detail = await a.evaluate(() => document.querySelector('main').innerText.includes('커밋·changelist'));
  await a.screenshot({ path: path.join(OUT, 'P25_analysis_detail.png'), fullPage: false });
  await a.goto(`${BASE}/projects/${projectId}/history`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-history-rows] .source-cell', { timeout: 15000 });
  r.historySource = await a.$eval('[data-history-rows] .source-cell', (n) => n.textContent.trim());
  // 1024
  await a.setViewport({ width: 1024, height: 800 });
  await a.goto(`${BASE}/projects/${projectId}/edit#project-repo`, { waitUntil: 'networkidle0' });
  r.scrollEdit1024 = await scrollX(a);
  await a.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden);
  r.scrollShow1024 = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P25_project_1024.png'), clip: { x: 0, y: 0, width: 1024, height: 700 } });
  await a.goto(`${BASE}/admin/config-settings#repository`, { waitUntil: 'networkidle0' });
  r.scrollHosts1024 = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P25_hosts_1024.png'), fullPage: true });
  check(r.scrollEdit1280 <= 0 && r.scrollEdit1024 <= 0 && r.scrollShow1024 <= 0 && r.scrollHosts1024 <= 0, '가로 스크롤 0', [r.scrollEdit1280, r.scrollEdit1024, r.scrollShow1024, r.scrollHosts1024]);
} catch (e) {
  problems.push(`예외(관리자): ${e.stack ?? e}`);
}

// ---- 일반 사용자 ----
const user = await session(userLogin);
const u = user.page;
try {
  await u.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
  await u.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden, { timeout: 15000 });
  r.userBox = { fetch: await u.$('[data-repo-fetch]') !== null, settings: await u.$('[data-repo-settings]') !== null, text: await u.$eval('[data-repo-status]', (n) => n.innerText.replace(/\s+/g, ' ')) };
  check(!r.userBox.fetch && !r.userBox.settings, '일반 사용자: 가져오기·설정 없음', r.userBox);
  const view = await api(u, 'GET', `/api/v1/projects/${projectId}/repo`);
  r.userApiKeys = Object.keys(view.json?.data ?? {});
  const page = await u.content();
  check(!view.text.includes(TOKEN) && !view.text.includes('location') && !page.includes(TOKEN), '일반 사용자 응답·화면에 토큰·주소 없음', r.userApiKeys);
  r.userEdit = (await api(u, 'PUT', `/api/v1/projects/${projectId}/repo`, { kind: 'git', url: REPO_URL })).status;
  check(r.userEdit === 403, '일반 사용자 저장 403', r.userEdit);
  await u.screenshot({ path: path.join(OUT, 'P25_project_user.png'), clip: { x: 0, y: 0, width: 1280, height: 620 } });
} catch (e) {
  problems.push(`예외(사용자): ${e.stack ?? e}`);
}

await admin.browser.close();
await user.browser.close();
console.log(JSON.stringify({ r: { ...r, html: undefined }, csp, problems }, null, 2));
process.exit(problems.length === 0 && csp.length === 0 ? 0 : 1);
