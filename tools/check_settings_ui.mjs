// 설정 관리 브라우저 점검(P27A, 헤드리스 Chrome). 격리 서버(별도 포트·DB 사본·config 사본 폴더)에서만 쓴다 — 실제로 settings.local.ini를 바꾼다.
//   PJG_PASSWORD=... PJG_PATH_NEW=<아직 없는 프로젝트 밖 폴더> node tools/check_settings_ui.mjs <점검 서버> <일반 사용자> [관리자] --phase=1|2|tabs
// 1단계(격리 워커 실행 중): 메뉴·카테고리 탭(P27F — 예전 묶음 접기/펼치기 대신)·검색(탭을 가로질러), 값 바꾸기 → 형식 오류 표시(탭 머리 오류) →
//   저장 시도 = 첫 오류 탭으로 이동·포커스 → 변경 미리보기(키보드·Esc) → 저장 → 출처 배지 "화면 설정"·
//   반영 상태 "적용됨"(워커가 다시 읽음), 경로 항목(점검 서버가 등록한 더미 키 test.dummy_dir): 금지 위치 실패 → 없는 폴더 → "만들까요?" → 생성·통과 → 저장,
//   기본값으로(확인 창), 다른 관리자 저장과 충돌(409 안내), 1280·1024 캡처·가로 스크롤.
// 2단계(워커 정지 + 점검용 생존 신호에 pending_restart): 재시작 필요 배너, 변경 이력 표, 일반 사용자 403·메뉴 없음.
// tabs 단계(P27F): 탭 이름·순서·항목 수, ←→·Home·End, 해시(#backup·예전 #group-backup·모르는 값), 탭을 옮겨도 입력 유지·탭 머리 변경 건수,
//   검색의 다른 탭 안내, 백업 탭의 경로 확인·디스크 추정 위치, 백업·복원 화면 링크가 백업 탭을 엶, 한국어·영어 × 라이트·다크 × 1280·1024(탭 한 줄·줄바꿈, 가로 스크롤 0).
// 콘솔 오류 0, CSP 위반 0. 캡처 P27F_*.png(1·2단계도 예전 P27A_* 대신 P27F_* 이름)
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const phase = (process.argv.find((a) => a.startsWith('--phase=')) ?? '--phase=1').slice(8);
const [baseArg, userLogin, adminLogin = 'admin'] = args;
if (!userLogin || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... PJG_PATH_NEW=<폴더> node tools/check_settings_ui.mjs <점검 서버> <일반 사용자> [관리자] --phase=1|2');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });

async function session(login, { lang = null, theme = null } = {}) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  // 화면 언어·테마(P27D): 로그인 전 쿠키 — 계정 값이 비어 있는 사본에서 쓴다(계정 값이 있으면 그쪽이 우선)
  if (lang) await page.setCookie({ name: 'pjg_lang', value: lang, url: BASE });
  if (theme) await page.setCookie({ name: 'pjg_theme', value: theme, url: BASE });
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[39]/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', process.env.PJG_PASSWORD);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return { browser, page };
}
const apiCall = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const base = document.querySelector('meta[name="app-base"]')?.content ?? '';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: b === null ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body ?? null);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const shot = async (page, name, width = 1280, full = true) => {
  await page.setViewport({ width, height: 900 });
  await sleep(250);
  const s = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth }));
  r[`scroll_${name}`] = s.scroll;
  check(s.scroll <= 0, `가로 스크롤 ${name}`, s);
  await page.screenshot({ path: path.join(OUT, `P27F_${name}.png`), fullPage: full });
};
const item = (key) => `[data-item="${key}"]`;
// P27F: 항목이 든 탭을 연다(숨은 탭의 입력 칸은 누를 수 없음)
const openTabOf = (page, key) => page.evaluate((k) => {
  const panel = document.querySelector(`[data-item="${k}"]`)?.closest('[role="tabpanel"]');
  if (panel?.hidden) document.querySelector(`[aria-controls="${panel.id}"]`).click();
}, key);
const selectedTab = (page) => page.$eval('[role="tab"][aria-selected="true"]', (n) => n.dataset.tab).catch(() => null);
const tabHeads = (page) => page.$$eval('[role="tab"]', (ts) => ts.map((x) => ({
  id: x.dataset.tab, name: x.querySelector('.config-tab__name').textContent,
  changes: x.querySelector('[data-tab-changes]').hidden ? 0 : Number(x.querySelector('[data-tab-changes]').textContent),
  errors: x.querySelector('[data-tab-errors]').hidden ? '' : x.querySelector('[data-tab-errors]').textContent, errorIcon: !!x.querySelector('[data-tab-errors] svg'),
  matches: x.querySelector('[data-tab-matches]').hidden ? null : x.querySelector('[data-tab-matches]').textContent, nomatch: x.classList.contains('config-tab--nomatch'),
  label: x.getAttribute('aria-label'), top: Math.round(x.getBoundingClientRect().top), selected: x.getAttribute('aria-selected') === 'true', tabIndex: x.tabIndex,
})));
const setValue = async (page, key, value) => {
  await openTabOf(page, key);
  const sel = `${item(key)} input[data-input]`;
  await page.$eval(sel, (n) => { n.value = ''; });
  await page.type(sel, value);
};
// 다시 불러오기: GET 응답이 오고 다시 그린 뒤까지 기다린다(이전 요소를 잡지 않게)
const reload = async (page) => {
  await Promise.all([page.waitForResponse((res) => res.url().includes('/api/v1/admin/config-settings') && res.request().method() === 'GET'), page.click('[data-reload]')]);
  await sleep(200);
};
const openSettings = async (page) => {
  await page.goto(`${BASE}/admin/config-settings`, { waitUntil: 'networkidle0' });
  await page.waitForSelector('[data-item]');
};

// P27F 카테고리 탭: 한국어·영어 × 라이트·다크 × 1280·1024 배치, 탭 동작(키보드·해시·상태 유지), 백업 탭, 다른 화면 링크
async function tabsPhase() {
  const NAMES = {
    // P28B(의도된 변경): 계정 승인·LDAP/AD 묶음이 더해져 9개. P28C·P28D(의도된 변경, X10에서 반영 — 그 단계에서 이 단계를 다시 돌리지 못함): SSO(OIDC)·SSO(SAML) 더해 11개
    // X12(이름만): "저장소 연결 테스트" 탭 → "저장소 연결"(허용 호스트 구역과 합침)
    // P30(의도된 변경): 알림 다음에 모니터링 탭(헬스체크 허용 IP·큐 정체) — 12개
    ko: ['보존 기간', '디스크', '알림', '모니터링', '주기 분석·가져오기', '저장소 연결', '분석', '백업', '계정 승인', 'LDAP/AD', 'SSO(OIDC)', 'SSO(SAML)'],
    en: ['Retention periods', 'Disk', 'Notifications', 'Monitoring', 'Scheduled analysis and fetch', 'Repository connections', 'Analysis', 'Backups', 'Account approval', 'LDAP/AD', 'SSO (OIDC)', 'SSO (SAML)'],
  };
  for (const [lang, theme] of [['ko', 'light'], ['en', 'light'], ['ko', 'dark'], ['en', 'dark']]) {
    const s = await session(adminLogin, { lang, theme });
    const p = s.page;
    try {
      // 로그인하면 쿠키가 빈 계정 값으로 옮겨지므로(P27D) 계정 화면 설정을 API로 바꾼다(사본 전용, 끝에 비움)
      const pref = await apiCall(p, 'PUT', '/api/v1/users/me/preferences', { language: lang, theme });
      check(pref.status === 200, `화면 설정 ${lang} ${theme}`, pref.status);
      await openSettings(p);
      const names = (await tabHeads(p)).filter((h) => h.id !== 'test').map((h) => h.name);
      check(JSON.stringify(names) === JSON.stringify(NAMES[lang]), `탭 이름 ${lang}`, names);
      r[`theme_${lang}_${theme}`] = await p.evaluate(() => [document.documentElement.lang, document.documentElement.dataset.theme ?? '', getComputedStyle(document.querySelector('.config-panel')).backgroundColor]);
      check(r[`theme_${lang}_${theme}`][0] === lang && r[`theme_${lang}_${theme}`][1] === theme, `언어·테마 ${lang} ${theme}`, r[`theme_${lang}_${theme}`]);
      for (const width of [1280, 1024]) {
        await p.setViewport({ width, height: 900 });
        await sleep(250);
        // 요구 7개 탭(점검용 더미 test 탭 제외)의 줄 수, 탭 글자 잘림, 페이지 가로 스크롤
        const m = await p.evaluate(() => {
          const tabs = [...document.querySelectorAll('[role="tab"]')].filter((x) => x.dataset.tab !== 'test');
          return {
            rows: new Set(tabs.map((x) => Math.round(x.getBoundingClientRect().top))).size,
            clipped: tabs.filter((x) => x.scrollWidth > x.clientWidth + 1).map((x) => x.dataset.tab),
            scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            listWidth: Math.round(document.querySelector('[data-tablist]').getBoundingClientRect().width),
            tabsWidth: Math.round(tabs.reduce((sum, x) => sum + x.getBoundingClientRect().width, 0)),
          };
        });
        r[`layout_${lang}_${theme}_${width}`] = m;
        check(m.scroll <= 0 && m.clipped.length === 0, `가로 스크롤·탭 잘림 ${lang} ${theme} ${width}`, m);
        if (width === 1280) check(m.rows === 1, `1280px 탭 12개(+점검용 test) 한 줄 ${lang} ${theme}`, m);
        await p.screenshot({ path: path.join(OUT, `P27F_${lang}_${theme}_${width}.png`), fullPage: true });
      }
      await p.setViewport({ width: 1280, height: 900 });
      await p.click('[aria-controls="panel-backup"]');
      await sleep(300);
      await p.screenshot({ path: path.join(OUT, `P27F_backup_${lang}_${theme}.png`), fullPage: true });
      if (lang !== 'ko' || theme !== 'light') continue;

      // ---- 동작(한국어·라이트에서 한 번) ----
      await openSettings(p);
      // 키보드: 지금 탭만 Tab 순서, ←→·Home·End로 이동과 선택, 주소 해시
      await p.focus('[role="tab"][aria-selected="true"]');
      const key = async (k) => { await p.keyboard.press(k); await sleep(80); return [await selectedTab(p), await p.evaluate(() => [document.activeElement?.dataset.tab, location.hash])]; };
      r.keys = [await key('ArrowRight'), await key('End'), await key('Home'), await key('ArrowLeft'), await key('Home')];
      r.roving = (await tabHeads(p)).map((h) => [h.id, h.tabIndex]).filter(([, i]) => i === 0);
      check(JSON.stringify(r.keys) === JSON.stringify([['disk', ['disk', '#disk']], ['test', ['test', '#test']], ['retention', ['retention', '#retention']], ['test', ['test', '#test']], ['retention', ['retention', '#retention']]])
        && JSON.stringify(r.roving) === JSON.stringify([['retention', 0]]), '키보드 ←→·Home·End·해시·roving tabindex', [r.keys, r.roving]);
      r.panelShown = await p.$$eval('[role="tabpanel"]', (ps) => ps.filter((x) => !x.hidden).map((x) => [x.id, x.getAttribute('aria-labelledby')]));
      check(JSON.stringify(r.panelShown) === JSON.stringify([['panel-retention', 'cs-tab-retention']]), '패널 하나만 보임·aria-labelledby', r.panelShown);
      // 해시로 직접 열기, 예전 #group-backup은 #backup으로, 모르는 값은 첫 탭, hashchange
      const at = async (hash) => {
        await p.goto(`${BASE}/admin/config-settings${hash}`, { waitUntil: 'networkidle0' });
        await p.waitForSelector('[role="tab"][aria-selected="true"]');
        return [await selectedTab(p), await p.evaluate(() => location.hash)];
      };
      r.hash = [await at('#backup'), await at('#group-backup'), await at('#zzz'), await at('')];
      await p.evaluate(() => { location.hash = '#analysis'; });
      await sleep(200);
      r.hash.push([await selectedTab(p), await p.evaluate(() => location.hash)]);
      check(JSON.stringify(r.hash) === JSON.stringify([['backup', '#backup'], ['backup', '#backup'], ['retention', '#retention'], ['retention', ''], ['analysis', '#analysis']]), '해시로 탭 열기(예전 링크·모르는 값·hashchange)', r.hash);
      // 탭을 옮겨도 입력 유지 + 탭 머리 변경 건수, 변경 취소는 모든 탭
      await setValue(p, 'analysis.max_concurrent', '3');
      await setValue(p, 'backup.retention_days', '45');
      await p.click('[aria-controls="panel-analysis"]');
      r.keep = [await p.$eval(`${item('analysis.max_concurrent')} input`, (n) => n.value), await p.$eval(`${item('backup.retention_days')} input`, (n) => n.value),
        Object.fromEntries((await tabHeads(p)).filter((h) => h.changes).map((h) => [h.id, h.changes])), await text(p, '[data-preview]')];
      await p.click('[data-discard]');
      r.discard = [(await tabHeads(p)).every((h) => h.changes === 0), await p.$eval(`${item('backup.retention_days')} input`, (n) => n.value), await selectedTab(p)];
      check(r.keep[0] === '3' && r.keep[1] === '45' && JSON.stringify(r.keep[2]) === JSON.stringify({ analysis: 1, backup: 1 }) && r.keep[3] === '설정 항목 변경 확인(2건)'
        && r.discard[0] && r.discard[1] !== '45' && r.discard[2] === 'analysis', '탭 간 입력 유지·탭 머리 건수·변경 취소(모든 탭, 지금 탭 유지)', [r.keep, r.discard]);
      // 백업 탭: 디스크 추정은 보관 개수 항목 바로 아래, 경로 확인 결과는 백업 폴더 항목 아래
      await p.click('[aria-controls="panel-backup"]');
      r.estimateIn = await p.$eval('[data-backup-estimate]', (n) => n.closest('[data-item]')?.dataset.item ?? null).catch(() => 'none');
      await p.click(`${item('backup.dir')} [data-check-path]`);
      await p.waitForFunction((sel) => (document.querySelector(sel)?.textContent ?? '').length > 0 && !/확인하는 중/.test(document.querySelector(sel).textContent), { timeout: 10000 }, `${item('backup.dir')} [data-path-result]`);
      r.backupPath = await text(p, `${item('backup.dir')} [data-path-result]`);
      check(r.estimateIn === 'backup.retention_max_count' && /확인됨|같은 볼륨|없습니다/.test(r.backupPath ?? ''), '백업 탭: 디스크 추정 위치·경로 확인 결과', [r.estimateIn, r.backupPath]);
      // 다른 화면 링크(P27B 폴더 변경·P27E 주기 변경) → 백업 탭
      await p.goto(`${BASE}/admin/backups`, { waitUntil: 'networkidle0' });
      await p.waitForSelector('[data-admin-backups] a[href$="/admin/config-settings#backup"]', { timeout: 10000 });
      r.links = await p.$$eval('[data-admin-backups] a[href*="/admin/config-settings"]', (as) => as.map((x) => x.getAttribute('href')));
      await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click('[data-admin-backups] a[href$="/admin/config-settings#backup"]')]);
      await p.waitForSelector('[role="tab"][aria-selected="true"]');
      r.linkTab = await selectedTab(p);
      check(r.links.length >= 2 && r.links.every((h) => h.endsWith('/admin/config-settings#backup')) && r.linkTab === 'backup', '백업·복원 화면 링크 → 백업 탭', [r.links, r.linkTab]);
    } finally {
      await apiCall(s.page, 'PUT', '/api/v1/users/me/preferences', { language: null, theme: null }).catch(() => null);
      await s.browser.close();
    }
  }
}

if (phase === '1') {
  const admin = await session(adminLogin);
  const a = admin.page;
  try {
    await openSettings(a);
    // 시작 값(점검 환경의 config.ini 값)을 API에서 읽어 기대값으로 쓴다
    const start = Object.fromEntries(((await apiCall(a, 'GET', '/api/v1/admin/config-settings')).json?.data?.items ?? []).map((i) => [i.key, i]));
    const warnBase = start['disk.warn_percent']?.value;
    check(start['disk.warn_percent']?.source === 'config' && start['analysis.timeout']?.value === '4h', '시작 상태(화면 설정 없음)', [start['disk.warn_percent'], start['analysis.timeout']?.value]);
    // 메뉴·묶음
    // X10(이름만 바꿈): 설정 관리는 메뉴 "시스템 관리"(admin.tools)의 첫 탭 — 메뉴 항목 "설정 관리"는 없고, 시스템 관리가 현재 메뉴, 탭 줄에서 설정 관리가 현재 탭
    r.menu = await a.$eval('[data-nav="admin.tools"]', (n) => [n.textContent.trim(), n.getAttribute('aria-current')]).catch(() => null);
    r.menuOld = await a.$('[data-nav="admin.settings"]');
    r.subnav = await a.$$eval('[data-system-subnav] a', (as) => as.map((x) => [x.textContent.trim(), x.getAttribute('aria-current')]));
    // X12(위치만): 설정 관리는 규칙 설정 바로 뒤(6번째), 알림 보관·저장소 허용 호스트 탭 없음
    check(r.menu?.[0] === '시스템 관리' && r.menu?.[1] === 'page' && !r.menuOld && JSON.stringify(r.subnav.map(([n]) => n)) === JSON.stringify(['분석 도구', '워커', '점검', 'Run 관리', '규칙 설정', '설정 관리', '주기 분석', '백업·복원'])
      && r.subnav[5]?.[1] === 'page', '관리자 메뉴 "시스템 관리" > 탭 "설정 관리"(규칙 설정 뒤, 현재 화면)', { menu: r.menu, subnav: r.subnav });
    r.menuOld = Boolean(r.menuOld);
    // P27F(의도된 변경): 묶음 카드 → 카테고리 탭. 요구 7개 + 점검 라우터의 더미 묶음 test(등록부에 묶음을 더하면 탭이 늘어남)
    r.groups = await a.$$eval('[role="tabpanel"]', (ps) => ps.map((p) => [p.dataset.group, p.querySelectorAll('[data-item]').length, !p.hidden]));
    check(JSON.stringify(r.groups.map(([g, n]) => [g, n])) === JSON.stringify([['retention', 7], ['disk', 2], ['notification', 2], ['monitoring', 2], ['schedule', 5], ['repository', 3], ['analysis', 4], ['backup', 14], ['auth', 2], ['auth_ldap', 14], ['auth_oidc', 14], ['auth_saml', 14], ['test', 2]])
      && r.groups.filter(([, , shown]) => shown).length === 1 && r.groups[0][2], '탭 12개(+더미 test) 순서·항목 수(허용 83개 — P28B·P28C·P28D·P30 의도된 변경), 첫 탭만 보임', r.groups);
    r.dbNote = await a.$$eval('.config-settings__db-note a', (as) => as.map((x) => x.getAttribute('href')));
    check(r.dbNote.includes('#notification') && r.dbNote.some((h) => h.endsWith('/dashboard')), 'DB 화면 설정 안내 링크(X12: 알림 보관 기간은 같은 화면 알림 탭)', r.dbNote);
    // X12: 통합 구역 — 알림 탭 끝 "알림 보관 기간"(자기 저장 단추), 저장소 연결 탭 맨 위 허용 호스트, 구역 변경 표시·탭 머리 건수·검색
    r.merged = await a.evaluate(() => ({ notif: document.querySelector('#panel-notification > .config-merge:last-child')?.matches('[data-merge-into="notification"]') ?? false,
      hosts: document.querySelector('#panel-repository > h2 + .config-merge')?.matches('[data-repo-hosts]') ?? false,
      headings: [...document.querySelectorAll('#panel-notification .config-merge__title, #panel-repository .config-merge__title')].map((h) => h.textContent.trim()) }));
    check(r.merged.notif && r.merged.hosts && r.merged.headings.length === 4, '알림·저장소 연결 탭 안 구역 배치·구역 제목', r.merged);
    await a.click('[data-tab="notification"]');
    await a.waitForSelector('[data-setting="notifications.retain_read_days"]:not([disabled])', { timeout: 10000 });
    const readBefore = await a.$eval('[data-setting="notifications.retain_read_days"]', (n) => n.value);
    await a.click('[data-setting="notifications.retain_read_days"]', { clickCount: 3 });
    await a.type('[data-setting="notifications.retain_read_days"]', String(Number(readBefore) === 5 ? 6 : 5));
    await sleep(200);
    r.mergedDirty = await a.evaluate(() => ({ mark: document.querySelector('[data-merge-into="notification"] [data-merge-dirty]')?.textContent, hidden: document.querySelector('[data-merge-into="notification"] [data-merge-dirty]')?.hidden,
      tabCount: document.querySelector('[data-tab="notification"] [data-tab-changes]')?.textContent, preview: document.querySelector('[data-preview]').textContent }));
    check(r.mergedDirty.hidden === false && /1/.test(r.mergedDirty.mark ?? '') && r.mergedDirty.tabCount === '1' && /0/.test(r.mergedDirty.preview), '구역 변경 표시·탭 머리 건수(설정 저장 단추 건수와 별개)', r.mergedDirty);
    await a.click('[data-admin-notif] [data-save]');
    await a.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-admin-notif] [data-status]')?.textContent ?? ''), { timeout: 10000 });
    r.mergedSaved = await apiCall(a, 'GET', '/api/v1/admin/settings');
    check(String(r.mergedSaved.json?.data?.['notifications.retain_read_days']?.value) !== readBefore && await a.$eval('[data-merge-into="notification"] [data-merge-dirty]', (n) => n.hidden), '알림 보관 기간 저장(app_settings)·표시 사라짐', r.mergedSaved.json?.data?.['notifications.retain_read_days']);
    r.mergedSaved = r.mergedSaved.json?.data?.['notifications.retain_read_days']?.value;
    await apiCall(a, 'PATCH', '/api/v1/admin/settings', { 'notifications.retain_read_days': Number(readBefore) });
    await a.type('#cs-search', '허용 호스트');
    await sleep(300);
    r.mergedSearch = await a.evaluate(() => ({ matches: document.querySelector('[data-tab="repository"] [data-tab-matches]')?.textContent, hosts: !document.querySelector('[data-repo-hosts]').hidden }));
    check(/[1-9]/.test(r.mergedSearch.matches ?? '') && r.mergedSearch.hosts, '탭 간 검색이 허용 호스트 구역을 찾음', r.mergedSearch);
    await a.click('#cs-search', { clickCount: 3 });
    await a.keyboard.press('Backspace');
    await sleep(300);
    await a.click('[data-tab="retention"]');
    // X12: 저장소 연결 탭의 허용 호스트 구역 — 추가 폼 입력 중 표시 → 추가(DB 표, 자기 API) → 행 옆 "연결 테스트" → 결과 → API로 지움(.invalid 이름이라 외부 접속 없음)
    await a.click('[data-tab="repository"]');
    await a.type('#rh-host', 'git.x12-check.invalid');
    await sleep(200);
    r.hostDirty = await a.evaluate(() => ({ mark: !document.querySelector('[data-repo-hosts] [data-merge-dirty]').hidden, tab: document.querySelector('[data-tab="repository"] [data-tab-changes]')?.textContent }));
    await a.click('[data-host-add]');
    await a.waitForFunction(() => [...document.querySelectorAll('[data-host-rows] tr')].some((tr) => tr.textContent.includes('git.x12-check.invalid')), { timeout: 10000 });
    r.hostAdded = await a.evaluate(() => ({ dirtyAfter: document.querySelector('[data-repo-hosts] [data-merge-dirty]').hidden, id: [...document.querySelectorAll('[data-host-rows] tr')].find((tr) => tr.textContent.includes('git.x12-check.invalid'))?.dataset.hostId }));
    await a.click(`[data-host-rows] tr[data-host-id="${r.hostAdded.id}"] [data-test-host]`);
    await a.waitForFunction(() => !document.querySelector('[data-host-result]').hidden && document.querySelector('[data-result-title]').textContent.includes('git.x12-check.invalid'), { timeout: 20000 });
    r.hostTest = (await text(a, '[data-host-result]')) ?? '';
    await a.screenshot({ path: path.join(OUT, 'X12_repository_tab.png'), fullPage: true });
    r.hostDeleted = (await apiCall(a, 'DELETE', `/api/v1/admin/repo-hosts/${r.hostAdded.id}`)).status;
    check(r.hostDirty.mark && r.hostDirty.tab === '1' && r.hostAdded.dirtyAfter && r.hostAdded.id && r.hostTest.length > 0 && r.hostDeleted === 200, '허용 호스트 구역: 입력 중 표시·추가·행별 연결 테스트 결과·삭제', { dirty: r.hostDirty, added: r.hostAdded, test: r.hostTest.slice(0, 120), del: r.hostDeleted });
    await a.click('[data-tab="retention"]');
    await shot(a, 'settings_1280');
    await shot(a, 'settings_1024', 1024);
    await a.setViewport({ width: 1280, height: 900 });
    // P27F(의도된 변경): 모두 접기·펼치기 단추 없음. 검색은 모든 탭에서 — 지금 탭(보존 기간)에 없으면 "다른 탭에 N건" + 그 탭으로
    r.noExpand = await a.$('[data-expand-all], [data-collapse-all]') === null;
    check(r.noExpand, '모두 접기·펼치기 단추 제거', r.noExpand);
    await a.type('[data-search]', '디스크 경고');
    r.searchHint = [await text(a, '[data-search-hint]'), await a.$eval('[data-search-hint]', (n) => !n.hidden)];
    r.searchHeads = (await tabHeads(a)).map((h) => [h.id, h.matches, h.nomatch]);
    check(r.searchHint[1] && /다른 탭에 1건/.test(r.searchHint[0] ?? '') && /"디스크" 탭 보기/.test(r.searchHint[0] ?? '')
      && JSON.stringify(r.searchHeads.find(([id]) => id === 'disk')) === JSON.stringify(['disk', '(1)', false]) && r.searchHeads.find(([id]) => id === 'retention')[2],
    '검색: 다른 탭 안내·탭 머리 건수·0건 흐리게', [r.searchHint, r.searchHeads]);
    await a.screenshot({ path: path.join(OUT, 'P27F_search_other.png'), clip: { x: 0, y: 0, width: 1280, height: 520 } });
    await a.click('[data-search-go]');
    r.search = [await selectedTab(a), await a.$$eval('[data-item]', (rows) => rows.filter((x) => !x.hidden && !x.closest('[role="tabpanel"]').hidden).map((x) => x.dataset.item)),
      await a.evaluate(() => document.activeElement?.dataset.tab)];
    check(JSON.stringify(r.search) === JSON.stringify(['disk', ['disk.warn_percent'], 'disk']) && await a.$eval('[data-search-hint]', (n) => n.hidden), '검색 "디스크 경고" → 디스크 탭으로(포커스)', r.search);
    await a.$eval('[data-search]', (n) => { n.value = ''; });
    await a.type('[data-search]', 'zzzz');
    r.searchNone = await a.$eval('[data-search-none]', (n) => !n.hidden);
    await a.$eval('[data-search]', (n) => { n.value = ''; n.dispatchEvent(new Event('input')); });
    r.searchCleared = (await tabHeads(a)).every((h) => h.matches === null && !h.nomatch) && await a.$eval('[data-search-none]', (n) => n.hidden);
    check(r.searchNone && r.searchCleared, '검색 결과 없음 안내(기존 문구)·지우면 원래대로', [r.searchNone, r.searchCleared]);
    // 값 바꾸기 → 형식 오류 → 고침 → 미리보기
    await setValue(a, 'disk.warn_percent', '75');
    await setValue(a, 'analysis.timeout', '2h');
    await setValue(a, 'scheduler.tick_seconds', '5s');
    r.fieldError = await text(a, `${item('scheduler.tick_seconds')} [data-field-error]`);
    // P27F(의도된 변경): 탭 머리에 변경 건수·오류(아이콘 + 글자), 저장 시도 = 대화상자 없이 첫 오류 탭으로 이동·그 항목에 포커스
    const heads = await tabHeads(a);
    r.heads = Object.fromEntries(heads.map((h) => [h.id, [h.changes, h.errors, h.errorIcon]]));
    check(JSON.stringify([r.heads.disk, r.heads.analysis, r.heads.schedule, r.heads.retention]) === JSON.stringify([[1, '', false], [1, '', false], [1, '오류 1', true], [0, '', false]])
      && heads.find((h) => h.id === 'analysis').label === '분석, 변경 1건' && heads.find((h) => h.id === 'schedule').label === '주기 분석·가져오기, 변경 1건, 입력 오류 1건',
    '탭 머리: 변경 건수·오류 아이콘+문구·스크린리더 이름', [r.heads, heads.map((h) => h.label)]);
    await a.click('[aria-controls="panel-retention"]');
    await a.screenshot({ path: path.join(OUT, 'P27F_tab_heads.png'), clip: await a.$eval('[data-tablist]', (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY - 4, width: b.width, height: b.height + 8 }; }) });
    await a.click('[data-preview]');
    await sleep(300);
    r.blocked = [await a.$('dialog[open]') === null, await selectedTab(a), await a.evaluate(() => document.activeElement?.dataset.input), await text(a, '[data-bar] [data-status]')];
    check(r.blocked[0] && r.blocked[1] === 'schedule' && r.blocked[2] === 'scheduler.tick_seconds' && /"주기 분석·가져오기" 탭의 "/.test(r.blocked[3] ?? '') && /범위 10s ~ 1h/.test(r.fieldError ?? ''),
      '형식 오류 → 저장 막힘(저장 시도 = 첫 오류 탭으로 이동·포커스)', [r.fieldError, r.blocked]);
    await a.screenshot({ path: path.join(OUT, 'P27F_error_jump.png') });
    await setValue(a, 'scheduler.tick_seconds', '1m'); // 지금 값(60s)과 같은 값 — 변경 아님
    r.previewText = await text(a, '[data-preview]');
    // 탭을 옮겨도 입력 유지(모든 탭이 한 폼 상태)
    await a.click('[aria-controls="panel-retention"]');
    await a.click('[aria-controls="panel-disk"]');
    r.kept = [await a.$eval(`${item('disk.warn_percent')} input`, (n) => n.value), await a.$eval(`${item('analysis.timeout')} input`, (n) => n.value)];
    check(r.previewText === '설정 항목 변경 확인(2건)' && JSON.stringify(r.kept) === JSON.stringify(['75', '2h']), '변경 2건(같은 값은 빠짐)·탭을 옮겨도 입력 유지', [r.previewText, r.kept]);
    await a.click('[data-preview]');
    await a.waitForSelector('dialog[open] .config-dialog__table');
    r.dialogRows = await a.$$eval('dialog[open] tbody tr', (rows) => rows.map((tr) => [...tr.cells].map((c) => c.textContent)));
    check(JSON.stringify(r.dialogRows) === JSON.stringify([['디스크 경고 비율', warnBase, '75'], ['분석 시간 상한', '4h', '2h']]), '미리보기 표(항목·지금·저장 후)', r.dialogRows);
    r.dialogFocus = await a.evaluate(() => document.activeElement?.textContent);
    await a.screenshot({ path: path.join(OUT, 'P27F_preview_dialog.png') });
    await a.keyboard.press('Escape');
    await sleep(200);
    r.afterEsc = [await a.$('dialog[open]') === null, await text(a, '[data-preview]')];
    check(r.dialogFocus === '취소' && r.afterEsc[0] && r.afterEsc[1] === '설정 항목 변경 확인(2건)', '대화상자: 취소에 포커스, Esc = 취소(변경 유지)', [r.dialogFocus, r.afterEsc]);
    await a.click('[data-preview]');
    await a.waitForSelector('dialog[open] .btn--primary');
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-bar] [data-status]')?.textContent ?? ''), { timeout: 10000 });
    r.badge = await text(a, `${item('disk.warn_percent')} .config-item__badge`);
    r.hasReset = await a.$(`${item('disk.warn_percent')} [data-reset]`) !== null;
    r.afterSaveTab = [await selectedTab(a), (await tabHeads(a)).every((h) => h.changes === 0 && !h.errors)];
    check(r.badge === '화면 설정' && r.hasReset && r.afterSaveTab[0] === 'disk' && r.afterSaveTab[1], '저장 뒤 출처 "화면 설정"·기본값으로 버튼·지금 탭 유지·탭 머리 비움', [r.badge, r.hasReset, r.afterSaveTab]);
    // 워커가 몇 초 안에 다시 읽음 → 다시 불러오면 "적용됨"
    let applied = null;
    for (let i = 0; i < 20 && applied !== '적용됨'; i++) {
      await sleep(1000);
      await reload(a);
      await a.waitForSelector(`${item('disk.warn_percent')} [data-apply-status]`);
      applied = await text(a, `${item('disk.warn_percent')} [data-apply-status]`);
    }
    r.applied = applied;
    r.reloadTab = await selectedTab(a);
    check(applied === '적용됨' && r.reloadTab === 'disk', '워커가 재시작 없이 반영(적용됨)·다시 불러와도 지금 탭', [applied, r.reloadTab]);
    // 경로 항목(더미): 금지 위치 → 실패, 없는 폴더 → 만들기 확인 → 통과 → 저장
    const forbidden = `${ROOT.replace(/\//g, '\\')}\\config`;
    await setValue(a, 'test.dummy_dir', forbidden);
    await openTabOf(a, 'test.dummy_dir');
    await a.click(`${item('test.dummy_dir')} [data-check-path]`);
    await a.waitForFunction((s) => /쓸 수 없는 위치/.test(document.querySelector(s)?.textContent ?? ''), { timeout: 10000 }, `${item('test.dummy_dir')} [data-path-result]`);
    r.pathFail = [await text(a, `${item('test.dummy_dir')} [data-path-result]`), await a.$eval('[data-preview]', (b) => b.disabled)];
    r.pathFail[1] = await a.$eval('[data-preview]', (b) => b.dataset.blocked === '1'); // P27F: 단추는 눌리지만 막힘 표지(누르면 오류 탭으로)
    check(/쓸 수 없는 위치/.test(r.pathFail[0] ?? '') && r.pathFail[1], '경로 확인 실패 → 저장 막힘', r.pathFail);
    await a.screenshot({ path: path.join(OUT, 'P27F_path_fail.png'), clip: await a.$eval(item('test.dummy_dir'), (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height }; }) });
    const fresh = process.env.PJG_PATH_NEW;
    await setValue(a, 'test.dummy_dir', fresh);
    await a.click(`${item('test.dummy_dir')} [data-check-path]`);
    await a.waitForSelector('dialog[open]');
    r.createMsg = await text(a, 'dialog[open] p');
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction((s) => /확인됨/.test(document.querySelector(s)?.textContent ?? ''), { timeout: 10000 }, `${item('test.dummy_dir')} [data-path-result]`);
    r.pathOk = [await text(a, `${item('test.dummy_dir')} [data-path-result]`), await a.$eval('[data-preview]', (b) => b.disabled || b.dataset.blocked === '1')];
    check(/폴더가 없습니다\. 만들까요\?/.test(r.createMsg ?? '') && /^폴더를 만들었습니다\. 확인됨/.test(r.pathOk[0] ?? '') && !r.pathOk[1], '없는 폴더 → 만들기 확인 → 통과·저장 가능', [r.createMsg, r.pathOk]);
    await a.screenshot({ path: path.join(OUT, 'P27F_path_ok.png'), clip: await a.$eval(item('test.dummy_dir'), (n) => { const b = n.getBoundingClientRect(); return { x: b.x, y: b.y + window.scrollY, width: b.width, height: b.height }; }) });
    r.pathAria = await a.$eval(`${item('test.dummy_dir')} [data-path-result]`, (n) => [n.getAttribute('role'), n.getAttribute('aria-live')]);
    check(r.pathAria[0] === 'status' && r.pathAria[1] === 'polite', '경로 결과 aria-live', r.pathAria);
    await a.click('[data-preview]');
    await a.waitForSelector('dialog[open] .btn--primary');
    r.pathDialogNote = await a.$$eval('dialog[open] p', (ps) => ps.map((p) => p.textContent).some((t) => /경로 확인/.test(t)));
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction(() => /저장했습니다/.test(document.querySelector('[data-bar] [data-status]')?.textContent ?? ''), { timeout: 10000 });
    r.pathSaved = await a.$eval(`${item('test.dummy_dir')} input`, (n) => n.value);
    check(r.pathDialogNote && r.pathSaved.toLowerCase() === fresh.replace(/\//g, '\\').toLowerCase(), '경로 저장(정규화한 경로)', [r.pathDialogNote, r.pathSaved]);
    // 기본값으로
    await openTabOf(a, 'disk.warn_percent');
    await a.click(`${item('disk.warn_percent')} [data-reset]`);
    await a.waitForSelector('dialog[open]');
    r.resetMsg = await text(a, 'dialog[open] p');
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction(() => /되돌렸습니다/.test(document.querySelector('[data-bar] [data-status]')?.textContent ?? ''), { timeout: 10000 });
    r.reset = [await a.$eval(`${item('disk.warn_percent')} input`, (n) => n.value), await text(a, `${item('disk.warn_percent')} .config-item__badge`)];
    check(r.resetMsg === `"디스크 경고 비율" 화면 설정을 지우고 ${warnBase}(config.ini)로 되돌릴까요?` && r.reset[0] === warnBase && r.reset[1] === 'config.ini', '기본값으로', [r.resetMsg, r.reset]);
    // 충돌: 다른 관리자(API)가 먼저 저장 → 이 화면의 저장은 409 안내
    const view = (await apiCall(a, 'GET', '/api/v1/admin/config-settings')).json.data;
    const other = await apiCall(a, 'PUT', '/api/v1/admin/config-settings', { changes: { 'scheduler.max_per_tick': '40' }, version: view.version });
    check(other.status === 200, '다른 저장', other.status);
    await setValue(a, 'repository.test_per_minute', '7');
    await a.click('[data-preview]');
    await a.waitForSelector('dialog[open] .btn--primary');
    await a.click('dialog[open] .btn--primary');
    await a.waitForFunction(() => !document.querySelector('[data-config-settings] > [data-error]')?.hidden, { timeout: 10000 });
    r.conflict = await text(a, '[data-config-settings] > [data-error]');
    check(/다른 관리자가 그사이 설정을 바꿨습니다/.test(r.conflict ?? ''), '충돌 안내(409)', r.conflict);
    await reload(a);
    await a.waitForSelector('[data-item]');
    await shot(a, 'settings_after_1280');
  } finally {
    await admin.browser.close();
  }
} else if (phase === '2') {
  const admin = await session(adminLogin);
  const a = admin.page;
  try {
    await openSettings(a);
    r.banner = await text(a, '[data-restart-banner]');
    r.bannerLink = await a.$eval('[data-restart-banner] a', (n) => n.getAttribute('href')).catch(() => null);
    check(/워커를 다시 시작해야 반영되는 설정이 있습니다: test\.dummy_worker/.test(r.banner ?? '') && /\/admin\/worker$/.test(r.bannerLink ?? ''), '재시작 필요 배너·워커 관리 링크', [r.banner, r.bannerLink]);
    r.restartStatus = await text(a, `${item('test.dummy_worker')} [data-apply-status]`);
    check(r.restartStatus === '워커를 다시 시작해야 반영', '항목 상태 "재시작 필요"', r.restartStatus);
    r.history = await a.$$eval('.config-settings__history-table tbody tr', (rows) => rows.map((tr) => [...tr.cells].slice(1).map((c) => c.textContent)));
    check(r.history.length >= 4 && r.history.some((h) => h[0] === 'admin' && h[1] === '디스크 경고 비율' && h[3] === '75') && r.history.some((h) => h[1] === '디스크 경고 비율' && h[2] === '75'), '변경 이력 표(사용자·항목·이전·이후)', r.history.slice(0, 6));
    await a.screenshot({ path: path.join(OUT, 'P27F_restart_banner.png') });
    await a.evaluate(() => document.querySelector('.config-settings__history').scrollIntoView());
    await a.screenshot({ path: path.join(OUT, 'P27F_history.png') });
  } finally {
    await admin.browser.close();
  }
  const u = await session(userLogin);
  try {
    const res = await u.page.goto(`${BASE}/admin/config-settings`, { waitUntil: 'networkidle0' });
    r.userPage = res.status();
    r.userMenu = await u.page.$('[data-nav="admin.settings"]') !== null;
    r.userApi = (await apiCall(u.page, 'GET', '/api/v1/admin/config-settings')).status;
    check(r.userPage === 403 && !r.userMenu && r.userApi === 403, '일반 사용자: 화면 403·메뉴 없음·API 403', [r.userPage, r.userMenu, r.userApi]);
    await u.page.screenshot({ path: path.join(OUT, 'P27F_user_403.png') });
  } finally {
    await u.browser.close();
  }
} else {
  await tabsPhase();
}
console.log(JSON.stringify(r, null, 1));
if (csp.length) problems.push(`CSP 위반 ${csp.length}: ${csp.join(' | ')}`);
console.log(problems.length ? `문제 ${problems.length}개:\n- ${problems.join('\n- ')}` : '모든 점검 통과(CSP 위반 0, 콘솔 오류 0)');
process.exit(problems.length ? 1 : 0);
