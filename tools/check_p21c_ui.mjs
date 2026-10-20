// P21C 화면 보완 브라우저 점검(헤드리스 Chrome). **격리 서버·DB 사본·격리 워커에서만** 쓴다(설정을 바꾸고 업로드를 실제로 한다).
//   PJG_PASSWORD=... node tools/check_p21c_ui.mjs <기본 주소> <IP 주소로 연 같은 서버> <일반 사용자 로그인> <Python 프로젝트 id> [관리자 로그인]
//   전제: 격리 워커가 실행 중(tools/worker.php --config=<점검 설정>). Python 프로젝트에 업로드 권한이 있는 관리자.
// 확인(1280·1024px):
//   1. 시스템 관리 → 분석 도구: Node.js "상태 갱신" — 누르는 동안 aria-busy·비활성, 연타해도 요청 하나, 워커가 다시 확인한 결과와 시각
//   2. 점검 탭 "환경": 상태 아이콘 계산 색 = 토큰(정상 녹색·경고 주황·문제 빨강) + 글자, 흰 배경 대비 3:1 이상
//   3. 프로젝트 표: Py·JS/TS 배지 너비 같음(1px)
//   4. 대시보드: 선택 상자 4개가 콤보박스(role, aria-expanded, 입력으로 좁힘, 일치 없음, ↓·Enter·Esc), 언어 필터(주소 유지·추이/규칙 상위 요청에 language,
//      추이 프로젝트 목록이 그 언어만), admin "표시 프로젝트 수" 대화상자(바꾸면 추이 개수 반영, 끝나면 원래 값으로), 일반 사용자는 버튼 없음·API 403
//   5. 업로드: 같은 zip 두 번 → 두 번째에 "직전 업로드와 같은 파일" 확인 대화상자·요약(정상·파일 수·해시 12자리), 첫 번째는 요약만
//   6. 상단 메뉴: 기본 주소와 IP 주소에서 메뉴 글자 수가 같고(1280·1024) 정적 파일 상태 코드가 같음
//   콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21C_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, ipBase, userLogin, pyProject, adminLogin = 'admin'] = process.argv;
if (!ipBase || !userLogin || !pyProject || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_p21c_ui.mjs <기본 주소> <IP 주소> <일반 사용자> <Python 프로젝트 id> [관리자]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
// 최상위 폴더가 있는 zip(개발 DB의 Python 프로젝트 규칙과 같아 경고 없이 대기열로 → 두 번째가 '직전과 같음')
const ZIP = path.join(ROOT, 'samples/zips/python_sample_toplevel.zip');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
let allow400 = false; // 범위 밖 값(51) 저장 시험의 400은 기대한 응답

async function session(login, base = BASE) {
  const page = await (await browser.createBrowserContext()).newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of 403/.test(m.text()) || (allow400 && /status of 400/.test(m.text()))) return; // 일반 사용자 403, 범위 밖 저장 400은 시험 대상
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('response', (res) => { if (res.status() >= 400 && res.status() !== 403 && !(allow400 && res.status() === 400)) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const api = (page, url, init = {}) => page.evaluate(async (u, i) => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const res = await fetch(u, { ...i, headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } });
  return { status: res.status, body: await res.json().catch(() => null) };
}, url, init);

const admin = await session(adminLogin);

// ---- 1. Node.js 상태 갱신 ----
console.error('[1] Node.js 상태 갱신');
{
  await admin.goto(`${BASE}/admin/tools`, { waitUntil: 'networkidle0' });
  await admin.waitForSelector('[data-node] dd');
  let posts = 0;
  const onReq = (q) => { if (q.method() === 'POST' && q.url().includes('/node/refresh')) posts += 1; };
  admin.on('request', onReq);
  const before = await admin.$eval('[data-node]', (n) => n.textContent);
  await admin.click('[data-node-refresh]');
  r.nodeBusy = await admin.$eval('[data-node-refresh]', (b) => [b.getAttribute('aria-busy'), b.disabled]);
  await admin.click('[data-node-refresh]').catch(() => {}); // 연타(비활성이라 무시되어야 함)
  await admin.evaluate(() => document.querySelector('[data-node-refresh]').click());
  await admin.waitForFunction(() => /다시 확인했습니다|응답이 없습니다|멈춰 있어/.test(document.querySelector('[data-node-refresh-status]').textContent), { timeout: 15000 });
  admin.off('request', onReq);
  r.node = { posts, status: await admin.$eval('[data-node-refresh-status]', (n) => n.textContent), before, after: await admin.$eval('[data-node]', (n) => n.textContent),
    busyAfter: await admin.$eval('[data-node-refresh]', (b) => b.getAttribute('aria-busy')) };
  check(r.nodeBusy[0] === 'true' && r.nodeBusy[1] === true && posts === 1 && r.node.status.includes('다시 확인했습니다') && r.node.busyAfter === null, 'Node 상태 갱신', { busy: r.nodeBusy, ...r.node });
  await (await admin.$('section[aria-labelledby="node-title"]')).screenshot({ path: path.join(OUT, 'P21C_node_refresh.png') });
}

// ---- 2. 점검 탭 환경 상태 색 ----
console.error('[2] 점검 탭 환경 상태 색');
{
  await admin.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
  await admin.waitForSelector('[data-env] tr');
  r.env = await admin.evaluate(() => {
    const css = getComputedStyle(document.documentElement);
    const tok = (n) => css.getPropertyValue(n).trim();
    const rgb = (hex) => { const h = hex.replace('#', ''); return `rgb(${parseInt(h.slice(0, 2), 16)}, ${parseInt(h.slice(2, 4), 16)}, ${parseInt(h.slice(4, 6), 16)})`; };
    const want = { ok: rgb(tok('--status-icon-ok')), warn: rgb(tok('--status-icon-warn')), fail: rgb(tok('--status-icon-fail')) };
    const lum = (s) => { const [r1, g, b] = s.match(/\d+/g).map((x) => Number(x) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4)); return 0.2126 * r1 + 0.7152 * g + 0.0722 * b; };
    // 이 환경에 "문제" 행이 없을 수 있어 같은 규칙의 칸을 하나 만들어 계산 색을 본다(바로 지움)
    const probe = document.createElement('td');
    probe.className = 'status-cell';
    probe.dataset.status = 'fail';
    probe.append(document.querySelector('[data-env] td.status-cell svg').cloneNode(true));
    document.querySelector('[data-env] tr').append(probe);
    const probeColor = getComputedStyle(probe.querySelector('svg')).color;
    probe.remove();
    const rows = [...document.querySelectorAll('[data-env] td.status-cell')].map((td) => {
      const color = getComputedStyle(td.querySelector('svg')).color;
      return { status: td.dataset.status, color, label: td.textContent.trim(), contrast: Math.round(((1.05) / (lum(color) + 0.05)) * 100) / 100 };
    });
    rows.push({ status: 'fail', color: probeColor, label: '(시험 칸)', contrast: Math.round(((1.05) / (lum(probeColor) + 0.05)) * 100) / 100 });
    return { want, rows };
  });
  const bad = r.env.rows.filter((x) => x.color !== r.env.want[x.status] || !x.label || x.contrast < 3);
  check(r.env.rows.length > 0 && bad.length === 0, '환경 상태 색·글자·대비', bad);
  r.envStatuses = [...new Set(r.env.rows.map((x) => `${x.status}:${x.label}:${x.color}:${x.contrast}`))];
  await (await admin.$('[data-env]')).screenshot({ path: path.join(OUT, 'P21C_env_colors.png') });
}

// ---- 3. 프로젝트 표 언어 배지 너비 ----
console.error('[3] 프로젝트 표 언어 배지 너비');
for (const width of [1280, 1024]) {
  await admin.setViewport({ width, height: 900 });
  await admin.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  const w = await admin.$$eval('table .lang-badge', (ns) => ns.map((n) => ({ lang: n.className.replace(/.*lang-badge--/, ''), w: Math.round(n.getBoundingClientRect().width * 10) / 10 })));
  r[`badges@${width}`] = w;
  const ws = w.map((x) => x.w);
  check(new Set(w.map((x) => x.lang)).size >= 2 && Math.max(...ws) - Math.min(...ws) <= 1, `언어 배지 너비 ${width}`, w);
  if (width === 1280) await (await admin.$('table')).screenshot({ path: path.join(OUT, 'P21C_lang_badges.png') });
}
await admin.setViewport({ width: 1280, height: 900 });

// ---- 4. 대시보드: 콤보박스·언어 필터·표시 개수 ----
console.error('[4] 대시보드: 콤보박스·언어 필터·표시 개수');
{
  const trendReqs = [];
  const onRes = async (res) => {
    if (res.url().includes('/api/v1/dashboard/trend') && res.request().method() === 'GET') {
      const j = await res.json().catch(() => null);
      trendReqs.push({ url: res.url(), language: j?.data?.language ?? null, limit: j?.data?.project_limit, series: (j?.data?.series ?? []).map((s) => s.project_name) });
    }
  };
  admin.on('response', onRes);
  await admin.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  r.combos = await admin.$$eval('[data-combobox]', (ns) => ns.map((n) => ({ id: n.id, role: n.getAttribute('role'), expanded: n.getAttribute('aria-expanded'), controls: n.getAttribute('aria-controls') })));
  check(r.combos.length === 4 && r.combos.every((c) => c.role === 'combobox' && c.expanded === 'false' && c.controls), '콤보박스 4개', r.combos);
  const listOf = (id) => admin.$$eval(`#${id}-list li`, (ns) => ns.map((n) => n.textContent));
  await admin.focus('#ds-language');
  r.langOpen = { expanded: await admin.$eval('#ds-language', (n) => n.getAttribute('aria-expanded')), items: await listOf('ds-language') };
  await admin.keyboard.type('py');
  r.langFiltered = await listOf('ds-language');
  {
    const fb = await (await admin.$('[data-language-filter]')).boundingBox();
    await admin.screenshot({ path: path.join(OUT, 'P21C_dashboard_combobox.png'), clip: { x: 0, y: Math.max(0, fb.y - 10), width: 1280, height: 260 }, captureBeyondViewport: true });
  }
  trendReqs.length = 0;
  await Promise.all([admin.waitForResponse((x) => x.url().includes('/dashboard/trend')), admin.keyboard.press('Enter')]);
  await admin.waitForNetworkIdle();
  r.langApplied = { url: new URL(admin.url()).search, input: await admin.$eval('#ds-language', (n) => n.value), trend: trendReqs.at(-1),
    trendOptions: await admin.$$eval('#ds-trend-project-native option:not([hidden])', (ns) => ns.map((o) => o.dataset.language ?? '')) };
  check(r.langOpen.expanded === 'true' && r.langOpen.items.length === 3 && r.langFiltered.length === 1 && r.langApplied.url.includes('language=python')
    && r.langApplied.trend?.language === 'python' && r.langApplied.trendOptions.every((l) => l === '' || l === 'python'), '언어 필터', { ...r.langOpen, filtered: r.langFiltered, ...r.langApplied });
  // 일치 없음, Esc는 값 그대로
  await admin.click('#ds-trend-project');
  await admin.keyboard.type('없는프로젝트이름');
  r.noMatch = await listOf('ds-trend-project');
  await admin.keyboard.press('Escape');
  r.afterEsc = { value: await admin.$eval('#ds-trend-project', (n) => n.value), expanded: await admin.$eval('#ds-trend-project', (n) => n.getAttribute('aria-expanded')) };
  check(r.noMatch.join() === '일치하는 항목 없음' && r.afterEsc.expanded === 'false' && r.afterEsc.value.startsWith('최근 분석한 프로젝트'), '일치 없음·Esc', { noMatch: r.noMatch, ...r.afterEsc });
  // 새로 고침해도 언어 유지
  await admin.reload({ waitUntil: 'networkidle0' });
  r.reloadLang = await admin.$eval('#ds-language', (n) => n.value);
  check(r.reloadLang === 'Python(ruff)', '주소에서 언어 복원', r.reloadLang);
  // 표시 프로젝트 수: 전체 언어에서 1로 → 추이 1개 → 원래 값으로
  await admin.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  const original = Number(await admin.$eval('[data-trend-limit]', (b) => b.dataset.value));
  await admin.click('[data-trend-limit]');
  await admin.waitForSelector('dialog[open] input[type=number]');
  await admin.$eval('dialog[open] input[type=number]', (n) => { n.value = ''; });
  await admin.type('dialog[open] input[type=number]', '51');
  allow400 = true;
  await admin.click('dialog[open] button[type=submit]');
  await admin.waitForFunction(() => !document.querySelector('dialog[open] .field__error')?.hidden);
  await sleep(200);
  allow400 = false;
  r.limitError = await admin.$eval('dialog[open] .field__error', (n) => n.textContent);
  await admin.screenshot({ path: path.join(OUT, 'P21C_trend_limit_dialog.png') });
  await admin.$eval('dialog[open] input[type=number]', (n) => { n.value = ''; });
  await admin.type('dialog[open] input[type=number]', '1');
  trendReqs.length = 0;
  await Promise.all([admin.waitForResponse((x) => x.url().includes('/dashboard/trend')), admin.click('dialog[open] button[type=submit]')]);
  await admin.waitForNetworkIdle();
  r.limit = { button: await admin.$eval('[data-trend-limit]', (b) => b.textContent.trim()), trend: trendReqs.at(-1) };
  check(r.limitError.includes('1~50') && r.limit.trend?.limit === 1 && r.limit.trend.series.length <= 1 && r.limit.button.includes('1개'), '표시 프로젝트 수', { error: r.limitError, ...r.limit });
  const back = await api(admin, `${BASE}/api/v1/admin/settings`, { method: 'PATCH', body: JSON.stringify({ 'dashboard.trend_projects': original }) });
  check(back.status === 200, '원래 값으로', back.status);
  admin.off('response', onRes);
}
{
  const user = await session(userLogin);
  await user.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  r.userDash = { button: await user.$('[data-trend-limit]') === null, combos: await user.$$eval('[data-combobox]', (ns) => ns.length),
    patch: (await api(user, `${BASE}/api/v1/admin/settings`, { method: 'PATCH', body: JSON.stringify({ 'dashboard.trend_projects': 3 }) })).status,
    refresh: (await api(user, `${BASE}/api/v1/admin/tools/node/refresh`, { method: 'POST', body: '{}' })).status };
  check(r.userDash.button && r.userDash.combos === 4 && r.userDash.patch === 403 && r.userDash.refresh === 403, '일반 사용자', r.userDash);
  await user.close();
}

// ---- 5. 업로드: 같은 zip 두 번 ----
console.error('[5] 업로드: 같은 zip 두 번');
async function uploadOnce() {
  await admin.goto(`${BASE}/upload?project=${pyProject}`, { waitUntil: 'networkidle0' });
  await admin.select('#up-project', String(pyProject)).catch(() => {});
  const input = await admin.$('#up-file');
  await input.uploadFile(ZIP);
  await admin.waitForFunction(() => !document.querySelector('#up-start').disabled, { timeout: 10000 });
  await admin.click('#up-start');
  await admin.waitForFunction(() => !document.querySelector('#up-content').hidden || !document.querySelector('#up-error').hidden, { timeout: 60000 });
  return admin.evaluate(() => ({
    previous: document.querySelector('#up-summary-previous').dataset.previous, previousText: document.querySelector('#up-summary-previous').textContent.trim(),
    rows: [...document.querySelectorAll('#up-summary-list dt')].map((dt) => `${dt.textContent}=${dt.nextElementSibling.textContent}`),
    hash: document.querySelector('#up-summary-hash').textContent, hashFull: document.querySelector('#up-summary-hash').title.length,
    dialog: document.querySelector('dialog[open]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
    error: document.querySelector('#up-error').hidden ? null : document.querySelector('#up-error').textContent,
  }));
}
r.upload1 = await uploadOnce();
await (await admin.$('#up-content')).screenshot({ path: path.join(OUT, 'P21C_upload_summary.png') });
r.upload2 = await uploadOnce();
await admin.waitForSelector('dialog[open]', { timeout: 10000 }).catch(() => {});
r.upload2.dialog = await admin.evaluate(() => document.querySelector('dialog[open]')?.textContent.replace(/\s+/g, ' ').trim() ?? null);
await admin.screenshot({ path: path.join(OUT, 'P21C_upload_same_dialog.png') });
check(r.upload1.error === null && r.upload1.rows[0].includes('정상') && /^[0-9a-f]{12}…$/.test(r.upload1.hash) && r.upload1.hashFull === 64
  && r.upload2.previous === 'same' && (r.upload2.dialog ?? '').includes('직전 업로드'), '업로드 요약·같은 파일', { u1: r.upload1, u2: r.upload2 });
// 대화상자 "나중에" → 패널의 "업로드 취소"로 정리(사본 DB라도 분석이 늘지 않게)
await admin.click('dialog[open] .dialog__actions button:not(.btn--danger):not(.btn--primary)').catch(() => {});
await sleep(300);
await admin.click('#up-cancel-warning').catch(() => {});
await admin.waitForSelector('dialog[open]').catch(() => {});
await admin.click('dialog[open] .btn--danger').catch(() => {});
await sleep(500);
await (await admin.$('#up-content')).screenshot({ path: path.join(OUT, 'P21C_upload_same_summary.png') }).catch(() => {});

// ---- 6. 상단 메뉴: 기본 주소와 IP ----
console.error('[6] 상단 메뉴: 기본 주소와 IP');
const menu = async (page, base) => {
  const out = {};
  for (const width of [1280, 1024]) {
    await page.setViewport({ width, height: 800 });
    await page.goto(`${base}/dashboard`, { waitUntil: 'networkidle0' });
    out[width] = await page.$$eval('.topbar__nav .nav-item .icon-label__text', (ns) => ns.filter((n) => n.getBoundingClientRect().width > 2).length);
  }
  return out;
};
const ipPage = await session(adminLogin, ipBase);
const assets = {};
ipPage.on('response', (res) => { if (res.url().includes('/assets/')) assets[res.status()] = (assets[res.status()] ?? 0) + 1; });
r.menu = { base: await menu(admin, BASE), ip: await menu(ipPage, ipBase), ipAssets: assets };
check(r.menu.base[1280] === r.menu.ip[1280] && r.menu.base[1024] === r.menu.ip[1024] && r.menu.ip[1024] > 0 && Object.keys(assets).join() === '200', '메뉴 글자(IP = 기본 주소)', r.menu);
await ipPage.setViewport({ width: 1024, height: 800 });
await ipPage.goto(`${ipBase}/dashboard`, { waitUntil: 'networkidle0' });
await ipPage.screenshot({ path: path.join(OUT, 'P21C_menu_ip_1024.png'), clip: { x: 0, y: 0, width: 1024, height: 200 } });
await admin.setViewport({ width: 1024, height: 800 });
await admin.screenshot({ path: path.join(OUT, 'P21C_menu_base_1024.png'), clip: { x: 0, y: 0, width: 1024, height: 200 } });

await sleep(200);
await browser.close();
console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건`);
for (const c of csp) console.log(`  ${c}`);
console.log(`문제 ${problems.length}건`);
for (const p of problems) console.log(`  ${p}`);
process.exit(csp.length || problems.length ? 1 : 0);
