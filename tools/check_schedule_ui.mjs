// 주기 분석 브라우저 점검(P26, 헤드리스 Chrome). 격리 서버·DB 사본(019 적용 + 점검용 암호화 키) + 격리 워커 + 로컬 Git HTTP 저장소에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_schedule_ui.mjs <기본 주소> <정상 연결 프로젝트> <연결 없는 프로젝트> <연속 실패 프로젝트> <일반 사용자> [관리자]
// 확인(1280·1024px):
//   - 프로젝트 편집 "주기 분석": 잘못된 시각 오류(필드 아래), 같은 시각 예약 안내, "1주일" 빠른 선택, 저장 → 다음 실행 예정, 연결 없는 프로젝트는 끄고 이유
//   - 프로젝트 목록: 주기 표시(아이콘 + 다음 예정), admin에게 연속 실패 경고 / 일반 사용자는 경고 없음
//   - 프로젝트 화면: "주기 분석" 줄(N일마다 · 다음 예정), admin에게 연속 실패 경고(아이콘 + 글자)
//   - 시스템 관리 > 주기 분석: 스케줄러 상태, 일정 표, 최근 실패(다음 주기 재수행 예정), 최근 건너뜀
//   - 알림: admin 알림 목록에 schedule_failed 문구, 일반 사용자에게는 없음(목록·설정 탭)
//   - 분석 탭: 실행 주체 조건 "주기" → 주기 분석만, 요청자 칸 "주기 실행"
//   - 일반 사용자: 편집 화면 403, 설정 API 403
//   - 가로 스크롤 0, 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P26_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, okProject, noConnProject, failProject, userLogin, adminLogin = 'admin'] = process.argv;
if (!failProject || !userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_schedule_ui.mjs <기본 주소> <정상 연결 프로젝트> <연결 없는 프로젝트> <연속 실패 프로젝트> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (403|409)/.test(m.text())) return; // 의도한 오류(일반 사용자 403 등)
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
  return { status: res.status, json };
}, method, url, body);
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.trim()).catch(() => null);
const visible = (page, sel) => page.$eval(sel, (n) => !n.hidden && n.offsetParent !== null).catch(() => false);
const at = async (page, width, shot) => {
  await page.setViewport({ width, height: 900 });
  await new Promise((res) => setTimeout(res, 150));
  r[`scroll_${shot}`] = await scrollX(page);
  check(r[`scroll_${shot}`] <= 0, `가로 스크롤 ${shot}`, r[`scroll_${shot}`]);
  await page.screenshot({ path: path.join(OUT, `P26_${shot}.png`), fullPage: true });
};

const admin = await session(adminLogin);
const a = admin.page;
try {
  // ---- 편집 화면: 연결 없는 프로젝트 → 끄고 이유 ----
  await a.goto(`${BASE}/projects/${noConnProject}/edit#project-schedule`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => document.querySelector('[data-schedule-blocker]') && !document.querySelector('[data-schedule-blocker]').hidden);
  r.blocker = await text(a, '[data-schedule-blocker-text]');
  r.blockedDisabled = await a.$eval('[data-schedule-enabled]', (n) => n.disabled);
  check(r.blockedDisabled && /연결되어 있지 않아/.test(r.blocker ?? ''), '연결 없음 → 끄고 이유', [r.blockedDisabled, r.blocker]);
  await a.$eval('#project-schedule', (n) => n.scrollIntoView());
  await a.screenshot({ path: path.join(OUT, 'P26_settings_blocked.png'), clip: await a.$eval('#project-schedule', (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height }; }) });

  // ---- 편집 화면: 정상 연결 → 검증·같은 시각 안내·저장 ----
  await a.goto(`${BASE}/projects/${okProject}/edit#project-schedule`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => document.querySelector('#schedule-time')?.value);
  r.initial = { enabled: await a.$eval('[data-schedule-enabled]', (n) => n.checked), days: await a.$eval('#schedule-days', (n) => n.value), time: await a.$eval('#schedule-time', (n) => n.value) };
  r.sameTimeShown = await visible(a, '[data-schedule-same-time]');
  r.sameTime = await text(a, '[data-schedule-same-time]');
  check(r.sameTimeShown && /03:00.*1개/.test(r.sameTime ?? ''), '같은 시각(03:00) 예약 안내', r.sameTime);
  await a.click('[data-schedule-enabled]');
  await a.click('#schedule-days', { clickCount: 3 });
  await a.type('#schedule-days', '3');
  await a.click('[data-schedule-week]');
  r.weekButton = await a.$eval('#schedule-days', (n) => n.value);
  check(r.weekButton === '7', '1주일 빠른 선택', r.weekButton);
  await a.click('#schedule-time', { clickCount: 3 });
  await a.type('#schedule-time', '25:00');
  await a.click('[data-schedule-save]');
  await a.waitForFunction(() => !document.querySelector('[data-error-for="run_time"]').hidden);
  r.timeError = await text(a, '[data-error-for="run_time"]');
  r.timeInvalid = await a.$eval('#schedule-time', (n) => n.getAttribute('aria-invalid'));
  check(/00:00~23:59/.test(r.timeError ?? '') && r.timeInvalid === 'true', '잘못된 시각 오류', [r.timeError, r.timeInvalid]);
  await a.$eval('#project-schedule', (n) => n.scrollIntoView());
  await a.screenshot({ path: path.join(OUT, 'P26_settings_invalid.png'), fullPage: false });
  await a.click('#schedule-time', { clickCount: 3 });
  await a.type('#schedule-time', '5:20');
  await a.click('[data-schedule-save]');
  await a.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-schedule-result]').textContent));
  r.saved = await text(a, '[data-schedule-result]');
  r.savedTime = await a.$eval('#schedule-time', (n) => n.value);
  r.next = await text(a, '[data-schedule-next]');
  check(r.savedTime === '05:20' && /\d{4}-\d{2}-\d{2} 05:20/.test(r.next ?? ''), '저장 → 다음 실행 예정(입력 정리 5:20 → 05:20)', [r.savedTime, r.next]);
  r.sameTimeAfter = await visible(a, '[data-schedule-same-time]');
  check(!r.sameTimeAfter, '05:20에는 같은 시각 예약 없음', r.sameTimeAfter);
  await at(a, 1280, 'settings_saved_1280');
  await at(a, 1024, 'settings_saved_1024');
  await a.setViewport({ width: 1280, height: 900 });
  const savedApi = await api(a, 'GET', `/api/v1/projects/${okProject}/schedule`);
  r.savedApi = { enabled: savedApi.json?.data?.enabled, days: savedApi.json?.data?.interval_days, time: savedApi.json?.data?.run_time, rev: savedApi.json?.data?.revision };
  check(r.savedApi.enabled === true && r.savedApi.days === 7 && r.savedApi.time === '05:20', '저장된 값', r.savedApi);

  // ---- 프로젝트 목록 ----
  await a.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.listTags = await a.$$eval('.project-schedule-tag', (ns) => ns.map((n) => n.textContent.trim()));
  check(r.listTags.some((s) => /주기 · 다음/.test(s)) && r.listTags.some((s) => /연속 실패 \d+회/.test(s)), '목록 주기 표시·연속 실패(admin)', r.listTags);
  await at(a, 1280, 'projects_admin_1280');
  await a.setViewport({ width: 1280, height: 900 });

  // ---- 프로젝트 화면: 연속 실패 경고 ----
  await a.goto(`${BASE}/projects/${failProject}`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden && document.querySelector('[data-repo-next-run]').textContent.trim() !== '—');
  r.statusLine = await text(a, '[data-repo-next-run]');
  r.failing = await text(a, '[data-repo-schedule-failing-text]');
  r.failingShown = await visible(a, '[data-repo-schedule-failing]');
  check(/1일마다 · 다음 예정/.test(r.statusLine ?? '') && r.failingShown && /연속 \d+회 실패/.test(r.failing ?? ''), '프로젝트 화면 주기 줄·연속 실패 경고', [r.statusLine, r.failing]);
  await a.$eval('[data-repo-status]', (n) => n.scrollIntoView());
  await a.screenshot({ path: path.join(OUT, 'P26_project_failing.png'), clip: await a.$eval('[data-repo-status]', (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height }; }) });

  // ---- 분석 탭: 실행 주체 조건 ----
  await a.goto(`${BASE}/projects/${failProject}#analyses`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-analyses] [data-trigger]');
  await a.select('[data-analyses] [data-trigger]', 'schedule');
  await a.waitForFunction(() => document.querySelectorAll('[data-analyses] [data-rows] tr').length > 0);
  r.scheduleRows = await a.$$eval('[data-analyses] [data-rows] tr', (rows) => rows.map((tr) => [tr.dataset.status, tr.children[0].textContent.trim(), tr.children[3].textContent.trim()]));
  check(r.scheduleRows.length > 0 && r.scheduleRows.every((x) => x[2] === '주기 실행'), '분석 탭 실행 주체 = 주기', r.scheduleRows);
  r.attemptsShown = r.scheduleRows.some((x) => /3번 시도|재시도 대기/.test(x[1]));
  check(r.attemptsShown, '재시도 표시(재시도 대기 또는 N번 시도)', r.scheduleRows);
  await a.select('[data-analyses] [data-trigger]', 'user');
  await a.waitForFunction(() => !document.querySelector('[data-analyses] [data-empty-filtered]').hidden || document.querySelectorAll('[data-analyses] [data-rows] tr').length === 0);
  r.userFilteredEmpty = await visible(a, '[data-analyses] [data-empty-filtered]');
  check(r.userFilteredEmpty, '사용자 실행 없음 → 조건 결과 없음 안내', r.userFilteredEmpty);
  await a.select('[data-analyses] [data-trigger]', 'schedule');
  await a.waitForFunction(() => document.querySelectorAll('[data-analyses] [data-rows] tr').length > 0);
  await at(a, 1280, 'analyses_trigger');
  await a.setViewport({ width: 1280, height: 900 });

  // ---- 관리자 주기 분석 탭 ----
  await a.goto(`${BASE}/admin/schedules`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-admin-schedules] [data-table]').hidden);
  r.scheduler = await text(a, '[data-scheduler-state]');
  r.delayed = await visible(a, '[data-scheduler-delayed]');
  r.schedRows = await a.$$eval('[data-admin-schedules] [data-rows] tr', (rows) => rows.map((tr) => [...tr.children].map((c) => c.textContent.trim())));
  r.failuresList = await a.$$eval('[data-failures] li', (ls) => ls.map((l) => l.textContent.trim()));
  r.skipsList = await a.$$eval('[data-skips] li', (ls) => ls.map((l) => l.textContent.trim()));
  check(/마지막 확인/.test(r.scheduler ?? '') && !r.delayed, '스케줄러 상태', [r.scheduler, r.delayed]);
  check(r.schedRows.length >= 3, '일정 표', r.schedRows);
  check(r.failuresList.some((s) => /실패 — 3번 시도/.test(s) && /다음 주기 재수행 예정/.test(s)), '최근 실패(재수행 예정)', r.failuresList);
  r.subnavCurrent = await text(a, '.subnav [aria-current="page"]');
  check(r.subnavCurrent === '주기 분석', '하위 메뉴', r.subnavCurrent);
  await at(a, 1280, 'admin_schedules_1280');
  await at(a, 1024, 'admin_schedules_1024');
  await a.setViewport({ width: 1280, height: 900 });

  // ---- 알림(admin) ----
  await a.goto(`${BASE}/notifications?kind=schedule_failed`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => document.querySelectorAll('[data-notif-list] [data-items] li').length > 0 || !document.querySelector('[data-notif-list] [data-empty]').hidden);
  r.adminNotifs = await a.$$eval('[data-notif-list] [data-items] li', (ls) => ls.map((l) => l.textContent.trim().replace(/\s+/g, ' ')));
  check(r.adminNotifs.some((s) => /주기 분석이 (연속 \d+회 )?실패했습니다/.test(s)), 'admin 알림에 schedule_failed', r.adminNotifs);
  r.adminPrefSwitch = await a.$('#notif-pref-schedule_failed') !== null;
  check(r.adminPrefSwitch, 'admin 설정 탭에 주기 분석 실패 스위치', r.adminPrefSwitch);
  await a.screenshot({ path: path.join(OUT, 'P26_notifications_admin.png'), fullPage: false });
} finally {
  await admin.browser.close();
}

const user = await session(userLogin);
const u = user.page;
try {
  await u.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.userTags = await u.$$eval('.project-schedule-tag', (ns) => ns.map((n) => n.textContent.trim()));
  check(r.userTags.some((s) => /주기 · 다음/.test(s)) && !r.userTags.some((s) => /연속 실패/.test(s)), '일반 사용자 목록: 주기 표시만', r.userTags);
  await u.goto(`${BASE}/projects/${failProject}`, { waitUntil: 'networkidle0' });
  await u.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden && document.querySelector('[data-repo-next-run]').textContent.trim() !== '—');
  r.userStatusLine = await text(u, '[data-repo-next-run]');
  r.userFailing = await visible(u, '[data-repo-schedule-failing]');
  check(/다음 예정/.test(r.userStatusLine ?? '') && !r.userFailing, '일반 사용자 프로젝트 화면: 읽기 전용 표시·실패 경고 없음', [r.userStatusLine, r.userFailing]);
  await u.$eval('[data-repo-status]', (n) => n.scrollIntoView());
  await u.screenshot({ path: path.join(OUT, 'P26_project_user.png'), clip: await u.$eval('[data-repo-status]', (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height }; }) });
  r.userApi = (await api(u, 'GET', `/api/v1/projects/${failProject}/schedule`)).status;
  r.userPut = (await api(u, 'PUT', `/api/v1/projects/${failProject}/schedule`, { enabled: false, interval_days: 1, run_time: '04:30', revision: 1 })).status;
  r.userAdminApi = (await api(u, 'GET', '/api/v1/admin/schedules')).status;
  check(r.userApi === 403 && r.userPut === 403 && r.userAdminApi === 403, '일반 사용자 API 403', [r.userApi, r.userPut, r.userAdminApi]);
  const repo = await api(u, 'GET', `/api/v1/projects/${failProject}/repo`);
  r.userRepoSchedule = repo.json?.data?.schedule;
  check(r.userRepoSchedule && !('consecutive_failures' in r.userRepoSchedule), '일반 사용자 요약에 실패 정보 없음', r.userRepoSchedule);
  await u.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  r.userKinds = await u.$$eval('#notif-kind option', (os) => os.map((o) => o.value));
  r.userPrefSwitch = await u.$('#notif-pref-schedule_failed') !== null;
  r.userNotifText = await u.$eval('[data-notif-list]', (n) => n.textContent);
  check(!r.userKinds.includes('schedule_failed') && !r.userPrefSwitch && !/주기 분석이/.test(r.userNotifText), '일반 사용자에게 schedule_failed 없음', [r.userKinds, r.userPrefSwitch]);
} finally {
  await user.browser.close();
}

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? `: ${csp.join(' | ')}` : ''}`);
if (problems.length) {
  console.log(`문제 ${problems.length}건:`);
  for (const p of problems) console.log(` - ${p}`);
  process.exit(1);
}
console.log('모든 점검 통과');
