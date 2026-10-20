// 인증·계정 화면 브라우저 점검(P10, 헤드리스 Chrome). 콘솔 오류·CSP 위반 수집, 화면 캡처(docs/results/P10_*.png).
// 흐름: 비로그인 / → 로그인 화면, 틀린 비밀번호, 관리자 로그인 → 홈 → 사용자 관리 → 사용자 추가(임시 비밀번호 대화상자)
//       → 비활성화 확인 대화상자(취소) → 새 사용자로 로그인(임시 비밀번호) → 비밀번호 강제 변경 → 홈 → /admin/users 403
// 준비: puppeteer-core(data/work/puppeteer, tools/check_styleguide.mjs 머리말 참고), Apache 실행
// 실행: PJG_ADMIN_LOGIN=admin PJG_ADMIN_PASSWORD=... node tools/check_auth_ui.mjs [기본 주소(생략하면 config.ini의 app.base_url)] [Chrome 경로]
//   비밀번호는 명령행 인자가 아니라 환경 변수로 받는다. 캡처의 임시 비밀번호는 가린다.
//   주의: 실행할 때마다 시험 사용자(ui…)를 하나 만든다(계정은 지울 수 없음). 캡처 저장 위치는 PJG_SHOT_DIR(기본 docs/results)
//   결과의 화면 주소는 배포 경로 접두사(예: /PJGuardian)를 뺀 앱 안 경로로 적는다.
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const { base: BASE, chromeArgs, rel } = target(ROOT, process.argv[2]);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const ADMIN = process.env.PJG_ADMIN_LOGIN ?? 'admin';
const ADMIN_PW = process.env.PJG_ADMIN_PASSWORD;
if (!ADMIN_PW) {
  console.error('PJG_ADMIN_PASSWORD 환경 변수가 필요합니다.');
  process.exit(1);
}
const browser = await puppeteer.launch({
  executablePath: process.argv[3] ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  headless: true,
  args: chromeArgs,
});
const problems = [];
const result = {};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function newPage(context) {
  const page = await context.newPage();
  await page.setViewport({ width: 1280, height: 900 });
  // 401·403은 시험하려고 일부러 만든 응답이다(틀린 비밀번호, 권한 없음). 그 밖의 실패 응답은 주소와 함께 기록
  page.on('console', (m) => { if (['error', 'warning'].includes(m.type()) && !/Failed to load resource/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`); });
  page.on('response', (r) => { if (r.status() >= 400 && ![401, 403].includes(r.status())) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  page.on('requestfailed', (r) => problems.push(`requestfailed: ${r.url()} ${r.failure()?.errorText}`));
  return page;
}

async function login(page, id, pw) {
  await page.type('#login-id', id);
  await page.type('#login-password', pw);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
}

// 1. 관리자
const adminCtx = await browser.createBrowserContext();
const page = await newPage(adminCtx);
await page.goto(`${BASE}/`, { waitUntil: 'networkidle0' });
result.rootRedirect = rel(page.url());
await page.screenshot({ path: `${OUT}/P10_login.png` });

await login(page, ADMIN, 'Wrong-password-1');
result.wrongPassword = { status: await page.$eval('#login-error', (e) => e.textContent.trim()), loginIdKept: await page.$eval('#login-id', (e) => e.value), passwordCleared: await page.$eval('#login-password', (e) => e.value === '') };
await page.screenshot({ path: `${OUT}/P10_login_error.png` });

await page.$eval('#login-id', (e) => { e.value = ''; });
await login(page, ADMIN, ADMIN_PW);
result.afterLogin = rel(page.url());
result.homeHasAdminMenu = await page.$$eval('[data-nav^="admin."]', (n) => n.length);
await page.screenshot({ path: `${OUT}/P10_home_admin.png` });

await page.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
await page.waitForSelector('#users-body tr');
result.usersRows = await page.$$eval('#users-body tr', (r) => r.length);
const newId = `ui${Date.now().toString().slice(-6)}`;
await page.type('#uc-login', newId);
await page.type('#uc-name', 'UI Test User');
await page.click('#user-create button[type=submit]');
await page.waitForSelector('dialog[open] .secret-value');
const temp = await page.$eval('dialog[open] .secret-value', (e) => e.textContent);
result.createdDialog = { title: await page.$eval('dialog[open] .dialog__title', (e) => e.textContent), secretLength: temp.length, focused: await page.evaluate(() => document.activeElement?.textContent.trim()) };
await page.$eval('dialog[open] .secret-value', (e) => { e.textContent = '••••••••••••••'; }); // 캡처에서 가림
await page.screenshot({ path: `${OUT}/P10_user_created_dialog.png` });
await page.keyboard.press('Escape');
await sleep(300);
result.dialogRemoved = await page.$('dialog .secret-value') === null;

// 새 사용자 행의 비활성화 → 확인 대화상자(취소)
await page.waitForSelector(`#users-body tr td code`);
const rowHandle = await page.evaluateHandle((id) => [...document.querySelectorAll('#users-body tr')].find((tr) => tr.querySelector('code')?.textContent === id), newId);
const buttons = await rowHandle.$$('button');
let deactivate = null;
for (const b of buttons) if ((await b.evaluate((e) => e.textContent)) === '비활성화') deactivate = b;
await deactivate.click();
await page.waitForSelector('dialog[open].dialog--danger');
result.deactivateDialog = await page.evaluate(() => ({ title: document.querySelector('dialog[open] .dialog__title').textContent, focused: document.activeElement?.textContent.trim(), icon: document.querySelector('dialog[open] svg')?.dataset.icon }));
await page.screenshot({ path: `${OUT}/P10_deactivate_confirm.png` });
await page.keyboard.press('Escape');
await sleep(300);
result.stillActive = await page.evaluate((id) => [...document.querySelectorAll('#users-body tr')].find((tr) => tr.querySelector('code')?.textContent === id)?.children[4].textContent, newId);
await page.screenshot({ path: `${OUT}/P10_users.png`, fullPage: true });

// 2. 새 사용자(별도 브라우저 문맥): 임시 비밀번호 → 강제 변경 → 홈 → 관리자 화면 403
const userCtx = await browser.createBrowserContext();
const up = await newPage(userCtx);
await up.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await login(up, newId, temp);
result.tempLoginLanding = rel(up.url());
await up.screenshot({ path: `${OUT}/P10_password_forced.png` });
await up.goto(`${BASE}/home`, { waitUntil: 'networkidle0' });
result.homeWhileTemp = rel(up.url());
const newPw = `Ui-${Math.random().toString(36).slice(2, 10)}9A`;
await up.type('#pw-current', temp);
await up.type('#pw-new', newPw);
await up.type('#pw-confirm', newPw);
await Promise.all([up.waitForNavigation({ waitUntil: 'networkidle0' }), up.click('#main form button[type=submit]')]);
result.afterChange = rel(up.url(), true);
result.userHomeAdminMenu = await up.$$eval('[data-nav^="admin."]', (n) => n.length);
await up.screenshot({ path: `${OUT}/P10_home_user.png` });
const resp = await up.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
result.userAdminPage = { status: resp.status(), heading: await up.$eval('h1', (e) => e.textContent) };
await up.screenshot({ path: `${OUT}/P10_user_forbidden.png` });
// 로그아웃(폼)
await up.goto(`${BASE}/home`, { waitUntil: 'networkidle0' });
await Promise.all([up.waitForNavigation({ waitUntil: 'networkidle0' }), up.click('button[data-nav="ui.logout"]')]);
result.afterLogout = rel(up.url(), true);
result.logoutNotice = await up.$eval('.notice', (e) => e.textContent.trim());

result.problems = problems;
console.log(JSON.stringify(result, null, 2));
await browser.close();
process.exit(problems.length === 0 ? 0 : 1);
