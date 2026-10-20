// 부하 시험(P31 B-6, 명세 19.6): Apache 다중 작업자(mpm_winnt 스레드)에서 동시 사용자 20·50·100.
// 외부 도구 없이 Node 내장 fetch만. 실행 환경은 모두 격리한다:
//   - 가짜 데이터 설치본(tools/guide_demo + 큰 프로젝트 하나 결함 --big개, 임시 폴더·새 DB)
//   - XAMPP의 httpd.exe를 **임시 설정 파일**(-f)로 별도 포트(기본 8099)에 하나 더 띄움 — 사용자 Apache 설정·vhost는 건드리지 않음.
//     php.ini도 임시 사본(intl 켬)을 PHPIniDir로. 앱 설정은 SetEnv PJG_ISO_INI(설치본 config.ini)
//   - 격리 워커(같은 설치본) — "워커와 동시" 단계에서는 큰 반영(seed_defects로 결함 수만 건 쓰기)을 함께 돌림
// 끝나면 Apache·워커를 멈추고 폴더를 지운다(--keep이면 남김).
//
//   node tools/loadtest/run.mjs [--users=20,50,100] [--seconds=60] [--port=8099] [--threads=150] [--big=50000] [--keep] [--json=<결과 파일>]
//
// 시나리오(가상 사용자 하나가 생각 시간 500~1500ms를 두고 무작위 반복, 한 번 = 한 "화면"의 요청 묶음):
//   dashboard(대시보드 HTML + API 5개 동시), defects(결함 목록 + 요약), search(검색어·경로 조건 목록 + 요약), detail(결함 상세 + 이력 + 코드),
//   export(작은 프로젝트 CSV 내보내기 — 드물게 3%), upload(업로드 시작 + 취소 — 드물게 3%), poll(알림 개수 — 매 반복), monitoring(관리자 가상 사용자만)
//   login은 시작할 때 모든 가상 사용자가 한꺼번에(bcrypt 비용 그대로).
// 출력: 단계별·시나리오별 p50·p95·p99·최대(ms), 오류율, SQLite 잠금 오류 수(앱 로그의 database is locked·busy), Apache 오류 로그 줄 수.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync, readFileSync, writeFileSync, existsSync, readdirSync, copyFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { performance } from 'node:perf_hooks';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..').replace(/\\/g, '/');
const opt = (k, d) => process.argv.find((a) => a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : d);
const PORT = Number(opt('port', 8099));
const USERS = String(opt('users', '20,50,100')).split(',').map(Number);
const SECONDS = Number(opt('seconds', 60));
const THREADS = Number(opt('threads', 150));
const BIG = Number(opt('big', 50000));
const PHP = (process.env.PJG_PHP ?? 'C:/xampp/php/php.exe').replace(/\\/g, '/');
const XAMPP = (process.env.PJG_XAMPP ?? 'C:/xampp').replace(/\\/g, '/');
const work = `${ROOT}/data/work/loadtest_${randomBytes(4).toString('hex')}`;
const PW = `Load-${randomBytes(9).toString('base64url')}1!`;
const BASE = `http://127.0.0.1:${PORT}`;
const sleep = (ms) => new Promise((r) => { setTimeout(r, ms); });
const pick = (list) => list[Math.floor(Math.random() * list.length)];
let httpd = null;
let worker = null;
let writer = null;

process.on('exit', () => {
  for (const p of [httpd, worker, writer]) { try { p?.kill(); } catch { /* 이미 끝남 */ } }
  try { execFileSync('taskkill', ['/F', '/FI', `WINDOWTITLE eq loadtest-${PORT}`], { stdio: 'ignore' }); } catch { /* 없음 */ }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`폴더를 지우지 못함: ${e.code}`); } }
});

// ---- 1. 설치본 ----
mkdirSync(work, { recursive: true });
console.log(`설치본: ${work}`);
execFileSync(PHP, [`${ROOT}/tools/guide_demo/setup.php`, work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'ignore', 'inherit'] });
const db = `${work}/db/demo.sqlite`;
// 큰 프로젝트(결함 BIG개, 파일 500개, 코드 스냅샷) — alex에게 매핑
execFileSync(PHP, ['-r', `$p = new PDO('sqlite:' . $argv[1]); $t = '2026-10-01T00:00:00Z';
  $p->exec("INSERT INTO projects (name, language, description, created_at, updated_at) VALUES ('load-big', 'python', 'load test', '$t', '$t')");
  $id = (int) $p->lastInsertId(); $a = (int) $p->query("SELECT id FROM users WHERE login_id = 'alex'")->fetchColumn();
  $p->exec("INSERT INTO project_access (project_id, subject_type, subject_id, created_at) VALUES ($id, 'user', $a, '$t')"); echo $id;`, db]);
const bigId = Number(execFileSync(PHP, ['-r', `$p = new PDO('sqlite:' . $argv[1]); echo $p->query("SELECT id FROM projects WHERE name = 'load-big'")->fetchColumn();`, db]).toString());
const t0 = performance.now();
execFileSync(PHP, ['-d', 'memory_limit=2G', `${ROOT}/tools/seed_defects.php`, `--db=${db}`, `--project=${bigId}`, `--defects=${BIG}`, '--files=500', `--snapshots-dir=${work}/snapshots`], { stdio: ['ignore', 'ignore', 'inherit'] });
console.log(`큰 프로젝트 #${bigId} 결함 ${BIG}개 준비 ${((performance.now() - t0) / 1000).toFixed(1)}초`);
const ids = JSON.parse(execFileSync(PHP, ['-r', `$p = new PDO('sqlite:' . $argv[1]); echo json_encode(['defects' => array_map('intval', $p->query("SELECT id FROM defects WHERE project_id = ${bigId} ORDER BY RANDOM() LIMIT 300")->fetchAll(PDO::FETCH_COLUMN)),
  'small' => (int) $p->query("SELECT id FROM projects WHERE name = 'sample-api'")->fetchColumn()]);`, db]).toString());

// ---- 2. Apache(임시 설정) ----
mkdirSync(`${work}/docroot`, { recursive: true });
mkdirSync(`${work}/php`, { recursive: true });
writeFileSync(`${work}/docroot/front.php`, `<?php
// 부하 시험 전용 프런트 컨트롤러: 설정은 SetEnv PJG_ISO_INI(가짜 데이터 설치본)
$_SERVER['SCRIPT_NAME'] = '/index.php';
require '${ROOT}/src/bootstrap.php';
$ini = (string) getenv('PJG_ISO_INI');
$app = PJGuardian\\App::create(PJG_ROOT, PJGuardian\\Support\\Config::load($ini, dirname($ini) . '/secrets.ini', getenv()));
$request = PJGuardian\\Http\\Request::fromGlobals();
$app->handle($request)->send($request->method !== 'HEAD');
`);
let ini = readFileSync(`${XAMPP}/php/php.ini`, 'utf8');
if (!/^\s*extension\s*=\s*"?intl"?/m.test(ini)) ini += '\nextension=intl\n';
ini = ini.replace(/^\s*extension_dir\s*=.*$/m, `extension_dir="${XAMPP}/php/ext"`);
writeFileSync(`${work}/php/php.ini`, ini);
const conf = `ServerRoot "${XAMPP}/apache"
Listen 127.0.0.1:${PORT}
ServerName 127.0.0.1:${PORT}
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule alias_module modules/mod_alias.so
LoadModule env_module modules/mod_env.so
LoadModule log_config_module modules/mod_log_config.so
LoadModule php_module "${XAMPP}/php/php8apache2_4.dll"
PHPIniDir "${work}/php"
TypesConfig conf/mime.types
PidFile "${work}/httpd.pid"
ErrorLog "${work}/apache_error.log"
LogLevel warn
ThreadsPerChild ${THREADS}
MaxConnectionsPerChild 0
KeepAlive On
KeepAliveTimeout 5
DocumentRoot "${work}/docroot"
<Directory "${work}/docroot">
  Require all granted
  FallbackResource /front.php
  <FilesMatch "\\.php$">
    SetHandler application/x-httpd-php
  </FilesMatch>
</Directory>
AliasMatch "^/assets/(?!v/)(.*)$" "${ROOT}/public/assets/$1"
<Directory "${ROOT}/public/assets">
  Require all granted
</Directory>
SetEnv PJG_ISO_INI "${work}/config.ini"
`;
writeFileSync(`${work}/httpd.conf`, conf);
execFileSync(`${XAMPP}/apache/bin/httpd.exe`, ['-t', '-f', `${work}/httpd.conf`], { stdio: 'inherit' });
httpd = spawn(`${XAMPP}/apache/bin/httpd.exe`, ['-f', `${work}/httpd.conf`], { stdio: 'ignore' });
for (let i = 0; i < 100; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}
worker = spawn(PHP, ['-d', 'extension=intl', `${ROOT}/tools/worker.php`, `--config=${work}/config.ini`], { stdio: 'ignore' });
console.log(`Apache ${BASE} (ThreadsPerChild ${THREADS}), 격리 워커 시작`);

// ---- 3. 가상 사용자 ----
class Client {
  constructor() { this.cookie = ''; this.csrf = ''; }
  async req(method, p, body) {
    const headers = { Accept: 'application/json' };
    if (this.cookie) headers.Cookie = this.cookie;
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = this.csrf; }
    const res = await fetch(BASE + p, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: 'manual' });
    const set = res.headers.get('set-cookie');
    if (set) this.cookie = set.split(';')[0];
    const text = await res.text();
    return { status: res.status, text };
  }
  async login(id) {
    const c = await this.req('GET', '/api/v1/auth/csrf');
    this.csrf = JSON.parse(c.text).data.csrf_token;
    const r = await this.req('POST', '/api/v1/auth/login', { login_id: id, password: PW });
    if (r.status !== 200) throw new Error(`로그인 ${r.status} ${r.text.slice(0, 120)}`);
    this.csrf = JSON.parse(r.text).data.csrf_token;
  }
}

let stats;
let errors;
let total;
function rec(name, ms, ok, detail) {
  (stats[name] ??= []).push(ms);
  total.n += 1;
  if (!ok) { total.err += 1; errors[`${name}: ${detail}`] = (errors[`${name}: ${detail}`] ?? 0) + 1; }
}
async function screen(name, c, reqs) {
  const s = performance.now();
  try {
    const rs = await Promise.all(reqs.map(([m, p, b]) => c.req(m, p, b)));
    const bad = rs.find((r) => r.status >= 400 && !(name === 'export' && r.status === 429) && !(name === 'upload' && r.status === 429));
    rec(name, performance.now() - s, !bad, bad ? `${bad.status} ${bad.text.slice(0, 60)}` : '');
    return rs;
  } catch (e) {
    rec(name, performance.now() - s, false, e.message.slice(0, 60));
    return null;
  }
}
const words = ['import', 'unused', 'src', 'module', 'line', 'name'];
async function vu(i, until) {
  const admin = i % 10 === 0;
  const c = new Client();
  const s = performance.now();
  try { await c.login(admin ? 'demo-admin' : 'alex'); rec('login', performance.now() - s, true); } catch (e) { rec('login', performance.now() - s, false, e.message.slice(0, 60)); return; }
  while (performance.now() < until) {
    const r = Math.random();
    if (r < 0.2) {
      await screen('dashboard', c, [['GET', '/dashboard'], ['GET', '/api/v1/dashboard/summary'], ['GET', '/api/v1/dashboard/trend'], ['GET', '/api/v1/dashboard/top-rules'],
        ['GET', '/api/v1/dashboard/top-files'], ['GET', '/api/v1/dashboard/tools']]);
    } else if (r < 0.45) {
      const page = 1 + Math.floor(Math.random() * 20);
      await screen('defects', c, [['GET', `/api/v1/projects/${bigId}/defects?page=${page}&size=50`], ['GET', `/api/v1/projects/${bigId}/defects/summary`]]);
    } else if (r < 0.6) {
      const q = encodeURIComponent(pick(words));
      await screen('search', c, [['GET', `/api/v1/projects/${bigId}/defects?q=${q}&size=50`], ['GET', `/api/v1/projects/${bigId}/defects/summary?q=${q}`]]);
    } else if (r < 0.88) {
      const id = pick(ids.defects);
      await screen('detail', c, [['GET', `/api/v1/defects/${id}`], ['GET', `/api/v1/defects/${id}/history`], ['GET', `/api/v1/defects/${id}/code`]]);
    } else if (r < 0.91) {
      await screen('export', c, [['POST', `/api/v1/projects/${ids.small}/defects/export`, { format: 'csv' }]]);
    } else if (r < 0.94) {
      const rs = await screen('upload', c, [['POST', `/api/v1/projects/${ids.small}/uploads`, { file_name: 'load.zip', total_size: 1048576 }]]);
      const up = rs?.[0]?.status === 201 ? JSON.parse(rs[0].text).data : null;
      if (up?.upload_id) await c.req('DELETE', `/api/v1/uploads/${up.upload_id}`);
    } else if (admin) {
      await screen('monitoring', c, [['GET', '/api/v1/admin/monitoring']]);
    }
    await screen('poll', c, [['GET', '/api/v1/notifications/unread-count']]);
    await sleep(500 + Math.random() * 1000);
  }
}
const pct = (arr, p) => { if (!arr.length) return null; const s = [...arr].sort((a, b) => a - b); return Math.round(s[Math.min(s.length - 1, Math.ceil(s.length * p) - 1)]); };
function locks() {
  let n = 0;
  for (const f of readdirSync(`${work}/logs`)) {
    if (/\.log$/.test(f)) n += (readFileSync(`${work}/logs/${f}`, 'utf8').match(/database is locked|SQLSTATE\[HY000\]: General error: 5|database table is locked|busy/gi) ?? []).length;
  }
  return n;
}
const apacheErrors = () => (existsSync(`${work}/apache_error.log`) ? readFileSync(`${work}/apache_error.log`, 'utf8').split('\n').filter((l) => /\[(error|crit|alert|emerg)\]|php:error|PHP Fatal/i.test(l)).length : 0);

const results = [];
async function phase(label, n, withWriter = false) {
  stats = {};
  errors = {};
  total = { n: 0, err: 0 };
  const lockBefore = locks();
  const apBefore = apacheErrors();
  if (withWriter) {
    // 워커와 동시: 큰 반영(결함 3만 건을 새 프로젝트에 쓰기)을 함께 — 분석 반영처럼 긴 쓰기 트랜잭션
    writer = spawn(PHP, ['-d', 'memory_limit=2G', `${ROOT}/tools/seed_defects.php`, `--db=${db}`, `--project=${ids.small}`, '--defects=30000', '--files=200'], { stdio: 'ignore' });
  }
  const until = performance.now() + SECONDS * 1000;
  const started = performance.now();
  await Promise.all(Array.from({ length: n }, (_, i) => vu(i, until)));
  const secs = (performance.now() - started) / 1000;
  if (writer) { try { writer.kill(); } catch { /* 끝남 */ } writer = null; }
  const row = { label, users: n, seconds: Math.round(secs), requests: total.n, rps: Math.round(total.n / secs), error_rate: total.n ? +(100 * total.err / total.n).toFixed(2) : 0,
    sqlite_locks: locks() - lockBefore, apache_errors: apacheErrors() - apBefore, scenarios: {} };
  for (const [k, v] of Object.entries(stats)) row.scenarios[k] = { n: v.length, p50: pct(v, 0.5), p95: pct(v, 0.95), p99: pct(v, 0.99), max: Math.round(Math.max(...v)) };
  row.errors = errors;
  results.push(row);
  console.log(`\n== ${label}: 동시 ${n}명 ${row.seconds}초, 요청 ${row.requests}(${row.rps}/s), 오류율 ${row.error_rate}%, SQLite 잠금 ${row.sqlite_locks}, Apache 오류 ${row.apache_errors}`);
  console.log('시나리오'.padEnd(12) + '건수'.padStart(7) + 'p50'.padStart(8) + 'p95'.padStart(8) + 'p99'.padStart(8) + '최대'.padStart(8));
  for (const [k, s] of Object.entries(row.scenarios)) console.log(k.padEnd(12) + String(s.n).padStart(7) + String(s.p50).padStart(8) + String(s.p95).padStart(8) + String(s.p99).padStart(8) + String(s.max).padStart(8));
  if (Object.keys(errors).length) console.log('오류:', JSON.stringify(errors));
  await sleep(3000);
}

try {
  await phase('워밍업', 5);
  for (const n of USERS) await phase(`동시 ${n}`, n);
  await phase(`동시 ${USERS.includes(50) ? 50 : USERS[0]} + 큰 쓰기(워커 반영 흉내)`, USERS.includes(50) ? 50 : USERS[0], true);
} finally {
  try { execFileSync(PHP, ['-d', 'extension=intl', `${ROOT}/tools/worker.php`, '--stop', `--config=${work}/config.ini`], { stdio: 'ignore' }); } catch { /* 멈춤 */ }
  try { httpd?.kill(); } catch { /* 끝남 */ }
  // mpm_winnt는 자식 프로세스가 따로 있다 — PID 파일로 정리
  try { const pid = readFileSync(`${work}/httpd.pid`, 'utf8').trim(); execFileSync('taskkill', ['/F', '/T', '/PID', pid], { stdio: 'ignore' }); } catch { /* 이미 끝남 */ }
}
if (opt('json')) writeFileSync(String(opt('json')), JSON.stringify(results, null, 1));
console.log('\n부하 시험 끝');
process.exit(0);
