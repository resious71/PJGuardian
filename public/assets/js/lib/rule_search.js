// 규칙 설명 목록의 검색 상태(P22, DOM 없음 — tests/js/rules.test.mjs). 결함 검색(P21V)과 같은 규칙:
// 고르는 값(draft)과 적용된 값(applied)을 나누고, 주소에는 적용된 조건만 넣는다. 잘못된 값은 무시(기본값).
// X08: 언어 탭(language = python | javascript)이 도구 조건을 대신한다. 탭은 고르는 즉시 적용되고(변경 표시 대상 아님), 탭을 바꾸면 조건은 기본으로.
// P22B: "기본 활성"(default_enabled) 조건 → "분석 적용"(analysis = 1 | 0, 관리자 변경 반영)과 "관리자 변경"(overridden = 1).
//       예전 주소의 default_enabled=는 analysis로 읽는다(주소는 다음 적용 때 analysis=로 바뀐다).
// P22C: 규칙 키 접두사(prefix — 키가 그 값으로 시작, 키에 쓰는 글자만 64자). 잘못된 값은 무시.
import { LANGUAGES, toolOf, languageOf } from './tool_language.js';

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
export const SORTS = ['rule', 'name', 'severity', 'tool'];
export const CHANGES = ['new', 'changed', 'unchanged'];
export const DEFAULT_LANGUAGE = 'python';
const YES_NO = ['1', '0'];
/** 고르는 조건(탭·쪽 번호 제외) */
export const KEYS = ['q', 'prefix', 'category', 'severity', 'analysis', 'overridden', 'fixable', 'deprecated', 'change', 'sort', 'order'];
export const PREFIX_PATTERN = /^[A-Za-z0-9@/_.-]{1,64}$/;

export function emptyState(language = DEFAULT_LANGUAGE) {
  return { language, q: '', prefix: '', category: '', severity: [], analysis: '', overridden: '', fixable: '', deprecated: '', change: '', sort: 'rule', order: 'asc', page: 1 };
}

/**
 * 탭 정하기: (1) 주소의 language (2) 예전 주소의 tool=ruff|eslint(language와 다르면 language가 이김) (3) 이 브라우저의 마지막 탭 (4) Python.
 * rewrite: 주소를 language= 로 고쳐 써야 하는가(language가 없었거나 tool=이 있었음)
 */
export function resolveLanguage(search, stored = null) {
  const p = new URLSearchParams(search);
  const fromUrl = LANGUAGES.includes(p.get('language')) ? p.get('language') : null;
  const fromTool = languageOf(p.get('tool'));
  const remembered = LANGUAGES.includes(stored) ? stored : null;
  const language = fromUrl ?? fromTool ?? remembered ?? DEFAULT_LANGUAGE;
  return { language, rewrite: fromUrl === null || p.has('tool') };
}

/** 주소 → 상태(잘못된 값은 무시). 탭은 resolveLanguage로 정한 값 */
export function stateFromSearch(search, stored = null) {
  const p = new URLSearchParams(search);
  const s = emptyState(resolveLanguage(search, stored).language);
  const q = (p.get('q') ?? '').trim();
  if (q.length <= 100) s.q = q;
  const prefix = (p.get('prefix') ?? '').trim();
  if (PREFIX_PATTERN.test(prefix)) s.prefix = prefix;
  const cat = (p.get('category') ?? '').trim();
  if (cat.length <= 60 && /^[\w .-]*$/.test(cat)) s.category = cat;
  s.severity = [...new Set((p.get('severity') ?? '').split(',').filter((v) => SEVERITIES.includes(v)))];
  for (const k of ['analysis', 'fixable', 'deprecated']) if (YES_NO.includes(p.get(k))) s[k] = p.get(k);
  if (!p.has('analysis') && YES_NO.includes(p.get('default_enabled'))) s.analysis = p.get('default_enabled');
  if (p.get('overridden') === '1') s.overridden = '1';
  if (CHANGES.includes(p.get('change'))) s.change = p.get('change');
  if (SORTS.includes(p.get('sort'))) s.sort = p.get('sort');
  if (p.get('order') === 'desc') s.order = 'desc';
  const page = Number(p.get('page'));
  if (Number.isInteger(page) && page >= 1 && page <= 100000) s.page = page;
  return s;
}

/** 상태 → 주소 쿼리(언어는 늘 넣고, 나머지는 기본값이면 뺀다. ? 포함) */
export function searchFromState(s) {
  const p = new URLSearchParams();
  p.set('language', s.language);
  const d = emptyState(s.language);
  for (const k of KEYS) {
    const v = Array.isArray(s[k]) ? s[k].join(',') : s[k];
    const dv = Array.isArray(d[k]) ? d[k].join(',') : d[k];
    if (v !== dv && v !== '') p.set(k, v);
  }
  if (s.page > 1) p.set('page', String(s.page));
  return `?${p.toString()}`;
}

/** API 쿼리(쪽 크기 포함). 탭 → tool */
export function apiQuery(s, size) {
  const out = { page: String(s.page), size: String(size), tool: toolOf(s.language) };
  for (const k of KEYS) {
    const v = Array.isArray(s[k]) ? s[k].join(',') : s[k];
    if (v !== '') out[k] = v;
  }
  return out;
}

/** 고른 조건이 적용된 조건과 다른가(탭·쪽 번호 제외) */
export function isDirty(draft, applied) {
  return KEYS.some((k) => (Array.isArray(draft[k]) ? [...draft[k]].sort().join(',') !== [...applied[k]].sort().join(',') : draft[k] !== applied[k]));
}

/** 조건이 하나라도 있는가(탭·정렬 제외) */
export function isFiltered(s) {
  return ['q', 'prefix', 'category', 'analysis', 'overridden', 'fixable', 'deprecated', 'change'].some((k) => s[k] !== '') || s.severity.length > 0;
}

export function toggle(list, value) {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}

/** 일괄 변경의 "검색 결과 전체" 대상 조건(P22C): 적용된 조건 그대로(쪽·크기·정렬 없음, 탭 = language) */
export function bulkFilter(s) {
  const out = { language: s.language };
  for (const k of KEYS) {
    if (k === 'sort' || k === 'order') continue;
    const v = Array.isArray(s[k]) ? s[k].join(',') : s[k];
    if (v !== '') out[k] = v;
  }
  return out;
}

/** 상세의 "목록으로": 그 탭의 마지막 목록 조건(없으면 탭 기본 목록) */
export function backToListSearch(language, saved) {
  if (typeof saved === 'string' && saved.startsWith('?') && new URLSearchParams(saved).get('language') === language) return saved;
  return `?language=${language}`;
}
