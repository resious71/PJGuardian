// P31 하드닝 화면 점검(헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo — 임시 폴더·새 DB·가짜 계정)을 별도 포트에 띄우고
// 격리 워커를 돌린 뒤 점검하고, 끝나면 서버·워커를 멈추고 폴더를 지운다(실제 DB·설정·사용자 워커와 무관).
//   node tools/check_p31_ui.mjs [--port=8093] [--keep] [--no-shots]
// 흐름: 설치본의 demo-admin을 초기 비밀번호 상태(password_kind = initial)로 바꿈 →
//   A-3 admin 모든 화면 위 닫을 수 없는 경고 배너(본인 → 비밀번호 변경 링크), 일반 사용자에게 없음, 모니터링 화면 경고 목록에 한 건, 영어 문구에 한글 없음
//   A-4 시스템 관리 > 점검 > 서버 환경의 보안 줄(설정 폴더·설정 파일·비밀 파일·public/ 노출·display_errors·expose_php·세션 저장), 정보·확인 불가 상태 표시
//   A-5 감사 로그 > 무결성 검사 → 체인 검사 + DB 밖 기록 대조 한 줄(워커가 기록한 뒤 "일치")
//   한/영 × 라이트/다크 × 1280/1024(가로 스크롤 0은 1280 — 앱 최소 너비), 콘솔 오류·CSP 위반 0. 캡처 P31_initial_password_banner·P31_checks_security·P31_audit_integrity.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8093);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const SHOTS = !opt('no-shots');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `p31_check_${randomBytes(4).toString('hex')}`);
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
process.on('exit', () => {
  for (const p of [server, worker]) { try { p?.kill(); } catch { /* 이미 끝남 */ } }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`점검 폴더를 지우지 못함: ${e.code}`); } }
});

mkdirSync(work, { recursive: true });
execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'pipe', 'inherit'] });
// 설치본의 demo-admin을 "초기 비밀번호를 바꾸지 않은" 상태로(격리 DB — 실제 DB 아님)
execFileSync(PHP, ['-r', `$p = new PDO('sqlite:' . $argv[1]); $p->exec("UPDATE users SET password_kind = 'initial' WHERE login_id = 'demo-admin'");`, path.join(work, 'db/demo.sqlite')]);
server = spawn(PHP, ['-d', 'extension=intl', '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: INI }, stdio: 'ignore' });
worker = spawn(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), `--config=${INI}`], { stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}
// 워커가 감사 로그 DB 밖 기록을 처음 남길 때까지
for (let i = 0; i < 75 && !existsSync(path.join(work, 'logs/audit-anchor.jsonl')); i += 1) await sleep(200);

async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of (403|404)/.test(m.text())) problems.push(`console.${m.type()}(${login}): ${m.text()}`);
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
const banner = (page) => page.evaluate(() => {
  const b = document.querySelector('[data-initial-password-banner]');
  return b ? { text: b.textContent.replace(/\s+/g, ' ').trim(), href: b.querySelector('a')?.getAttribute('href') ?? '', close: !!b.querySelector('button'), public: b.dataset.public } : null;
});
const clipOf = (page, sel, pad = 8) => page.$eval(sel, (n, p) => { const b = n.getBoundingClientRect(); return { x: Math.max(0, b.x - p), y: b.y + window.scrollY - p, width: b.width + 2 * p, height: b.height + 2 * p }; }, pad);

try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });

  // A-3 배너
  const a = await session('demo-admin');
  r.banners = {};
  for (const p of ['/dashboard', '/projects', '/admin/checks', '/admin/monitoring', '/account']) {
    await a.goto(`${BASE}${p}`, { waitUntil: 'networkidle0' });
    r.banners[p] = await banner(a);
  }
  const b0 = r.banners['/dashboard'];
  check(Object.values(r.banners).every((b) => b !== null) && /초기 비밀번호를 아직 바꾸지 않은 관리자 계정이 있습니다\(demo-admin\)/.test(b0?.text ?? '')
    && /\/password$/.test(b0?.href ?? '') && b0?.close === false && b0?.public === '0', 'admin 모든 화면 배너·본인 → 비밀번호 변경·닫기 없음', r.banners);
  await a.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  if (SHOTS) await a.screenshot({ path: path.join(OUT, 'P31_initial_password_banner.png'), clip: { x: 0, y: 0, width: 1280, height: 220 } });
  const u = await session('alex');
  r.userBanner = await banner(u);
  check(r.userBanner === null, '일반 사용자에게 배너 없음', r.userBanner);
  await a.goto(`${BASE}/admin/monitoring`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-card][data-level]', { timeout: 20000 });
  r.monAlerts = await a.$$eval('[data-alerts] [data-alert]', (ns) => ns.map((n) => [n.dataset.alert, n.dataset.level]));
  check(r.monAlerts.some(([c, l]) => c === 'initial_admin_password' && l === 'warn'), '모니터링 경고 목록에 한 건(경고)', r.monAlerts);

  // A-4 점검 탭 보안 줄
  await a.goto(`${BASE}/admin/checks`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-env] tr');
  r.secRows = await a.$$eval('[data-env] tr', (rows) => rows.map((tr) => [tr.cells[0].textContent.trim(), tr.dataset.status, tr.cells[2].textContent.trim().slice(0, 60)]));
  const sec = r.secRows.filter(([label]) => /설정 폴더|설정 파일|비밀 파일|웹 루트|display_errors|expose_php|세션 저장/.test(label));
  check(sec.length >= 8 && sec.some(([, s]) => s === 'info') && sec.some(([l, s]) => /비밀 파일 권한/.test(l) && s === 'unknown') && sec.some(([l, s]) => /웹 루트/.test(l) && s === 'ok'),
    '점검 탭 보안 줄(정보·확인 불가 상태 포함, public/ 깨끗함)', sec);
  r.secIcons = await a.$$eval('[data-env] tr[data-status="info"] .icon, [data-env] tr[data-status="unknown"] .icon', (ns) => ns.length);
  check(r.secIcons >= 2, '정보·확인 불가 아이콘', r.secIcons);
  if (SHOTS) {
    const first = await a.$$eval('[data-env] tr', (rows) => rows.findIndex((tr) => /설정 폴더/.test(tr.cells[0].textContent)));
    await a.$$eval('[data-env] tr', (rows, i) => rows[i].scrollIntoView({ block: 'center' }), first);
    const clip = await a.$$eval('[data-env] tr', (rows, i) => {
      const top = rows[i].getBoundingClientRect();
      const last = rows[Math.min(rows.length - 1, i + 9)].getBoundingClientRect();
      return { x: Math.max(0, top.x - 8), y: top.y + window.scrollY - 8, width: top.width + 16, height: last.bottom - top.y + 16 };
    }, first);
    await a.screenshot({ path: path.join(OUT, 'P31_checks_security.png'), clip });
  }

  // A-5 감사 로그 무결성 + DB 밖 기록
  await a.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
  await a.click('[data-integrity]');
  await a.waitForFunction(() => ['ok', 'broken', 'error'].includes(document.querySelector('[data-integrity-status]')?.dataset.result ?? ''), { timeout: 30000 });
  r.integrity = await a.$eval('[data-integrity-status]', (n) => ({ result: n.dataset.result, anchor: n.dataset.anchor, text: n.textContent.replace(/\s+/g, ' ').trim() }));
  check(r.integrity.result === 'ok' && r.integrity.anchor === 'ok' && /DB 밖 기록과도 일치/.test(r.integrity.text), '무결성 검사 + DB 밖 기록 일치', r.integrity);
  if (SHOTS) await a.screenshot({ path: path.join(OUT, 'P31_audit_integrity.png'), clip: await clipOf(a, '[data-integrity-box]') });

  // 한/영 × 라이트/다크 × 1280/1024
  r.layout = [];
  for (const [lang, theme] of [['en', 'dark'], ['ko', 'light']]) {
    await prefs(a, lang, theme);
    for (const w of [1280, 1024]) {
      await a.setViewport({ width: w, height: 900 });
      for (const p of ['/dashboard', '/admin/checks']) {
        await a.goto(`${BASE}${p}`, { waitUntil: 'networkidle0' });
        const v = await a.evaluate(() => ({
          banner: document.querySelector('[data-initial-password-banner]')?.textContent.replace(/\s+/g, ' ').trim() ?? null,
          scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
          hangulRows: [...document.querySelectorAll('[data-env] tr')].filter((tr) => /[가-힣]/.test(tr.cells[0]?.textContent ?? '')).length,
        }));
        r.layout.push({ lang, theme, w, p, ...v });
        // 가로 스크롤은 1280에서만(앱 최소 너비 --app-min-width 1280px — 1024는 배치만 확인, check_i18n_theme_ui와 같은 기준)
        check((w !== 1280 || v.scroll) && v.banner !== null && (lang !== 'en' || (!/[가-힣]/.test(v.banner) && v.hangulRows === 0)), `${lang}/${theme} ${w} ${p}`, v);
      }
    }
  }
  check(csp.length === 0, 'CSP 위반 0', csp);
} catch (e) {
  problems.push(`예외: ${e.stack ?? e}`);
} finally {
  await browser?.close();
  try { execFileSync(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/worker.php'), '--stop', `--config=${INI}`], { stdio: 'ignore' }); } catch { /* 이미 멈춤 */ }
}
console.log(JSON.stringify(r, null, 1));
if (problems.length) {
  console.log(`문제 ${problems.length}건:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
console.log('P31 화면 점검: 모두 통과');
process.exit(0);
