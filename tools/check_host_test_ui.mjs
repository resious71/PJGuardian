// 허용 호스트 연결 테스트·저장소 심볼릭 링크 브라우저 점검(P26B, 헤드리스 Chrome). 격리 서버·DB 사본(020 + 점검용 CA를 ca_cert_file로) + 로컬 시험 서버
// (tests/Support/probe_server.php: 정상·만료 임박·자체 서명·Perforce SSL) + 로컬 Git HTTP 저장소(링크 포함) + 격리 워커에서만 쓴다. 외부 호스트는 등록하지 않는다.
//   PJG_PASSWORD=... PJG_HOSTS='{"ok":55078,"soon":55079,"self":55080,"p4":55081,"closed":1}' PJG_REPO_URL=http://127.0.0.1:8097/team/links.git \
//     node tools/check_host_test_ui.mjs <점검 서버> <링크 프로젝트> <일반 사용자> [관리자]
// 확인(1280·1024px): 행별 "연결 테스트"(단계별 체크 목록·해결 안내·결과 영역 aria-live), "전체 테스트", 마지막 테스트·인증서 만료 열과 만료 임박 경고,
//   Perforce SSL 지문 표시, 일반 사용자 403, 분석 상세의 "심볼릭 링크 N개를 건너뛰었습니다", 가로 스크롤 0, 콘솔 오류 0, CSP 위반 0. 캡처 P26B_*.png
// P27C: PJG_HOSTS에 nopage·gone·proxy·bad(평문 HTTP 시험 서버 포트: 루트가 404·410·407·502)를 주면 4xx 판정(407 뺀 4xx = 도달 성공 + 안내,
//   407·5xx = 오류)을 화면에서 확인하고 캡처 P27C_*.png. PJG_ONLY_4XX=1이면 이 부분만(인증서 시험 서버 없이).
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, userLogin, adminLogin = 'admin'] = process.argv;
const HOSTS = JSON.parse(process.env.PJG_HOSTS ?? '{}');
const REPO_URL = process.env.PJG_REPO_URL;
const ONLY_4XX = process.env.PJG_ONLY_4XX === '1';
if (!userLogin || !process.env.PJG_PASSWORD || (ONLY_4XX ? !HOSTS.nopage : (!HOSTS.ok || !REPO_URL))) {
  console.error('사용법: PJG_PASSWORD=... PJG_HOSTS=\'{"ok":…,"soon":…,"self":…,"p4":…,"closed":…}\' PJG_REPO_URL=... node tools/check_host_test_ui.mjs <점검 서버> <프로젝트> <일반 사용자> [관리자]');
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
    else if (/status of (403|409)/.test(m.text())) return; // 의도한 일반 사용자 403, 다시 실행할 때 이미 있는 호스트 409
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
  const res = await fetch(base + u, { method: m, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' }, body: b === undefined ? undefined : JSON.stringify(b) });
  let json = null;
  try { json = await res.json(); } catch { json = null; }
  return { status: res.status, json };
}, method, url, body);
const scrollX = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
const resultText = (page) => page.$eval('[data-host-result]', (n) => n.innerText.replace(/\s+/g, ' ').trim());
const rowOf = async (page, port) => page.$$eval('[data-host-rows] tr', (rows, p) => {
  const tr = rows.find((x) => x.children[1].textContent.trim() === String(p));
  return tr ? [...tr.children].map((c) => c.innerText.replace(/\s+/g, ' ').trim()) : null;
}, port);

const admin = await session(adminLogin);
const a = admin.page;
try {
  // ---- 호스트 등록(시험 서버만) ----
  // 다시 실행해도 되게: 이미 있으면(409) 목록에서 찾는다
  const add = async (port, extra = {}) => {
    const res = await api(a, 'POST', '/api/v1/admin/repo-hosts', { host: '127.0.0.1', port, ...extra });
    if (res.status === 201) return res.json.data.id;
    return (await api(a, 'GET', '/api/v1/admin/repo-hosts')).json.data.find((h) => h.host === '127.0.0.1' && h.port === port)?.id;
  };
  // ---- P27C: 루트 4xx(407 제외) = 도달 성공 + 안내, 407·5xx = 오류 ----
  if (HOSTS.nopage) {
    const four = {
      nopage: [await add(HOSTS.nopage, { allow_http: true, note: '루트 404' }), 'ok', /루트 경로에 페이지가 없습니다/],
      gone: [await add(HOSTS.gone, { allow_http: true, note: '루트 410' }), 'ok', /루트 경로에 페이지가 없습니다/],
      proxy: [await add(HOSTS.proxy, { allow_http: true, note: '프록시 인증 407' }), 'fail_http_status', /프록시 인증 필요/],
      bad: [await add(HOSTS.bad, { allow_http: true, note: '서버 오류 502' }), 'fail_http_status', /오류 응답/],
    };
    await a.goto(`${BASE}/admin/config-settings#repository`, { waitUntil: 'networkidle0' });
    await a.waitForSelector('[data-test-host]');
    r.fourxx = {};
    for (const [k, [id, code, re]] of Object.entries(four)) {
      await a.click(`[data-test-host="${id}"]`);
      await a.waitForFunction((hid, c) => document.querySelector('[data-host-result] [data-code]')?.dataset.code === c && !document.querySelector(`[data-test-host="${hid}"]`).disabled, { timeout: 30000 }, id, code);
      r.fourxx[k] = { result: await resultText(a), row: (await rowOf(a, HOSTS[k]))?.[5] };
      check(re.test(r.fourxx[k].result), `4xx 판정 ${k}(${code})`, r.fourxx[k]);
      if (k === 'nopage') {
        await a.$eval('[data-host-result]', (n) => n.scrollIntoView());
        await a.screenshot({ path: path.join(OUT, 'P27C_host_404.png'), fullPage: true });
      }
      if (k === 'proxy') await a.screenshot({ path: path.join(OUT, 'P27C_host_407.png'), fullPage: false });
    }
    check(/정상/.test(r.fourxx.nopage.row ?? '') && /정상/.test(r.fourxx.gone.row ?? '') && !/정상/.test(r.fourxx.proxy.row ?? ''), '표 마지막 테스트 열', r.fourxx);
    r.fourScroll = [await scrollX(a)];
    await a.setViewport({ width: 1024, height: 900 });
    await sleep(200);
    r.fourScroll.push(await scrollX(a));
    await a.screenshot({ path: path.join(OUT, 'P27C_hosts_1024.png'), fullPage: true });
    await a.setViewport({ width: 1280, height: 900 });
    check(r.fourScroll.every((x) => x <= 0), '가로 스크롤 0(관리 화면은 결함 표 화면이 아니어도 1024에서 0이어야 — P26B 기준)', r.fourScroll);
  }
  if (ONLY_4XX) throw Object.assign(new Error('only4xx'), { only4xx: true });
  const ids = {
    ok: await add(HOSTS.ok, { github_links: true, note: '사내 Git(정상)' }),
    soon: await add(HOSTS.soon, { github_links: true, note: '인증서 만료 임박' }),
    self: await add(HOSTS.self, { github_links: true, note: '자체 서명' }),
    p4: await add(HOSTS.p4, { note: 'Perforce SSL' }),
    closed: await add(HOSTS.closed, { note: '닫힌 포트' }),
    git: await add(8097, { allow_http: true, note: '로컬 Git(HTTP)' }),
  };
  check(Object.values(ids).every(Boolean), '호스트 등록', ids);
  await a.goto(`${BASE}/admin/config-settings#repository`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-test-host]');
  r.beforeLast = await rowOf(a, HOSTS.ok);

  // ---- 행별 연결 테스트: 정상(401은 인증 필요 = 정상) ----
  await a.click(`[data-test-host="${ids.ok}"]`);
  r.busyText = await a.$eval(`[data-test-host="${ids.ok}"]`, (b) => [b.textContent, b.disabled]);
  await a.waitForFunction(() => !document.querySelector('[data-host-result]').hidden && document.querySelector('[data-status]').textContent.includes('결과'));
  r.okResult = await resultText(a);
  check(/정상/.test(r.okResult) && /인증서 신뢰됨/.test(r.okResult) && /401/.test(r.okResult) && /인증 필요/.test(r.okResult), '정상 결과·단계', r.okResult);
  check(r.busyText[1] === true && /테스트 중/.test(r.busyText[0]), '진행 중 버튼 비활성·글자', r.busyText);
  r.okRow = await rowOf(a, HOSTS.ok);
  check(/정상/.test(r.okRow[5]) && /일 남음/.test(r.okRow[6]), '표: 마지막 테스트·인증서 만료', r.okRow);
  await a.$eval('[data-host-result]', (n) => n.scrollIntoView());
  await a.screenshot({ path: path.join(OUT, 'P26B_host_ok.png'), fullPage: true });

  // ---- 자체 서명 → 신뢰 안 됨 + 안내 ----
  await a.click(`[data-test-host="${ids.self}"]`);
  await a.waitForFunction((id) => document.querySelector('[data-host-result] [data-code]')?.dataset.code === 'fail_tls_untrusted' && !document.querySelector(`[data-test-host="${id}"]`).disabled, {}, ids.self);
  r.selfResult = await resultText(a);
  check(/신뢰할 수 없/.test(r.selfResult) && /ca_cert_file/.test(r.selfResult), '자체 서명 → 신뢰 안 됨·안내', r.selfResult);

  // ---- Perforce SSL: 지문 ----
  await a.click(`[data-test-host="${ids.p4}"]`);
  await a.waitForFunction(() => /지문/.test(document.querySelector('[data-host-result]').innerText));
  r.p4Result = await resultText(a);
  check(/공개 키 SHA-1: ([0-9A-F]{2}:){19}[0-9A-F]{2}/.test(r.p4Result) && /자동으로 신뢰하지 않음/.test(r.p4Result), 'Perforce 지문 표시', r.p4Result);
  await a.$eval('[data-host-result]', (n) => n.scrollIntoView());
  await a.screenshot({ path: path.join(OUT, 'P26B_host_p4.png'), fullPage: false });

  // ---- 전체 테스트 ----
  await a.click('[data-test-all]');
  await a.waitForFunction(() => /전체 테스트 결과/.test(document.querySelector('[data-result-title]').textContent) && !document.querySelector('[data-test-all]').disabled, { timeout: 60000 });
  r.allCodes = await a.$$eval('[data-host-result] li[data-code]', (ls) => ls.map((l) => [l.innerText.trim().split(' — ')[0], l.dataset.code]));
  const codes = Object.fromEntries(r.allCodes.map(([h, c]) => [h.replace(/^.*:/, ''), c]));
  check(codes[HOSTS.ok] === 'ok' && codes[HOSTS.soon] === 'warn_cert_expiring' && codes[HOSTS.self] === 'fail_tls_untrusted' && codes[HOSTS.closed] === 'fail_connect' && codes[HOSTS.p4] === 'ok' && codes['8097'] === 'ok',
    '전체 테스트 코드(로컬 Git 서버는 / 에 404 → P27C부터 도달 성공 + 안내. P26B에서는 fail_http_status)', r.allCodes);
  r.soonRow = await rowOf(a, HOSTS.soon);
  r.soonBadge = await a.$$eval('[data-host-rows] .badge--tone-warn', (ns) => ns.map((n) => n.textContent.trim()));
  check(/만료 임박/.test(r.soonRow[5]) && r.soonBadge.some((s) => /일 남음/.test(s)), '만료 임박 경고(표)', [r.soonRow, r.soonBadge]);
  r.scroll1280 = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P26B_hosts_all_1280.png'), fullPage: true });
  await a.setViewport({ width: 1024, height: 900 });
  await sleep(200);
  r.scroll1024 = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P26B_hosts_all_1024.png'), fullPage: true });
  check(r.scroll1280 <= 0 && r.scroll1024 <= 0, '가로 스크롤 0', [r.scroll1280, r.scroll1024]);
  await a.setViewport({ width: 1280, height: 900 });
  r.ariaLive = await a.$eval('[data-host-result]', (n) => n.getAttribute('aria-live'));
  check(r.ariaLive === 'polite', '결과 영역 aria-live', r.ariaLive);

  // ---- 심볼릭 링크: 저장소 연결 → 지금 가져와 분석 → 분석 상세 경고 ----
  const cur = (await api(a, 'GET', `/api/v1/projects/${projectId}/repo`)).json?.data;
  const save = await api(a, 'PUT', `/api/v1/projects/${projectId}/repo`, { kind: 'git', url: REPO_URL, branch: 'main', ...(cur?.configured ? { revision: cur.revision } : {}) });
  check(save.status === 200, '저장소 연결 저장', save);
  const f = await api(a, 'POST', `/api/v1/projects/${projectId}/repo/fetch`, {});
  const aid = f.json?.data?.analysis_id;
  let st = null;
  for (let i = 0; i < 90; i++) {
    st = (await api(a, 'GET', `/api/v1/analyses/${aid}`)).json?.data;
    if (['succeeded', 'failed', 'cancelled'].includes(st?.status)) break;
    await sleep(1000);
  }
  r.symAnalysis = { id: aid, status: st?.status, failure: st?.failure_code, counts: st?.counts, warnings: st?.source_warnings };
  check(st?.status === 'succeeded' && st?.source_warnings?.[0]?.count === 3, '링크가 있어도 분석 성공·경고 3개', r.symAnalysis);
  await a.goto(`${BASE}/analyses/${aid}`, { waitUntil: 'networkidle0' });
  await a.waitForSelector('[data-notice="analyses.notice.symlinks_skipped"]');
  r.symNotice = await a.$eval('[data-notice="analyses.notice.symlinks_skipped"]', (n) => n.textContent.trim());
  check(/심볼릭 링크 3개를 건너뛰었습니다/.test(r.symNotice) && /app\/link_lib/.test(r.symNotice) && /app\/outside\.py/.test(r.symNotice), '분석 상세 링크 경고', r.symNotice);
  r.detailScroll = await scrollX(a);
  await a.screenshot({ path: path.join(OUT, 'P26B_analysis_symlinks.png'), fullPage: false });
} catch (e) {
  if (!e.only4xx) throw e;
} finally {
  await admin.browser.close();
}

const user = await session(userLogin);
try {
  const u = user.page;
  r.userPage = (await u.goto(`${BASE}/admin/config-settings#repository`, { waitUntil: 'networkidle0' })).status();
  r.userApi = (await api(u, 'POST', '/api/v1/admin/repo-hosts/1/test', {})).status;
  r.userAll = (await api(u, 'POST', '/api/v1/admin/repo-hosts/test-all', {})).status;
  check(r.userPage === 403 && r.userApi === 403 && r.userAll === 403, '일반 사용자 403', [r.userPage, r.userApi, r.userAll]);
} finally {
  await user.browser.close();
}

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? `: ${csp.join(' | ')}` : ''}`);
if (problems.length) {
  console.log(`문제 ${problems.length}건:`);
  for (const p of problems) console.log(` - ${p}`);
  process.exit(1);
}
console.log('모든 점검 통과');
