// 규칙 기본 심각도 화면 점검(P21S, 헤드리스 Chrome). 격리 서버·DB(개발 DB 사본에 012 적용 + severity_apply --apply)에서만 쓴다.
//   PJG_PASSWORD=... node tools/check_severity_ui.mjs <기본 주소> <프로젝트 id> <과거 Run 번호> [로그인]
// 확인:
//   - 결함 목록: 심각도 칩 숫자 = 요약 API, 심각도 필터 결과 건수 = 칩 숫자, 배지 출처 툴팁("규칙 기본값"), title 없음
//   - 일괄 변경 영역의 안내 한 줄, 확인 창의 안내
//   - 결함 상세: 심각도 옆 "규칙 기본값" → 사람이 바꾸면 "수동 변경", 이력 표의 수동 행과 규칙 기본 적용 행, 전체 이력 창의 "기본 심각도 적용"
//   - 목록으로 돌아가면 그 결함 배지 옆 "수동 변경"
//   - 과거 Run 보기: 적용 전 Run은 모두 not_defined(칩·요약)
//   - 대시보드: 열린 결함 심각도 분포가 not_defined만이 아님(새 값으로 집계)
//   - 콘솔 오류 0, CSP 위반 0. 캡처: PJG_SHOT_DIR(기본 docs/results), 이름 P21S_*.png
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { target } from './lib/base_url.mjs';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const { default: puppeteer } = await import(pathToFileURL(path.join(ROOT, 'data/work/puppeteer/node_modules/puppeteer-core/lib/esm/puppeteer/puppeteer-core.js')).href);
const [, , baseArg, projectId, pastRun, login = 'admin'] = process.argv;
const { base: BASE, chromeArgs } = target(ROOT, baseArg);
const OUT = process.env.PJG_SHOT_DIR ?? path.join(ROOT, 'docs/results');
const browser = await puppeteer.launch({ executablePath: process.env.PJG_CHROME ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true, args: chromeArgs });
const csp = [];
const problems = [];
const r = {};
const page = await (await browser.createBrowserContext()).newPage();
await page.setViewport({ width: 1280, height: 900 });
await page.evaluateOnNewDocument(() => document.addEventListener('securitypolicyviolation', (e) => console.error(`CSP ${e.violatedDirective} ${e.blockedURI}`)));
page.on('console', (m) => {
  if (/CSP|Content Security Policy/.test(m.text())) csp.push(m.text());
  else if (['error', 'warning'].includes(m.type())) problems.push(`console.${m.type()}: ${m.text()}`);
});
page.on('response', (res) => { if (res.status() >= 400 && res.status() !== 409) problems.push(`HTTP ${res.status()}: ${res.url()}`); });
page.on('pageerror', (e) => problems.push(`pageerror: ${e.message}`));
const api = (p) => page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'application/json' } })).json(), p);
const check = (ok, label, detail) => { if (!ok) problems.push(`${label}: ${JSON.stringify(detail)}`); };
const rowsReady = () => page.waitForFunction(() => document.querySelector('[data-rows] tr') && !document.querySelector('[data-section-defects]')?.getAttribute('aria-busy'), { timeout: 20000 });

await page.goto(`${BASE}/login`, { waitUntil: 'networkidle0' });
await page.type('#login-id', login);
await page.type('#login-password', process.env.PJG_PASSWORD);
await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle0' }), page.click('#main form button[type=submit]')]);

// ---- 결함 목록: 칩 = 요약, 필터 건수, 출처 툴팁 ----
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await rowsReady();
const summary = (await api(`/api/v1/projects/${projectId}/defects/summary`)).data;
const chips = await page.$$eval('[data-facet="severity"] .chip', (ns) => ns.map((n) => Number(n.querySelector('.chip__count').textContent.replace(/[^0-9]/g, ''))));
r.severityChips = chips;
r.severityApi = summary.severity;
check(JSON.stringify(chips) === JSON.stringify(['critical', 'high', 'medium', 'low', 'not_defined'].map((s) => summary.severity[s])), '칩 = 요약', { chips, api: summary.severity });
check(summary.severity.not_defined < summary.total, '새 값으로 집계(not_defined만이 아님)', summary.severity);
const top = ['high', 'medium', 'low', 'critical'].find((s) => summary.severity[s] > 0);
await page.goto(`${BASE}/projects/${projectId}?severity=${top}`, { waitUntil: 'networkidle0' });
await rowsReady();
r.filtered = { severity: top, count: await page.$eval('[data-count]', (n) => n.textContent) };
check(r.filtered.count.replace(/[^0-9]/g, '') === String(summary.severity[top]), '필터 건수 = 칩', r.filtered);
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await rowsReady();
// 출처가 auto인 첫 결함(다시 실행해도 같은 점검이 되도록 앞 실행이 manual로 바꾼 결함은 건너뛴다)
const AUTO = '[data-rows] tr:has(.severity-cell[data-severity-source="auto"])';
r.firstCell = await page.$eval(`${AUTO} .severity-cell`, (n) => ({ source: n.dataset.severitySource, title: n.getAttribute('title'), tip: n.dataset.tip }));
check(r.firstCell.source === 'auto' && r.firstCell.title === null && r.firstCell.tip.startsWith('규칙 기본값'), '목록 출처', r.firstCell);
await page.$eval(AUTO, (n) => n.scrollIntoView({ block: 'center' }));
await page.hover(`${AUTO} .severity-cell`);
await page.waitForSelector('#pjg-clip-tip:not([hidden])', { timeout: 5000 });
r.listTip = await page.$eval('#pjg-clip-tip', (n) => n.textContent);
await page.screenshot({ path: path.join(OUT, 'P21S_list_tooltip.png') }); // 보이는 화면(툴팁 포함)
// 일괄 변경 안내
await page.mouse.move(5, 5);
await page.click(`${AUTO} input[data-select]`);
await page.waitForFunction(() => !document.querySelector('[data-bulk-bar]').hidden);
r.bulkNote = await page.$eval('#bulk-severity-note', (n) => (n.offsetParent ? n.textContent : ''));
check(r.bulkNote.includes('사람이 바꾼 심각도'), '일괄 변경 안내', r.bulkNote);
await page.select('#bulk-severity', 'low');
await page.click('[data-bulk-apply]');
await page.waitForSelector('dialog[open]', { timeout: 5000 });
r.bulkConfirm = await page.$eval('dialog[open]', (d) => d.textContent);
check(r.bulkConfirm.includes('사람이 바꾼 심각도'), '확인 창 안내', r.bulkConfirm.slice(0, 200));
await page.screenshot({ path: path.join(OUT, 'P21S_bulk_note.png') });
await page.keyboard.press('Escape');

// ---- 결함 상세: 출처, 수동 변경, 이력, 전체 이력 ----
const defectId = await page.$eval(AUTO, (tr) => Number(tr.dataset.defectId));
await page.goto(`${BASE}/defects/${defectId}`, { waitUntil: 'networkidle0' });
await page.waitForSelector('[data-history-rows] tr', { timeout: 20000 });
r.detailBefore = await page.$eval('[data-field="severity"] [data-severity-source]', (n) => n.textContent.trim());
r.historyRuleDefault = await page.$$eval('[data-history-rows] tr', (trs) => trs.map((tr) => tr.lastElementChild.textContent).filter((t) => t.includes('규칙 기본 심각도')));
check(r.detailBefore === '규칙 기본값', '상세 출처(전)', r.detailBefore);
check(r.historyRuleDefault.length === 1 && /매핑 (ruff|eslint) v\d+/.test(r.historyRuleDefault[0]), '이력의 규칙 기본 적용 행', r.historyRuleDefault);
await page.screenshot({ path: path.join(OUT, 'P21S_detail_auto.png'), clip: { x: 0, y: 0, width: 1280, height: 900 } });
const current = await page.$eval('#de-severity', (s) => s.value);
const next = current === 'low' ? 'medium' : 'low';
await page.select('#de-severity', next);
await page.click('[data-edit-save]');
await page.waitForFunction((v) => document.querySelector('[data-field="severity"] [data-severity-source]')?.textContent.trim() === '수동 변경', { timeout: 10000 }, next);
r.detailAfter = await page.$eval('[data-field="severity"] [data-severity-source]', (n) => n.textContent.trim());
await page.waitForFunction(() => document.querySelector('[data-history-rows] tr')?.textContent.includes('수동'), { timeout: 10000 });
r.historyTop = await page.$eval('[data-history-rows] tr', (tr) => [...tr.children].map((td) => td.textContent.trim()));
check(r.historyTop.includes('수동'), '이력 맨 위 = 수동 변경', r.historyTop);
// 전체 이력 창
await page.click('[data-open-timeline]');
await page.waitForSelector('dialog[open] tr[data-kind]', { timeout: 10000 });
r.timeline = await page.$$eval('dialog[open] tr[data-kind]', (trs) => trs.map((tr) => [tr.dataset.kind, ...[...tr.children].map((td) => td.textContent.trim())]));
const sd = r.timeline.find((x) => x[0] === 'severity_default');
check(sd && sd[5] === '시스템(규칙 기본값)' && /Run \d+ 이후/.test(sd[2]), '전체 이력의 기본 심각도 적용', sd);
await page.screenshot({ path: path.join(OUT, 'P21S_timeline.png') });
await page.keyboard.press('Escape');

// ---- 목록: 그 결함 배지 옆 "수동 변경" ----
await page.goto(`${BASE}/projects/${projectId}`, { waitUntil: 'networkidle0' });
await rowsReady();
r.listManual = await page.$eval(`[data-rows] tr[data-defect-id="${defectId}"] .severity-cell`, (n) => ({ source: n.dataset.severitySource, text: n.textContent }));
check(r.listManual.source === 'manual' && r.listManual.text.includes('수동 변경'), '목록 수동 표시', r.listManual);
await page.$eval(`[data-rows] tr[data-defect-id="${defectId}"]`, (n) => n.scrollIntoView({ block: 'center' }));
await page.screenshot({ path: path.join(OUT, 'P21S_list_manual.png') });

// ---- 과거 Run: 적용 전 값 ----
const past = (await api(`/api/v1/projects/${projectId}/defects/summary?run=${pastRun}`)).data;
r.past = { total: past.total, severity: past.severity };
check(past.severity.not_defined === past.total && past.total > 0, `Run ${pastRun}(적용 전)은 not_defined`, r.past);
await page.goto(`${BASE}/projects/${projectId}?run=${pastRun}`, { waitUntil: 'networkidle0' });
await rowsReady();
r.pastChips = await page.$$eval('[data-facet="severity"] .chip', (ns) => ns.map((n) => n.querySelector('.chip__count').textContent));
r.pastCell = await page.$eval('[data-rows] tr td:nth-child(1)', (td) => ({ source: td.querySelector('.severity-cell')?.dataset.severitySource ?? 'none', text: td.textContent.trim() }));

// ---- 대시보드 ----
await page.goto(`${BASE}/dashboard`, { waitUntil: 'networkidle0' });
await page.waitForFunction(() => document.querySelector('[data-section="summary"] [data-body]') && !document.querySelector('[data-section="summary"] [data-body]').hidden, { timeout: 20000 });
const dash = (await api('/api/v1/dashboard/summary')).data;
r.dashboard = dash.open?.by_severity ?? null;
check(r.dashboard && Object.entries(r.dashboard).some(([k, v]) => k !== 'not_defined' && v > 0), '대시보드 열린 결함 심각도(새 값)', r.dashboard);
await (await page.$('[data-section="summary"]')).screenshot({ path: path.join(OUT, 'P21S_dashboard.png') });

console.log(JSON.stringify(r, null, 1));
console.log(`CSP 위반 ${csp.length}건${csp.length ? `: ${csp.join(' | ')}` : ''}`);
console.log(`문제 ${problems.length}건${problems.length ? `:\n- ${problems.join('\n- ')}` : ''}`);
await browser.close();
process.exit(csp.length || problems.length ? 1 : 0);
