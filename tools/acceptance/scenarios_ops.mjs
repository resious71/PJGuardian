// 인수 시험(P32) 시나리오 9~16: 저장소·주기 분석, 외부 인증, 설정·모니터링, 백업·복원, 감사 로그, 가이드·FAQ, 보안 점검, 정리·보존.
import path from 'node:path';
import { readFileSync, writeFileSync, mkdirSync, existsSync, readdirSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { screen, setPrefs, addSecret, Client } from './lib.mjs';
import { PW, ADMIN_PW, upload, settle } from './scenarios_core.mjs';

const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
/** 받은 메일 원문의 제목(인코딩된 낱말 =?utf-8?B|Q?…?= 풀기) */
const subjectOf = (m) => {
  const head = (m?.data ?? '').split(/\r\n\r\n|\n\n/)[0].replace(/\r?\n[ \t]+/g, ' ');
  const raw = head.split(/\r?\n/).find((l) => /^Subject:/i.test(l))?.slice(8).trim() ?? '';
  return raw.replace(/=\?utf-8\?([BQ])\?([^?]*)\?=\s*/gi, (_, e, s) => (e.toUpperCase() === 'B' ? Buffer.from(s, 'base64').toString('utf8')
    : Buffer.from(s.replace(/_/g, ' ').replace(/=([0-9A-F]{2})/gi, (__, h) => String.fromCharCode(parseInt(h, 16))), 'latin1').toString('utf8'))).trim();
};
/** 받은 메일 본문(base64면 풀기) */
const bodyOf = (m) => {
  const raw = m?.data ?? '';
  const i = raw.search(/\r?\n\r?\n/);
  const head = i < 0 ? raw : raw.slice(0, i);
  const rest = i < 0 ? '' : raw.slice(i).trim();
  return /Content-Transfer-Encoding:\s*base64/i.test(head) ? Buffer.from(rest.replace(/\s+/g, ''), 'base64').toString('utf8') : rest;
};
const tool = (ctx, f) => path.join(ctx.ROOT, 'tools/acceptance', f);
/** KST HH:MM(지금 + 분) */
const kstHm = (plusMinutes) => { const d = new Date(Date.now() + 9 * 3600e3 + plusMinutes * 60e3); return `${String(d.getUTCHours()).padStart(2, '0')}:${String(d.getUTCMinutes()).padStart(2, '0')}`; };
async function waitAnalysis(ctx, c, id, ms = 180000) {
  return ctx.until(async () => { const a = await c.data('GET', `/api/v1/analyses/${id}`); return ['succeeded', 'failed', 'cancelled'].includes(a.status) ? a : null; }, ms, 700);
}
/** 설정 관리 저장(현재 version으로) */
export async function saveSettings(admin, changes) {
  const cur = await admin.data('GET', '/api/v1/admin/config-settings');
  return admin.req('PUT', '/api/v1/admin/config-settings', { json: { changes, version: cur.version } });
}

export const scenarios = [
  {
    n: 9, title: '저장소·주기 분석 — 가짜 Git HTTP 서버(토큰), 허용 호스트·연결 테스트, 가져오기, 변경 없음 생략, 주기 분석 연결 실패 재시도',
    async fn(ctx) {
      const { rec, S } = ctx;
      const gdir = path.join(ctx.dir, 'git');
      ctx.setConfig('repository', 'allow_local_dev', 'true');
      ctx.setConfig('scheduler', 'tick_seconds', '10s');
      ctx.setConfig('repository', 'connect_retry_interval', '20s');
      await ctx.stopWorker();
      await ctx.startWorker();
      rec.note('시험 설정(설치본 config.ini): [repository] allow_local_dev=true(127.0.0.1의 시험 저장소 허용 — 운영은 false), [scheduler] tick_seconds=10s, [repository] connect_retry_interval=20s(재시도를 기다리는 시간 단축). git-http-backend는 이 PC의 Git(시험 지원 코드만)');
      const admin = await ctx.user('admin');
      const dev = await ctx.user('dev1');
      const c1 = ctx.php([tool(ctx, 'git_fixture.php'), 'commit', gdir, 'app', path.join(ctx.ROOT, 'samples/python_sample')], { cwd: ctx.ROOT }).out.trim().split('\n').pop();
      const gport = await ctx.freePort(8191);
      const TOKEN = `tok-${randomBytes(8).toString('hex')}`;
      addSecret(TOKEN);
      const cfg = ctx.php([tool(ctx, 'git_fixture.php'), 'config', gdir, String(gport), `bot:${TOKEN}`], { cwd: ctx.ROOT }).out.trim().split('\n').pop();
      const startGit = () => ctx.spawnBg('git', ['-S', `127.0.0.1:${gport}`, path.join(ctx.ROOT, 'tests/Support/git_http_router.php')], { env: { PJG_GIT_HTTP_CONFIG: cfg }, cwd: gdir, port: gport });
      await startGit();
      rec.check(/^[0-9a-f]{40}$/.test(c1), `가짜 Git 서버(http://127.0.0.1:${gport}, Basic 토큰) + 저장소 app(커밋 python_sample)`, c1.slice(0, 12), 'git_fixture.php');
      const h = await admin.data('POST', '/api/v1/admin/repo-hosts', { host: '127.0.0.1', port: gport, allow_http: true, note: 'acceptance' });
      const ht = await admin.req('POST', `/api/v1/admin/repo-hosts/${h.id ?? h.host?.id}/test`);
      rec.check(ht.status === 200, '허용 호스트 등록 → 연결 테스트(이름 풀이·TCP·HTTP 상태 줄)', `${ht.status} ${JSON.stringify(ht.json?.data).slice(0, 220)}`, 'POST /api/v1/admin/repo-hosts/{id}/test');
      const pid = (S.p.repo = (await admin.data('POST', '/api/v1/projects', { name: 'repo', language: 'python' })).id);
      await admin.data('POST', `/api/v1/projects/${pid}/access`, { subject_type: 'user', login_id: 'dev1' });
      const url = `http://127.0.0.1:${gport}/app.git`;
      const bad = await admin.req('PUT', `/api/v1/projects/${pid}/repo`, { json: { kind: 'git', url, branch: 'main', username: 'bot', secret: 'wrong-token' } });
      const tBad = await admin.req('POST', `/api/v1/projects/${pid}/repo/test`);
      rec.check(bad.status === 200 && tBad.json?.data?.ok === false, '틀린 토큰으로 연결 테스트 → 실패(사유 코드)', `${tBad.status} ${JSON.stringify(tBad.json?.data).slice(0, 200)}`, 'POST /api/v1/projects/{id}/repo/test');
      const rev = (await admin.data('GET', `/api/v1/projects/${pid}/repo`))?.connection?.revision ?? (await admin.data('GET', `/api/v1/projects/${pid}/repo`))?.revision;
      const put = await admin.req('PUT', `/api/v1/projects/${pid}/repo`, { json: { kind: 'git', url, branch: 'main', username: 'bot', secret: TOKEN, ...(rev ? { revision: rev } : {}) } });
      const tOk = await admin.req('POST', `/api/v1/projects/${pid}/repo/test`);
      rec.check(put.status === 200 && tOk.json?.data?.ok === true, '맞는 토큰 저장 → 연결 테스트 성공', `${put.status} ${JSON.stringify(tOk.json?.data).slice(0, 200)}`, 'PUT·POST /api/v1/projects/{id}/repo(/test)');
      const devView = await dev.get(`/api/v1/projects/${pid}`);
      rec.check(!devView.body.includes(TOKEN), '일반 사용자 응답에 토큰 없음', devView.status, `GET /api/v1/projects/${pid} (dev1)`);
      const f1 = await admin.data('POST', `/api/v1/projects/${pid}/repo/fetch`);
      const a1 = await waitAnalysis(ctx, admin, f1.analysis_id);
      rec.check(a1?.status === 'succeeded' && a1.counts?.total === 19 && String(JSON.stringify(a1)).includes(c1.slice(0, 7)), '지금 가져와 분석 → 성공(결함 19), 출처 커밋 기록', `${a1?.status} total=${a1?.counts?.total}`, `analysis ${f1.analysis_id}`);
      const reqLog = readFileSync(path.join(gdir, 'http.log'), 'utf8');
      rec.check(!reqLog.includes(TOKEN) && /"auth":"Basic /.test(reqLog), '토큰은 URL이 아니라 Authorization 헤더로만', `${reqLog.split('\n').length - 1}개 요청`, 'git 서버 요청 기록');
      const f2 = await admin.data('POST', `/api/v1/projects/${pid}/repo/fetch`);
      const a2 = await waitAnalysis(ctx, admin, f2.analysis_id);
      const nc = ctx.sqlOne('SELECT no_change, run_number FROM analyses WHERE id = ?', f2.analysis_id);
      rec.check(a2?.status === 'succeeded' && nc?.no_change === 1 && nc.run_number === null, '같은 커밋 다시 가져오기 → 성공(변경 없음), 분석 생략(Run 만들지 않음)', `${a2?.status} no_change=${nc?.no_change} run=${nc?.run_number}`, `analysis ${f2.analysis_id}`);
      // 주기 분석: Git 서버를 끄고 새 커밋 → 예약 시각에 가져오기 실패 → 재시도 대기 → 서버를 켜면 다음 재시도에 성공
      await ctx.stopServer('git');
      const c2 = ctx.php([tool(ctx, 'git_fixture.php'), 'commit', gdir, 'app', '', JSON.stringify({ 'extra/new_mod.py': 'import json\n' })], { cwd: ctx.ROOT }).out.trim().split('\n').pop();
      const runTime = kstHm(2);
      const sch = await admin.req('PUT', `/api/v1/projects/${pid}/schedule`, { json: { enabled: true, interval_days: 1, run_time: runTime } });
      rec.check(sch.status === 200, `주기 분석 켬(1일, KST ${runTime})`, `${sch.status} next=${JSON.stringify(sch.json?.data).slice(0, 160)}`, 'PUT /api/v1/projects/{id}/schedule');
      const retry = await ctx.until(() => ctx.sqlOne("SELECT id, status, retry_count, retry_code, retry_after FROM analyses WHERE project_id = ? AND trigger_kind = 'schedule' AND retry_count >= 1 ORDER BY id DESC LIMIT 1", pid), 240000, 1000);
      rec.check(!!retry, '예약 시각에 가져오기 → 연결 실패 → 재시도 대기(같은 분석)', JSON.stringify(retry), 'analyses.retry_count');
      await startGit();
      const done = retry ? await ctx.until(() => { const r = ctx.sqlOne('SELECT id, status, source_revision, retry_count FROM analyses WHERE id = ?', retry.id); return ['succeeded', 'failed', 'cancelled'].includes(r.status) ? r : null; }, 180000, 1000) : null;
      rec.check(done?.status === 'succeeded' && done.source_revision === c2, '서버를 다시 켜면 재시도에서 가져와 분석 성공(새 커밋)', JSON.stringify(done), `analysis ${retry?.id}`);
      await admin.req('PUT', `/api/v1/projects/${pid}/schedule`, { json: { enabled: false, interval_days: 1, run_time: runTime } });
      const pa = await ctx.page('admin');
      await screen(pa, rec, `/projects/${pid}`, { lang: 'ko', theme: 'light', shot: 'S09_repo_project' });
      const adminC = await ctx.user('admin');
      await setPrefs(adminC, 'en', 'dark');
      await screen(pa, rec, '/admin/schedules', { lang: 'en', theme: 'dark', shot: 'S09_admin_schedules_en_dark' });
      await setPrefs(adminC, 'ko', 'light');
      S.repoCommit = c2;
    },
  },
  {
    n: 10, title: '외부 인증 — 가짜 LDAP 디렉터리·OIDC 제공자·SAML IdP로 첫 로그인 → 승인 대기 → 승인 → 로그인',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await ctx.user('admin');
      const fdir = path.join(ctx.dir, 'fakes');
      rec.note('가짜 제공자는 시험 지원 코드(tests/Support)를 인수 라우터가 끼운다 — 실제 LDAP/AD·IdP 연결은 미확인(운영 확인 항목)');
      const approve = async (login) => {
        const list = await admin.data('GET', '/api/v1/users?approval=pending');
        const u = list.find((x) => x.login_id === login);
        if (!u) return { status: 0 };
        return admin.req('POST', `/api/v1/users/${u.id}/approve`, { json: { role: 'user', updated_at: u.updated_at } });
      };
      // LDAP
      const DIRPW = `Dir-${randomBytes(4).toString('hex')}`;
      addSecret(DIRPW);
      writeFileSync(path.join(fdir, 'ldap.json'), JSON.stringify({ users: { lee: { password: DIRPW, id: 'guid-lee', name: 'Lee Dir', email: 'lee@corp.test' } } }));
      ctx.setConfig('ldap', 'enabled', 'true');
      ctx.setConfig('ldap', 'servers', 'ldaps://dir.test');
      ctx.setConfig('ldap', 'base_dn', 'DC=test');
      ctx.setConfig('ldap', 'bind_dn', 'CN=svc,DC=test');
      const BINDPW = `Bind-${randomBytes(4).toString('hex')}`;
      addSecret(BINDPW);
      const bp = await admin.req('PUT', '/api/v1/admin/auth/ldap/bind-password', { json: { password: BINDPW } });
      rec.check(bp.status === 200 && !bp.body.includes(BINDPW), 'LDAP 검색 계정 비밀번호 저장(값은 다시 내리지 않음)', `${bp.status} ${bp.body.slice(0, 80)}`, 'PUT /api/v1/admin/auth/ldap/bind-password');
      const lee = new Client(ctx.base, 'lee');
      const l1 = await lee.login('lee', DIRPW);
      rec.check(l1.status === 403 && l1.json?.error?.code === 'account_pending', 'LDAP 첫 로그인 → 승인 대기(세션 없음)', `${l1.status} ${l1.json?.error?.code}`, 'POST /api/v1/auth/login');
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/admin/users?approval=pending', { lang: 'ko', theme: 'light', shot: 'S10_pending_users' });
      const ap = await approve('lee');
      const l2 = await lee.login('lee', DIRPW);
      const me = await lee.get('/api/v1/auth/me');
      rec.check(ap.status === 200 && l2.status === 200 && me.json?.data?.user?.auth_type === 'ldap', '관리자 승인 → LDAP 로그인 200(auth_type ldap)', `${ap.status}/${l2.status} ${me.json?.data?.user?.auth_type}`, 'POST /api/v1/users/{id}/approve');
      // OIDC
      const odir = path.join(fdir, 'oidc');
      ctx.php([tool(ctx, 'fake_idp.php'), 'oidc-init', odir], { cwd: ctx.ROOT });
      const SECRET = `cs-${randomBytes(8).toString('hex')}`;
      addSecret(SECRET);
      writeFileSync(path.join(odir, 'secret'), SECRET);
      ctx.setConfig('oidc', 'enabled', 'true');
      ctx.setConfig('oidc', 'issuer', 'https://idp.example.test');
      ctx.setConfig('oidc', 'client_id', 'pjg-client');
      const cs = await admin.req('PUT', '/api/v1/admin/auth/oidc/client-secret', { json: { secret: SECRET } });
      const oidcLogin = async (c) => {
        const st = await c.get('/auth/oidc/start', { headers: { Accept: 'text/html' } });
        const loc = new URL(st.headers.get('location') ?? 'http://x/');
        if (!loc.href.startsWith('https://idp.example.test/authorize')) return { start: st.status, loc: loc.href };
        const q = loc.searchParams;
        const code = `code-${randomBytes(12).toString('hex')}`;
        const codes = JSON.parse(readFileSync(path.join(odir, 'codes.json'), 'utf8'));
        codes[code] = { claims: { sub: 'sub-kim', preferred_username: 'okim', name: 'Kim OIDC', email: 'okim@corp.test' }, nonce: q.get('nonce'), challenge: q.get('code_challenge'), redirect_uri: q.get('redirect_uri'), opt: {} };
        writeFileSync(path.join(odir, 'codes.json'), JSON.stringify(codes));
        const back = new URL(q.get('redirect_uri'));
        const cb = await c.get(`${back.pathname}?code=${code}&state=${encodeURIComponent(q.get('state'))}`, { headers: { Accept: 'text/html' } });
        return { start: st.status, method: q.get('code_challenge_method'), callback: cb.status, loc: cb.headers.get('location') };
      };
      const oc = new Client(ctx.base, 'okim');
      const o1 = await oidcLogin(oc);
      rec.check(cs.status === 200 && o1.method === 'S256' && /account_pending/.test(o1.loc ?? ''), 'OIDC(PKCE S256) 첫 로그인 → 승인 대기', JSON.stringify(o1), '/auth/oidc/start → 가짜 IdP → /auth/oidc/callback');
      const ap2 = await approve('okim');
      const oc2 = new Client(ctx.base, 'okim');
      const o2 = await oidcLogin(oc2);
      const me2 = await oc2.get('/api/v1/auth/me');
      rec.check(ap2.status === 200 && me2.json?.data?.user?.auth_type === 'oidc', '승인 → OIDC 로그인(auth_type oidc)', `${JSON.stringify(o2)} ${me2.status} ${me2.json?.data?.user?.auth_type}`, '');
      // SAML
      const sdir = path.join(fdir, 'saml');
      const idp = JSON.parse(ctx.php([tool(ctx, 'fake_idp.php'), 'saml-init', sdir], { cwd: ctx.ROOT }).out.trim().split('\n').pop());
      for (const [k, v] of [['enabled', 'true'], ['idp_entity_id', idp.entity], ['idp_sso_url', idp.sso], ['idp_cert', idp.cert], ['attr_login', 'uid'], ['attr_name', 'displayName'], ['attr_email', 'mail']]) ctx.setConfig('saml', k, v);
      const samlLogin = async (c) => {
        const st = await c.get('/auth/saml/start', { headers: { Accept: 'text/html' } });
        const loc = st.headers.get('location') ?? '';
        if (!loc.startsWith(idp.sso)) return { start: st.status, loc };
        const signed = JSON.parse(ctx.php([path.join(ctx.ROOT, 'tests/Support/fake_saml_sign.php'), sdir, loc, JSON.stringify({ name_id: 'nid-park', attributes: { uid: ['spark'], displayName: ['Park SAML'], mail: ['spark@corp.test'] } })], { cwd: ctx.ROOT }).out.trim().split('\n').pop());
        const form = new URLSearchParams({ SAMLResponse: signed.response, RelayState: signed.relay }).toString();
        const acs = await c.req('POST', new URL(signed.acs).pathname, { form, headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'text/html' }, csrf: false });
        const fin = acs.headers.get('location') ?? '';
        const f = fin.startsWith('/auth/saml/finish') ? await c.get(fin, { headers: { Accept: 'text/html' } }) : null;
        return { start: st.status, acs: acs.status, finish: f?.status, loc: f?.headers.get('location') ?? fin };
      };
      const s1 = await samlLogin(new Client(ctx.base, 'spark'));
      rec.check(s1.acs === 303 && /account_pending/.test(s1.loc ?? ''), 'SAML(서명된 응답, ACS 303 → 마무리) 첫 로그인 → 승인 대기', JSON.stringify(s1), '/auth/saml/start → 가짜 IdP(fake_saml_sign) → /auth/saml/acs → /auth/saml/finish');
      const ap3 = await approve('spark');
      const sc = new Client(ctx.base, 'spark');
      const s2 = await samlLogin(sc);
      const me3 = await sc.get('/api/v1/auth/me');
      rec.check(ap3.status === 200 && me3.json?.data?.user?.auth_type === 'saml', '승인 → SAML 로그인(auth_type saml)', `${JSON.stringify(s2)} ${me3.status} ${me3.json?.data?.user?.auth_type}`, '');
      // 로그인 화면: SSO 단추 둘(영어·라이트), 로컬 계정은 외부 인증으로 로그인되지 않음
      const anon = await (await import('./lib.mjs')).userPage(ctx.browser, ctx.base);
      await anon.setCookie({ name: 'pjg_lang', value: 'en', url: ctx.base }, { name: 'pjg_theme', value: 'light', url: ctx.base });
      await screen(anon, rec, '/login', { lang: 'en', theme: 'light', shot: 'S10_login_sso_en' });
      const btns = await anon.$$eval('a[href*="/auth/oidc/start"], a[href*="/auth/saml/start"]', (a) => a.length);
      rec.check(btns === 2, '로그인 화면 SSO 단추(OIDC·SAML)', btns, 'S10_login_sso_en.png');
      await anon.ctx.close();
      const localAsLdap = await new Client(ctx.base, 'dev1x').login('dev1', DIRPW);
      rec.check(localAsLdap.status === 401, '로컬 계정(dev1)은 디렉터리 비밀번호로 로그인되지 않음', localAsLdap.status, '');
      S.ext = { lee: true, okim: true, spark: true };
    },
  },
  {
    n: 11, title: '설정·모니터링 — 설정 관리 변경(확인·감사), 모니터링 카드, /health 허용 IP, 관리자 배너, 관리자 이메일(가짜 수신기)',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await ctx.user('admin');
      const before = await admin.data('GET', '/api/v1/admin/config-settings');
      rec.note(`설정 관리 항목 ${JSON.stringify(before).match(/"key":/g)?.length ?? '?'}개, version ${String(before.version).slice(0, 12)}`);
      const stale = await admin.req('PUT', '/api/v1/admin/config-settings', { json: { changes: { 'disk.warn_percent': '79' }, version: 'stale-version' } });
      rec.check(stale.status === 409, '낡은 version으로 저장 → 409(동시 변경 충돌)', `${stale.status} ${stale.json?.error?.code}`, 'PUT /api/v1/admin/config-settings');
      const h0 = await fetch(`${ctx.base}/health`);
      const sv = await saveSettings(admin, { 'health.allowed_ips': '127.0.0.1', 'disk.warn_percent': '79' });
      rec.check(sv.status === 200, '설정 관리 저장(헬스체크 허용 IP 127.0.0.1, 디스크 경고 79%)', `${sv.status} ${JSON.stringify(sv.json?.data).slice(0, 160)}`, 'PUT /api/v1/admin/config-settings');
      const h1 = await fetch(`${ctx.base}/health`);
      const h1b = await h1.json().catch(() => null);
      rec.check(h0.status === 404 && h1.status === 200, '/health: 허용 IP 비었을 때 404 → 허용하면 200', `${h0.status} → ${h1.status} ${JSON.stringify(h1b).slice(0, 120)}`, 'GET /health');
      const mon = await admin.data('GET', '/api/v1/admin/monitoring');
      rec.check(Object.keys(mon.cards ?? {}).length >= 9 && mon.status !== 'fail', `모니터링 카드 ${Object.keys(mon.cards ?? {}).length}개, 상태 ${mon.status}`, Object.entries(mon.cards).map(([k, v]) => `${k}:${v.state ?? (v.available ? 'ok' : '-')}`).join(' '), 'GET /api/v1/admin/monitoring');
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/admin/monitoring', { lang: 'ko', theme: 'light', shot: 'S11_monitoring' });
      // 워커 정지 → 오류 판정·배너·/health 503 → 다시 시작하면 정상
      await ctx.stopWorker();
      const down = await ctx.until(async () => { const m = await admin.data('GET', '/api/v1/admin/monitoring'); return m.alerts?.some((a) => a.code === 'worker_down') ? m : null; }, 30000, 1000);
      const h2 = await fetch(`${ctx.base}/health`);
      await pa.goto(`${ctx.base}/dashboard`, { waitUntil: 'networkidle0' });
      const banner = await pa.$('.health-banner, [data-health-banner]');
      if (banner) await pa.screenshot({ path: path.join(screen.dir, 'S11_worker_down_banner.png') }), rec.shot('S11_worker_down_banner.png');
      rec.check(!!down && h2.status === 503 && !!banner, '워커 정지 → 모니터링 worker_down(오류), /health 503, 관리자 화면 배너', `status=${down?.status} /health=${h2.status} 배너=${!!banner}`, 'S11_worker_down_banner.png');
      await ctx.startWorker();
      const up = await ctx.until(async () => (await fetch(`${ctx.base}/health`)).status === 200, 60000, 1000);
      rec.check(!!up, '워커 다시 시작 → /health 200', up ? 200 : '시간 초과', 'GET /health');
      // 관리자 이메일 → 가짜 SMTP 수신기(평문, 127.0.0.1)
      const sport = await ctx.freePort(2525);
      const out = path.join(ctx.dir, 'smtp.jsonl');
      await ctx.spawnBg('smtp', [path.join(ctx.ROOT, 'tests/Support/fake_smtp_server.php'), `--port=${sport}`, '--mode=plain', `--out=${out}`], { port: sport });
      const ms = await saveSettings(admin, { 'alert_mail.enabled': 'true', 'alert_mail.smtp_host': '127.0.0.1', 'alert_mail.smtp_port': String(sport), 'alert_mail.smtp_tls': 'none', 'alert_mail.smtp_auth': 'none', 'alert_mail.from_address': 'pjg@example.test', 'alert_mail.recipients': 'ops@example.test', 'alert_mail.resend_interval': '1h' });
      rec.check(ms.status === 200, '관리자 이메일 설정(가짜 수신기 127.0.0.1, 평문 — 화면은 평문 경고)', `${ms.status} ${ms.json?.error?.code ?? ''}`, 'PUT /api/v1/admin/config-settings');
      const tm = await admin.req('POST', '/api/v1/admin/alert-mail/test', { json: { to: 'ops@example.test' } }); // admin 계정에 이메일이 없으면 받는 사람을 준다(비우면 400 alert_mail_invalid_recipient)
      const got = await ctx.until(() => existsSync(out) && readFileSync(out, 'utf8').trim().split('\n').filter(Boolean).length >= 1, 20000, 500);
      const first = got ? JSON.parse(readFileSync(out, 'utf8').trim().split('\n')[0]) : null;
      rec.check(tm.json?.data?.ok === true && !!first && /^\[PJGuardian\]/.test(subjectOf(first)), '시험 메일 → 가짜 수신기가 받음([PJGuardian] 제목)', `${tm.status} ${tm.body.slice(0, 200)} rcpt=${first?.rcpt} 제목 "${subjectOf(first)}"`, 'POST /api/v1/admin/alert-mail/test');
      // 실제 경고: 디스크 경고 기준을 지금 사용률보다 낮춤 → 워커 판정 회차에 경고 메일
      const used = mon.cards.disk.used_percent;
      await saveSettings(admin, { 'disk.warn_percent': String(Math.max(1, Math.floor(used) - 5)) });
      const alertMail = await ctx.until(() => {
        if (!existsSync(out)) return null;
        const lines = readFileSync(out, 'utf8').trim().split('\n').filter(Boolean).map((x) => JSON.parse(x));
        return lines.find((m) => !/시험|test/i.test(subjectOf(m)) && /디스크|disk/i.test(subjectOf(m) + bodyOf(m))) ?? null;
      }, 150000, 2000);
      rec.check(!!alertMail, `디스크 경고(기준 ${Math.floor(used) - 5}% < 사용률 ${used}%) → 워커 판정에서 경고 메일 1통`, alertMail ? subjectOf(alertMail) : '안 옴', 'smtp.jsonl');
      await saveSettings(admin, { 'disk.warn_percent': '80', 'alert_mail.enabled': 'false' });
      S.smtpOut = out;
    },
  },
  {
    n: 12, title: '백업·복원 — 백업 생성·검증·다운로드·키 내보내기, 다른 새 폴더에 복원(설치 가이드 10절), 복원 후 로그인·데이터 일치·세션 무효화, 리허설',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await ctx.user('admin');
      const job = await admin.req('POST', '/api/v1/admin/backups', { json: { snapshots: true } });
      const t0 = Date.now();
      const list = await ctx.until(async () => { const l = await admin.data('GET', '/api/v1/admin/backups'); const b = (l.bundles ?? l.items ?? l.list ?? []).find((x) => x.kind === 'manual' && Date.parse(x.created_at ?? x.at ?? 0) >= t0 - 60000); return b ? { l, b } : null; }, 180000, 1500);
      const b = list?.b;
      rec.check(job.status === 202 && !!b, '지금 백업(스냅샷 포함) → 워커가 묶음 생성', `${job.status} ${b?.name} ${b?.size ?? ''}`, 'POST /api/v1/admin/backups');
      if (!b) throw new Error(`백업 목록 형식: ${JSON.stringify(list?.l ?? (await admin.data('GET', '/api/v1/admin/backups'))).slice(0, 300)}`);
      const v = await admin.data('POST', `/api/v1/admin/backups/${b.name}/verify`);
      rec.check(v.ok === true, '묶음 다시 검증 ok', JSON.stringify(v).slice(0, 160), 'POST /api/v1/admin/backups/{name}/verify');
      const tk = await admin.data('POST', `/api/v1/admin/backups/${b.name}/download`, { password: ADMIN_PW });
      const dl = await admin.get(tk.url, { headers: { Accept: '*/*' } });
      const again = await admin.get(tk.url, { headers: { Accept: '*/*' } });
      const tdir = path.join(ctx.dir, 'transfer');
      mkdirSync(tdir, { recursive: true });
      const bundlePath = path.join(tdir, b.name);
      writeFileSync(bundlePath, dl.buf);
      rec.check(dl.status === 200 && dl.buf.length > 10000 && again.status !== 200, '내려받기(비밀번호 재입력 → 한 번 쓰는 주소, 두 번째는 거부)', `${dl.status} ${dl.buf.length}바이트, 재사용 ${again.status}`, 'POST …/download → GET /api/v1/admin/backups/download/{token}');
      const PASS = `Key-${randomBytes(6).toString('hex')}-phrase`;
      addSecret(PASS);
      const ke = await admin.data('POST', '/api/v1/admin/backups/key-export', { password: ADMIN_PW, passphrase: PASS, passphrase2: PASS });
      const keyPath = path.join(tdir, ke.file_name);
      writeFileSync(keyPath, ke.content);
      const keAudit = (await admin.get('/api/v1/admin/audit-logs?action=key.export&size=1')).json;
      rec.check(/\.pjgkey$/.test(ke.file_name) && ke.content.length > 50 && keAudit?.page?.total >= 1, '키 내보내기(.pjgkey, 암호문구로 암호화) + 감사 key.export', `${ke.file_name}, 감사 "${String(keAudit?.data?.[0]?.description ?? '').slice(0, 50)}"`, 'POST /api/v1/admin/backups/key-export');
      const reh = await admin.req('POST', '/api/v1/admin/backups/rehearse', { json: { name: b.name } });
      const rehAt = new Date(Date.now() - 2000).toISOString();
      const rehDone = await ctx.until(async () => { const l = await admin.data('GET', '/api/v1/admin/backups'); const r = l.last_rehearsal ?? l.summary?.last_rehearsal ?? null; return r && r.at >= rehAt.replace(/\.\d+Z$/, 'Z') ? r : null; }, 180000, 1500);
      rec.check(reh.status === 202 && rehDone?.ok === true, '복원 리허설(운영 데이터 변경 없이 임시로 복원·점검)', `${reh.status} ${JSON.stringify(rehDone).slice(0, 200)}`, 'POST /api/v1/admin/backups/rehearse');
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/admin/backups', { lang: 'ko', theme: 'light', shot: 'S12_backups' });
      // 다른 새 폴더에 복원(설치 가이드 10.3·10.4): 묶음 설치(7절 생략) → --dry-run → --check-bin → --key 복원 → 워커·로그인
      const rport = await ctx.freePort(ctx.port + 1);
      const rdir = path.join(path.dirname(ctx.dir), `${path.basename(ctx.dir)}_restore`);
      S.restoreDir = rdir;
      const inst = JSON.parse(ctx.php([tool(ctx, 'install.php'), `--zip=${ctx.zip}`, `--dir=${rdir}`, `--port=${rport}`, '--bin=ruff,eslint,git', '--restore-only'], { cwd: ctx.ROOT }).out.trim().split('\n').pop());
      const rapp = inst.app;
      S.restoreApp = rapp;
      rec.check(inst.steps.every((s) => s.code === 0 || s.step === 'tools_verify'), '두 번째 새 폴더에 묶음 설치(설정·키 생성·bin 배치·check_env, DB 만들기는 건너뜀)', inst.steps.map((s) => `${s.step}:${s.code}`).join(' '), 'install.php --restore-only');
      const dry = ctx.php([path.join(rapp, 'tools/restore.php'), `--file=${bundlePath}`, '--dry-run'], { cwd: rapp });
      rec.check(dry.code === 0, '사전 검증 --dry-run(바꾸지 않음)', dry.out.trim().split('\n').slice(-3).join(' / ').slice(0, 220), 'restore.php --dry-run');
      const cb = ctx.php([path.join(rapp, 'tools/restore.php'), `--file=${bundlePath}`, '--check-bin'], { cwd: rapp });
      rec.check(cb.code === 0, '분석 도구 비교 --check-bin(같은 버전·체크섬)', cb.out.trim().split('\n').slice(-3).join(' / ').slice(0, 220), 'restore.php --check-bin');
      const rs = ctx.php([path.join(rapp, 'tools/restore.php'), `--file=${bundlePath}`, `--key=${keyPath}`, '--yes'], { cwd: rapp, env: { PJG_KEY_PASSPHRASE: PASS } });
      rec.check(rs.code === 0, '복원 실행(--key + PJG_KEY_PASSPHRASE, --yes)', rs.out.trim().split('\n').slice(-4).join(' / ').slice(0, 260), 'restore.php');
      const ms = ctx.php([path.join(rapp, 'tools/migrate.php'), '--status'], { cwd: rapp });
      rec.check(/031\s+alert_mail_state\s+적용됨/.test(ms.out), '복원 뒤 migrate --status 모두 적용됨', ms.out.trim().split('\n').pop(), 'migrate.php --status');
      ctx.setConfig('repository', 'allow_local_dev', 'true', rapp); // 시험 저장소(127.0.0.1) 허용 — 원래 설치본과 같은 시험 설정(설정 파일은 복원하지 않음)
      await ctx.startServer(rapp, rport, 'server_restore');
      await ctx.startWorker(rapp, 'worker_restore');
      const rbase = `http://127.0.0.1:${rport}`;
      const rdev = new Client(rbase, 'dev1');
      const rl = await rdev.login('dev1', PW);
      const srcDev = await ctx.user('dev1');
      const cmp = {};
      for (const [k, p] of [['projects', '/api/v1/projects'], ['mine', '/api/v1/my-defects']]) {
        const a = await srcDev.get(p);
        const r = await rdev.get(p);
        cmp[k] = [a.json?.page?.total ?? a.json?.data?.length, r.json?.page?.total ?? r.json?.data?.length];
      }
      const dA = (await srcDev.get(`/api/v1/projects/${S.p.def}/defects?size=1`)).json?.page?.total;
      const dR = (await rdev.get(`/api/v1/projects/${S.p.def}/defects?size=1`)).json?.page?.total;
      cmp.defects = [dA, dR];
      rec.check(rl.status === 200 && Object.values(cmp).every(([a, r]) => a === r), '새 폴더에서 옛 비밀번호로 로그인 200, 프로젝트·결함·내 결함 건수 일치', JSON.stringify(cmp), `복원본 ${rbase}`);
      const rAdmin = new Client(rbase, 'admin');
      await rAdmin.login('admin', ADMIN_PW);
      const repoTest = await rAdmin.req('POST', `/api/v1/projects/${S.p.repo}/repo/test`);
      rec.check(repoTest.status === 200 && repoTest.json?.data?.ok === true, '키를 함께 복원 → 저장소 자격 증명이 새 폴더에서 풀림(연결 테스트 성공)', JSON.stringify(repoTest.json?.data).slice(0, 160), 'POST /api/v1/projects/{id}/repo/test(복원본)');
      // 같은 설치에서 화면 복원 → 모든 세션 무효화(원래 설치)
      const dev = await ctx.user('dev1');
      const st = await admin.req('POST', '/api/v1/admin/restore', { json: { name: b.name, password: ADMIN_PW, confirm: '복원' } });
      const back = await ctx.until(async () => { const r = await fetch(`${ctx.base}/api/v1/restore-status`).then((x) => x.json()).catch(() => null); const s = r?.data?.status ?? r?.status; return ['succeeded', 'failed', 'rolled_back', 'rollback_failed'].includes(s) ? { status: s, steps: (r.data ?? r).steps?.map((x) => x.name).join('>') } : null; }, 240000, 2000);
      const after = await dev.get('/api/v1/auth/me');
      rec.check(st.status === 202 && back?.status === 'succeeded' && after.status === 401, '화면 복원(비밀번호·확인 문구 "복원") → 진행 상태 → 끝나면 기존 로그인 세션 모두 무효(401)', `${st.status} ${JSON.stringify(back).slice(0, 120)} me=${after.status}`, 'POST /api/v1/admin/restore, GET /api/v1/restore-status');
      for (const c of Object.values(ctx.clients)) c.csrf = '';
      for (const k of Object.keys(ctx.pages)) { await ctx.pages[k].ctx.close().catch(() => null); delete ctx.pages[k]; }
      await ctx.stopWorker(rapp, 'worker_restore');
      await ctx.stopServer('server_restore');
    },
  },
  {
    n: 13, title: '감사 로그 — 앞 시나리오의 관리 작업이 사람이 읽는 설명으로, 조건·CSV·무결성 검사(DB 밖 기록 포함)',
    async fn(ctx) {
      const { rec } = ctx;
      const admin = await ctx.user('admin');
      const want = ['user.create', 'group.create', 'project.create', 'rules.override_set', 'rules.override_bulk', 'config.update', 'backup.create', 'restore.finish', 'user.approve'];
      rec.note('화면 복원(시나리오 12 끝)은 DB를 백업 시점으로 바꾸므로 그 뒤에 남긴 감사 행(key.export·restore.start)은 DB에서 사라진다 — 복원 뒤 restore.finish가 새로 남고, 무결성 검사는 DB 밖 기록(audit-anchor.jsonl)과 대조해 통과(아래)');
      const seen = {};
      for (const a of want) {
        const r = await admin.get(`/api/v1/admin/audit-logs?action=${encodeURIComponent(a)}&size=1`);
        const row = r.json?.data?.[0];
        seen[a] = r.status === 200 ? `${r.json.page.total}건${row ? ` "${String(row.description ?? row.summary ?? row.text ?? '').slice(0, 40)}"` : ''}` : `HTTP ${r.status} ${r.json?.error?.code}`;
      }
      const missing = Object.entries(seen).filter(([, s]) => !/^[1-9]\d*건 "[^"]+/.test(s));
      rec.check(missing.length === 0, '관리 작업이 감사 로그에 남고 설명 문장이 있음(사용자·그룹·프로젝트·규칙·설정·백업·키·복원·승인)', missing.length ? `없음: ${missing.map(([k, v]) => `${k}:${v}`).join('; ')}` : Object.entries(seen).map(([k, v]) => `${k} ${v.split(' ')[0]}`).join(', '), 'GET /api/v1/admin/audit-logs?action=');
      const login = await admin.get('/api/v1/admin/audit-logs?action=auth.login&size=1');
      rec.note(`로그인 성공 감사 행 조회: HTTP ${login.status} ${login.json?.page?.total ?? login.json?.error?.code}(명세 변경 — 로그인 성공·실패는 감사 로그 제외, 앱 로그에)`);
      const csv = await admin.req('POST', '/api/v1/admin/audit-logs/export', { json: { action: 'user.' } });
      const rows = csv.buf.toString('utf8').replace(/^﻿/, '').trim().split(/\r?\n/).length - 1;
      rec.check(csv.status === 200 && rows >= 1, '조건(작업 user.) CSV 내보내기', `${csv.status} ${rows}행`, 'POST /api/v1/admin/audit-logs/export');
      const integ = await admin.data('GET', '/api/v1/admin/audit-logs/integrity');
      rec.check(integ.ok === true, '무결성 검사(해시 체인 + DB 밖 기록 대조) 통과', JSON.stringify(integ).slice(0, 220), 'GET /api/v1/admin/audit-logs/integrity');
      const cli = ctx.php([path.join(ctx.app, 'tools/audit_verify.php')]);
      rec.check(cli.code === 0, 'tools/audit_verify.php 종료 코드 0', cli.out.trim().split('\n').pop(), 'audit_verify.php');
      const pa = await ctx.page('admin');
      await setPrefs(admin, 'en', 'dark');
      await screen(pa, rec, '/admin/audit-logs', { lang: 'en', theme: 'dark', shot: 'S13_audit_en_dark' });
      await setPrefs(admin, 'ko', 'light');
      await screen(pa, rec, '/admin/audit-logs?action=rules.', { lang: 'ko', theme: 'light', shot: 'S13_audit_rules_ko' });
    },
  },
  {
    n: 14, title: '가이드·FAQ — 가이드 탭·검색·링크, FAQ 시드 가져오기(faq_import), 한/영 전환',
    async fn(ctx) {
      const { rec } = ctx;
      const dev = await ctx.user('dev1');
      const admin = await ctx.user('admin');
      const imp = ctx.php([path.join(ctx.app, 'tools/faq_import.php')]);
      const imp2 = ctx.php([path.join(ctx.app, 'tools/faq_import.php')]);
      rec.check(imp.code === 0 && imp2.code === 0, 'faq_import(설치본의 시드) → 가져옴, 다시 실행하면 건너뜀(멱등)', `${imp.out.trim().split('\n').pop()} / ${imp2.out.trim().split('\n').pop()}`, 'tools/faq_import.php');
      const faq = await dev.get('/api/v1/faq?size=200');
      rec.check(faq.status === 200 && (faq.json?.data?.total ?? 0) >= 35, '일반 사용자 FAQ 목록 35개 이상(공개)', faq.json?.data?.total, 'GET /api/v1/faq');
      const g = await dev.data('GET', '/api/v1/guide/user');
      const s = await dev.get(`/api/v1/guide/user/search?q=${encodeURIComponent('업로드')}`);
      rec.check(JSON.stringify(g).length > 1000 && s.status === 200 && (s.json?.data?.results?.length ?? 0) > 0, '사용자 가이드 목차·검색("업로드")', `검색 ${s.json?.data?.results?.length}건`, 'GET /api/v1/guide/user(/search)');
      const ga = await dev.get('/api/v1/guide/admin');
      rec.check(ga.status === 403, '관리자 가이드는 일반 사용자에게 403', ga.status, 'GET /api/v1/guide/admin');
      const pd = await ctx.page('dev1');
      await screen(pd, rec, '/guide/user#upload', { lang: 'ko', theme: 'light', shot: 'S14_guide_ko' });
      const links = await pd.$$eval('[data-guide-body] a[href]', (as) => as.map((a) => a.getAttribute('href')).filter((h) => h.startsWith('/') || h.startsWith('#')).slice(0, 30));
      const broken = [];
      for (const h of links.filter((x) => x.startsWith('/'))) { const r = await dev.get(h.split('#')[0], { headers: { Accept: 'text/html' } }); if (r.status >= 400) broken.push(`${h}:${r.status}`); }
      rec.check(broken.length === 0, `가이드 본문 링크(앱 화면 ${links.filter((x) => x.startsWith('/')).length}개) 열림`, broken.join(',') || '모두 200', '/guide/user');
      await setPrefs(dev, 'en', 'dark');
      await screen(pd, rec, '/guide/faq', { lang: 'en', theme: 'dark', shot: 'S14_faq_en_dark', allowKo: true, note: '(FAQ 본문·분류는 관리자가 쓴 내용 — 시드는 한국어뿐이라 영어 화면에도 한국어, 화면 틀만 검사)' });
      await screen(pd, rec, '/guide/user', { lang: 'en', theme: 'dark' });
      await setPrefs(dev, 'ko', 'light');
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/guide/admin', { lang: 'ko', theme: 'light' });
      void admin;
    },
  },
  {
    n: 15, title: '보안 점검 — 권한 행렬·CSRF·보안 헤더·초기 비밀번호 경고 배너·점검 탭 모든 항목',
    async fn(ctx) {
      const { rec } = ctx;
      const dev = await ctx.user('dev1');
      const admin = await ctx.user('admin');
      const page = await dev.get('/dashboard', { headers: { Accept: 'text/html' } });
      const h = Object.fromEntries([...page.headers.entries()].map(([k, v]) => [k.toLowerCase(), v]));
      const csp = h['content-security-policy'] ?? '';
      const okHeaders = /script-src 'self'/.test(csp) && !/unsafe-inline/.test(csp.match(/script-src[^;]*/)?.[0] ?? '') && h['x-content-type-options'] === 'nosniff'
        && /frame-ancestors 'none'|DENY/i.test(`${csp} ${h['x-frame-options'] ?? ''}`) && !!h['referrer-policy'] && !!h['permissions-policy'] && /private|no-store/.test(h['cache-control'] ?? '');
      rec.check(okHeaders, '보안 헤더: CSP script-src \'self\'(인라인 없음)·nosniff·프레임 금지·Referrer-Policy·Permissions-Policy·화면 Cache-Control private', `CSP=${csp.slice(0, 90)}… XCTO=${h['x-content-type-options']} RP=${h['referrer-policy']} PP=${(h['permissions-policy'] ?? '').slice(0, 30)} CC=${h['cache-control']}`, 'GET /dashboard 응답 헤더');
      rec.note(`HSTS: http 시험 서버라 보내지 않음(${h['strict-transport-security'] ?? '없음'}) — https 운영에서 확인(미확인 목록)`);
      const noCsrf = await dev.req('POST', '/api/v1/notifications/read-all', { csrf: false });
      const anon = await new Client(ctx.base, 'anon').get('/api/v1/projects');
      const trav = await dev.get('/assets/../../config/config.ini', { headers: { Accept: '*/*' } });
      const cfg = await fetch(`${ctx.base}/../config/config.ini`).then((r) => r.status);
      rec.check(noCsrf.status === 403 && noCsrf.json?.error?.code === 'csrf_invalid' && anon.status === 401 && trav.status === 404 && !trav.body.includes('encryption_key') && cfg === 404,
        'CSRF 토큰 없는 POST 403, 비로그인 API 401, 경로 순회로 설정 파일 접근 404', `${noCsrf.status}/${noCsrf.json?.error?.code}, ${anon.status}, ${trav.status}, ${cfg}`, '');
      // 권한 행렬 시험(제품 시험 — 묶음과 같은 코드)
      const t = ctx.php(['tests/run.php', 'RouteAuthMatrix'], { cwd: ctx.ROOT });
      rec.check(t.code === 0 && /실패 0/.test(t.out), '권한 행렬 시험(RouteAuthMatrixTest — 모든 라우트 × 역할 5개) 통과', t.out.trim().split('\n').pop(), 'php tests/run.php RouteAuthMatrix(저장소 — 묶음과 같은 코드)');
      // 초기 비밀번호 경고 배너는 시나리오 1(설치 직후 admin/admin — S01 캡처)에서 확인. 여기서는 바꾼 뒤 없음 + 최초 관리자 도구가 두 번째를 만들지 않음
      const ca = ctx.php([path.join(ctx.app, 'tools/create_admin.php'), '--login=admin2', '--name=Second', '--skip-http']);
      const adminDash = await admin.get('/admin/users', { headers: { Accept: 'text/html' } });
      rec.check(ca.code !== 0 && /이미 활성 관리자/.test(ca.out) && !adminDash.body.includes('data-initial-password-banner'), '비밀번호를 바꾼 뒤 경고 배너 없음, create_admin은 활성 관리자가 있으면 만들지 않음(추가 관리자는 화면에서)', `create_admin 종료 ${ca.code}: ${ca.out.trim().split('\n')[0]}`, '/admin/users, tools/create_admin.php');
      const checks = await admin.data('GET', '/api/v1/admin/checks');
      const items = [...(checks.environment?.items ?? []), ...(checks.security?.items ?? []), ...(checks.hardening?.items ?? [])];
      const byStatus = items.reduce((m, i) => { m[i.status] = (m[i.status] ?? 0) + 1; return m; }, {});
      const fails = items.filter((i) => i.status === 'fail').map((i) => i.id);
      rec.check(fails.length === 0, `점검 탭 모든 항목(환경·보안) — FAIL 0`, `${JSON.stringify(byStatus)} 실패: ${items.filter((i) => i.status === 'fail').map((i) => `${i.id}(${i.note ?? i.message ?? JSON.stringify(i.params ?? {})})`).join(',')} 경고: ${items.filter((i) => i.status === 'warn').map((i) => `${i.id}(${i.note ?? JSON.stringify(i.params ?? {}).slice(0, 60)})`).join(',')}`, 'GET /api/v1/admin/checks');
      const pa = await ctx.page('admin');
      await setPrefs(admin, 'en', 'dark');
      await screen(pa, rec, '/admin/checks', { lang: 'en', theme: 'dark', shot: 'S15_checks_en_dark' });
      await setPrefs(admin, 'ko', 'light');
      const hc = ctx.php([path.join(ctx.app, 'tools/create_admin.php'), '--check-only', '--skip-http']);
      rec.note(`하드닝 점검(create_admin --check-only): ${hc.out.trim().split('\n').filter((l) => /충족|WARN|FAIL/.test(l)).slice(-3).join(' / ')}`);
    },
  },
  {
    n: 16, title: '정리·보존 — 정리 작업(임시 파일·만료 데이터·알림·감사 90일), 디스크 임계치',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await ctx.user('admin');
      const dev = await ctx.user('dev1');
      // 만료 데이터 만들기(이 설치본의 새 DB에서 시각을 과거로): 미완료 업로드 2일 전, 읽은 알림 40일 전
      const stale = await upload(dev, S.p.py, Buffer.alloc(9 * 1024 * 1024, 1), 'stale.zip', { stopAfter: 0 });
      const old = new Date(Date.now() - 2 * 86400e3).toISOString().replace(/\.\d+Z$/, 'Z');
      ctx.sqlWrite('UPDATE uploads SET last_activity_at = ?, created_at = ? WHERE id = ?', old, old, stale);
      const old40 = new Date(Date.now() - 40 * 86400e3).toISOString().replace(/\.\d+Z$/, 'Z');
      const nBefore = ctx.sqlOne('SELECT COUNT(*) AS n FROM notifications').n;
      ctx.sqlWrite('UPDATE notifications SET created_at = ?, read_at = COALESCE(read_at, ?) WHERE id IN (SELECT id FROM notifications ORDER BY id LIMIT 3)', old40, old40);
      const auditBefore = ctx.sqlOne('SELECT COUNT(*) AS n FROM audit_log').n;
      // 알림 보관 정리는 워커가 한다(시작 직후 한 번, 그 뒤 주기) — 워커를 다시 시작해 확인
      await ctx.stopWorker();
      await ctx.startWorker();
      await ctx.until(() => ctx.sqlOne('SELECT COUNT(*) AS n FROM notifications').n <= nBefore - 3, 60000, 1000);
      const dry = ctx.php([path.join(ctx.app, 'tools/cleanup.php'), '--dry-run']);
      const run = ctx.php([path.join(ctx.app, 'tools/cleanup.php')]);
      const upAfter = ctx.sqlOne('SELECT status FROM uploads WHERE id = ?', stale);
      const nAfter = ctx.sqlOne('SELECT COUNT(*) AS n FROM notifications').n;
      const auditAfter = ctx.sqlOne('SELECT COUNT(*) AS n FROM audit_log').n;
      rec.check(dry.code === 0 && run.code === 0, '정리 작업 --dry-run·실행 종료 코드 0', run.out.trim().split('\n').slice(-6).join(' / ').slice(0, 400), 'tools/cleanup.php');
      rec.check(upAfter?.status !== 'receiving', '24시간 지난 미완료 업로드 정리', `${stale.slice(0, 8)}… → ${upAfter?.status ?? '행 없음'}`, 'uploads');
      rec.check(nAfter <= nBefore - 3, '보존 기간(30일) 지난 읽은 알림 정리(워커의 보관 정리)', `${nBefore} → ${nAfter}`, 'notifications');
      rec.check(auditAfter >= auditBefore, '감사 로그: 90일 안의 행은 그대로(정리 작업이 체인을 깨지 않음)', `${auditBefore} → ${auditAfter}`, 'audit_log');
      const integ = await admin.data('GET', '/api/v1/admin/audit-logs/integrity');
      rec.check(integ.ok === true, '정리 뒤 감사 무결성 검사 통과', JSON.stringify(integ).slice(0, 160), 'GET /api/v1/admin/audit-logs/integrity');
      rec.note('감사 로그 90일 지난 행의 정리 자체는 시계를 주입하는 제품 시험(AuditLogTest·AuditRetention)이 확인 — 이 설치본에는 90일 지난 행이 없음(행 시각을 바꾸면 해시 체인이 깨지므로 만들지 않음)');
      // 디스크 임계치: 차단 기준을 지금 사용률보다 낮추면 업로드 시작 거부 → 되돌림
      const mon = await admin.data('GET', '/api/v1/admin/monitoring');
      const used = mon.cards.disk.used_percent;
      const sv = await saveSettings(admin, { 'disk.warn_percent': String(Math.max(1, Math.floor(used) - 10)), 'disk.block_percent': String(Math.max(2, Math.floor(used) - 5)) });
      const blocked = await dev.req('POST', `/api/v1/projects/${S.p.py}/uploads`, { json: { file_name: 'x.zip', total_size: 1000 } });
      const mon2 = await ctx.until(async () => { const m = await admin.data('GET', '/api/v1/admin/monitoring'); return m.cards.disk.state !== 'ok' ? m : null; }, 45000, 1000) ?? await admin.data('GET', '/api/v1/admin/monitoring'); // 판정 캐시(monitoring.cache) 지난 뒤
      rec.check(sv.status === 200 && blocked.status >= 400 && /disk/.test(blocked.json?.error?.code ?? '') && mon2.cards.disk.state !== 'ok', `디스크 차단 기준(${Math.floor(used) - 5}%) < 사용률 ${used}% → 업로드 시작 거부, 모니터링 디스크 경고/오류`, `${blocked.status} ${blocked.json?.error?.code}, disk=${mon2.cards.disk.state}`, 'POST /api/v1/projects/{id}/uploads');
      const pa = await ctx.page('admin');
      await setPrefs(admin, 'ko', 'dark');
      await screen(pa, rec, '/admin/monitoring', { lang: 'ko', theme: 'dark', shot: 'S16_disk_block_monitoring' }).catch(() => null);
      await setPrefs(admin, 'ko', 'light');
      await saveSettings(admin, { 'disk.warn_percent': '80', 'disk.block_percent': '90' });
      const ok = await dev.req('POST', `/api/v1/projects/${S.p.py}/uploads`, { json: { file_name: 'x.zip', total_size: 1000 } });
      if (ok.status === 201) await dev.req('DELETE', `/api/v1/uploads/${ok.json.data.upload_id}`);
      rec.check(ok.status === 201, '기준을 되돌리면 업로드 시작 다시 가능', ok.status, '');
    },
  },
  {
    n: 17, title: 'P32B 스모크 — 유지보수 모드(사전 공지·켜기 = 직전 백업·쓰기 차단·분석 대기·끄기), 신뢰 프록시, 규칙 쉬운 설명, 용량 추정 도우미',
    async fn(ctx) {
      const { rec } = ctx;
      const admin = await ctx.user('admin');
      // 자기완결(시나리오 17만 돌려도): dev1이 없으면 만든다
      const dev = await ctx.user('dev1').catch(async () => {
        const u = await admin.data('POST', '/api/v1/users', { login_id: 'dev1', display_name: 'User dev1' });
        await admin.data('PUT', `/api/v1/users/${u.user.id}/password`, { password: PW });
        ctx.client('dev1').csrf = '';
        return ctx.user('dev1');
      });
      // 자기완결: 프로젝트 하나 + 분석 하나(규칙 쉬운 설명을 결함 상세에서 보려고)
      const pid = (await admin.data('POST', '/api/v1/projects', { name: `p32b-${randomBytes(2).toString('hex')}`, language: 'python' })).id;
      await admin.data('POST', `/api/v1/projects/${pid}/access`, { subject_type: 'user', login_id: 'dev1' });
      const zip = readFileSync(path.join(ctx.ROOT, 'samples/zips/python_sample.zip'));
      const first = await settle(ctx, dev, await upload(dev, pid, zip, 'p32b.zip'));
      // 규칙 쉬운 설명: API(한/영)·규칙 화면·결함 상세
      const g = (await dev.data('GET', '/api/v1/rules/ruff/F401')).guide;
      rec.check(g && g.checked?.tool === 'ruff' && /[가-힣]/.test(g.summary), '규칙 쉬운 설명(ruff F401) — 한국어, 실제 도구로 확인된 것', `${g?.checked?.tool} ${g?.checked?.version}: ${String(g?.summary).slice(0, 40)}`, 'GET /api/v1/rules/ruff/F401');
      const pd = await ctx.page('dev1');
      await screen(pd, rec, '/rules/ruff/F401', { lang: 'ko', theme: 'light', shot: 'S17_rule_guide', wait: '[data-rule-guide]' });
      rec.check(!!(await pd.$('[data-rule-guide] .rule-guide__code')), '규칙 화면에 쉬운 설명 카드(나쁜 예·고친 코드)', '', 'S17_rule_guide.png');
      const d401 = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401&size=1`)).json?.data?.[0]?.id;
      const dp = await dev.get(`/defects/${d401}`, { headers: { Accept: 'text/html' } });
      rec.check(dp.body.includes('data-rule-guide'), '결함 상세에 같은 쉬운 설명(접을 수 있는 구역)', `analysis ${first.analysis?.status}, defect ${d401}`, `/defects/${d401}`);
      await setPrefs(dev, 'en', 'dark');
      await screen(pd, rec, '/rules/eslint/no-undef', { lang: 'en', theme: 'dark', wait: '[data-rule-guide]' });
      await setPrefs(dev, 'ko', 'light');
      // 용량 추정 도우미(점검 탭, 읽기 전용 계산)
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/admin/checks#capacity', { lang: 'ko', theme: 'light', wait: '[data-capacity-out="dataDisk"]' });
      const cap = await pa.$eval('[data-capacity-out="dataDisk"]', (e) => e.textContent).catch(() => '');
      await pa.$eval('[data-capacity]', (e) => e.scrollIntoView());
      await pa.screenshot({ path: path.join(screen.dir, 'S17_capacity.png') });
      rec.shot('S17_capacity.png');
      rec.check(/\d/.test(cap), '용량 추정 도우미가 지금 설정으로 권장 데이터 디스크 계산', cap, 'S17_capacity.png');
      // 신뢰 프록시: 목록에 이 접속 주소(127.0.0.1)를 넣으면 X-Forwarded-For로 /health 허용 IP 판정, 빼면 헤더 무시
      const hz = (h) => fetch(`${ctx.base}/health`, { headers: h }).then((r) => r.status);
      await saveSettings(admin, { 'health.allowed_ips': '203.0.113.7', 'security.trusted_proxies': '' });
      const before = await hz({ 'X-Forwarded-For': '203.0.113.7' });
      const chk = (await admin.data('GET', '/api/v1/admin/checks', undefined)).environment.items.find((i) => i.id === 'trusted_proxies');
      const chkFwd = (await admin.get('/api/v1/admin/checks', { headers: { 'X-Forwarded-For': '203.0.113.7' } })).json.data.environment.items.find((i) => i.id === 'trusted_proxies');
      const sv = await saveSettings(admin, { 'security.trusted_proxies': '127.0.0.1' });
      const via = await hz({ 'X-Forwarded-For': '203.0.113.7' });
      const direct = await hz({});
      rec.check(before === 404 && sv.status === 200 && via !== 404 && direct === 404, '신뢰 프록시: 비면 X-Forwarded-For 무시(404), 넣으면 그 프록시를 거친 허용 IP만 응답, 프록시 자신은 404',
        `before ${before}, save ${sv.status}, via ${via}, direct ${direct}`, 'GET /health');
      rec.check(chk?.note === 'proxy_none' && chkFwd?.status === 'info' && chkFwd?.note === 'proxy_headers_untrusted', '점검 탭 "신뢰 프록시" 줄: 헤더 없이 = 설정 없음, 헤더가 왔는데 비었으면 확인 필요(정보)',
        `${chk?.status}/${chk?.note}, ${chkFwd?.status}/${chkFwd?.note}`, 'GET /api/v1/admin/checks');
      await saveSettings(admin, { 'security.trusted_proxies': '', 'health.allowed_ips': '127.0.0.1' });
      // 유지보수: 사전 공지 → 켜기(워커가 직전 백업) → 쓰기 차단·분석 대기 → 끄기
      const kst = new Date(Date.now() + 9 * 3600e3 + 86400e3);
      const notice = `${kst.toISOString().slice(0, 10)} 22:00`;
      await saveSettings(admin, { 'maintenance.notice_at': notice, 'maintenance.message': '인수 시험 공지' });
      const pageN = await dev.get('/dashboard', { headers: { Accept: 'text/html' } });
      rec.check(pageN.body.includes('data-maintenance-banner="notice"') && pageN.body.includes(notice), '사전 공지 배너(예정 시각 전, 모든 사용자)', notice, '/dashboard');
      const t0 = Date.now();
      const on = await saveSettings(admin, { 'maintenance.enabled': 'true' });
      const bundle = ctx.sqlOne("SELECT details FROM audit_log WHERE action = 'maintenance.on' ORDER BY id DESC LIMIT 1");
      rec.check(on.status === 200 && /pre_upgrade/.test(bundle?.details ?? ''), '유지보수 모드 켜기 = 워커가 직전 DB 백업(pre_upgrade) 뒤 저장, 감사 maintenance.on', `${on.status} ${((Date.now() - t0) / 1000).toFixed(1)}초 ${bundle?.details}`, 'PUT /api/v1/admin/config-settings');
      await pd.goto(`${ctx.base}/dashboard`, { waitUntil: 'networkidle0' });
      await pd.screenshot({ path: path.join(screen.dir, 'S17_maintenance_banner.png') });
      rec.shot('S17_maintenance_banner.png');
      const w = await dev.req('PUT', '/api/v1/users/me/preferences', { json: { language: 'ko' } });
      const up = await dev.req('POST', `/api/v1/projects/${pid}/uploads`, { json: { file_name: 'x.zip', total_size: 10 } });
      const read = await dev.get(`/api/v1/projects/${pid}/defects?size=1`);
      rec.check(w.status === 503 && w.json?.error?.code === 'maintenance' && up.status === 503 && read.status === 200, '켜진 동안 일반 사용자: 변경·업로드 503 maintenance(안내 문구), 조회 200',
        `${w.status}/${up.status}/${read.status} "${String(w.json?.error?.message).slice(0, 50)}"`, 'S17_maintenance_banner.png');
      const aid = await upload(admin, pid, Buffer.concat([zip]), 'during.zip');
      const queued = await ctx.until(async () => { const u = await admin.data('GET', `/api/v1/uploads/${aid}`); if (u.status === 'needs_confirmation') await admin.data('POST', `/api/v1/uploads/${aid}/confirm`); return u.analysis_id ? u : null; }, 60000, 700);
      await new Promise((res) => { setTimeout(res, 8000); });
      const stuck = queued ? (await admin.data('GET', `/api/v1/analyses/${queued.analysis_id}`)).status : null;
      rec.check(stuck === 'queued', '관리자는 업로드 가능, 워커는 새 분석을 시작하지 않음(대기열에 그대로)', `${stuck} (8초 뒤)`, `analysis ${queued?.analysis_id}`);
      const hb = await (await fetch(`${ctx.base}/health`)).json().catch(() => null);
      const off = await saveSettings(admin, { 'maintenance.enabled': 'false', 'maintenance.notice_at': '' });
      const done = queued ? await ctx.until(async () => { const a = await admin.data('GET', `/api/v1/analyses/${queued.analysis_id}`); return ['succeeded', 'failed', 'cancelled'].includes(a.status) ? a : null; }, 120000, 1000) : null;
      rec.check(hb?.maintenance === true && off.status === 200 && done?.status === 'succeeded', '/health에 maintenance: true, 끄면 대기하던 분석이 이어서 성공', `health ${hb?.maintenance}, off ${off.status}, ${done?.status}`, 'GET /health');
    },
  },
];
