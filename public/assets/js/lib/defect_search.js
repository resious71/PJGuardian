// 결함 검색 영역의 "정한 뒤 검색"(P21V). DOM 없이 시험한다(tests/js/defect_search.test.mjs).
//   applied: 마지막으로 적용된 조건(주소·목록·요약 API·칩 숫자의 기준). draft: 폼에서 고르는 중인 조건.
//   검색 영역의 입력(검색어·파일·파일 조건·Run 필터·심각도·상태 칩·규칙·담당자·정렬·순서)은 draft만 바꾸고 요청하지 않는다.
//   검색(버튼·Enter)이 draft를 적용한다. 결과 표의 동작(쪽 이동·머리글 정렬)과 초기화는 바로 적용한다 — 쪽·머리글 정렬은
//   마지막 적용 조건에 그 변경만 더하고(아직 검색하지 않은 입력은 폼에 남김), 머리글 정렬은 정렬·순서 입력칸도 같은 값으로 맞춘다.
import { MULTI, clearFilters, toggle } from './defect_filters.js';

/** 검색 영역이 정하는 조건(주소의 analysis·change·run·page는 검색 영역 밖) */
export const DRAFT_KEYS = ['q', 'file', 'file_mode', 'from_run', 'in_run_from', 'in_run_to', 'severity', 'status', 'rule', 'assignee', 'sort', 'order'];
const RUN = /^[1-9][0-9]{0,8}$/;

/** 상태에서 검색 영역 조건만 복사 */
export function draftOf(state) {
  const d = {};
  for (const k of DRAFT_KEYS) d[k] = MULTI.includes(k) ? [...state[k]] : state[k];
  return d;
}

/** 입력값 정리: 앞뒤 공백, Run 번호 형식(틀리면 빈 값), 파일이 없으면 파일 조건은 기본값 */
export function normalizeDraft(d) {
  const out = draftOf(d);
  out.q = String(d.q ?? '').trim();
  out.file = String(d.file ?? '').trim();
  out.file_mode = out.file && d.file_mode === 'contains' ? 'contains' : 'prefix';
  for (const k of ['from_run', 'in_run_from', 'in_run_to']) {
    const v = String(d[k] ?? '').trim();
    out[k] = RUN.test(v) ? v : '';
  }
  return out;
}

const sameSet = (a, b) => a.length === b.length && a.every((v) => b.includes(v));

/** 적용된 조건과 고르는 중인 조건이 다른가(검색 버튼의 "조건이 변경되었습니다") */
export function isDirty(applied, draft) {
  const a = normalizeDraft(applied);
  const d = normalizeDraft(draft);
  return DRAFT_KEYS.some((k) => (MULTI.includes(k) ? !sameSet(a[k], d[k]) : a[k] !== d[k]));
}

/**
 * 검색 영역 컨트롤러. onApply(applied, { resetForm })는 적용할 때만 불린다(목록·요약 요청, 주소 반영).
 *   resetForm: 폼 전체를 적용된 조건으로 다시 채울지(검색·초기화·주소 이동). 쪽 이동·머리글 정렬은 false(고르던 입력을 남김).
 */
export function createSearch(initial, onApply) {
  let applied = { ...initial };
  let draft = draftOf(applied);
  const commit = (next, resetForm) => {
    applied = next;
    if (resetForm) draft = draftOf(applied);
    onApply(applied, { resetForm });
  };
  return {
    get applied() { return applied; },
    get draft() { return draft; },
    dirty: () => isDirty(applied, draft),
    /** 입력 하나(요청 없음) */
    edit(key, value) {
      if (!DRAFT_KEYS.includes(key)) throw new Error(`검색 영역 조건이 아님: ${key}`);
      draft = { ...draft, [key]: MULTI.includes(key) ? [...value] : value };
    },
    /** 칩 하나 켜고 끄기(요청 없음) */
    toggle(facet, value) {
      draft = { ...draft, [facet]: toggle(draft[facet], value) };
    },
    /** 검색: 고른 조건을 한꺼번에 적용(1쪽부터) */
    search() {
      commit({ ...applied, ...normalizeDraft(draft), page: 1 }, true);
    },
    /** 쪽 이동: 마지막 적용 조건 그대로 */
    page(n) {
      commit({ ...applied, page: n }, false);
    },
    /** 머리글 정렬: 다른 열이면 그 열 오름차순, 같은 열이면 순서 뒤집기. 정렬·순서 입력칸도 같은 값 */
    sortBy(key) {
      const change = key === applied.sort ? { order: applied.order === 'asc' ? 'desc' : 'asc' } : { sort: key, order: 'asc' };
      draft = { ...draft, sort: change.sort ?? applied.sort, order: change.order };
      commit({ ...applied, ...change, page: 1 }, false);
    },
    /** 초기화: 조건을 기본값으로 되돌리고 바로 적용(정렬·보는 Run은 유지, P17) */
    reset() {
      commit(clearFilters(applied), true);
    },
    /** 검색 영역 밖에서 바뀐 적용 조건(분석 필터 해제, 범위를 넘은 쪽 바로잡기, 뒤로 가기) */
    replace(next, { resetForm = false } = {}) {
      commit({ ...next }, resetForm);
    },
  };
}
