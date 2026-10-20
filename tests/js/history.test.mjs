// 히스토리 화면 계산(P21): 주소 상태 복원, 차트·표 데이터 변환, 증감 표시, 이름·변경 근거, 빈 상태, 타임라인 Run 표기와 요약.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  historyState, historySearch, historyChartData, historyChartTable, delta, rowName, changeLines, emptyKind, noChange, timelineRun, timelineSummary,
} from '../../public/assets/js/lib/history_core.js';

const row = (id, run, status, counts = null, extra = {}) => ({ analysis_id: id, run, status, counts, open_total: counts ? 10 + id : null, changed: false, changes: {}, ...extra });

test('주소 ↔ 상태: 분석·쪽·차트 건수, 틀린 값은 기본값', () => {
  assert.deepEqual(historyState('?analysis=12&page=3&n=50'), { analysis: 12, page: 3, n: 50 });
  assert.deepEqual(historyState('?analysis=x&page=0&n=7'), { analysis: null, page: 1, n: 20 });
  assert.equal(historySearch({ analysis: 12, page: 1, n: 20 }), '?analysis=12');
  assert.equal(historySearch({ analysis: null, page: 2, n: 10 }), '?page=2&n=10');
  assert.equal(historySearch({ analysis: null, page: 1, n: 20 }), '');
});

test('차트 데이터: 성공만 오래된 순, 해결은 음수, 변경 지점에 표식', () => {
  const rows = [
    row(5, 3, 'succeeded', { new: 1, resolved: 2, reopened: 0 }, { changed: true }),
    row(4, null, 'failed'),
    row(3, 2, 'succeeded', { new: 0, resolved: 0, reopened: 1 }),
    row(1, 1, 'succeeded', { new: 5, resolved: 0, reopened: 0 }),
  ];
  const d = historyChartData(rows);
  assert.deepEqual(d.labels, ['Run 1', 'Run 2', 'Run 3']);
  assert.deepEqual([d.newer, d.resolved, d.reopened], [[5, 0, 1], [-0, -0, -2], [0, 1, 0]]);
  assert.deepEqual(d.markers, [null, null, 15]);
  assert.deepEqual(d.analysisIds, [1, 3, 5]);
  const tbl = historyChartTable(rows);
  assert.deepEqual(tbl[2], ['Run 3', 1, 2, 0, 15, '변경'], '표는 해결을 양수로');
});

test('증감 표시: 방향 + 글자(색 단독 금지)', () => {
  assert.deepEqual(delta(3), { dir: 'up', text: '+3' });
  assert.deepEqual(delta(-1200), { dir: 'down', text: '−1,200' });
  assert.deepEqual(delta(0), { dir: 'same', text: '0' });
  assert.deepEqual(delta(null), { dir: null, text: '—' });
});

test('이름·변경 근거: Run N · 분석 #id, 실패는 Run —(실패), 값은 문자열 그대로', () => {
  assert.equal(rowName(row(7, 3, 'succeeded', {})), 'Run 3 · 분석 #7');
  assert.equal(rowName(row(8, null, 'failed')), 'Run —(실패) · 분석 #8');
  assert.deepEqual(changeLines({ tool_version: { from: '0.16.10', to: '<b>0.17</b>' } }), ['도구 버전: 0.16.10 → <b>0.17</b>']);
});

test('빈 상태 구분과 변화 없음', () => {
  assert.equal(emptyKind([], 0), 'no_analysis');
  assert.equal(emptyKind([row(1, null, 'running')], 1), 'not_finished');
  assert.equal(emptyKind([row(1, null, 'failed'), row(2, null, 'cancelled')], 2), 'failed_only');
  assert.equal(emptyKind([row(1, 1, 'succeeded', {})], 1), 'single');
  assert.equal(emptyKind([row(2, 2, 'succeeded', {}), row(1, 1, 'succeeded', {})], 2), null);
  assert.equal(noChange({ counts: { new: 0, resolved: 0, reopened: 0 } }), true);
  assert.equal(noChange({ counts: { new: 0, resolved: 1, reopened: 0 } }), false);
});

test('타임라인: Run 표기("Run 3 이후")와 한 줄 요약', () => {
  assert.equal(timelineRun({ run: 3, run_after: false }), 'Run 3');
  assert.equal(timelineRun({ run: 3, run_after: true }), 'Run 3 이후');
  assert.equal(timelineRun({ run: null }), '—');
  assert.equal(timelineSummary({ intervals: [{ from: 1, to: 1 }, { from: 3, to: null }], exists_now: true }),
    'Run 1에서 발생 → Run 2에서 사라짐 → Run 3에서 다시 나타남 → 현재 존재');
  assert.equal(timelineSummary({ intervals: [{ from: 2, to: 3 }], exists_now: false }), 'Run 2에서 발생 → Run 4에서 사라짐 → 현재 없음');
  assert.equal(timelineSummary({ intervals: [], first_run: 5, exists_now: true }), 'Run 5에서 발생 → 현재 존재', '되채움 전');
});
