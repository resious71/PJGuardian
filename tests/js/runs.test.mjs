// Run 화면 계산(P20R): 주소 ↔ Run, 선택기 표기, 존재 구간 표기, 결함 목록 상태의 run, 라벨 이스케이프. (Run 비교는 P20S에서 제거)
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  runFromSearch, withRun, runSuffix, runOptionLabel, runName, intervalsText,
} from '../../public/assets/js/lib/run_core.js';
import { stateFromSearch, searchFromState, apiQuery, clearFilters, emptyState } from '../../public/assets/js/lib/defect_filters.js';
import { has } from '../../public/assets/js/core/i18n.js';
import { el } from '../../public/assets/js/core/dom.js';
import { FakeDocument } from './fake-dom.mjs';

const runs = [
  { run: 7, finished_at: '2026-10-04T05:02:00Z', total: 1200, new: 3, resolved: 2, changed: true },
  { run: 6, finished_at: '2026-10-03T05:02:00Z', total: 1199, new: 0, resolved: 0, changed: false },
  { run: 3, finished_at: '2026-10-01T05:02:00Z', total: 900, new: 900, resolved: 0, changed: false },
];

test('주소의 run: 형식 검사, 최신 Run이면 뺌, 다른 쿼리·해시 유지, page는 1로', () => {
  assert.equal(runFromSearch('?run=3'), '3');
  for (const bad of ['?run=0', '?run=-1', '?run=abc', '?run=1.5', '?run=01', '']) assert.equal(runFromSearch(bad), '', bad);
  assert.equal(withRun('/p/1?status=open&page=3#defects', 3), '/p/1?status=open&run=3#defects');
  assert.equal(withRun('/p/1?run=3&status=open', '7', { latest: 7 }), '/p/1?status=open', '최신 Run = run 없음(기존 링크와 같은 주소)');
  assert.equal(withRun('/p/1?run=3', ''), '/p/1');
  assert.equal(runSuffix(4), '?run=4');
  assert.equal(runSuffix(null), '');
});

test('선택기 항목: Run 7 (최신) · 시각(KST) · 결함 N건 · 증감 · 변경 표식', () => {
  const label = runOptionLabel(runs[0], { latest: 7, tz: 'Asia/Seoul' });
  assert.equal(label, 'Run 7 (최신) · 2026-10-04 14:02 · 존재 결함 1,200건 · 신규 3 · 해결 2 · 버전/설정 변경');
  assert.equal(runOptionLabel(runs[1], { latest: 7, tz: 'Asia/Seoul' }), 'Run 6 · 2026-10-03 14:02 · 존재 결함 1,199건');
  assert.equal(runName(5), 'Run 5');
  assert.equal(runName(null, 'failed'), 'Run —(실패)');
  assert.equal(runName(null), 'Run —');
});

test('존재 구간: "Run 1~4, 6~현재", 한 Run, 없음', () => {
  assert.equal(intervalsText([{ from: 1, to: 4 }, { from: 6, to: null }]), 'Run 1~4, 6~현재');
  assert.equal(intervalsText([{ from: 3, to: 3 }]), 'Run 3');
  assert.equal(intervalsText([]), '존재 구간 정보 없음(되채움 전)');
});

test('결함 목록 상태: run은 필터 지우기에 남고 API 쿼리로 간다', () => {
  const s = stateFromSearch('?run=3&status=open');
  assert.equal(s.run, '3');
  assert.equal(searchFromState(s), '?status=open&run=3');
  assert.equal(apiQuery(s, 50).run, '3');
  assert.deepEqual(clearFilters(s), { ...emptyState(), run: '3' });
  // P20S: compare·set은 더 이상 상태가 아니다(주소에 남아 있어도 무시)
  const c = stateFromSearch('?compare=3:7&set=added&run=5');
  assert.deepEqual([c.run, 'compare' in c, 'set' in c], ['5', false, false]);
  assert.equal(searchFromState(c), '?run=5');
  assert.equal(stateFromSearch('?run=x').run, '');
});

test('라벨 이스케이프: 규칙 키·파일 경로는 텍스트 노드로만', () => {
  const doc = new FakeDocument();
  const node = el('code', { text: '<img src=x onerror=alert(1)>' }, [], doc);
  assert.equal(node.textContent, '<img src=x onerror=alert(1)>');
  assert.ok(node.childNodes.every((c) => !(c.tagName)), '자식은 텍스트 노드뿐');
});

test('문자열 리소스: Run 화면 키(비교 키는 없음)', () => {
  for (const k of ['runs.past_banner', 'runs.go_latest', 'runs.not_in_run_title', 'analyses.baseline_of_run', 'defects.code_none.not_in_run',
    'icon.ui.read_only', 'icon.ui.run']) {
    assert.equal(has(k), true, k);
  }
  for (const k of ['compare.title', 'runs.compare_option', 'icon.nav.compare']) assert.equal(has(k), false, k);
});
