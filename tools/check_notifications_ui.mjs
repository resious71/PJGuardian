// 앱 안 알림 브라우저 점검(P24, 헤드리스 Chrome). 격리 서버·DB 사본(017 적용 + 알림이 있는 사용자)에서만 쓴다 — 읽음·삭제·설정·접근을 실제로 바꾼다.
//   PJG_PASSWORD=... node tools/check_notifications_ui.mjs <기본 주소> <일반 사용자(알림 100건 이상)> <그 사용자가 보는 프로젝트> [관리자] [--poll]
// 확인(1280·1024px):
//   - 헤더: 알림 메뉴의 숫자 배지(99+), 접근 가능한 이름 "알림, 안 읽은 알림 N개", 0이면 배지 숨김
//   - 패널: 열기(aria-expanded)·최근 8건·안 읽음 굵게+점+"안 읽음"·Esc로 닫고 버튼으로 포커스·바깥 클릭으로 닫힘·키보드(Enter로 열면 첫 항목, ↓↑),
//     항목 클릭 → 읽음 + 대상 화면 이동, "모두 보기" → 목록
//   - 목록: 종류·안 읽은 알림만 → 검색(주소에 조건, 뒤로 가기 복원, 바꾸고 검색 전 안내), 읽음/안 읽음 표시·삭제, 모두 읽음(배지 사라짐), 읽은 알림 모두 삭제(확인 창)
//   - 알림 설정 탭: 스위치 저장(role=status)·새로 고침 뒤 유지·기본값으로
//   - 관리자 알림 보관: 값 표시·범위 밖 입력 안내·저장(감사 setting.update)·되돌림
//   - 접근 회수 → 그 프로젝트 알림이 목록·개수에서 사라지고 복구하면 다시 보임
//   - 가로 스크롤 0(1280·1024), 콘솔 오류 0, CSP 위반 0
//   - --poll: 헤더 개수 요청이 탭이 보이는 동안 분당 1회, 숨기면 없음, 다시 보이면 즉시(약 2.5분)
// 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P24_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const args = process.argv.slice(2).filter((a) => !a.startsWith('--'));
const POLL = process.argv.includes('--poll');
const [baseArg, userLogin, projectId, adminLogin = 'admin'] = args;
if (!userLogin || !projectId || !process.env.PJG_PASSWORD) {
  console.error('사용법: PJG_PASSWORD=... node tools/check_notifications_ui.mjs <기본 주소> <일반 사용자> <프로젝트> [관리자] [--poll]');
  process.exit(2);
}
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

async function session(login) {
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
  const page = await browser.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (/status of (400|404)/.test(m.text())) return; // 의도한 오류 응답(범위 밖 저장 등)
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
const bell = (page) => page.evaluate(() => {
  const b = document.querySelector('[data-notif-bell]');
  const badge = b.querySelector('[data-notif-badge]');
  return { label: b.getAttribute('aria-label'), expanded: b.getAttribute('aria-expanded'), role: b.getAttribute('role'), badge: badge.hidden ? '' : badge.textContent, controls: b.getAttribute('aria-controls') };
});
const waitBadge = (page, fn) => page.waitForFunction(fn, { timeout: 15000 });

const user = await session(userLogin);
const p = user.page;
try {
  // ---- 헤더 배지 ----
  await p.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
  await waitBadge(p, () => !document.querySelector('[data-notif-badge]').hidden);
  const total0 = (await api(p, 'GET', '/api/v1/notifications/unread-count')).json.data.count;
  r.bell = await bell(p);
  r.unread0 = total0;
  check(r.bell.badge === (total0 > 99 ? '99+' : String(total0)), '배지 숫자', r.bell);
  check(r.bell.label === `알림, 안 읽은 알림 ${total0 > 99 ? '99+' : total0}개` && r.bell.role === 'button' && r.bell.controls === 'notif-panel', '접근 가능한 이름·버튼', r.bell);
  await p.screenshot({ path: path.join(OUT, 'P24_header_badge.png'), clip: { x: 0, y: 0, width: 1280, height: 120 } });

  // ---- 패널(마우스) ----
  await p.click('[data-notif-bell]');
  await p.waitForSelector('#notif-panel:not([hidden]) .notif-item', { timeout: 10000 });
  r.panel = await p.evaluate(() => ({
    items: document.querySelectorAll('#notif-panel .notif-item').length,
    unread: document.querySelectorAll('#notif-panel .notif-item.is-unread').length,
    unreadText: document.querySelector('#notif-panel .notif-item.is-unread .notif-item__unread')?.textContent ?? null,
    bold: getComputedStyle(document.querySelector('#notif-panel .notif-item.is-unread .notif-item__text') ?? document.body).fontWeight,
    expanded: document.querySelector('[data-notif-bell]').getAttribute('aria-expanded'),
    all: document.querySelector('#notif-panel .notif-panel__all')?.getAttribute('href'),
    text: [...document.querySelectorAll('#notif-panel .notif-item__text')].slice(0, 3).map((n) => n.textContent),
    rect: document.getElementById('notif-panel').getBoundingClientRect().toJSON(),
  }));
  check(r.panel.items === 8 && r.panel.expanded === 'true' && r.panel.unreadText === '안 읽음' && Number(r.panel.bold) >= 700, '패널 8건·안 읽음 표시', r.panel);
  check(r.panel.rect.right <= 1280 && r.panel.rect.left >= 0, '패널이 화면 안', r.panel.rect);
  await p.screenshot({ path: path.join(OUT, 'P24_panel_1280.png'), clip: { x: 0, y: 0, width: 1280, height: Math.min(900, Math.ceil(r.panel.rect.bottom) + 16) } });
  await p.keyboard.press('Escape');
  r.afterEsc = await p.evaluate(() => ({ hidden: document.getElementById('notif-panel').hidden, focus: document.activeElement?.hasAttribute('data-notif-bell') ?? false }));
  check(r.afterEsc.hidden && r.afterEsc.focus, 'Esc로 닫고 버튼으로 포커스', r.afterEsc);
  // 바깥 클릭
  await p.click('[data-notif-bell]');
  await p.waitForSelector('#notif-panel:not([hidden])');
  await p.mouse.click(600, 700);
  check(await p.$eval('#notif-panel', (n) => n.hidden), '바깥 클릭으로 닫힘');

  // ---- 패널(키보드만) ----
  await p.focus('[data-notif-bell]');
  await p.keyboard.press('Enter');
  await p.waitForFunction(() => document.activeElement?.classList.contains('notif-item__link'), { timeout: 10000 });
  const k1 = await p.evaluate(() => [...document.querySelectorAll('#notif-panel .notif-item__link')].indexOf(document.activeElement));
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('ArrowDown');
  const k2 = await p.evaluate(() => [...document.querySelectorAll('#notif-panel .notif-item__link')].indexOf(document.activeElement));
  await p.keyboard.press('End');
  const k3 = await p.evaluate(() => [...document.querySelectorAll('#notif-panel .notif-item__link')].indexOf(document.activeElement));
  await p.keyboard.press('ArrowDown');
  const k4 = await p.evaluate(() => [...document.querySelectorAll('#notif-panel .notif-item__link')].indexOf(document.activeElement));
  r.keyboard = [k1, k2, k3, k4];
  check(JSON.stringify(r.keyboard) === JSON.stringify([0, 2, 7, 0]), '키보드: 첫 항목·↓·End·돌기', r.keyboard);
  await p.keyboard.press('Escape');

  // ---- 항목 클릭 → 읽음 + 이동 ----
  const first = (await api(p, 'GET', '/api/v1/notifications?size=8')).json.data.find((x) => x.read_at === null && x.href);
  await p.click('[data-notif-bell]');
  await p.waitForSelector(`#notif-panel .notif-item[data-id="${first.id}"] a`);
  await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle0' }), p.click(`#notif-panel .notif-item[data-id="${first.id}"] a`)]);
  const after = (await api(p, 'GET', `/api/v1/notifications?size=200`)).json.data.find((x) => x.id === first.id);
  r.open = { href: first.href, url: new URL(p.url()).pathname + new URL(p.url()).search, read: after?.read_at !== null };
  check(r.open.url === first.href && r.open.read, '클릭 → 읽음 + 대상 화면', r.open);
  await waitBadge(p, (n) => document.querySelector('[data-notif-badge]').textContent !== '' , undefined);

  // ---- 목록 화면 ----
  await p.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  await p.waitForSelector('[data-items] .notif-item');
  r.list = await p.evaluate(() => ({ count: document.querySelector('[data-count]').textContent, items: document.querySelectorAll('[data-items] .notif-item').length, pager: !document.querySelector('[data-pagination]').hidden }));
  check(r.list.items === 50 && r.list.pager, '목록 첫 쪽 50건·쪽 이동', r.list);
  r.scroll1280 = await scrollX(p);
  await p.screenshot({ path: path.join(OUT, 'P24_list_1280.png'), fullPage: false });
  // 조건: 종류 = 새 결함·해결 요약, 안 읽은 알림만 → 바꾼 뒤 안내 → 검색
  await p.click('#notif-kind');
  await p.type('#notif-kind', '요약');
  await p.keyboard.press('ArrowDown');
  await p.keyboard.press('Enter');
  await p.click('[data-unread]');
  r.dirty = await p.$eval('[data-dirty]', (n) => ({ hidden: n.hidden, text: n.textContent }));
  check(!r.dirty.hidden, '검색 전 바뀜 안내', r.dirty);
  await p.screenshot({ path: path.join(OUT, 'P24_list_dirty.png') });
  await p.click('[data-search]');
  await p.waitForFunction(() => location.search.includes('kind=analysis_summary') && location.search.includes('unread=1'), { timeout: 10000 });
  await p.waitForFunction(() => !document.querySelector('[data-loading]').hidden === false);
  await sleep(300);
  r.filtered = await p.evaluate(() => ({ search: location.search, kinds: [...document.querySelectorAll('[data-items] .notif-item__kind')].map((n) => n.textContent), dirty: document.querySelector('[data-dirty]').hidden }));
  check(r.filtered.kinds.length > 0 && r.filtered.kinds.every((k) => k === '새 결함·해결 요약') && r.filtered.dirty, '조건 적용·주소', r.filtered);
  await p.goBack({ waitUntil: 'networkidle0' }).catch(() => {});
  await sleep(500);
  r.back = await p.evaluate(() => ({ search: location.search, kind: document.querySelector('#notif-kind-native')?.value ?? document.querySelector('[data-kind]').value, unread: document.querySelector('[data-unread]').checked }));
  check(r.back.search === '' && r.back.kind === '' && !r.back.unread, '뒤로 가기 복원', r.back);
  // 항목별 읽음/안 읽음·삭제
  await p.goto(`${BASE}/notifications?kind=defect_assigned`, { waitUntil: 'networkidle0' });
  await p.waitForSelector('[data-items] .notif-item');
  const firstId = await p.$eval('[data-items] .notif-item', (n) => n.dataset.id);
  const before = await p.$eval('[data-items] .notif-item', (n) => n.classList.contains('is-unread'));
  await p.click(`[data-items] .notif-item[data-id="${firstId}"] .notif-item__actions button`);
  await p.waitForFunction((id, was) => document.querySelector(`[data-items] .notif-item[data-id="${id}"]`)?.classList.contains('is-unread') === !was, { timeout: 10000 }, firstId, before);
  r.toggle = 'ok';
  const n0 = await p.$$eval('[data-items] .notif-item', (ns) => ns.length);
  await p.click(`[data-items] .notif-item[data-id="${firstId}"] .notif-item__actions button:last-child`);
  await p.waitForFunction((id) => !document.querySelector(`[data-items] .notif-item[data-id="${id}"]`), { timeout: 10000 }, firstId);
  r.deleteOne = { before: n0, after: await p.$$eval('[data-items] .notif-item', (ns) => ns.length) };
  // 모두 읽음 → 배지 사라짐
  await p.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  await p.click('[data-read-all]');
  await waitBadge(p, () => document.querySelector('[data-notif-badge]').hidden);
  r.readAll = await bell(p);
  check(r.readAll.badge === '' && r.readAll.label === '알림, 안 읽은 알림 없음', '모두 읽음 → 배지 숨김', r.readAll);
  // 읽은 알림 모두 삭제(확인 창)
  await p.click('[data-delete-read]');
  await p.waitForSelector('dialog[open]');
  await p.screenshot({ path: path.join(OUT, 'P24_delete_read_dialog.png') });
  await p.click('dialog[open] .btn--danger');
  await p.waitForSelector('[data-empty]:not([hidden])', { timeout: 10000 });
  r.empty = await p.$eval('[data-empty]', (n) => n.textContent.replace(/\s+/g, ' ').trim());
  check(r.empty.includes('받은 알림이 없습니다.'), '빈 목록 안내', r.empty);
  await p.screenshot({ path: path.join(OUT, 'P24_list_empty.png') });

  // ---- 알림 설정 탭 ----
  await p.click('#tab-settings');
  await p.waitForFunction(() => !document.querySelector('[data-pref="analysis_summary"]').disabled);
  await p.click('[data-pref="analysis_summary"]');
  await p.waitForFunction(() => document.querySelector('[data-pref-status]').textContent.includes('껐습니다'), { timeout: 10000 });
  r.prefStatus = await p.$eval('[data-pref-status]', (n) => n.textContent);
  await p.screenshot({ path: path.join(OUT, 'P24_settings.png') });
  await p.reload({ waitUntil: 'networkidle0' });
  await p.click('#tab-settings');
  await p.waitForFunction(() => !document.querySelector('[data-pref="analysis_summary"]').disabled);
  r.prefPersist = await p.$eval('[data-pref="analysis_summary"]', (n) => n.getAttribute('aria-checked'));
  check(r.prefPersist === 'false', '설정 유지', r.prefPersist);
  // 키보드로 스위치(Space)
  await p.focus('[data-pref="defect_changed"]');
  await p.keyboard.press('Space');
  await p.waitForFunction(() => document.querySelector('[data-pref="defect_changed"]').getAttribute('aria-checked') === 'false', { timeout: 10000 });
  await p.click('[data-pref-reset]');
  await p.waitForFunction(() => [...document.querySelectorAll('[data-pref]')].every((b) => b.getAttribute('aria-checked') === 'true'), { timeout: 10000 });
  r.prefReset = await p.$eval('[data-pref-status]', (n) => n.textContent);

  // ---- 1024px ----
  await p.setViewport({ width: 1024, height: 800 });
  await p.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  r.scroll1024 = await scrollX(p);
  await p.screenshot({ path: path.join(OUT, 'P24_list_1024.png') });
  await p.click('[data-notif-bell]');
  await p.waitForSelector('#notif-panel:not([hidden])');
  await sleep(500);
  r.panel1024 = await p.evaluate(() => document.getElementById('notif-panel').getBoundingClientRect().toJSON());
  check(r.panel1024.right <= 1024 && r.panel1024.left >= 0, '패널이 1024 화면 안', r.panel1024);
  await p.screenshot({ path: path.join(OUT, 'P24_panel_1024.png') });
  await p.keyboard.press('Escape');
  check(r.scroll1280 <= 0 && r.scroll1024 <= 0, '가로 스크롤 0', [r.scroll1280, r.scroll1024]);
  await p.setViewport({ width: 1280, height: 900 });
} catch (e) {
  problems.push(`예외(사용자): ${e.stack ?? e}`);
}

// ---- 관리자: 알림 보관 설정, 접근 회수 ----
const admin = await session(adminLogin);
const a = admin.page;
try {
  await a.goto(`${BASE}/admin/config-settings#notification`, { waitUntil: 'networkidle0' });
  await a.waitForFunction(() => !document.querySelector('[data-save]').disabled);
  r.adminValues = await a.$$eval('[data-setting]', (ns) => ns.map((n) => [n.dataset.setting, n.value]));
  await a.click('#an-retain_read_days', { clickCount: 3 });
  await a.type('#an-retain_read_days', '0');
  await a.click('[data-save]');
  await a.waitForFunction(() => !document.querySelector('#an-retain_read_days-error').hidden);
  r.adminInvalid = await a.$eval('#an-retain_read_days-error', (n) => n.textContent);
  check(r.adminInvalid === '1~365 사이의 정수를 입력하세요.', '범위 밖 안내', r.adminInvalid);
  await a.screenshot({ path: path.join(OUT, 'P24_admin_invalid.png') });
  await a.click('#an-retain_read_days', { clickCount: 3 });
  await a.type('#an-retain_read_days', '5');
  await a.click('[data-save]');
  await a.waitForFunction(() => document.querySelector('[data-status]').textContent.includes('저장'), { timeout: 10000 });
  const saved = await api(a, 'GET', '/api/v1/admin/settings');
  r.adminSaved = saved.json.data['notifications.retain_read_days'].value;
  check(r.adminSaved === 5, '저장', r.adminSaved);
  // 서버 검증(화면을 거치지 않고)
  r.adminServer400 = (await api(a, 'PATCH', '/api/v1/admin/settings', { 'notifications.retain_unread_days': 366 })).status;
  check(r.adminServer400 === 400, '서버 범위 검증 400', r.adminServer400);
  await a.screenshot({ path: path.join(OUT, 'P24_admin_saved.png') });
  await api(a, 'PATCH', '/api/v1/admin/settings', { 'notifications.retain_read_days': 7 });
  await a.setViewport({ width: 1024, height: 800 });
  await a.reload({ waitUntil: 'networkidle0' });
  r.adminScroll1024 = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P24_admin_1024.png') });
  check(r.adminScroll1024 <= 0, '관리자 화면 1024 가로 스크롤 0', r.adminScroll1024);
  await a.setViewport({ width: 1280, height: 900 });

  // 접근 회수 → 사용자 화면에서 그 프로젝트 알림이 사라짐
  await p.goto(`${BASE}/notifications`, { waitUntil: 'networkidle0' });
  const visibleBefore = (await api(p, 'GET', '/api/v1/notifications?size=200')).json.data.filter((x) => x.project?.id === Number(projectId)).length;
  // 새 알림 하나(관리자가 결함을 사용자에게 지정) → 배지
  const uid = (await api(a, 'GET', `/api/v1/projects/${projectId}/assignees?q=${encodeURIComponent(userLogin)}`)).json?.data?.find((u) => u.login_id === userLogin)?.id;
  const d = (await api(a, 'GET', `/api/v1/projects/${projectId}/defects?size=1&status=open`)).json.data[0];
  await api(a, 'PATCH', `/api/v1/defects/${d.id}`, { version: d.version, assignee_id: null });
  const d2 = (await api(a, 'GET', `/api/v1/defects/${d.id}`)).json.data;
  await api(a, 'PATCH', `/api/v1/defects/${d.id}`, { version: d2.version, assignee_id: uid });
  await p.reload({ waitUntil: 'networkidle0' });
  await waitBadge(p, () => document.querySelector('[data-notif-badge]').textContent === '1');
  r.newBadge = (await bell(p)).badge;
  const rm = await api(a, 'DELETE', `/api/v1/projects/${projectId}/access/user/${uid}`);
  await p.reload({ waitUntil: 'networkidle0' });
  await sleep(800);
  const afterRevoke = (await api(p, 'GET', '/api/v1/notifications?size=200')).json;
  r.revoke = { removed: rm.status, before: visibleBefore + 1, after: afterRevoke.data.filter((x) => x.project?.id === Number(projectId)).length, badge: (await bell(p)).badge,
    listText: await p.$eval('[data-items]', (n) => n.children.length) };
  check(r.revoke.after === 0 && r.revoke.badge === '', '접근 회수 → 목록·배지에서 사라짐', r.revoke);
  await api(a, 'POST', `/api/v1/projects/${projectId}/access`, { subject_type: 'user', login_id: userLogin });
  await p.reload({ waitUntil: 'networkidle0' });
  await waitBadge(p, () => document.querySelector('[data-notif-badge]').textContent === '1');
  r.restored = (await bell(p)).badge;
} catch (e) {
  problems.push(`예외(관리자): ${e.stack ?? e}`);
}

// ---- 폴링(선택, 약 2.5분) ----
if (POLL) {
  try {
    const hits = [];
    const page2 = await user.browser.newPage();
    page2.on('request', (q) => { if (q.url().includes('/api/v1/notifications/unread-count')) hits.push({ t: Date.now(), bg: q.headers()['x-pjg-background'] ?? null }); });
    await page2.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
    const t0 = Date.now();
    await sleep(65000);
    const visible = hits.filter((h) => h.t >= t0 - 5000).length;
    await page2.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    const t1 = Date.now();
    await sleep(65000);
    const hidden = hits.filter((h) => h.t > t1 && h.t < Date.now()).length;
    const t2 = Date.now(); // 보이게 바꾸는 순간 요청이 나가므로 바꾸기 전 시각
    await page2.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await sleep(1500);
    const back = hits.filter((h) => h.t > t2).length;
    r.poll = { visible65s: visible, hidden65s: hidden, onVisible: back, background: hits.every((h) => h.bg === '1') };
    check(visible === 2 && hidden === 0 && back === 1 && r.poll.background, '폴링: 보이는 동안 분당 1회(열 때 + 60초), 숨김 0, 다시 보이면 즉시', r.poll);
    await page2.close();
  } catch (e) {
    problems.push(`예외(폴링): ${e.stack ?? e}`);
  }
}

await user.browser.close();
await admin.browser.close();
console.log(JSON.stringify({ r, csp, problems }, null, 2));
process.exit(problems.length === 0 && csp.length === 0 ? 0 : 1);
