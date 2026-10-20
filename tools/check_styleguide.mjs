// 스타일 가이드 브라우저 점검(P09, 헤드리스 Chrome). 콘솔·CSP 오류, 관리자 메뉴 유무, 흑백 모드, 키보드 포커스·툴팁,
// 확인 대화상자(포커스 가둠·Esc), 토스트, 탭, 시각 변환을 확인하고 docs/results/P09_*.png를 다시 찍는다.
// 준비(한 번): cd data/work/puppeteer && npm init -y && npm i puppeteer-core@24   (data/work는 git 무시)
// 실행: node tools/check_styleguide.mjs [기본 주소(생략하면 config.ini의 app.base_url)] [Chrome 경로]
//   전제: Apache 실행, config.ini의 app.env=dev. localhost가 아닌 이름(pjguardian.local 등)은 hosts 없이 127.0.0.1로 연결한다.
//   캡처 저장 위치: 환경 변수 PJG_SHOT_DIR(기본 docs/results)
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';
const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const { base: BASE, basePath, chromeArgs } = target(ROOT, process.argv[2]);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({
  executablePath: process.argv[3] ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe',
  headless: true,
  args: chromeArgs,
});
const page = await browser.newPage();
await page.setViewport({ width: 1280, height: 900 });
const problems = [];
page.on('console', (m) => { if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
page.on('requestfailed', (r) => problems.push(`requestfailed: ${r.url()} ${r.failure()?.errorText}`));
page.on('response', (r) => { if (r.status() >= 400) problems.push(`HTTP ${r.status()}: ${r.url()}`); });
const result = {};

await page.goto(`${BASE}/dev/styleguide?as=admin`, { waitUntil: 'networkidle0' });
await page.evaluate(() => { try { localStorage.removeItem('pjg-mono'); } catch {} });
await page.reload({ waitUntil: 'networkidle0' });
result.adminNav = await page.$$eval('[data-nav^="admin."]', (n) => n.length);
result.bodyMinWidth = await page.evaluate(() => getComputedStyle(document.body).minWidth);
// 자산 주소가 배포 경로 접두사로 시작하는지(하위 경로 배포, X01)
result.assetUrls = await page.evaluate(() => [...document.querySelectorAll('link[rel=stylesheet], script[src]')].map((e) => new URL(e.href || e.src).pathname).slice(0, 3));
result.assetsUnderBase = await page.evaluate((bp) => [...document.querySelectorAll('link[href], script[src], use')]
  .map((e) => e.getAttribute('href') ?? e.getAttribute('src') ?? '')
  .filter((u) => u.includes('/assets/'))
  .every((u) => u.startsWith(`${bp}/assets/`)), basePath);
// 스프라이트의 아이콘이 실제로 그려지는지(use 요소 크기)
result.iconsDrawn = await page.$$eval('svg.icon use', (us) => us.filter((u) => u.getBBox().width > 0).length + '/' + us.length);
result.spinAnim = await page.$eval('svg.icon-spin', (s) => getComputedStyle(s).animationName);
await page.screenshot({ path: `${OUT}/P09_styleguide_color.png`, fullPage: true });
await page.screenshot({ path: `${OUT}/P09_layout_admin.png`, clip: { x: 0, y: 0, width: 1280, height: 140 } });
result.topbarHeight = await page.$eval('.topbar', (t) => t.getBoundingClientRect().height);

// 흑백 모드
await page.click('[data-mono-toggle]');
result.mono = await page.evaluate(() => ({ cls: document.body.classList.contains('mono'), filter: getComputedStyle(document.body).filter, pressed: document.querySelector('[data-mono-toggle]').getAttribute('aria-pressed') }));
await page.screenshot({ path: `${OUT}/P09_styleguide_mono.png`, fullPage: true });
await page.reload({ waitUntil: 'networkidle0' });
result.monoPersisted = await page.evaluate(() => document.body.classList.contains('mono'));
await page.click('[data-mono-toggle]');

// 키보드 Tab으로 첫 아이콘 전용 버튼까지 이동 → 접근성 이름과 툴팁 표시
await page.evaluate(() => document.activeElement?.blur());
await page.focus('body');
let found = null;
for (let i = 0; i < 80 && !found; i++) {
  await page.keyboard.press('Tab');
  const isIcon = await page.evaluate(() => document.activeElement?.classList.contains('btn--icon'));
  if (isIcon) await new Promise((r) => setTimeout(r, 300));
  found = await page.evaluate(() => {
    const a = document.activeElement;
    if (!a?.classList.contains('btn--icon')) return null;
    const tip = a.querySelector('.tooltip');
    const cs = getComputedStyle(tip);
    const r = a.getBoundingClientRect();
    return { aria: a.getAttribute('aria-label'), tipText: tip.textContent, tipVisible: cs.visibility === 'visible' && cs.opacity === '1', focusVisible: a.matches(':focus-visible'), hit: `${Math.round(r.width)}x${Math.round(r.height)}` };
  });
  if (found) found.tabs = i + 1;
}
result.iconButtonFocus = found;
if (found) {
  const snap = await page.accessibility.snapshot({ interestingOnly: true });
  const names = [];
  (function walk(n) { if (n.role === 'button' && n.focused) names.push(n.name); (n.children || []).forEach(walk); })(snap);
  result.axFocusedButtonName = names[0] ?? null;
  const el = await page.$('.btn--icon:focus');
  await el.screenshot({ path: `${OUT}/P09_icon_button_focus.png`, captureBeyondViewport: false }).catch(() => {});
  const box = await el.boundingBox();
  await page.screenshot({ path: `${OUT}/P09_icon_button_focus.png`, clip: { x: Math.max(0, box.x - 40), y: Math.max(0, box.y - 50), width: 220, height: 110 } });
}

// 확인 대화상자: 기본 포커스는 취소, Esc로 닫히면 원래 버튼으로 포커스 복귀
await page.click('[data-demo-confirm]');
await page.waitForSelector('dialog[open]');
result.dialog = await page.evaluate(() => ({ focused: document.activeElement?.textContent?.trim(), modal: document.querySelector('dialog[open]').matches(':modal') }));
const trap = [];
for (let i = 0; i < 4; i++) { await page.keyboard.press('Tab'); trap.push(await page.evaluate(() => (document.activeElement?.closest('dialog') ? 'in:' : 'OUT:') + document.activeElement?.textContent.trim())); }
await page.keyboard.down('Shift'); await page.keyboard.press('Tab'); await page.keyboard.up('Shift');
trap.push(await page.evaluate(() => (document.activeElement?.closest('dialog') ? 'in:' : 'OUT:') + document.activeElement?.textContent.trim()));
result.dialogTrap = trap;
result.dialogIcons = await page.$$eval('dialog[open] svg', (s) => s.map((x) => x.dataset.icon));
await page.screenshot({ path: `${OUT}/P09_confirm_dialog.png` });
await page.keyboard.press('Escape');
await new Promise((r) => setTimeout(r, 200));
result.dialogAfter = await page.evaluate(() => ({ open: !!document.querySelector('dialog[open]'), result: document.getElementById('sg-confirm-result').textContent, focusBack: document.activeElement?.hasAttribute('data-demo-confirm') }));

// 토스트
await page.click('[data-demo-toast="success"]');
result.toast = await page.$eval('#toasts', (t) => ({ count: t.children.length, text: t.textContent.trim().slice(0, 60), live: t.getAttribute('aria-live') }));

// 페이지네이션(JS 렌더링)
result.pagination = await page.$eval('[data-pagination]', (n) => n.textContent.replace(/\s+/g, ' ').trim());

// 탭: 화살표 키
const tab = await page.$('[role="tab"]');
if (tab) {
  await tab.focus();
  await page.keyboard.press('ArrowRight');
  result.tabs = await page.evaluate(() => ({ selected: document.querySelector('[role="tab"][aria-selected="true"]')?.textContent.trim(), focused: document.activeElement?.getAttribute('role') }));
}

// 시간 표시(UTC → KST)
result.times = await page.$$eval('time[datetime]', (ts) => ts.slice(0, 2).map((t) => `${t.getAttribute('datetime')} → ${t.textContent}`));

// 일반 사용자: 관리자 메뉴 없음
await page.goto(`${BASE}/dev/styleguide?as=user`, { waitUntil: 'networkidle0' });
result.userAdminNav = await page.$$eval('[data-nav^="admin."]', (n) => n.length);
await page.screenshot({ path: `${OUT}/P09_layout_user.png`, clip: { x: 0, y: 0, width: 1280, height: 120 } });
await page.goto(`${BASE}/dev/styleguide?as=guest`, { waitUntil: 'networkidle0' });
result.guestNav = await page.$$eval('[data-nav]', (n) => n.length);

result.problems = problems;
console.log(JSON.stringify(result, null, 2));
await browser.close();
process.exit(result.problems.length === 0 && result.assetsUnderBase && result.adminNav > 0 && result.userAdminNav === 0 && result.guestNav === 0 ? 0 : 1);
