// 가이드 화면 브라우저 점검(P29, 헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo — 임시 폴더·새 DB·가짜 계정, 별도 포트)을 직접 띄우고
// 끝나면 서버를 멈추고 폴더를 지운다(실제 DB·설정·워커와 무관). 실패 분석 1건은 점검이 설치본 DB에 직접 넣는다.
//   node tools/check_guide_ui.mjs [--port=8097] [--keep] [--no-shots]
// 흐름: 일반 사용자 — 가이드 목차·해시 이동·이전/다음·관련 절, 검색(강조·목차 표시·짧은 검색어·Esc), 그림 확대(Esc·포커스 복귀), 오류 코드 탭(#코드 행),
//   화면 도움말 링크(프로젝트 목록 → 절), 분석 실패 "자세히 보기" → analysis-failures, 관리자 가이드(화면·API·검색·그림) 403에 관리자 내용 없음, 관리자 탭 없음,
//   라이선스 검색, 아이콘 범례. 관리자 — 관리자 탭·관리자 가이드 화면. 한국어·영어 × 라이트·다크 × 1280·1024(가로 스크롤 0, 영어 화면 목차에 한글 없음) 캡처 P29_*.png.
//   콘솔 오류 0, CSP 위반 0. 비밀번호는 무작위로 만들고 출력하지 않는다.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8097);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const PREFIX = process.env.PJG_SHOT_PREFIX ?? 'X11'; // 캡처 이름 앞부분(P29 → X11 절 단위 화면)
// P31: 캡처는 모두 이 함수로 — --no-shots면 아무것도 쓰지 않는다(예전에는 일부 캡처가 옵션과 관계없이 docs/results에 남았다)
const SHOTS = !opt('no-shots');
async function shot(page, file, extra = {}) {
  if (SHOTS) await page.screenshot({ path: path.join(OUT, file), ...extra });
}
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `guide_check_${randomBytes(4).toString('hex')}`);
const PW = `Demo-${randomBytes(9).toString('base64url')}1!`;
const BASE = `http://127.0.0.1:${PORT}`;
const csp = [];
const problems = [];
const r = {};
const check = (ok, name, detail) => { if (!ok) problems.push(`${name}: ${JSON.stringify(detail)}`); };
const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });
let server = null;
let browser = null;
process.on('exit', () => {
  try { server?.kill(); } catch { /* 이미 끝남 */ }
  if (!opt('keep')) rmSync(work, { recursive: true, force: true });
});

mkdirSync(work, { recursive: true });
execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: PW }, stdio: ['ignore', 'pipe', 'inherit'] });
// 실패 분석 1건(가짜 설치본 DB, prepared statement)
const failedId = Number(execFileSync(PHP, ['-r', `$d = new PDO('sqlite:' . $argv[1]); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$s = $d->prepare("INSERT INTO analyses (project_id, status, source_kind, trigger_kind, requested_by, tool, queued_at, started_at, finished_at, failure_code) VALUES (1, 'failed', 'upload', 'user', (SELECT id FROM users WHERE login_id = ?), 'eslint', ?, ?, ?, 'tool_crash')");
$s->execute(['alex', '2026-10-02T01:00:00Z', '2026-10-02T01:00:05Z', '2026-10-02T01:01:00Z']); echo $d->lastInsertId();`, path.join(work, 'db/demo.sqlite')], { encoding: 'utf8' }).trim());
server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: path.join(work, 'config.ini') }, stdio: 'ignore' });
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
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[0-9]/.test(m.text())) problems.push(`console.${m.type()}: ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.emulateTimezone('Asia/Seoul');
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  return page;
}
const prefs = async (page, language, theme) => {
  const res = await page.evaluate(async (l, th) => { const x = await fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: l, theme: th }) });
  return { status: x.status, body: await x.text() }; }, language, theme);
  (r.prefs ??= []).push(`${language}/${theme} ${res.status} ${res.body.slice(0, 160)}`);
  return res.status;
};
const status = (page, url) => page.evaluate(async (u) => { const res = await fetch(u, { credentials: 'same-origin' }); return { code: res.status, text: await res.text() }; }, url);
const guideReady = (page) => page.waitForSelector('[data-guide-body] article.guide-section', { timeout: 15000 });
const noHScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
// 가로로 넘치는 요소(가장 바깥쪽만, 원인 찾기용)
const overflowing = (page) => page.evaluate(() => { const w = document.documentElement.clientWidth;
  return [...document.querySelectorAll('body *')].filter((n) => n.getBoundingClientRect().right > w + 0.5 && !(n.parentElement && n.parentElement.getBoundingClientRect().right > w + 0.5))
    .slice(0, 5).map((n) => `${n.tagName.toLowerCase()}.${[...n.classList].join('.')} right=${Math.round(n.getBoundingClientRect().right)} w=${Math.round(n.getBoundingClientRect().width)}`); });

try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const user = await session('alex');
  check(await prefs(user, 'ko', 'light') === 200, '언어 바꾸기', null);

  // 1. 목차·해시·이전/다음
  await user.goto(`${BASE}/guide`, { waitUntil: 'networkidle0' });
  await guideReady(user);
  r.user = await user.evaluate(() => ({
    path: location.pathname,
    tabs: [...document.querySelectorAll('[data-guide-tab]')].map((a) => a.dataset.guideTab),
    current: document.querySelector('[data-guide-tab][aria-current]')?.dataset.guideTab ?? null,
    toc: document.querySelectorAll('[data-guide-toc] li').length,
    sections: document.querySelectorAll('[data-guide-body] article.guide-section').length,
    first: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key,
    prevDisabled: document.querySelector('[data-nav="prev"]')?.disabled ?? null,
    rawMarkup: /\{\{|guide-img:|guide:/.test(document.querySelector('[data-guide-body]').textContent),
  }));
  // X11: 본문에는 한 번에 한 절(해시 없으면 첫 절, 첫 절의 이전 단추는 비활성), 목차는 17
  check(r.user.sections === 1 && r.user.toc === 17 && r.user.first === 'getting-started' && r.user.prevDisabled === true, '절 하나·목차 17·첫 절', r.user);
  check(!r.user.tabs.includes('admin') && r.user.tabs.includes('user') && r.user.tabs.includes('errors'), '일반 사용자에게 관리자 탭 없음', r.user.tabs);
  check(!r.user.rawMarkup, '자리표시자·내부 링크가 글자로 남지 않음', r.user);
  await user.click('[data-guide-toc] a[data-key="upload-errors"]');
  await sleep(300);
  r.hash = await user.evaluate(() => ({ hash: location.hash, target: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key, current: document.querySelector('[data-guide-toc] a[aria-current]')?.dataset.key,
    focus: document.activeElement?.id, title: document.title, count: document.querySelectorAll('[data-guide-body] article.guide-section').length }));
  check(r.hash.hash === '#upload-errors' && r.hash.target === 'upload-errors' && r.hash.current === 'upload-errors' && r.hash.focus === 'guide-h-upload-errors' && r.hash.count === 1 && r.hash.title.startsWith(await user.$eval('#guide-h-upload-errors', (h) => h.textContent)), '목차 → 절(한 절·제목 포커스·문서 제목)', r.hash);
  await user.click('[data-nav="next"]');
  await sleep(300);
  r.next = await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key);
  check(r.next === 'analysis-status', '다음 절', r.next);
  await user.goBack();
  await sleep(300);
  check(await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) === 'upload-errors', '뒤로 가기 → 앞 절', null);

  // X11: 새로고침해도 같은 절, 없는 key → 첫 절 + 안내, 소제목 앵커, 목차 화살표
  await user.reload({ waitUntil: 'networkidle0' });
  await guideReady(user);
  check(await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) === 'upload-errors', '새로고침 → 같은 절', null);
  await user.evaluate(() => { location.hash = 'no-such-section'; });
  await sleep(300);
  r.unknown = await user.evaluate(() => ({ hash: location.hash, key: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key, notice: !!document.querySelector('[data-unknown-section]') }));
  check(r.unknown.key === 'getting-started' && r.unknown.hash === '#getting-started' && r.unknown.notice, '없는 key → 첫 절 + 안내', r.unknown);
  await user.evaluate(() => { location.hash = 'upload'; });
  await sleep(300);
  r.contents = await user.evaluate(() => [...document.querySelectorAll('.guide-section__contents a')].map((a) => a.getAttribute('href')));
  if (r.contents.length) {
    await user.click('.guide-section__contents li:nth-child(2) a');
    await sleep(300);
    r.subFocus = await user.evaluate(() => ({ hash: decodeURIComponent(location.hash), focus: document.activeElement?.id ?? '' }));
    check(r.subFocus.focus.startsWith('guide-upload--'), '소제목 앵커 → 그 소제목', r.subFocus);
  } else problems.push('upload 절에 "이 절의 내용" 없음');
  await user.focus('[data-guide-toc] a[data-key="projects"]');
  await user.keyboard.press('ArrowDown');
  check(await user.evaluate(() => document.activeElement?.dataset.key) === 'upload', '목차 아래 화살표', null);
  await user.keyboard.press('Enter');
  await sleep(300);
  check(await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) === 'upload', '목차 Enter → 절', null);

  // 2. 검색  await user.click('#guide-search');
  await user.type('#guide-search', 'zip');
  await user.waitForFunction(() => document.querySelector('[data-guide-search-status]').textContent.trim() !== '', { timeout: 5000 });
  r.search = await user.evaluate(() => ({ status: document.querySelector('[data-guide-search-status]').textContent.trim(), marks: document.querySelectorAll('[data-guide-body] mark.guide-mark').length,
    match: document.querySelectorAll('[data-guide-toc] li.guide__toc-match').length, miss: document.querySelectorAll('[data-guide-toc] li.guide__toc-miss').length }));
  r.search.results = await user.evaluate(() => [...document.querySelectorAll('[data-guide-results] a')].map((a) => a.dataset.result));
  check(r.search.match > 0 && r.search.match + r.search.miss === 17 && r.search.results.length === r.search.match, '검색 결과 = 절 목록·목차 표시', r.search);
  await user.click('[data-guide-results] a[data-result="upload-errors"]');
  await sleep(400);
  r.searchOpen = await user.evaluate(() => ({ key: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key, marks: document.querySelectorAll('[data-guide-body] mark.guide-mark').length }));
  check(r.searchOpen.key === 'upload-errors' && r.searchOpen.marks > 0, '결과 → 그 절 열림·강조', r.searchOpen);
  await user.focus('#guide-search');
  await shot(user, `${PREFIX}_search_ko.png`);
  await user.keyboard.press('Escape');
  await sleep(200);
  r.searchCleared = await user.evaluate(() => ({ value: document.querySelector('#guide-search').value, marks: document.querySelectorAll('mark.guide-mark').length }));
  check(r.searchCleared.value === '' && r.searchCleared.marks === 0, 'Esc로 검색 지움', r.searchCleared);
  await user.type('#guide-search', 'z');
  await sleep(500);
  r.short = await user.evaluate(() => document.querySelector('[data-guide-search-status]').textContent.trim());
  check(r.short !== '', '한 글자 검색 안내', r.short);
  await user.keyboard.press('Escape');

  // 3. 그림 확대(그림이 있는 절)
  await user.evaluate(() => { location.hash = 'upload'; });
  await sleep(300);
  const imgBtn = await user.$('.guide-img-btn');
  await imgBtn.evaluate((b) => b.scrollIntoView({ block: 'center' }));
  await imgBtn.click();
  await sleep(300);
  r.dialog = await user.evaluate(() => { const d = document.querySelector('[data-guide-img-dialog]'); const im = d.querySelector('[data-guide-img-full]');
    return { open: d.open, alt: im.alt.length > 0, loaded: im.complete && im.naturalWidth > 0, focus: document.activeElement?.hasAttribute('data-guide-img-close') }; });
  check(r.dialog.open && r.dialog.alt && r.dialog.loaded && r.dialog.focus, '그림 확대 대화상자', r.dialog);
  await shot(user, `${PREFIX}_image_dialog.png`);
  await user.keyboard.press('Escape');
  await sleep(200);
  r.dialogClosed = await user.evaluate(() => ({ open: document.querySelector('[data-guide-img-dialog]').open, back: document.activeElement?.classList.contains('guide-img-btn') }));
  check(!r.dialogClosed.open && r.dialogClosed.back, 'Esc로 닫고 포커스 복귀', r.dialogClosed);

  // 4. 오류 코드 탭
  const codes = JSON.parse((await status(user, '/api/v1/guide/errors')).text).data.groups;
  r.errorGroups = codes.map((g) => `${g.kind}:${g.items.length}→${g.guide_key}`);
  const code = codes.find((g) => g.kind === 'analysis').items[0].code;
  await user.goto(`${BASE}/guide/errors#${encodeURIComponent(code)}`, { waitUntil: 'networkidle0' });
  await user.waitForSelector('tr.guide-errors__row--target', { timeout: 10000 });
  r.errors = await user.evaluate(() => ({ target: document.querySelector('tr.guide-errors__row--target')?.dataset.code, rows: document.querySelectorAll('[data-guide-errors-body] tbody tr').length,
    links: [...document.querySelectorAll('[data-guide-link]')].map((a) => a.dataset.guideLink) }));
  check(r.errors.target === code && r.errors.rows > 20, '오류 코드 행', r.errors);
  check(['upload-errors', 'analysis-failures', 'login-errors'].every((k) => r.errors.links.includes(k)), '오류 종류 → 가이드 절 링크', r.errors.links);
  check(await noHScroll(user), '오류 코드 탭 가로 스크롤 없음', null);
  await shot(user, `${PREFIX}_errors_ko.png`);

  // 5. 화면 도움말 링크(프로젝트 목록)
  await user.goto(`${BASE}/projects`, { waitUntil: 'networkidle0' });
  r.help = await user.evaluate(() => document.querySelector('a.help-link')?.getAttribute('href') ?? null);
  check(r.help?.endsWith('/guide/user#projects'), '도움말 링크', r.help);
  await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('a.help-link')]);
  await guideReady(user);
  await sleep(300);
  check(await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) === 'projects', '도움말 링크 → 절', null);

  // 6. 분석 실패 "자세히 보기"
  await user.goto(`${BASE}/analyses/${failedId}`, { waitUntil: 'networkidle0' });
  await user.waitForSelector('[data-failure-guide]:not([hidden])', { timeout: 10000 });
  r.failure = await user.evaluate(() => ({ guide: document.querySelector('[data-failure-guide-link]')?.getAttribute('href'), code: document.querySelector('[data-failure-code-link]')?.getAttribute('href') ?? null }));
  check(r.failure.guide?.endsWith('/guide/user#analysis-failures'), '실패 화면 자세히 보기', r.failure);
  await shot(user, `${PREFIX}_failure_link.png`);
  await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click('[data-failure-guide-link]')]);
  await guideReady(user);
  await sleep(300);
  check(await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) === 'analysis-failures', '자세히 보기 → 분석 실패 절', null);

  // 7. 관리자 가이드는 일반 사용자에게 403(내용 없음)
  const adminTitles = ['관리자 가이드 안내', 'About the admin guide', 'overview'];
  r.forbidden = {};
  for (const u of ['/guide/admin', '/guide/admin#overview', '/api/v1/guide/admin', '/api/v1/guide/admin/search?q=guide', '/guide/img/admin/anything', '/guide/img/admin/no-such']) {
    const res = await status(user, u);
    r.forbidden[u] = res.code;
    check(res.code === 403, `403 ${u}`, res.code);
    check(!adminTitles.some((x) => res.text.includes(x)), `403에 관리자 내용 없음 ${u}`, null);
  }
  const userSearch = JSON.parse((await status(user, '/api/v1/guide/user/search?q=' + encodeURIComponent('관리자 가이드 안내'))).text).data.results.map((x) => x.key);
  check(!userSearch.includes('overview'), '사용자 검색에 관리자 절 없음', userSearch);

  // 8. 라이선스·아이콘 범례
  await user.goto(`${BASE}/guide/licenses`, { waitUntil: 'networkidle0' });
  await user.type('#guide-licenses-search', 'MIT');
  await sleep(500);
  r.licenses = await user.evaluate(() => ({ len: document.querySelector('[data-licenses]').textContent.length, marks: document.querySelectorAll('[data-licenses] mark.guide-mark').length,
    status: document.querySelector('[data-licenses-status]').textContent.trim() }));
  check(r.licenses.len > 1000 && r.licenses.marks > 0, '라이선스 검색', r.licenses);
  // X10(위치만 옮김): 바닥은 한 줄(버전·라이선스·아이콘 뜻 링크), 범례는 "화면 읽는 법" 절 본문에만
  r.licensesFooter = await user.evaluate(() => ({ lines: document.querySelectorAll('.guide-footer__line').length, legend: document.querySelectorAll('[data-legend-group]').length }));
  check(r.licensesFooter.lines === 1 && r.licensesFooter.legend === 0, '라이선스 탭: 한 줄 바닥글·범례 없음', r.licensesFooter);
  await user.goto(`${BASE}/guide`, { waitUntil: 'networkidle0' });
  await guideReady(user);
  const footerState = () => user.evaluate(() => {
    const line = document.querySelector('.guide-footer__line');
    const tops = new Set([...line.querySelectorAll(':scope > *')].filter((c) => c.getClientRects().length).map((c) => Math.round(c.getBoundingClientRect().top)));
    return { lines: document.querySelectorAll('.guide-footer__line').length, rows: tops.size, text: line.textContent.replace(/\s+/g, ' ').trim(),
      legendLink: !document.querySelector('[data-guide-legend-link-wrap]').hidden, inBody: document.querySelectorAll('[data-guide-body] [data-legend-group]').length,
      legendRows: document.querySelectorAll('[data-guide-body] [data-guide-legend] tbody tr').length, sourceRows: document.querySelectorAll('[data-guide-legend-source] tbody tr').length,
      groups: [...document.querySelectorAll('[data-guide-body] [data-legend-group]')].map((g) => g.dataset.legendGroup),
      licensesHref: document.querySelector('[data-guide-licenses-link]')?.getAttribute('href'), legendHref: document.querySelector('[data-guide-legend-link]')?.getAttribute('href') };
  });
  r.footerFirst = await footerState();
  check(r.footerFirst.lines === 1 && r.footerFirst.rows === 1 && r.footerFirst.legendLink && r.footerFirst.inBody === 0 && /ruff/.test(r.footerFirst.text) && /ESLint/.test(r.footerFirst.text)
    && r.footerFirst.licensesHref?.endsWith('/guide/licenses') && r.footerFirst.legendHref?.endsWith('/guide/user#shortcuts-and-icons'), '첫 절: 한 줄 바닥글·아이콘 뜻 링크·범례 없음', r.footerFirst);
  await user.click('[data-guide-legend-link]');
  await sleep(400);
  r.footer17 = await footerState();
  r.footer17.key = await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key);
  check(r.footer17.key === 'shortcuts-and-icons' && r.footer17.legendRows === r.footer17.sourceRows && r.footer17.legendRows > 50 && !r.footer17.groups.includes('admin') && !r.footer17.legendLink && r.footer17.lines === 1,
    '"화면 읽는 법" 절: 본문 범례(행 수 = 레지스트리, 관리자 그룹 없음)·바닥 링크 숨김', r.footer17);
  // X12: 범례 — ① 행 수 = 레지스트리 원본 ② 행마다 아이콘 하나·큰 크기 ③ 카테고리마다 구역·표 하나, 구역끼리 세로로 겹치지 않음 ④ 네 열 모든 셀 한 줄
  // ⑤ 1280px 표 가로 스크롤 0(1024는 표 영역만 허용·넘친 양 기록) ⑥ 카테고리 제목이 미니 목차·표 이름에
  const legendState = () => user.evaluate(() => {
    const groups = [...document.querySelectorAll('[data-guide-body] [data-legend-group]')];
    const rows = [...document.querySelectorAll('[data-guide-body] [data-legend]')];
    const boxes = groups.map((g) => g.getBoundingClientRect());
    const cells = [...document.querySelectorAll('[data-guide-body] .icon-legend td, [data-guide-body] .icon-legend th')];
    const multi = cells.filter((c) => { const lh = parseFloat(getComputedStyle(c).lineHeight) || 20; const pad = parseFloat(getComputedStyle(c).paddingTop) + parseFloat(getComputedStyle(c).paddingBottom); return c.getClientRects().length && (c.clientHeight - pad) > Math.max(lh * 1.5, 30); });
    const scrolls = [...document.querySelectorAll('[data-guide-body] .icon-legend__scroll')].map((s) => s.scrollWidth - s.clientWidth);
    const toc = [...document.querySelectorAll('.guide-section__contents a')].map((a) => a.textContent.trim());
    return {
      rows: rows.length, source: document.querySelectorAll('[data-guide-legend-source] [data-legend]').length,
      icons: [...new Set(rows.map((r) => { const s = [...r.querySelectorAll('.icon-legend__icons svg')]; return `${s.length}x${s.map((x) => x.getAttribute('width')).join(',')}`; }))],
      groups: groups.length, tables: groups.map((g) => g.querySelectorAll('table').length).every((n) => n === 1),
      overlap: boxes.some((b, i) => i > 0 && b.top < boxes[i - 1].bottom - 0.5), sameRow: boxes.some((b, i) => i > 0 && Math.abs(b.top - boxes[i - 1].top) < 1),
      multi: multi.slice(0, 3).map((c) => c.textContent.trim()), maxOverflow: Math.max(0, ...scrolls),
      captions: groups.every((g) => g.querySelector('caption')?.textContent.trim() === g.querySelector('h3')?.textContent.trim()),
      // X13: 범례 카테고리 제목은 미니 목차에 없고(본문에는 그대로) ## 소제목은 있음
      notInToc: groups.every((g) => !toc.includes(g.querySelector('h3')?.textContent.trim())) && toc.length >= 3, bullet: getComputedStyle(groups[0].querySelector('h3'), '::before').backgroundColor,
      // X13(P29C에서 확인): ① 표 폭 같음 ② 열 경계 같음 ③ 칸 안 여백(칸 폭 - 글자 폭) ④ 바탕 = 화면 바탕 ⑤ 모든 선 1px solid --guide-legend-border
      ...(() => {
        const tables = groups.map((g) => g.querySelector('table'));
        const widths = tables.map((t) => t.getBoundingClientRect().width);
        const edges = tables.map((t) => { const l = t.getBoundingClientRect().left; return [...t.rows[0].cells].map((c) => Math.round(c.getBoundingClientRect().left - l)); });
        const room = [Infinity, Infinity, Infinity, Infinity];
        for (const t of tables) for (const row of t.rows) [...row.cells].forEach((c, i) => {
          const rg = document.createRange(); rg.selectNodeContents(c);
          const pad = parseFloat(getComputedStyle(c).paddingLeft) + parseFloat(getComputedStyle(c).paddingRight);
          room[i] = Math.min(room[i], Math.round(c.getBoundingClientRect().width - rg.getBoundingClientRect().width - pad));
        });
        const probe = document.createElement('div'); probe.style.color = 'var(--guide-legend-border)'; document.body.append(probe);
        const lineColor = getComputedStyle(probe).color; probe.remove();
        const bodyBg = getComputedStyle(document.body).backgroundColor;
        const parts = tables.flatMap((t) => [t, ...t.querySelectorAll('tr, th, td')]);
        const badBg = parts.filter((n) => getComputedStyle(n).backgroundColor !== bodyBg).length;
        const badLine = tables.flatMap((t) => [t, ...t.querySelectorAll('th, td')]).filter((n) => {
          const cs = getComputedStyle(n);
          return ['Top', 'Right', 'Bottom', 'Left'].some((side) => cs[`border${side}Width`] !== '1px' || cs[`border${side}Style`] !== 'solid' || cs[`border${side}Color`] !== lineColor);
        }).length;
        return { widthSpread: Math.round((Math.max(...widths) - Math.min(...widths)) * 10) / 10, sameEdges: edges.every((e) => JSON.stringify(e) === JSON.stringify(edges[0])),
          room, badBg, badLine, lineColor, bodyBg };
      })(),
    };
  });
  r.legend1280 = await legendState();
  check(r.legend1280.widthSpread <= 1 && r.legend1280.sameEdges && r.legend1280.room[0] >= 8 && Math.min(...r.legend1280.room.slice(1)) >= 12 && r.legend1280.badBg === 0 && r.legend1280.badLine === 0,
    '범례 표(X13): 폭 같음·열 경계 같음·칸 여백·바탕 = 화면 바탕·모든 선 1px solid', r.legend1280);
  check(r.legend1280.rows === r.legend1280.source && r.legend1280.rows > 50 && JSON.stringify(r.legend1280.icons) === '["1x24"]' && r.legend1280.groups > 3 && r.legend1280.tables
    && !r.legend1280.overlap && !r.legend1280.sameRow && r.legend1280.multi.length === 0 && r.legend1280.maxOverflow === 0 && r.legend1280.captions && r.legend1280.notInToc,
    '범례(X12): 행 수·아이콘 하나 큰 크기·카테고리 구역 세로·셀 한 줄·1280 표 가로 스크롤 0·caption·미니 목차 제외(X13)', r.legend1280);
  await shot(user, `${PREFIX}_legend_section.png`, { fullPage: true });  await user.click('[data-nav="prev"]:not([disabled])');
  await sleep(300);
  check(await user.evaluate(() => document.querySelectorAll('[data-guide-body] [data-legend-group]').length === 0 && !document.querySelector('[data-guide-legend-link-wrap]').hidden), '다른 절로 가면 범례 없음·링크 다시 보임', null);

  // 9. 관리자
  const admin = await session('demo-admin');
  check(await prefs(admin, 'ko', 'light') === 200, '관리자 언어', null);
  await admin.goto(`${BASE}/guide/admin`, { waitUntil: 'networkidle0' });
  await guideReady(admin);
  r.admin = await admin.evaluate(() => ({ tabs: [...document.querySelectorAll('[data-guide-tab]')].map((a) => a.dataset.guideTab), current: document.querySelector('[data-guide-tab][aria-current]')?.dataset.guideTab,
    sections: [...document.querySelectorAll('[data-guide-body] article')].map((a) => a.dataset.key) }));
  check(r.admin.tabs.includes('admin') && r.admin.current === 'admin' && r.admin.sections.includes('overview'), '관리자 가이드', r.admin);
  const adminSearch = JSON.parse((await status(admin, '/api/v1/guide/admin/search?q=zip')).text).data.results.map((x) => x.key);
  check(!adminSearch.some((k) => ['upload', 'upload-errors'].includes(k)), '관리자 검색에 사용자 절 섞이지 않음', adminSearch);
  await shot(admin, `${PREFIX}_admin_guide.png`);
  check(await admin.evaluate(() => document.querySelectorAll('[data-legend-group]').length === 0 && document.querySelectorAll('.guide-footer__line').length === 1), '관리자 가이드: 범례 없음·한 줄 바닥글', null);

  // X14: 관리자 절 24개(배포 묶음 분리, P30부터 25개 — audit-log) — ko·en 목차 순서, 모든 절이 해시로 열림, 이전/다음이 새 순서를 따름, 미니 목차, 가로 스크롤 0
  const ADMIN_KEYS = ['overview', 'install', 'deploy-bundle', 'worker', 'tools-bin', 'accounts', 'groups-access', 'account-csv', 'external-auth', 'auth-ldap', 'auth-oidc', 'auth-saml',
    'settings', 'retention-disk', 'backup-restore', 'repository', 'schedule', 'projects-admin', 'rules-admin', 'notifications-admin', 'monitoring', 'admin-mail', 'audit-log', 'security', 'troubleshooting', 'linux-ops']; // P30: monitoring 뒤 audit-log, P30B: 그 사이 admin-mail
  r.adminSections = {};
  for (const lang of ['ko', 'en']) {
    check(await prefs(admin, lang, 'light') === 200, `관리자 ${lang}`, null);
    await admin.goto(`${BASE}/guide/admin`, { waitUntil: 'networkidle0' });
    await guideReady(admin);
    const toc = await admin.evaluate(() => [...document.querySelectorAll('[data-guide-toc] a[data-key]')].map((a) => a.dataset.key));
    check(JSON.stringify(toc) === JSON.stringify(ADMIN_KEYS), `관리자 목차 순서 ${lang}`, toc);
    const bad = [];
    for (const [i, key] of ADMIN_KEYS.entries()) {
      await admin.evaluate((k) => { location.hash = k; }, key);
      await admin.waitForFunction((k) => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key === k, { timeout: 10000 }, key).catch(() => null);
      const s = await admin.evaluate(() => ({ key: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key, title: document.querySelector('[data-guide-body] article.guide-section h2')?.textContent.trim() ?? '',
        prev: !!document.querySelector('[data-guide-body] [data-nav="prev"]')?.disabled, next: !!document.querySelector('[data-guide-body] [data-nav="next"]')?.disabled, mini: document.querySelectorAll('.guide-section__contents a').length,
        raw: /\{\{|guide-img:|\]\(guide:/.test(document.querySelector('[data-guide-body]').textContent), scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth }));
      if (s.key !== key || !s.title || s.prev !== (i === 0) || s.next !== (i === ADMIN_KEYS.length - 1) || s.raw || !s.scroll) bad.push({ key, ...s });
      if (key === 'deploy-bundle') r.adminSections[`${lang}_deploy`] = s;
    }
    check(bad.length === 0, `관리자 절 모두 열림·이전/다음 ${lang}`, bad);
    check((r.adminSections[`${lang}_deploy`]?.mini ?? 0) >= 3, `배포 묶음 미니 목차 ${lang}`, r.adminSections[`${lang}_deploy`]);
    // install의 "다음" = 배포 묶음, 배포 묶음의 "다음" = worker
    await admin.evaluate(() => { location.hash = 'install'; });
    await admin.waitForFunction(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key === 'install', { timeout: 10000 });
    await admin.click('[data-guide-body] [data-nav="next"]');
    await admin.waitForFunction(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key === 'deploy-bundle', { timeout: 10000 }).catch(() => null);
    await admin.click('[data-guide-body] [data-nav="next"]');
    await admin.waitForFunction(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key === 'worker', { timeout: 10000 }).catch(() => null);
    r.adminSections[`${lang}_nextChain`] = await admin.evaluate(() => location.hash);
    check(r.adminSections[`${lang}_nextChain`] === '#worker', `다음 단추 install → deploy-bundle → worker ${lang}`, r.adminSections[`${lang}_nextChain`]);
  }
  check(await prefs(admin, 'ko', 'light') === 200, '관리자 언어 되돌림', null);

  // X10·X12: 관리자 메뉴 "시스템 관리" → 첫 탭 "분석 도구" → 탭 줄의 "설정 관리"(규칙 설정 바로 뒤), 안쪽 카테고리 탭, 저장 전 변경이 있을 때 바깥 탭 이동 확인, 일반 사용자 403
  r.system = {};
  for (const lang of ['ko', 'en']) {
    check(await prefs(admin, lang, 'light') === 200, `관리자 ${lang}`, null);
    for (const width of [1280, 1024]) {
      await admin.setViewport({ width, height: 900 });
      await admin.goto(`${BASE}/guide/admin`, { waitUntil: 'networkidle0' });
      await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('[data-nav="admin.tools"]')]);
      const menuPath = await admin.evaluate(() => location.pathname);
      await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('[data-system-subnav] a[href$="/admin/config-settings"]')]);
      await admin.waitForSelector('[role="tab"]', { timeout: 15000 });
      const st = await admin.evaluate(() => {
        const nav = document.querySelector('.topbar');
        const items = [...document.querySelectorAll('.topbar .nav-item')].filter((n) => n.getClientRects().length);
        return { path: location.pathname, menu: document.querySelector('[data-nav="admin.tools"]')?.textContent.trim(), current: document.querySelector('[data-nav="admin.tools"]')?.getAttribute('aria-current'),
          old: !!document.querySelector('[data-nav="admin.settings"]'), monitor: document.querySelector('[data-nav="admin.system"]')?.textContent.trim(),
          tabs: [...document.querySelectorAll('[data-system-subnav] a')].map((a) => a.textContent.trim()), currentTab: document.querySelector('[data-system-subnav] a[aria-current]')?.textContent.trim(),
          h1: document.querySelector('h1')?.textContent.trim(), title: document.title, headerH: Math.round(nav.getBoundingClientRect().height),
          headerRows: new Set(items.map((n) => Math.round(n.getBoundingClientRect().top))).size,
          noScroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth };
      });
      st.menuPath = menuPath;
      r.system[`${lang}_${width}`] = st;
      // X12(사용자 결정): 영어 이름 단축, 탭 순서(설정 관리는 규칙 설정 뒤 6번째), 알림 보관·저장소 허용 호스트 탭 없음
      const want = lang === 'ko' ? ['시스템 관리', '시스템 모니터링', '설정 관리', '규칙 설정'] : ['System admin', 'Monitoring', 'Settings', 'Rule settings'];
      check(menuPath === '/admin/tools' && st.path === '/admin/config-settings' && st.menu === want[0] && st.current === 'page' && !st.old && st.monitor === want[1] && st.tabs.length === 8 && st.tabs[4] === want[3] && st.tabs[5] === want[2] && st.currentTab === want[2]
        && st.h1 === want[0], `시스템 관리 > 설정 관리 ${lang}/${width}`, st);
      await shot(admin, `${PREFIX}_system_settings_${lang}_${width}.png`);
    }
  }
  await admin.setViewport({ width: 1280, height: 900 });
  check(await prefs(admin, 'ko', 'light') === 200, '관리자 ko', null);
  await admin.goto(`${BASE}/admin/config-settings#backup`, { waitUntil: 'networkidle0' });
  await admin.reload({ waitUntil: 'networkidle0' }); // 같은 주소·해시만 다르면 다시 읽지 않는다(언어 바꾼 뒤)
  await admin.waitForSelector('[role="tab"][aria-selected="true"]', { timeout: 15000 });
  r.inner = await admin.evaluate(() => {
    const sel = document.querySelector('[role="tab"][aria-selected="true"]');
    const cs = getComputedStyle(sel);
    const outer = getComputedStyle(document.querySelector('[data-system-subnav] a[aria-current]'));
    return { selected: sel.textContent.trim(), hash: location.hash, innerFont: cs.fontSize, outerFont: outer.fontSize, innerBg: cs.backgroundColor, innerShadow: cs.boxShadow, outerBorder: outer.borderBottomColor };
  });
  check(r.inner.hash === '#backup' && parseFloat(r.inner.innerFont) < parseFloat(r.inner.outerFont), '안쪽 탭(해시 직접 진입·한 단계 작은 글자)', r.inner);
  await shot(admin, `${PREFIX}_system_inner_tabs.png`);
  // 안쪽 탭 키보드(Home) 뒤 값 하나 바꾸고 바깥 탭(분석 도구) → 확인 대화상자, 취소하면 그대로, 확인하면 이동
  await admin.focus('[role="tab"][aria-selected="true"]');
  await admin.keyboard.press('Home');
  await sleep(300);
  r.innerKey = await admin.evaluate(() => ({ hash: location.hash, focus: document.activeElement?.getAttribute('role'), first: document.activeElement === document.querySelector('[role="tab"]') }));
  check(r.innerKey.first && r.innerKey.focus === 'tab', '안쪽 탭 Home', r.innerKey);
  const input = await admin.$('[role="tabpanel"]:not([hidden]) input[type="number"]:not([disabled]), [role="tabpanel"]:not([hidden]) input[type="text"]:not([disabled])');
  await input.click({ clickCount: 3 });
  await input.type('7');
  await sleep(400);
  await admin.click('[data-system-subnav] a[href$="/admin/tools"]');
  await admin.waitForSelector('dialog[open]', { timeout: 5000 });
  r.leave = await admin.evaluate(() => ({ title: document.querySelector('dialog[open] .dialog__title')?.textContent, msg: document.querySelector('dialog[open]')?.textContent.replace(/\s+/g, ' ').trim() }));
  await shot(admin, `${PREFIX}_system_leave_dialog.png`);
  await admin.keyboard.press('Escape');
  await sleep(300);
  r.leaveCancel = await admin.evaluate(() => ({ path: location.pathname, changed: document.querySelectorAll('.config-item--changed').length }));
  check(Boolean(r.leave.title) && r.leaveCancel.path === '/admin/config-settings' && r.leaveCancel.changed === 1, '저장 전 변경 → 바깥 탭 확인, 취소 = 그대로', { leave: r.leave, cancel: r.leaveCancel });
  await admin.click('[data-system-subnav] a[href$="/admin/tools"]');
  await admin.waitForSelector('dialog[open]', { timeout: 5000 });
  await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('dialog[open] .dialog__actions button[value="ok"]')]);
  r.leaveOk = await admin.evaluate(() => ({ path: location.pathname, tab: document.querySelector('[data-system-subnav] a[aria-current]')?.getAttribute('href') }));
  check(r.leaveOk.path === '/admin/tools' && Boolean(r.leaveOk.tab?.endsWith('/admin/tools')), '확인 = 분석 도구 탭으로 이동', r.leaveOk);
  for (const p of ['/admin/config-settings', '/admin/tools', '/admin/backups']) {
    const res = await status(user, p);
    check(res.code === 403 && !res.text.includes('data-system-subnav'), `일반 사용자 403 ${p}`, res.code);
  }
  check(!(await user.evaluate(() => !!document.querySelector('[data-nav="admin.tools"]'))), '일반 사용자 메뉴에 시스템 관리 없음', null);

  // P29C: 관리자 화면 도움말 링크 → 관리자 가이드 절(admin에게만), 설정 관리는 지금 탭에 맞는 절로 바뀜, 눌러서 그 절이 열림
  r.adminHelp = {};
  const helpOf = (page) => page.evaluate(() => { const a = document.querySelector('.page-help a.help-link'); return a ? { href: a.getAttribute('href'), key: a.dataset.guide, aud: a.dataset.guideAudience } : null; });
  for (const [p, key] of [['/admin/users', 'accounts'], ['/admin/users/import', 'account-csv'], ['/groups', 'groups-access'], ['/projects/new', 'projects-admin'], ['/admin/tools', 'tools-bin'], ['/admin/worker', 'worker'],
    ['/admin/checks', 'monitoring'], ['/admin/monitoring', 'monitoring'], ['/admin/audit-logs', 'audit-log'], ['/admin/runs', 'projects-admin'], ['/admin/rule-settings', 'rules-admin'], ['/admin/schedules', 'schedule'], ['/admin/backups', 'backup-restore']]) {
    await admin.goto(BASE + p, { waitUntil: 'networkidle0' });
    const h = await helpOf(admin);
    r.adminHelp[p] = h?.key ?? null;
    check(h?.aud === 'admin' && h.key === key && h.href.endsWith(`/guide/admin#${key}`), `도움말 링크 ${p} → admin#${key}`, h);
  }
  const tabsHelp = { retention: 'retention-disk', disk: 'retention-disk', notification: 'notifications-admin', monitoring: 'monitoring', schedule: 'schedule', repository: 'repository', analysis: 'settings', backup: 'backup-restore', auth: 'external-auth', auth_ldap: 'auth-ldap', auth_oidc: 'auth-oidc', auth_saml: 'auth-saml' };
  await admin.goto(`${BASE}/admin/config-settings`, { waitUntil: 'networkidle0' });
  await admin.waitForSelector('[role="tab"]', { timeout: 15000 });
  r.configHelp = {};
  for (const [tab, key] of Object.entries(tabsHelp)) {
    await admin.click(`[data-tab="${tab}"]`);
    await sleep(150);
    r.configHelp[tab] = (await helpOf(admin))?.key ?? null;
    check(r.configHelp[tab] === key, `설정 관리 ${tab} 탭 도움말 → ${key}`, r.configHelp[tab]);
  }
  await Promise.all([admin.waitForNavigation({ waitUntil: 'networkidle0' }), admin.click('.page-help a.help-link')]);
  await guideReady(admin);
  await sleep(300);
  r.configHelpOpen = await admin.evaluate(() => ({ path: location.pathname, key: document.querySelector('[data-guide-body] article.guide-section')?.dataset.key }));
  check(r.configHelpOpen.path === '/guide/admin' && r.configHelpOpen.key === 'auth-saml', '설정 관리 도움말 → 관리자 가이드 그 절', r.configHelpOpen);
  // 일반 사용자: 같은 그룹 목록 화면에 관리자 가이드 링크 없음
  await user.goto(`${BASE}/groups`, { waitUntil: 'networkidle0' }).catch(() => null);
  r.userGroupsHelp = await helpOf(user);
  check(!r.userGroupsHelp || r.userGroupsHelp.aud !== 'admin', '일반 사용자 화면에 관리자 가이드 링크 없음', r.userGroupsHelp);
  await user.goto(`${BASE}/guide`, { waitUntil: 'networkidle0' }); // 다음 확인(화면 설정 API)이 CSRF 토큰 있는 화면에서

  // 10. 한/영 × 라이트/다크 × 1280/1024
  r.matrix = [];
  if (!opt('no-shots')) {
    for (const lang of ['ko', 'en']) {
      for (const theme of ['light', 'dark']) {
        check(await prefs(user, lang, theme) === 200, `설정 ${lang}/${theme}`, null);
        for (const width of [1280, 1024]) {
          await user.setViewport({ width, height: 900 });
          await user.goto(`${BASE}/guide/user#upload`, { waitUntil: 'networkidle0' });
          await user.reload({ waitUntil: 'networkidle0' }); // 같은 주소·해시만 다르면 문서를 다시 읽지 않는다
          await guideReady(user);
          await sleep(300);
          const m = await user.evaluate(() => ({ lang: document.documentElement.lang, theme: document.documentElement.dataset.theme ?? null,
            hangulToc: /[가-힣]/.test(document.querySelector('[data-guide-toc]').textContent), fallback: document.querySelectorAll('[data-fallback]').length,
            bg: getComputedStyle(document.body).backgroundColor,
            // X10: 불릿 색 = 그 글자색(소제목 ●·하위 ▪·목록 점·번호)
            bullets: (() => {
              const out = [];
              for (const h of document.querySelectorAll('.guide-md h3.md-heading, .guide-md h4.md-heading')) out.push([h.tagName, getComputedStyle(h, '::before').backgroundColor, getComputedStyle(h).color]);
              for (const li of document.querySelectorAll('.guide-md li')) out.push(['LI', getComputedStyle(li, '::marker').color, getComputedStyle(li).color]);
              return { n: out.length, bad: out.filter(([, a, b]) => a !== b).slice(0, 3), h3: getComputedStyle(document.querySelector('.guide-md h3.md-heading')).color,
                title: getComputedStyle(document.querySelector('.guide-section__title')).color, titleSize: getComputedStyle(document.querySelector('.guide-section__title')).fontSize,
                h3Size: getComputedStyle(document.querySelector('.guide-md h3.md-heading')).fontSize };
            })() }));
          check(m.bullets.n > 5 && m.bullets.bad.length === 0 && m.bullets.h3 === m.bullets.title && parseFloat(m.bullets.titleSize) > parseFloat(m.bullets.h3Size), `불릿 색 = 글자색·제목색 같음 ${lang}/${theme}`, m.bullets);
          m.noScroll = await noHScroll(user);
          if (!m.noScroll) m.overflow = await overflowing(user);
          r.matrix.push(`${lang}/${theme}/${width}: ${m.noScroll ? '가로 0' : '가로 스크롤!'} ${m.bg} 불릿 ${m.bullets.n}개 색 ${m.bullets.h3}`);
          check(m.noScroll, `가로 스크롤 ${lang}/${theme}/${width}`, m);
          check(m.lang === lang, `html lang ${lang}`, m.lang);
          if (lang === 'en') check(!m.hangulToc && m.fallback === 0, '영어 목차에 한글·번역 대기 없음', m);
          await shot(user, `${PREFIX}_guide_${lang}_${theme}_${width}.png`);
          // X10: 범례가 있는 절도 가로 스크롤 0
          await user.evaluate(() => { location.hash = 'shortcuts-and-icons'; });
          await sleep(300);
          check(await noHScroll(user), `범례 절 가로 스크롤 ${lang}/${theme}/${width}`, await overflowing(user));
          const lg = await legendState();
          (r.legendMatrix ??= []).push(`${lang}/${theme}/${width}: 셀 줄바꿈 ${lg.multi.length}, 표 넘침 최대 ${lg.maxOverflow}px, 폭 차이 ${lg.widthSpread}px, 여백 ${lg.room.join('/')}, 바탕 다름 ${lg.badBg}, 선 다름 ${lg.badLine}, 선 ${lg.lineColor}`);
          check(lg.widthSpread <= 1 && lg.sameEdges && lg.badBg === 0 && lg.badLine === 0, `범례 표 ${lang}/${theme}/${width}: 폭·열 경계·바탕·선`, lg);
          check(lg.multi.length === 0 && (width !== 1280 || lg.maxOverflow === 0) && JSON.stringify(lg.icons) === '["1x24"]', `범례 ${lang}/${theme}/${width}: 셀 한 줄·1280 표 스크롤 0`, lg);
          if (width === 1024) await shot(user, `${PREFIX}_legend_${lang}_${theme}_1024.png`);
        }
      }
    }
    await user.setViewport({ width: 1280, height: 900 });
  }
  await prefs(user, 'ko', 'light');
} catch (e) {
  problems.push(`예외: ${e.message}`);
} finally {
  await browser?.close();
}
console.log(JSON.stringify({ r, csp, problems }, null, 1));
console.log(problems.length || csp.length ? `실패 ${problems.length + csp.length}건` : '가이드 화면 점검 통과');
process.exit(problems.length || csp.length ? 1 : 0);
