// 가이드 이미지 만들기(P29, 개발 전용 — 사람이 손으로 찍지 않는다). resources/guide/screenshots.json의 목록대로
// 가짜 데이터 설치본(tools/guide_demo/setup.php — 새 DB·가짜 사용자·합성 결함, 실제 개발 DB·사용자·경로·호스트 없음)을 임시 폴더에 만들고
// 별도 포트의 PHP 내장 서버(tools/guide_demo/router.php)를 띄워 헤드리스 Chrome으로 1280px·라이트 테마·ko/en 두 벌을 찍는다.
// 결과: resources/guide/<audience>/img/<slug>.<lang>.png(300KB 이하·가로 1600px 이하 — 넘으면 실패). 끝나면 서버를 멈추고 임시 폴더를 지운다.
//   node tools/make_guide_screenshots.mjs [--port=8098] [--only=slug,…] [--dry-run] [--keep]
//   --dry-run: 목록 검사만(브라우저·서버 없이 — 시험이 씀). 출력에 비밀번호를 남기지 않는다.
import { spawn, execFileSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readFileSync, statSync, rmSync, mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const opt = (name, def = null) => (argv.find((a) => a.startsWith(`--${name}=`))?.split('=')[1] ?? (argv.includes(`--${name}`) ? true : def));
const PHP = process.env.PJG_PHP ?? 'C:/xampp/php/php.exe';
const PORT = Number(opt('port', '8098'));
const MAX_BYTES = 307200;
const list = JSON.parse(readFileSync(path.join(ROOT, 'resources/guide/screenshots.json'), 'utf8'));
const only = opt('only') ? String(opt('only')).split(',') : null;
const images = list.images.filter((i) => !only || only.includes(i.slug));

// 목록 검사(드라이런·실행 공통)
const problems = [];
for (const i of list.images) {
  if (!/^[a-z0-9][a-z0-9-]{0,63}$/.test(i.slug ?? '')) problems.push(`slug 형식: ${i.slug}`);
  if (!['user', 'admin'].includes(i.audience)) problems.push(`${i.slug}: audience`);
  if (!Array.isArray(i.langs) || i.langs.some((l) => !['ko', 'en'].includes(l))) problems.push(`${i.slug}: langs`);
  if (typeof i.path !== 'string' || !i.path.startsWith('/')) problems.push(`${i.slug}: path`);
  if (!i.clip || (!i.clip.selector && !(i.clip.width > 0 && i.clip.height > 0))) problems.push(`${i.slug}: clip`);
  if ((i.clip?.width ?? 0) > 1600) problems.push(`${i.slug}: 가로 1600px 초과`);
}
if (new Set(list.images.map((i) => i.slug)).size !== list.images.length) problems.push('slug 중복');
// 그림 수 상한: 사용자 가이드 6장(P29), 관리자 가이드 8장(P29C)
for (const [aud, max] of [['user', 6], ['admin', 8]]) {
  const n = list.images.filter((i) => i.audience === aud).length;
  if (n > max) problems.push(`${aud} 가이드 이미지는 최대 ${max}장(${n})`);
}
if (problems.length) {
  console.error(`목록 오류:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
if (opt('dry-run')) {
  console.log(`목록 정상: ${list.images.length}장 × 언어 → ${list.images.map((i) => `${i.audience}/img/${i.slug}.{${i.langs.join(',')}}.png`).join(', ')}`);
  process.exit(0);
}

const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const work = path.join(ROOT, 'data/work', `guide_shots_${randomBytes(4).toString('hex')}`);
const password = `Demo-${randomBytes(9).toString('base64url')}1!`;
mkdirSync(work, { recursive: true });
let server = null;
const cleanup = () => {
  try { server?.kill(); } catch { /* 이미 끝남 */ }
  if (!opt('keep')) rmSync(work, { recursive: true, force: true });
};
process.on('exit', cleanup);
try {
  execFileSync(PHP, [path.join(ROOT, 'tools/guide_demo/setup.php'), work, String(PORT)], { env: { ...process.env, PJG_DEMO_PASSWORD: password }, stdio: ['ignore', 'pipe', 'inherit'] });
  server = spawn(PHP, ['-S', `127.0.0.1:${PORT}`, '-t', path.join(ROOT, 'public'), path.join(ROOT, 'tools/guide_demo/router.php')],
    { env: { ...process.env, PJG_ISO_INI: path.join(work, 'config.ini') }, stdio: 'ignore' });
  const base = `http://127.0.0.1:${PORT}`;
  for (let i = 0; i < 50; i += 1) {
    try { if ((await fetch(`${base}/login`)).ok) break; } catch { /* 아직 */ }
    await new Promise((r) => { setTimeout(r, 200); });
  }
  const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const made = [];
  // P29C: 그림마다 로그인 계정(img.login, 기본 list.login — 관리자 그림은 가짜 관리자 demo-admin). 계정마다 따로 로그인하고 언어는 계정 화면 설정으로 바꾼다
  for (const login of [...new Set(images.map((x) => x.login ?? list.login))]) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  await page.setViewport({ width: list.viewport.width, height: list.viewport.height, deviceScaleFactor: 1 });
  await page.emulateTimezone('Asia/Seoul');
  await page.setCookie({ name: 'pjg_lang', value: 'ko', url: base }, { name: 'pjg_theme', value: 'light', url: base });
  await page.goto(`${base}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', login);
  await page.type('#login-password', password);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);
  for (const lang of ['ko', 'en']) {
    // 로그인 때 쿠키 언어가 계정 값으로 옮겨지므로(P27D) 계정의 화면 언어를 바꾼다(가짜 설치본의 가짜 계정)
    await page.goto(`${base}/projects`, { waitUntil: 'networkidle0' });
    const st = await page.evaluate(async (l) => (await fetch('/api/v1/users/me/preferences', { method: 'PUT', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: JSON.stringify({ language: l, theme: 'light' }) })).status, lang);
    if (st !== 200) throw new Error(`화면 언어 바꾸기 실패(${st})`);
    for (const img of images.filter((x) => x.langs.includes(lang) && (x.login ?? list.login) === login)) {
      await page.goto(base + img.path, { waitUntil: 'networkidle0' });
      if (img.path.includes('#')) await page.reload({ waitUntil: 'networkidle0' }); // 같은 주소·해시만 다르면 다시 읽지 않는다
      if (img.wait) await page.waitForSelector(img.wait, { timeout: 15000 });
      for (const step of img.prepare ?? []) {
        if (step.click) await page.click(step.click);
        if (step.wait) await page.waitForSelector(step.wait, { timeout: 10000 });
        if (step.scrollTo) await page.$eval(step.scrollTo, (n) => n.scrollIntoView({ block: 'start' }));
      }
      await new Promise((r) => { setTimeout(r, 400); });
      let clip = img.clip;
      if (clip.selector) {
        const box = await page.$eval(clip.selector, (n) => { const r = n.getBoundingClientRect(); return { x: r.x + scrollX, y: r.y + scrollY, width: r.width, height: r.height }; });
        clip = { x: Math.max(0, Math.floor(box.x)), y: Math.max(0, Math.floor(box.y)), width: Math.min(1600, Math.ceil(box.width)), height: Math.ceil(Math.min(box.height, clip.maxHeight ?? box.height)) };
      }
      const dir = path.join(ROOT, 'resources/guide', img.audience, 'img');
      if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
      const file = path.join(dir, `${img.slug}.${lang}.png`);
      await page.screenshot({ path: file, clip, captureBeyondViewport: true });
      const bytes = statSync(file).size;
      made.push(`${img.audience}/img/${img.slug}.${lang}.png ${clip.width}x${clip.height} ${bytes}B`);
      if (bytes > MAX_BYTES) problems.push(`${img.slug}.${lang}.png ${bytes}바이트 > ${MAX_BYTES}(자르는 영역을 줄이세요)`);
    }
  }
  await ctx.close();
  }
  await browser.close();
  console.log(made.join('\n'));
} catch (e) {
  problems.push(String(e?.message ?? e));
}
if (problems.length) {
  console.error(`문제:\n- ${problems.join('\n- ')}`);
  process.exit(1);
}
console.log('가이드 이미지 만들기 완료');
process.exit(0); // 띄운 서버를 정리(exit 처리기)하고 끝낸다
