// 계정 CSV 일괄 처리 브라우저 점검(P28A, 헤드리스 Chrome). 격리 서버(별도 포트·DB 사본)에서만 쓴다 — 실제로 계정을 만들고 비활성화한다.
//   PJG_PASSWORD=... node tools/check_account_import_ui.mjs <점검 서버> <일반 사용자> [관리자] [--shots=<폴더>]
// 흐름: 사용자 관리의 "CSV로 일괄 처리" → 양식 내려받기(내용 확인) → 편집(생성 4·비활성화 1·오류 2·경고) → 키보드로 미리보기 →
//   판정 표(아이콘 + 글자)·필터(라디오 방향키)·실행 막힘 → "오류 행 제외"(Space) → 확인 대화상자(관리자 생성 문구) → 결과(임시 비밀번호 한 번)·
//   결과 CSV 한 번(수식 문자 작은따옴표) → 새 계정으로 첫 로그인(변경 강제 화면) → 결과 화면 새로 고침하면 비밀번호 사라짐 →
//   미리보기 뒤 충돌(같은 ID를 API로 생성) → 실행하면 반영 없이 다시 미리보기 → CP949 파일 → 1,000행 쪽 나누기 → 일반 사용자 403·링크 없음.
// 한국어·영어 × 라이트·다크 × 1280·1024 캡처(가로 스크롤 0), 콘솔 오류 0, CSP 위반 0. 캡처 P28A_*.png
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const [baseArg, userLogin, adminLogin = 'admin'] = args;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_account_import_ui.mjs <점검 서버> <일반 사용자> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const TMP = fs.mkdtempSync(path.join(os.tmpdir(), 'pjg_p28a_'));
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
const tag = Date.now().toString(36).slice(-5); // 실행마다 다른 로그인 ID(같은 사본에서 다시 돌려도 충돌 없게)

async function session(login, password = process.env.PJG_PASSWORD) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[03-9]|status of 4(09|13|22)/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return { browser, page };
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
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const shot = async (page, name, width = 1280) => {
  await page.setViewport({ width, height: 900 });
  await sleep(250);
  const s = await scrollX(page);
  r[`scroll_${name}`] = s;
  check(s <= 0, `가로 스크롤 ${name}`, s);
  await page.screenshot({ path: path.join(OUT, `P28A_${name}.png`), fullPage: true });
  await page.setViewport({ width: 1280, height: 900 });
};
const writeTmp = (name, content) => { const f = path.join(TMP, name); fs.writeFileSync(f, content); return f; };
const choose = async (page, file, encoding = 'auto') => {
  const input = await page.$('[data-file]');
  await input.uploadFile(file);
  await page.select('[data-encoding]', encoding);
};
const previewByKeyboard = async (page) => {
  await page.focus('[data-encoding]');
  await page.keyboard.press('Tab'); // 미리보기 단추로
  const focused = await page.evaluate(() => document.activeElement?.hasAttribute('data-preview-btn'));
  await Promise.all([page.waitForResponse((res) => res.url().includes('/account-import/preview')), page.keyboard.press('Enter')]);
  await page.waitForSelector('[data-step="preview"]:not([hidden])');
  await sleep(200);
  return focused;
};
const tableRows = (page, sel = '[data-step="preview"] tbody tr') => page.$$eval(sel, (trs) => trs.map((tr) => ({
  row: tr.dataset.row, status: tr.dataset.status ?? tr.dataset.result, cells: [...tr.cells].map((c) => c.textContent.replace(/\s+/g, ' ').trim()), icon: !!tr.querySelector('svg'),
})));

try {
  const admin = await session(adminLogin);
  const a = admin.page;
  try {
    // 1. 사용자 관리 → 일괄 처리 화면
    await a.goto(`${BASE}/admin/users`, { waitUntil: 'networkidle0' });
    r.link = await a.$eval('[data-import-link]', (n) => [n.textContent.trim(), n.getAttribute('href')]).catch(() => null);
    check(r.link?.[0] === 'CSV로 일괄 처리' && /\/admin\/users\/import$/.test(r.link?.[1] ?? ''), '사용자 관리의 "CSV로 일괄 처리" 링크', r.link);
    await Promise.all([a.waitForNavigation({ waitUntil: 'networkidle0' }), a.click('[data-import-link]')]);
    r.steps = await a.$$eval('[data-step-mark]', (ls) => ls.map((l) => [l.textContent.replace(/\s+/g, ' ').trim(), l.getAttribute('aria-current')]));
    check(JSON.stringify(r.steps) === JSON.stringify([['1 파일 선택', 'step'], ['2 미리보기', null], ['3 결과', null]]), '3단계 표시(1단계 현재)', r.steps);
    await shot(a, 'file_1280');
    // 2. 양식 내려받기(링크 주소를 같은 세션으로 받아 내용 확인) → 편집
    const tplHref = await a.$eval('[data-template]', (n) => n.getAttribute('href'));
    const tpl = await a.evaluate(async (u) => { const res = await fetch(u, { credentials: 'same-origin' }); const b = new Uint8Array(await res.arrayBuffer()); return { status: res.status, type: res.headers.get('content-type'), disp: res.headers.get('content-disposition'), bom: b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF, body: new TextDecoder('utf-8', { ignoreBOM: true }).decode(b) }; }, tplHref);
    r.template = [tpl.status, tpl.type, tpl.disp, tpl.bom, tpl.body.split('\r\n')[0]];
    check(tpl.status === 200 && /text\/csv/.test(tpl.type) && /accounts_template\.csv/.test(tpl.disp) && tpl.bom && tpl.body.split('\r\n')[0] === '\uFEFFaction,login_id,display_name,email,role,auth_type', '양식 내려받기(UTF-8 BOM)', r.template);
    const lines = tpl.body.replace(/^\uFEFF/, '').trim().split('\r\n');
    const csv = [lines[0],
      `create,p28a.${tag}.a,Alpha ${tag},alpha.${tag}@example.com,user,local`,
      `create,p28a.${tag}.b,=Beta ${tag},,admin,`, // 관리자(경고) + 수식 문자로 시작하는 표시 이름(결과 CSV에서 작은따옴표)
      `create,p28a.${tag}.c,Gamma ${tag},gamma.${tag}@example.invalid,,`,
      `create,${userLogin},Dup,,,`, // 기존 계정(오류)
      `create,p28a.${tag}.d,Ldap,,,ldap`, // 켜지지 않은 인증 방식(오류)
      `deactivate,p28a.${tag}.none,,,,`, // 없는 계정(오류)
    ].join('\r\n');
    const file = writeTmp('accounts.csv', `\uFEFF${csv}\r\n`);
    await choose(a, file);
    r.keyboardPreview = await previewByKeyboard(a);
    r.steps2 = await a.$$eval('[data-step-mark]', (ls) => ls.map((l) => l.getAttribute('aria-current')));
    r.encoding = await text(a, '[data-encoding-text]');
    r.summary = await a.$$eval('[data-summary] li', (ls) => ls.map((l) => l.textContent.trim()));
    r.rows = await tableRows(a);
    check(r.keyboardPreview && JSON.stringify(r.steps2) === JSON.stringify([null, 'step', null]) && r.encoding === '인식한 인코딩: UTF-8 (BOM 있음)', '키보드로 미리보기·2단계·인코딩', [r.keyboardPreview, r.steps2, r.encoding]);
    check(JSON.stringify(r.summary) === JSON.stringify(['6행', '생성 3', '오류 3', '경고 1', '관리자 생성 1']), '요약', r.summary);
    check(r.rows.length === 6 && r.rows.every((x) => x.icon) && r.rows[1].cells[5] === '경고' && /관리자 계정을 만듭니다/.test(r.rows[1].cells[6])
      && r.rows[3].cells[5] === '오류' && /이미 있는 계정/.test(r.rows[3].cells[6]) && /아직 켜지지 않은 인증 방식/.test(r.rows[4].cells[6]) && /없는 계정/.test(r.rows[5].cells[6]),
    '행별 판정(아이콘 + 글자·사유)', r.rows.map((x) => x.cells.slice(4)));
    r.blocked = [await a.$eval('[data-execute]', (b) => b.disabled), await text(a, '[data-run-note]')];
    check(r.blocked[0] && /오류 행이 있어 실행할 수 없습니다/.test(r.blocked[1] ?? ''), '오류가 있으면 실행 막힘', r.blocked);
    // 필터: 라디오 방향키(전체 → 오류만)
    await a.focus('#ai-filter-all');
    await a.keyboard.press('ArrowRight');
    await sleep(150);
    r.filterError = (await tableRows(a)).map((x) => x.status);
    await a.keyboard.press('ArrowRight');
    await sleep(150);
    r.filterWarn = (await tableRows(a)).map((x) => x.status);
    await a.keyboard.press('ArrowRight');
    await sleep(150);
    check(JSON.stringify(r.filterError) === JSON.stringify(['error', 'error', 'error']) && JSON.stringify(r.filterWarn) === JSON.stringify(['warning']), '보기 조건(오류만·경고만, 방향키)', [r.filterError, r.filterWarn]);
    await shot(a, 'preview_1280');
    await shot(a, 'preview_1024', 1024);
    // 오류 행 제외(Space) → 실행(Enter) → 확인 대화상자(관리자 생성 문구, Tab으로 확인 단추)
    await a.focus('[data-skip-errors]');
    await a.keyboard.press('Space');
    await sleep(150);
    r.enabled = await a.$eval('[data-execute]', (b) => !b.disabled);
    await a.focus('[data-execute]');
    await a.keyboard.press('Enter');
    await a.waitForSelector('dialog[open]');
    r.confirm = await text(a, 'dialog[open] p');
    await a.screenshot({ path: path.join(OUT, 'P28A_confirm.png') });
    check(r.enabled && /계정 3개를 만들고 0개를 비활성화합니다/.test(r.confirm ?? '') && /관리자 1명을 만듭니다/.test(r.confirm ?? '') && /오류 3행은 건너뜁니다/.test(r.confirm ?? ''), '오류 행 제외 → 확인 대화상자 문구', [r.enabled, r.confirm]);
    await a.keyboard.press('Tab');
    await Promise.all([a.waitForResponse((res) => res.url().includes('/account-import/execute'), { timeout: 120000 }), a.keyboard.press('Enter')]);
    await a.waitForSelector('[data-step="result"]:not([hidden])');
    await sleep(200);
    r.result = [await text(a, '[data-result-summary]'), await a.$$eval('[data-step-mark]', (ls) => ls.map((l) => l.getAttribute('aria-current')))];
    r.resultRows = await tableRows(a, '[data-result-table] tbody tr');
    const created = r.resultRows.filter((x) => x.status === 'created');
    const temps = Object.fromEntries(created.map((x) => [x.cells[2], x.cells[5]]));
    // 출력(JSON)·캡처에 임시 비밀번호를 남기지 않는다(P28A 규칙) — 비교에만 쓰고 출력할 값은 가림
    const masked = r.resultRows.map((x) => ({ ...x, cells: x.cells.map((c, i) => (i === 5 && c ? '(hidden)' : c)) }));
    check(/생성 3개, 비활성화 0개, 건너뜀 0행, 오류로 건너뜀 3행/.test(r.result[0] ?? '') && r.result[1][2] === 'step' && created.length === 3 && created.every((x) => x.cells[5].length >= 12),
      '결과(3단계, 임시 비밀번호 표시)', [r.result, r.resultRows.map((x) => [x.cells[2], x.status, x.cells[5] ? '(있음)' : ''])]);
    // 결과 CSV 한 번
    const cdp = await a.target().createCDPSession();
    await cdp.send('Browser.setDownloadBehavior', { behavior: 'allow', downloadPath: TMP });
    await a.click('[data-result-csv]');
    await sleep(1200);
    const dl = fs.readdirSync(TMP).find((f) => f.startsWith('accounts_result_'));
    const dlText = dl ? fs.readFileSync(path.join(TMP, dl), 'utf8') : '';
    r.resultCsv = [dl ?? null, await a.$eval('[data-result-csv]', (b) => b.disabled), await text(a, '[data-result-csv-done]')];
    const betaLine = dlText.split('\r\n').find((l) => l.includes(`p28a.${tag}.b`)) ?? '';
    check(!!dl && dlText.startsWith('\uFEFFrow,') && betaLine.includes(`'=Beta ${tag}`) && betaLine.includes(temps[`p28a.${tag}.b`]) && r.resultCsv[1] && /다시 내려받을 수 없습니다/.test(r.resultCsv[2] ?? ''),
      '결과 CSV 한 번(수식 문자 작은따옴표·비밀번호 열)', [r.resultCsv, betaLine.replace(temps[`p28a.${tag}.b`] ?? '§', '(비밀번호)')]);
    r.resultRows = masked;
    await a.$$eval('.account-import__secret', (cells) => { for (const c of cells) if (c.textContent) c.textContent = '••••••••••••••'; }); // 캡처용 가림(화면 동작과 무관)
    await shot(a, 'result_1280');
    // 새로 고치면 비밀번호가 사라지고 1단계
    await a.reload({ waitUntil: 'networkidle0' });
    r.afterReload = [await a.$eval('[data-step="result"]', (s) => s.hidden), await a.evaluate((v) => document.body.textContent.includes(v), temps[`p28a.${tag}.a`])];
    check(r.afterReload[0] && !r.afterReload[1], '새로 고치면 임시 비밀번호 사라짐', r.afterReload);
    // 새 계정으로 첫 로그인 → 비밀번호 변경 강제
    const nu = await session(`p28a.${tag}.a`, temps[`p28a.${tag}.a`]);
    r.firstLogin = [new URL(nu.page.url()).pathname, (await apiCall(nu.page, 'GET', '/api/v1/projects')).json?.error?.code];
    await nu.page.screenshot({ path: path.join(OUT, 'P28A_first_login.png') });
    await nu.browser.close();
    check(/\/password$/.test(r.firstLogin[0]) && r.firstLogin[1] === 'password_change_required', '새 계정 첫 로그인 = 비밀번호 변경 강제', r.firstLogin);

    // 3. 미리보기 뒤 충돌 → 실행해도 반영 없음 → 다시 미리보기(오류로 보임)
    await a.goto(`${BASE}/admin/users/import`, { waitUntil: 'networkidle0' });
    const f2 = writeTmp('conflict.csv', `login_id,display_name\np28a.${tag}.x,X\np28a.${tag}.y,Y\n`);
    await choose(a, f2);
    await previewByKeyboard(a);
    const made = await apiCall(a, 'POST', '/api/v1/users', { login_id: `P28A.${tag}.X`, display_name: 'Someone' });
    check(made.status === 201, '충돌용 계정 생성', made.status);
    await a.click('[data-execute]');
    await a.waitForSelector('dialog[open]');
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction(() => !document.querySelector('[data-error]')?.hidden, { timeout: 15000 });
    await sleep(300);
    r.stale = [await text(a, '[data-error]'), (await tableRows(a)).map((x) => x.status), (await apiCall(a, 'GET', `/api/v1/users?q=p28a.${tag}.y`)).json?.data?.length];
    check(/미리보기 이후 파일이나 계정이 바뀌어 아무것도 반영하지 않았습니다/.test(r.stale[0] ?? '') && JSON.stringify(r.stale[1]) === JSON.stringify(['error', 'ok']) && r.stale[2] === 0,
      '미리보기 뒤 충돌 → 반영 없음·다시 미리보기', r.stale);
    await a.screenshot({ path: path.join(OUT, 'P28A_stale.png') });

    // 4. CP949(한국어 Excel 저장) — 자동 판별
    await a.click('[data-restart]');
    const name = '\uC774\uC11C\uC900';
    const cp = Buffer.from([...Buffer.from(`login_id,display_name\np28a.${tag}.k,`, 'ascii'), 0xC0, 0xCC, 0xBC, 0xAD, 0xC1, 0xD8, 0x0A]); // "이서준"(CP949)
    await choose(a, writeTmp('cp949.csv', cp));
    await previewByKeyboard(a);
    r.cp949 = [await text(a, '[data-encoding-text]'), (await tableRows(a))[0]?.cells[3]];
    check(r.cp949[0] === '인식한 인코딩: CP949(Excel 한국어)' && r.cp949[1] === name, 'CP949 자동 판별', r.cp949);
    // 5. 1,000행 쪽 나누기
    await a.click('[data-restart]');
    let big = 'login_id,display_name\n';
    for (let i = 1; i <= 1000; i++) big += `p28a.${tag}.n${i},N ${i}\n`;
    await choose(a, writeTmp('big.csv', big));
    await previewByKeyboard(a);
    r.big = [await text(a, '[data-summary] li'), (await tableRows(a)).length, await text(a, '.account-import__pager [role="status"]')];
    await a.click('[data-page-next]');
    await sleep(150);
    r.big.push(await text(a, '.account-import__pager [role="status"]'), (await tableRows(a))[0]?.row);
    check(r.big[0] === '1000행' && r.big[1] === 100 && r.big[2] === '1–100 / 1000행' && r.big[3] === '101–200 / 1000행' && r.big[4] === '102', '1,000행 쪽 나누기', r.big);
  } finally {
    await admin.browser.close();
  }

  // 6. 언어·테마 × 폭 캡처(계정 화면 설정을 API로 바꿨다가 비움)
  for (const [lang, theme] of [['en', 'light'], ['ko', 'dark'], ['en', 'dark']]) {
    const s = await session(adminLogin);
    const p = s.page;
    try {
      await apiCall(p, 'PUT', '/api/v1/users/me/preferences', { language: lang, theme });
      await p.goto(`${BASE}/admin/users/import`, { waitUntil: 'networkidle0' });
      await choose(p, path.join(TMP, 'accounts.csv'));
      await previewByKeyboard(p);
      r[`lang_${lang}_${theme}`] = await p.evaluate(() => [document.documentElement.lang, document.documentElement.dataset.theme ?? '', document.querySelector('[data-summary] li')?.textContent]);
      check(r[`lang_${lang}_${theme}`][0] === lang && r[`lang_${lang}_${theme}`][1] === theme, `언어·테마 ${lang} ${theme}`, r[`lang_${lang}_${theme}`]);
      await shot(p, `preview_${lang}_${theme}_1280`);
      await shot(p, `preview_${lang}_${theme}_1024`, 1024);
    } finally {
      await apiCall(p, 'PUT', '/api/v1/users/me/preferences', { language: null, theme: null }).catch(() => null);
      await s.browser.close();
    }
  }

  // 7. 일반 사용자: 화면 403·링크 없음·API 403
  const u = await session(userLogin);
  try {
    const res = await u.page.goto(`${BASE}/admin/users/import`, { waitUntil: 'networkidle0' });
    r.user = [res.status(), await u.page.$('[data-import-link]') !== null, (await apiCall(u.page, 'GET', '/api/v1/admin/account-import/template')).status];
    check(r.user[0] === 403 && !r.user[1] && r.user[2] === 403, '일반 사용자: 화면 403·링크 없음·API 403', r.user);
  } finally {
    await u.browser.close();
  }
} finally {
  fs.rmSync(TMP, { recursive: true, force: true });
}
console.log(JSON.stringify(r, null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${problems.join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
