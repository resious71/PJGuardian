// 관리자 장애 이메일 화면 브라우저 점검(P30B, 헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo — 임시 폴더·새 DB·가짜 계정)을 별도 포트에 띄우고
// 격리 워커와 **로컬 가짜 SMTP 수신기**(tests/Support/fake_smtp_server.php, 127.0.0.1 — 외부 연결 없음, 평문 + AUTH LOGIN)를 돌린 뒤 점검하고,
// 끝나면 서버·워커·수신기를 멈추고 폴더를 지운다(실제 DB·설정·사용자 워커·실제 메일 서버와 무관).
//   node tools/check_admin_mail_ui.mjs [--port=8094] [--keep] [--no-shots]
// 흐름: 일반 사용자 — 상태 API·설정 화면 403. admin — 설정 관리 "관리자 이메일" 탭(발송 상태 줄·평문 경고·인증 없음이면 사용자 이름 흐리게),
//   SMTP 비밀번호 저장(값은 화면·API·HTML에 다시 없음) → 상태 바뀜, 저장 전 변경이 있으면 시험 단추 막힘·되돌리면 풀림,
//   시험 메일(받는 사람 비우면 내 계정 이메일) → 수신기에 한 통(AUTH 성공·[PJGuardian] 제목), 틀린 비밀번호 → 실패 사유 "인증 실패", 분당 3회 → 4번째 안내,
//   사용 켜기 + 디스크 경고 기준 1%를 화면에서 저장 → 워커가 다음 판정에서 경고 메일 한 통(같은 사건은 간격 안에 다시 안 보냄),
//   모니터링 앱 정보 카드 "관리자 이메일: 켜짐" 링크 → 설정 탭, 비밀번호 지우기(확인 대화상자) → "설정 필요",
//   영어·다크 × 1280/1024 탭 이름·한글 없음·가로 스크롤 0, 콘솔 오류·CSP 위반 0. 캡처 P30B_mail_settings_1280·P30B_mail_test_result.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { createServer } from 'node:net';
import { mkdirSync, rmSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8094);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const SHOTS = !opt('no-shots');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `mail_check_${randomBytes(4).toString('hex')}`);
const PW = `Demo-${randomBytes(9).toString('base64url')}1!`;
const SMTP_PASS = `Smtp-${randomBytes(9).toString('base64url')}`;
const BASE = `http://127.0.0.1:${PORT}`;
const INI = path.join(work, 'config.ini');
const MAILS = path.join(work, 'mails.jsonl');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
let server = null;
let worker = null;
let smtp = null;
let browser = null;
const stopWorker = () => {
  try { execFileSync(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), '--stop', `--config=${INI}`], { stdio: 'ignore' }); } catch { /* 이미 멈춤 */ }
};
process.on('exit', () => {
  for (const p of [server, worker, smtp]) { try { p?.kill(); } catch { /* 이미 끝남 */ } }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`점검 폴더를 지우지 못함: ${e.code}`); } }
});
const freePort = () => new Promise((res) => { const s = createServer(); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => res(port)); }); });

/** 설치본 config.ini의 [섹션] 키 값을 바꾼다(없으면 섹션 끝에 더함) */
function setIni(section, key, value) {
  const lines = readFileSync(INI, 'utf8').split('\n');
  const start = lines.findIndex((l) => l.trim() === `[${section}]`);
  if (start < 0) {
    lines.push(`[${section}]`, `${key} = "${value}"`, '');
  } else {
    let end = lines.findIndex((l, i) => i > start && /^\[/.test(l.trim()));
    if (end < 0) end = lines.length;
    const at = lines.findIndex((l, i) => i > start && i < end && new RegExp(`^${key}\\s*=`).test(l.trim()));
    if (at >= 0) lines[at] = `${key} = "${value}"`;
    else lines.splice(end, 0, `${key} = "${value}"`);
  }
  writeFileSync(INI, lines.join('\n'));
}
const mails = () => (existsSync(MAILS) ? readFileSync(MAILS, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []);
/** 본문(base64 한 부분) 풀기 */
const bodyOf = (m) => { const i = m.data.search(/\r?\n\r?\n/); return Buffer.from(m.data.slice(i).replace(/\s+/g, ''), 'base64').toString('utf8'); };
const subjectOf = (m) => {
  const raw = /^Subject: (.+(?:\r?\n[ \t].+)*)/m.exec(m.data)?.[1] ?? '';
  return raw.replace(/\r?\n[ \t]/g, ' ').replace(/=\?utf-8\?B\?([^?]+)\?=\s*/gi, (_, b) => Buffer.from(b, 'base64').toString('utf8')).trim();
};

mkdirSync(work, { recursive: true });
execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'pipe', 'inherit'] });
const SMTP_PORT = await freePort();
for (const [k, v] of Object.entries({ enabled: 'false', smtp_host: '127.0.0.1', smtp_port: String(SMTP_PORT), smtp_tls: 'none', smtp_auth: 'login', smtp_user: 'mailer',
  from_address: 'pjguardian@example.test', recipients: 'ops@example.test', resend_interval: '10m', language: 'ko' })) setIni('alert_mail', k, v);
setIni('monitoring', 'cache', '5s');
smtp = spawn(PHP, [path.join(ROOT, 'tests/Support/fake_smtp_server.php'), `--port=${SMTP_PORT}`, '--mode=plain', `--out=${MAILS}`, `--ready=${work}/smtp.ready`,
  '--user=mailer', `--pass=${SMTP_PASS}`], { stdio: 'ignore' });
server = spawn(PHP, ['-d', 'extension=intl', '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: INI }, stdio: 'ignore' });
worker = spawn(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), `--config=${INI}`], { stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}
for (let i = 0; i < 50 && !(existsSync(`${work}/smtp.ready`) && existsSync(path.join(work, 'work/worker/heartbeat.json'))); i += 1) await sleep(200);

async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of (403|429)/.test(m.text())) problems.push(`console.${m.type()}(${login}): ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror(${login}): ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.emulateTimezone('Asia/Seoul');
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const prefs = (page, language, theme) => page.evaluate(async (l, th) => (await fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: l, theme: th }) })).status, language, theme);
const api = (page, url) => page.evaluate(async (u) => { const res = await fetch(u, { credentials: 'same-origin' }); return { status: res.status, text: await res.text() }; }, url);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const item = (key) => `[data-item="${key}"]`;
const openMailTab = async (page) => {
  await page.goto('about:blank'); // 같은 주소#해시로 가면 다시 읽지 않으므로
  await page.goto(`${BASE}/admin/config-settings#admin_mail`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('[data-mail-status] p');
  await sleep(200);
};
const testState = (page) => page.evaluate(() => {
  const run = document.querySelector('[data-mail-test-run]');
  const why = document.querySelector('[data-mail-test-why]');
  return { disabled: run?.disabled ?? null, why: why && !why.hidden ? why.textContent : '' };
});
const setInput = async (page, key, value) => {
  const sel = `${item(key)} [data-input]`;
  await page.$eval(sel, (n, v) => {
    n.value = v;
    n.dispatchEvent(new Event(n.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
  }, value);
};
const runTest = async (page, to = '') => {
  await page.$eval('#cs-mail-test-to', (n, v) => { n.value = v; }, to);
  await Promise.all([page.waitForResponse((res) => res.url().includes('/api/v1/admin/alert-mail/test')), page.click('[data-mail-test-run]')]);
  await sleep(300);
  return page.evaluate(() => ({
    result: document.querySelector('[data-mail-test-result]')?.textContent.trim() ?? '', state: document.querySelector('[data-mail-test-result]')?.dataset.state ?? '',
    code: document.querySelector('[data-mail-test-result]')?.dataset.code ?? '',
    error: (() => { const e = document.querySelector('[data-mail-test] .field__error'); return e && !e.hidden ? e.textContent : ''; })(),
  }));
};
const savePassword = async (page, value) => {
  await page.type('#cs-mail-password', value);
  await Promise.all([page.waitForResponse((res) => res.url().includes('/api/v1/admin/alert-mail') && res.request().method() === 'GET'), page.click('[data-mail-secret] [data-secret-save]')]);
  await sleep(300);
};

try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--no-sandbox'] });

  // 1. 일반 사용자: API·화면 403
  const alex = await session('alex');
  r.user = [(await api(alex, '/api/v1/admin/alert-mail')).status, (await alex.goto(`${BASE}/admin/config-settings`)).status()];
  check(r.user[0] === 403 && r.user[1] === 403, '일반 사용자 403', r.user);

  // 2. admin: 탭·발송 상태·평문 경고
  const a = await session('demo-admin');
  await openMailTab(a);
  r.tab = await a.evaluate(() => ({
    selected: document.querySelector('[role="tab"][aria-selected="true"]')?.dataset.tab,
    name: document.querySelector('[role="tab"][data-tab="admin_mail"] .config-tab__name')?.textContent,
    items: document.querySelectorAll('[role="tabpanel"]:not([hidden]) [data-item]').length,
    plain: !document.querySelector('[data-mail-plaintext]').hidden,
    status: document.querySelector('[data-mail-status]').textContent.replace(/\s+/g, ' ').trim(),
    secret: document.querySelector('[data-mail-secret] [data-secret-state]')?.dataset.secretState,
  }));
  r.test0 = await testState(a);
  check(r.tab.selected === 'admin_mail' && r.tab.name === '관리자 이메일' && r.tab.items === 13 && r.tab.plain && /꺼짐/.test(r.tab.status) && r.tab.secret === 'none',
    '관리자 이메일 탭(13항목·평문 경고·꺼짐·비밀번호 없음)', r.tab);
  check(r.test0.disabled === true && /SMTP 비밀번호/.test(r.test0.why), '비밀번호 없으면 시험 막힘·이유', r.test0);
  await setInput(a, 'alert_mail.smtp_auth', 'none');
  r.inactive = await a.$eval(item('alert_mail.smtp_user'), (n) => n.classList.contains('config-item__inactive') || n.classList.contains('config-item--inactive'));
  await setInput(a, 'alert_mail.smtp_auth', 'login');
  await setInput(a, 'alert_mail.smtp_tls', 'starttls');
  r.plainHidden = await a.$eval('[data-mail-plaintext]', (n) => n.hidden);
  await setInput(a, 'alert_mail.smtp_tls', 'none');
  check(r.inactive && r.plainHidden, '인증 없음 → 사용자 이름 흐리게, STARTTLS면 평문 경고 숨김', [r.inactive, r.plainHidden]);

  // 3. 비밀번호 저장 → 상태, 값은 어디에도 다시 없음
  await savePassword(a, SMTP_PASS);
  r.afterPw = await a.evaluate(() => ({ state: document.querySelector('[data-mail-secret] [data-secret-state]')?.dataset.secretState, status: document.querySelector('[data-mail-status]').textContent,
    input: document.querySelector('#cs-mail-password').value }));
  const st = await api(a, '/api/v1/admin/alert-mail');
  r.leak = [st.text.includes(SMTP_PASS), (await a.content()).includes(SMTP_PASS)];
  check(r.afterPw.state === 'set' && /사용을 켜고 저장/.test(r.afterPw.status) && r.afterPw.input === '' && !r.leak[0] && !r.leak[1], '비밀번호 저장 → 저장됨·준비됨, 값 없음', [r.afterPw, r.leak]);

  // 4. 저장 전 변경 → 시험 막힘, 되돌리면 풀림
  await setInput(a, 'alert_mail.smtp_port', '2526');
  r.unsaved = await testState(a);
  await setInput(a, 'alert_mail.smtp_port', String(SMTP_PORT));
  r.reverted = await testState(a);
  check(r.unsaved.disabled && /먼저 저장/.test(r.unsaved.why) && r.reverted.disabled === false, '저장 전 변경 → 막힘·되돌리면 풀림', [r.unsaved, r.reverted]);

  // 5. 시험 메일: 성공(받는 사람 비우면 내 이메일) → 수신기 한 통
  r.send1 = await runTest(a, '');
  const m1 = mails();
  r.m1 = m1.map((m) => ({ rcpt: m.rcpt, auth: m.auth, subject: subjectOf(m), pass: m.data.includes(SMTP_PASS) || bodyOf(m).includes(SMTP_PASS) }));
  check(r.send1.state === 'ok' && /보냈습니다/.test(r.send1.result) && m1.length === 1 && m1[0].auth && m1[0].rcpt[0] === 'demo-admin@example.invalid'
    && r.m1[0].subject === '[PJGuardian] 시험 메일' && !r.m1[0].pass, '시험 메일 성공 → 수신기 한 통(AUTH·제목·내 이메일)', [r.send1, r.m1]);
  if (SHOTS) {
    await a.$eval('[data-mail-test]', (n) => n.scrollIntoView({ block: 'center' }));
    const clip = await a.$eval('[data-mail-test]', (n) => { const b = n.getBoundingClientRect(); return { x: b.x - 8, y: b.y + window.scrollY - 8, width: b.width + 16, height: b.height + 16 }; });
    await a.screenshot({ path: path.join(OUT, 'P30B_mail_test_result.png'), clip });
  }
  // 틀린 비밀번호 → 인증 실패 사유
  await savePassword(a, 'wrong-password');
  r.send2 = await runTest(a, 'ops@example.test');
  check(r.send2.state === 'fail' && r.send2.code === 'auth' && /인증 실패/.test(r.send2.result) && mails().length === 1, '틀린 비밀번호 → 인증 실패 안내', r.send2);
  await savePassword(a, SMTP_PASS);
  r.send3 = await runTest(a, 'ops@example.test');
  r.send4 = await runTest(a, 'ops@example.test');
  check(r.send3.state === 'ok' && /1분에 3번/.test(r.send4.error), '분당 3회 → 4번째 안내', [r.send3, r.send4]);

  // 6. 사용 켜기 + 디스크 경고 기준 1%(경고 흉내)를 화면에서 저장 → 워커가 경고 메일 한 통
  const before = mails().length;
  await a.$eval(`${item('alert_mail.enabled')} [data-input]`, (n) => n.click());
  await a.evaluate(() => {
    const panel = document.querySelector('[data-item="disk.warn_percent"]').closest('[role="tabpanel"]');
    document.querySelector(`[role="tab"][aria-controls="${panel.id}"]`)?.click();
  });
  await setInput(a, 'disk.warn_percent', '1');
  await a.click('[data-preview]');
  await a.waitForSelector('dialog[open] .btn--primary');
  await a.click('dialog[open] .btn--primary');
  await a.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-bar] [data-status]')?.textContent ?? ''), { timeout: 10000 });
  await openMailTab(a);
  r.on = await text(a, '[data-mail-status]');
  check(/켜짐/.test(r.on ?? '') && /1명/.test(r.on ?? ''), '저장 뒤 발송 상태 켜짐(받는 사람 1명)', r.on);
  if (SHOTS) await a.screenshot({ path: path.join(OUT, 'P30B_mail_settings_1280.png'), fullPage: true });
  let alert = null;
  for (let i = 0; i < 60 && alert === null; i += 1) {
    await sleep(1000);
    alert = mails().slice(before).find((m) => /^\[PJGuardian\] (경고|오류)/.test(subjectOf(m))) ?? null;
  }
  r.alert = alert ? { subject: subjectOf(alert), rcpt: alert.rcpt, monitoring: bodyOf(alert).includes('/admin/monitoring'), body: bodyOf(alert).split('\n').slice(0, 6) } : null;
  check(r.alert !== null && r.alert.rcpt[0] === 'ops@example.test' && r.alert.monitoring, '워커가 판정에서 경고 메일 한 통', r.alert);
  const count = mails().length;
  await sleep(15000);
  r.noRepeat = mails().length - count;
  check(r.noRepeat === 0, '같은 사건은 간격(10분) 안에 다시 안 보냄', r.noRepeat);

  // 7. 모니터링 앱 카드 → 설정 탭
  await a.goto(`${BASE}/admin/monitoring`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-card="app"] [data-focus-key="alert-mail"]', { timeout: 20000 });
  r.card = await text(a, '[data-card="app"] [data-focus-key="alert-mail"]');
  await Promise.all([a.waitForNavigation({ waitUntil: 'networkidle0' }), a.click('[data-card="app"] [data-focus-key="alert-mail"]')]);
  await a.waitForSelector('[data-mail-status] p');
  r.cardNav = [new URL(a.url()).pathname + new URL(a.url()).hash, await a.$eval('[role="tab"][aria-selected="true"]', (n) => n.dataset.tab)];
  check(r.card === '켜짐' && r.cardNav[0] === '/admin/config-settings#admin_mail' && r.cardNav[1] === 'admin_mail', '모니터링 앱 카드 관리자 이메일 → 설정 탭', [r.card, r.cardNav]);

  // 8. 비밀번호 지우기(확인 대화상자) → 설정 필요
  await a.click('[data-mail-secret] [data-secret-delete]');
  await a.waitForSelector('dialog[open] button[value="ok"]');
  await Promise.all([a.waitForResponse((res) => res.url().includes('/api/v1/admin/alert-mail') && res.request().method() === 'GET'), a.click('dialog[open] button[value="ok"]')]);
  await sleep(300);
  r.deleted = [await a.$eval('[data-mail-secret] [data-secret-state]', (n) => n.dataset.secretState), await text(a, '[data-mail-status]')];
  check(r.deleted[0] === 'none' && /설정이 필요/.test(r.deleted[1] ?? ''), '비밀번호 지우기 → 없음·설정 필요', r.deleted);

  // 9. 영어·다크 × 1280/1024
  r.layout = [];
  for (const [lang, theme] of [['en', 'dark'], ['ko', 'light']]) {
    const ps = await prefs(a, lang, theme);
    check(ps === 200, `언어 ${lang} ${theme}`, ps);
    for (const w of [1280, 1024]) {
      await a.setViewport({ width: w, height: 900 });
      await openMailTab(a);
      const v = await a.evaluate(() => ({
        name: document.querySelector('[role="tab"][data-tab="admin_mail"] .config-tab__name')?.textContent,
        hangul: /[가-힣]/.test(document.querySelector('[role="tabpanel"]:not([hidden])').textContent),
        scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
        theme: document.documentElement.dataset.theme ?? '',
      }));
      r.layout.push({ lang, theme, w, ...v });
      check(v.scroll && (lang === 'en' ? v.name === 'Admin email' && !v.hangul : v.name === '관리자 이메일'), `${lang}/${theme} ${w}`, v);
    }
  }
  check(csp.length === 0, 'CSP 위반 0', csp);
} catch (e) {
  problems.push(`예외: ${e.stack ?? e}`);
} finally {
  await browser?.close();
  stopWorker();
}
console.log(JSON.stringify(r, null, 1));
if (problems.length) {
  console.log(`문제 ${problems.length}건:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
console.log('관리자 이메일 화면 점검: 모두 통과');
process.exit(0);
