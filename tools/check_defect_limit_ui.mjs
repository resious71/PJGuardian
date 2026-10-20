// 결함 수 경고·상한·최근 감지 브라우저 점검(P19C, 헤드리스 Chrome). 격리 서버·DB 사본(023 적용, [limits]를 낮춘 점검용 설정) + 격리 워커에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_defect_limit_ui.mjs <점검 서버> <경고 프로젝트> <상한 초과 분석 id> <일반 사용자> [관리자]
// 확인(1280·1024px):
//   - 프로젝트 화면: 결함 수 경고 한 줄(아이콘 + 글자, "결함이 N건으로 많습니다(경고 기준 M건)…") — 관리자·일반 사용자 모두
//   - 관리자 알림: defect_count_warn 문구(관리자 전용) / 일반 사용자 알림 목록에는 없음
//   - 상한 초과 분석의 실패 화면: 제목 "결함 수 상한 초과", 원인·조치(제외 패턴), 코드 defect_limit_exceeded
//   - 결함 목록 "최근 감지" 열: 값이 API last_seen과 같고, 머리글로 정렬하면 API 정렬(last_seen desc)과 같은 순서
//   - 가로 스크롤: 결함 표 화면 0, 그 밖은 앱 최소 너비(1280px, 명세 1)만큼만. 콘솔 오류 0, CSP 위반 0. 캡처 P19C_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, warnProject, failedAnalysis, userLogin, adminLogin = 'admin'] = process.argv;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_defect_limit_ui.mjs <점검 서버> <경고 프로젝트> <상한 초과 분석 id> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
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
const api = (page, method, url) => page.evaluate(async (m, u) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { Accept: 'application/json' } });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const at = async (page, width, shot) => {
  await page.setViewport({ width, height: 900 });
  await sleep(250);
  const s = await page.evaluate(() => ({
    scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    allowed: document.body.matches(':has(.defects-table)') ? 0 : Math.max(0, 1280 - document.documentElement.clientWidth),
  }));
  r[`scroll_${shot}`] = s;
  check(s.scroll <= s.allowed, `가로 스크롤 ${shot}`, s);
  await page.screenshot({ path: path.join(OUT, `P19C_${shot}.png`), fullPage: true });
};

const admin = await session(adminLogin);
const a = admin.page;
try {
  // ---- 프로젝트 화면: 경고 한 줄 ----
  await a.goto(`${BASE}/projects/${warnProject}`, { waitUntil: 'networkidle0' });
  r.warn = await text(a, '[data-defect-count-warn]');
  r.warnIcon = await a.$eval('[data-defect-count-warn] svg', () => true).catch(() => false);
  check(/^결함이 [\d,]+건으로 많습니다\(경고 기준 [\d,]+건\)\. 생성 파일·압축\(minified\) 파일을 제외 패턴에 넣어 보세요\.$/.test(r.warn ?? '') && r.warnIcon, '프로젝트 경고 한 줄(아이콘 + 글자)', [r.warn, r.warnIcon]);
  await at(a, 1280, 'project_warn_1280');
  await at(a, 1024, 'project_warn_1024');
  await a.setViewport({ width: 1280, height: 900 });
  // ---- 결함 목록 최근 감지 열·정렬 ----
  const apiSorted = (await api(a, 'GET', `/api/v1/projects/${warnProject}/defects?sort=last_seen&order=desc&size=50`)).json;
  const apiAll = (await api(a, 'GET', `/api/v1/projects/${warnProject}/defects?size=50`)).json;
  await a.waitForSelector('.defects-table tbody tr');
  r.cells = await a.$$eval('.defects-table tbody tr', (rows) => rows.map((tr) => ({ id: Number(tr.dataset.defectId), seen: tr.querySelector('.defects-table__seen')?.textContent.trim() })));
  const byId = Object.fromEntries((apiAll?.data ?? []).map((d) => [d.id, d]));
  // 화면 값 = 마지막 감지 분석 종료 시각의 KST 날짜(yy-mm-dd)
  const kst = (iso) => new Date(Date.parse(iso) + 9 * 3600 * 1000).toISOString().slice(2, 10);
  r.seenMismatch = r.cells.filter((c) => !byId[c.id] || !byId[c.id].last_detected_at || c.seen !== kst(byId[c.id].last_detected_at)).slice(0, 5);
  check(r.cells.length > 0 && r.seenMismatch.length === 0, '최근 감지 열 = API 값(날짜)', r.seenMismatch);
  await a.goto(`${BASE}/projects/${warnProject}?sort=last_seen&order=desc`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('.defects-table tbody tr');
  r.sortedIds = await a.$$eval('.defects-table tbody tr', (rows) => rows.map((tr) => Number(tr.dataset.defectId)));
  check(JSON.stringify(r.sortedIds) === JSON.stringify((apiSorted?.data ?? []).map((d) => d.id)), '최근 감지 정렬 = API 정렬', [r.sortedIds.slice(0, 5), (apiSorted?.data ?? []).slice(0, 5).map((d) => d.id)]);
  await at(a, 1280, 'defects_last_seen_1280');
  await at(a, 1024, 'defects_last_seen_1024');
  await a.setViewport({ width: 1280, height: 900 });
  // ---- 관리자 알림 ----
  const notes = (await api(a, 'GET', '/api/v1/notifications?size=50')).json?.data ?? [];
  r.adminWarn = notes.filter((n) => n.kind === 'defect_count_warn').map((n) => [n.project?.id, n.params?.count, n.params?.warn, n.href]);
  check(r.adminWarn.length === 1 && r.adminWarn[0][0] === Number(warnProject), '관리자 알림 defect_count_warn 한 건', r.adminWarn);
  await a.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  await sleep(500);
  r.noteText = await a.$$eval('[data-notif-list] li, .notif-list li', (ls) => ls.map((l) => l.textContent.replace(/\s+/g, ' ').trim()).find((t) => t.includes('결함이')) ?? null).catch(() => null);
  check(/결함이 [\d,]+건으로 많습니다/.test(r.noteText ?? ''), '알림 목록 문구', r.noteText);
  await at(a, 1280, 'notifications_admin');
  await a.setViewport({ width: 1280, height: 900 });
  // ---- 상한 초과 분석 실패 화면 ----
  await a.goto(`${BASE}/analyses/${failedAnalysis}`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-failure]')?.hidden, { timeout: 10000 });
  r.failure = { title: await text(a, '[data-failure-title]'), cause: await text(a, '[data-failure-cause]'), action: await text(a, '[data-failure-action]'), code: await text(a, '[data-failure-code]') };
  check(r.failure.title === '결함 수 상한 초과' && /허용 상한/.test(r.failure.cause ?? '') && /제외 패턴/.test(r.failure.action ?? '') && r.failure.code === 'defect_limit_exceeded', '상한 초과 실패 화면', r.failure);
  await at(a, 1280, 'limit_failed_1280');
  await at(a, 1024, 'limit_failed_1024');
} finally {
  await admin.browser.close();
}
const u = await session(userLogin);
try {
  await u.page.goto(`${BASE}/projects/${warnProject}`, { waitUntil: 'networkidle0' });
  r.userWarn = await text(u.page, '[data-defect-count-warn]');
  const notes = (await api(u.page, 'GET', '/api/v1/notifications?size=50')).json?.data ?? [];
  r.userNotes = notes.filter((n) => n.kind === 'defect_count_warn').length;
  check(r.userWarn !== null && r.userNotes === 0, '일반 사용자: 경고 줄은 보이고 관리자 알림은 없음', [r.userWarn, r.userNotes]);
} finally {
  await u.browser.close();
}
console.log(JSON.stringify(r, null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${problems.join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
