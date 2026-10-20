// 인수 시험(P32) 공통: API 클라이언트(쿠키·CSRF), 헤드리스 Chrome 화면 점검(콘솔 오류·CSP 위반·가로 스크롤·영어 화면 틀의 한글·글자 잘림·테마 바탕),
// 결과 기록(시나리오별 확인 항목 — 기대·실제·증거·판정). 비밀번호·토큰은 기록하지 않는다(hide).
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { mkdirSync } from 'node:fs';

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
export const sleep = (ms) => new Promise((res) => { setTimeout(res, ms); });

// ---------------------------------------------------------------- 결과
const secrets = new Set();
export const addSecret = (s) => { if (s && String(s).length >= 8) secrets.add(String(s)); }; // 짧은 값(초기 비밀번호 admin 등)은 가리면 낱말이 다 가려짐
export function hide(v) {
  let s = typeof v === 'string' ? v : JSON.stringify(v ?? null);
  for (const x of secrets) s = s.split(x).join('(hidden)');
  return s;
}

export class Recorder {
  constructor() { this.rows = []; this.current = null; this.issues = []; }
  start(n, title) { this.current = { n, title, checks: [], shots: [], started: Date.now() }; this.rows.push(this.current); console.log(`\n[${n}] ${title}`); }
  /** 확인 하나: 기대(무엇이 맞아야 하나)·실제(관찰 값)·증거(캡처·로그 줄·API) */
  check(ok, expected, actual = '', evidence = '') {
    const row = { ok: !!ok, expected, actual: hide(actual).slice(0, 400), evidence: hide(evidence).slice(0, 300) };
    this.current.checks.push(row);
    console.log(`   ${ok ? 'ok ' : 'XX '} ${expected}${ok ? '' : ` — ${row.actual}`}`);
    return !!ok;
  }
  note(text) { this.current.checks.push({ ok: null, expected: text, actual: '', evidence: '' }); console.log(`   ·  ${text}`); }
  shot(file) { this.current.shots.push(file); }
  issue(severity, title, repro, cause, fixed) { this.issues.push({ scenario: this.current?.n, severity, title, repro, cause, fixed }); }
}

// ---------------------------------------------------------------- API
export class Client {
  constructor(base, name) { this.base = base; this.name = name; this.jar = new Map(); this.csrf = ''; }
  cookieHeader() { return [...this.jar].map(([k, v]) => `${k}=${v}`).join('; '); }
  async req(method, p, { json, raw, headers = {}, csrf = true, form } = {}) {
    const h = { Accept: 'application/json', ...headers };
    if (this.jar.size) h.Cookie = this.cookieHeader();
    if (csrf && method !== 'GET' && method !== 'HEAD' && this.csrf) h['X-CSRF-Token'] = this.csrf;
    let body;
    if (json !== undefined) { h['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    else if (raw !== undefined) { h['Content-Type'] ??= 'application/octet-stream'; body = raw; }
    else if (form !== undefined) { body = form; }
    const res = await fetch(this.base + p, { method, headers: h, body, redirect: 'manual' });
    for (const c of res.headers.getSetCookie?.() ?? []) {
      const [kv] = c.split(';');
      const i = kv.indexOf('=');
      const k = kv.slice(0, i).trim();
      const v = kv.slice(i + 1).trim();
      if (/max-age=0|expires=thu, 01 jan 1970/i.test(c) || v === '') this.jar.delete(k); else this.jar.set(k, v);
    }
    const buf = Buffer.from(await res.arrayBuffer());
    const text = buf.toString('utf8');
    let j = null;
    try { j = JSON.parse(text); } catch { /* 본문이 JSON이 아님 */ }
    return { status: res.status, json: j, body: text, buf, headers: res.headers };
  }
  get(p, opt) { return this.req('GET', p, opt); }
  async data(method, p, json) {
    const r = await this.req(method, p, json === undefined ? {} : { json });
    if (r.status >= 400) throw new Error(`${this.name} ${method} ${p} → ${r.status} ${hide(r.body).slice(0, 300)}`);
    return r.json?.data ?? r.json;
  }
  async login(id, pw) {
    addSecret(pw);
    this.jar.clear();
    this.csrf = (await this.get('/api/v1/auth/csrf')).json?.data?.csrf_token ?? '';
    const r = await this.req('POST', '/api/v1/auth/login', { json: { login_id: id, password: pw } });
    if (r.status === 200) this.csrf = r.json.data.csrf_token;
    return r;
  }
}

// ---------------------------------------------------------------- 브라우저
let puppeteer = null;
export async function launch() {
  puppeteer ??= (await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href)).default;
  return puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: ['--lang=ko-KR'] });
}

/** 사용자별 브라우저 페이지(따로 쿠키) — 콘솔 오류·CSP 위반을 모은다 */
export async function userPage(browser, base) {
  const ctx = await browser.createBrowserContext();
  const page = await ctx.newPage();
  const log = { csp: [], errors: [] };
  await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
  page.on('console', (m) => {
    const t = m.text();
    if (/CSP|Content Security Policy/.test(t)) log.csp.push(t);
    else if (m.type() === 'error' && !/status of (40[0-9]|410|503)/.test(t)) log.errors.push(t);
  });
  page.on('pageerror', (e) => log.errors.push(`pageerror: ${e.message}`));
  await page.setViewport({ width: 1280, height: 900 });
  page.base = base;
  page.log = log;
  page.ctx = ctx;
  return page;
}

export async function formLogin(page, id, pw) {
  addSecret(pw);
  await page.goto(`${page.base}/login`, { waitUntil: 'networkidle0' });
  await page.type('#login-id', id);
  await page.type('#login-password', pw);
  await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => null), page.click('#main form button[type=submit]')]);
  return page.evaluate(() => location.pathname);
}

/** 화면 한 장 점검: HTTP, 콘솔 오류·CSP(이 화면에서 새로 생긴 것), 가로 스크롤(1280), 영어면 화면 틀 한글·글자 잘림, 테마 바탕 밝기 */
export async function screen(page, rec, url, { lang, theme, width = 1280, shot, wait, note, allowKo = false } = {}) {
  const before = { csp: page.log.csp.length, err: page.log.errors.length };
  await page.setViewport({ width, height: 900 });
  let res = await page.goto(`${page.base}${url}`, { waitUntil: 'networkidle0', timeout: 60000 });
  if (!res) res = await page.reload({ waitUntil: 'networkidle0', timeout: 60000 });
  if (wait) await page.waitForSelector(wait, { timeout: 15000 }).catch(() => null);
  await sleep(300);
  const r = await page.evaluate(() => {
    const H = /[가-힣]/;
    const frame = [];
    const seen = new Set();
    for (const el of document.querySelectorAll('header, nav, h1, h2, h3, label, th, button, .btn, .badge, .tabs__list, legend, .empty-state, .page-title, .card__title')) {
      if (el.closest('.code-viewer, .cm-editor, [data-user-content], select, option, td, [lang="ko"]')) continue;
      const own = [...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent.trim()).join(' ');
      const text = (own || (el.children.length === 0 ? el.textContent.trim() : '')).slice(0, 60);
      if (text && H.test(text) && !seen.has(text)) { seen.add(text); frame.push(text); }
    }
    const clipped = [];
    for (const el of document.querySelectorAll('header .nav-item, .btn, th, .badge, .tag, .subnav__item, .tabs__list [role="tab"]')) {
      if (el.offsetParent === null) continue;
      const s = getComputedStyle(el);
      if ((s.whiteSpace === 'nowrap' || s.textOverflow === 'ellipsis') && el.scrollWidth > el.clientWidth + 1 && s.overflow !== 'visible') clipped.push(el.textContent.trim().slice(0, 40));
    }
    const rgb = (getComputedStyle(document.body).backgroundColor.match(/\d+/g) ?? [255, 255, 255]).map(Number);
    return {
      lang: document.documentElement.lang, theme: document.documentElement.getAttribute('data-theme'),
      scroll: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      lum: Math.round(((0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255) * 100) / 100,
      frame, clipped, title: document.title,
    };
  });
  const csp = page.log.csp.slice(before.csp);
  const errors = page.log.errors.slice(before.err);
  const bad = [];
  if (res.status() >= 400) bad.push(`HTTP ${res.status()}`);
  if (csp.length) bad.push(`CSP ${csp.length}: ${csp[0]}`);
  if (errors.length) bad.push(`콘솔 오류 ${errors.length}: ${errors[0]}`);
  if (width >= 1280 && r.scroll > 0) bad.push(`가로 스크롤 ${r.scroll}px`);
  if (lang && r.lang !== lang) bad.push(`lang ${r.lang}`);
  if (lang === 'en' && r.frame.length && !allowKo) bad.push(`영어 화면 틀 한글: ${r.frame.slice(0, 3).join(' | ')}`);
  if (lang === 'en' && r.clipped.length) bad.push(`글자 잘림: ${r.clipped.slice(0, 3).join(' | ')}`);
  if (theme === 'dark' && r.lum >= 0.25) bad.push(`다크 바탕 밝음 ${r.lum}`);
  if (theme === 'light' && r.lum <= 0.75) bad.push(`라이트 바탕 어두움 ${r.lum}`);
  let file = '';
  if (shot) {
    mkdirSync(screen.dir, { recursive: true });
    file = `${shot}.png`;
    await page.screenshot({ path: path.join(screen.dir, file), fullPage: false });
    rec.shot(file);
  }
  rec.check(bad.length === 0, `화면 ${url} [${lang ?? '-'}/${theme ?? '-'}/${width}]${note ? ` ${note}` : ''}: HTTP·콘솔·CSP·가로 스크롤${lang === 'en' ? '·영어 틀·잘림' : ''}`,
    bad.length ? bad.join('; ') : `HTTP ${res.status()}, 스크롤 ${r.scroll}, 바탕 ${r.lum}`, file || `title=${r.title}`);
  return { res, ...r };
}
screen.dir = path.join(ROOT, 'docs/results/P32_acceptance');

/** 화면 언어·테마(내 정보 설정) — API로 바꾼다 */
export async function setPrefs(client, language, theme) {
  return client.data('PUT', '/api/v1/users/me/preferences', { language, theme });
}
