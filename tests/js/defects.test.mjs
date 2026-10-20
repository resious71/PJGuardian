// 결함 목록 필터 상태·코드 뷰어 계산(P17): node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  stateFromSearch, searchFromState, apiQuery, isFiltered, clearFilters, toggle, emptyState,
} from '../../public/assets/js/lib/defect_filters.js';
import { languageForPath, defectRange, displayLineNumber, noticesFor } from '../../public/assets/js/lib/code_view_core.js';
import { badge } from '../../public/assets/js/ui/badge.js';
import { t, has } from '../../public/assets/js/core/i18n.js';
import { FakeDocument } from './fake-dom.mjs';

test('필터: URL 쿼리 → 상태 → URL 쿼리 왕복, 기본값은 URL에 넣지 않음', () => {
  const s = stateFromSearch('?severity=high,low,bogus&status=open&rule=ruff:F401,syntax-error&assignee=none,3,x&file=src%2Fapp%2F&q=%25_%27&sort=severity&order=desc&page=3');
  assert.deepEqual(s.severity, ['high', 'low'], '모르는 값은 버림');
  assert.deepEqual(s.assignee, ['none', '3']);
  assert.deepEqual(s.rule, ['ruff:F401', 'syntax-error']);
  assert.equal(s.q, "%_'");
  assert.equal(s.page, 3);
  const back = stateFromSearch(searchFromState(s));
  assert.deepEqual(back, s, '왕복');
  assert.equal(searchFromState(emptyState()), '', '빈 상태 = 쿼리 없음');
  assert.equal(searchFromState({ ...emptyState(), file: 'a', file_mode: 'prefix' }), '?file=a', '기본 file_mode는 생략');
  assert.equal(stateFromSearch('?sort=id;drop&order=x&page=-2').sort, 'file', '허용하지 않는 정렬은 기본값');
  assert.equal(stateFromSearch('?page=-2').page, 1);
});

test('필터: API 쿼리, 필터 여부, 지우기, 토글', () => {
  const s = { ...emptyState(), severity: ['high'], status: ['open', 'resolved'], file: 'src/', q: 'x', page: 2 };
  assert.deepEqual(apiQuery(s, 50), { severity: 'high', status: 'open,resolved', file: 'src/', file_mode: 'prefix', q: 'x', sort: 'file', order: 'asc', page: 2, size: 50 });
  assert.deepEqual(apiQuery(s, null, false), { severity: 'high', status: 'open,resolved', file: 'src/', file_mode: 'prefix', q: 'x', sort: 'file', order: 'asc' }, '요약 API는 페이지 없음');
  assert.equal(isFiltered(s), true);
  assert.equal(isFiltered({ ...emptyState(), sort: 'rule', page: 4 }), false, '정렬·페이지는 필터가 아님');
  const cleared = clearFilters({ ...s, sort: 'status', order: 'desc' });
  assert.deepEqual(cleared, { ...emptyState(), sort: 'status', order: 'desc' }, '정렬은 유지');
  assert.deepEqual(toggle(['a', 'b'], 'a'), ['b']);
  assert.deepEqual(toggle(['a'], 'b'), ['a', 'b']);
});

test('코드 뷰어: 확장자 → 언어', () => {
  const cases = { 'a.py': 'python', 'x/y.PYI': 'python', 'a.js': 'javascript', 'a.mjs': 'javascript', 'a.cjs': 'javascript', 'a.jsx': 'jsx',
    'a.ts': 'typescript', 'a.mts': 'typescript', 'a.tsx': 'tsx', 'Makefile': null, 'a.txt': null, '': null };
  for (const [path, lang] of Object.entries(cases)) assert.equal(languageForPath(path), lang, path);
});

test('코드 뷰어: 결함 위치 → 문서 안 위치(창·발췌 기준), 범위 밖이면 null', () => {
  const lines = Array.from({ length: 11 }, (_, i) => `l${i + 5}`);
  const data = { start_line: 5, lines, highlight: { line: 10, column: 5, end_line: 10, end_column: 9 } };
  assert.deepEqual(defectRange(data), { fromLine: 6, toLine: 6, fromCol: 4, toCol: 8 });
  assert.deepEqual(defectRange({ ...data, highlight: { line: 10, column: null, end_line: null, end_column: null } }), { fromLine: 6, toLine: 6, fromCol: null, toCol: null });
  assert.deepEqual(defectRange({ ...data, highlight: { line: 3, column: 1, end_line: 7, end_column: 2 } }), { fromLine: 1, toLine: 3, fromCol: null, toCol: 1 }, '앞이 잘린 여러 줄 결함');
  assert.equal(defectRange({ ...data, highlight: { line: 50, column: 1, end_line: 50, end_column: 2 } }), null, '창 밖');
  assert.equal(defectRange({ ...data, lines: [] }), null);
  assert.equal(displayLineNumber(1, 5), 5);
  assert.equal(displayLineNumber(11, 5), 15);
});

test('코드 뷰어: 안내 문구(발췌본, 인코딩 대체, 일부만, 긴 줄)와 문자열 리소스', () => {
  const ex = noticesFor({ source: 'excerpt', excerpt: true, start_line: 1, end_line: 41, content_replaced: true, partial: true, truncated_lines: [] }, { excerptContext: 20 });
  assert.deepEqual(ex.map((n) => n.key), ['defects.code_excerpt', 'defects.code_replaced']);
  assert.match(t(ex[0].key, ex[0].params), /^발췌본입니다\(결함 앞뒤 20줄, 1~41행\)/);
  const part = noticesFor({ source: 'snapshot', excerpt: false, partial: true, start_line: 5, end_line: 15, total_lines: 300, truncated_lines: [9], max_line_length: 2000 }, { mode: 'full' });
  assert.deepEqual(part.map((n) => n.key), ['defects.code_partial_too_long', 'defects.code_truncated']);
  assert.deepEqual(noticesFor({ source: 'none', reason: 'binary' }), []);
  for (const key of ['defects.code_partial', 'defects.code_none_title', 'defects.history_empty', 'defects.count', 'defects.code_aria']) assert.equal(has(key), true, key);
  for (const r of ['snapshot_missing', 'excerpt_missing', 'file_missing', 'binary', 'file_too_large', 'load_failed']) assert.equal(has(`defects.code_none.${r}`), true, r);
  for (const r of ['not_detected', 'file_deleted', 'redetected', 'still_detected']) assert.equal(has(`defects.reason.${r}`), true, r);
  for (const f of ['assignee', 'severity', 'status']) assert.equal(has(`defects.history_field.${f}`), true, f);
});

test('배지: PHP partials/badge와 같은 클래스, 텍스트 노드', () => {
  const doc = new FakeDocument();
  const b = badge('severity.not_defined', doc);
  assert.equal(b.className, 'badge badge--severity-not-defined');
  assert.equal(b.dataset.badge, 'severity.not_defined');
  assert.match(b.textContent, /미지정/);
});

// ---- P18 결함 수정 ----
import { baseline, formValues, diffChanges, patchBody, conflictRows, bulkChanges, bulkItems, changedFields } from '../../public/assets/js/lib/defect_edit_core.js';

test('수정: 바뀐 필드만 PATCH 본문으로, 같으면 빈 객체, 빈 사유는 보내지 않음', () => {
  const base = baseline({ assignee: { id: 3 }, severity: 'low', status: 'open' });
  assert.deepEqual(base, { assignee: 3, severity: 'low', status: 'open' });
  assert.deepEqual(diffChanges(base, formValues({ assignee: '3', severity: 'low', status: 'open' })), {}, '같은 값');
  assert.deepEqual(diffChanges(base, formValues({ assignee: '', severity: 'high', status: 'open' })), { assignee_id: null, severity: 'high' }, '해제 + 심각도');
  assert.deepEqual(patchBody(4, { status: 'resolved' }, '  '), { version: 4, status: 'resolved' });
  assert.deepEqual(patchBody(4, { status: 'resolved' }, ' 이유 '), { version: 4, status: 'resolved', memo: '이유' });
});

test('수정: 충돌 비교 행은 세 필드 모두, 내가 바꾼 필드와 다른 사람이 바꾼 필드를 구분', () => {
  const base = { assignee: null, severity: 'not_defined', status: 'open' };
  const rows = conflictRows(base, { severity: 'high' }, { assignee: null, severity: 'not_defined', status: 'in_progress', version: 2 });
  assert.deepEqual(rows, [
    { field: 'assignee', edited: false, mine: null, latest: null, changedByOther: false },
    { field: 'severity', edited: true, mine: 'high', latest: 'not_defined', changedByOther: false },
    { field: 'status', edited: false, mine: null, latest: 'in_progress', changedByOther: true },
  ]);
});

test('수정: 충돌 뒤 다시 시도는 내가 바꾸려던 필드만 남긴다(다른 사람의 변경을 되돌리지 않음)', () => {
  assert.deepEqual(changedFields({ severity: 'high' }), ['severity']);
  assert.deepEqual(changedFields({ assignee_id: null, status: 'open' }), ['assignee', 'status']);
  // 최신(다른 사용자가 상태를 in_progress로) 기준으로 폼을 채우고 내 심각도만 남기면, 다시 저장할 변경은 심각도뿐
  const latest = baseline({ assignee: null, severity: 'not_defined', status: 'in_progress' });
  const form = { ...latest, severity: 'high' };
  assert.deepEqual(diffChanges(latest, form), { severity: 'high' });
});

test('일괄: 바꾸지 않음(keep)·미지정·값, 선택 → items', () => {
  assert.deepEqual(bulkChanges({ assignee: 'keep', severity: 'keep', status: 'keep' }), {});
  assert.deepEqual(bulkChanges({ assignee: '', severity: 'low', status: 'keep' }), { assignee_id: null, severity: 'low' });
  assert.deepEqual(bulkChanges({ assignee: '12', severity: 'keep', status: 'resolved' }), { assignee_id: 12, status: 'resolved' });
  assert.deepEqual(bulkItems(new Map([[5, 2], [9, 1]])), [{ id: 5, version: 2 }, { id: 9, version: 1 }]);
  for (const k of ['defects.bulk_result', 'defects.conflict_title', 'defects.conflict_retry', 'defects.no_access', 'defects.select_row']) assert.equal(has(k), true, k);
});
