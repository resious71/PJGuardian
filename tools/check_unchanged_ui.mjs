// 성공(변경 없음) 브라우저 점검(P27, 헤드리스 Chrome). 격리 서버·DB 사본(021 적용 + 점검용 암호화 키) + 격리 워커 + 로컬 Git HTTP 저장소에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_unchanged_ui.mjs <기본 주소> <저장소 프로젝트(아직 분석 없음)> <일반 사용자> [관리자] --phase=1|2
// 1단계(실제로 분석·마감하므로 사본 전용):
//   - 프로젝트 화면 "지금 가져와 분석" → 처음 분석(Run 1) → 다시 누름 → 워커가 변경 없음으로 마감 → "변경이 없습니다. 그래도 분석할까요?"
//     (취소가 기본 초점, Esc = 취소 → 아무것도 만들지 않음, 초점이 버튼으로 돌아옴) → 다시 누름 → 확인 → 강제 분석(Run 2)
// (그 사이 주기 확인을 여러 번 기록: 점검 준비 스크립트가 FixedClock 앱으로 스케줄러 경로를 부른다)
// 3단계(P27C): 실패 뒤 변경 없음 확인이 있어도 대시보드 실패 프로젝트·프로젝트 최근 분석 카드는 실패한 실제 분석, 이력에는 변경 없음 행. 캡처 P27C_*.png
// 2단계:
//   - 이력: "성공(변경 없음)" 배지(아이콘 + 글자), "N회 확인 · 마지막 확인 yy-mm-dd HH:mm", "기준 Run N" 링크(전체 문장은 툴팁), Run 열 "Run — · 분석 #id", 비교 버튼 끔,
//     "변경 없음 숨기기"(주소 unchanged=hide, 새로 고침 복원), 추이 차트에 변경 없음 없음
//   - 분석 상세: 제목·배지·"변경 없음 — 리비전 …은 Run N(…)에서 이미 분석했습니다" 링크·확인 횟수
//   - 프로젝트 화면: 마지막 분석한 커밋(Run 2) 옆 "마지막 확인" 시각·결과
//   - 대시보드 추이 API: 변경 없음 점 없음, 일반 사용자: 배지 보임·버튼 없음·force 403
//   - 가로 스크롤: 1280은 0, 1024는 결함 표 화면 0·그 밖은 앱 최소 너비(1280px, 명세 1)만큼만. 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P27_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const phase = (process.argv.find((a) => a.startsWith('--phase=')) ?? '--phase=1').slice(8);
const [baseArg, project, userLogin, adminLogin = 'admin'] = args;
if (!userLogin || !process.env.PJG_PASSWORD || !['1', '2', '3'].includes(phase)) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_unchanged_ui.mjs <기본 주소> <저장소 프로젝트> <일반 사용자> [관리자] --phase=1|2|3');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (403|409)/.test(m.text())) return; // 의도한 오류(일반 사용자 403, 변경 없음 비교 409)
    else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return { browser, page };
}
const api = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }, body: b === undefined ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body);
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.trim()).catch(() => null);
const at = async (page, width, shot) => {
  await page.setViewport({ width, height: 900 });
  await sleep(200);
  r[`scroll_${shot}`] = await scrollX(page);
  // 앱 최소 너비 1280px(명세 1 데스크톱 전용, base.css). 결함 표가 있는 화면만 1024px까지 풀려 있다(P21B) → 그 밖의 화면은 최소 너비만큼만 허용(내용이 더 넓히면 실패)
  const allowed = await page.evaluate(() => (document.body.matches(':has(.defects-table)') ? 0 : Math.max(0, 1280 - document.documentElement.clientWidth)));
  check(r[`scroll_${shot}`] <= allowed, `가로 스크롤 ${shot}(허용 ${allowed})`, r[`scroll_${shot}`]);
  await page.screenshot({ path: path.join(OUT, shot.startsWith('p27c_') ? `P27C_${shot.slice(5)}.png` : `P27_${shot}.png`), fullPage: true });
};
const analyses = async (page) => (await api(page, 'GET', `/api/v1/projects/${project}/analyses?size=50`)).json?.data ?? [];
async function waitDone(page, id, seconds = 180) {
  for (let i = 0; i < seconds; i++) {
    const a = (await api(page, 'GET', `/api/v1/analyses/${id}`)).json?.data;
    if (a && !['queued', 'running'].includes(a.status)) return a;
    await sleep(1000);
  }
  return null;
}
const dialogOpen = (page) => page.evaluate(() => !!document.querySelector('dialog[open]'));

const admin = await session(adminLogin);
const a = admin.page;
try {
  if (phase === '1') {
    await a.goto(`${BASE}/projects/${project}`, { waitUntil: 'networkidle0' });
    await a.waitForFunction(() => !document.querySelector('[data-repo-status]').hidden && !document.querySelector('[data-repo-fetch]').disabled, { timeout: 15000 });
    r.before = { lastRevision: await text(a, '[data-repo-last-revision]'), lastCheck: await text(a, '[data-repo-last-check]') };
    check(r.before.lastCheck === '—', '처음에는 마지막 확인 없음', r.before);
    // 1) 처음 분석
    await a.click('[data-repo-fetch]');
    await a.waitForFunction(() => /변경 여부를 확인/.test(document.querySelector('[data-repo-message]')?.textContent ?? ''), { timeout: 10000 });
    r.checkingMsg = await text(a, '[data-repo-message]');
    const first = (await analyses(a))[0];
    const done1 = await waitDone(a, first.id);
    r.first = { id: first.id, status: done1?.status, run: done1?.run, unchanged: done1?.unchanged };
    check(done1?.status === 'succeeded' && done1.run === 1 && !done1.unchanged, '처음은 분석(Run 1)', r.first);
    await sleep(2500); // 화면의 지켜보기가 끝나기를
    // 2) 같은 커밋 → 변경 없음 → 확인 대화상자 → Esc(취소)
    await a.reload({ waitUntil: 'networkidle0' });
    await a.waitForFunction(() => !document.querySelector('[data-repo-fetch]').disabled, { timeout: 15000 });
    await a.click('[data-repo-fetch]');
    await a.waitForFunction(() => !!document.querySelector('dialog[open]'), { timeout: 90000 });
    r.dialog = await a.evaluate(() => {
      const d = document.querySelector('dialog[open]');
      return { title: d.querySelector('.dialog__title')?.textContent, message: d.querySelector('p')?.textContent, focus: document.activeElement?.textContent?.trim(),
        buttons: [...d.querySelectorAll('button')].map((b) => b.textContent.trim()) };
    });
    check(r.dialog.title === '변경 없음' && /변경이 없습니다\. 그래도 분석할까요\?/.test(r.dialog.message ?? '') && r.dialog.focus === '취소', '확인 대화상자(취소가 기본 초점)', r.dialog);
    r.msgBehind = await text(a, '[data-repo-message]');
    check(/변경이 없어 분석을 생략했습니다/.test(r.msgBehind ?? ''), '즉시 안내 "변경이 없어 분석을 생략했습니다"', r.msgBehind);
    await a.screenshot({ path: path.join(OUT, 'P27_force_dialog.png') });
    await a.keyboard.press('Tab');
    r.tabFocus = await a.evaluate(() => document.activeElement?.textContent?.trim());
    await a.keyboard.press('Tab');
    r.tabWrap = await a.evaluate(() => document.activeElement?.textContent?.trim());
    check(r.tabFocus === '그래도 분석' && r.tabWrap === '취소', 'Tab 순환(대화상자 안에서만)', [r.tabFocus, r.tabWrap]);
    const countBefore = (await analyses(a)).length;
    await a.keyboard.press('Escape');
    await a.waitForFunction(() => !document.querySelector('dialog[open]'), { timeout: 5000 });
    await sleep(1500); // Esc의 close 이벤트는 다음 작업에서 온다
    r.afterEsc = { focus: await a.evaluate(() => document.activeElement?.matches('[data-repo-fetch]')), message: await text(a, '[data-repo-message]') };
    const list = await analyses(a);
    r.afterEsc.count = [countBefore, list.length];
    r.unchangedRow = { id: list[0].id, status: list[0].status, unchanged: list[0].unchanged, run: list[0].run, base: list[0].unchanged_base };
    check(r.afterEsc.focus && /분석하지 않았습니다/.test(r.afterEsc.message ?? '') && list.length === countBefore && !list.some((x) => ['queued', 'running'].includes(x.status)),
      'Esc = 취소 → 아무것도 만들지 않고 초점 복귀', r.afterEsc);
    check(r.unchangedRow.unchanged && r.unchangedRow.run === null && r.unchangedRow.base?.analysis_id === first.id, '변경 없음 행(Run 없음, 기준 = Run 1)', r.unchangedRow);
    // 3) 다시 → 확인 → 강제 분석(Run 2)
    await a.click('[data-repo-fetch]');
    await a.waitForFunction(() => !!document.querySelector('dialog[open]'), { timeout: 90000 });
    await a.evaluate(() => [...document.querySelectorAll('dialog[open] button')].find((b) => b.textContent.trim() === '그래도 분석').click());
    await a.waitForFunction(() => /강제 분석 #\d+을 대기열에/.test(document.querySelector('[data-repo-message]')?.textContent ?? ''), { timeout: 10000 });
    const forced = (await analyses(a))[0];
    const done2 = await waitDone(a, forced.id);
    r.forced = { id: forced.id, status: done2?.status, run: done2?.run, force: done2?.force_analysis, unchanged: done2?.unchanged };
    check(done2?.status === 'succeeded' && done2.run === 2 && done2.force_analysis && !done2.unchanged, '강제 분석 → 새 Run', r.forced);
    // 감사: 강제 분석 기록은 서버 쪽(자동 시험) — 여기서는 화면만
  } else if (phase === '3') {
    // ---- P27C: 실패 뒤 변경 없음 확인 → 실패 프로젝트 유지(준비: 실패 분석 + 주기 확인을 시계 주입으로 기록) ----
    const s = (await api(a, 'GET', '/api/v1/dashboard/summary')).json?.data;
    r.failedProjects = (s?.failed_projects ?? []).map((f) => [f.project_id, f.analysis_id, f.failure_code]);
    const list = await analyses(a);
    r.rows = list.slice(0, 4).map((x) => [x.id, x.status, x.unchanged]);
    const realFailed = list.find((x) => !x.unchanged);
    check(list[0]?.unchanged && realFailed?.status === 'failed', '준비: 최근 행 = 변경 없음, 그 앞 실제 분석 = 실패', r.rows);
    check(r.failedProjects.some(([pid, aid]) => pid === Number(project) && aid === realFailed?.id), '대시보드 실패 프로젝트 유지(API)', r.failedProjects);
    await a.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
    await sleep(500);
    r.tile = await a.evaluate(() => [...document.querySelectorAll('.stat-tile--wide')].map((n) => n.innerText.replace(/\s+/g, ' ').trim()).find((x) => x.includes('실패')));
    check((r.tile ?? '').includes('P27'), '대시보드 실패 타일에 프로젝트', r.tile);
    await at(a, 1280, 'p27c_dashboard_failed');
    await a.setViewport({ width: 1280, height: 900 });
    await a.goto(`${BASE}/projects/${project}`, { waitUntil: 'networkidle0' });
    await a.waitForFunction(() => document.querySelector('[data-repo-last-check]')?.textContent.trim() !== '—', { timeout: 15000 });
    r.card = await a.$eval('.project-summary__latest', (n) => n.innerText.replace(/\s+/g, ' ').trim());
    r.lastCheck = await text(a, '[data-repo-last-check]');
    check(r.card.includes(`분석 #${realFailed?.id}`) && /실패/.test(r.card), '프로젝트 최근 분석 카드 = 실패한 실제 분석', r.card);
    check(/변경 없음$/.test(r.lastCheck ?? ''), '저장소 상자 마지막 확인 = 변경 없음', r.lastCheck);
    await at(a, 1280, 'p27c_project_failed');
    await at(a, 1024, 'p27c_project_failed_1024');
    await a.setViewport({ width: 1280, height: 900 });
    await a.goto(`${BASE}/projects/${project}/history`, { waitUntil: 'networkidle0' });
    await a.waitForSelector('[data-history-rows] tr[data-unchanged]');
    r.historyTop = await a.$$eval('[data-history-rows] tr', (rows) => rows.slice(0, 3).map((tr) => tr.querySelector('[data-badge]')?.textContent.trim()));
    check(r.historyTop[0] === '성공(변경 없음)' && r.historyTop.includes('실패'), '이력에는 변경 없음 행이 보임', r.historyTop);
  } else {
    // ---- 이력 ----
    await a.goto(`${BASE}/projects/${project}/history`, { waitUntil: 'networkidle0' });
    await a.waitForSelector('[data-history-rows] tr');
    r.history = await a.$$eval('[data-history-rows] tr[data-unchanged]', (rows) => rows.map((tr) => ({
      id: tr.dataset.analysisId, name: tr.querySelector('th')?.textContent.trim(), badge: tr.querySelector('[data-badge]')?.textContent.trim(),
      badgeName: tr.querySelector('[data-badge]')?.dataset.badge, icon: !!tr.querySelector('[data-badge] svg'),
      fold: tr.querySelector('[data-unchanged-fold]')?.textContent.trim(), base: tr.querySelector('[data-unchanged-base]')?.getAttribute('href'),
      baseText: tr.querySelector('[data-unchanged-base]')?.textContent.trim(), baseLabel: tr.querySelector('[data-unchanged-base]')?.getAttribute('aria-label'), compareDisabled: tr.querySelector('button')?.disabled,
    })));
    const folded = r.history.find((h) => /^\d+회 확인/.test(h.fold ?? ''));
    check(r.history.length >= 2, '변경 없음 행 2개 이상(사용자 1 + 주기 접힘)', r.history);
    check(r.history.every((h) => h.badge === '성공(변경 없음)' && h.badgeName === 'analysis_status.unchanged' && h.icon && h.compareDisabled && /^Run — · 분석 #\d+$/.test(h.name)),
      '배지(아이콘 + 글자)·Run —·비교 끔', r.history);
    check(folded && /^\d+회 확인 · 마지막 확인 \d{2}-\d{2}-\d{2} \d{2}:\d{2}$/.test(folded.fold) && /\/analyses\/\d+$/.test(folded.base ?? '') && /^기준 Run \d+$/.test(folded.baseText ?? '') && /이미 분석했습니다/.test(folded.baseLabel ?? ''),
      '접힌 표시·기준 Run 링크', folded);
    r.chartPoints = await a.evaluate(() => [...document.querySelectorAll('[data-history-figure] table tbody tr')].map((tr) => tr.firstChild?.textContent));
    check(r.chartPoints.every((l) => /^Run \d+$/.test(l ?? '')), '추이 차트(표)에 변경 없음 없음', r.chartPoints);
    await at(a, 1280, 'history_1280');
    await at(a, 1024, 'history_1024');
    await a.setViewport({ width: 1280, height: 900 });
    // 숨기기 → 주소 → 새로 고침 복원
    await a.click('[data-history-hide-unchanged]');
    await a.waitForFunction(() => !document.querySelector('[data-history-rows] tr[data-unchanged]'), { timeout: 10000 });
    r.hideUrl = new URL(a.url()).search;
    await a.reload({ waitUntil: 'networkidle0' });
    await a.waitForSelector('[data-history-rows] tr');
    r.hideRestored = { checked: await a.$eval('[data-history-hide-unchanged]', (n) => n.checked), unchangedRows: await a.$$eval('[data-history-rows] tr[data-unchanged]', (n) => n.length) };
    check(r.hideUrl.includes('unchanged=hide') && r.hideRestored.checked && r.hideRestored.unchangedRows === 0, '숨기기 필터 주소 반영·복원', [r.hideUrl, r.hideRestored]);
    await at(a, 1280, 'history_hidden');
    await a.goBack({ waitUntil: 'networkidle0' });
    // ---- 분석 상세(접힌 변경 없음) ----
    await a.goto(`${BASE}/analyses/${folded.id}`, { waitUntil: 'networkidle0' });
    await a.waitForSelector('[data-notice="unchanged"]');
    r.detail = {
      title: await text(a, '[data-analysis-title]'), badge: await text(a, '[data-status-badge]'), notice: await text(a, '[data-notice="unchanged"]'),
      link: await a.$eval('[data-notice="unchanged"] a', (n) => n.getAttribute('href')).catch(() => null), resultHidden: await a.$eval('[data-result]', (n) => n.hidden),
    };
    check(/^Run —\(성공\(변경 없음\)\) · 분석 #\d+$/.test(r.detail.title ?? '') && r.detail.badge === '성공(변경 없음)' && /이미 분석했습니다/.test(r.detail.notice ?? '')
      && /\/analyses\/\d+$/.test(r.detail.link ?? '') && r.detail.resultHidden, '분석 상세(기준 Run 링크, 결과 영역 없음)', r.detail);
    await at(a, 1280, 'detail_1280');
    await at(a, 1024, 'detail_1024');
    await a.setViewport({ width: 1280, height: 900 });
    // 기준 Run 링크 이동
    await Promise.all([a.waitForNavigation({ waitUntil: 'networkidle0' }), a.click('[data-notice="unchanged"] a')]);
    r.baseTitle = await text(a, '[data-analysis-title]');
    check(/^Run \d+ · 분석 #\d+$/.test(r.baseTitle ?? ''), '기준 Run으로 이동', r.baseTitle);
    // ---- 프로젝트 화면: 마지막 분석 / 마지막 확인 ----
    await a.goto(`${BASE}/projects/${project}`, { waitUntil: 'networkidle0' });
    await a.waitForFunction(() => document.querySelector('[data-repo-last-check]')?.textContent.trim() !== '—', { timeout: 15000 });
    r.project = { lastRevision: await text(a, '[data-repo-last-revision]'), lastCheck: await text(a, '[data-repo-last-check]'), lastFetch: await text(a, '[data-repo-last-fetch]') };
    check(/^[0-9a-f]{7} \(main\)$/.test(r.project.lastRevision ?? '') && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}.* · 변경 없음$/.test(r.project.lastCheck ?? ''), '마지막 분석·마지막 확인', r.project);
    await a.$eval('[data-repo-status]', (n) => n.scrollIntoView());
    await at(a, 1280, 'project_1280');
    await at(a, 1024, 'project_1024');
    await a.setViewport({ width: 1280, height: 900 });
    // ---- 대시보드 추이 ----
    const trend = (await api(a, 'GET', `/api/v1/dashboard/trend?project=${project}`)).json?.data;
    const points = trend?.series?.[0]?.points ?? trend?.points ?? [];
    const ids = new Set(r.history.map((h) => Number(h.id)));
    r.trend = points.map((p) => [p.run, p.analysis_id]);
    check(points.length >= 2 && points.every((p) => Number.isInteger(p.run) && !ids.has(p.analysis_id)), '대시보드 추이에 변경 없음 없음', r.trend);
    await a.goto(`${BASE}/dashboard?project=${project}`, { waitUntil: 'networkidle0' });
    await sleep(500);
    await at(a, 1280, 'dashboard');
    // ---- 일반 사용자 ----
    const u = await session(userLogin);
    try {
      await u.page.goto(`${BASE}/projects/${project}/history`, { waitUntil: 'networkidle0' });
      await u.page.waitForSelector('[data-history-rows] tr');
      r.user = {
        badges: await u.page.$$eval('[data-history-rows] tr[data-unchanged] [data-badge]', (n) => n.map((x) => x.textContent.trim())),
        force: (await api(u.page, 'POST', `/api/v1/projects/${project}/repo/fetch`, { force: true })).status,
      };
      await u.page.goto(`${BASE}/projects/${project}`, { waitUntil: 'networkidle0' });
      r.user.fetchButton = await u.page.$('[data-repo-fetch]') !== null;
      r.user.lastCheck = await text(u.page, '[data-repo-last-check]');
      check(r.user.badges.length >= 2 && r.user.force === 403 && !r.user.fetchButton, '일반 사용자: 배지 보임, 버튼 없음, force 403', r.user);
    } finally {
      await u.browser.close();
    }
  }
} finally {
  await admin.browser.close();
}
console.log(JSON.stringify(r, null, 2));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${problems.join('\n- ')}` : `${phase}단계 모든 점검 통과(CSP 위반 0, 콘솔 오류 0)`);
process.exit(problems.length ? 1 : 0);
