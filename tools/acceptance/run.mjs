// 인수 시험(P32) 실행기 — 릴리스 묶음을 새 폴더에 설치하고(가짜 설정·새 DB·별도 포트·별도 워커) 16개 시나리오를 끝에서 끝까지 확인한다.
//   node tools/acceptance/run.mjs [--zip=<묶음 zip>] [--port=8091] [--only=1,2,…] [--keep] [--reuse=<설치 폴더>] [--no-shots]
// - 묶음 zip을 주지 않으면 dist/의 가장 새 zip(php tools/build_bundle.php --zip). 설치는 tools/acceptance/install.php(설치 가이드 순서).
// - 서버: PHP 내장 서버 + tools/acceptance/router.php(설치본의 public/index.php 그대로, 시나리오 10만 가짜 디렉터리·IdP를 끼움).
// - 워커: 설치본의 tools/worker.php(설치본 설정) — 사용자의 워커·공유 config·개발 DB는 건드리지 않는다. 인터넷에 접속하지 않는다.
// - 결과: docs/results/P32_acceptance/results.json·results.md(시나리오별 기대·실제·증거·판정), 캡처 같은 폴더. 끝나면 서버·워커를 멈추고 설치 폴더를 지운다(--keep이면 남김).
// - --reuse: 이미 설치한 폴더(--keep으로 남긴 것)에서 서버·워커만 다시 띄워 --only 시나리오를 이어서(상태는 그 폴더의 state.json).
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, writeFileSync, rmSync, statSync, openSync } from 'node:fs';
import net from 'node:net';
import { randomBytes } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { ROOT, Recorder, Client, launch, userPage, formLogin, screen, sleep, hide } from './lib.mjs';
import { scenarios as core, PW, ADMIN_PW } from './scenarios_core.mjs';
import { scenarios as ops } from './scenarios_ops.mjs';

const arg = (k, d) => { const a = process.argv.find((x) => x.startsWith(`--${k}=`)); return a ? a.slice(k.length + 3) : (process.argv.includes(`--${k}`) ? true : d); };
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const only = arg('only') ? String(arg('only')).split(',').map(Number) : null;
const reuse = arg('reuse');
const keep = !!arg('keep') || !!reuse;
if (arg('no-shots')) screen.dir = path.join(ROOT, 'data/work/acc_shots_discard');

async function freePort(from) {
  for (let p = from; p < from + 50; p++) {
    const ok = await new Promise((res) => { const s = net.createServer().once('error', () => res(false)).once('listening', () => s.close(() => res(true))).listen(p, '127.0.0.1'); });
    if (ok) return p;
  }
  throw new Error('빈 포트 없음');
}
function latestZip() {
  const dist = path.join(ROOT, 'dist');
  const zips = existsSync(dist) ? readdirSync(dist).filter((f) => f.endsWith('.zip')).map((f) => path.join(dist, f)) : [];
  zips.sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
  return zips[0];
}

const dir = reuse ? path.resolve(String(reuse)) : path.join(ROOT, 'data/work', `acc_${randomBytes(4).toString('hex')}`);
const statePath = path.join(dir, 'state.json');
const ctx = {
  ROOT, PHP, dir, rec: new Recorder(), S: existsSync(statePath) ? JSON.parse(readFileSync(statePath, 'utf8')) : {},
  zip: path.resolve(String(arg('zip') ?? latestZip() ?? '')), procs: {}, clients: {}, pages: {}, sleep, hide,
};
ctx.port = ctx.S.port ?? Number(arg('port', 0)) ?? 0;
ctx.app = ctx.S.app ?? path.join(dir, 'inst', 'app');

/** 설치본의 PHP 도구 실행(작업 폴더 = 설치본) */
ctx.php = (args, { input, env, cwd } = {}) => {
  const r = spawnSync(PHP, args, { cwd: cwd ?? ctx.app, input, env: { ...process.env, ...env }, encoding: 'utf8', timeout: 600000, maxBuffer: 64 * 1024 * 1024 });
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}` };
};
ctx.db = () => new DatabaseSync(path.join(ctx.app, 'data/db/pjguardian.sqlite'), { readOnly: true });
ctx.sql = (q, ...p) => { const d = ctx.db(); try { return d.prepare(q).all(...p); } finally { d.close(); } };
ctx.sqlOne = (q, ...p) => ctx.sql(q, ...p)[0] ?? null;
/** 시험 데이터 조작(설치본의 새 DB에만 — 보존 기간 시험용으로 시각을 과거로 돌리는 등). 개발 DB가 아님 */
ctx.sqlWrite = (q, ...p) => { const d = new DatabaseSync(path.join(ctx.app, 'data/db/pjguardian.sqlite')); try { d.exec('PRAGMA busy_timeout = 10000'); return d.prepare(q).run(...p); } finally { d.close(); } };
/** 설치본 config.ini의 [섹션] 키 값을 바꾼다(운영자가 파일을 고치는 것과 같음 — 웹 요청은 매번 읽고, 워커는 다시 시작해야 반영) */
ctx.setConfig = (section, key, value, appDir = ctx.app) => {
  const f = path.join(appDir, 'config/config.ini');
  const lines = readFileSync(f, 'utf8').split('\n');
  let cur = '';
  for (let i = 0; i < lines.length; i++) {
    const m = /^\[([^\]]+)\]/.exec(lines[i]);
    if (m) { cur = m[1]; continue; }
    if (cur === section && new RegExp(`^${key}[ \\t]*=`).test(lines[i])) { lines[i] = `${key} = "${value}"`; writeFileSync(f, lines.join('\n')); return; }
  }
  throw new Error(`설정 키 없음 [${section}] ${key}`);
};

/** 서버(내장 서버 + 인수 라우터) */
ctx.startServer = async (appDir = ctx.app, port = ctx.port, name = 'server') => {
  const log = openSync(path.join(dir, `${name}.log`), 'a');
  const p = spawn(PHP, ['-S', `127.0.0.1:${port}`, '-t', path.join(appDir, 'public'), path.join(ROOT, 'tools/acceptance/router.php')],
    { cwd: appDir, env: { ...process.env, PJG_ACC_APP: appDir, PJG_ACC_FAKES: path.join(dir, 'fakes'), PHP_CLI_SERVER_WORKERS: '4' }, stdio: ['ignore', log, log], windowsHide: true });
  ctx.procs[name] = p;
  for (let i = 0; i < 50; i++) {
    await sleep(200);
    try { const r = await fetch(`http://127.0.0.1:${port}/login`); if (r.status === 200) return p; } catch { /* 아직 */ }
  }
  throw new Error(`${name} 기동 실패`);
};
ctx.stopServer = async (name = 'server') => {
  const p = ctx.procs[name];
  if (p && p.exitCode === null) { spawnSync('taskkill', ['/PID', String(p.pid), '/T', '/F']); await sleep(300); }
  delete ctx.procs[name];
};
ctx.freePort = freePort;
/** 보조 서버(가짜 Git·SMTP 등) — 이름으로 기록해 끝날 때 멈춘다. 포트가 열릴 때까지 기다림 */
ctx.spawnBg = async (name, args, { env, cwd, port } = {}) => {
  const log = openSync(path.join(dir, `${name}.log`), 'a');
  const p = spawn(PHP, args, { cwd: cwd ?? ROOT, env: { ...process.env, ...env }, stdio: ['ignore', log, log], windowsHide: true });
  ctx.procs[name] = p;
  if (port) {
    for (let i = 0; i < 50; i++) {
      await sleep(200);
      const open = await new Promise((res) => { const s = net.connect(port, '127.0.0.1').once('connect', () => { s.destroy(); res(true); }).once('error', () => res(false)); });
      if (open) break;
    }
  }
  return p;
};
/** 워커(설치본 tools/worker.php, 설치본 설정) — 계속 실행 */
ctx.startWorker = async (appDir = ctx.app, name = 'worker') => {
  const log = openSync(path.join(dir, `${name}.log`), 'a');
  const p = spawn(PHP, [path.join(appDir, 'tools/worker.php')], { cwd: appDir, stdio: ['ignore', log, log], windowsHide: true });
  ctx.procs[name] = p;
  for (let i = 0; i < 50; i++) {
    await sleep(300);
    const st = spawnSync(PHP, [path.join(appDir, 'tools/worker.php'), '--status'], { cwd: appDir, encoding: 'utf8' });
    if (/실행 중\(잠금 있음\)/.test(st.stdout ?? '')) return p;
  }
  return p;
};
ctx.stopWorker = async (appDir = ctx.app, name = 'worker') => {
  const p = ctx.procs[name];
  spawnSync(PHP, [path.join(appDir, 'tools/worker.php'), '--stop'], { cwd: appDir, encoding: 'utf8', timeout: 60000 });
  for (let i = 0; i < 100 && p && p.exitCode === null; i++) await sleep(200);
  if (p && p.exitCode === null) spawnSync('taskkill', ['/PID', String(p.pid), '/T', '/F']);
  delete ctx.procs[name];
};
/** 조건이 맞을 때까지 기다림(기본 120초) */
ctx.until = async (fn, ms = 120000, step = 500) => {
  const t = Date.now();
  for (;;) {
    const v = await fn();
    if (v) return v;
    if (Date.now() - t > ms) return null;
    await sleep(step);
  }
};
ctx.client = (name) => { ctx.clients[name] ??= new Client(ctx.base, name); return ctx.clients[name]; };
/** 로그인한 API 클라이언트(처음 쓸 때 로그인) */
ctx.user = async (name) => {
  const c = ctx.client(name);
  if (!c.csrf) {
    const r = await c.login(name, name === 'admin' ? ADMIN_PW : PW);
    if (r.status !== 200) throw new Error(`${name} 로그인 ${r.status}`);
  }
  return c;
};
/** 사용자별 브라우저 페이지(처음 쓸 때 로그인) */
ctx.page = async (name) => {
  if (!ctx.pages[name]) {
    const p = await userPage(ctx.browser, ctx.base);
    await formLogin(p, name, name === 'admin' ? ADMIN_PW : PW);
    ctx.pages[name] = p;
  }
  return ctx.pages[name];
};

const t0 = Date.now();
let exitCode = 0;
try {
  mkdirSync(dir, { recursive: true });
  mkdirSync(path.join(dir, 'fakes'), { recursive: true });
  if (!reuse) {
    if (!existsSync(ctx.zip)) throw new Error('묶음 zip 없음 — php tools/build_bundle.php --zip');
    ctx.port = await freePort(Number(arg('port', 8091)));
    ctx.S.port = ctx.port;
  }
  ctx.base = `http://127.0.0.1:${ctx.port}`;
  if (reuse) {
    await ctx.startServer();
    await ctx.startWorker();
  }
  ctx.browser = await launch();
  for (const s of [...core, ...ops]) {
    if (only && !only.includes(s.n)) continue;
    ctx.rec.start(s.n, s.title);
    try {
      await s.fn(ctx);
    } catch (e) {
      ctx.rec.check(false, '예외 없이 끝까지', `${e.message}`.slice(0, 300), (e.stack ?? '').split('\n').slice(1, 3).join(' '));
    }
    ctx.rec.current.seconds = Math.round((Date.now() - ctx.rec.current.started) / 1000);
    writeFileSync(statePath, JSON.stringify(ctx.S));
  }
} catch (e) {
  console.error(`중단: ${e.message}`);
  exitCode = 1;
} finally {
  await ctx.browser?.close().catch(() => null);
  for (const name of Object.keys(ctx.procs)) {
    if (name.startsWith('worker')) await ctx.stopWorker(name === 'worker' ? ctx.app : ctx.S.restoreApp, name);
    else await ctx.stopServer(name);
  }
  // 결과
  const out = path.join(ROOT, 'docs/results/P32_acceptance');
  mkdirSync(out, { recursive: true });
  const rows = ctx.rec.rows.map((r) => ({ n: r.n, title: r.title, seconds: r.seconds, pass: r.checks.every((c) => c.ok !== false), checks: r.checks, shots: r.shots }));
  if (!only && !arg('no-shots')) {
    writeFileSync(path.join(out, 'results.json'), JSON.stringify({ when: new Date().toISOString(), zip: path.basename(ctx.zip), rows, issues: ctx.rec.issues }, null, 1));
    const md = ['# P32 인수 시험 결과(자동 생성 — tools/acceptance/run.mjs)', '', `묶음 \`${path.basename(ctx.zip)}\`, ${new Date().toISOString()}`, ''];
    for (const r of rows) {
      md.push(`## ${r.n}. ${r.title} — ${r.pass ? '통과' : '실패'} (${r.seconds}초)`, '', '| 판정 | 기대 | 실제 | 증거 |', '| --- | --- | --- | --- |');
      for (const c of r.checks) md.push(`| ${c.ok === null ? '참고' : c.ok ? '통과' : '**실패**'} | ${c.expected.replace(/\|/g, '\\|')} | ${String(c.actual).replace(/\|/g, '\\|').replace(/\n/g, ' ')} | ${String(c.evidence).replace(/\|/g, '\\|').replace(/\n/g, ' ')} |`);
      md.push('');
    }
    writeFileSync(path.join(out, 'results.md'), md.join('\n'));
  }
  console.log('\n== 요약 ==');
  for (const r of rows) {
    const bad = r.checks.filter((c) => c.ok === false).length;
    console.log(`  ${String(r.n).padStart(2)}. ${r.pass ? '통과' : '실패'} — ${r.title} (확인 ${r.checks.filter((c) => c.ok !== null).length}, 실패 ${bad}, ${r.seconds}초)`);
    if (bad) exitCode = 1;
  }
  console.log(`${Math.round((Date.now() - t0) / 1000)}초, 설치 폴더 ${dir} ${keep ? '(남김)' : '(삭제)'}`);
  if (!keep) {
    await sleep(800);
    try { rmSync(dir, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 }); } catch (e) { console.log(`폴더 삭제 실패: ${e.message}`); }
    if (ctx.S.restoreDir) try { rmSync(ctx.S.restoreDir, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 }); } catch { /* 아래 보고 */ }
  }
  process.exit(exitCode);
}
