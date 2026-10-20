// 시스템 모니터링 화면 브라우저 점검(P30, 헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo — 임시 폴더·새 DB·가짜 계정)을 별도 포트에 띄우고
// 격리 워커(같은 설치본 설정)를 돌린 뒤 점검하고, 끝나면 서버·워커를 멈추고 폴더를 지운다(실제 DB·설정·사용자 워커와 무관).
//   node tools/check_monitoring_ui.mjs [--port=8095] [--keep] [--no-shots]
// 흐름: 일반 사용자 — 화면·API 403, 메뉴·배너 없음. admin — 메뉴 활성화, 카드 9개 그림(값을 못 구한 카드만 "확인 불가"), 화면 경고 = API 경고 = 배너 건수 = /health 상태,
//   자동 갱신(갱신 시각 바뀜, 포커스·스크롤 유지), "지금 새로 고침", 경고 흉내(설치본 설정 disk.warn_percent=1 → disk_warn·배너, 워커 정지 → worker_down·/health 503),
//   한/영 × 라이트/다크 × 1280/1024 가로 스크롤 0, 콘솔 오류·CSP 위반 0, 성능(API 20회 p95, /health 20회 p95).
// X18 카드 공용 구조: 카드 순서, 모든 카드에 머리(아이콘·제목·배지)·핵심 값·세부·바닥 줄(확인 시각), 배지는 5종(정상·주의·오류·확인 불가·정보) 중 하나이고 아이콘 + 글자,
//   한 줄 카드 높이 차 1px 이내·바닥 줄 정렬, 안쪽 여백·테두리·간격 같음, 격자 열 수(1280 3 · 1024 2 · 640 1), 상태 흉내(인증서 확인 불가 → 정상 → 만료 임박 주의,
//   디스크 주의, 워커 정지 오류) 사이 카드 높이 그대로, 640 가로 스크롤 0. 캡처 X18_monitoring_1280·1024·dark·warn(--all-shots면 P30 때의 나머지 캡처도)
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8095);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const PREFIX = process.env.PJG_SHOT_PREFIX ?? 'X18';
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `mon_check_${randomBytes(4).toString('hex')}`);
const PW = `Demo-${randomBytes(9).toString('base64url')}1!`;
const BASE = `http://127.0.0.1:${PORT}`;
const INI = path.join(work, 'config.ini');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
let server = null;
let worker = null;
let browser = null;
const stopWorker = () => {
  try { execFileSync(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), '--stop', `--config=${INI}`], { stdio: 'ignore' }); } catch { /* 이미 멈춤 */ }
};
process.on('exit', () => {
  try { server?.kill(); } catch { /* 이미 끝남 */ }
  try { worker?.kill(); } catch { /* 이미 끝남 */ }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`점검 폴더를 지우지 못함: ${e.code}`); } }
});

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

mkdirSync(work, { recursive: true });
execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'pipe', 'inherit'] });
setIni('health', 'allowed_ips', '127.0.0.1');
setIni('monitoring', 'cache', '5s');
setIni('monitoring', 'refresh', '10s');
server = spawn(PHP, ['-d', 'extension=intl', '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: INI }, stdio: 'ignore' });
worker = spawn(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), `--config=${INI}`], { stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}
for (let i = 0; i < 50 && !existsSync(path.join(work, 'work/worker/heartbeat.json')); i += 1) await sleep(200);

async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of (40[34]|503)/.test(m.text())) problems.push(`console.${m.type()}(${login}): ${m.text()}`);
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
const json = (page, url) => page.evaluate(async (u) => { const res = await fetch(u, { credentials: 'same-origin' }); return { status: res.status, body: await res.json().catch(() => null) }; }, url);
const openMonitoring = async (page) => {
  await page.goto(`${BASE}/admin/monitoring`, { waitUntil: 'networkidle0' });
  await page.waitForFunction(() => document.querySelectorAll('[data-card][data-level]').length === 9, { timeout: 20000 });
};
const screenState = (page) => page.evaluate(() => ({
  status: document.querySelector('[data-status]')?.dataset.status ?? null,
  statusText: document.querySelector('[data-status]')?.textContent.trim() ?? '',
  updated: document.querySelector('[data-updated]')?.textContent.trim() ?? '',
  alerts: [...document.querySelectorAll('[data-alerts] [data-alert]')].map((n) => n.dataset.alert),
  levels: Object.fromEntries([...document.querySelectorAll('[data-card]')].map((n) => [n.dataset.card, n.dataset.level])),
  unavailable: [...document.querySelectorAll('[data-unavailable]')].map((n) => n.closest('[data-card]').dataset.card + ':' + n.dataset.unavailable),
  scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
}));
const bannerCount = (page) => page.evaluate(() => Number(document.querySelector('[data-health-banner]')?.dataset.count ?? 0));
const timing = (page, url, n = 20) => page.evaluate(async (u, count) => {
  const ms = [];
  for (let i = 0; i < count; i += 1) { const t0 = performance.now(); await (await fetch(u, { credentials: 'same-origin' })).text(); ms.push(performance.now() - t0); }
  ms.sort((a, b) => a - b);
  return { p50: Math.round(ms[Math.floor(count / 2)]), p95: Math.round(ms[Math.ceil(count * 0.95) - 1]) };
}, url, n);

// X18 카드 배치·구조 측정
const ORDER = ['worker', 'queue', 'disk', 'backup', 'tools', 'cert', 'schedules', 'failures', 'app'];
const layout = (page) => page.evaluate(() => {
  const grid = document.querySelector('.mon-grid');
  const gs = getComputedStyle(grid);
  const cards = [...grid.querySelectorAll(':scope > [data-card]')].map((n) => {
    const b = n.getBoundingClientRect();
    const cs = getComputedStyle(n);
    const badge = n.querySelector('.mon-card__head > [data-badge]');
    const foot = n.querySelector('.mon-card__foot');
    return {
      name: n.dataset.card, top: Math.round(b.top + window.scrollY), left: Math.round(b.left), right: Math.round(b.right), height: b.height,
      footBottom: foot ? foot.getBoundingClientRect().bottom - b.top : null,
      padding: cs.padding, border: `${cs.borderTopWidth} ${cs.borderRightWidth} ${cs.borderBottomWidth}`, radius: cs.borderRadius,
      section: n.tagName === 'SECTION' && n.getAttribute('aria-labelledby') === n.querySelector('h2')?.id,
      title: !!n.querySelector('.mon-card__head > h2 svg') && (n.querySelector('.mon-card__head > h2')?.textContent.trim() ?? '') !== '',
      badgeState: badge?.dataset.state ?? null, badgeIcon: !!badge?.querySelector('svg'), badgeText: badge?.textContent.trim() ?? '',
      value: !!n.querySelector(':scope > [data-value]') && n.querySelector(':scope > [data-value]').textContent.trim() !== '',
      detail: !!n.querySelector(':scope > [data-detail]'), foot: !!foot, checked: (n.querySelector('[data-checked]')?.textContent.trim() ?? '') !== '',
      tables: [...n.querySelectorAll('table')].map((t) => ({ caption: !!t.querySelector('caption'), rows: t.tBodies[0]?.rows.length ?? 0 })),
    };
  });
  return { cols: gs.gridTemplateColumns.split(' ').filter(Boolean).length, colGap: gs.columnGap, rowGap: gs.rowGap, cards,
    scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth };
});
/** 공용 구조·같은 여백·한 줄 높이 */
const structure = (lay, label) => {
  check(JSON.stringify(lay.cards.map((c) => c.name)) === JSON.stringify(ORDER), `카드 순서 ${label}`, lay.cards.map((c) => c.name));
  const bad = lay.cards.filter((c) => !(c.section && c.title && c.value && c.detail && c.foot && c.checked && c.badgeIcon && c.badgeText !== ''
    && ['ok', 'warn', 'fail', 'unknown', 'info'].includes(c.badgeState) && c.tables.every((t) => t.caption && t.rows <= 5)));
  check(bad.length === 0, `공용 구조(머리·배지·값·세부·바닥 줄·표 caption·최대 5행) ${label}`, bad);
  const first = lay.cards[0];
  check(lay.cards.every((c) => c.padding === first.padding && c.border === first.border && c.radius === first.radius), `안쪽 여백·테두리·모서리 같음 ${label}`,
    [...new Set(lay.cards.map((c) => `${c.padding}|${c.border}|${c.radius}`))]);
  const rows = {};
  for (const c of lay.cards) (rows[c.top] ??= []).push(c);
  const spread = Object.values(rows).map((row) => ({ names: row.map((c) => c.name), h: Math.max(...row.map((c) => c.height)) - Math.min(...row.map((c) => c.height)),
    foot: Math.max(...row.map((c) => c.footBottom)) - Math.min(...row.map((c) => c.footBottom)),
    gaps: row.slice(1).map((c, i) => c.left - row[i].right) }));
  check(spread.every((s) => s.h <= 1 && s.foot <= 1), `한 줄 카드 높이·바닥 줄 차 1px 이내 ${label}`, spread);
  check(spread.every((s) => s.gaps.every((g) => Math.abs(g - parseFloat(lay.colGap)) <= 1)) && lay.colGap === lay.rowGap, `카드 간격 같음 ${label}`, { spread, col: lay.colGap, row: lay.rowGap });
  return spread;
};
/** 상태가 바뀌어도 카드 높이 그대로 */
const sameHeights = (a, b, label) => {
  if (!a || !b) { check(false, `높이 비교 자료 없음 ${label}`, null); return; }
  const diff = a.cards.map((c, i) => ({ name: c.name, before: Math.round(c.height), after: Math.round(b.cards[i]?.height ?? -1) })).filter((d) => Math.abs(d.before - d.after) > 1);
  check(diff.length === 0, `상태가 바뀌어도 카드 높이 그대로(${label})`, diff);
};
const makeCert = (days) => {
  const file = path.join(work, `cert_${days}.pem`);
  execFileSync(PHP, ['-r', `require $argv[1] . '/src/bootstrap.php'; @mkdir($argv[2], 0777, true); file_put_contents($argv[2] . '/openssl-min.cnf', "[ req ]\\ndistinguished_name = dn\\n[ dn ]\\n");
$k = PJGuardian\\Tests\\Support\\FakeSamlIdp::makeKey($argv[2] . '/openssl-min.cnf', 'monitoring.test', (int) $argv[3]); file_put_contents($argv[4], $k['cert']);`,
  ROOT, path.join(work, 'tmp'), String(days), file], { stdio: ['ignore', 'pipe', 'inherit'] });
  return file;
};
// 배지가 그 상태가 되고 "지금 새로 고침"이 끝날 때까지(갱신 시각 글자가 "새로 고치는 중"이 아님)
const waitBadge = (page, card, state) => page.waitForFunction((c, s) => document.querySelector(`[data-card="${c}"] [data-badge]`)?.dataset.state === s
  && !document.querySelector('[data-refresh-now]').hasAttribute('aria-busy'), { timeout: 15000 }, card, state);
try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const user = await session('alex');
  const admin = await session('demo-admin');
  check(await prefs(user, 'ko', 'light') === 200 && await prefs(admin, 'ko', 'light') === 200, '언어 ko', null);

  // 1. 일반 사용자: 403·메뉴·배너 없음
  const res403 = await user.goto(`${BASE}/admin/monitoring`, { waitUntil: 'networkidle0' });
  r.user = { page: res403.status(), api: (await json(user, '/api/v1/admin/monitoring')).status };
  await user.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.user.menu = await user.evaluate(() => !!document.querySelector('[data-nav="admin.system"]'));
  r.user.banner = await user.evaluate(() => !!document.querySelector('[data-health-banner]'));
  check(r.user.page === 403 && r.user.api === 403 && !r.user.menu && !r.user.banner, '일반 사용자 403·메뉴·배너 없음', r.user);

  // 2. admin: 메뉴 활성화 → 화면, 카드 9개, 판정 일치(화면 = API = 배너 = /health)
  await admin.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.menu = await admin.evaluate(() => ({ system: document.querySelector('a[data-nav="admin.system"]')?.getAttribute('href') ?? null, audit: document.querySelector('a[data-nav="admin.audit"]')?.getAttribute('href') ?? null,
    disabled: document.querySelectorAll('.topbar__nav--admin .is-disabled').length }));
  check(r.menu.system?.endsWith('/admin/monitoring') && r.menu.audit?.endsWith('/admin/audit-logs') && r.menu.disabled === 0, '관리자 메뉴 활성화', r.menu);
  await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('a[data-nav="admin.system"]')]);
  await admin.waitForFunction(() => document.querySelectorAll('[data-card][data-level]').length === 9, { timeout: 20000 });
  r.first = await screenState(admin);
  const api1 = (await json(admin, '/api/v1/admin/monitoring')).body.data;
  const health1 = await json(admin, '/health');
  r.firstApi = { status: api1.status, alerts: api1.alerts.map((a) => a.code), health: health1.status, healthStatus: health1.body?.status };
  await admin.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.firstBanner = await bannerCount(admin);
  check(r.first.status === api1.status && JSON.stringify(r.first.alerts) === JSON.stringify(r.firstApi.alerts) && r.firstBanner === api1.alerts.length
    && r.firstApi.healthStatus === api1.status && r.firstApi.health === (api1.status === 'fail' ? 503 : 200), '판정 일치(화면·API·배너·/health)', { screen: r.first, api: r.firstApi, banner: r.firstBanner });
  check(r.first.unavailable.every((u) => u.startsWith('cert:')), '확인 불가는 인증서(미설정)만', r.first.unavailable);
  check(!r.firstApi.alerts.includes('worker_down') && r.first.levels.worker === 'ok', '격리 워커 실행 중 → 워커 정상', r.first);

  // 2b. X18 카드 공용 구조 — 확인 불가(인증서 미설정) → 정상(1년 인증서) → 주의(만료 5일 전): 구조·한 줄 높이·간격, 상태가 바뀌어도 카드 높이 그대로
  await openMonitoring(admin);
  r.layoutNa = await layout(admin);
  r.spreadNa = structure(r.layoutNa, '확인 불가 1280');
  check(r.layoutNa.cols === 3 && r.layoutNa.cards.find((c) => c.name === 'cert').badgeState === 'unknown' && r.layoutNa.cards.find((c) => c.name === 'app').badgeState === 'info',
    '1280 3열·인증서 확인 불가·앱 정보 배지', r.layoutNa.cards.map((c) => `${c.name}:${c.badgeState}`));
  setIni('monitoring', 'tls_cert_file', makeCert(365).replace(/\\/g, '/'));
  await openMonitoring(admin);
  await admin.click('[data-refresh-now]');
  await waitBadge(admin, 'cert', 'ok');
  r.layoutCertOk = await layout(admin);
  structure(r.layoutCertOk, '인증서 정상 1280');
  sameHeights(r.layoutNa, r.layoutCertOk, '인증서 확인 불가 → 정상');
  if (!opt('no-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_monitoring_1280.png`), fullPage: true });
  await admin.setViewport({ width: 1024, height: 900 });
  await openMonitoring(admin);
  r.layout1024 = await layout(admin);
  structure(r.layout1024, '1024');
  check(r.layout1024.cols === 2 && r.layout1024.scroll, '1024 2열·가로 스크롤 0', { cols: r.layout1024.cols });
  if (!opt('no-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_monitoring_1024.png`), fullPage: true });
  await admin.setViewport({ width: 640, height: 900 });
  await openMonitoring(admin);
  r.layout640 = await layout(admin);
  check(r.layout640.cols === 1 && r.layout640.scroll, '640 1열·가로 스크롤 0', { cols: r.layout640.cols, scroll: r.layout640.scroll });
  await admin.setViewport({ width: 1280, height: 900 });
  setIni('monitoring', 'tls_cert_file', makeCert(5).replace(/\\/g, '/'));
  await openMonitoring(admin);
  await admin.click('[data-refresh-now]');
  await waitBadge(admin, 'cert', 'warn');
  r.layoutCertWarn = await layout(admin);
  sameHeights(r.layoutCertOk, r.layoutCertWarn, '인증서 만료 임박(주의)');
  setIni('monitoring', 'tls_cert_file', makeCert(365).replace(/\\/g, '/'));
  await openMonitoring(admin);
  await admin.click('[data-refresh-now]');
  await waitBadge(admin, 'cert', 'ok');
  // 자동 갱신 때 카드 높이가 출렁이지 않음(값만 교체)
  const h0 = await layout(admin);
  const u0 = await admin.evaluate(() => document.querySelector('[data-updated]').textContent);
  await admin.waitForFunction((u) => document.querySelector('[data-updated]').textContent !== u, { timeout: 25000 }, u0);
  sameHeights(h0, await layout(admin), '자동 갱신');
  // 키보드 Tab 순서 = 카드 순서(바닥 줄 링크)
  r.tabOrder = await admin.evaluate(() => [...document.querySelectorAll('.mon-card__foot a')].map((a) => a.closest('[data-card]').dataset.card));
  check(JSON.stringify(r.tabOrder) === JSON.stringify(['worker', 'queue', 'disk', 'backup', 'tools', 'schedules']), '바닥 줄 링크 순서 = 카드 순서', r.tabOrder);

  // 3. 자동 갱신(10초): 포커스·스크롤 유지
  await openMonitoring(admin);
  await admin.setViewport({ width: 1280, height: 600 });
  await admin.focus('[data-focus-key="backups"]');
  await admin.evaluate(() => window.scrollTo(0, 200));
  const before = await admin.evaluate(() => ({ updated: document.querySelector('[data-updated]').textContent, y: window.scrollY }));
  await admin.waitForFunction((u) => document.querySelector('[data-updated]').textContent !== u, { timeout: 25000 }, before.updated);
  r.auto = await admin.evaluate(() => ({ focus: document.activeElement?.dataset?.focusKey ?? null, y: window.scrollY }));
  check(r.auto.focus === 'backups' && r.auto.y === before.y, '자동 갱신: 포커스·스크롤 유지', { before, after: r.auto });
  await admin.setViewport({ width: 1280, height: 900 });

  // 4. 경고 흉내: 디스크 경고 기준 1% → disk_warn(지금 새로 고침), 배너
  setIni('disk', 'warn_percent', '1');
  await openMonitoring(admin);
  await admin.click('[data-refresh-now]');
  await admin.waitForFunction(() => [...document.querySelectorAll('[data-alerts] [data-alert]')].some((n) => n.dataset.alert === 'disk_warn'), { timeout: 15000 });
  r.disk = await screenState(admin);
  r.layoutDisk = await layout(admin);
  sameHeights(r.layoutCertOk, r.layoutDisk, '디스크 주의');
  if (!opt('no-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_monitoring_warn.png`), fullPage: true });
  await admin.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.diskBanner = await admin.evaluate(() => ({ count: Number(document.querySelector('[data-health-banner]')?.dataset.count ?? 0), text: document.querySelector('[data-health-banner]')?.textContent.replace(/\s+/g, ' ').trim() ?? '' }));
  if (opt('all-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_banner.png`) });
  await user.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.diskUserBanner = await user.evaluate(() => !!document.querySelector('[data-health-banner]'));
  check(r.disk.levels.disk === 'warn' && r.diskBanner.count === r.disk.alerts.length && /시스템 모니터링에서 보기/.test(r.diskBanner.text) && !r.diskUserBanner,
    '디스크 경고 → 카드·배너(admin만)', { disk: r.disk, banner: r.diskBanner, user: r.diskUserBanner });
  setIni('disk', 'warn_percent', '80');

  // 5. 워커 정지 → worker_down(fail), /health 503
  stopWorker();
  for (let i = 0; i < 60; i += 1) {
    const hb = JSON.parse(readFileSync(path.join(work, 'work/worker/heartbeat.json'), 'utf8'));
    if (hb.state === 'stopped') break;
    await sleep(500);
  }
  worker = null;
  await openMonitoring(admin);
  await admin.click('[data-refresh-now]');
  await admin.waitForFunction(() => [...document.querySelectorAll('[data-alerts] [data-alert]')].some((n) => n.dataset.alert === 'worker_down'), { timeout: 15000 });
  r.down = await screenState(admin);
  await sleep(5200); // /health 캐시(5s)
  const health2 = await json(admin, '/health');
  r.downHealth = { status: health2.status, body: health2.body?.status, alive: health2.body?.checks?.worker?.alive };
  check(r.down.status === 'fail' && r.down.levels.worker === 'fail' && r.downHealth.status === 503 && r.downHealth.body === 'fail' && r.downHealth.alive === false,
    '워커 정지 → 장애·/health 503', { down: r.down, health: r.downHealth });
  if (opt('all-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_monitoring_down.png`), fullPage: true });
  r.layoutDown = await layout(admin);
  sameHeights(r.layoutCertOk, r.layoutDown, '워커 정지(오류)');

  // 6. 한/영 × 라이트/다크 × 1280/1024: 가로 스크롤 0, 영어 화면 제목
  r.matrix = {};
  for (const lang of ['ko', 'en']) {
    for (const theme of ['light', 'dark']) {
      check(await prefs(admin, lang, theme) === 200, `언어 ${lang} ${theme}`, null);
      for (const width of [1280, 1024]) {
        await admin.setViewport({ width, height: 900 });
        await openMonitoring(admin);
        const s = await screenState(admin);
        const title = await admin.evaluate(() => document.querySelector('h1')?.textContent.trim());
        r.matrix[`${lang}_${theme}_${width}`] = { scroll: s.scroll, title };
        check(s.scroll, `가로 스크롤 ${lang} ${theme} ${width}`, null);
        if (lang === 'en') check(!/[가-힣]/.test(await admin.evaluate(() => document.querySelector('[data-monitoring]').textContent)), `영어 화면에 한글 없음 ${theme} ${width}`, null);
        if (!opt('no-shots') && width === 1280 && (opt('all-shots') || (lang === 'ko' && theme === 'dark'))) {
          await admin.screenshot({ path: path.join(OUT, lang === 'ko' && theme === 'dark' && !opt('all-shots') ? `${PREFIX}_monitoring_dark.png` : `${PREFIX}_monitoring_${lang}_${theme}.png`), fullPage: true });
        }
        const lay = await layout(admin);
        structure(lay, `${lang} ${theme} ${width}`);
        check(lay.cols === (width === 1280 ? 3 : 2), `격자 열 수 ${lang} ${theme} ${width}`, lay.cols);
      }
    }
  }
  check(r.matrix.en_light_1280.title === 'Monitoring' && r.matrix.ko_light_1280.title === '시스템 모니터링', '화면 제목 한/영', r.matrix);
  await prefs(admin, 'ko', 'light');
  await admin.setViewport({ width: 1280, height: 900 });
  await openMonitoring(admin);
  // 7. 성능(워커 정지 상태 — 판정 캐시 5s): API ?refresh=1(매번 새 판정)·캐시 사용, /health
  r.perf = { apiFresh: await timing(admin, '/api/v1/admin/monitoring?refresh=1'), apiCached: await timing(admin, '/api/v1/admin/monitoring'), health: await timing(admin, '/health', 20) };
  check(r.perf.apiFresh.p95 <= 1000, '모니터링 API p95 1초 이내(새 판정)', r.perf);
} catch (e) {
  problems.push(`예외: ${e.message}`);
} finally {
  await browser?.close();
  if (worker) stopWorker();
}
console.log(JSON.stringify({ r, csp, problems }, null, 1));
console.log(problems.length || csp.length ? `실패 ${problems.length + csp.length}건` : '시스템 모니터링 화면 점검 통과');
process.exit(problems.length || csp.length ? 1 : 0);
