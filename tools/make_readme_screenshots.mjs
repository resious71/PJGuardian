// README·기능 소개 스크린샷 만들기(P33 4-1, 개발 전용 — 사람이 손으로 찍지 않는다).
// 가짜 데이터 설치본(tools/guide_demo/setup.php — 새 DB·가짜 사용자 demo-admin·alex·가상 프로젝트, 이메일은 example.invalid)을
// data/work/readme_shots_* 에 만들고, 이 스크립트가 쓰는 데이터만 더한 뒤(두 번째 Run·Python 프로젝트·감사 기록·규칙·백업·관리자 이메일 설정)
// 별도 포트의 PHP 내장 서버(tools/guide_demo/router.php) + 격리 워커로 실제 업로드·분석을 한 번 돌리고 헤드리스 Chrome으로 찍는다.
// 실제 개발 DB·공유 config.ini·사용자 워커·실제 계정·경로는 쓰지 않는다(설치본 밖 설정이면 멈춤). 끝나면 서버·워커를 멈추고 폴더를 지운다.
//   node tools/make_readme_screenshots.mjs [--port=8091] [--only=dashboard,…] [--keep]
// 결과: docs/screenshots/<이름>.png(영어 화면)·<이름>.ko.png(한국어)·다크 2장, 목록 docs/screenshots/SCREENSHOTS.json.
// 규격: 폭 1280px, 장당 300KB 이하(넘으면 실패), 합계 8MB 이하. 찍은 직후 화면 글자(document.body.innerText)를
// 공개 스캐너(tools/scan_release.php --stdin)로 검사해 걸리면 그 이미지를 버리고 실패한다.
import { spawn, execFileSync, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, writeFileSync, statSync, rmSync, mkdirSync, existsSync, readdirSync, unlinkSync } from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { Client } from './acceptance/lib.mjs';
import { upload } from './acceptance/scenarios_core.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const opt = (name, def = null) => (argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? (argv.includes(`--${name}`) ? true : def));
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const PORT = Number(opt('port', '8091'));
const OUT = path.join(ROOT, 'docs/screenshots');
const MAX_BYTES = 300 * 1024;
const MAX_TOTAL = 8 * 1024 * 1024;
const VIEW = { width: 1280, height: 800 };
const only = opt('only') ? String(opt('only')).split(',') : null;
const sleep = (ms) => new Promise((r) => { setTimeout(r, ms); });

if (PORT < 1024 || PORT === 80 || PORT === 443) throw new Error('전용 포트(기본 8091)만 씁니다');
const work = path.join(ROOT, 'data/work', `readme_shots_${randomBytes(4).toString('hex')}`);
const INI = path.join(work, 'config.ini');
// 백업 폴더는 프로젝트 폴더 밖이어야 한다(PathPolicy) — 운영체제 임시 폴더 안의 새 폴더(끝나면 지움)
const backupDir = path.join(os.tmpdir(), `pjg_readme_backups_${randomBytes(4).toString('hex')}`).split(path.sep).join('/');
// 실제 설정으로 찍지 않도록: 설정 파일은 반드시 이 실행의 임시 설치본 안
if (!path.resolve(INI).startsWith(path.resolve(work) + path.sep) || path.resolve(INI) === path.resolve(ROOT, 'config/config.ini')) {
  throw new Error('가짜 데이터 설치본의 설정만 쓸 수 있습니다');
}
const PW = `Demo-${randomBytes(9).toString('base64url')}1!`;
const BASE = `http://127.0.0.1:${PORT}`;
let server = null;
let worker = null;
let browser = null;
const problems = [];
process.on('exit', () => {
  try { server?.kill(); } catch { /* 이미 끝남 */ }
  try { worker?.kill(); } catch { /* 이미 끝남 */ }
  if (!opt('keep')) { try { rmSync(backupDir, { recursive: true, force: true }); } catch { /* 없음 */ } }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`임시 폴더를 지우지 못함: ${e.code}`); } }
});

function php(args, env = {}) {
  const r = spawnSync(PHP, args, { cwd: ROOT, env: { ...process.env, ...env } });
  if (r.status !== 0) throw new Error(`php ${args[0] === '-r' ? '-r' : path.basename(args[0])} 실패(${r.status}): ${String(r.stdout).slice(-300)} ${String(r.stderr).slice(-300)}`);
  return String(r.stdout);
}
/** 설치본 config.ini의 [섹션] 키 값을 바꾼다(없으면 섹션 끝에 더함) */
function setIni(section, key, value) {
  const lines = readFileSync(INI, 'utf8').split('\n');
  const start = lines.findIndex((l) => l.trim() === `[${section}]`);
  if (start < 0) { lines.push(`[${section}]`, `${key} = "${value}"`, ''); writeFileSync(INI, lines.join('\n')); return; }
  let end = lines.findIndex((l, i) => i > start && /^\[/.test(l.trim()));
  if (end < 0) end = lines.length;
  const at = lines.findIndex((l, i) => i > start && i < end && new RegExp(`^${key}\\s*=`).test(l.trim()));
  if (at >= 0) lines[at] = `${key} = "${value}"`; else lines.splice(end, 0, `${key} = "${value}"`);
  writeFileSync(INI, lines.join('\n'));
}
/** 설치본 DB에 SQL 한 줄(읽기) — 결과 JSON */
const sql = (q, params = []) => JSON.parse(php(['-r', `require $argv[1] . '/src/bootstrap.php';
$app = PJGuardian\\App::create(PJG_ROOT, PJGuardian\\Support\\Config::load($argv[2]));
echo json_encode($app->db()->all($argv[3], json_decode($argv[4], true)));`, ROOT, INI, q, JSON.stringify(params)]));

/** 화면 글자 개인 정보 검사: 공개 스캐너(--stdin)가 있으면 그것, 없으면 같은 종류의 간이 규칙 */
const scanner = path.join(ROOT, 'tools/scan_release.php');
function privacyScan(text) {
  if (existsSync(scanner)) {
    const r = spawnSync(PHP, [scanner, '--stdin'], { input: text, cwd: ROOT });
    return { tool: 'scan_release.php --stdin', ok: r.status === 0, detail: r.status === 0 ? '' : String(r.stdout).slice(0, 400) };
  }
  const hits = [];
  for (const m of text.matchAll(/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/g)) {
    if (!/(^|\.)example\.(com|org|net|invalid)$|\.example$|\.invalid$/i.test(m[1])) hits.push(`email:${m[0].slice(0, 3)}…`);
  }
  if (/\b[A-Za-z]:[\\/][^\s]/.test(text)) hits.push('windows_path');
  if (/\/home\/[a-z]|\/Users\/[A-Za-z]/.test(text)) hits.push('home_path');
  // 이름(사용자·다른 프로젝트) 목록은 tools/scan_release.rules.php에만 둔다 — 간이 규칙에서는 보지 않는다
  return { tool: '간이 규칙(scan_release.php 없음)', ok: hits.length === 0, detail: hits.join(', ') };
}

// ---------------------------------------------------------------- 설치본 준비
mkdirSync(work, { recursive: true });
php([path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { PJG_DEMO_PASSWORD: PW });
setIni('health', 'allowed_ips', '127.0.0.1');
setIni('monitoring', 'cache', '5s');
setIni('disk', 'warn_percent', '1'); // 모니터링에 경고 카드 하나(디스크 사용률 경고 기준을 낮춤)
setIni('backup', 'dir', backupDir);
setIni('alert_mail', 'smtp_host', 'smtp.example.invalid');
setIni('alert_mail', 'from_address', 'pjguardian@example.invalid');
setIni('alert_mail', 'recipients', 'ops@example.invalid');
setIni('alert_mail', 'smtp_user', 'pjguardian');
// 두 번째 Run(히스토리·직전 Run 대비 비교): 같은 합성 소스에서 파일 하나가 빠지고 파일마다 결함 줄이 늘어남 → 신규·해결이 섞인다
const db = path.join(work, 'db/demo.sqlite');
php([path.join(ROOT, 'tools/seed_defects.php'), `--db=${db}`, '--project=1', '--defects=143', '--files=11', `--snapshots-dir=${path.join(work, 'snapshots')}`]);
php(['-r', `require $argv[1] . '/src/bootstrap.php';
$app = PJGuardian\\App::create(PJG_ROOT, PJGuardian\\Support\\Config::load($argv[2]));
$db = $app->db();
$t = '2026-10-01T01:00:00Z';
$alex = (int) $db->value("SELECT id FROM users WHERE login_id = 'alex'");
$admin = $db->one("SELECT id, login_id FROM users WHERE login_id = 'demo-admin'");
$db->execute('INSERT INTO projects (name, language, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?)', ['sample-cli', 'python', 'Sample command-line tool', $t, $t]);
$pid = $db->lastInsertId();
$db->execute("INSERT INTO project_access (project_id, subject_type, subject_id, created_at, created_by) VALUES (?, 'user', ?, ?, ?)", [$pid, $alex, $t, (int) $admin['id']]);
$a = $app->audit();
$me = PJGuardian\\Services\\Actor::fromUser($admin, '192.0.2.10');
$a->record($me, 'user.create', 'user', (string) $alex, ['login_id' => 'alex', 'role' => 'user']);
$a->record($me, 'project.create', 'project', (string) $pid, ['name' => 'sample-cli', 'language' => 'python']);
$a->record($me, 'project.access_add', 'project', (string) $pid, ['subject_type' => 'user', 'subject_id' => $alex, 'login_id' => 'alex']);
$a->record($me, 'config.update', 'config', 'analysis.timeout', ['old' => '4h', 'new' => '2h', 'old_source' => 'config', 'file' => 'settings.local.ini']);
echo $pid;`, ROOT, INI]);
const cli = sql("SELECT id FROM projects WHERE name = 'sample-cli'")[0].id;
php([path.join(ROOT, 'tools/rules_load.php'), `--config=${INI}`, '--tool=ruff', '--apply']);
php([path.join(ROOT, 'tools/backup.php'), '--kind=manual', `--config=${INI}`]);

server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: INI }, stdio: 'ignore' });
worker = spawn(PHP, [path.join(ROOT, 'tools/worker.php'), `--config=${INI}`], { stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}

// 실제 업로드 한 번(분석 상태 화면, 이후 사전 검사 경고의 기준 = 최상위 폴더 없음)
const alexApi = new Client(BASE, 'alex');
if ((await alexApi.login('alex', PW)).status !== 200) throw new Error('alex 로그인 실패');
const upId = await upload(alexApi, cli, readFileSync(path.join(ROOT, 'samples/zips/python_sample.zip')), 'python_sample.zip');
let analysisId = null;
for (let i = 0; i < 300 && !analysisId; i += 1) {
  const u = await alexApi.data('GET', `/api/v1/uploads/${upId}`);
  if (u.status === 'needs_confirmation') await alexApi.data('POST', `/api/v1/uploads/${upId}/confirm`);
  if (u.analysis_id) {
    const a = await alexApi.data('GET', `/api/v1/analyses/${u.analysis_id}`);
    if (a.status === 'succeeded') analysisId = a.id;
    else if (['failed', 'cancelled'].includes(a.status)) throw new Error(`분석 ${a.status}`);
  }
  if (u.status === 'failed') throw new Error(`업로드 실패 ${u.error_code}`);
  await sleep(1000);
}
if (!analysisId) throw new Error('분석이 끝나지 않음');
// 결함 상세: 실제 업로드 분석(ruff가 samples/python_sample에서 찾은 결함 — 코드와 규칙이 실제와 맞음)의 결함 하나
const defectId = (sql("SELECT id FROM defects WHERE project_id = ? AND rule_key = 'ruff:F401' ORDER BY id LIMIT 1", [cli])[0] ?? sql('SELECT id FROM defects WHERE project_id = ? ORDER BY id LIMIT 1', [cli])[0]).id;

// ---------------------------------------------------------------- 찍기
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  page.on('pageerror', (e) => problems.push(`pageerror(${login}): ${e.message}`));
  await page.setViewport({ ...VIEW, deviceScaleFactor: 1 });
  await page.emulateTimezone('Asia/Seoul');
  if (login) {
    await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
    await page.type('#login-id', login);
    await page.type('#login-password', PW);
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  }
  return page;
}
const prefs = (page, language, theme) => page.evaluate(async (l, th) => (await fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin',
  headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: l, theme: th }) })).status, language, theme);

/** 화면 목록 — name: 파일 이름, login: 계정(null = 로그인 전), prepare: 찍기 전 동작 */
const SCREENS = [
  { name: 'login', screen: 'Sign-in', login: null, path: '/login', wait: '#login-id' },
  { name: 'dashboard', screen: 'Dashboard', login: 'alex', path: '/dashboard', wait: 'canvas', height: 1400 },
  { name: 'projects', screen: 'Project list', login: 'alex', path: '/projects', wait: '#main table' },
  { name: 'upload-precheck', screen: 'Upload and pre-check warning', login: 'alex', path: `/upload?project=${cli}`, wait: '#up-start',
    async prepare(page) {
      const input = await page.$('#up-file');
      await input.uploadFile(path.join(ROOT, 'samples/zips/python_sample_toplevel.zip'));
      await page.waitForFunction(() => !document.getElementById('up-start').disabled, { timeout: 20000 });
      await page.click('#up-start');
      await page.waitForSelector('#up-warnings:not([hidden]) #up-warning-list li', { timeout: 120000 });
      await sleep(800);
      // 경고와 함께 뜨는 "이대로 분석할까요?" 대화상자는 "나중에 정하기"로 닫고 화면의 경고 구역을 찍는다
      const later = await page.$('dialog[open] button.btn:not(.btn--primary)');
      if (later) { await later.click(); await sleep(500); }
      await page.$eval('#up-progress', (n) => n.scrollIntoView({ block: 'start' }));
      await page.evaluate(() => window.scrollBy(0, -80));
    },
    async after(page) { // 다음 언어에서 다시 경고를 보이도록 이 업로드를 취소
      await page.click('#up-cancel-warning');
      await sleep(1500);
      const dlg = await page.$('dialog[open] .btn--danger, dialog[open] .btn--primary');
      if (dlg) { await dlg.click(); await sleep(1500); }
    } },
  { name: 'analysis-status', screen: 'Analysis result', login: 'alex', path: `/analyses/${analysisId}`, wait: '#main' },
  { name: 'defects-list', screen: 'Defect list with search and Run selector', login: 'alex', path: '/projects/1', wait: '.defects-table tbody tr' },
  { name: 'defect-detail', screen: 'Defect detail with code viewer', login: 'alex', path: `/defects/${defectId}`, wait: '.cm-editor', top: true, height: 1250 },
  { name: 'history', screen: 'History and comparison with the previous Run', login: 'alex', path: '/projects/1/history', wait: '#main table tbody tr', height: 1100 },
  { name: 'rules', screen: 'Rules', login: 'alex', path: '/rules', wait: '#main table tbody tr' },
  { name: 'audit-log', screen: 'Audit log with descriptions and integrity check', login: 'demo-admin', path: '/admin/audit-logs', wait: '#main table tbody tr',
    async prepare(page) {
      const btn = await page.$('[data-integrity]');
      if (btn) { await btn.click(); await page.waitForFunction(() => (document.querySelector('[data-integrity-range]')?.textContent ?? '').trim() !== '', { timeout: 30000 }); }
    } },
  { name: 'backups', screen: 'System admin — Backup & restore', login: 'demo-admin', path: '/admin/backups', wait: '#main table tbody tr' },
  { name: 'settings-admin-email', screen: 'Settings — Admin email tab', login: 'demo-admin', path: '/admin/config-settings#admin_mail', wait: '[role="tabpanel"]:not([hidden]) [data-item]', reload: true },
  { name: 'guide', screen: 'Built-in guide', login: 'alex', path: '/guide', wait: '#main' },
  { name: 'monitoring', screen: 'System monitoring (OK, warning and error together)', login: 'demo-admin', path: '/admin/monitoring', wait: '[data-card][data-level]', stopWorker: true },
];
const DARK = [
  { name: 'dashboard-dark', screen: 'Dashboard (dark theme)', base: 'dashboard' },
  { name: 'defect-detail-dark', screen: 'Defect detail (dark theme)', base: 'defect-detail' },
];

mkdirSync(OUT, { recursive: true });
const manifest = [];
async function shoot(page, s, name, lang, theme, login) {
  try { await page.goto(BASE + s.path, { waitUntil: 'networkidle0', timeout: 60000 }); } catch { await page.goto(BASE + s.path, { waitUntil: 'networkidle2', timeout: 60000 }); } // 내장 서버는 요청을 하나씩 처리 — 느리면 한 번 더
  if (s.reload) await page.reload({ waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector(s.wait, { timeout: 20000 });
  if (s.prepare) await s.prepare(page);
  if (s.top) await page.evaluate(() => window.scrollTo(0, 0)); // 화면이 스스로 아래로 이동해도 머리부터 찍음
  await sleep(700);
  // 이 설치본의 서버 경로(임시 폴더 — 개발 PC 경로가 드러남)는 공개 그림에서 가린다. 가린 개수는 목록에 남긴다
  const redacted = await page.evaluate((label) => {
    let n = 0;
    const re = /(?:(?<![A-Za-z])[A-Za-z]:[\\/]|\\\\)[^\s"'<>()]+/g; // 드라이브 문자 앞에 글자가 있으면 경로가 아님(https:// 등)
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    for (let node = w.nextNode(); node; node = w.nextNode()) {
      const v = node.nodeValue.replace(re, label);
      if (v !== node.nodeValue) { node.nodeValue = v; n += 1; }
    }
    for (const i of document.querySelectorAll('input:not([type=file]), textarea')) {
      const v = i.value.replace(re, label);
      if (v !== i.value) { i.value = v; n += 1; }
    }
    return n;
  }, lang === 'ko' ? '(경로 가림)' : '(path hidden)');
  const text = await page.evaluate(() => document.body.innerText);
  const scan = privacyScan(text);
  const file = path.join(OUT, `${name}${lang === 'ko' ? '.ko' : ''}.png`);
  if (!scan.ok) {
    problems.push(`${path.basename(file)}: 개인 정보 검사 실패(${scan.detail}) — 버림`);
    if (existsSync(file)) unlinkSync(file);
  } else {
    await page.screenshot({ path: file, clip: { x: 0, y: await page.evaluate(() => scrollY), width: VIEW.width, height: s.height ?? VIEW.height } }); // height: 차트·비교 패널까지 보이게 화면별로 길게
    const bytes = statSync(file).size;
    if (bytes > MAX_BYTES) problems.push(`${path.basename(file)} ${bytes}바이트 > ${MAX_BYTES}`);
    manifest.push({ file: `docs/screenshots/${path.basename(file)}`, screen: s.screen, lang, theme, bytes, scene: s.scene ?? s.screen, redacted_paths: redacted, login: login ?? '(signed out)', scan: scan.tool, script: 'tools/make_readme_screenshots.mjs' });
    console.log(`${path.basename(file)} ${bytes}B`);
  }
  if (s.after) await s.after(page);
}

const want = (n) => !only || only.includes(n);
const pages = {};
async function pageFor(login, lang) {
  if (login === null) {
    const page = await session(null);
    await page.setCookie({ name: 'pjg_lang', value: lang, url: BASE }, { name: 'pjg_theme', value: 'light', url: BASE });
    return page;
  }
  pages[login] ??= await session(login);
  const page = pages[login];
  if (page.__lang !== lang) { if (await prefs(page, lang, 'light') !== 200) throw new Error('화면 언어 바꾸기 실패'); page.__lang = lang; }
  return page;
}
// 1) 워커가 필요한 화면(업로드)을 포함해 모니터링 밖 화면을 두 언어로 → 2) 워커를 멈춰 모니터링에 오류 카드 하나 → 모니터링 두 언어
for (const pass of [false, true]) {
  if (pass) {
    try { execFileSync(PHP, [path.join(ROOT, 'tools/worker.php'), '--stop', `--config=${INI}`], { stdio: 'ignore' }); } catch { /* 이미 멈춤 */ }
    for (let i = 0; i < 60; i += 1) { if (worker.exitCode !== null) break; await sleep(500); }
    await sleep(6000); // 모니터링 캐시(5초)
  }
  for (const lang of ['en', 'ko']) {
    for (const s of SCREENS.filter((x) => want(x.name) && !!x.stopWorker === pass)) {
      const page = await pageFor(s.login, lang);
      await shoot(page, s, s.name, lang, 'light', s.login);
      if (s.login === null) await page.browserContext().close();
    }
  }
}
for (const d of DARK.filter((x) => want(x.name))) {
  const s = SCREENS.find((x) => x.name === d.base);
  const page = pages[s.login] ??= await session(s.login);
  await prefs(page, 'en', 'dark');
  page.__lang = null;
  await shoot(page, { ...s, prepare: null, after: null, screen: d.screen }, d.name, 'en', 'dark', s.login);
}
await browser.close();

// 목록과 합계(--only면 기존 목록에 덮어 합침)
const listFile = path.join(OUT, 'SCREENSHOTS.json');
let all = manifest;
if (only && existsSync(listFile)) {
  const old = JSON.parse(readFileSync(listFile, 'utf8')).images ?? [];
  all = [...old.filter((o) => !manifest.some((m) => m.file === o.file)), ...manifest];
}
all.sort((a, b) => a.file.localeCompare(b.file));
writeFileSync(listFile, `${JSON.stringify({ comment: 'README·docs/features 스크린샷 목록(P33). 가짜 데이터 설치본(tools/guide_demo)에서 tools/make_readme_screenshots.mjs로 만든다 — 손으로 고치지 않는다.', viewport: VIEW, images: all }, null, 2)}\n`);
const total = readdirSync(OUT).filter((f) => f.endsWith('.png')).reduce((n, f) => n + statSync(path.join(OUT, f)).size, 0);
console.log(`합계 ${total}바이트(${(total / 1048576).toFixed(2)}MB)`);
if (total > MAX_TOTAL) problems.push(`합계 ${total}바이트 > ${MAX_TOTAL}`);
if (problems.length) {
  console.error(`문제:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
console.log('README 스크린샷 만들기 완료');
process.exit(0);
