// 결함 검색 영역의 "정한 뒤 검색"(P21V): 입력 변경은 요청 없음, 검색·Enter로만 요청, 칩은 선택만, 쪽 이동·머리글 정렬은
// 마지막 적용 조건으로 즉시 요청, 변경 표시, 초기화는 즉시 적용, 주소에는 적용된 조건만.
import test from 'node:test';
import assert from 'node:assert/strict';
import { createSearch, isDirty, normalizeDraft, draftOf } from '../../public/assets/js/lib/defect_search.js';
import { stateFromSearch, searchFromState, apiQuery } from '../../public/assets/js/lib/defect_filters.js';

function setup(search = '') {
  const calls = [];
  const s = createSearch(stateFromSearch(search), (applied, opts) => calls.push({ url: searchFromState(applied), api: apiQuery(applied, 50), ...opts }));
  return { s, calls };
}

test('입력 변경은 요청하지 않고 변경 표시만 켠다, 검색하면 한 번에 적용', () => {
  const { s, calls } = setup('?severity=high&sort=rule');
  assert.equal(s.dirty(), false);
  s.edit('q', '  undefined  ');
  s.toggle('severity', 'low');
  s.toggle('status', 'open');
  s.edit('rule', ['eslint:no-undef']);
  s.edit('assignee', ['none', '3']);
  s.edit('file', 'src/');
  s.edit('file_mode', 'contains');
  s.edit('from_run', '3');
  s.edit('order', 'desc');
  assert.equal(calls.length, 0, '입력만으로는 요청 없음');
  assert.equal(s.dirty(), true, '변경 표시');
  assert.deepEqual(s.applied.severity, ['high'], '적용 조건은 그대로');
  s.search();
  assert.equal(calls.length, 1, '검색 = 요청 한 번');
  assert.equal(s.dirty(), false, '검색하면 변경 표시가 꺼짐');
  assert.equal(calls[0].resetForm, true);
  const p = new URLSearchParams(calls[0].url);
  assert.deepEqual([p.get('q'), p.get('severity'), p.get('status'), p.get('rule'), p.get('assignee'), p.get('file'), p.get('file_mode'), p.get('from_run'), p.get('sort'), p.get('order'), p.get('page')],
    ['undefined', 'high,low', 'open', 'eslint:no-undef', 'none,3', 'src/', 'contains', '3', 'rule', 'desc', null]);
});

test('칩은 선택만: 다시 누르면 원래대로이고 변경 표시도 꺼진다', () => {
  const { s, calls } = setup('?status=open');
  s.toggle('status', 'open');
  assert.equal(s.dirty(), true);
  assert.deepEqual(s.draft.status, []);
  s.toggle('status', 'open');
  assert.equal(s.dirty(), false, '같은 조건으로 돌아오면 변경 아님');
  assert.equal(calls.length, 0);
});

test('쪽 이동·머리글 정렬은 마지막 적용 조건으로 즉시 요청하고, 고르던 입력은 남긴다', () => {
  const { s, calls } = setup('?severity=high&page=2');
  s.edit('q', '아직 검색 안 함');
  s.toggle('severity', 'low');
  s.page(3);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].resetForm, false, '폼은 그대로');
  assert.equal(new URLSearchParams(calls[0].url).get('q'), null, '검색하지 않은 검색어는 주소·요청에 없음');
  assert.equal(new URLSearchParams(calls[0].url).get('severity'), 'high');
  assert.equal(calls[0].api.page, 3);
  assert.equal(s.draft.q, '아직 검색 안 함', '입력은 남음');
  assert.equal(s.dirty(), true, '변경 표시도 남음');
  // 머리글 정렬: 다른 열 → 오름차순, 같은 열 → 뒤집기, 1쪽부터, 정렬·순서 입력칸 동기화
  s.sortBy('run');
  s.sortBy('run');
  assert.equal(calls.length, 3);
  assert.deepEqual([calls[1].api.sort, calls[1].api.order, calls[1].api.page], ['run', 'asc', 1]);
  assert.deepEqual([calls[2].api.sort, calls[2].api.order], ['run', 'desc']);
  assert.deepEqual([s.draft.sort, s.draft.order], ['run', 'desc'], '정렬·순서 입력칸 = 머리글 정렬');
  assert.equal(new URLSearchParams(calls[2].url).get('q'), null);
  assert.equal(s.draft.q, '아직 검색 안 함');
});

test('초기화는 즉시 적용(정렬·보는 Run 유지), 폼도 적용 조건으로', () => {
  const { s, calls } = setup('?severity=high&q=x&sort=rule&run=3&page=4');
  s.edit('file', 'zzz');
  s.reset();
  assert.equal(calls.length, 1);
  assert.equal(calls[0].resetForm, true);
  assert.equal(calls[0].url, '?sort=rule&run=3');
  assert.equal(s.draft.file, '', '고르던 입력도 지움');
  assert.equal(s.dirty(), false);
});

test('주소 복원·뒤로 가기: 적용 조건을 바꾸고 폼을 다시 채운다', () => {
  const { s, calls } = setup('');
  s.edit('q', 'abc');
  s.replace(stateFromSearch('?status=open&page=2'), { resetForm: true });
  assert.deepEqual([calls[0].url, s.draft.q, s.draft.status, s.dirty()], ['?status=open&page=2', '', ['open'], false]);
});

test('정리 규칙: 공백·틀린 Run 번호·파일 없는 파일 조건은 변경으로 보지 않음', () => {
  const applied = stateFromSearch('?q=abc');
  assert.equal(isDirty(applied, { ...draftOf(applied), q: '  abc ' }), false);
  assert.equal(isDirty(applied, { ...draftOf(applied), from_run: '0' }), false, '틀린 Run 번호는 빈 값');
  assert.equal(isDirty(applied, { ...draftOf(applied), file_mode: 'contains' }), false, '파일 없으면 파일 조건은 의미 없음');
  assert.equal(isDirty(applied, { ...draftOf(applied), rule: [] }), false);
  assert.deepEqual(normalizeDraft({ ...draftOf(applied), in_run_to: ' 7 ' }).in_run_to, '7');
  assert.throws(() => createSearch(applied, () => {}).edit('page', 2), '검색 영역 밖 키');
});
