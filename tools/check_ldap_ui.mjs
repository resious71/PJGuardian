// LDAP/AD 로그인·승인 대기 브라우저 점검(P28B, 헤드리스 Chrome). 격리 서버(가짜 디렉터리를 끼운 점검 라우터·026 적용한 DB 사본)에서만 —
// 실제 LDAP/AD 서버에 연결하지 않는다. 실제로 계정을 만들고 바꾼다(사본 전용).
//   PJG_PASSWORD=<로컬 계정 비밀번호> PJG_DIR_PASSWORD=<가짜 디렉터리 비밀번호> node tools/check_ldap_ui.mjs <점검 서버> <일반 사용자> [관리자]
// 흐름: 디렉터리 첫 로그인 → 로그인 화면 "승인 대기" 안내(세션 없음) → 관리자 사용자 관리 "승인 대기 N건" → 승인 대기 보기(이름 확인 배지·승인·거절) →
//   승인(역할 선택 대화상자, 키보드) → 그 사람이 로그인(내 정보 인증 방식 LDAP·이름 수정 가능·비밀번호 화면 안내) → 거절 → 재로그인 "승인되지 않음" →
//   같은 ID의 로컬 계정은 디렉터리 비밀번호로 로그인되지 않음 → 인증 방식 변경(로컬 → LDAP, LDAP → 로컬 임시 비밀번호) →
//   설정 관리 LDAP/AD 탭(검색 계정 비밀번호 저장 — 값 다시 안 보임, 연결 시험 단계·가린 값) → 점검 화면 LDAP 항목 → 일반 사용자 403.
// 한국어·영어 × 라이트·다크 × 1280·1024 캡처(가로 스크롤 0), 콘솔 오류 0, CSP 위반 0. 출력·캡처에 비밀번호를 남기지 않는다. 캡처 P28B_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const [baseArg, userLogin, adminLogin = 'admin'] = args;
const PW = process.env.PJG_PASSWORD;
const DIR_PW = process.env.PJG_DIR_PASSWORD;
if (!userLogin || !PW || !DIR_PW) {
  console.error('사용법: PJG_PASSWORD=... PJG_DIR_PASSWORD=... node tools/check_ldap_ui.mjs <점검 서버> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
const secrets = [PW, DIR_PW];
const hide = (v) => { let s = JSON.stringify(v); for (const x of secrets) s = s.split(x).join('(hidden)'); return JSON.parse(s); };
let printed = false;
// 중간에 멈춰도(예외) 그때까지의 결과·문제를 남긴다
process.on('exit', () => { if (!printed) console.log(JSON.stringify(hide({ r, problems }), null, 1)); });

async function browser() {
  const b = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await b.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[0-9]|status of 50[03]/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  return { b, page };
}
async function loginForm(page, login, password) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page.evaluate(() => ({ path: location.pathname, account: document.querySelector('[data-account-state]')?.dataset.accountState ?? null,
    accountText: document.querySelector('[data-account-state]')?.textContent.trim() ?? null, error: document.querySelector('#login-error')?.textContent.trim() ?? null }));
}
const apiCall = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: b === null ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body ?? null);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
// allowScroll: 앱 최소 폭(1280px) 설계 화면(사용자 관리 표)은 1024px 가로 스크롤을 기록만
const shot = async (page, name, width = 1280, full = true, allowScroll = false) => {
  await page.setViewport({ width, height: 900 });
  await sleep(300);
  const s = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  r[`scroll_${name}`] = s;
  if (!allowScroll) check(s <= 0, `가로 스크롤 ${name}`, s);
  // 캡처 전 비밀번호 칸·임시 비밀번호 가림(화면 동작과 무관)
  await page.$$eval('.dialog code, .secret, [data-secret]', (ns) => { for (const n of ns) n.textContent = '••••••••••••'; }).catch(() => null);
  await page.screenshot({ path: path.join(OUT, `P28B_${name}.png`), fullPage: full });
  await page.setViewport({ width: 1280, height: 900 });
};
const rows = (page) => page.$$eval('#users-body tr', (trs) => trs.map((tr) => ({ login: tr.querySelector('code')?.textContent, approval: tr.dataset.approval ?? 'approved',
  cells: [...tr.cells].map((c) => c.textContent.replace(/\s+/g, ' ').trim()), buttons: [...tr.querySelectorAll('button')].map((b) => b.textContent.trim()) })));

// 1. 디렉터리 첫 로그인 → 승인 대기(세션 없음)
{
  const { b, page } = await browser();
  r.first = await loginForm(page, 'lee.dir', DIR_PW);
  r.firstSession = (await apiCall(page, 'GET', '/api/v1/auth/me')).status;
  check(r.first.path === '/login' && r.first.account === 'account_pending' && /관리자 승인 대기 중/.test(r.first.accountText ?? '') && r.first.error === null && r.firstSession === 401,
    '첫 로그인 = 승인 대기 안내(오류 아님, 세션 없음)', r.first);
  await shot(page, 'login_pending', 1280, false);
  r.wrong = await loginForm(page, 'lee.dir', 'wrong-password');
  r.unknown = await loginForm(page, 'nobody.dir', 'wrong-password');
  check(r.wrong.error !== null && r.wrong.error === r.unknown.error && r.wrong.account === null, '틀린 비밀번호·없는 ID 같은 문구', [r.wrong.error, r.unknown.error]);
  await loginForm(page, 'park.dir', DIR_PW); // 이름이 "admin"(다른 사용자 로그인 ID)
  // 같은 ID의 로컬 계정은 디렉터리 비밀번호로 로그인되지 않는다
  r.localWithDir = await loginForm(page, userLogin, DIR_PW);
  check(r.localWithDir.error !== null && r.localWithDir.path === '/login', '같은 ID 로컬 계정 = 디렉터리 로그인 불가', r.localWithDir.path);
  await b.close();
}

// 2. 관리자: 승인 대기 → 승인(역할 선택)·거절
{
  const { b, page } = await browser();
  await loginForm(page, adminLogin, PW);
  await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('#users-body tr');
  r.banner = await text(page, '#users-pending');
  r.authCol = (await rows(page)).find((x) => x.login === adminLogin)?.cells[5];
  check(/승인 대기 계정 2건/.test(r.banner ?? '') && r.authCol === '앱 계정', '승인 대기 배너·인증 방식 열', [r.banner, r.authCol]);
  await page.click('[data-show-pending]');
  await sleep(500);
  r.pending = await rows(page);
  const park = r.pending.find((x) => x.login === 'park.dir');
  check(r.pending.length === 2 && r.pending.every((x) => x.approval === 'pending') && /이름 확인/.test(park?.cells[1] ?? '') && park?.cells[5] === 'LDAP'
    && JSON.stringify(park?.buttons) === JSON.stringify(['승인', '거절', '삭제', '정보 수정']), '승인 대기 목록(이름 확인 배지·LDAP·승인/거절/삭제)', r.pending.map((x) => [x.login, x.cells[1], x.buttons]));
  await shot(page, 'users_pending_1280');
  await shot(page, 'users_pending_1024', 1024, true, true);
  // 승인: 대화상자에서 역할 user(기본) — 키보드(Enter)
  await page.click('tr[data-approval="pending"] button[data-action="approve"]');
  await page.waitForSelector('dialog[open] select');
  r.approveDialog = await text(page, 'dialog[open]');
  await page.screenshot({ path: path.join(OUT, 'P28B_approve_dialog.png') });
  // 키보드: 역할 칸에서 Tab으로 확인 단추까지 → Enter(선택 칸의 Enter는 폼을 제출하지 않는다)
  await page.focus('dialog[open] .btn--primary');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => ![...document.querySelectorAll('#users-body code')].some((c) => c.textContent === 'lee.dir'), { timeout: 10000 }).catch(() => null);
  r.afterApprove = (await rows(page)).map((x) => x.login);
  check(/처음 로그인한 계정입니다/.test(r.approveDialog ?? '') && JSON.stringify(r.afterApprove) === JSON.stringify(['park.dir']), '승인(키보드) 뒤 대기 목록에서 빠짐', [r.approveDialog, r.afterApprove]);
  // 거절
  await page.evaluate(() => [...document.querySelectorAll('#users-body tr')].find((tr) => tr.querySelector('code')?.textContent === 'park.dir')
    ?.querySelectorAll('button')[1].click());
  await page.waitForSelector('dialog[open]');
  r.rejectDialog = await text(page, 'dialog[open]');
  await page.click('dialog[open] .btn--danger');
  await page.waitForFunction(() => document.querySelectorAll('#users-body tr[data-approval="pending"]').length === 0, { timeout: 10000 }).catch(() => null);
  r.rejectedList = (await apiCall(page, 'GET', '/api/v1/users?approval=rejected')).json?.data?.map((u) => u.login_id);
  check(JSON.stringify(r.rejectedList) === JSON.stringify(['park.dir']), '거절 → 거절됨 보기', r.rejectedList);
  // 인증 방식 변경: 로컬 → LDAP(일반 사용자), 확인 대화상자의 안내
  await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('#users-body tr');
  const uid = (await apiCall(page, 'GET', `/api/v1/users?q=${userLogin}`)).json.data.find((u) => u.login_id === userLogin).id;
  await page.click(`tr[data-user-id="${uid}"] button:nth-last-of-type(1)`);
  await page.waitForSelector('dialog[open] select');
  r.authDialog = await text(page, 'dialog[open]');
  await page.screenshot({ path: path.join(OUT, 'P28B_auth_change_dialog.png') });
  await page.focus('dialog[open] .btn--primary');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => !document.querySelector('dialog[open]'), { timeout: 10000 }).catch(() => null);
  await sleep(500);
  r.afterAuth = (await apiCall(page, 'GET', `/api/v1/users/${uid}`)).json?.data?.auth_type;
  check(/앱 비밀번호 로그인이 사라집니다/.test(r.authDialog ?? '') && r.afterAuth === 'ldap', '인증 방식 변경 로컬 → LDAP', [r.authDialog, r.afterAuth]);
  await b.close();
}

// 3. 승인된 사람·전환된 사람의 로그인, 내 정보·비밀번호 화면
{
  const { b, page } = await browser();
  r.approvedLogin = await loginForm(page, 'lee.dir', DIR_PW);
  r.me = (await apiCall(page, 'GET', '/api/v1/auth/me')).json?.data?.user;
  check(r.approvedLogin.path !== '/login' && r.me?.login_id === 'lee.dir' && r.me?.auth_type === 'ldap', '승인 뒤 로그인', [r.approvedLogin.path, r.me?.login_id]);
  await page.goto(`${BASE}/account`, { waitUntil: 'networkidle0' });
  r.account = await page.evaluate(() => ({ save: !!document.getElementById('account-save'), readonly: document.querySelector('#account-name')?.hasAttribute('readonly'), auth: [...document.querySelectorAll('dd')].map((d) => d.textContent.trim()) }));
  check(r.account.save && !r.account.readonly && r.account.auth.includes('LDAP'), '내 정보: LDAP 계정도 이름 수정 가능', r.account);
  await shot(page, 'account_ldap', 1280, false);
  await page.goto(`${BASE}/password`, { waitUntil: 'networkidle0' });
  r.pwPage = await text(page, '#password-external');
  check(/조직의 디렉터리에서 변경하세요/.test(r.pwPage ?? ''), '비밀번호 화면: 디렉터리에서 변경 안내', r.pwPage);
  await b.close();
  const p2 = await browser();
  r.rejected = await loginForm(p2.page, 'park.dir', DIR_PW);
  check(r.rejected.account === 'account_rejected' && /승인되지 않았습니다/.test(r.rejected.accountText ?? ''), '거절된 사람 재로그인 = 승인되지 않음 안내', r.rejected);
  await shot(p2.page, 'login_rejected', 1280, false);
  r.switched = await loginForm(p2.page, userLogin, DIR_PW);
  check(r.switched.path !== '/login', '로컬 → LDAP으로 바꾼 계정은 디렉터리 비밀번호로 로그인(첫 로그인 연결)', r.switched.path);
  await p2.b.close();
}

// 4. 설정 관리 LDAP/AD 탭: 검색 계정 비밀번호·연결 시험, 점검 화면, 언어·테마
for (const [lang, theme] of [['ko', 'light'], ['en', 'dark']]) {
  const { b, page } = await browser();
  await loginForm(page, adminLogin, PW);
  await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: lang, theme });
  try {
    await page.goto(`${BASE}/admin/config-settings#auth_ldap`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('[data-ldap-secret] input');
    if (lang === 'ko') {
      r.tabs = await page.$$eval('[role="tab"]', (ts) => ts.map((t) => t.querySelector('.config-tab__name').textContent));
      check(r.tabs.slice(-2).join(',') === '계정 승인,LDAP/AD', '설정 탭 계정 승인·LDAP/AD(등록부에서 자동)', r.tabs);
      await page.type('[data-ldap-secret] input', 'Svc-Bind-Secret-9');
      await page.click('[data-secret-save]');
      await page.waitForSelector('[data-secret-state="set"]', { timeout: 5000 });
      r.secret = [await text(page, '[data-secret-state]'), await page.$eval('[data-ldap-secret] input', (n) => n.value)];
      const body = await page.evaluate(() => document.body.innerText);
      check(r.secret[1] === '' && !body.includes('Svc-Bind-Secret-9'), '검색 계정 비밀번호 저장(값 다시 안 보임)', r.secret[0]);
      await page.type('#cs-ldap-test-login', 'lee.dir');
      await page.click('[data-ldap-test-run]');
      await page.waitForSelector('[data-ldap-steps] li[data-step="user_search"]', { timeout: 10000 });
      r.steps = await page.$$eval('[data-ldap-steps] li', (ls) => ls.map((l) => [l.dataset.step, l.dataset.state]));
      r.detail = await text(page, '.ldap-test__detail');
      check(JSON.stringify(r.steps) === JSON.stringify([['connect', 'ok'], ['service_bind', 'ok'], ['base_dn', 'ok'], ['user_search', 'ok']]) && /CN=le\*\*\*/.test(r.detail ?? '') && /le\*\*\*/.test(r.detail ?? ''),
        '연결 시험 단계·가린 값', [r.steps, r.detail]);
    }
    await page.evaluate(() => document.querySelector('[data-ldap-test]')?.scrollIntoView());
    await shot(page, `settings_ldap_${lang}_${theme}_1280`);
    await shot(page, `settings_ldap_${lang}_${theme}_1024`, 1024);
    await page.goto(`${BASE}/admin/users?approval=rejected`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('#users-body tr');
    await shot(page, `users_rejected_${lang}_${theme}_1280`);
    if (lang === 'ko') {
      await page.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
      await sleep(500);
      r.check = await page.$$eval('tr', (trs) => trs.map((t) => t.textContent.replace(/\s+/g, ' ').trim()).filter((s) => s.startsWith('LDAP/AD')));
      check(r.check.length === 1 && /정상/.test(r.check[0]) && /연결·검색 계정 확인됨/.test(r.check[0]), '점검 화면 LDAP 항목', r.check);
    }
  } finally {
    await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: null, theme: null }).catch(() => null);
    await b.close();
  }
}

// 5. 로그인 화면 안내 영어·다크, 일반 사용자 403
{
  const { b, page } = await browser();
  await page.setCookie({ name: 'pjg_lang', value: 'en', url: BASE }, { name: 'pjg_theme', value: 'dark', url: BASE });
  r.rejectedEn = await loginForm(page, 'park.dir', DIR_PW);
  check(/not approved/.test(r.rejectedEn.accountText ?? ''), '영어 안내', r.rejectedEn.accountText);
  await shot(page, 'login_rejected_en_dark', 1280, false);
  await b.close();
}
{
  // 일반 사용자: 계정이 LDAP으로 바뀌었으므로 디렉터리 비밀번호로
  const { b, page } = await browser();
  await loginForm(page, userLogin, DIR_PW);
  r.user = [(await apiCall(page, 'GET', '/api/v1/admin/auth/ldap')).status, (await apiCall(page, 'POST', '/api/v1/users/1/approve', { role: 'user', updated_at: 'x' })).status,
    (await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' })).status()];
  check(JSON.stringify(r.user) === JSON.stringify([403, 403, 403]), '일반 사용자 403', r.user);
  await b.close();
}

printed = true;
console.log(JSON.stringify(hide(r), null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${hide(problems).join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
