// 인수 시험(P32) 시나리오 1~8: 설치, 계정·권한, 프로젝트·업로드, 분석, 결함, 대시보드·히스토리·Run, 규칙, 내보내기·알림.
import path from 'node:path';
import { readFileSync } from 'node:fs';
import { ROOT, screen, formLogin, setPrefs, addSecret, userPage } from './lib.mjs';
import { pathToFileURL } from 'node:url';

// 시험 계정 비밀번호(실행마다 새로, --reuse로 이어 할 때는 환경 변수로 같은 값을 준다 — 출력·결과에는 가림)
export const PW = process.env.PJG_ACC_PW ?? `Acc-${Math.random().toString(36).slice(2, 8)}-Pw1!`;
export const ADMIN_PW = process.env.PJG_ACC_ADMIN_PW ?? `Adm-${Math.random().toString(36).slice(2, 8)}-Pw9!`;
addSecret(PW);
addSecret(ADMIN_PW);

let fflate = null;
async function zipFiles(files) { // {경로: 문자열|Uint8Array} → zip 바이트(STORE — 브라우저 폴더 묶기와 같은 방식)
  fflate ??= await import(pathToFileURL(path.join(ROOT, 'public/assets/vendor/fflate/fflate.esm.js')).href);
  const enc = new TextEncoder();
  const input = {};
  for (const [k, v] of Object.entries(files)) input[k] = [typeof v === 'string' ? enc.encode(v) : v, { level: 0 }];
  return Buffer.from(fflate.zipSync(input));
}
export { zipFiles };

/** 폴더 → {상대 경로: 바이트} */
export function readTree(dir, base = dir, out = {}) {
  for (const e of readdirSyncSafe(dir)) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) { if (e.name !== '__pycache__' && e.name !== 'node_modules') readTree(p, base, out); } else out[path.relative(base, p).split(path.sep).join('/')] = readFileSync(p);
  }
  return out;
}
import { readdirSync } from 'node:fs';
function readdirSyncSafe(d) { try { return readdirSync(d, { withFileTypes: true }); } catch { return []; } }

/** 청크 업로드(8MB 조각, 조각마다 SHA-256) → upload_id. stopAfter면 그 조각까지만 올리고 멈춤(이어 올리기 시험) */
export async function upload(c, projectId, bytes, name, { stopAfter = null, from = 0, uploadId = null } = {}) {
  const { createHash } = await import('node:crypto');
  let init = uploadId ? { upload_id: uploadId, chunk_size: 8 * 1024 * 1024 } : await c.data('POST', `/api/v1/projects/${projectId}/uploads`, { file_name: name, total_size: bytes.length });
  if (uploadId) init = { ...init, ...(await c.data('GET', `/api/v1/uploads/${uploadId}/status`)) };
  const size = init.chunk_size;
  const n = Math.ceil(bytes.length / size);
  for (let i = from; i < n; i++) {
    const part = bytes.subarray(i * size, Math.min(bytes.length, (i + 1) * size));
    const r = await c.req('PUT', `/api/v1/uploads/${init.upload_id}/chunks/${i}`, { raw: part, headers: { 'X-Chunk-Sha256': createHash('sha256').update(part).digest('hex') } });
    if (r.status !== 200) throw new Error(`청크 ${i} → ${r.status} ${r.body.slice(0, 200)}`);
    if (stopAfter !== null && i >= stopAfter) return init.upload_id;
  }
  await c.data('POST', `/api/v1/uploads/${init.upload_id}/complete`);
  return init.upload_id;
}

/** 업로드가 분석까지 끝날 때까지(확인 대기면 confirm=true일 때 확인) → {upload, analysis} */
export async function settle(ctx, c, uploadId, { confirm = true, ms = 180000 } = {}) {
  let confirmed = false;
  const res = await ctx.until(async () => {
    const u = await c.data('GET', `/api/v1/uploads/${uploadId}`);
    if (u.status === 'needs_confirmation' && confirm && !confirmed) { await c.data('POST', `/api/v1/uploads/${uploadId}/confirm`); confirmed = true; return null; }
    if (u.status === 'needs_confirmation' || u.status === 'failed' || u.status === 'cancelled') return { upload: u };
    if (!u.analysis_id) return null;
    const a = await c.data('GET', `/api/v1/analyses/${u.analysis_id}`);
    return ['succeeded', 'failed', 'cancelled'].includes(a.status) ? { upload: u, analysis: a } : null;
  }, ms, 700);
  return res ?? { upload: await c.data('GET', `/api/v1/uploads/${uploadId}`), timeout: true };
}

const SAMPLES = (ctx) => path.join(ctx.ROOT, 'samples');

export const scenarios = [
  {
    n: 1, title: '설치 — 설치 가이드대로 새 폴더 설치(마이그레이션·create_admin·워커 시작·첫 로그인·점검 탭)',
    async fn(ctx) {
      const { rec, S } = ctx;
      rec.note(`묶음 ${path.basename(ctx.zip)} → ${path.relative(ctx.ROOT, ctx.dir)}/app, 포트 ${ctx.port}(내장 서버), 설치본 전용 DB·워커`);
      const r = ctx.php([path.join(ctx.ROOT, 'tools/acceptance/install.php'), `--zip=${ctx.zip}`, `--dir=${ctx.dir}/inst`, `--port=${ctx.port}`, '--bin=ruff,eslint,git'], { cwd: ctx.ROOT });
      // install.php는 빈 폴더를 요구 — inst/app을 설치본으로 쓴다
      const j = JSON.parse(r.out.trim().split('\n').pop());
      ctx.app = j.app;
      S.app = j.app;
      const st = Object.fromEntries(j.steps.map((s) => [s.step, s]));
      const tail = (k) => (st[k]?.tail ?? []).join(' / ');
      rec.check(st.verify_bundle?.code === 0 && /묶음 점검 통과/.test(tail('verify_bundle')), '묶음을 푼 폴더에서 verify_bundle 통과', tail('verify_bundle').slice(-160), 'install.php verify_bundle');
      rec.check(st.make_secret_key?.code === 0, 'make_secret_key — 키 생성(값은 출력 안 함)', tail('make_secret_key').slice(0, 120), 'install.php');
      rec.check(st.check_env?.code === 0 && /FAIL 0/.test(tail('check_env')), 'check_env FAIL 0', (tail('check_env').match(/결과: [^/]+/) ?? [''])[0], 'check_env.php');
      rec.check(st.migrate?.code === 0 && /031\s+alert_mail_state\s+적용됨/.test(tail('migrate_status')), 'migrate 001~031 적용, --status 모두 적용됨', tail('migrate_status').slice(-80), 'migrate.php --status');
      rec.check(st.create_admin?.code === 0 && /관리자 초기 비밀번호\s+admin/.test(tail('create_admin')), 'create_admin — 초기 비밀번호 admin + 하드닝 경고', (tail('create_admin').match(/\[WARN\] 관리자 초기[^/]+/) ?? [''])[0], 'create_admin.php');
      rec.check(st.tools_verify?.code === 0 && /ruff/.test(tail('tools_verify')) && /eslint/.test(tail('tools_verify')), 'bin/ 도구 배치 뒤 tools_ctl list(체크섬·스모크)', tail('tools_verify').slice(0, 200), 'tools_ctl.php list');
      S.install = j.steps.map((s) => ({ step: s.step, code: s.code, seconds: s.seconds }));
      await ctx.startServer();
      await ctx.startWorker();
      const ws = ctx.php([path.join(ctx.app, 'tools/worker.php'), '--status']);
      rec.check(/실행 중\(잠금 있음\)/.test(ws.out), '워커 시작 → --status 실행 중', ws.out.trim(), 'worker.php --status');
      // 첫 로그인(admin/admin) — 초기 비밀번호 배너
      const admin = ctx.client('admin');
      const lr = await admin.login('admin', 'admin');
      rec.check(lr.status === 200, '첫 로그인 admin/admin 200(변경 강제 없음, X17A)', lr.status, 'POST /api/v1/auth/login');
      const page = await userPage(ctx.browser, ctx.base);
      await formLogin(page, 'admin', 'admin');
      const d = await screen(page, rec, '/dashboard', { lang: 'ko', theme: 'light', shot: 'S01_dashboard_initial_password_banner', wait: '[data-initial-password-banner]' });
      const banner = await page.$('[data-initial-password-banner]');
      rec.check(!!banner, '초기 비밀번호 경고 배너 표시', banner ? 'data-initial-password-banner 있음' : '없음', 'S01_dashboard_initial_password_banner.png');
      const checks = await admin.data('GET', '/api/v1/admin/checks');
      const env = checks.environment?.items ?? [];
      const fails = env.filter((i) => i.status === 'fail');
      rec.check(fails.length === 0, '점검 탭 환경 항목 FAIL 0', `${env.length}개, FAIL ${fails.length}${fails.length ? `: ${fails.map((f) => f.id).join(',')}` : ''}`, 'GET /api/v1/admin/checks');
      await screen(page, rec, '/admin/checks', { lang: 'ko', theme: 'light', shot: 'S01_admin_checks' });
      // 워커가 설치 직후 체크섬·스모크를 돌려 활성 버전을 고른다(ESLint는 버전 3개라 수십 초) — 최대 3분 기다림
      const t0 = Date.now();
      const tools = (await ctx.until(async () => { const x = await admin.data('GET', '/api/v1/admin/tools'); return x.tools?.every((t) => t.selection?.version) ? x : null; }, 180000, 2000)) ?? await admin.data('GET', '/api/v1/admin/tools');
      rec.note(`워커 시작 뒤 도구 활성 버전 선택까지 약 ${Math.round((Date.now() - t0) / 1000)}초`);
      const sel = (t) => tools.tools?.find((x) => x.tool === t)?.selection?.version ?? null;
      const smoke = (t) => (tools.tools?.find((x) => x.tool === t)?.versions ?? []).map((v) => `${v.version}:${v.status}${v.smoke_message ? `(${v.smoke_message})` : ''}`).join(' ');
      rec.check(sel('ruff') && sel('eslint'), '분석 도구 활성 버전 선택(체크섬·스모크 통과 — 묶음의 samples/ 시료로)', `ruff ${sel('ruff')} [${smoke('ruff')}], eslint ${sel('eslint')} [${smoke('eslint')}]`, 'GET /api/v1/admin/tools');
      await screen(page, rec, '/admin/tools', { lang: 'ko', theme: 'light', shot: 'S01_admin_tools' });
      // 비밀번호 변경 → 배너 사라짐
      const cp = await admin.req('POST', '/api/v1/auth/password', { json: { current_password: 'admin', new_password: ADMIN_PW } });
      rec.check(cp.status === 200, '관리자 비밀번호 변경 200', cp.status, 'POST /api/v1/auth/password');
      await admin.login('admin', ADMIN_PW);
      await page.ctx.close();
      const p2 = await ctx.page('admin');
      await p2.goto(`${ctx.base}/dashboard`, { waitUntil: 'networkidle0' });
      rec.check(!(await p2.$('[data-initial-password-banner]')), '변경 뒤 배너 사라짐', '', '/dashboard');
    },
  },
  {
    n: 2, title: '계정·권한 — 사용자·그룹·그룹 관리자, 권한 경계, 프로젝트 접근(보이지 않으면 404)',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await adminClient(ctx);
      S.u = {};
      for (const login of ['dev1', 'dev2', 'mgr1', 'mem1', 'out1']) {
        const u = await admin.data('POST', '/api/v1/users', { login_id: login, display_name: `User ${login}` });
        S.u[login] = u.user.id;
        await admin.data('PUT', `/api/v1/users/${u.user.id}/password`, { password: PW });
      }
      rec.check(Object.keys(S.u).length === 5, 'admin이 사용자 5명 생성(임시 비밀번호 → 관리자가 비밀번호 지정)', Object.keys(S.u).join(','), 'POST /api/v1/users, PUT /users/{id}/password');
      S.g = (await admin.data('POST', '/api/v1/groups', { name: 'team', description: 'acceptance' })).id;
      await admin.data('POST', `/api/v1/groups/${S.g}/members`, { login_id: 'mgr1' });
      await admin.data('PUT', `/api/v1/groups/${S.g}/members/${S.u.mgr1}/manager`, { is_manager: true });
      S.p = {};
      for (const [name, language] of [['py', 'python'], ['js', 'javascript']]) S.p[name] = (await admin.data('POST', '/api/v1/projects', { name, language })).id;
      for (const pid of Object.values(S.p)) for (const l of ['dev1', 'dev2']) await admin.data('POST', `/api/v1/projects/${pid}/access`, { subject_type: 'user', login_id: l });
      await admin.data('POST', `/api/v1/projects/${S.p.py}/access`, { subject_type: 'group', group_id: S.g });
      rec.check(true, '그룹 team(그룹 관리자 mgr1) 생성, 프로젝트 py·js 생성, dev1·dev2 → py·js, 그룹 team → py 매핑', `py=${S.p.py}, js=${S.p.js}, group=${S.g}`, 'POST /api/v1/groups·projects·access');
      const c = {};
      for (const l of ['dev1', 'dev2', 'mgr1', 'mem1', 'out1']) {
        c[l] = ctx.client(l);
        const r = await c[l].login(l, PW);
        if (r.status !== 200) throw new Error(`${l} 로그인 ${r.status}`);
      }
      // 그룹 관리자: 자기 그룹 구성원 추가·계정 만들기, 일반 구성원은 못 함
      const add = await c.mgr1.req('POST', `/api/v1/groups/${S.g}/members`, { json: { login_id: 'mem1' } });
      rec.check(add.status >= 200 && add.status < 300, '그룹 관리자 mgr1이 자기 그룹에 구성원 mem1 추가', add.status, 'POST /api/v1/groups/{id}/members (mgr1)');
      const acc = await c.mgr1.req('POST', `/api/v1/groups/${S.g}/accounts`, { json: { login_id: 'grp1', display_name: 'Group made' } });
      rec.check(acc.status === 201 && acc.json?.data?.user?.role === 'user', '그룹 관리자가 그룹 계정 생성(역할은 user 고정)', `${acc.status} ${acc.json?.data?.user?.role}`, 'POST /api/v1/groups/{id}/accounts');
      const memAdd = await c.mem1.req('POST', `/api/v1/groups/${S.g}/members`, { json: { login_id: 'out1' } });
      rec.check(memAdd.status === 404, '일반 구성원 mem1은 구성원 추가 불가 — 관리하지 않는 그룹은 보이지 않아 404(GroupPermission::canManage)', memAdd.status, 'POST /api/v1/groups/{id}/members (mem1)');
      const adminOnly = [];
      for (const l of ['dev1', 'mgr1']) {
        adminOnly.push((await c[l].get('/api/v1/users')).status, (await c[l].get('/admin/users', { headers: { Accept: 'text/html' } })).status, (await c[l].get('/api/v1/admin/monitoring')).status);
      }
      rec.check(adminOnly.every((s) => s === 403), '일반 사용자·그룹 관리자의 관리자 API·화면 403', adminOnly.join(','), 'GET /api/v1/users, /admin/users, /api/v1/admin/monitoring');
      // 프로젝트 접근: 보이는 사람 200, 안 보이는 사람 404(없는 것과 같음)
      const st = async (who, pid) => [(await c[who].get(`/api/v1/projects/${pid}`)).status, (await c[who].get(`/projects/${pid}`, { headers: { Accept: 'text/html' } })).status];
      const m = { dev1: await st('dev1', S.p.py), mgr1: await st('mgr1', S.p.py), mem1: await st('mem1', S.p.py), out1: await st('out1', S.p.py), mem1js: await st('mem1', S.p.js) };
      rec.check(JSON.stringify(m) === JSON.stringify({ dev1: [200, 200], mgr1: [200, 200], mem1: [200, 200], out1: [404, 404], mem1js: [404, 404] }),
        '프로젝트 접근: 직접 매핑·그룹 구성원 200, 매핑 없는 out1과 그룹에 매핑 안 된 js는 404(403 아님)', JSON.stringify(m), 'GET /api/v1/projects/{id}, /projects/{id}');
      const missing = await c.out1.get('/api/v1/projects/999999');
      rec.check(missing.status === 404 && missing.json?.error?.code === (await c.out1.get(`/api/v1/projects/${S.p.py}`)).json?.error?.code, '보이지 않는 프로젝트 = 없는 프로젝트와 같은 오류 코드', missing.json?.error?.code, '');
      const out1List = await c.out1.data('GET', '/api/v1/projects');
      rec.check(Array.isArray(out1List) && out1List.length === 0, 'out1의 프로젝트 목록 0건', out1List.length, 'GET /api/v1/projects');
      await c.mgr1.data('DELETE', `/api/v1/groups/${S.g}/members/${S.u.mem1}`);
      const after = await st('mem1', S.p.py);
      rec.check(after[0] === 404, '그룹에서 빼면 다음 요청부터 404', after.join(','), 'DELETE /api/v1/groups/{id}/members/{userId}');
      await c.mgr1.data('POST', `/api/v1/groups/${S.g}/members`, { login_id: 'mem1' });
      const p = await ctx.page('mgr1');
      await setPrefs(c.mgr1, 'en', 'dark');
      await screen(p, rec, `/groups/${S.g}`, { lang: 'en', theme: 'dark', shot: 'S02_group_manager_en_dark' });
      await setPrefs(c.mgr1, 'ko', 'light');
      const po = await ctx.page('out1');
      const r404 = await po.goto(`${ctx.base}/projects/${S.p.py}`, { waitUntil: 'networkidle0' });
      rec.check(r404.status() === 404, '브라우저: 매핑 없는 사용자의 프로젝트 화면 404', r404.status(), `/projects/${S.p.py}`);
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/admin/users', { lang: 'ko', theme: 'light', shot: 'S02_admin_users' });
    },
  },
  {
    n: 3, title: '프로젝트·업로드 — ZIP(소형·대형·중단 후 새 세션에서 이어 올리기), 폴더 업로드(브라우저), 사전 검사·경고 확인, 비정상 zip',
    async fn(ctx) {
      const { rec, S } = ctx;
      const dev = await ctx.user('dev1');
      const small = readFileSync(path.join(SAMPLES(ctx), 'zips/python_sample.zip'));
      const id1 = await upload(dev, S.p.py, small, 'python_sample.zip');
      const u0 = await dev.data('GET', `/api/v1/uploads/${id1}`);
      rec.check(['assemble_pending', 'assembling', 'extracting', 'queued', 'running'].includes(u0.status) || u0.analysis_id, 'complete 직후 "조립 대기"(조립은 워커가)', u0.status, 'GET /api/v1/uploads/{id}');
      const r1 = await settle(ctx, dev, id1);
      rec.check(r1.analysis?.status === 'succeeded' && r1.analysis.counts.total === 19, '소형 zip(python_sample.zip) → 분석 성공, 결함 19건(samples/EXPECTED.md)', `${r1.analysis?.status} total=${r1.analysis?.counts?.total}`, `analysis ${r1.analysis?.id}`);
      S.a1 = r1.analysis?.id;
      // 대형(20MB 넘음, 조각 3개) — 첫 조각만 올리고 멈춤 → 새 로그인 세션에서 이어 올리기
      const { randomBytes } = await import('node:crypto');
      const big = await zipFiles({ ...readTree(path.join(SAMPLES(ctx), 'python_sample')), 'assets/blob.bin': randomBytes(20 * 1024 * 1024) });
      const idB = await upload(dev, S.p.py, big, 'big.zip', { stopAfter: 0 });
      const st = await dev.data('GET', `/api/v1/uploads/${idB}/status`);
      rec.check(st.total_chunks === 3 && st.received.length === 1, `대형 zip ${(big.length / 1048576).toFixed(1)}MB(조각 ${st.total_chunks}개) — 1개만 올리고 중단`, `received=${st.received.length}/${st.total_chunks}`, 'GET /api/v1/uploads/{id}/status');
      await dev.login('dev1', PW); // 새 세션
      await upload(dev, S.p.py, big, 'big.zip', { uploadId: idB, from: st.received.length });
      const rB = await settle(ctx, dev, idB);
      rec.check(rB.analysis?.status === 'succeeded' && rB.analysis.counts.total === 19, '새 세션에서 나머지 조각 이어 올리기 → 조립·해제·분석 성공(결함 19)', `${rB.upload.status} ${rB.analysis?.status} total=${rB.analysis?.counts?.total}`, `upload ${idB}`);
      // 사전 검사 경고: 최상위 폴더가 있는 zip(첫 업로드는 없었음) → 확인 대기 → 계속
      const idT = await upload(dev, S.p.py, readFileSync(path.join(SAMPLES(ctx), 'zips/python_sample_toplevel.zip')), 'python_sample_toplevel.zip');
      const wT = await settle(ctx, dev, idT, { confirm: false });
      rec.check(wT.upload.status === 'needs_confirmation' && (wT.upload.warnings ?? []).some((w) => /top_folder/.test(w)), '최상위 폴더가 첫 업로드와 다름 → 확인 대기(경고)', `${wT.upload.status} ${JSON.stringify(wT.upload.warnings)}`, `upload ${idT}`);
      const pd = await ctx.page('dev1');
      await dev.data('POST', `/api/v1/uploads/${idT}/confirm`);
      const rT = await settle(ctx, dev, idT);
      rec.check(rT.analysis?.status === 'succeeded', '경고 확인(계속) → 분석 성공', rT.analysis?.status, `upload ${idT}`);
      // 대상 파일 없는 zip → no_target 경고
      const idN = await upload(dev, S.p.js, readFileSync(path.join(SAMPLES(ctx), 'zips/empty_no_target.zip')), 'empty_no_target.zip');
      const wN = await settle(ctx, dev, idN, { confirm: false });
      rec.check(wN.upload.status === 'needs_confirmation' && (wN.upload.warnings ?? []).includes('no_target'), '대상 파일 없는 zip → 확인 대기 no_target', `${wN.upload.status} ${JSON.stringify(wN.upload.warnings)}`, `upload ${idN}`);
      await dev.req('DELETE', `/api/v1/uploads/${idN}`);
      // 비정상 zip: 경로 조작
      const idS = await upload(dev, S.p.py, readFileSync(path.join(SAMPLES(ctx), 'zips_bad/zip_slip.zip')), 'zip_slip.zip');
      const rS = await settle(ctx, dev, idS);
      rec.check(rS.upload.status === 'failed' && rS.upload.error_code && rS.upload.error?.known, '경로 조작 zip → 실패 + 알려진 오류 코드', `${rS.upload.status} ${rS.upload.error_code}`, `upload ${idS}`);
      const outside = ctx.sql("SELECT COUNT(*) AS n FROM analyses WHERE project_id = ? AND status = 'succeeded'", S.p.py)[0].n;
      rec.note(`py 성공 분석 ${outside}건`);
      // 폴더 업로드(브라우저): showDirectoryPicker가 없는 경우의 폴더 선택(webkitdirectory) → STORE로 묶어 업로드
      await pd.evaluateOnNewDocument(() => { try { Object.defineProperty(window, 'showDirectoryPicker', { value: undefined, configurable: true }); } catch { /* 없음 */ } });
      await pd.goto(`${ctx.base}/upload`, { waitUntil: 'networkidle0' });
      await pd.select('#up-project', String(S.p.js));
      const input = await pd.$('#up-folder');
      await input.uploadFile(path.join(SAMPLES(ctx), 'js_sample'));
      await pd.waitForFunction(() => !document.getElementById('up-start').disabled, { timeout: 20000 }).catch(() => null);
      const target = await pd.$eval('#up-target', (e) => e.textContent.trim()).catch(() => '');
      const before = ctx.sqlOne('SELECT COUNT(*) AS n FROM uploads WHERE project_id = ?', S.p.js).n;
      await pd.click('#up-start');
      const newUp = await ctx.until(() => (ctx.sqlOne('SELECT COUNT(*) AS n FROM uploads WHERE project_id = ?', S.p.js).n > before ? ctx.sqlOne('SELECT id, status FROM uploads WHERE project_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1', S.p.js) : null), 60000);
      let rF = null;
      if (newUp) rF = await settle(ctx, dev, newUp.id);
      // 업로드 화면 그대로(진행·단계 표시) 캡처 — 이 화면에서 생긴 콘솔 오류·CSP 위반이 없어야 함
      await sleep(1500);
      await pd.screenshot({ path: path.join(screen.dir, 'S03_upload_folder_done.png') });
      rec.shot('S03_upload_folder_done.png');
      rec.check(pd.log.csp.length === 0 && pd.log.errors.length === 0, '폴더 업로드 화면: 콘솔 오류·CSP 위반 0', `CSP ${pd.log.csp.length}, 오류 ${pd.log.errors.length} ${pd.log.errors[0] ?? ''}`, 'S03_upload_folder_done.png');
      rec.check(rF?.analysis?.status === 'succeeded' && rF.analysis.tool === 'eslint', '브라우저 폴더 선택(webkitdirectory) → 묶어 업로드 → ESLint 분석 성공', `대상 "${target}" → ${rF?.upload?.status} ${rF?.analysis?.status} ${rF?.analysis?.tool} total=${rF?.analysis?.counts?.total}`, 'upload.js 폴더 묶기(STORE)');
      S.aJs = rF?.analysis?.id;
      const z = ctx.sqlOne('SELECT file_name, total_size FROM uploads WHERE id = ?', newUp?.id ?? 0);
      rec.note(`폴더 업로드 파일 이름 ${z?.file_name}, ${z?.total_size}바이트. showDirectoryPicker 방식(선택 창)은 헤드리스에서 조작할 수 없어 수동 확인 항목`);
    },
  },
  {
    n: 4, title: '분석 — ruff/ESLint 완료·실패·재시도·취소(대기·실행 중), 큐 순서, 상태 화면, 오류 코드',
    async fn(ctx) {
      const { rec, S } = ctx;
      const dev = await ctx.user('dev1');
      const admin = await adminClient(ctx);
      const pyTree = readTree(path.join(SAMPLES(ctx), 'python_sample'));
      await ctx.until(() => ctx.sqlOne("SELECT COUNT(*) AS n FROM uploads WHERE user_id = ? AND status IN ('receiving','assemble_pending','assembling','extracting')", S.u.dev1).n === 0, 60000);
      const openBefore = ctx.sqlOne("SELECT COUNT(*) AS n, MAX(version) AS v FROM defects WHERE project_id = ? AND status != 'resolved'", S.p.py);
      const okBefore = ctx.sqlOne("SELECT MAX(id) AS id FROM analyses WHERE project_id = ? AND status = 'succeeded'", S.p.py).id;
      // 실패: 잘못된 ruff 설정
      const idF = await upload(dev, S.p.py, await zipFiles({ ...pyTree, 'ruff.toml': '[lint]\nselect = ["NOPE999"]\n' }), 'bad_config.zip');
      const rF = await settle(ctx, dev, idF);
      rec.check(rF.analysis?.status === 'failed' && rF.analysis.failure?.code === 'tool_config_error', '잘못된 ruff 설정 → 분석 실패 tool_config_error(제목·원인·가이드 절)', `${rF.analysis?.status} ${rF.analysis?.failure?.code}: ${rF.analysis?.failure?.title} / ${rF.analysis?.failure?.guide_key}`, `analysis ${rF.analysis?.id}`);
      const openAfter = ctx.sqlOne("SELECT COUNT(*) AS n, MAX(version) AS v FROM defects WHERE project_id = ? AND status != 'resolved'", S.p.py);
      rec.check(JSON.stringify(openBefore) === JSON.stringify(openAfter), '실패한 분석은 결함을 바꾸지 않음', `${JSON.stringify(openBefore)} → ${JSON.stringify(openAfter)}`, 'defects');
      S.failedAnalysis = rF.analysis?.id;
      const pd = await ctx.page('dev1');
      await setPrefs(dev, 'en', 'dark');
      const fs = await screen(pd, rec, `/analyses/${rF.analysis?.id}`, { lang: 'en', theme: 'dark', shot: 'S04_analysis_failed_en_dark' });
      const txt = await pd.evaluate(() => document.body.innerText);
      rec.check(/tool_config_error/.test(txt), '실패 화면에 오류 코드 표시', /tool_config_error/.test(txt) ? 'tool_config_error 보임' : txt.slice(0, 200), 'S04_analysis_failed_en_dark.png');
      await setPrefs(dev, 'ko', 'light');
      const errs = await dev.data('GET', '/api/v1/guide/errors');
      rec.check(JSON.stringify(errs).includes('tool_config_error'), '오류 코드 탭(가이드)에 tool_config_error', '', 'GET /api/v1/guide/errors');
      // 재시도: 고친 zip을 다시 올림 → 성공, 비교 기준 = 직전 성공(실패는 건너뜀)
      const idR = await upload(dev, S.p.py, await zipFiles(pyTree), 'retry.zip');
      const rR = await settle(ctx, dev, idR);
      rec.check(rR.analysis?.status === 'succeeded' && rR.analysis.baseline?.id === okBefore, '설정을 고쳐 다시 올림 → 성공, 비교 기준 = 실패 전 마지막 성공 분석', `${rR.analysis?.status} baseline=${rR.analysis?.baseline?.id} (기대 ${okBefore})`, `analysis ${rR.analysis?.id}`);
      // 취소(대기 중): 워커를 멈추고 업로드 후처리만 → queued → 취소
      await ctx.stopWorker();
      const idQ = await upload(dev, S.p.py, await zipFiles({ ...pyTree, 'extra_q.py': 'import os\n' }), 'q.zip');
      const pu = ctx.php([path.join(ctx.app, 'tools/process_upload.php'), '--all']);
      const uq = await dev.data('GET', `/api/v1/uploads/${idQ}`);
      const aq = uq.analysis_id ? await dev.data('GET', `/api/v1/analyses/${uq.analysis_id}`) : null;
      const cq = aq ? await dev.data('POST', `/api/v1/analyses/${aq.id}/cancel`) : null;
      rec.check(aq?.status === 'queued' && cq?.status === 'cancelled', '워커 정지 중 업로드 → 후처리(process_upload --all) → queued → 취소 즉시 cancelled', `${aq?.status} → ${cq?.status} (process_upload 종료 ${pu.code})`, 'POST /api/v1/analyses/{id}/cancel');
      // 큐 순서: 워커 정지 상태에서 py·js·py 순서로 3개를 큐에 → 워커 시작 → 같은 프로젝트는 하나씩, 전체 동시 2개
      // (사용자당 진행 중 업로드 2개 한도 — 조립 대기 2개면 세 번째는 429 too_many_uploads. 그래서 둘을 후처리한 뒤 세 번째)
      const jsTree = readTree(path.join(SAMPLES(ctx), 'js_sample'));
      const order = [];
      order.push(await upload(dev, S.p.py, await zipFiles({ ...pyTree, 'q2.py': 'import sys\n' }), 'o1.zip'));
      order.push(await upload(dev, S.p.js, await zipFiles({ ...jsTree, 'o2.js': 'var unused = 1;\n' }), 'o2.zip'));
      const third = await dev.req('POST', `/api/v1/projects/${S.p.py}/uploads`, { json: { file_name: 'o3.zip', total_size: 10 } });
      rec.check(third.status === 429 && third.json?.error?.code === 'too_many_uploads', '사용자당 동시 업로드 2개 — 세 번째 시작 429 too_many_uploads', `${third.status} ${third.json?.error?.code}`, 'POST /api/v1/projects/{id}/uploads');
      ctx.php([path.join(ctx.app, 'tools/process_upload.php'), '--all']);
      order.push(await upload(dev, S.p.py, await zipFiles({ ...pyTree, 'q3.py': 'import re\n' }), 'o3.zip'));
      ctx.php([path.join(ctx.app, 'tools/process_upload.php'), '--all']);
      for (const id of order) { const u = await dev.data('GET', `/api/v1/uploads/${id}`); if (u.status === 'needs_confirmation') await dev.data('POST', `/api/v1/uploads/${id}/confirm`); }
      await ctx.startWorker();
      const done = [];
      for (const id of order) done.push(await settle(ctx, dev, id));
      const rows = done.map((d) => ctx.sqlOne('SELECT id, project_id, queued_at, started_at, finished_at FROM analyses WHERE id = ?', d.analysis?.id ?? 0));
      const startOrder = [...rows].sort((a, b) => (a.started_at < b.started_at ? -1 : a.started_at > b.started_at ? 1 : a.id - b.id)).map((r) => r.id);
      const sameProjectOk = rows[2].started_at >= rows[0].finished_at; // py의 두 번째는 첫 번째가 끝난 뒤
      rec.check(done.every((d) => d.analysis?.status === 'succeeded') && sameProjectOk && startOrder.indexOf(rows[1].id) < startOrder.indexOf(rows[2].id),
        '큐: 3건 모두 성공, 같은 프로젝트(py)는 하나씩(두 번째는 첫 번째가 끝난 뒤 시작), 다른 프로젝트(js)는 먼저 등록된 대로 앞서 시작',
        rows.map((r) => `#${r.id} p${r.project_id} ${r.started_at?.slice(11, 19)}~${r.finished_at?.slice(11, 19)}`).join(', '), 'analyses.started_at');
      // 취소(실행 중): 큰 JS 프로젝트(ESLint가 오래 걸림) → running → 취소
      const bigJs = {};
      for (let i = 0; i < 1200; i++) bigJs[`src/m${i}.js`] = Array.from({ length: 120 }, (_, k) => `var v${k} = ${k}; if (v${k} == null) { console.log(v${k}) }`).join('\n');
      const idC = await upload(dev, S.p.js, await zipFiles(bigJs), 'big_js.zip');
      const running = await ctx.until(async () => { const u = await dev.data('GET', `/api/v1/uploads/${idC}`); if (u.status === 'needs_confirmation') await dev.data('POST', `/api/v1/uploads/${idC}/confirm`); if (!u.analysis_id) return null; const a = await dev.data('GET', `/api/v1/analyses/${u.analysis_id}`); return a.status === 'running' ? a : (['succeeded', 'failed', 'cancelled'].includes(a.status) ? a : null); }, 120000, 300);
      let cancelled = null;
      if (running?.status === 'running') {
        await sleep(1500);
        const c1 = await dev.data('POST', `/api/v1/analyses/${running.id}/cancel`);
        cancelled = await ctx.until(async () => { const a = await dev.data('GET', `/api/v1/analyses/${running.id}`); return a.status !== 'running' ? a : null; }, 60000, 300);
        rec.check(c1.cancel_requested === true && cancelled?.status === 'cancelled' && cancelled.can_cancel === false, '실행 중 취소 → 취소 요청 → 워커가 프로세스 트리를 멈추고 cancelled', `${c1.status}/${c1.cancel_requested} → ${cancelled?.status}`, `analysis ${running.id}`);
      } else {
        rec.check(false, '실행 중 취소(분석이 running 상태가 됨)', running?.status ?? '시간 초과', `upload ${idC}`);
      }
      const lastOkJs = ctx.sqlOne("SELECT MAX(id) AS id FROM analyses WHERE project_id = ? AND status = 'succeeded'", S.p.js).id;
      rec.check(lastOkJs === done[1].analysis?.id, '취소된 분석은 비교 기준(직전 성공)을 바꾸지 않음', `직전 성공 ${lastOkJs}`, '');
      await screen(pd, rec, `/projects/${S.p.js}/history`, { lang: 'ko', theme: 'light', shot: 'S04_js_history_cancelled' });
    },
  },
  {
    n: 5, title: '결함 — 목록·검색·정렬·상세·코드 뷰어, 담당자·심각도·상태, 일괄 변경, 동시 수정 충돌, 신규/해결/재오픈, 내 결함',
    async fn(ctx) {
      const { rec, S } = ctx;
      const dev = await ctx.user('dev1');
      const dev2 = await ctx.user('dev2');
      const py = S.p.py;
      // 깨끗한 프로젝트 하나로(이전 시나리오의 시험 파일 영향 없이) 같은 흐름
      const admin = await adminClient(ctx);
      S.p.def = (await admin.data('POST', '/api/v1/projects', { name: 'defects', language: 'python' })).id;
      for (const l of ['dev1', 'dev2']) await admin.data('POST', `/api/v1/projects/${S.p.def}/access`, { subject_type: 'user', login_id: l });
      const pid = S.p.def;
      const r1 = await settle(ctx, dev, await upload(dev, pid, readFileSync(path.join(SAMPLES(ctx), 'zips/python_sample.zip')), 'v1.zip'));
      const all = await dev.get(`/api/v1/projects/${pid}/defects?size=200`);
      rec.check(all.json?.page?.total === 19, '결함 목록 19건', all.json?.page?.total, `GET /api/v1/projects/${pid}/defects`);
      const f401 = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401`)).json.page.total;
      const q = (await dev.get(`/api/v1/projects/${pid}/defects?q=${encodeURIComponent('imported but unused')}`)).json.page.total;
      rec.check(f401 > 0 && f401 === q, '규칙 필터 = 메시지 검색 건수', `rule=ruff:F401 ${f401}, q ${q}`, '');
      const sev = (await dev.get(`/api/v1/projects/${pid}/defects?sort=severity&order=asc&size=200`)).json.data.map((d) => d.severity);
      const rank = { critical: 0, high: 1, medium: 2, low: 3, not_defined: 4 };
      rec.check(sev.every((s, i) => i === 0 || rank[sev[i - 1]] <= rank[s]), '심각도 정렬(치명적부터)', sev.slice(0, 6).join(','), 'sort=severity');
      const d0 = all.json.data[0];
      const code = await dev.data('GET', `/api/v1/defects/${d0.id}/code`);
      rec.check(code.source === 'snapshot' && Array.isArray(code.lines), '코드(스냅샷) 응답', `${d0.file_path}:${d0.line} source=${code.source}`, `GET /api/v1/defects/${d0.id}/code`);
      const pd = await ctx.page('dev1');
      await setPrefs(dev, 'en', 'light');
      await screen(pd, rec, `/projects/${pid}`, { lang: 'en', theme: 'light', shot: 'S05_defects_list_en_light', wait: 'table' }).catch(() => null);
      await setPrefs(dev, 'ko', 'dark');
      await screen(pd, rec, `/defects/${d0.id}`, { lang: 'ko', theme: 'dark', shot: 'S05_defect_detail_ko_dark', wait: '.cm-editor' });
      const cm = await pd.$('.cm-editor');
      rec.check(!!cm, '상세 코드 뷰어(CodeMirror) 렌더링', cm ? '있음' : '없음', 'S05_defect_detail_ko_dark.png');
      await setPrefs(dev, 'ko', 'light');
      const p1 = await dev.data('PATCH', `/api/v1/defects/${d0.id}`, { version: d0.version ?? 1, assignee_id: S.u.dev2, severity: 'high', status: 'in_progress', memo: 'acceptance' });
      rec.check(p1.version === (d0.version ?? 1) + 1 && p1.assignee?.login_id === 'dev2' && p1.status === 'in_progress', '담당자 dev2·심각도 high·상태 진행 중 변경(version +1)', `v${p1.version} ${p1.assignee?.login_id} ${p1.severity} ${p1.status}`, `PATCH /api/v1/defects/${d0.id}`);
      const hist = await dev.data('GET', `/api/v1/defects/${d0.id}/history`);
      rec.check(hist.length >= 3, '변경 이력(필드별)', hist.map((h) => h.field).join(','), `GET /api/v1/defects/${d0.id}/history`);
      const ok = await dev.req('PATCH', `/api/v1/defects/${d0.id}`, { json: { version: p1.version, status: 'postponed' } });
      const conflict = await dev2.req('PATCH', `/api/v1/defects/${d0.id}`, { json: { version: p1.version, severity: 'low' } });
      rec.check(ok.status === 200 && conflict.status === 409 && conflict.json?.error?.code === 'version_conflict', '동시 수정: 먼저 저장 200, 같은 version으로 저장한 쪽 409 version_conflict(최신 값 포함)', `${ok.status}/${conflict.status} ${conflict.json?.error?.code} current=${conflict.json?.error?.details?.current?.status}`, '');
      const items = all.json.data.slice(1, 5).map((x) => ({ id: x.id, version: x.version ?? 1 }));
      items[1].version = 99;
      const b = await dev.data('POST', `/api/v1/projects/${pid}/defects/bulk`, { items, changes: { severity: 'critical' }, memo: 'bulk' });
      rec.check(b.updated.length === 3 && b.conflicts.length === 1, '일괄 변경 4건: 3건 변경, 낡은 version 1건 충돌(부분 성공)', `updated ${b.updated.length}, conflicts ${b.conflicts.length}`, `POST /api/v1/projects/${pid}/defects/bulk`);
      const mine = await dev2.get('/api/v1/my-defects');
      rec.check((mine.json?.page?.total ?? 0) >= 1 && mine.json.data.some((x) => x.id === d0.id), '내게 지정된 결함(dev2)에 지정한 결함이 보임', `total ${mine.json?.page?.total}`, 'GET /api/v1/my-defects');
      const p2 = await ctx.page('dev2');
      await screen(p2, rec, '/my-defects', { lang: 'ko', theme: 'light', shot: 'S05_my_defects' });
      // 신규/해결/재오픈: config_loader.py 결함 하나를 수동 resolved, helpers.py 한 줄 변경, main.py 삭제, 새 파일 추가
      const cl = all.json.data.find((x) => x.file_path === 'config_loader.py');
      const cur = await dev.data('GET', `/api/v1/defects/${cl.id}`);
      await dev.data('PATCH', `/api/v1/defects/${cl.id}`, { version: cur.version, status: 'resolved', memo: '고쳤다고 생각함' });
      const tree = readTree(path.join(SAMPLES(ctx), 'python_sample'));
      tree['utils/helpers.py'] = Buffer.from(tree['utils/helpers.py'].toString('utf8').replace('return value == None', 'return value == None  # changed'));
      delete tree['main.py'];
      tree['extra/new_mod.py'] = 'import json\n';
      const r2 = await settle(ctx, dev, await upload(dev, pid, await zipFiles(tree), 'v2.zip'));
      const cnt = r2.analysis?.counts ?? {};
      rec.check(cnt.new === 2 && cnt.resolved === 4 && cnt.reopened === 1, '재분석: 신규 2(변경 줄 1·새 파일 1), 자동 해결 4(삭제 파일 3·변경 1), 재오픈 1(수동 resolved였는데 계속 감지)', JSON.stringify(cnt), `analysis ${r2.analysis?.id}`);
      const re = await dev.data('GET', `/api/v1/defects/${cl.id}`);
      rec.check(re.status === 'open', '수동 resolved → open으로 재오픈', re.status, `GET /api/v1/defects/${cl.id}`);
      const reasons = ctx.sql('SELECT reason_code, COUNT(*) AS n FROM defect_history WHERE analysis_id = ? GROUP BY reason_code ORDER BY reason_code', r2.analysis?.id ?? 0).map((r) => `${r.reason_code}:${r.n}`).join(',');
      rec.check(reasons === 'file_deleted:3,not_detected:1,redetected:1', '자동 이력 사유(파일 삭제 3·미감지 1·재감지 1)', reasons, 'defect_history');
      S.defA2 = r2.analysis?.id;
    },
  },
  {
    n: 6, title: '대시보드·히스토리·Run — 집계, 추이, Run 선택, 직전 대비 비교, 최근 15개 Run 상세 유지',
    async fn(ctx) {
      const { rec, S } = ctx;
      const dev = await ctx.user('dev1');
      const admin = await adminClient(ctx);
      const pid = (S.p.runs = (await admin.data('POST', '/api/v1/projects', { name: 'runs', language: 'python' })).id);
      await admin.data('POST', `/api/v1/projects/${pid}/access`, { subject_type: 'user', login_id: 'dev1' });
      const orig = readTree(path.join(SAMPLES(ctx), 'python_sample'));
      const v2 = { ...orig, 'utils/helpers.py': Buffer.from(orig['utils/helpers.py'].toString('utf8').replace('return value == None', 'return value == None  # changed')), 'extra/new_mod.py': 'import json\n' };
      delete v2['main.py'];
      const res = [];
      for (const [name, files] of [['v1.zip', orig], ['v2.zip', v2], ['v3.zip', { ...orig, 'z.py': '' }]]) res.push(await settle(ctx, dev, await upload(dev, pid, await zipFiles(files), name)));
      const list = await dev.data('GET', `/api/v1/projects/${pid}/runs`);
      rec.check(JSON.stringify(list.map((r) => r.run)) === '[3,2,1]' && JSON.stringify(list.map((r) => r.total)) === '[19,17,19]', 'Run 3개(최신순), Run별 결함 19 → 17 → 19', JSON.stringify(list.map((r) => [r.run, r.total])), `GET /api/v1/projects/${pid}/runs`);
      const t1 = (await dev.get(`/api/v1/projects/${pid}/defects?run=1`)).json?.page?.total;
      const t2 = (await dev.get(`/api/v1/projects/${pid}/defects?run=2`)).json?.page?.total;
      rec.check(t1 === 19 && t2 === 17, 'Run 선택(과거 Run 1·2 목록 건수)', `${t1}, ${t2}`, 'defects?run=N');
      const hist = await dev.data('GET', `/api/v1/projects/${pid}/history`);
      const cmp = await dev.data('GET', `/api/v1/analyses/${hist[1].analysis_id}/comparison`);
      rec.check(cmp.counts.new === 2 && cmp.counts.resolved === 4, '히스토리 비교 패널: Run 2 = 직전 대비 신규 2·해결 4', JSON.stringify(cmp.counts), `GET /api/v1/analyses/${hist[1].analysis_id}/comparison`);
      const sum = await dev.data('GET', '/api/v1/dashboard/summary');
      const openDb = ctx.sql("SELECT COUNT(*) AS n FROM defects d JOIN project_access pa ON 1=0").length; // 형식 확인용(아래 API 합과 비교)
      const perProject = [];
      for (const p of Object.values(S.p)) {
        const r = await dev.get(`/api/v1/projects/${p}/defects?status=open,in_progress,postponed&size=1`);
        if (r.status === 200) perProject.push(r.json.page.total);
      }
      rec.check(sum.open.total === perProject.reduce((a, b) => a + b, 0), '대시보드 열린 결함 합계 = 보이는 프로젝트별 결함 목록(열린 상태) 합', `${sum.open.total} = ${perProject.join('+')}`, 'GET /api/v1/dashboard/summary');
      void openDb;
      const trend = await dev.data('GET', `/api/v1/dashboard/trend?project=${pid}`);
      rec.check((trend.series ?? []).length >= 1, '추이(프로젝트 지정) 계열 있음', `series ${trend.series?.length}, 점 ${trend.series?.[0]?.points?.length ?? '-'}`, `GET /api/v1/dashboard/trend?project=${pid}`);
      const pd = await ctx.page('dev1');
      await setPrefs(dev, 'en', 'dark');
      await screen(pd, rec, '/dashboard', { lang: 'en', theme: 'dark', shot: 'S06_dashboard_en_dark', wait: 'canvas' });
      await setPrefs(dev, 'ko', 'light');
      await screen(pd, rec, `/projects/${pid}/history`, { lang: 'ko', theme: 'light', shot: 'S06_history_ko_light' });
      // 최근 15개 Run 상세 유지: 작은 프로젝트에 Run 17개 → Run 1·2의 상세는 지워지고(목록 410) 3~17은 유지
      const kp = (S.p.keep = (await admin.data('POST', '/api/v1/projects', { name: 'keep15', language: 'python' })).id);
      await admin.data('POST', `/api/v1/projects/${kp}/access`, { subject_type: 'user', login_id: 'dev1' });
      for (let i = 1; i <= 17; i++) {
        const files = { 'a.py': `import os\nimport sys\n${Array.from({ length: i }, (_, k) => `x${k} = ${k}`).join('\n')}\n`, [`m${i}.py`]: 'import json\n' };
        const r = await settle(ctx, dev, await upload(dev, kp, await zipFiles(files), `k${i}.zip`));
        if (r.analysis?.status !== 'succeeded') throw new Error(`Run ${i} ${r.analysis?.status ?? r.upload.status}`);
      }
      // 보관 표시는 워커가 반영·체크포인트 뒤에 한다(분석이 succeeded로 보인 직후엔 아직일 수 있음) — 최대 60초 기다림
      const t0 = Date.now();
      await ctx.until(async () => (await dev.get(`/api/v1/projects/${kp}/defects?run=2`)).status === 410, 60000, 500);
      rec.note(`Run 17 성공 뒤 Run 2 보관 표시까지 약 ${((Date.now() - t0) / 1000).toFixed(1)}초`);
      const kr = await dev.data('GET', `/api/v1/projects/${kp}/runs`);
      const st = {};
      for (const n of [1, 2, 3, 17]) st[n] = (await dev.get(`/api/v1/projects/${kp}/defects?run=${n}`)).status;
      rec.check(kr.length >= 17 && st[1] === 410 && st[2] === 410 && st[3] === 200 && st[17] === 200, 'Run 17개 → 최근 15개(3~17) 상세 유지, Run 1·2는 410(상세 삭제 안내)', `runs ${kr.length}, 상태 ${JSON.stringify(st)}`, `defects?run=N (${kp})`);
    },
  },
  {
    n: 7, title: '규칙 — 규칙 설명 적재·검색, 규칙 끄기/켜기(개별·일괄), 분석에 반영',
    async fn(ctx) {
      const { rec, S } = ctx;
      const admin = await adminClient(ctx);
      const dev = await ctx.user('dev1');
      const meta = await dev.data('GET', '/api/v1/rules/meta');
      rec.check(JSON.stringify(meta).includes('ruff') && JSON.stringify(meta).includes('eslint'), '규칙 설명이 설치 직후 워커가 적재(ruff·ESLint)', JSON.stringify(meta).slice(0, 200), 'GET /api/v1/rules/meta');
      const s = await dev.get(`/api/v1/rules?tool=ruff&q=${encodeURIComponent('unused-import')}`);
      rec.check(s.status === 200 && s.json.data.some((r) => r.rule === 'F401'), '규칙 검색(unused-import) → F401', s.json?.data?.map((r) => r.rule).slice(0, 5).join(','), 'GET /api/v1/rules?q=');
      const pid = (S.p.rules = (await admin.data('POST', '/api/v1/projects', { name: 'rules', language: 'python' })).id);
      await admin.data('POST', `/api/v1/projects/${pid}/access`, { subject_type: 'user', login_id: 'dev1' });
      const zip = readFileSync(path.join(SAMPLES(ctx), 'zips/python_sample.zip'));
      await settle(ctx, dev, await upload(dev, pid, zip, 'r1.zip'));
      const n401 = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401`)).json.page.total;
      const off = await admin.req('PUT', '/api/v1/admin/rules/ruff/F401/override', { json: { enabled: false, expected_enabled: true, reason: 'acceptance' } });
      rec.check(off.status === 200, '개별: admin이 ruff F401 끄기', `${off.status} ${off.body.slice(0, 120)}`, 'PUT /api/v1/admin/rules/ruff/F401/override');
      const r2 = await settle(ctx, dev, await upload(dev, pid, zip, 'r2.zip'));
      const open401 = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401&status=open`)).json.page.total;
      rec.check(r2.analysis?.status === 'succeeded' && r2.analysis.counts.resolved === n401 && open401 === 0, `다음 분석에 반영: F401 ${n401}건 해결, 열린 F401 0`, `resolved ${r2.analysis?.counts?.resolved}, 열린 F401 ${open401}`, `analysis ${r2.analysis?.id}`);
      const pv = await admin.data('POST', '/api/v1/admin/rules/overrides/bulk/preview', { tool: 'ruff', action: 'enable', target: { mode: 'keys', keys: ['F401', 'E711'] } });
      const ap = await admin.req('POST', '/api/v1/admin/rules/overrides/bulk', { json: { tool: 'ruff', action: 'enable', target: { mode: 'keys', keys: ['F401', 'E711'] }, reason: 'acceptance bulk', expected_revision: pv.revision } });
      rec.check(ap.status === 200, '일괄: 미리보기 → 적용(F401·E711 켜기)', `preview changes ${pv.changes}, apply ${ap.status}`, 'POST /api/v1/admin/rules/overrides/bulk/preview·bulk');
      const r3 = await settle(ctx, dev, await upload(dev, pid, zip, 'r3.zip'));
      const open401b = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401&status=open`)).json.page.total;
      rec.check(open401b === n401 && (r3.analysis?.counts?.reopened ?? 0) >= n401, `다시 켜면 다음 분석에서 F401 ${n401}건 재오픈`, `열린 F401 ${open401b}, reopened ${r3.analysis?.counts?.reopened}, new ${r3.analysis?.counts?.new}`, `analysis ${r3.analysis?.id}`);
      const pa = await ctx.page('admin');
      await screen(pa, rec, '/rules', { lang: 'ko', theme: 'light', shot: 'S07_rules_admin' });
      await setPrefs(dev, 'en', 'dark');
      await screen(await ctx.page('dev1'), rec, '/rules/ruff/F401', { lang: 'en', theme: 'dark', shot: 'S07_rule_detail_en_dark' });
      await setPrefs(dev, 'ko', 'light');
    },
  },
  {
    n: 8, title: '내보내기·알림 — CSV/Excel(조건 반영), 앱 안 알림 생성·읽음',
    async fn(ctx) {
      const { rec, S } = ctx;
      const dev = await ctx.user('dev1');
      const dev2 = await ctx.user('dev2');
      const pid = S.p.def;
      const want = (await dev.get(`/api/v1/projects/${pid}/defects?rule=ruff:F401`)).json.page.total;
      const csv = await dev.req('POST', `/api/v1/projects/${pid}/defects/export`, { json: { format: 'csv', rule: 'ruff:F401' } });
      const lines = csv.buf.toString('utf8').replace(/^﻿/, '').trim().split(/\r?\n/);
      rec.check(csv.status === 200 && lines.length - 1 === want, 'CSV 내보내기(규칙 조건) 행 수 = 화면 건수', `${csv.status} ${csv.headers.get('content-type')} 행 ${lines.length - 1} / 목록 ${want}`, `POST /api/v1/projects/${pid}/defects/export`);
      const xlsx = await dev.req('POST', `/api/v1/projects/${pid}/defects/export`, { json: { format: 'xlsx' } });
      rec.check(xlsx.status === 200 && xlsx.buf.subarray(0, 2).toString() === 'PK', 'Excel(.xlsx) 내보내기', `${xlsx.status} ${xlsx.headers.get('content-type')} ${xlsx.buf.length}바이트 ${xlsx.headers.get('content-disposition')}`, '');
      const mineCsv = await dev2.req('POST', '/api/v1/my-defects/export', { json: { format: 'csv' } });
      rec.check(mineCsv.status === 200, '내 결함 내보내기(dev2)', mineCsv.status, 'POST /api/v1/my-defects/export');
      const rulesCsv = await dev.req('POST', '/api/v1/rules/export', { json: { format: 'csv', language: 'python' } });
      rec.check(rulesCsv.status === 200 && rulesCsv.buf.length > 1000, '규칙 목록 내보내기', `${rulesCsv.status} ${rulesCsv.buf.length}바이트`, 'POST /api/v1/rules/export');
      // 알림: dev2는 담당자 지정(시나리오 5), dev1은 분석 완료
      const n2 = await dev2.data('GET', '/api/v1/notifications');
      const c2 = await dev2.data('GET', '/api/v1/notifications/unread-count');
      const types = [...new Set(n2.map((n) => n.kind))];
      rec.check(n2.length > 0 && types.some((t) => /assign/.test(t)), 'dev2 알림: 담당자 지정 알림 생성', `${n2.length}건, 종류 ${types.join(',')}, 안 읽음 ${JSON.stringify(c2)}`, 'GET /api/v1/notifications');
      const n1 = await dev.data('GET', '/api/v1/notifications');
      rec.check(n1.some((n) => /analysis/.test(n.kind)), 'dev1 알림: 분석 완료·실패 알림', [...new Set(n1.map((n) => n.kind))].join(','), 'GET /api/v1/notifications');
      const first = n2.find((n) => !n.read_at) ?? n2[0];
      await dev2.data('POST', `/api/v1/notifications/${first.id}/read`);
      const c2b = await dev2.data('GET', '/api/v1/notifications/unread-count');
      const num = (x) => (typeof x === 'number' ? x : x?.count ?? x?.unread ?? NaN);
      rec.check(num(c2b) === num(c2) - 1, '하나 읽음 → 안 읽은 수 1 감소', `${JSON.stringify(c2)} → ${JSON.stringify(c2b)}`, 'POST /api/v1/notifications/{id}/read');
      await dev2.data('POST', '/api/v1/notifications/read-all');
      const c2c = await dev2.data('GET', '/api/v1/notifications/unread-count');
      rec.check(num(c2c) === 0, '모두 읽음 → 0', JSON.stringify(c2c), 'POST /api/v1/notifications/read-all');
      const p2 = await ctx.page('dev2');
      await setPrefs(dev2, 'en', 'dark');
      await screen(p2, rec, '/notifications', { lang: 'en', theme: 'dark', shot: 'S08_notifications_en_dark' });
      await setPrefs(dev2, 'ko', 'light');
    },
  },
];

const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
export async function adminClient(ctx) {
  return ctx.user('admin');
}
