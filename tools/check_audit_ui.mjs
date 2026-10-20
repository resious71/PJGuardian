// 감사 로그 화면 브라우저 점검(P30, 헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo)을 별도 포트에 띄우고 앱 코드로 감사 기록을 넣은 뒤 점검,
// 끝나면 서버를 멈추고 폴더를 지운다(실제 DB·설정·워커와 무관).
//   node tools/check_audit_ui.mjs [--port=8096] [--keep] [--no-shots]
// 흐름: 일반 사용자 — 화면·API 403, 메뉴 없음. admin — 메뉴 → 목록(최신순, 건수, 쪽 나누기), 조건(작업 접두사·행위자·검색·기간 직접 지정) 적용은 조회 단추·Enter로만,
//   조건이 주소에 반영(새로 고침·뒤로 가기·링크 공유), 상세 대화상자(가려진 값 — 비밀번호·토큰·이메일 원문 없음, Esc·포커스 복귀), 무결성 검사(정상 → 진행 100·결과),
//   CSV 내보내기(내려받기·X-Export-Rows·audit.export 기록), 수정·삭제 단추 없음, 한/영 × 라이트/다크 × 1280/1024 가로 스크롤 0, 콘솔 오류·CSP 위반 0.
// X17 사람이 읽는 설명: 여러 작업(사용자 역할·프로젝트·삭제된 대상·설정·백업·FAQ·실패·알 수 없는 작업)을 넣고 목록 "설명" 열(문장·작업 이름 작은 글씨·설명 없음 배지·
//   상세 단추 접근 이름에 요약), 상세(요약 → 바뀐 항목 표 → 누가·언제·어디서 → 대상 링크/삭제됨 → 결과 → 접힌 기술 정보·원본 JSON·복사 단추), 작업 선택지 사람 이름·분류,
//   CSV 마지막 열 "설명", 영어 상세. 캡처 X17_audit_list_1280·X17_audit_detail_1280·X17_audit_detail_en(--all-shots면 P30 때의 무결성·언어/테마 캡처도 같은 접두사로)
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8096);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const PREFIX = process.env.PJG_SHOT_PREFIX ?? 'X19';
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `audit_check_${randomBytes(4).toString('hex')}`);
const PW = `Demo-${randomBytes(9).toString('base64url')}1!`;
const BASE = `http://127.0.0.1:${PORT}`;
const INI = path.join(work, 'config.ini');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
let server = null;
let browser = null;
process.on('exit', () => {
  try { server?.kill(); } catch { /* 이미 끝남 */ }
  if (!opt('keep')) { try { rmSync(work, { recursive: true, force: true, maxRetries: 20, retryDelay: 250 }); } catch (e) { console.error(`점검 폴더를 지우지 못함: ${e.code}`); } }
});

mkdirSync(work, { recursive: true });
execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'pipe', 'inherit'] });
// 감사 기록 120건(앱 코드로 — 해시 체인 그대로): 여러 작업 묶음, 민감한 값이 든 예전 형식 행 하나(화면 단계 마스킹 확인)
execFileSync(PHP, ['-d', 'extension=intl', '-r', `require $argv[1] . '/src/bootstrap.php';
$app = PJGuardian\\App::create(PJG_ROOT, PJGuardian\\Support\\Config::load($argv[2]));
$a = $app->audit();
$acts = ['user.update', 'group.create', 'faq.create', 'project.update', 'repo.test', 'config.update'];
for ($i = 0; $i < 120; $i++) { $a->record(PJGuardian\\Services\\Actor::labeled($i % 3 === 0 ? 'demo-admin' : 'ops-bot'), $acts[$i % 6], 'item', (string) $i, ['n' => $i, 'note' => $i === 7 ? '50%_할인 표시' : 'ok']); }
$a->record(PJGuardian\\Services\\Actor::labeled('legacy'), 'user.update', 'user', '42', ['password' => 'Sup3rSecret!', 'email' => 'kim.lee@example.com', 'note' => 'token=abcdef123456']);
$db = $app->db();
$adm = $db->one("SELECT id, login_id FROM users WHERE login_id = 'demo-admin'");
$jor = (string) $db->value("SELECT id FROM users WHERE login_id = 'jordan'");
$prj = (string) $db->value("SELECT id FROM projects WHERE name = 'sample-api'");
$me = PJGuardian\\Services\\Actor::fromUser($adm, '10.0.0.5');
$a->record($me, 'future.thing', 'widget', '3', ['size' => 10]);
$a->record($me, 'group.member_add', 'group', '555', ['user_id' => (int) $jor, 'login_id' => 'jordan', 'actor_role' => 'admin']);
$a->record($me, 'repo.test', 'project', $prj, ['ok' => false, 'code' => 'repo_auth_failed', 'draft' => false]);
$a->record($me, 'faq.publish', 'faq', '777', ['published' => false, 'question' => '비밀번호를 잊었어요']);
$a->record($me, 'backup.create', 'backup', null, ['kind' => 'manual', 'result' => 'requested', 'snapshots' => true]);
$a->record($me, 'backup.create', 'backup', 'PJGuardian_backup_20261002_210000_manual.zip', ['kind' => 'manual', 'result' => 'ok', 'size' => 52428800, 'ms' => 4200]);
$a->record($me, 'config.update', 'config', 'upload.failed_zip_retention', ['old' => '1d', 'new' => '3d', 'old_source' => 'default', 'file' => 'settings.local.ini']);
$a->record($me, 'project.delete', 'project', '9999', ['name' => 'Old app', 'language' => 'javascript', 'analyses' => 14, 'defects' => 230, 'snapshot_bytes' => 5242880]);
$a->record($me, 'project.update', 'project', $prj, ['description' => ['from' => 'Sample API service', 'to' => '결제 API'], 'exclude_patterns' => ['from' => ['build/'], 'to' => ['build/', 'dist/']]]);
$a->record($me, 'user.role_change', 'user', $jor, ['from' => 'user', 'to' => 'admin']);
echo "seeded\\n";`, ROOT, INI], { stdio: ['ignore', 'pipe', 'inherit'] });
server = spawn(PHP, ['-d', 'extension=intl', '-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: INI }, stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}

async function session(login) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[34]/.test(m.text())) problems.push(`console.${m.type()}(${login}): ${m.text()}`);
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
const listState = (page) => page.evaluate(() => ({
  url: location.search, count: document.querySelector('[data-audit-count]')?.textContent.trim() ?? '',
  rows: [...document.querySelectorAll('[data-rows] tr')].map((tr) => tr.dataset.id),
  actions: [...document.querySelectorAll('[data-rows] .audit__action')].map((n) => n.textContent),
  pager: document.querySelector('[data-pager] .pagination__info')?.textContent ?? '',
  scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
}));
const waitList = (page, before) => page.waitForFunction((b) => {
  const l = document.querySelector('[data-loading]');
  return l && l.hidden && (document.querySelector('[data-audit-count]')?.textContent ?? '') !== '' && (b === null || location.search + document.querySelector('[data-audit-count]').textContent + (document.querySelector('[data-rows] tr')?.dataset.id ?? '') !== b);
}, { timeout: 15000 }, before);
const sig = (page) => page.evaluate(() => location.search + (document.querySelector('[data-audit-count]')?.textContent ?? '') + (document.querySelector('[data-rows] tr')?.dataset.id ?? ''));

// X19 표 모양: 열 순서(규칙·설명·행위자·대상·결과·시각·요청 ID·상세), 규칙 열 윗줄/아랫줄, 설명 열에 작업 키 없음, 시간 형식·툴팁, 결과 아이콘(모양·색 서로 다름) + 글자
const tableState = (page) => page.evaluate(() => {
  const heads = [...document.querySelectorAll('[data-table] thead th')].map((th) => th.textContent.trim());
  const rows = [...document.querySelectorAll('[data-rows] tr')].map((tr) => {
    const c = tr.children;
    const res = c[4]?.querySelector('.audit__result');
    const svg = res?.querySelector('svg');
    const timeEl = c[5]?.querySelector('time');
    return {
      action: tr.querySelector('[data-rule]')?.dataset.rule ?? null,
      ruleTop: c[0]?.querySelector('[data-unknown]') ? '#badge' : (c[0]?.querySelector('.audit__action')?.getAttribute('title') ?? ''),
      ruleKey: c[0]?.querySelector('.audit__action')?.textContent ?? '',
      summaryHasCode: !!c[1]?.querySelector('code, .audit__action'), summaryText: c[1]?.textContent ?? '',
      outcome: tr.dataset.outcome, resultText: res?.textContent.trim() ?? '', resultIcon: svg?.dataset.icon ?? null,
      resultColor: svg ? getComputedStyle(svg).color : null, resultHidden: svg?.getAttribute('aria-hidden') === 'true',
      resultOneLine: res ? res.getBoundingClientRect().height < 26 && getComputedStyle(c[4]).whiteSpace === 'nowrap' : false,
      time: timeEl?.textContent ?? '', timeTitle: timeEl?.getAttribute('title') ?? '', datetime: timeEl?.getAttribute('datetime') ?? '',
    };
  });
  return { heads, rows };
});
const HEADS = {
  ko: ['규칙', '설명', '행위자', '대상', '결과', '시각', '요청 ID', '상세'],
  en: ['Rule', 'Description', 'Actor', 'Target', 'Result', 'Time', 'Request ID', 'Details'],
};
const tableShape = (st, lang, label) => {
  check(JSON.stringify(st.heads) === JSON.stringify(HEADS[lang]), `열 순서 ${label}`, st.heads);
  const bad = st.rows.filter((x) => !(/^\d{2}\/\d{2}\/\d{2} \d{2}:\d{2}$/.test(x.time) && x.timeTitle.includes(x.datetime) && x.datetime !== ''
    && !x.summaryHasCode && x.ruleKey === x.action && x.ruleTop !== '' && x.resultIcon && x.resultHidden && x.resultText !== '' && x.resultOneLine));
  check(bad.length === 0, `행 모양(규칙 두 줄·설명에 작업 키 없음·시간 형식·툴팁·결과 아이콘 + 글자 한 줄) ${label}`, bad.slice(0, 3));
  const kinds = {};
  for (const x of st.rows) kinds[x.outcome] ??= { icon: x.resultIcon, color: x.resultColor, text: x.resultText };
  const k = Object.values(kinds);
  check(['success', 'failure', 'requested'].every((o) => kinds[o]) && new Set(k.map((v) => v.icon)).size === k.length && new Set(k.map((v) => v.color)).size === k.length,
    `결과 아이콘 모양·색이 서로 다름(성공·실패·요청됨) ${label}`, kinds);
  return kinds;
};
try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const user = await session('alex');
  const admin = await session('demo-admin');
  check(await prefs(user, 'ko', 'light') === 200 && await prefs(admin, 'ko', 'light') === 200, '언어 ko', null);

  // 1. 일반 사용자
  const res = await user.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
  r.user = { page: res.status(), api: await user.evaluate(async () => (await fetch('/api/v1/admin/audit-logs')).status), menu: await user.evaluate(() => !!document.querySelector('[data-nav="admin.audit"]')) };
  check(r.user.page === 403 && r.user.api === 403 && !r.user.menu, '일반 사용자 403·메뉴 없음', r.user);

  // 2. 메뉴 → 목록
  await admin.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('a[data-nav="admin.audit"]')]);
  await waitList(admin, null);
  r.list = await listState(admin);
  r.noWrite = await admin.evaluate(() => document.querySelectorAll('[data-audit] [data-delete], [data-audit] [data-edit], [data-audit] .btn--danger, [data-audit] .btn--danger-outline').length);
  check(r.list.rows.length === 50 && /^1[0-9]{2}건$/.test(r.list.count) && r.list.pager.includes('/') && r.noWrite === 0, '목록 50행·건수·쪽·수정/삭제 없음', { ...r.list, noWrite: r.noWrite });

  // 2b. X17 설명: 목록 "설명" 열·작업 이름 작은 글씨·설명 없음 배지·결과 아이콘·대상 이름, 상세 단추 접근 이름에 요약
  r.desc = await admin.evaluate(() => [...document.querySelectorAll('[data-rows] tr')].slice(0, 10).map((tr) => ({
    summary: tr.querySelector('[data-summary]')?.textContent ?? '', code: tr.querySelector('.audit__action')?.textContent ?? '',
    unknown: !!tr.querySelector('[data-unknown]'), target: tr.querySelector('.audit__target')?.textContent ?? '', outcome: tr.dataset.outcome,
    failIcon: !!tr.querySelector('.audit__outcome svg'), aria: tr.querySelector('[data-detail]')?.getAttribute('aria-label') ?? '',
    clamp: getComputedStyle(tr.querySelector('[data-summary]')).webkitLineClamp,
  })));
  const by = Object.fromEntries(r.desc.map((d) => [d.code, d]));
  check(by['user.role_change']?.summary === '관리자 demo-admin이 사용자 Jordan Lee (jordan)의 역할을 일반 사용자 → 관리자로 바꿨습니다.', '설명 열: 역할 변경 문장', by['user.role_change']);
  check(by['project.delete']?.summary.includes('프로젝트 Old app을 삭제했습니다') && by['project.delete']?.target === 'Old app', '설명 열: 삭제된 프로젝트(기록된 이름)', by['project.delete']);
  check(by['config.update']?.summary === '관리자 demo-admin이 설정 실패한 업로드 zip 보관을 1d → 3d로 바꿨습니다.', '설명 열: 설정 변경', by['config.update']);
  check(by['faq.publish']?.target === '비밀번호를 잊었어요', '설명 열: 지운 FAQ는 기록된 질문', by['faq.publish']);
  check(by['group.member_add']?.target === '삭제된 항목(#555)' && by['group.member_add']?.summary === '관리자 demo-admin이 그룹 삭제된 항목(#555)에 jordan을 구성원으로 추가했습니다.', '설명 열: 이름 없는 삭제된 대상', by['group.member_add']);
  check(by['future.thing']?.unknown && by['future.thing']?.summary === '작업 future.thing이 기록되었습니다.', '알 수 없는 작업: 일반 문장 + 설명 없음 배지', by['future.thing']);
  check(by['repo.test']?.outcome === 'failure' && by['repo.test']?.failIcon, '실패는 아이콘 + 글자', by['repo.test']);
  check(r.desc.every((d) => d.aria.includes(d.summary) && d.clamp === '2'), '상세 단추 접근 이름에 요약·두 줄 말줄임', r.desc.map((d) => [d.code, d.clamp]));
  r.table = await tableState(admin);
  const roleRow = r.table.rows.find((x) => x.action === 'user.role_change');
  check(roleRow?.ruleTop === '사용자 — 역할 변경' && roleRow?.ruleKey === 'user.role_change' && roleRow?.time.length === 14, '규칙 열: 작업 키(툴팁에 분류 — 작업 이름)', roleRow);
  check(r.table.rows.find((x) => x.action === 'future.thing')?.ruleTop === '#badge', '알 수 없는 작업: 규칙 열에 설명 없음 배지', null);
  r.resultKinds = tableShape(r.table, 'ko', 'ko light 1280');
  // 작업 선택지: 분류 optgroup + "분류 — 이름 (작업)"
  r.options = await admin.evaluate(() => ({
    groups: [...document.querySelectorAll('[data-action] optgroup')].map((g) => g.label),
    role: [...document.querySelectorAll('[data-action] option')].find((o) => o.value === 'user.role_change')?.textContent ?? '',
    prefix: [...document.querySelectorAll('[data-action] option')].find((o) => o.value === 'backup.')?.textContent ?? '',
  }));
  check(r.options.groups.includes('사용자') && r.options.groups.includes('백업') && r.options.role === '사용자 — 역할 변경 (user.role_change)' && r.options.prefix === '백업 전체 (backup.*)',
    '작업 선택지 사람 이름·분류', r.options);
  // 상세(역할 변경): 요약 → 바뀐 항목 표 → … → 접힌 기술 정보(원본 JSON·복사 단추), 대상 링크
  const openRow = async (code) => {
    await admin.evaluate((c) => [...document.querySelectorAll('[data-rows] tr')].find((tr) => tr.querySelector('.audit__action')?.textContent === c)?.querySelector('[data-detail]')?.click(), code);
    await admin.waitForSelector('dialog[open] [data-tech]', { timeout: 10000 });
    return admin.evaluate(() => {
      const d = document.querySelector('dialog[open]');
      return {
        summary: d.querySelector('[data-summary]')?.textContent ?? '', sections: [...d.querySelectorAll('[data-section]')].map((n) => n.dataset.section),
        changes: [...d.querySelectorAll('[data-changes] tbody tr')].map((tr) => [...tr.children].map((c) => c.textContent)),
        techOpen: d.querySelector('[data-tech]').open, raw: d.querySelector('[data-raw]')?.textContent ?? '', copy: !!d.querySelector('[data-copy-json]'),
        link: d.querySelector('[data-target-link]')?.getAttribute('href') ?? null, unknown: !!d.querySelector('[data-unknown]'), text: d.textContent,
        scroll: d.scrollWidth <= d.clientWidth + 1,
      };
    });
  };
  const closeDialog = async () => { await admin.keyboard.press('Escape'); await sleep(250); };
  r.detailRole = await openRow('user.role_change');
  check(r.detailRole.summary.startsWith('관리자 demo-admin이') && JSON.stringify(r.detailRole.sections) === JSON.stringify(['changes', 'who', 'target', 'result'])
    && JSON.stringify(r.detailRole.changes) === JSON.stringify([['역할', '일반 사용자', '관리자']]) && !r.detailRole.techOpen && r.detailRole.copy
    && r.detailRole.raw.includes('"from": "user"') && /^\/admin\/users$/.test(r.detailRole.link ?? ''), '상세: 요약·바뀐 항목 표·구역 순서·기술 정보 접힘·대상 링크', r.detailRole);
  await admin.evaluate(() => { document.querySelector('dialog[open] [data-tech]').open = true; });
  if (opt('all-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_detail_1280.png`) });
  await closeDialog();
  r.detailPrj = await openRow('project.update');
  check(r.detailPrj.changes.length === 2 && r.detailPrj.changes[1][0] === '제외 패턴' && r.detailPrj.changes[1][2] === 'build/, dist/' && r.detailPrj.sections.includes('changes'), '상세: 여러 항목 표', r.detailPrj.changes);
  await closeDialog();
  r.detailDel = await openRow('project.delete');
  check(r.detailDel.link === null && r.detailDel.text.includes('삭제되었거나 찾을 수 없는 대상') && r.detailDel.sections.includes('context') && !r.detailDel.sections.includes('changes'), '상세: 삭제된 대상 링크 없음·바뀐 항목 표 없음', r.detailDel);
  await closeDialog();
  r.detailFail = await openRow('repo.test');
  check(r.detailFail.text.includes('repo_auth_failed') && r.detailFail.text.includes('오류 코드 풀이 보기'), '상세: 실패 이유·가이드 링크', r.detailFail.text.slice(0, 300));
  await closeDialog();
  r.detailUnknown = await openRow('future.thing');
  check(r.detailUnknown.unknown && r.detailUnknown.raw.includes('"size": 10'), '상세: 설명 없음 배지·원본', { unknown: r.detailUnknown.unknown });
  await closeDialog();
  // CSV 마지막 열 "설명"(화면 언어)
  r.csv = await admin.evaluate(async () => {
    const res = await fetch('/api/v1/admin/audit-logs/export', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ action: 'user.role_change' }) });
    const text = await res.text();
    const lines = text.replace(/^﻿/, '').split(/\r?\n/).filter(Boolean);
    return { status: res.status, head: lines[0], row: lines[1] ?? '' };
  });
  check(r.csv.status === 200 && r.csv.head.endsWith(',설명') && r.csv.row.includes('역할을 일반 사용자 → 관리자로 바꿨습니다'), 'CSV 설명 열', r.csv);

  // 3. 조건: 입력만으로는 요청 없음 → 조회 단추로 적용, 주소 반영
  let reqs = 0;
  const onReq = (q) => { if (q.url().includes('/api/v1/admin/audit-logs?') || q.url().endsWith('/api/v1/admin/audit-logs')) reqs += 1; };
  admin.on('request', onReq);
  await admin.select('[data-action]', 'faq.');
  await admin.type('[data-actor]', 'ops');
  await sleep(500);
  r.typed = reqs;
  let before = await sig(admin);
  await admin.click('[data-audit-filters] button[type=submit]');
  await waitList(admin, before);
  admin.off('request', onReq);
  r.filtered = await listState(admin);
  check(r.typed === 0 && r.filtered.url.includes('action=faq.') && r.filtered.url.includes('actor=ops') && r.filtered.actions.length > 0 && r.filtered.actions.every((a) => a === 'faq.create'),
    '조건 적용(조회 단추만)·주소 반영', { typed: r.typed, ...r.filtered });
  // 새로 고침해도 같은 조건, 뒤로 가기 = 처음 목록
  await admin.reload({ waitUntil: 'networkidle0' });
  await waitList(admin, null);
  r.reloaded = await admin.evaluate(() => ({ action: document.querySelector('[data-action]').value, actor: document.querySelector('[data-actor]').value, url: location.search }));
  before = await sig(admin);
  await admin.goBack({ waitUntil: 'networkidle0' });
  await waitList(admin, before).catch(() => null);
  r.back = await listState(admin);
  check(r.reloaded.action === 'faq.' && r.reloaded.actor === 'ops' && r.back.url === '' && r.back.rows.length === 50, '새로 고침·뒤로 가기', { reloaded: r.reloaded, back: r.back.url });
  // 검색(%·_는 글자) + Enter
  await admin.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
  await waitList(admin, null);
  before = await sig(admin);
  await admin.type('[data-q]', '50%_할');
  await admin.keyboard.press('Enter');
  await waitList(admin, before);
  r.search = await listState(admin);
  check(r.search.rows.length === 1, '검색(Enter, %·_ 글자)', r.search);
  // 직접 기간: 시작 > 종료 → 안내
  await admin.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
  await waitList(admin, null);
  await admin.select('[data-period]', 'custom');
  await admin.evaluate(() => { document.querySelector('[data-from]').value = '2026-10-05'; document.querySelector('[data-to]').value = '2026-10-01'; });
  await admin.click('[data-audit-filters] button[type=submit]');
  await sleep(300);
  r.badDate = await admin.evaluate(() => ({ err: document.querySelector('[data-error]').hidden ? '' : document.querySelector('[data-error]').textContent, custom: !document.querySelector('[data-custom]').hidden }));
  check(r.badDate.err.includes('시작일과 종료일') && r.badDate.custom, '기간 오류 안내', r.badDate);

  // 4. 상세: 가려진 값, Esc·포커스 복귀
  await admin.goto(`${BASE}/admin/audit-logs?actor=legacy`, { waitUntil: 'networkidle0' });
  await waitList(admin, null);
  await admin.focus('[data-rows] [data-detail]');
  await admin.keyboard.press('Enter');
  await admin.waitForSelector('dialog[open] [data-tech]', { timeout: 10000 });
  r.detail = await admin.evaluate(() => { const d = document.querySelector('dialog[open]'); return { text: d.textContent, title: d.querySelector('.dialog__title').textContent }; });
  await admin.keyboard.press('Escape');
  await sleep(300);
  r.detailBack = await admin.evaluate(() => ({ open: !!document.querySelector('dialog[open]'), focus: document.activeElement?.hasAttribute('data-detail') }));
  check(!/Sup3rSecret|kim\.lee@|abcdef123456/.test(r.detail.text) && r.detail.text.includes('k***@example.com') && !r.detailBack.open && r.detailBack.focus,
    '상세: 비밀 원문 없음·Esc·포커스 복귀', { title: r.detail.title, back: r.detailBack });

  // 5. 무결성 검사
  await admin.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
  await waitList(admin, null);
  await admin.click('[data-integrity]');
  await admin.waitForFunction(() => ['ok', 'broken', 'error'].includes(document.querySelector('[data-integrity-status]')?.dataset.result ?? ''), { timeout: 20000 });
  r.integrity = await admin.evaluate(() => ({ result: document.querySelector('[data-integrity-status]').dataset.result, text: document.querySelector('[data-integrity-status]').textContent,
    progress: document.querySelector('[data-integrity-progress]').value, range: document.querySelector('[data-integrity-range]').textContent }));
  check(r.integrity.result === 'ok' && r.integrity.progress === 100 && /정상/.test(r.integrity.text) && r.integrity.range.includes('남은 범위'), '무결성 검사 정상', r.integrity);
  if (opt('all-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_integrity.png`) });

  // 6. CSV 내보내기: 응답(파일)·건수·audit.export 기록
  const exportedBefore = await admin.evaluate(async () => (await (await fetch('/api/v1/admin/audit-logs?action=audit.export')).json()).page.total);
  const exportRes = admin.waitForResponse((x) => x.url().includes('/api/v1/admin/audit-logs/export'), { timeout: 20000 });
  await admin.click('[data-export-format="csv"]');
  const er = await exportRes;
  r.export = { status: er.status(), rows: er.headers()['x-export-rows'], type: er.headers()['content-type'], disp: er.headers()['content-disposition'] };
  await sleep(500);
  const exported = await admin.evaluate(async () => (await (await fetch('/api/v1/admin/audit-logs?action=audit.export')).json()).page.total);
  check(r.export.status === 200 && Number(r.export.rows) >= 121 && /text\/csv/.test(r.export.type) && /PJGuardian_audit_/.test(r.export.disp) && exported === exportedBefore + 1, 'CSV 내보내기·audit.export', { ...r.export, exported, exportedBefore });

  // 7. 한/영 × 라이트/다크 × 1280/1024
  r.matrix = {};
  for (const lang of ['ko', 'en']) {
    for (const theme of ['light', 'dark']) {
      check(await prefs(admin, lang, theme) === 200, `언어 ${lang} ${theme}`, null);
      for (const width of [1280, 1024]) {
        await admin.setViewport({ width, height: 900 });
        await admin.goto(`${BASE}/admin/audit-logs`, { waitUntil: 'networkidle0' });
        await waitList(admin, null);
        const s = await listState(admin);
        const hangul = lang === 'en' ? await admin.evaluate(() => /[가-힣]/.test([...document.querySelectorAll('[data-audit] label, [data-audit] th, [data-audit] button, [data-audit] h1, .page-header')].map((n) => n.textContent).join(' '))) : false;
        r.matrix[`${lang}_${theme}_${width}`] = { scroll: s.scroll, hangul };
        tableShape(await tableState(admin), lang, `${lang} ${theme} ${width}`);
        if (!opt('no-shots') && theme === 'light' && lang === 'ko') await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_list_${width}.png`) });
        if (!opt('no-shots') && theme === 'light' && lang === 'en' && width === 1280) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_list_en.png`) });
        if (!opt('no-shots') && theme === 'dark' && lang === 'ko' && width === 1280) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_list_dark.png`) });
        if (lang === 'en' && theme === 'light' && width === 1280) {
          r.enDetail = await openRow('user.role_change');
          check(r.enDetail.summary === 'Admin demo-admin changed the role of user Jordan Lee (jordan) from Regular user to Admin.'
            && JSON.stringify(r.enDetail.changes) === JSON.stringify([['Role', 'Regular user', 'Admin']]) && r.enDetail.scroll && !/[가-힣]/.test(r.enDetail.text.replace(/비밀번호를 잊었어요|결제 API/g, '')), '영어 상세', r.enDetail);
          await admin.evaluate(() => { document.querySelector('dialog[open] [data-tech]').open = true; });
          if (opt('all-shots')) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_detail_en.png`) });
          await closeDialog();
        }
        check(s.scroll && !hangul, `가로 스크롤·영어 틀 ${lang} ${theme} ${width}`, r.matrix[`${lang}_${theme}_${width}`]);
        if (opt('all-shots') && width === 1280) await admin.screenshot({ path: path.join(OUT, `${PREFIX}_audit_${lang}_${theme}.png`) });
      }
    }
  }
  await prefs(admin, 'ko', 'light');
} catch (e) {
  problems.push(`예외: ${e.message}`);
} finally {
  await browser?.close();
}
console.log(JSON.stringify({ r, csp, problems }, null, 1));
console.log(problems.length || csp.length ? `실패 ${problems.length + csp.length}건` : '감사 로그 화면 점검 통과');
process.exit(problems.length || csp.length ? 1 : 0);
