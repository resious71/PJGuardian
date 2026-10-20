// 결함 표 "Run"·"최근 감지" 열(P21U): 날짜 헬퍼(yy-mm-dd, 표시 시간대 경계·월말·윤년), 열 값(Run 링크·한정 표시·값 없음),
// 표 행(머리글과 같은 열 수, 툴팁에 전체 일시, title 없음), 정렬 키(run), 범례 표식 크기, 막대 색 토큰 대비.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { formatShortDate } from '../../public/assets/js/core/time.js';
import { lastDetectedCells } from '../../public/assets/js/lib/run_core.js';
import { stateFromSearch, searchFromState, apiQuery, SORTS } from '../../public/assets/js/lib/defect_filters.js';
import { defectRow } from '../../public/assets/js/ui/defect_table.js';
import { legendOptions, legendMarkSize, LEGEND_FONT_SIZE } from '../../public/assets/js/ui/chart_style.js';
import { contrastRatio } from '../../tools/lib/contrast.mjs';
import { FakeDocument } from './fake-dom.mjs';

const KST = 'Asia/Seoul';

test('날짜 yy-mm-dd: KST 자정 전후(UTC 저장값), 월말·연말, 윤년', () => {
  assert.equal(formatShortDate('2026-10-05T14:59:59Z', KST), '26-10-05', 'KST 23:59:59');
  assert.equal(formatShortDate('2026-10-05T15:00:00Z', KST), '26-10-06', 'KST 다음 날 00:00');
  assert.equal(formatShortDate('2026-10-05T15:00:00Z', 'UTC'), '26-10-05', '시간대 설정을 따른다');
  assert.equal(formatShortDate('2026-10-31T15:30:00Z', KST), '26-11-01', '월말 → 다음 달');
  assert.equal(formatShortDate('2026-12-31T15:00:00Z', KST), '27-01-01', '연말 → 다음 해');
  assert.equal(formatShortDate('2028-02-28T15:00:00Z', KST), '28-02-29', '윤년 2월 29일');
  assert.equal(formatShortDate('2027-02-28T15:00:00Z', KST), '27-03-01', '평년');
  assert.equal(formatShortDate('2000-02-29T00:00:00Z', KST), '00-02-29', '두 자리 연도 앞 0');
  assert.throws(() => formatShortDate('2026-10-05 15:00:00', KST), 'UTC 표기가 없으면 거부');
});

const row = (extra = {}) => ({
  id: 7, project_id: 3, file_path: 'src/a.py', line: 2, message: 'm', rule_key: 'ruff:F401', rule_url: null, severity: 'high', status: 'open',
  assignee: null, version: 1, last_seen: { analysis_id: 40, run: 5, finished_at: '2026-10-05T15:10:20Z' },
  last_detected_run: 5, last_detected_at: '2026-10-05T15:10:20Z', last_detected_analysis_id: 40, ...extra,
});

test('Run·최근 감지 값: 링크는 그 Run의 히스토리 비교 패널, 툴팁용 전체 일시, 값 없음은 —', () => {
  assert.deepEqual(lastDetectedCells(row(), { base: '/app', tz: KST }), {
    run: 'Run 5', href: '/app/projects/3/history?analysis=40', date: '26-10-06', full: '2026-10-06 00:10:20', capped: false,
  });
  const none = lastDetectedCells(row({ last_detected_run: null, last_detected_at: null }), { tz: KST });
  assert.deepEqual([none.run, none.href, none.date, none.full], ['—', null, '—', '']);
  assert.equal(lastDetectedCells(row({ last_detected_at: 'bad' }), { tz: KST }).date, '—', '형식이 틀리면 —');
});

test('과거 Run 보기 한정: 서버가 N으로 한정한 값을 그대로 쓰고, 실제 마지막 감지가 뒤면 capped', () => {
  // Run 3 보기: 서버가 last_detected_*를 Run 3으로 한정해 준다(last_seen은 최신 Run 5 그대로)
  const capped = lastDetectedCells(row({ last_detected_run: 3, last_detected_analysis_id: 30, last_detected_at: '2026-10-03T00:00:00Z' }), { tz: KST, viewRun: 3 });
  assert.deepEqual([capped.run, capped.href, capped.date, capped.capped], ['Run 3', '/projects/3/history?analysis=30', '26-10-03', true]);
  const own = lastDetectedCells(row({ last_seen: { analysis_id: 20, run: 2, finished_at: 'x' }, last_detected_run: 2, last_detected_analysis_id: 20 }), { tz: KST, viewRun: 3 });
  assert.equal(own.capped, false, '마지막 감지 ≤ N이면 한정 아님');
});

test('표 행: 열 순서(…담당자, Run, 최근 감지), 툴팁은 data-tip(title 없음), Run 링크', () => {
  const doc = new FakeDocument({ 'app-base': '/app' });
  const tr = defectRow(row(), { detailBase: '/app/defects/', tz: KST }, doc);
  const tds = tr.childNodes;
  assert.equal(tds.length, 8, '심각도·상태·규칙·파일:줄·메시지·담당자·Run·최근 감지');
  const [run, seen] = [tds[6].childNodes[0], tds[7].childNodes[0]];
  assert.deepEqual([run.tagName, run.getAttribute('href'), run.textContent], ['A', '/app/projects/3/history?analysis=40', 'Run 5']);
  assert.equal(run.dataset.tip, '마지막으로 감지한 Run — 누르면 히스토리 비교 패널');
  assert.deepEqual([seen.textContent, seen.dataset.tip, seen.getAttribute('tabindex')], ['26-10-06', '2026-10-06 00:10:20 · 마지막 감지 분석 종료 시각', '0']);
  for (const n of [run, seen]) assert.equal(n.getAttribute('title'), null, 'title 대신 공통 툴팁');
  // 과거 Run 보기에서 한정된 값: 툴팁에 한정 안내
  const capped = defectRow(row({ last_detected_run: 3, last_detected_analysis_id: 30 }), { detailBase: '/d/', tz: KST, viewRun: 3 }, doc).childNodes[6].childNodes[0];
  assert.match(capped.dataset.tip, /Run 3 보기: Run 3 이하의 마지막 감지/);
  // 값 없음: 링크 없이 —
  const none = defectRow(row({ last_detected_run: null, last_detected_at: null }), { detailBase: '/d/', tz: KST }, doc);
  assert.deepEqual([none.childNodes[6].childNodes[0].tagName, none.childNodes[6].textContent, none.childNodes[7].textContent], ['SPAN', '—', '—']);
  // 내 결함(프로젝트 열 + 같은 두 열)
  assert.equal(defectRow(row({ project: { id: 3, name: 'p' } }), { detailBase: '/d/', tz: KST, project: true }, doc).childNodes.length, 9);
});

test('정렬 키 run: 주소 ↔ 상태 ↔ API(허용 목록 밖은 기본값)', () => {
  assert.ok(SORTS.includes('run'));
  const s = stateFromSearch('?sort=run&order=desc');
  assert.deepEqual([s.sort, s.order], ['run', 'desc']);
  assert.equal(searchFromState(s).includes('sort=run'), true);
  assert.equal(apiQuery(s).sort, 'run');
  assert.equal(stateFromSearch('?sort=last_detected_run').sort, 'file');
});

test('범례 표식은 글자 크기 이내, 선 굵기와 무관', () => {
  const o = legendOptions('#344054');
  assert.ok(legendMarkSize(o.labels) <= LEGEND_FONT_SIZE, `${legendMarkSize(o.labels)} ≤ ${LEGEND_FONT_SIZE}`);
  assert.equal(o.labels.font.size, LEGEND_FONT_SIZE);
  assert.equal(o.labels.padding, LEGEND_FONT_SIZE, '항목 간격 = 글자 크기');
  assert.equal(o.labels.usePointStyle, true, '선 모양·점 모양 구분 유지');
  const fakeChart = { constructor: { defaults: { plugins: { legend: { labels: { generateLabels: () => [{ text: '전체', lineWidth: 3 }, { text: '낮음', lineWidth: 1 }] } } } } } };
  assert.deepEqual(o.labels.generateLabels(fakeChart).map((i) => i.lineWidth), [1, 1]);
});

test('막대 색 토큰(라이트) 대비: 테두리 3:1 이상, 막대 끝 글자 4.5:1 이상', () => {
  const css = readFileSync(new URL('../../public/assets/css/tokens.css', import.meta.url), 'utf8');
  const tok = (n) => new RegExp(`--${n}:[ ]*(#[0-9a-fA-F]{6})`).exec(css)[1];
  const bg = tok('color-surface');
  const fill = tok('chart-bar');
  const border = tok('chart-bar-border');
  const text = tok('chart-text');
  assert.ok(contrastRatio(border, bg) >= 3, `테두리:배경 ${contrastRatio(border, bg)}`);
  assert.ok(contrastRatio(border, fill) >= 3, `테두리:채움 ${contrastRatio(border, fill)}`);
  assert.ok(contrastRatio(text, bg) >= 4.5, `글자:배경 ${contrastRatio(text, bg)}`);
  assert.ok(contrastRatio(text, fill) >= 4.5, `글자:채움(겹칠 때) ${contrastRatio(text, fill)}`);
  assert.ok(contrastRatio(fill, bg) < contrastRatio('#56b4e9', bg), '이전 막대색(#56b4e9)보다 밝은 파스텔');
  // 계산 함수 자체: 알려진 값
  assert.equal(contrastRatio('#000000', '#ffffff'), 21);
  assert.equal(contrastRatio('#ffffff', '#ffffff'), 1);
  assert.equal(contrastRatio('#767676', '#ffffff'), 4.54);
});

test('심각도 출처(P21S): auto는 툴팁만, manual은 툴팁 + "수동 변경" 글자, none은 배지만(title 없음)', async () => {
  const { severityCell } = await import('../../public/assets/js/ui/defect_table.js');
  const doc = new FakeDocument();
  const auto = severityCell({ severity: 'high', severity_source: 'auto' }, doc);
  assert.equal(auto.dataset.severitySource, 'auto');
  assert.match(auto.dataset.tip, /^규칙 기본값/);
  assert.equal(auto.childNodes.length, 1, '글자 없음');
  const manual = severityCell({ severity: 'low', severity_source: 'manual' }, doc);
  assert.match(manual.dataset.tip, /^수동 변경/);
  assert.equal(manual.childNodes[1].textContent, '수동 변경');
  const none = severityCell({ severity: 'not_defined', severity_source: 'none' }, doc);
  assert.equal(none.dataset.severitySource, undefined, '배지만');
  const old = severityCell({ severity: 'high' }, doc);
  assert.equal(old.dataset.severitySource, undefined, '출처 없는 응답(이전 서버)도 배지만');
  for (const n of [auto, manual]) assert.equal(n.getAttribute('title'), null);
});
