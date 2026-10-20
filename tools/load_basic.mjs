// 간단한 동시 사용자 부하(P17, 명세 19.6 목표의 설계 결함을 일찍 찾기 위한 것. 정식 부하 시험은 P31).
// 가상 사용자 N명이 각자 로그인한 뒤 정해진 시간 동안 "목록 화면(목록 + 필터 요약)", "결함 상세(화면 + 이력)", "코드 뷰어 열기"를
// 무작위로 반복한다(요청 사이 생각 시간). 화면 단위 응답 시간(같은 화면의 API를 함께 보낸 뒤 모두 끝날 때까지)의 p50·p95·최대를 출력한다.
//
// 실행: PJG_PASSWORD=... node tools/load_basic.mjs --base=http://127.0.0.1:8099[,http://127.0.0.1:8100…] --users=100 --seconds=60
//        [--login-prefix=u --login-start=1] [--projects=1:50000,2:5000…(id:결함 수, 생략하면 API로 구함)] [--think=500-1500]
//   --base에 여러 주소를 주면 가상 사용자를 돌아가며 나눈다(같은 DB를 쓰는 서버 여러 개 = 웹 서버 작업자 여러 개 흉내).
//   --scenario=dashboard(P20): 가상 사용자가 대시보드 화면(API 5개 summary·trend·top-rules·top-files·tools를 동시에)을 반복한다.
//     가끔 범위를 프로젝트 하나로 바꾼다(--projects의 id, 생략하면 전체만). 화면 응답 = 5개가 모두 끝날 때까지.
//   --scenario=runs(P20R): 과거 Run 화면(목록 + 요약, 무작위 Run·필터), 과거 Run 결함 상세(화면 + 이력), 과거 Run 코드를
//     섞어 반복한다. --runs=<최신 Run 번호>(생략하면 API로 구함).
//   --scenario=history(P21): 히스토리 화면(화면 + 이력 첫 쪽 + 차트용 100건 + 비교 요약), 비교 카드 → 결함 목록(analysis+change),
//     결함 전체 이력(타임라인)을 섞어 반복한다. 비교할 분석은 이력 API에서 무작위로 고른다.
//   비밀번호는 환경 변수로만. 개발 DB·공유 설정이 아닌 점검 서버에만 쓴다.
import { performance } from 'node:perf_hooks';

const args = Object.fromEntries(process.argv.slice(2).map((a) => { const m = /^--([^=]+)=?(.*)$/.exec(a); return m ? [m[1], m[2]] : [a, '']; }));
const bases = (args.base ?? 'http://127.0.0.1:8099').split(',');
const USERS = Number(args.users ?? 100);
const SECONDS = Number(args.seconds ?? 60);
const PREFIX = args['login-prefix'] ?? 'u';
const START = Number(args['login-start'] ?? 1);
const SKIP = new Set((args.skip ?? '').split(',').filter(Boolean).map(Number)); // 쓰지 않을 계정 번호(비활성 등)
const [THINK_MIN, THINK_MAX] = (args.think ?? '500-1500').split('-').map(Number);
const SCENARIO = args.scenario ?? 'defects';
const PW = process.env.PJG_PASSWORD;
if (!PW) {
  console.error('PJG_PASSWORD 환경 변수가 필요합니다.');
  process.exit(1);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const pick = (list) => list[Math.floor(Math.random() * list.length)];

class Client {
  constructor(base) { this.base = base; this.cookie = ''; this.csrf = ''; }
  async req(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (this.cookie) headers.Cookie = this.cookie;
    if (body !== undefined) { headers['Content-Type'] = 'application/json'; headers['X-CSRF-Token'] = this.csrf; }
    const res = await fetch(this.base + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: 'manual' });
    const set = res.headers.get('set-cookie');
    if (set) this.cookie = set.split(';')[0];
    const text = await res.text();
    return { status: res.status, text };
  }
  async login(id) {
    const c = await this.req('GET', '/api/v1/auth/csrf');
    this.csrf = JSON.parse(c.text).data.csrf_token;
    const r = await this.req('POST', '/api/v1/auth/login', { login_id: id, password: PW });
    if (r.status !== 200) throw new Error(`로그인 실패 ${id}: ${r.status} ${r.text.slice(0, 200)}`);
    this.csrf = JSON.parse(r.text).data.csrf_token;
  }
}

const stats = {};
const errors = {};
function record(name, ms, ok, detail) {
  (stats[name] ??= []).push(ms);
  if (!ok) errors[`${name}: ${detail}`] = (errors[`${name}: ${detail}`] ?? 0) + 1;
}
async function timed(name, fn) {
  const t = performance.now();
  let ok = true;
  let detail = '';
  try {
    const rs = await fn();
    for (const r of rs) if (r.status !== 200) { ok = false; detail = `HTTP ${r.status}`; }
  } catch (e) {
    ok = false;
    detail = e.message;
  }
  record(name, performance.now() - t, ok, detail);
}

const FILTERS = [
  '', 'status=open', 'severity=high', 'severity=high,critical&status=open,in_progress', 'rule=ruff:F401,ruff:E711',
  'assignee=none', 'file=src/pkg001/', 'q=undefined_v0_3', 'status=open&q=v0_1&sort=severity', 'sort=last_seen&order=desc',
  'file=mod_00&file_mode=contains&sort=rule',
];

async function vu(c, projects, deadline) {
  await sleep(Math.random() * 2000); // 시작을 흩뜨림
  while (Date.now() < deadline) {
    const [pid, count] = pick(projects);
    const roll = Math.random();
    if (roll < 0.45) {
      const f = pick(FILTERS);
      const pages = Math.max(1, Math.ceil(count / 50));
      const page = Math.random() < 0.2 ? Math.max(1, Math.floor(pages * 0.9)) : 1 + Math.floor(Math.random() * 3);
      await timed('목록 화면(목록 + 필터 요약)', () => Promise.all([
        c.req('GET', `/api/v1/projects/${pid}/defects?${f}&page=${page}&size=50`),
        c.req('GET', `/api/v1/projects/${pid}/defects/summary?${f}`),
      ]));
    } else if (roll < 0.75) {
      const id = pick(projects.idsOf(pid));
      await timed('결함 상세(화면 + 이력)', () => Promise.all([
        c.req('GET', `/defects/${id}`),
        c.req('GET', `/api/v1/defects/${id}/history`),
      ]));
    } else {
      const id = pick(projects.idsOf(pid));
      await timed('코드 뷰어 열기(코드 API)', () => Promise.all([c.req('GET', `/api/v1/defects/${id}/code`)]));
    }
    await sleep(THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN));
  }
}

async function vuRuns(c, projects, deadline) {
  await sleep(Math.random() * 2000);
  while (Date.now() < deadline) {
    const [pid] = pick(projects);
    const latest = projects.latestOf(pid);
    const run = 1 + Math.floor(Math.random() * Math.max(1, latest - 1)); // 과거 Run
    const roll = Math.random();
    if (roll < 0.45) {
      const f = pick(FILTERS);
      const page = 1 + Math.floor(Math.random() * 3);
      await timed('과거 Run 목록 화면(목록 + 요약)', () => Promise.all([
        c.req('GET', `/api/v1/projects/${pid}/defects?${f}&run=${run}&page=${page}&size=50`),
        c.req('GET', `/api/v1/projects/${pid}/defects/summary?${f}&run=${run}`),
      ]));
    } else if (roll < 0.7) {
      const id = pick(projects.idsOf(pid));
      await timed('과거 Run 결함 상세(화면 + 이력)', () => Promise.all([
        c.req('GET', `/defects/${id}?run=${run}`),
        c.req('GET', `/api/v1/defects/${id}/history`),
      ]));
    } else {
      const id = pick(projects.idsOf(pid));
      await timed('과거 Run 코드', () => Promise.all([c.req('GET', `/api/v1/defects/${id}/code?run=${run}`)]));
    }
    await sleep(THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN));
  }
}

async function vuHistory(c, projects, deadline) {
  await sleep(Math.random() * 2000);
  while (Date.now() < deadline) {
    const [pid] = pick(projects);
    const aid = pick(projects.analysesOf(pid));
    const roll = Math.random();
    if (roll < 0.5) {
      await timed('히스토리 화면(화면 + 이력 + 차트 + 비교)', () => Promise.all([
        c.req('GET', `/projects/${pid}/history?analysis=${aid}`),
        c.req('GET', `/api/v1/projects/${pid}/history?page=1&size=20`),
        c.req('GET', `/api/v1/projects/${pid}/history?page=1&size=100`),
        c.req('GET', `/api/v1/analyses/${aid}/comparison`),
      ]));
    } else if (roll < 0.8) {
      const change = pick(['new', 'resolved', 'reopened']);
      await timed('비교 카드 → 결함 목록(목록 + 요약)', () => Promise.all([
        c.req('GET', `/api/v1/projects/${pid}/defects?analysis=${aid}&change=${change}&page=1&size=50`),
        c.req('GET', `/api/v1/projects/${pid}/defects/summary?analysis=${aid}&change=${change}`),
      ]));
    } else {
      const id = pick(projects.idsOf(pid));
      await timed('결함 전체 이력(타임라인)', () => Promise.all([c.req('GET', `/api/v1/defects/${id}/timeline`)]));
    }
    await sleep(THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN));
  }
}

async function vuDashboard(c, projects, deadline) {
  await sleep(Math.random() * 2000);
  while (Date.now() < deadline) {
    const scope = projects.length && Math.random() < 0.3 ? `?project_id=${pick(projects)[0]}` : '';
    await timed('대시보드 화면(API 5개)', () => Promise.all([
      c.req('GET', '/api/v1/dashboard/summary'),
      c.req('GET', `/api/v1/dashboard/trend${scope}`),
      c.req('GET', `/api/v1/dashboard/top-rules${scope}`),
      c.req('GET', `/api/v1/dashboard/top-files${scope}`),
      c.req('GET', '/api/v1/dashboard/tools'),
    ]));
    await sleep(THINK_MIN + Math.random() * (THINK_MAX - THINK_MIN));
  }
}

/** i번째 가상 사용자의 로그인 ID(SKIP 번호는 건너뜀) */
function loginName(i) {
  let n = START;
  for (let k = 0; ; n++) {
    if (SKIP.has(n)) continue;
    if (k++ === i) break;
  }
  return `${PREFIX}${String(n).padStart(3, '0')}`;
}

function pct(list, p) {
  const s = [...list].sort((a, b) => a - b);
  return s[Math.min(s.length - 1, Math.ceil((p / 100) * s.length) - 1)];
}

// 대상 프로젝트와 결함 id 표본(admin 아닌 첫 사용자로 조회)
const probe = new Client(bases[0]);
await probe.login(loginName(0));
const projects = [];
const idCache = new Map();
for (const spec of (args.projects ?? '').split(',').filter(Boolean)) {
  const [pid, count] = spec.split(':').map(Number);
  projects.push([pid, count]);
}
for (const [pid] of SCENARIO === 'dashboard' ? [] : projects) {
  const ids = [];
  for (const page of [1, 50, 100, 400, 900]) {
    const r = JSON.parse((await probe.req('GET', `/api/v1/projects/${pid}/defects?page=${page}&size=50&sort=last_seen`)).text);
    for (const d of r.data ?? []) ids.push(d.id);
  }
  idCache.set(pid, ids);
}
projects.idsOf = (pid) => idCache.get(pid);
const latestCache = new Map();
if (SCENARIO === 'runs') {
  for (const [pid] of projects) {
    latestCache.set(pid, Number(args.runs) || JSON.parse((await probe.req('GET', `/api/v1/projects/${pid}/runs?size=1`)).text).status.latest);
  }
}
projects.latestOf = (pid) => latestCache.get(pid);
const analysesCache = new Map();
if (SCENARIO === 'history') {
  for (const [pid] of projects) {
    const r = JSON.parse((await probe.req('GET', `/api/v1/projects/${pid}/history?size=100`)).text);
    analysesCache.set(pid, r.data.filter((a) => a.status === 'succeeded').map((a) => a.analysis_id));
  }
}
projects.analysesOf = (pid) => analysesCache.get(pid);
// 결함 수가 많은 프로젝트를 더 자주(가중치 = 결함 수)
const weighted = [];
for (const p of projects) for (let k = 0; k < Math.max(1, Math.round(p[1] / 5000)); k++) weighted.push(p);
weighted.idsOf = projects.idsOf;
weighted.latestOf = projects.latestOf;
weighted.analysesOf = projects.analysesOf;

console.log(`가상 사용자 ${USERS}명, ${SECONDS}초, 서버 ${bases.length}개, 생각 시간 ${THINK_MIN}~${THINK_MAX}ms, 프로젝트 ${projects.map((p) => p.join(':')).join(', ')}`);
// 1단계: 모두 로그인(측정에서 뺀다. 첫 로그인은 bcrypt 재해시로 느리고, 단일 작업자 서버에서는 줄을 세워 측정을 오염시킨다)
const clients = Array.from({ length: USERS }, (_, i) => new Client(bases[i % bases.length]));
const tl = Date.now();
for (let k = 0; k < USERS; k += 10) await Promise.all(clients.slice(k, k + 10).map((c, j) => c.login(loginName(k + j))));
console.log(`로그인 ${USERS}명 ${((Date.now() - tl) / 1000).toFixed(1)}초(측정 제외)`);
// 2단계: 측정
const t0 = Date.now();
const deadline = Date.now() + SECONDS * 1000;
const runner = { dashboard: () => (c) => vuDashboard(c, projects, deadline), runs: () => (c) => vuRuns(c, weighted, deadline), history: () => (c) => vuHistory(c, weighted, deadline) }[SCENARIO]?.() ?? ((c) => vu(c, weighted, deadline));
await Promise.all(clients.map(runner));
const elapsed = (Date.now() - t0) / 1000;
console.log(`\n| 화면 | 횟수 | p50(ms) | p95(ms) | 최대(ms) | 오류 |\n| --- | --- | --- | --- | --- | --- |`);
for (const [name, list] of Object.entries(stats)) {
  const errs = Object.entries(errors).filter(([k]) => k.startsWith(name)).reduce((a, [, v]) => a + v, 0);
  console.log(`| ${name} | ${list.length} | ${pct(list, 50).toFixed(0)} | ${pct(list, 95).toFixed(0)} | ${Math.max(...list).toFixed(0)} | ${errs} |`);
}
const total = Object.values(stats).reduce((a, l) => a + l.length, 0);
console.log(`\n전체 ${total}회, ${elapsed.toFixed(0)}초, 화면 처리량 ${(total / SECONDS).toFixed(1)}/초`);
if (Object.keys(errors).length) console.log('오류:', errors);
