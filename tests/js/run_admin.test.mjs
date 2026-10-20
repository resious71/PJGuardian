// Run 보관과 관리자 삭제(P21B): 관리 화면 계산, 삭제된 Run의 표시(전체 이력·대시보드 추이·결함 표 Run 열)
import test from 'node:test';
import assert from 'node:assert/strict';
import { selectOlder, confirmMatches, stateText, summaryText, deleteErrorText, resultText } from '../../public/assets/js/lib/run_admin_core.js';
import { timelineRun } from '../../public/assets/js/lib/history_core.js';
import { pointHref } from '../../public/assets/js/lib/dashboard_core.js';
import { defectRow } from '../../public/assets/js/ui/defect_table.js';
import { has } from '../../public/assets/js/core/i18n.js';
import { FakeDocument } from './fake-dom.mjs';

test('오래된 Run 모두 선택: 최근 N개와 최신 Run은 언제나 빠짐, 번호 오름차순', () => {
  const kept = [12, 3, 9, 10, 11, 5];
  assert.deepEqual(selectOlder(kept, 12, 3), [3, 5, 9]);
  assert.deepEqual(selectOlder(kept, 12, 1), [3, 5, 9, 10, 11], 'N=1이어도 최신은 빠짐');
  for (const bad of [0, -2, 1.5, null, undefined]) assert.deepEqual(selectOlder(kept, 12, bad), [3, 5, 9, 10, 11], `잘못된 N(${bad})은 1`);
  assert.deepEqual(selectOlder([12], 12, 1), [], '최신만 남으면 고를 것 없음');
  assert.deepEqual(selectOlder([1, 2, 3], 12, 20), [], 'N이 크면 없음');
});

test('확인 입력: 프로젝트 이름과 정확히 같아야(앞뒤 공백만 무시, 대소문자 구분)', () => {
  assert.equal(confirmMatches('Chart.js', 'Chart.js'), true);
  assert.equal(confirmMatches('  Chart.js ', 'Chart.js'), true);
  for (const bad of ['chart.js', 'Chart', '', '   ', null, undefined, 3]) assert.equal(confirmMatches(bad, 'Chart.js'), false, String(bad));
  assert.equal(confirmMatches('', ''), false, '빈 이름은 언제나 불일치');
});

test('상세 상태·요약·결과 문장', () => {
  assert.equal(stateText({ detail_deleted: null }), '상세 있음');
  assert.equal(stateText({ detail_deleted: { reason: 'manual' } }), '수동 삭제됨');
  assert.equal(stateText({ detail_deleted: { reason: 'retention' } }), '자동 삭제됨');
  assert.equal(summaryText({ kept: 15, keep: 15, deleted: 3, next_retention: [] }), '상세 있는 Run 15개(자동 보관 기준 15개), 삭제된 Run 3개.');
  assert.equal(summaryText({ kept: 17, keep: 15, deleted: 0, next_retention: [4, 5] }),
    '상세 있는 Run 17개(자동 보관 기준 15개), 삭제된 Run 0개. 다음 분석 뒤 자동 삭제될 Run: 4, 5.');
  assert.match(summaryText({ kept: 40, keep: 0, deleted: 0 }), /자동 보관 꺼짐/);
  assert.equal(resultText({ deleted: [3, 4], already: [] }), 'Run 2개의 상세를 지웠습니다(3, 4).');
  assert.equal(resultText({ deleted: [], already: [5] }), 'Run 0개의 상세를 지웠습니다(—). 이미 삭제된 Run은 건너뛰었습니다(5).');
});

test('삭제 오류 문장: 서버 메시지 + 문제 Run(최신 Run·없는 Run)', () => {
  assert.equal(deleteErrorText({ message: '최신 Run은 삭제할 수 없습니다.', details: { runs: [12] } }), '최신 Run은 삭제할 수 없습니다. (해당 Run: 12)');
  assert.equal(deleteErrorText({ message: '오류', details: {} }), '오류');
  assert.equal(deleteErrorText({ message: '오류' }), '오류');
});

test('삭제된 Run 표시: 전체 이력 "(상세 삭제됨)", 추이 점은 이동 없음(null), Run 열 툴팁 안내', () => {
  assert.equal(timelineRun({ run: 3, run_after: false, run_detail_deleted: true }), 'Run 3(상세 삭제됨)');
  assert.equal(timelineRun({ run: 3, run_after: true, run_detail_deleted: true }), 'Run 3 이후(상세 삭제됨)');
  assert.equal(timelineRun({ run: 3, run_after: false, run_detail_deleted: false }), 'Run 3');
  assert.equal(pointHref('/b', 7, { run: 2, analysis_id: 5, detail_deleted: true }), null);
  assert.equal(pointHref('/b', 7, { run: 2, analysis_id: 5, detail_deleted: false }), '/b/projects/7?run=2');

  const doc = new FakeDocument({ 'app-base': '/app' });
  const d = {
    id: 1, severity: 'high', severity_source: 'none', status: 'open', rule_key: 'ruff:F401', rule_url: null, file_path: 'a/b.py', line: 3, message: 'm',
    assignee: null, last_seen: { analysis_id: 40, run: 5, finished_at: '2026-10-06T00:10:20Z' }, last_detected_run: 2, last_detected_analysis_id: 20,
    last_detected_at: '2026-10-02T00:00:00Z', last_detected_detail_deleted: true, project_id: 3,
  };
  const run = defectRow(d, { detailBase: '/d/', tz: 'Asia/Seoul' }, doc).childNodes[6].childNodes[0];
  assert.equal(run.tagName, 'A', '링크(히스토리 비교 패널)는 유지');
  assert.match(run.dataset.tip, /Run 2의 상세는 삭제되었습니다/);
});

test('문자열 키가 모두 있다', () => {
  for (const k of ['runs.detail_deleted_title', 'runs.detail_deleted_body', 'runs.view_latest', 'runs.detail_deleted_short', 'runs.detail_deleted_option',
    'runs.detail_deleted_tip', 'history.cards_disabled', 'history.run_deleted_mark', 'dashboard.point_detail_deleted',
    'admin_runs.confirm_title', 'admin_runs.latest_not_deletable']) { // 오류 문장(error.*)은 서버 응답 message(src/Lang/ko.php)
    assert.ok(has(k), k);
  }
});
