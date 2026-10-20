// SSO(SAML 2.0) 로그인 브라우저 점검(P28D, 헤드리스 Chrome). 격리 서버(모의 IdP를 끼운 점검 라우터·027·028 적용한 DB 사본)에서만 —
// 실제 IdP에 연결하지 않는다. 브라우저가 가는 IdP SSO 화면(https://idp.example.test/saml/sso)은 요청 가로채기로 대신하고, 서명된 SAMLResponse는
// tests/Support/fake_saml_sign.php(격리 폴더의 시험용 키)로 만들어 IdP 쪽에서 자동 제출하는 폼으로 돌려준다 — 실제 브라우저의 cross-site POST →
// 303 → 같은 사이트 GET(Lax 세션 쿠키) 흐름을 그대로 탄다. 실제로 계정을 만들고 바꾼다(사본 전용).
//   PJG_PASSWORD=<로컬 계정 비밀번호> PJG_IDP_DIR=<격리 폴더>/idp node tools/check_saml_ui.mjs <점검 서버> <일반 사용자> [관리자]
// 흐름: 로그인 화면 SSO 단추 둘(OIDC·SAML, 설정 문구·키보드) → 모의 IdP → 첫 로그인 "승인 대기"(세션 없음) → 관리자 승인 대기 행(SAML)·승인 →
//   그 사람 로그인(세션 ID 바뀜·돌아갈 곳·내 정보) → 로그아웃(앱 세션만) → 재로그인(IdP 이름이 바뀌어도 그대로) → 서명 변조 응답 → E-SSO-TOKEN →
//   같은 ID 로컬 계정 충돌 거부 → 설정 관리 SSO(SAML) 탭(SP 정보 복사·메타데이터 내려받기·IdP 메타데이터 가져오기로 칸 채우기(저장 안 함)·
//   SP 키 생성(개인 키 안 보임)·인증서 만료일·설정 확인 4단계) → 점검 화면 → SP 메타데이터 XML → 일반 사용자 403.
// 한국어·영어 × 라이트·다크 × 1280·1024 캡처(가로 스크롤 0, 로그인·사용자 관리는 앱 최소 폭 설계라 기록만), 콘솔 오류 0, CSP 위반 0. 출력·캡처에 비밀번호·응답 원문을 남기지 않는다. 캡처 P28D_*.png
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const [baseArg, userLogin, adminLogin = 'admin'] = args;
const PW = process.env.PJG_PASSWORD;
const IDP_DIR = process.env.PJG_IDP_DIR;
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
if (!userLogin || !PW || !IDP_DIR) {
  console.error('사용법: PJG_PASSWORD=... PJG_IDP_DIR=<격리 폴더>/idp node tools/check_saml_ui.mjs <점검 서버> <일반 사용자> [관리자]');
  process.exit(2);
}
const IDP_SSO = 'https://idp.example.test/saml/sso';
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
const hide = (v) => JSON.parse(JSON.stringify(v).split(PW).join('(hidden)').replace(/[A-Za-z0-9+/]{200,}={0,2}/g, '(base64 hidden)'));
let printed = false;
process.on('exit', () => { if (!printed) console.log(JSON.stringify(hide({ r, problems }), null, 1)); });

// 모의 IdP의 브라우저 쪽: 다음 로그인에 쓸 사람(옵션)
let idpOptions = null;
const seen = { sso: 0 };

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
    if (!u.startsWith('https://idp.example.test/')) { req.continue(); return; }
    if (u.startsWith(`${IDP_SSO}?`)) {
      seen.sso += 1;
      const out = JSON.parse(execFileSync(PHP, [path.join(ROOT, 'tests/Support/fake_saml_sign.php'), IDP_DIR, u, JSON.stringify(idpOptions ?? {})], { encoding: 'utf8' }));
      const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
      // IdP 쪽 화면(우리 CSP와 무관): 자동 제출 폼 — 실제 IdP처럼 다른 사이트에서 ACS로 POST
      const html = `<!doctype html><meta charset="utf-8"><title>Fake IdP</title><form method="post" action="${esc(out.acs)}">`
        + `<input type="hidden" name="SAMLResponse" value="${esc(out.response)}"><input type="hidden" name="RelayState" value="${esc(out.relay)}">`
        + '<button type="submit">Continue</button></form><script>document.forms[0].submit()</script>';
      req.respond({ status: 200, contentType: 'text/html', body: html });
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
// SAML 단추를 키보드로 → 모의 IdP 자동 제출 → ACS(303) → 마무리 → 끝 화면
async function samlLogin(page, opts, { from = '/login' } = {}) {
  idpOptions = opts;
  await page.goto(`${BASE}${from}`, { waitUntil: 'networkidle0' });
  await page.focus('[data-sso-provider="saml"]');
  await page.keyboard.press('Enter');
  await page.waitForFunction((base) => location.href.startsWith(base) && !location.pathname.startsWith('/auth/saml/'), { timeout: 20000 }, BASE);
  await page.waitForNetworkIdle({ idleTime: 300 }).catch(() => null);
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
  await page.screenshot({ path: path.join(OUT, `P28D_${name}.png`), fullPage: full });
  await page.setViewport({ width: 1280, height: 900 });
};
const LEE = { name_id: 'b8f0e7a2-0000-4000-8000-00000000d001', attributes: { uid: ['saml.lee'], displayName: ['Lee SAML'], mail: ['lee.saml@corp.test'] } };

// 1. 로그인 화면 단추 둘 → SAML 첫 로그인 = 승인 대기(세션 없음)
{
  const { b, page } = await browser();
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  r.buttons = await page.$$eval('[data-sso-provider]', (as) => as.map((a) => [a.dataset.ssoProvider, a.textContent.trim(), a.getAttribute('href')]));
  check(JSON.stringify(r.buttons) === JSON.stringify([['oidc', 'SSO로 로그인', '/auth/oidc/start'], ['saml', '회사 SSO(SAML)', '/auth/saml/start']]), 'SSO 단추 둘(OIDC 기본 문구·SAML 설정 문구)', r.buttons);
  await shot(page, 'login_ko_light_1280', 1280, false);
  await shot(page, 'login_ko_light_1024', 1024, false, true); // 로그인 화면도 앱 최소 폭(1280px) 설계 — 1024px는 기록만(P27D·P28C와 같음)
  r.first = await samlLogin(page, LEE);
  r.firstSession = (await apiCall(page, 'GET', '/api/v1/auth/me')).status;
  check(r.first.path === '/login?sso_notice=account_pending' && /관리자 승인 대기 중/.test(r.first.accountText ?? '') && r.first.error === null && r.firstSession === 401 && seen.sso === 1,
    '첫 SAML 로그인 = 승인 대기 안내(세션 없음) — 실제 브라우저의 cross-site POST·303·Lax 쿠키 흐름', [r.first, r.firstSession, seen.sso]);
  await shot(page, 'login_pending', 1280, false);
  // 서명 변조 응답 → 안전한 오류 코드
  r.tamper = await samlLogin(page, { ...LEE, tamper: true });
  check(r.tamper.path === '/login?sso_error=E-SSO-TOKEN' && /SSO 로그인에 실패했습니다.*E-SSO-TOKEN/.test(r.tamper.error ?? ''), '서명 변조 = E-SSO-TOKEN', r.tamper);
  await shot(page, 'login_error_token', 1280, false);
  // 같은 ID 로컬 계정과 겹침 → 거부, 로컬 로그인은 그대로
  r.conflict = await samlLogin(page, { name_id: 'conflict-1', attributes: { uid: [userLogin], displayName: ['Someone'] } });
  check(r.conflict.path === '/login?sso_error=E-SSO-DENIED', '같은 ID 로컬 계정 충돌 = 거부', r.conflict);
  r.localStill = await loginForm(page, userLogin, PW);
  check(r.localStill !== '/login', '로컬 계정 로그인은 그대로', r.localStill);
  await b.close();
}

// 2. 영어·다크 로그인 화면
{
  const { b, page } = await browser();
  await page.setCookie({ name: 'pjg_lang', value: 'en', url: BASE }, { name: 'pjg_theme', value: 'dark', url: BASE });
  await page.goto(`${BASE}/login?sso_error=E-SSO-TOKEN`, { waitUntil: 'networkidle0' });
  r.en = await loginState(page);
  check(/SSO sign-in failed.*E-SSO-TOKEN/.test(r.en.error ?? ''), '영어 오류 문구', r.en.error);
  await shot(page, 'login_en_dark_1280', 1280, false);
  await shot(page, 'login_en_dark_1024', 1024, false, true);
  await b.close();
}

// 3. 관리자: 승인 대기 행(SAML) → 승인
{
  const { b, page } = await browser();
  await loginForm(page, adminLogin, PW);
  await page.goto(`${BASE}/admin/users?approval=pending`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('#users-body tr');
  r.pendingRows = await page.$$eval('#users-body tr', (trs) => trs.map((tr) => ({ login: tr.querySelector('code')?.textContent, approval: tr.dataset.approval, auth: tr.cells[5]?.textContent.trim() })));
  check(r.pendingRows.find((x) => x.login === 'saml.lee')?.auth === 'SAML(SSO)', '승인 대기 행 인증 방식 SAML(SSO)', r.pendingRows);
  await shot(page, 'users_pending_1280');
  await page.evaluate(() => [...document.querySelectorAll('#users-body tr')].find((tr) => tr.querySelector('code')?.textContent === 'saml.lee')
    ?.querySelector('button[data-action="approve"]').click());
  await page.waitForSelector('dialog[open] select');
  r.approveDialog = await text(page, 'dialog[open]');
  await page.focus('dialog[open] .btn--primary');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => ![...document.querySelectorAll('#users-body code')].some((c) => c.textContent === 'saml.lee'), { timeout: 10000 }).catch(() => null);
  check(/SAML\(SSO\)로 처음 로그인한 계정입니다/.test(r.approveDialog ?? ''), '승인 대화상자(방식 SAML)', r.approveDialog);
  r.authTypes = (await apiCall(page, 'GET', '/api/v1/users?size=1')).json?.auth_types;
  check(JSON.stringify(r.authTypes) === JSON.stringify(['local', 'oidc', 'saml']), '켜진 인증 방식 local·oidc·saml', r.authTypes);
  await b.close();
}

// 4. 승인된 사람: 로그인(세션 ID 바뀜·돌아갈 곳) → 내 정보 → 로그아웃(앱 세션만) → 재로그인(이름 그대로)
{
  const { b, page } = await browser();
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  const pre = (await page.cookies()).find((c) => c.name === 'pjg_session')?.value;
  r.ok = await samlLogin(page, LEE, { from: '/login?next=%2Faccount' });
  const post = (await page.cookies()).find((c) => c.name === 'pjg_session')?.value;
  r.me = (await apiCall(page, 'GET', '/api/v1/auth/me')).json?.data?.user;
  check(r.ok.path === '/account' && r.me?.login_id === 'saml.lee' && r.me?.auth_type === 'saml', '승인 뒤 SAML 로그인(돌아갈 곳 /account)', [r.ok.path, r.me?.login_id]);
  check(Boolean(pre && post && pre !== post), '로그인 전후 세션 ID가 다름(세션 고정 방지)', null);
  r.account = await page.evaluate(() => ({ dd: [...document.querySelectorAll('dd')].map((d) => d.textContent.trim()), name: document.querySelector('#account-name')?.value }));
  check(r.account.dd.includes('SAML(SSO)') && r.account.name === 'Lee SAML', '내 정보: 인증 방식 SAML(SSO)·이름', r.account);
  await shot(page, 'account_saml', 1280, false);
  await page.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('[data-nav="ui.logout"]')]);
  r.logout = await page.evaluate(() => location.pathname + location.search);
  check(r.logout === '/login?logged_out=1', '로그아웃 = 앱 세션만(SLO 없음)', r.logout);
  r.relogin = await samlLogin(page, { ...LEE, attributes: { uid: ['saml.lee.renamed'], displayName: ['Changed At IdP'], mail: ['changed@corp.test'] } });
  r.me2 = (await apiCall(page, 'GET', '/api/v1/auth/me')).json?.data?.user;
  check(r.relogin.path === '/projects' && r.me2?.login_id === 'saml.lee' && r.me2?.display_name === 'Lee SAML', '재로그인: (EntityID, NameID)로 같은 계정·이름 그대로', [r.relogin.path, r.me2?.display_name]);
  await b.close();
}

// 5. 설정 관리 SSO(SAML) 탭, 점검 화면, SP 메타데이터, 언어·테마
for (const [lang, theme] of [['ko', 'light'], ['en', 'dark']]) {
  const { b, page } = await browser();
  await b.defaultBrowserContext().overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
  await loginForm(page, adminLogin, PW);
  await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: lang, theme });
  try {
    await page.goto(`${BASE}/admin/config-settings#auth_saml`, { waitUntil: 'networkidle0' });
    await page.waitForSelector('[data-saml-key] button');
    if (lang === 'ko') {
      r.tabs = await page.$$eval('[role="tab"]', (ts) => ts.map((t) => t.querySelector('.config-tab__name').textContent));
      check(r.tabs.slice(-4).join(',') === '계정 승인,LDAP/AD,SSO(OIDC),SSO(SAML)', '설정 탭 SSO(SAML)(등록부에서 자동)', r.tabs);
      r.sp = [await text(page, '#cs-saml-entity'), await text(page, '#cs-saml-acs'), await text(page, '#cs-saml-metadata')];
      check(JSON.stringify(r.sp) === JSON.stringify([`${BASE}/auth/saml/metadata`, `${BASE}/auth/saml/acs`, `${BASE}/auth/saml/metadata`]), 'SP 정보(EntityID·ACS·메타데이터 주소)', r.sp);
      await page.click('[data-copy="saml-acs"]');
      await sleep(300);
      r.copied = await page.evaluate(() => navigator.clipboard.readText()).catch((e) => `읽기 실패 ${e.message}`);
      check(r.copied === `${BASE}/auth/saml/acs`, 'ACS 주소 복사', r.copied);
      r.certLine = await page.$eval('[data-saml-cert="current"]', (n) => [n.dataset.state, n.textContent.trim()]).catch(() => null);
      check(r.certLine?.[0] === 'ok' && /현재 인증서: \d{4}-\d{2}-\d{2}까지 유효/.test(r.certLine[1]), 'IdP 인증서 만료일 표시', r.certLine);
      // IdP 메타데이터 가져오기(주소) → 칸이 채워짐(저장은 안 함) → 변경 취소
      await page.type('#cs-saml-meta-url', 'https://idp.example.test/saml/metadata');
      await page.click('[data-saml-import-run]');
      await page.waitForFunction(() => /값을 채웠습니다/.test(document.querySelector('[data-saml-import-msg]')?.textContent ?? ''), { timeout: 10000 });
      r.imported = await page.evaluate(() => ({ next: document.querySelector('[data-input="saml.idp_cert_next"]').value.length,
        changed: [...document.querySelectorAll('[data-item] [data-changed-mark]:not([hidden])')].map((m) => m.closest('[data-item]').dataset.item) }));
      check(r.imported.next > 100 && r.imported.changed.includes('saml.idp_cert_next'), '메타데이터 가져오기 = 다음 인증서 칸 채움(변경 표시, 저장 안 함)', r.imported);
      await shot(page, 'settings_saml_import');
      await page.click('[data-discard]').catch(() => null);
      // SP 키 생성 — 개인 키는 화면에 없음(변경 취소 뒤 탭이 다시 그려지므로 단추를 기다림)
      await page.waitForSelector('[data-saml-key-generate]', { timeout: 10000 });
      await sleep(300);
      await page.click('[data-saml-key-generate]');
      await page.waitForSelector('[data-saml-key-state="set"]', { timeout: 10000 });
      r.keyState = await text(page, '[data-saml-key-state]');
      const html = await page.content();
      check(/저장됨 — 인증서 \d{4}-\d{2}-\d{2}까지/.test(r.keyState ?? '') && !/PRIVATE KEY/.test(html), 'SP 키 생성(개인 키 안 보임)', r.keyState);
      await page.$eval('[data-saml-test-run]', (btn) => btn.scrollIntoView({ block: 'center' }));
      await sleep(300);
      await page.click('[data-saml-test-run]');
      await page.waitForSelector('[data-saml-steps] li[data-step="sp_metadata"]', { timeout: 10000 }).catch(async () => { r.testPanel = await text(page, '[data-saml-test]'); });
      r.steps = await page.$$eval('[data-saml-steps] li', (ls) => ls.map((l) => [l.dataset.step, l.dataset.state]));
      check(JSON.stringify(r.steps) === JSON.stringify([['idp_cert', 'ok'], ['sso_url', 'ok'], ['sp', 'ok'], ['sp_metadata', 'ok']]), '설정 확인 4단계', r.steps);
    } else {
      await page.$eval('[data-saml-test-run]', (btn) => btn.scrollIntoView({ block: 'center' }));
      await sleep(300);
      await page.click('[data-saml-test-run]');
      await page.waitForSelector('[data-saml-steps] li[data-step="sp_metadata"]', { timeout: 10000 }).catch(async () => { r.testPanelEn = await text(page, '[data-saml-test]'); });
      r.stepsEn = await page.$$eval('[data-saml-steps] li', (ls) => ls.map((l) => l.textContent.replace(/\s+/g, ' ').trim()));
      check(/IdP certificate: passed/.test(r.stepsEn[0] ?? ''), '영어 설정 확인', r.stepsEn);
    }
    await shot(page, `settings_saml_${lang}_${theme}_1280`);
    await shot(page, `settings_saml_${lang}_${theme}_1024`, 1024);
    if (lang === 'ko') {
      await page.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
      await sleep(500);
      r.check = await page.$$eval('tr', (trs) => trs.map((t) => t.textContent.replace(/\s+/g, ' ').trim()).filter((s) => s.startsWith('SSO(SAML)')));
      check(r.check.length === 1 && /정상/.test(r.check[0]) && /설정·인증서 확인됨/.test(r.check[0]), '점검 화면 SSO(SAML) 항목', r.check);
      const md = await page.evaluate(async () => { const res = await fetch('/auth/saml/metadata'); return { status: res.status, type: res.headers.get('content-type'), body: await res.text() }; });
      r.metadata = { status: md.status, type: md.type, entity: /entityID="[^"]+\/auth\/saml\/metadata"/.test(md.body), acs: /AssertionConsumerService[^>]+\/auth\/saml\/acs"/.test(md.body),
        cert: /X509Certificate>/.test(md.body), privateKey: /PRIVATE KEY/.test(md.body) };
      check(md.status === 200 && /samlmetadata\+xml/.test(md.type ?? '') && r.metadata.entity && r.metadata.acs && r.metadata.cert && !r.metadata.privateKey, 'SP 메타데이터(인증서 포함, 개인 키 없음)', r.metadata);
    }
  } finally {
    await apiCall(page, 'PUT', '/api/v1/users/me/preferences', { language: null, theme: null }).catch(() => null);
    await b.close();
  }
}

// 6. 일반 사용자 403
{
  const { b, page } = await browser();
  await loginForm(page, userLogin, PW);
  r.user = [(await apiCall(page, 'GET', '/api/v1/admin/auth/saml')).status, (await apiCall(page, 'POST', '/api/v1/admin/auth/saml/test', {})).status,
    (await apiCall(page, 'POST', '/api/v1/admin/auth/saml/sp-key', {})).status, (await apiCall(page, 'POST', '/api/v1/admin/auth/saml/metadata-import', { xml: '<x/>' })).status];
  check(JSON.stringify(r.user) === JSON.stringify([403, 403, 403, 403]), '일반 사용자 403', r.user);
  await b.close();
}

printed = true;
console.log(JSON.stringify(hide(r), null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${hide(problems).join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
