// 백업·복원 브라우저 점검(P27B, 헤드리스 Chrome). 격리 서버·DB 사본(024 적용)·격리 워커·임시 백업 폴더에서만 쓴다(실제로 백업·복원한다).
//   PJG_PASSWORD=... node tools/check_backup_ui.mjs <점검 서버> <일반 사용자> [관리자]
// 확인(1280·1024px):
//   - 탭 요약·경고(아이콘 + 문구, 백업 없음), 목록 빈 안내, 하위 메뉴 "백업·복원" 현재 표시
//   - 지금 백업: 진행 표시(aria-live) → 완료 문구 → 목록 1줄(검증 통과), 경고 사라짐
//   - 키 내보내기 대화(재인증·암호문구 두 번·분리 보관 경고) → .pjgkey 내려받기(평문 키 없음)
//   - 다시 검증(토스트), 묶음 올리기 → "검증 필요"(복원 단추 꺼짐) → 검증 → 복원 가능
//   - 복원 대화 흐름(사전 검증·건수 나란히·손실 안내·설정 복원 기본 끔·키 상태·대기 방식·비밀번호·확인 문구) → 독립 진행 페이지(로그인 없이) → 완료
//   - 복원 뒤: 기존 세션 무효(로그인으로), 다시 로그인하면 목록에 복원 직전 백업(pre_restore)
//   - 일반 사용자: 화면 403, API 403
//   - P27E 자동 백업: 설정 관리의 선택 목록·켜기/끄기 칸, 방식에 맞지 않는 항목 흐리게(+이유), 설정 화면 디스크 추정, 매주로 저장 → 요약 주기 문구·보관 추정,
//     끄기 → 주기 항목 흐림·요약 "자동 백업 꺼짐"·정보 줄(오래됨 경고 없음), 매월 말일 → 요약 문구. 캡처 P27E_*.png
//   - 가로 스크롤 0, 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P27B_*.png
import path from 'node:path';
import { mkdtempSync, readdirSync, readFileSync, rmSync, copyFileSync } from 'node:fs';
import os from 'node:os';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, userLogin, adminLogin = 'admin'] = process.argv;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_backup_ui.mjs <점검 서버> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const DL = mkdtempSync(path.join(os.tmpdir(), 'pjg_bkui_'));
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

async function launch() {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (401|403|409|503)/.test(m.text())) return; // 의도한 오류(일반 사용자 403, 복원 중 503 등)
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  const cdp = await page.createCDPSession();
  await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: DL });
  return { browser, page };
}
async function login(page, who) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', who);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
}
const api = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: b === undefined ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body);
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.trim()).catch(() => null);
const shot = async (page, name, width = 1280) => {
  await page.setViewport({ width, height: 900 });
  await sleep(200);
  r[`scroll_${name}`] = await scrollX(page);
  check(r[`scroll_${name}`] <= 0, `가로 스크롤 ${name}`, r[`scroll_${name}`]);
  await page.screenshot({ path: path.join(OUT, `P27B_${name}.png`), fullPage: true });
};
const dialogShot = async (page, name) => {
  await sleep(200);
  const box = await page.$eval('dialog[open]', (n) => { const b = n.getBoundingClientRect(); return { x: Math.max(0, b.x - 4), y: Math.max(0, b.y - 4), width: b.width + 8, height: b.height + 8 }; });
  await page.screenshot({ path: path.join(OUT, `P27B_${name}.png`), clip: box });
};
const loaded = (page) => page.waitForFunction(() => document.querySelector('[data-admin-backups]')?.dataset.loaded === '1', { timeout: 15000 });
const reloadTab = async (page) => { await page.goto(`${BASE}/admin/backups`, { waitUntil: 'networkidle0' }); await loaded(page); };
const rows = (page) => page.$$eval('[data-rows] tr', (trs) => trs.map((tr) => ({ name: tr.dataset.bundle, verify: tr.dataset.verify, restoreDisabled: tr.querySelector('button[aria-label^="복원"]')?.disabled ?? null })));
const clickRow = async (page, name, labelStart) => {
  await page.$eval(`tr[data-bundle="${name}"] button[aria-label^="${labelStart}"]`, (b) => b.click());
};
const waitJobDone = async (page, timeout = 120000) => {
  await page.waitForFunction(() => document.querySelector('[data-job]')?.hidden === true && (document.querySelector('[data-job-done]')?.textContent ?? '') !== '', { timeout });
};

const admin = await launch();
const a = admin.page;
try {
  await login(a, adminLogin);
  // ---- 처음: 백업 없음 경고, 빈 목록 ----
  await reloadTab(a);
  r.subnavCurrent = await a.$eval('.subnav [aria-current="page"]', (n) => n.textContent.trim()).catch(() => null);
  check(r.subnavCurrent === '백업·복원', '하위 메뉴 현재', r.subnavCurrent);
  // 점검 설정은 일일 시각을 늦춰 둔다([backup] daily_at — 워커가 시작하자마자 오늘 몫을 만들지 않게). 그래도 묶음이 있으면 빈 상태 확인은 건너뜀
  r.initialItems = (await api(a, 'GET', '/api/v1/admin/backups')).json.data.items.length;
  r.warnings0 = await a.$$eval('[data-warnings] li', (ls) => ls.map((l) => [l.dataset.warning, l.querySelector('svg') !== null, l.textContent.trim()]));
  check(!r.warnings0.some(([id]) => id === 'dir_invalid'), '새 설치: 폴더가 없어도 오류로 보이지 않음(자동으로 만듦)', r.warnings0);
  if (r.initialItems === 0) {
    check(r.warnings0.some(([id, hasIcon]) => id === 'none' && hasIcon), '백업 없음 경고(아이콘 + 문구)', r.warnings0);
    r.emptyVisible = await a.$eval('[data-empty]', (n) => !n.hidden);
    check(r.emptyVisible, '빈 목록 안내', r.emptyVisible);
  }
  r.summary0 = await a.$$eval('[data-summary] dt', (ds) => ds.map((d) => d.textContent));
  check(['마지막 백업', '자동 백업', '백업 폴더', '디스크 여유', '마지막 리허설', '암호화 키', '워커'].every((k) => r.summary0.includes(k)), '요약 항목', r.summary0);
  r.changeDirHref = await a.$eval('[data-summary] a', (n) => n.getAttribute('href'));
  check(/\/admin\/config-settings#backup$/.test(r.changeDirHref ?? ''), '폴더 변경 링크 = 설정 관리 백업 탭(P27F)', r.changeDirHref);
  await shot(a, 'tab_empty_1280');

  // ---- 지금 백업: 진행 → 완료 ----
  await a.click('[data-backup-now]');
  await a.waitForFunction(() => document.querySelector('[data-job]') && !document.querySelector('[data-job]').hidden, { timeout: 10000 });
  r.jobText = await text(a, '[data-job-text]');
  r.jobLive = await a.$eval('[data-job-text]', (n) => n.getAttribute('aria-live'));
  check(/백업/.test(r.jobText ?? '') && r.jobLive === 'polite', '진행 표시(aria-live)', [r.jobText, r.jobLive]);
  await shot(a, 'backup_progress');
  await waitJobDone(a);
  r.jobDone = await text(a, '[data-job-done]');
  check(/백업 완료/.test(r.jobDone ?? ''), '완료 문구', r.jobDone);
  r.rows1 = await rows(a);
  check(r.rows1.length === r.initialItems + 1 && r.rows1[0].verify === 'ok', '목록에 새 묶음(맨 위)·검증 통과', r.rows1);
  const first = r.rows1[0]?.name;
  r.warnings1 = await a.$$eval('[data-warnings] li', (ls) => ls.map((l) => l.dataset.warning));
  check(!r.warnings1.includes('none'), '백업 없음 경고 사라짐', r.warnings1);
  await shot(a, 'tab_1280');
  await shot(a, 'tab_1024', 1024);
  await a.setViewport({ width: 1280, height: 900 });

  // ---- 키 내보내기 ----
  await a.click('[data-key-export]');
  await a.waitForSelector('dialog[open]');
  r.keyWarn = await a.$eval('dialog[open]', (d) => d.textContent.includes('서로 다른 곳에 보관'));
  check(r.keyWarn, '분리 보관 경고', r.keyWarn);
  const inputs = await a.$$('dialog[open] input[type=password]');
  check(inputs.length === 3, '비밀번호 + 암호문구 두 칸', inputs.length);
  await inputs[0].type(process.env.PJG_PASSWORD);
  await inputs[1].type('check passphrase 27b');
  await inputs[2].type('check passphrase 27b');
  await dialogShot(a, 'key_dialog');
  await a.click('dialog[open] button[type=submit]');
  await a.waitForFunction(() => !document.querySelector('dialog[open]'), { timeout: 15000 });
  await sleep(800);
  const keyFiles = readdirSync(DL).filter((f) => f.endsWith('.pjgkey'));
  check(keyFiles.length === 1, '.pjgkey 내려받음', keyFiles);
  if (keyFiles[0]) {
    const k = JSON.parse(readFileSync(path.join(DL, keyFiles[0]), 'utf8'));
    r.keyFile = { format: k.format, kdf: k.kdf?.alg, iterations: k.kdf?.iterations, fingerprint: k.fingerprint, hasPlain: JSON.stringify(k).includes('encryption_key') };
    check(!r.keyFile.hasPlain && r.keyFile.iterations >= 200000, '키 파일 형식(평문 키 없음)', r.keyFile);
  }

  // ---- 다시 검증 ----
  await clickRow(a, first, '검증');
  await a.waitForFunction(() => [...document.querySelectorAll('#toasts *')].some((n) => /검증을 통과/.test(n.textContent)), { timeout: 15000 });
  r.verifyToast = true;

  // ---- 묶음 올리기: 검증 필요 → 검증 → 복원 가능 ----
  const upName = first.replace(/-manual(-\d+)?\.pjgbak$/, '-manual-77.pjgbak').replace(/^pjguardian-\d{8}/, 'pjguardian-20200101');
  const upPath = path.join(DL, upName);
  const bkDir = (await api(a, 'GET', '/api/v1/admin/backups')).json.data.dir;
  copyFileSync(path.join(bkDir, first), upPath);
  const fileInput = await a.$('#ab-upload-file');
  await fileInput.uploadFile(upPath);
  await a.click('[data-upload-form] button[type=submit]');
  await a.waitForFunction((n) => document.querySelector(`tr[data-bundle="${n}"]`), { timeout: 30000 }, upName);
  r.uploaded = (await rows(a)).find((x) => x.name === upName);
  check(r.uploaded?.verify === 'needs_verify' && r.uploaded?.restoreDisabled === true, '올린 묶음은 검증 필요·복원 불가', r.uploaded);
  await shot(a, 'uploaded_needs_verify');
  await clickRow(a, upName, '검증');
  await a.waitForFunction((n) => document.querySelector(`tr[data-bundle="${n}"]`)?.dataset.verify === 'ok', { timeout: 15000 }, upName);
  r.uploadedAfter = (await rows(a)).find((x) => x.name === upName);
  check(r.uploadedAfter?.restoreDisabled === false, '검증 뒤 복원 가능', r.uploadedAfter);

  // ---- 백업 뒤 변경(손실 안내에 보이게): 사용자 하나 만들기 ----
  const mk = await api(a, 'POST', '/api/v1/users', { login_id: 'p27b_later', display_name: '백업 뒤 사용자', role: 'user' });
  check(mk.status === 201 || mk.status === 200, '백업 뒤 사용자 생성', mk.status);

  // ---- 복원 대화 흐름 ----
  await clickRow(a, first, '복원');
  await a.waitForSelector('dialog[open] .restore-flow', { timeout: 30000 });
  r.flow = await a.$eval('dialog[open]', (d) => ({
    sections: [...d.querySelectorAll('.restore-flow__section h3')].map((h) => h.textContent),
    danger: d.querySelector('.restore-flow__danger')?.textContent ?? '',
    counts: [...d.querySelectorAll('[data-count]')].map((tr) => [tr.dataset.count, ...[...tr.querySelectorAll('td')].map((td) => td.textContent)]),
    loss: d.querySelector('[data-loss]')?.textContent ?? '',
    withConfig: d.querySelector('[data-with-config]')?.checked ?? null,
    hasDangerIcon: d.classList.contains('dialog--danger'),
  }));
  check(r.flow.sections.length >= 5, '흐름 단계 제목', r.flow.sections);
  check(r.flow.counts.length === 4 && r.flow.counts.find((c) => c[0] === 'users')?.[1] !== r.flow.counts.find((c) => c[0] === 'users')?.[2], '건수 나란히(사용자 수 다름)', r.flow.counts);
  check(/계정 변경 1건/.test(r.flow.loss), '이후 변경 손실 안내', r.flow.loss);
  check(r.flow.withConfig === false || r.flow.withConfig === null, '설정 복원 기본 끔', r.flow.withConfig);
  check(r.flow.hasDangerIcon && r.flow.danger.length > 0, '위험 안내(색 + 아이콘 + 문구)', r.flow);
  await dialogShot(a, 'restore_dialog');
  // 확인 문구가 틀리면 대화상자 안 오류
  await a.type('dialog[open] input[name=password]', process.env.PJG_PASSWORD);
  await a.type('dialog[open] input[name=confirm]', '복구');
  await a.click('dialog[open] button[type=submit]');
  await a.waitForFunction(() => { const e = document.querySelector('dialog[open] .field__error[role=alert]'); return e && !e.hidden; });
  r.confirmError = await text(a, 'dialog[open] .field__error[role=alert]');
  check(/복원/.test(r.confirmError ?? ''), '확인 문구 오류', r.confirmError);
  await a.$eval('dialog[open] input[name=confirm]', (n) => { n.value = ''; });
  await a.type('dialog[open] input[name=confirm]', '복원');
  const pw = await a.$eval('dialog[open] input[name=password]', (n) => n.value);
  if (pw === '') await a.type('dialog[open] input[name=password]', process.env.PJG_PASSWORD);
  await Promise.all([a.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }), a.click('dialog[open] button[type=submit]')]);
  r.statusUrl = new URL(a.url()).pathname;
  check(r.statusUrl.endsWith('/restore-status'), '진행 상태 페이지로 이동', r.statusUrl);
  // 진행 중 다른 API는 503(유지보수) — 빠르면 이미 끝났을 수 있어 기록만
  r.during = await api(a, 'GET', '/api/v1/projects');
  await a.waitForFunction(() => ['succeeded', 'failed', 'rolled_back', 'rollback_failed'].includes(document.querySelector('[data-restore-status]')?.querySelector('[data-restore-status]')?.dataset.restoreStatus ?? '')
    && document.querySelector('[data-maintenance]')?.hidden === true, { timeout: 180000 });
  r.restoreState = await a.$eval('[data-state] [data-restore-status]', (n) => n.dataset.restoreStatus);
  check(r.restoreState === 'succeeded', '복원 완료', r.restoreState);
  r.steps = await a.$$eval('[data-steps] li', (ls) => ls.map((l) => [l.dataset.step, l.dataset.stepStatus]));
  r.result = await a.$$eval('[data-result-list] li', (ls) => ls.map((l) => l.textContent));
  check(r.result.some((t) => /복원 직전 백업/.test(t)) && r.result.some((t) => /다시 로그인/.test(t)), '결과(직전 백업·세션)', r.result);
  check(await a.$eval('[data-login]', (n) => !n.hidden), '로그인 단추', true);
  await shot(a, 'restore_status_1280');
  await shot(a, 'restore_status_1024', 1024);
  await a.setViewport({ width: 1280, height: 900 });

  // ---- 복원 뒤: 기존 세션 무효 → 다시 로그인 → 목록에 pre_restore, 백업 뒤 만든 사용자 없음 ----
  await a.goto(`${BASE}/admin/backups`, { waitUntil: 'networkidle0' });
  r.afterUrl = new URL(a.url()).pathname;
  check(r.afterUrl.endsWith('/login'), '복원 뒤 세션 무효', r.afterUrl);
  await login(a, adminLogin);
  await reloadTab(a);
  r.kinds = await a.$$eval('[data-rows] tr td:nth-child(2)', (tds) => tds.map((t) => t.textContent));
  check(r.kinds.includes('복원 직전'), '목록에 복원 직전 백업', r.kinds);
  const users = await api(a, 'GET', '/api/v1/users', undefined);
  r.laterUser = JSON.stringify(users.json ?? {}).includes('p27b_later');
  check(!r.laterUser, '백업 뒤 만든 사용자는 사라짐', r.laterUser);
  await shot(a, 'tab_after_restore');
} catch (e) {
  problems.push(`예외: ${e.stack ?? e}`);
  await a.screenshot({ path: path.join(OUT, 'P27B_error.png'), fullPage: true }).catch(() => {});
} finally {
  await admin.browser.close();
}

// ---- P27E 자동 백업 켜기/끄기·주기: 설정 관리 → 백업·복원 요약 ----
const p27e = await launch();
const c = p27e.page;
const item = (k) => `[data-item="${k}"]`;
// P27E 캡처는 P27E_ 이름으로(같은 검사: 가로 스크롤 0)
const shotE = async (page, name, width = 1280) => {
  await page.setViewport({ width, height: 900 });
  await sleep(200);
  r[`scroll_p27e_${name}`] = await scrollX(page);
  check(r[`scroll_p27e_${name}`] <= 0, `가로 스크롤 P27E ${name}`, r[`scroll_p27e_${name}`]);
  await page.screenshot({ path: path.join(OUT, `P27E_${name}.png`), fullPage: true });
};
const saveSettings = async () => {
  await c.click('[data-preview]');
  await c.waitForSelector('dialog[open] .btn--primary');
  await c.click('dialog[open] .btn--primary');
  await c.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-bar] [data-status]')?.textContent ?? ''), { timeout: 10000 });
};
const openSettings = async () => {
  await c.goto(`${BASE}/admin/config-settings#backup`, { waitUntil: 'networkidle0' });
  await c.waitForSelector(item('backup.schedule_mode'));
};
const inactive = (k) => c.$eval(item(k), (row) => ({ dim: row.classList.contains('config-item--inactive'), disabled: row.querySelector('[data-input]').disabled,
  note: row.querySelector('[data-inactive]').hidden ? '' : row.querySelector('[data-inactive]').textContent }));
try {
  await login(c, adminLogin);
  await openSettings();
  r.p27eControls = await c.evaluate(() => ({
    mode: document.querySelector('[data-input="backup.schedule_mode"]')?.tagName,
    modeOptions: [...document.querySelectorAll('[data-input="backup.schedule_mode"] option')].map((o) => o.textContent),
    enabled: document.querySelector('[data-input="backup.enabled"]')?.type,
    monthlyLast: [...document.querySelectorAll('[data-input="backup.monthly_day"] option')].pop()?.textContent,
  }));
  check(r.p27eControls.mode === 'SELECT' && r.p27eControls.enabled === 'checkbox' && r.p27eControls.modeOptions.length === 3 && r.p27eControls.monthlyLast === '말일',
    '선택 목록·켜기/끄기 칸', r.p27eControls);
  r.weeklyDim = await inactive('backup.weekly_day');
  check(r.weeklyDim.dim && r.weeklyDim.disabled && /매주/.test(r.weeklyDim.note), '매일일 때 요일 흐리게 + 이유', r.weeklyDim);
  r.estimate = await c.$eval('[data-backup-estimate]', (n) => n.textContent).catch(() => null);
  check(/보관 시 약/.test(r.estimate ?? ''), '설정 화면 디스크 추정', r.estimate);
  // 매주 수요일로 바꾸면 요일이 살아나고 날짜는 흐림
  await c.select('[data-input="backup.schedule_mode"]', 'weekly');
  r.weeklyAfter = [await inactive('backup.weekly_day'), await inactive('backup.monthly_day')];
  check(!r.weeklyAfter[0].dim && r.weeklyAfter[1].dim, '방식 바꾸면 흐리게가 따라감', r.weeklyAfter);
  await c.select('[data-input="backup.weekly_day"]', 'wed');
  await shotE(c, 'settings_weekly_1280');
  await saveSettings();
  await reloadTab(c);
  r.schedWeekly = await c.$eval('[data-summary] [data-schedule]', (n) => n.textContent).catch(() => null);
  check(/^매주 수요일 \d\d:\d\d$/.test(r.schedWeekly ?? ''), '요약 주기 문구(매주)', r.schedWeekly);
  r.estimateTab = await c.$$eval('[data-summary] dt', (ds) => ds.map((d) => d.textContent)).then((x) => x.includes('보관 추정'));
  check(r.estimateTab, '요약 보관 추정 줄', r.estimateTab);
  await shotE(c, 'tab_weekly_1280');
  // 끄기 → 주기 항목 모두 흐림, 요약은 "자동 백업 꺼짐" + 정보 줄(경고 아님)
  await openSettings();
  await c.click('[data-input="backup.enabled"]');
  r.offDim = await Promise.all(['backup.schedule_mode', 'backup.daily_at', 'backup.weekly_day', 'backup.monthly_day'].map(inactive));
  check(r.offDim.every((x) => x.dim && x.disabled && /꺼져/.test(x.note)), '끄면 주기 항목 흐리게', r.offDim);
  r.offText = await c.$eval(`${item('backup.enabled')} .config-item__check-text`, (n) => n.textContent);
  check(r.offText === '꺼짐', '켜기/끄기 글자', r.offText);
  await shotE(c, 'settings_off_1280');
  await shotE(c, 'settings_off_1024', 1024);
  await c.setViewport({ width: 1280, height: 900 });
  await saveSettings();
  await reloadTab(c);
  r.offSummary = await c.$$eval('[data-summary] dd', (ds) => ds.map((d) => d.textContent)).then((x) => x.some((t) => /자동 백업 꺼짐/.test(t)));
  r.offWarnings = await c.$$eval('[data-warnings] li', (ls) => ls.map((l) => [l.dataset.warning, l.className]));
  check(r.offSummary && r.offWarnings.some(([id, cls]) => id === 'auto_off' && /notice--info/.test(cls)) && !r.offWarnings.some(([id]) => id === 'stale' || id === 'none'),
    '꺼짐: 요약 "자동 백업 꺼짐"·정보 줄·오래됨 경고 없음', [r.offSummary, r.offWarnings]);
  await shotE(c, 'tab_off_1280');
  await shotE(c, 'tab_off_1024', 1024);
  await c.setViewport({ width: 1280, height: 900 });
  // 다시 켜고 매월 말일로
  await openSettings();
  await c.click('[data-input="backup.enabled"]');
  await c.select('[data-input="backup.schedule_mode"]', 'monthly');
  await c.select('[data-input="backup.monthly_day"]', 'last');
  await saveSettings();
  await reloadTab(c);
  r.schedMonthly = await c.$eval('[data-summary] [data-schedule]', (n) => n.textContent).catch(() => null);
  check(/^매월 말일 \d\d:\d\d$/.test(r.schedMonthly ?? ''), '요약 주기 문구(매월 말일)', r.schedMonthly);
  r.monthlyWarnings = await c.$$eval('[data-warnings] li', (ls) => ls.map((l) => l.dataset.warning));
  check(!r.monthlyWarnings.includes('auto_off'), '켜면 정보 줄 사라짐', r.monthlyWarnings);
  await shotE(c, 'tab_monthly_1280');
  // 원래대로(매일·켜짐) — 점검 설정을 남기지 않게
  await openSettings();
  await c.select('[data-input="backup.schedule_mode"]', 'daily');
  await c.select('[data-input="backup.weekly_day"]', 'sun').catch(() => {});
  await saveSettings();
} catch (e) {
  problems.push(`예외(P27E): ${e.stack ?? e}`);
  await c.screenshot({ path: path.join(OUT, 'P27B_error_p27e.png'), fullPage: true }).catch(() => {});
} finally {
  await p27e.browser.close();
}

// ---- 일반 사용자: 403 ----
const user = await launch();
try {
  await login(user.page, userLogin);
  const res = await user.page.goto(`${BASE}/admin/backups`, { waitUntil: 'networkidle0' });
  r.userPage = res.status();
  r.userApi = (await api(user.page, 'GET', '/api/v1/admin/backups')).status;
  check(r.userPage === 403 && r.userApi === 403, '일반 사용자 403', [r.userPage, r.userApi]);
  r.userMenu = await user.page.$('[data-nav="admin.tools"]') !== null;
  check(!r.userMenu, '일반 사용자 메뉴 없음', r.userMenu);
} catch (e) {
  problems.push(`예외(일반 사용자): ${e.stack ?? e}`);
} finally {
  await user.browser.close();
}
rmSync(DL, { recursive: true, force: true });

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? ': ' + csp.join(' | ') : ''}`);
if (problems.length) {
  console.log(`문제 ${problems.length}건:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
console.log('백업·복원 화면 점검 통과');
