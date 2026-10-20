// SSO(OIDC) 로그인 브라우저 점검(P28C, 헤드리스 Chrome). 격리 서버(가짜 IdP를 끼운 점검 라우터·027 적용한 DB 사본)에서만 —
// 실제 IdP에 연결하지 않는다. 브라우저가 가는 IdP 인가·로그아웃 엔드포인트(https://idp.example.test)는 요청 가로채기로 대신하고,
// 발급한 코드는 <격리 폴더>/idp/codes.json에 적는다(서버 쪽 가짜 IdP가 토큰 교환 때 읽음). 실제로 계정을 만들고 바꾼다(사본 전용).
//   PJG_PASSWORD=<로컬 계정 비밀번호> PJG_OIDC_SECRET=<격리 클라이언트 비밀> PJG_IDP_DIR=<격리 폴더>/idp node tools/check_oidc_ui.mjs <점검 서버> <일반 사용자> [관리자]
// 흐름: 로그인 화면 SSO 단추(설정 문구·키보드) → 가짜 IdP → 첫 로그인 "승인 대기"(세션 없음) → 관리자 승인 대기 행(OIDC)·승인 → 그 사람 로그인(세션·내 정보·비밀번호 안내) →
//   로그아웃(앱 세션만) → 재로그인(제공자 이름이 바뀌어도 그대로) → 잘못된 state·IdP 오류 → 안전한 오류 코드 → 같은 ID 로컬 계정 충돌 거부(로컬 로그인은 그대로) →
//   설정 관리 SSO(OIDC) 탭(클라이언트 비밀 — 값 다시 안 보임, 리다이렉트 URI 복사, 연결 시험 3단계) → 점검 화면 → 인증 방식 변경 대화상자에 OIDC →
//   제공자 로그아웃 켜기(폼 POST → IdP 로그아웃, CSP form-action 위반 없음) → 일반 사용자 403.
// 한국어·영어 × 라이트·다크 × 1280·1024 캡처(가로 스크롤 0, 사용자 관리는 앱 최소 폭 설계라 기록만), 콘솔 오류 0, CSP 위반 0. 출력·캡처에 비밀·코드·state를 남기지 않는다. 캡처 P28C_*.png
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const [baseArg, userLogin, adminLogin = 'admin'] = args;
const PW = process.env.PJG_PASSWORD;
const SECRET = process.env.PJG_OIDC_SECRET;
const IDP_DIR = process.env.PJG_IDP_DIR;
if (!userLogin || !PW || !SECRET || !IDP_DIR) {
  console.error('사용법: PJG_PASSWORD=... PJG_OIDC_SECRET=... PJG_IDP_DIR=<격리 폴더>/idp node tools/check_oidc_ui.mjs <점검 서버> <일반 사용자> [관리자]');
  process.exit(2);
}
const IDP = 'https://idp.example.test';
const CODES = path.join(IDP_DIR, 'codes.json');
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
const secrets = [PW, SECRET];
const hide = (v) => {
  let s = JSON.stringify(v);
  for (const x of secrets) s = s.split(x).join('(hidden)');
  s = s.replace(/(code|state|nonce|code_challenge)=[A-Za-z0-9_-]{8,}/g, '$1=(hidden)').replace(/code-[0-9a-f]{24}/g, 'code-(hidden)');
  return JSON.parse(s);
};
let printed = false;
process.on('exit', () => { if (!printed) console.log(JSON.stringify(hide({ r, problems }), null, 1)); });

// 가짜 IdP의 브라우저 쪽: 다음 인가 요청에 로그인할 사람(클레임)과 동작
let identity = null;
let idpMode = 'ok';
const seen = { authorize: [], logout: [] };

async function browser() {
  const b = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await b.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[0-9]|status of 50[03]/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setRequestInterception(true);
  page.on('request', (req) => {
    const u = req.url();
    if (!u.startsWith(IDP)) { req.continue(); return; }
    const url = new URL(u);
    const q = url.searchParams;
    if (url.pathname === '/authorize') {
      seen.authorize.push({ response_type: q.get('response_type'), client_id: q.get('client_id'), redirect_uri: q.get('redirect_uri'), scope: q.get('scope'),
        method: q.get('code_challenge_method'), state_len: q.get('state')?.length, nonce_len: q.get('nonce')?.length });
      const back = new URL(q.get('redirect_uri'));
      if (idpMode === 'error') {
        back.searchParams.set('error', 'access_denied');
      } else {
        const code = `code-${randomBytes(12).toString('hex')}`;
        const codes = JSON.parse(readFileSync(CODES, 'utf8'));
        codes[code] = { claims: identity, nonce: q.get('nonce'), challenge: q.get('code_challenge'), redirect_uri: q.get('redirect_uri'), opt: {} };
        writeFileSync(CODES, JSON.stringify(codes));
        back.searchParams.set('code', code);
      }
      back.searchParams.set('state', q.get('state'));
      req.respond({ status: 302, headers: { Location: back.toString() }, body: '' });
      return;
    }
    if (url.pathname === '/logout') {
      seen.logout.push({ client_id: q.get('client_id'), post_logout_redirect_uri: q.get('post_logout_redirect_uri') });
      req.respond({ status: 200, contentType: 'text/html', body: '<!doctype html><meta charset="utf-8"><title>Fake IdP</title><p id="idp-logged-out">signed out at fake IdP</p>' });
      return;
    }
    req.respond({ status: 404, body: '' });
  });
  await page.setViewport({ width: 1280, height: 900 });
  return { b, page };
}
async function loginForm(page, login, password) {
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page.evaluate(() => location.pathname);
}
const loginState = (page) => page.evaluate(() => ({ path: location.pathname + location.search, account: document.querySelector('[data-account-state]')?.dataset.accountState ?? null,
  accountText: document.querySelector('[data-account-state]')?.textContent.trim() ?? null, error: document.querySelector('#login-error')?.textContent.trim() ?? null }));
// 로그인 화면의 SSO 단추를 키보드로(포커스 → Enter) 눌러 가짜 IdP를 거쳐 돌아온 화면
async function ssoLogin(page, who, { from = '/login', mode = 'ok' } = {}) {
  identity = who;
  idpMode = mode;
  await page.goto(`${BASE}${from}`, { waitUntil: 'networkidle0' });
  await page.focus('[data-sso-provider="oidc"]');
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.keyboard.press('Enter')]);
  return loginState(page);
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
const shot = async (page, name, width = 1280, full = true, allowScroll = false) => {
  await page.setViewport({ width, height: 900 });
  await sleep(300);
  const s = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  r[`scroll_${name}`] = s;
  if (!allowScroll) check(s <= 0, `가로 스크롤 ${name}`, s);
  await page.$$eval('input[type=password]', (ns) => { for (const n of ns) n.value = ''; }).catch(() => null);
  await page.screenshot({ path: path.join(OUT, `P28C_${name}.png`), fullPage: full });
  await page.setViewport({ width: 1280, height: 900 });
};
const LEE = { sub: 'f3b0c2d1-0000-4000-8000-00000000a001', preferred_username: 'sso.lee', name: 'Lee SSO', email: 'lee.sso@corp.test', email_verified: true };

// 1. 로그인 화면 SSO 단추 → 첫 로그인 = 승인 대기(세션 없음)
{
  const { b, page } = await browser();
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  r.button = await page.$eval('[data-sso-provider="oidc"]', (a) => ({ tag: a.tagName, text: a.textContent.trim(), href: a.getAttribute('href') })).catch(() => null);
  check(r.button?.tag === 'A' && r.button.text === '회사 계정으로 로그인' && r.button.href === '/auth/oidc/start', 'SSO 단추(설정 문구, 링크)', r.button);
  await shot(page, 'login_ko_light_1280', 1280, false);
  await shot(page, 'login_ko_light_1024', 1024, false, true); // 로그인 화면도 앱 최소 폭(1280px) 설계 — 1024px는 기록만(P27D와 같음)
  r.first = await ssoLogin(page, LEE);
  r.firstSession = (await apiCall(page, 'GET', '/api/v1/auth/me')).status;
  r.authorize = seen.authorize[0];
  check(r.first.path === '/login?sso_notice=account_pending' && r.first.account === 'account_pending' && /관리자 승인 대기 중/.test(r.first.accountText ?? '') && r.first.error === null
    && r.firstSession === 401, '첫 SSO 로그인 = 승인 대기 안내(세션 없음)', r.first);
  check(r.authorize?.response_type === 'code' && r.authorize.method === 'S256' && r.authorize.redirect_uri === `${BASE}/auth/oidc/callback` && r.authorize.state_len === 43
    && r.authorize.nonce_len === 43 && r.authorize.scope === 'openid profile email', '인가 요청(PKCE S256·state·nonce·리다이렉트 URI)', r.authorize);
  await shot(page, 'login_pending', 1280, false);
  // 같은 ID의 로컬 계정과 겹치는 SSO 로그인 ID → 일반 실패(오류 코드), 로컬 로그인은 그대로
  r.conflict = await ssoLogin(page, { sub: 'f3b0c2d1-0000-4000-8000-00000000a002', preferred_username: userLogin, name: 'Someone' });
  check(r.conflict.path === '/login?sso_error=E-SSO-DENIED' && /SSO 로그인에 실패했습니다.*E-SSO-DENIED/.test(r.conflict.error ?? ''), '같은 ID 로컬 계정 충돌 = 거부', r.conflict);
  r.localStill = await loginForm(page, userLogin, PW);
  check(r.localStill !== '/login', '로컬 계정 로그인은 그대로', r.localStill);
  await b.close();
}

// 2. 잘못된 state·IdP 오류 → 안전한 오류 코드(영어·다크도)
{
  const { b, page } = await browser();
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.goto(`${BASE}/auth/oidc/callback?state=forged-state-value&code=forged`, { waitUntil: 'networkidle0' });
  r.badState = await loginState(page);
  check(r.badState.path === '/login?sso_error=E-SSO-STATE' && /E-SSO-STATE/.test(r.badState.error ?? ''), '위조 state = E-SSO-STATE', r.badState);
  await shot(page, 'login_error_state', 1280, false);
  r.idpError = await ssoLogin(page, LEE, { mode: 'error' });
  check(r.idpError.path === '/login?sso_error=E-SSO-IDP', 'IdP 오류(동의 거부) = E-SSO-IDP', r.idpError);
  await page.setCookie({ name: 'pjg_lang', value: 'en', url: BASE }, { name: 'pjg_theme', value: 'dark', url: BASE });
  await page.goto(`${BASE}/login?sso_error=E-SSO-TOKEN`, { waitUntil: 'networkidle0' });
  r.errEn = await loginState(page);
  r.buttonEn = await text(page, '[data-sso-provider="oidc"]');
  check(/SSO sign-in failed.*E-SSO-TOKEN/.test(r.errEn.error ?? ''), '영어 오류 문구', r.errEn.error);
  await shot(page, 'login_en_dark_1280', 1280, false);
  await shot(page, 'login_en_dark_1024', 1024, false, true);
  await b.close();
}

// 3. 관리자: 승인 대기 행(OIDC) → 승인, 인증 방식 변경 대화상자에 OIDC
{
  const { b, page } = await browser();
  await loginForm(page, adminLogin, PW);
  await page.goto(`${BASE}/admin/users?approval=pending`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('#users-body tr');
  r.pendingRows = await page.$$eval('#users-body tr', (trs) => trs.map((tr) => ({ login: tr.querySelector('code')?.textContent, approval: tr.dataset.approval, auth: tr.cells[5]?.textContent.trim() })));
  const lee = r.pendingRows.find((x) => x.login === 'sso.lee');
  check(lee?.approval === 'pending' && lee.auth === 'OIDC(SSO)', '승인 대기 행 인증 방식 OIDC(SSO)', r.pendingRows);
  await shot(page, 'users_pending_1280');
  await page.evaluate(() => [...document.querySelectorAll('#users-body tr')].find((tr) => tr.querySelector('code')?.textContent === 'sso.lee')
    ?.querySelector('button[data-action="approve"]').click());
  await page.waitForSelector('dialog[open] select');
  r.approveDialog = await text(page, 'dialog[open]');
  await page.focus('dialog[open] .btn--primary');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => ![...document.querySelectorAll('#users-body code')].some((c) => c.textContent === 'sso.lee'), { timeout: 10000 }).catch(() => null);
  check(/OIDC\(SSO\)로 처음 로그인한 계정입니다/.test(r.approveDialog ?? ''), '승인 대화상자(방식 OIDC)', r.approveDialog);
  r.authTypes = (await apiCall(page, 'GET', '/api/v1/users?size=1')).json?.auth_types;
  check(JSON.stringify(r.authTypes) === JSON.stringify(['local', 'oidc']), '켜진 인증 방식 local·oidc', r.authTypes);
  await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('#users-body tr');
  const uid = (await apiCall(page, 'GET', `/api/v1/users?q=${userLogin}`)).json.data.find((u) => u.login_id === userLogin).id;
  await page.click(`tr[data-user-id="${uid}"] button:nth-last-of-type(1)`);
  await page.waitForSelector('dialog[open] select');
  r.authOptions = await page.$$eval('dialog[open] select option', (os) => os.map((o) => o.textContent.trim()));
  check(r.authOptions.includes('OIDC(SSO)'), '인증 방식 변경에 OIDC(SSO)', r.authOptions);
  await page.screenshot({ path: path.join(OUT, 'P28C_auth_change_dialog.png') });
  await page.keyboard.press('Escape');
  await b.close();
}

// 4. 승인된 사람: SSO 로그인 → 세션 새로·내 정보·비밀번호 안내 → 로그아웃(앱 세션만) → 재로그인(이름 그대로)
{
  const { b, page } = await browser();
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  const pre = (await page.cookies()).find((c) => c.name === 'pjg_session')?.value;
  r.ok = await ssoLogin(page, LEE, { from: '/login?next=%2Faccount' });
  const post = (await page.cookies()).find((c) => c.name === 'pjg_session')?.value;
  r.me = (await apiCall(page, 'GET', '/api/v1/auth/me')).json?.data?.user;
  check(r.ok.path === '/account' && r.me?.login_id === 'sso.lee' && r.me?.auth_type === 'oidc', '승인 뒤 SSO 로그인(돌아갈 곳 next → /account)', [r.ok.path, r.me?.login_id]);
  check(Boolean(pre && post && pre !== post), '로그인 전후 세션 ID가 다름(세션 고정 방지)', null);
  r.account = await page.evaluate(() => ({ dd: [...document.querySelectorAll('dd')].map((d) => d.textContent.trim()), name: document.querySelector('#account-name')?.value }));
  check(r.account.dd.includes('OIDC(SSO)') && r.account.name === 'Lee SSO', '내 정보: 인증 방식 OIDC(SSO)·이름(첫 로그인 때 값)', r.account);
  await shot(page, 'account_oidc', 1280, false);
  await page.goto(`${BASE}/password`, { waitUntil: 'networkidle0' });
  r.pwPage = await text(page, '#password-external');
  check(r.pwPage !== null, '비밀번호 화면: 외부 인증 안내', r.pwPage);
  await page.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('[data-nav="ui.logout"]')]);
  r.logout = await page.evaluate(() => location.pathname + location.search);
  check(r.logout === '/login?logged_out=1' && seen.logout.length === 0, '로그아웃 = 앱 세션만(제공자 로그아웃 꺼짐)', r.logout);
  r.relogin = await ssoLogin(page, { ...LEE, name: 'Changed At IdP', email: 'changed@corp.test', preferred_username: 'sso.lee.renamed' });
  r.me2 = (await apiCall(page, 'GET', '/api/v1/auth/me')).json?.data?.user;
  check(r.relogin.path === '/projects' && r.me2?.login_id === 'sso.lee' && r.me2?.display_name === 'Lee SSO', '재로그인: (발급자, sub)로 같은 계정·이름 그대로', [r.relogin.path, r.me2?.login_id, r.me2?.display_name]);
  await b.close();
}

// 5. 설정 관리 SSO(OIDC) 탭: 클라이언트 비밀·리다이렉트 URI 복사·연결 시험, 점검 화면, 언어·테마
for (const [lang, theme] of [['ko', 'light'], ['en', 'dark']]) {
  const { b, page } = await browser();
  await b.defaultBrowserContext().overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
  await loginForm(page, adminLogin, PW);
  await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: lang, theme });
  try {
    await page.goto(`${BASE}/admin/config-settings#auth_oidc`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('[data-oidc-secret] input');
    if (lang === 'ko') {
      r.tabs = await page.$$eval('[role="tab"]', (ts) => ts.map((t) => t.querySelector('.config-tab__name').textContent));
      r.selected = await page.$eval('[role="tab"][aria-selected="true"] .config-tab__name', (n) => n.textContent);
      check(r.tabs.slice(-3).join(',') === '계정 승인,LDAP/AD,SSO(OIDC)' && r.selected === 'SSO(OIDC)', '설정 탭 SSO(OIDC)(등록부에서 자동)', [r.tabs, r.selected]);
      r.secretBefore = await text(page, '[data-oidc-secret] [data-secret-state]');
      await page.type('[data-oidc-secret] input', SECRET);
      await page.click('[data-oidc-secret] [data-secret-save]');
      // 격리 설치 때 이미 저장돼 있으므로(state=set) "저장했습니다" 문구로 완료를 본다
      await page.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-oidc-secret] [data-secret-state]')?.textContent ?? ''), { timeout: 5000 });
      const body = await page.evaluate(() => document.body.innerText + document.documentElement.outerHTML);
      r.secretAfter = [await text(page, '[data-oidc-secret] [data-secret-state]'), await page.$eval('[data-oidc-secret] input', (n) => n.value === '')];
      check(/저장됨/.test(r.secretBefore ?? '') && r.secretAfter[1] && !body.includes(SECRET), '클라이언트 비밀 교체(값 다시 안 보임)', [r.secretBefore, r.secretAfter[0]]);
      r.redirect = await text(page, '#cs-oidc-redirect');
      await page.click('[data-copy="redirect"]');
      await sleep(300);
      r.copied = await page.evaluate(() => navigator.clipboard.readText()).catch((e) => `읽기 실패 ${e.message}`);
      r.copyMsg = await page.$eval('[data-copy="redirect"]', (btn) => btn.closest('.oidc-uris__row').querySelector('.oidc-uris__msg').textContent);
      check(r.redirect === `${BASE}/auth/oidc/callback` && r.copied === r.redirect && r.copyMsg === '복사했습니다', '리다이렉트 URI 표시·복사', [r.redirect, r.copied, r.copyMsg]);
      await page.$eval('[data-oidc-test-run]', (btn) => btn.scrollIntoView({ block: 'center' })); // 비밀 저장·복사 안내로 배치가 바뀐 뒤 클릭
      await sleep(300);
      await page.click('[data-oidc-test-run]');
      await page.waitForSelector('[data-oidc-steps] li[data-step="token_endpoint"]', { timeout: 10000 }).catch(async () => { r.testPanel = await text(page, '[data-oidc-test]'); });
      r.steps = await page.$$eval('[data-oidc-steps] li', (ls) => ls.map((l) => [l.dataset.step, l.dataset.state, l.textContent.replace(/\s+/g, ' ').trim()]));
      check(JSON.stringify(r.steps.map((s) => s.slice(0, 2))) === JSON.stringify([['discovery', 'ok'], ['jwks', 'ok'], ['token_endpoint', 'ok']])
        && /키 2개, 쓸 수 있는 알고리즘 RS256, ES256/.test(r.steps[1][2]) && /HTTP 400 응답/.test(r.steps[2][2]), '연결 시험 3단계', r.steps);
    } else {
      await page.$eval('[data-oidc-test-run]', (btn) => btn.scrollIntoView({ block: 'center' })); // 비밀 저장·복사 안내로 배치가 바뀐 뒤 클릭
      await sleep(300);
      await page.click('[data-oidc-test-run]');
      await page.waitForSelector('[data-oidc-steps] li[data-step="token_endpoint"]', { timeout: 10000 }).catch(async () => { r.testPanel = await text(page, '[data-oidc-test]'); });
      r.stepsEn = await page.$$eval('[data-oidc-steps] li', (ls) => ls.map((l) => l.textContent.replace(/\s+/g, ' ').trim()));
      check(/Discovery and issuer match: passed/.test(r.stepsEn[0] ?? ''), '영어 연결 시험', r.stepsEn);
    }
    await shot(page, `settings_oidc_${lang}_${theme}_1280`);
    await shot(page, `settings_oidc_${lang}_${theme}_1024`, 1024);
    if (lang === 'ko') {
      await page.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
      await sleep(500);
      r.check = await page.$$eval('tr', (trs) => trs.map((t) => t.textContent.replace(/\s+/g, ' ').trim()).filter((s) => s.startsWith('SSO(OIDC)')));
      check(r.check.length === 1 && /정상/.test(r.check[0]) && /디스커버리 확인됨/.test(r.check[0]), '점검 화면 SSO(OIDC) 항목', r.check);
    }
  } finally {
    await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: null, theme: null }).catch(() => null);
    await b.close();
  }
}

// 6. 제공자 로그아웃 켜기: 로그아웃 폼 POST → IdP 로그아웃(form-action에 IdP 출처만 더해 CSP 위반 없음) → 끄기
{
  const { b, page } = await browser();
  await loginForm(page, adminLogin, PW);
  const view = (await apiCall(page, 'GET', '/api/v1/admin/config-settings')).json.data;
  r.plOn = (await apiCall(page, 'PUT', '/api/v1/admin/config-settings', { changes: { 'oidc.provider_logout': 'true' }, version: view.version })).status;
  await b.close();
  const u = await browser();
  await ssoLogin(u.page, LEE);
  r.cspHeader = (await u.page.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' })).headers()['content-security-policy'];
  await Promise.all([u.page.waitForNavigation({ waitUntil: 'networkidle0' }), u.page.click('[data-nav="ui.logout"]')]);
  r.providerLogout = { at: await u.page.evaluate(() => location.origin + location.pathname), page: await text(u.page, '#idp-logged-out'), seen: seen.logout.at(-1) };
  check(/form-action 'self' https:\/\/idp\.example\.test;/.test(r.cspHeader ?? '') && r.providerLogout.at === `${IDP}/logout` && r.providerLogout.page === 'signed out at fake IdP'
    && r.providerLogout.seen?.client_id === 'pjg-client' && r.providerLogout.seen?.post_logout_redirect_uri === `${BASE}/login?logged_out=1`, '제공자 로그아웃(켬)', r.providerLogout);
  await u.b.close();
  const a = await browser();
  await loginForm(a.page, adminLogin, PW);
  const v2 = (await apiCall(a.page, 'GET', '/api/v1/admin/config-settings')).json.data;
  r.plOff = (await apiCall(a.page, 'PUT', '/api/v1/admin/config-settings', { changes: { 'oidc.provider_logout': 'false' }, version: v2.version })).status;
  check(r.plOn === 200 && r.plOff === 200, '제공자 로그아웃 켜기·끄기 저장', [r.plOn, r.plOff]);
  await a.b.close();
}

// 7. 일반 사용자 403
{
  const { b, page } = await browser();
  await loginForm(page, userLogin, PW);
  r.user = [(await apiCall(page, 'GET', '/api/v1/admin/auth/oidc')).status, (await apiCall(page, 'POST', '/api/v1/admin/auth/oidc/test', {})).status,
    (await apiCall(page, 'PUT', '/api/v1/admin/auth/oidc/client-secret', { secret: 'x' })).status];
  check(JSON.stringify(r.user) === JSON.stringify([403, 403, 403]), '일반 사용자 403', r.user);
  await b.close();
}

printed = true;
console.log(JSON.stringify(hide(r), null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${hide(problems).join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
