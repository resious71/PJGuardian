// FAQ 화면 브라우저 점검(P29B, 헤드리스 Chrome). 가짜 데이터 설치본(tools/guide_demo — 임시 폴더·새 DB(마이그레이션 029 포함)·가짜 계정)을 별도 포트에 스스로 띄우고
// 끝나면 서버를 멈추고 폴더를 지운다(실제 DB·설정·워커와 무관).
//   node tools/check_faq_ui.mjs [--port=8093] [--keep] [--no-perf]
// 흐름: 일반 사용자 — FAQ 탭·빈 안내·편집 요소가 DOM에 없음. admin — 첫 FAQ 추가(대화상자: 분류 제안·글자 수·필수 검증·미리보기(같은 렌더러, <script> 글자 그대로)·
//   취소 시 바뀐 내용 확인·Esc·포커스 복귀), 같은 분류 둘째·다른 분류 비공개 항목, 수정, 동시 수정 409(최신 내용 표시 → 내 내용으로 다시 저장), 위·아래 이동(키보드),
//   공개 전환(일반 사용자에게 보였다 숨겨짐), 삭제(확인 대화상자 — 취소가 기본 포커스·Esc·포커스 복귀). 일반 사용자 — 비공개 안 보임, 검색(입력만으로는 요청 없음, Enter로 적용,
//   결과 펼침·0건 안내), 항목 주소 #faq-<id>로 열면 펼쳐짐, 링크 복사, 접기/펼치기 키보드(Enter·Space), 모두 펼치기/접기. 1280·1024 가로 스크롤 0, 콘솔 오류·CSP 위반 0.
// X16: 시드(resources/faq/seed.ko.json)를 tools/faq_import.php로 두 번 가져오기(두 번째 0건) → 분류별 묶음·처음엔 접힘·가이드 링크 클릭 → 사용자 가이드 그 절, 캡처 X16_faq_seeded_1280.png
// X15: 검색 입력·분류 선택·검색 단추 한 줄(1280·1024 세로 중심 차 2px 이내·같은 높이·같은 부모·왼쪽→오른쪽 순서, 640px 가로 스크롤 0, 긴 분류 이름, 일반 사용자·admin × ko·en), 캡처 X15_faq_search_*.png
// 성능(--no-perf가 아니면): 500개 항목(큰 답변 포함)을 설치본 DB에 넣고 목록·검색 응답 시간(20회 p95, 3번의 중앙값)·화면 첫 표시 시간. 캡처 P29B_*.png
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const opt = (k) => process.argv.find((a) => a === `--${k}` || a.startsWith(`--${k}=`))?.split('=')[1] ?? (process.argv.includes(`--${k}`) ? true : null);
const PORT = Number(opt('port') ?? 8093);
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const PREFIX = process.env.PJG_SHOT_PREFIX ?? 'P29B';
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);

const work = path.join(ROOT, 'data/work', `faq_check_${randomBytes(4).toString('hex')}`);
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
server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
  { env: { ...process.env, PJG_ISO_INI: path.join(work, 'config.ini') }, stdio: 'ignore' });
for (let i = 0; i < 50; i += 1) {
  try { if ((await fetch(`${BASE}/login`)).ok) break; } catch { /* 아직 */ }
  await sleep(200);
}

async function session(login) {
  const ctx = await browser.createBrowserContext();
  await ctx.overridePermissions(BASE, ['clipboard-read', 'clipboard-write', 'clipboard-sanitized-write']);
  const page = await ctx.newPage();
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
    else if (['error', 'warning'].includes(m.type()) && !/status of 40[0-9]/.test(m.text())) problems.push(`console.${m.type()}(${login}): ${m.text()}`);
  });
  page.on('pageerror', (e) => problems.push(`pageerror(${login}): ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  await page.emulateTimezone('Asia/Seoul');
  await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', PW);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  // 화면 언어 ko·라이트(가짜 계정)
  await page.evaluate(async () => fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: 'ko', theme: 'light' }) }));
  return page;
}
const api = (page, method, url, body) => page.evaluate(async (m, u, b) => {
  const res = await fetch(u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: b === null ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { /* 본문 없음 */ }
  return { status: res.status, json };
}, method, url, body ?? null);
const openFaq = async (page, hash = '') => {
  await page.goto(`${BASE}/guide/faq${hash}`, { waitUntil: 'networkidle0' });
  if (hash) await page.reload({ waitUntil: 'networkidle0' });
  await page.waitForFunction(() => !document.querySelector('[data-faq-list] p.muted') || document.querySelector('[data-faq-list] [data-faq-empty]') || document.querySelector('.faq-item'), { timeout: 15000 });
  await sleep(200);
};
const noHScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);
const editCount = (page) => page.evaluate(() => document.querySelectorAll('[data-faq-add], [data-faq-add-first], [data-faq-edit], [data-faq-delete], [data-faq-move], [data-faq-publish], [data-faq-dialog]').length);
const text = (page, sel) => page.$eval(sel, (n) => n.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
const dialogOpen = (page) => page.evaluate(() => [...document.querySelectorAll('dialog[open]')].map((d) => d.querySelector('.dialog__title')?.textContent ?? ''));
async function fillDialog(page, { category, question, answer, published = true }) {
  const set = async (sel, v) => { await page.click(sel, { clickCount: 3 }); await page.$eval(sel, (n) => { n.value = ''; }); await page.type(sel, v); };
  if (category !== undefined) await set('#faq-edit-category', category);
  if (question !== undefined) await set('#faq-edit-question', question);
  if (answer !== undefined) {
    await page.$eval('#faq-edit-answer', (n, v) => { n.value = v; n.dispatchEvent(new Event('input', { bubbles: true })); }, answer);
  }
  const checked = await page.$eval('#faq-edit-published', (n) => n.checked);
  if (checked !== published) await page.click('#faq-edit-published');
}
async function saveDialog(page) {
  await page.click('[data-faq-save]');
  await page.waitForFunction(() => !document.querySelector('[data-faq-dialog]'), { timeout: 10000 });
  await sleep(300);
}

try {
  browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const user = await session('alex');
  const admin = await session('demo-admin');

  // 1. 비어 있을 때
  await openFaq(user);
  r.userEmpty = await user.evaluate(() => ({ tab: document.querySelector('[data-guide-tab][aria-current]')?.dataset.guideTab, empty: document.querySelector('[data-faq-empty]')?.dataset.faqEmpty,
    tabs: [...document.querySelectorAll('[data-guide-tab]')].map((a) => a.dataset.guideTab) }));
  r.userEditEls = await editCount(user);
  check(r.userEmpty.tab === 'faq' && r.userEmpty.empty === 'user' && r.userEditEls === 0 && JSON.stringify(r.userEmpty.tabs) === '["user","faq","errors"]', '일반 사용자: FAQ 탭·빈 안내·편집 요소 없음', { ...r.userEmpty, edit: r.userEditEls });
  await openFaq(admin);
  r.adminEmpty = await admin.evaluate(() => ({ empty: document.querySelector('[data-faq-empty]')?.dataset.faqEmpty, first: !!document.querySelector('[data-faq-add-first]'), add: !!document.querySelector('[data-faq-add]'),
    tabs: [...document.querySelectorAll('[data-guide-tab]')].map((a) => a.dataset.guideTab) }));
  check(r.adminEmpty.empty === 'admin' && r.adminEmpty.first && r.adminEmpty.add && JSON.stringify(r.adminEmpty.tabs) === '["user","admin","faq","errors"]', 'admin: 빈 안내·첫 FAQ 추가', r.adminEmpty);
  await admin.screenshot({ path: path.join(OUT, `${PREFIX}_admin_empty.png`) });

  // 2. 추가 대화상자: 필수 검증 → 입력 → 미리보기 → 저장
  await admin.click('[data-faq-add-first]');
  await admin.waitForSelector('[data-faq-dialog]');
  r.dialogFocus = await admin.evaluate(() => document.activeElement?.id);
  await admin.click('[data-faq-save]');
  await sleep(200);
  r.required = await admin.evaluate(() => [...document.querySelectorAll('[data-faq-dialog] .field__error:not([hidden])')].map((n) => n.textContent));
  check(r.dialogFocus === 'faq-edit-category' && r.required.length === 3, '추가 대화상자: 첫 칸 포커스·필수 3개', r);
  const answer1 = '업로드 한도는 **설정**을 따릅니다.\n\n- 폴더 묶기\n- ZIP 올리기\n\n```\nphp tools/check_env.php\n```\n\n<script>alert(1)</script> [가이드](/guide/user#upload) [외부](https://example.com/x) [나쁜](javascript:alert(1))';
  await fillDialog(admin, { category: '업로드', question: '업로드 크기 한도는 어디서 보나요?', answer: answer1 });
  r.counter = await text(admin, '#faq-edit-question-count');
  await admin.click('[data-faq-preview]');
  await sleep(150);
  r.preview = await admin.evaluate(() => { const p = document.querySelector('[data-faq-preview-box]');
    return { shown: !p.hidden, pre: p.querySelectorAll('pre').length, li: p.querySelectorAll('li').length, script: p.querySelectorAll('script').length, links: [...p.querySelectorAll('a')].map((a) => [a.getAttribute('href'), a.getAttribute('rel')]),
      scriptText: p.textContent.includes('<script>alert(1)</script>'), textareaHidden: document.querySelector('#faq-edit-answer').hidden }; });
  check(r.preview.shown && r.preview.pre === 1 && r.preview.li === 2 && r.preview.script === 0 && r.preview.scriptText && r.preview.textareaHidden
    && JSON.stringify(r.preview.links) === JSON.stringify([['/guide/user#upload', null], ['https://example.com/x', 'noopener noreferrer']]), '미리보기(같은 렌더러, 위험 링크·스크립트 없음)', r.preview);
  await admin.screenshot({ path: path.join(OUT, `${PREFIX}_dialog_preview.png`) });
  await admin.click('[data-faq-write]');
  await saveDialog(admin);
  r.afterAdd = await admin.evaluate(() => ({ items: document.querySelectorAll('.faq-item').length, focus: document.activeElement?.dataset?.faqAddFirst !== undefined || document.activeElement?.dataset?.faqAdd !== undefined }));
  check(r.afterAdd.items === 1, '추가 후 목록', r.afterAdd);
  // 같은 분류 둘째, 다른 분류 비공개(긴 코드 줄 — 블록 안에서만 스크롤)
  await admin.click('[data-faq-add]');
  await admin.waitForSelector('[data-faq-dialog]');
  r.suggest = await admin.evaluate(() => [...document.querySelectorAll('#faq-edit-cats option')].map((o) => o.value));
  await fillDialog(admin, { category: '업로드', question: '폴더를 올릴 수 있나요?', answer: '네. **폴더 선택**으로 고르면 브라우저가 묶어 올립니다.' });
  await saveDialog(admin);
  await admin.click('[data-faq-add]');
  await admin.waitForSelector('[data-faq-dialog]');
  await fillDialog(admin, { category: '계정', question: '비밀 초안 질문', answer: `초안입니다.\n\n\`\`\`\n${'x'.repeat(400)}\n\`\`\``, published: false });
  await saveDialog(admin);
  const items = (await api(admin, 'GET', '/api/v1/faq')).json.data.items;
  const [q1, q2, q3] = items;
  r.items = items.map((i) => `${i.category}/${i.question}/${i.is_published}`);
  check(JSON.stringify(r.suggest) === '["업로드"]' && items.length === 3 && q3.is_published === false, '분류 제안·세 항목(하나 비공개)', { suggest: r.suggest, items: r.items });

  // 3. 취소: 바뀐 내용이 있으면 확인(Esc로 확인 창만 닫힘) → 버리고 닫기 → 연 단추로 포커스
  await admin.focus('[data-faq-add]');
  await admin.keyboard.press('Enter');
  await admin.waitForSelector('[data-faq-dialog]');
  await admin.type('#faq-edit-question', '입력 중');
  await admin.keyboard.press('Escape');
  await sleep(200);
  r.discard1 = await dialogOpen(admin);
  await admin.keyboard.press('Escape');
  await sleep(200);
  r.discard2 = await dialogOpen(admin);
  await admin.keyboard.press('Escape');
  await sleep(200);
  await admin.click('dialog[open] .dialog__actions button[value="ok"]');
  await sleep(300);
  r.discard3 = { open: await dialogOpen(admin), focus: await admin.evaluate(() => document.activeElement?.hasAttribute('data-faq-add')) };
  check(r.discard1.length === 2 && r.discard2.length === 1 && r.discard3.open.length === 0 && r.discard3.focus, '바뀐 내용 확인·Esc·포커스 복귀', { d1: r.discard1, d2: r.discard2, d3: r.discard3 });

  // 4. 수정 + 동시 수정(다른 관리자 = API로 먼저 저장) → 409 → 최신 내용 → 내 내용으로 다시 저장
  await admin.click(`[data-faq-edit="${q2.id}"]`);
  await admin.waitForSelector('[data-faq-dialog]');
  await fillDialog(admin, { question: '폴더를 통째로 올릴 수 있나요?' });
  r.otherSave = (await api(admin, 'PATCH', `/api/v1/admin/faq/${q2.id}`, { version: q2.version, answer: '다른 관리자가 먼저 바꾼 답변.' })).status;
  await admin.click('[data-faq-save]');
  await admin.waitForSelector('[data-faq-conflict]:not([hidden])', { timeout: 10000 });
  r.conflict = await text(admin, '[data-faq-conflict]');
  r.conflictKept = await admin.$eval('#faq-edit-question', (n) => n.value);
  await admin.screenshot({ path: path.join(OUT, `${PREFIX}_conflict.png`) });
  await admin.click('[data-faq-keep-mine]');
  await admin.waitForFunction(() => !document.querySelector('[data-faq-dialog]'), { timeout: 10000 });
  await sleep(300);
  const after = (await api(admin, 'GET', `/api/v1/faq/${q2.id}`)).json.data;
  check(r.otherSave === 200 && /다른 관리자가 먼저 바꾼 답변/.test(r.conflict ?? '') && r.conflictKept === '폴더를 통째로 올릴 수 있나요?' && after.question === '폴더를 통째로 올릴 수 있나요?' && after.version === 3,
    '동시 수정 409 → 최신 내용 표시·내 입력 보존 → 내 내용으로 저장', { conflict: r.conflict, kept: r.conflictKept, after });

  // 5. 순서: 둘째를 위로(키보드)
  await openFaq(admin);
  r.moveUpFirstDisabled = await admin.$eval(`[data-faq-move="up"][data-faq-id="${q1.id}"]`, (b) => b.disabled);
  await admin.focus(`[data-faq-move="up"][data-faq-id="${q2.id}"]`);
  await admin.keyboard.press('Enter');
  await admin.waitForFunction((id) => document.querySelector('.faq-group .faq-item')?.dataset.faqId === String(id), { timeout: 10000 }, q2.id);
  r.moveAria = await admin.$eval(`[data-faq-move="down"][data-faq-id="${q2.id}"]`, (b) => b.getAttribute('aria-label'));
  r.order = await admin.evaluate(() => [...document.querySelectorAll('.faq-item')].map((n) => n.dataset.faqId));
  check(r.moveUpFirstDisabled && r.order[0] === String(q2.id) && /폴더를 통째로/.test(r.moveAria ?? ''), '위·아래 이동(첫 항목 위 단추 꺼짐, 이름에 질문)', { order: r.order, aria: r.moveAria });

  // 6. 비공개는 일반 사용자에게 없음 → 공개 전환 → 보임
  await openFaq(user);
  r.userBefore = await user.evaluate(() => ({ items: [...document.querySelectorAll('.faq-item')].map((n) => n.dataset.faqId), draftText: document.body.textContent.includes('비밀 초안 질문'), cats: [...document.querySelectorAll('[data-faq-category] option')].map((o) => o.value) }));
  r.userEditEls2 = await editCount(user);
  check(r.userBefore.items.length === 2 && !r.userBefore.draftText && !r.userBefore.cats.includes('계정') && r.userEditEls2 === 0, '일반 사용자: 비공개 항목·분류 없음, 편집 요소 없음', { ...r.userBefore, edit: r.userEditEls2 });
  await openFaq(admin);
  r.draftBadge = await admin.$eval(`[data-faq-id="${q3.id}"] [data-faq-draft]`, (n) => n.textContent.trim()).catch(() => null);
  await admin.click(`[data-faq-publish="${q3.id}"]`);
  await sleep(600);
  await openFaq(user);
  r.userAfter = await user.evaluate(() => document.body.textContent.includes('비밀 초안 질문'));
  check(r.draftBadge === '비공개' && r.userAfter, '비공개 표시(아이콘 + 글자) → 공개 전환 → 일반 사용자에게 보임', { badge: r.draftBadge, after: r.userAfter });
  await admin.screenshot({ path: path.join(OUT, `${PREFIX}_admin_list.png`), fullPage: true });

  // 7. 삭제: 확인 대화상자(취소 기본 포커스·Esc·포커스 복귀) → 삭제
  await openFaq(admin);
  await admin.focus(`[data-faq-delete="${q3.id}"]`);
  await admin.keyboard.press('Enter');
  await admin.waitForSelector('dialog[open]');
  r.delDialog = await admin.evaluate(() => ({ focus: document.activeElement?.value, msg: document.querySelector('dialog[open]').textContent.replace(/\s+/g, ' ') }));
  await admin.keyboard.press('Escape');
  await sleep(200);
  r.delBack = await admin.evaluate((id) => document.activeElement?.dataset?.faqDelete === String(id), q3.id);
  await admin.click(`[data-faq-delete="${q3.id}"]`);
  await admin.waitForSelector('dialog[open]');
  await admin.click('dialog[open] .dialog__actions button[value="ok"]');
  await sleep(600);
  r.afterDelete = (await api(admin, 'GET', '/api/v1/faq')).json.data.total;
  check(r.delDialog.focus === 'cancel' && /되돌릴 수 없습니다/.test(r.delDialog.msg) && r.delDialog.msg.includes('비밀 초안 질문') && r.delBack && r.afterDelete === 2, '삭제 확인(취소 포커스·질문 표시·Esc·복귀)', { ...r.delDialog, back: r.delBack, total: r.afterDelete });

  // 8. 일반 사용자: 검색(입력만으로는 요청 없음), 항목 주소, 링크 복사, 접기/펼치기 키보드
  await openFaq(user);
  let faqRequests = 0;
  const onReq = (req) => { if (req.url().includes('/api/v1/faq')) faqRequests += 1; };
  user.on('request', onReq);
  await user.type('#faq-q', '통째로');
  await sleep(600);
  r.typedRequests = faqRequests;
  await user.keyboard.press('Enter');
  await user.waitForFunction(() => document.querySelector('[data-faq-status]').textContent.trim() !== '', { timeout: 10000 });
  user.off('request', onReq);
  r.search = await user.evaluate(() => ({ items: document.querySelectorAll('.faq-item').length, open: document.querySelectorAll('.faq-item__q[aria-expanded="true"]').length, status: document.querySelector('[data-faq-status]').textContent }));
  check(r.typedRequests === 0 && r.search.items === 1 && r.search.open === 1, '검색: 입력만으로 요청 없음, Enter로 적용, 결과 펼침', { typed: r.typedRequests, ...r.search });
  await user.click('#faq-q', { clickCount: 3 });
  await user.type('#faq-q', '없는말%_');
  await user.keyboard.press('Enter');
  await sleep(600);
  r.none = await text(user, '[data-faq-empty="search"]');
  check(r.none === '찾은 FAQ가 없습니다.', '0건 안내', r.none);
  await openFaq(user, `#faq-${q1.id}`);
  r.hash = await user.evaluate((id) => ({ open: document.querySelector(`[data-faq-toggle="${id}"]`)?.getAttribute('aria-expanded'), others: document.querySelectorAll('.faq-item__q[aria-expanded="true"]').length,
    inView: (() => { const b = document.getElementById(`faq-${id}`).getBoundingClientRect(); return b.top >= -1 && b.top < innerHeight; })() }), q1.id);
  check(r.hash.open === 'true' && r.hash.others === 1 && r.hash.inView, '항목 주소로 열면 펼쳐져 보임', r.hash);
  await user.click(`[data-faq-copy="${q2.id}"]`);
  await sleep(400);
  r.copy = await user.evaluate(async () => ({ hash: location.hash, clip: await navigator.clipboard.readText().catch(() => null), toast: document.querySelector('.toast')?.textContent ?? null }));
  check(r.copy.hash === `#faq-${q2.id}` && (r.copy.clip ?? '').endsWith(`/guide/faq#faq-${q2.id}`), '링크 복사', r.copy);
  await user.focus(`[data-faq-toggle="${q2.id}"]`);
  await user.keyboard.press('Enter');
  r.kb1 = await user.$eval(`[data-faq-toggle="${q2.id}"]`, (b) => b.getAttribute('aria-expanded'));
  await user.keyboard.press('Space');
  r.kb2 = await user.$eval(`[data-faq-toggle="${q2.id}"]`, (b) => b.getAttribute('aria-expanded'));
  await user.click('[data-faq-expand-all]');
  r.all = await user.evaluate(() => [...document.querySelectorAll('.faq-item__q')].every((b) => b.getAttribute('aria-expanded') === 'true'));
  check(r.kb1 !== r.kb2 && r.all, '키보드 접기/펼치기·모두 펼치기', { kb1: r.kb1, kb2: r.kb2, all: r.all });
  // 9. 폭·캡처
  r.scroll = {};
  for (const [name, page] of [['user', user], ['admin', admin]]) {
    for (const width of [1280, 1024]) {
      await page.setViewport({ width, height: 900 });
      await openFaq(page);
      await page.click('[data-faq-expand-all]');
      await sleep(200);
      r.scroll[`${name}_${width}`] = await noHScroll(page);
      check(r.scroll[`${name}_${width}`], `가로 스크롤 ${name} ${width}`, null);
      await page.screenshot({ path: path.join(OUT, `${PREFIX}_${name}_${width}.png`), fullPage: true });
    }
  }

  // X15: 검색 입력·분류 선택·검색 단추 한 줄(1280·1024 — 세로 중심 차 2px 이내·같은 높이·같은 부모), 640px은 줄바꿈 허용·가로 스크롤 0.
  // 긴 분류 이름이 선택 상자를 넓혀 줄을 밀어내지 않는지(40자 분류 추가), 일반 사용자·admin, ko·en
  r.longCat = (await api(admin, 'POST', '/api/v1/admin/faq', { category: '아주 긴 분류 이름 가나다라마바사아자차카타파하 가나다라마바사아자', question: '긴 분류 질문', answer: '답변' })).status;
  check(r.longCat === 201, '긴 분류 항목 추가', r.longCat);
  const searchRow = (page) => page.evaluate(() => {
    const row = document.querySelector('[data-faq-search-row]');
    const els = ['#faq-q', '[data-faq-category]', '[data-faq-go]'].map((s) => document.querySelector(s));
    const boxes = els.map((n) => n.getBoundingClientRect());
    const centers = boxes.map((b) => b.top + b.height / 2);
    return { sameParent: els.every((n) => n.parentElement === row), heights: boxes.map((b) => Math.round(b.height)), centerDiff: Math.round((Math.max(...centers) - Math.min(...centers)) * 10) / 10,
      lefts: boxes.map((b) => Math.round(b.left)), widths: boxes.map((b) => Math.round(b.width)), rowWidth: Math.round(row.getBoundingClientRect().width),
      tab: [document.querySelector('#faq-q'), document.querySelector('[data-faq-category]'), document.querySelector('[data-faq-go]')].map((n) => n.tabIndex),
      labels: ['faq-q', 'faq-category'].map((id) => document.querySelector(`label[for="${id}"]`)?.textContent.trim() ?? null),
      scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth };
  });
  r.searchRow = {};
  for (const lang of ['ko', 'en']) {
    for (const [name, page] of [['user', user], ['admin', admin]]) {
      await page.evaluate(async (l) => fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: l, theme: 'light' }) }), lang);
      for (const width of [1280, 1024, 640]) {
        await page.setViewport({ width, height: 900 });
        await openFaq(page);
        const s = await searchRow(page);
        r.searchRow[`${lang}_${name}_${width}`] = s;
        const oneLine = s.centerDiff <= 2 && new Set(s.heights).size === 1 && s.lefts[0] < s.lefts[1] && s.lefts[1] < s.lefts[2];
        if (width >= 1024) check(s.sameParent && oneLine && s.labels.every(Boolean) && s.scroll, `검색 줄 한 줄 ${lang} ${name} ${width}`, s);
        else check(s.sameParent && s.scroll, `검색 줄 640px 가로 스크롤 0 ${lang} ${name}`, s);
        if (lang === 'ko' && ((name === 'user' && width === 1280) || (name === 'admin' && width === 1024) || (name === 'user' && width === 640))) {
          await page.screenshot({ path: path.join(OUT, `X15_faq_search${name === 'admin' ? '_admin' : ''}_${width}.png`), clip: { x: 0, y: 0, width, height: 520 } });
        }
      }
    }
  }
  for (const page of [user, admin]) {
    await page.evaluate(async () => fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: 'ko', theme: 'light' }) }));
    await page.setViewport({ width: 1280, height: 900 });
  }

  // X16: 시드(resources/faq/seed.ko.json)를 도구로 가져온 뒤(설치본 설정 — 실제 DB와 무관) 분류별 표시·펼치기·가이드 링크 이동·가로 스크롤 0
  {
    const ini = path.join(work, 'config.ini');
    const imp1 = execFileSync(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/faq_import.php'), `--config=${ini}`], { encoding: 'utf8' });
    const imp2 = execFileSync(PHP, ['-d', 'extension=intl', path.join(ROOT, 'tools/faq_import.php'), `--config=${ini}`], { encoding: 'utf8' });
    const seed = JSON.parse((await import('node:fs')).readFileSync(path.join(ROOT, 'resources/faq/seed.ko.json'), 'utf8')).items;
    const cats = [...new Set(seed.map((s) => s.category))];
    r.seedImport = { first: imp1.split('\n')[0], second: imp2.split('\n')[0] };
    check(imp1.includes(`추가 ${seed.length}건`) && imp2.includes('추가 0건'), '시드 가져오기(두 번째는 0건)', r.seedImport);
    await user.setViewport({ width: 1280, height: 900 });
    await openFaq(user);
    r.seeded = await user.evaluate((names) => ({ groups: [...document.querySelectorAll('[data-faq-category]')].filter((n) => n.tagName === 'SECTION').map((n) => n.dataset.faqCategory),
      items: document.querySelectorAll('.faq-item').length, scroll: document.documentElement.scrollWidth <= document.documentElement.clientWidth,
      allClosed: [...document.querySelectorAll('.faq-item__q')].every((b) => b.getAttribute('aria-expanded') === 'false'), names }), cats);
    check(cats.every((c) => r.seeded.groups.includes(c)) && r.seeded.items >= seed.length && r.seeded.allClosed && r.seeded.scroll, '시드: 분류별 묶음·처음엔 접힘·가로 스크롤 0', r.seeded);
    // 가이드 링크가 있는 첫 시드 질문을 펼치고 링크를 누르면 사용자 가이드 그 절로
    const withLink = seed.find((s) => /\]\(\/guide\/user#/.test(s.answer));
    const want = /\]\(\/guide\/user#([^)]+)\)/.exec(withLink.answer)[1];
    const toggled = await user.evaluate((q) => { const b = [...document.querySelectorAll('.faq-item__q')].find((n) => n.textContent.trim() === q); b?.click(); return !!b; }, withLink.question);
    await sleep(200);
    await user.screenshot({ path: path.join(OUT, 'X16_faq_seeded_1280.png'), fullPage: true });
    const href = await user.evaluate((q) => { const li = [...document.querySelectorAll('.faq-item')].find((n) => n.querySelector('.faq-item__q')?.textContent.trim() === q);
      const a = li?.querySelector('.faq-item__a a[href*="/guide/user#"]'); return a ? a.getAttribute('href') : null; }, withLink.question);
    await Promise.all([user.waitForNavigation({ waitUntil: 'networkidle0' }), user.click(`.faq-item__a a[href="${href}"]`)]);
    await user.waitForSelector('[data-guide-body] article.guide-section', { timeout: 15000 });
    r.seedLink = { toggled, href, url: await user.evaluate(() => location.pathname + location.hash), section: await user.evaluate(() => document.querySelector('[data-guide-body] article.guide-section')?.dataset.key) };
    check(toggled && href?.endsWith(`/guide/user#${want}`) && r.seedLink.url === `/guide/user#${want}` && r.seedLink.section === want.split('/')[0], '시드 답변의 가이드 링크 → 그 절', r.seedLink);
  }

  // 10. 성능: 500개(큰 답변 포함)를 설치본 DB에 넣고 측정
  if (!opt('no-perf')) {
    execFileSync(PHP, ['-r', `$d = new PDO('sqlite:' . $argv[1]); $d->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $d->exec('DELETE FROM faq_items');
$s = $d->prepare('INSERT INTO faq_items (category, question, answer, search_text, sort_order, is_published, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)');
$d->beginTransaction(); $t = '2026-10-01T00:00:00Z';
for ($i = 0; $i < 500; $i++) { $c = '분류 ' . ($i % 20); $q = str_pad('질문 ' . $i . ' 검색어', 200, '가'); $a = $i < 20 ? str_repeat("긴 답변 줄 $i 입니다. ", 400) : "보통 답변 $i — **굵게** 와 목록\\n\\n- 하나\\n- 둘";
  $a = mb_substr($a, 0, 10000); $s->execute([$c, mb_substr($q, 0, 200), $a, mb_strtolower($c . "\\n" . $q . "\\n" . $a), $i, $t, $t]); }
$d->commit(); echo $d->query('SELECT COUNT(*), SUM(length(answer)) FROM faq_items')->fetch(PDO::FETCH_NUM)[1];`, path.join(work, 'db/demo.sqlite')], { encoding: 'utf8' });
    const timing = async (url, n = 20) => user.evaluate(async (u, count) => {
      const ms = [];
      let bytes = 0;
      for (let i = 0; i < count; i += 1) { const t0 = performance.now(); const res = await fetch(u, { credentials: 'same-origin' }); const body = await res.text(); ms.push(performance.now() - t0); bytes = body.length; }
      ms.sort((a, b) => a - b);
      return { p50: Math.round(ms[Math.floor(count / 2)]), p95: Math.round(ms[Math.ceil(count * 0.95) - 1]), bytes };
    }, url, n);
    r.perf = { list: [], search: [], render: [] };
    for (let k = 0; k < 3; k += 1) {
      r.perf.list.push(await timing('/api/v1/faq'));
      r.perf.search.push(await timing(`/api/v1/faq?q=${encodeURIComponent('검색어 4')}`));
      await user.setViewport({ width: 1280, height: 900 });
      const t0 = Date.now();
      await user.goto(`${BASE}/guide/faq`, { waitUntil: 'domcontentloaded' });
      await user.waitForFunction(() => document.querySelectorAll('.faq-item').length === 500, { timeout: 30000 });
      r.perf.render.push(Date.now() - t0);
    }
    const med = (arr, key) => [...arr.map((x) => (key ? x[key] : x))].sort((a, b) => a - b)[1];
    r.perfSummary = { listP95: med(r.perf.list, 'p95'), listP50: med(r.perf.list, 'p50'), listBytes: r.perf.list[0].bytes, searchP95: med(r.perf.search, 'p95'), renderMs: med(r.perf.render) };
    check(r.perfSummary.listP95 <= 300, '목록 p95 0.3초 이내', r.perfSummary);
    r.perfScroll = await noHScroll(user);
    check(r.perfScroll, '500개 화면 가로 스크롤 0', null);
  }
} catch (e) {
  problems.push(`예외: ${e.message}`);
} finally {
  await browser?.close();
}
console.log(JSON.stringify({ r, csp, problems }, null, 1));
console.log(problems.length || csp.length ? `실패 ${problems.length + csp.length}건` : 'FAQ 화면 점검 통과');
process.exit(problems.length || csp.length ? 1 : 0);
