// 결함 목록의 필터 상태 ↔ URL 쿼리 ↔ API 쿼리(P17, 명세 6.4). DOM 없이 시험한다(tests/js/defects.test.mjs).
// URL 쿼리 이름은 API와 같다: rule, severity, status, assignee(쉼표 구분 다중 선택), file, file_mode, q, sort, order, page.
// 기본값(file_mode=prefix, sort=file, order=asc, page=1)은 URL에 넣지 않는다(링크를 짧게).
// analysis + change(P19): 분석 결과 요약에서 온 "Run N(분석 #id)의 신규/해결/재오픈" 필터(둘이 함께 있을 때만).
// run(P20R): 과거 Run 시점 보기(없으면 최신 Run = 현재 결함 표). 필터가 아니라 "무엇을 보는지"라서 필터 지우기에도 남는다.

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
export const STATUSES = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
export const SORTS = ['file', 'rule', 'severity', 'status', 'run', 'last_seen'];
export const MULTI = ['rule', 'severity', 'status', 'assignee'];
export const CHANGES = ['new', 'resolved', 'reopened'];
const DEFAULTS = { file_mode: 'prefix', sort: 'file', order: 'asc' };

/** 빈 상태(필터 없음) */
export function emptyState() {
  return {
    rule: [], severity: [], status: [], assignee: [], file: '', file_mode: 'prefix', q: '', sort: 'file', order: 'asc', page: 1, analysis: '', change: '',
    run: '',
    from_run: '', in_run_from: '', in_run_to: '', // P21: Run N에서 새로 생김, Run N~M에 존재
  };
}

function uniq(list) {
  return [...new Set(list)];
}

/** location.search → 상태. 모르는 값은 버린다(서버가 400을 주기 전에 화면에서 정리) */
export function stateFromSearch(search) {
  const p = new URLSearchParams(search);
  const s = emptyState();
  const split = (name) => uniq((p.get(name) ?? '').split(',').map((v) => v.trim()).filter(Boolean));
  s.rule = split('rule');
  s.severity = split('severity').filter((v) => SEVERITIES.includes(v));
  s.status = split('status').filter((v) => STATUSES.includes(v));
  s.assignee = split('assignee').filter((v) => v === 'none' || /^[1-9][0-9]*$/.test(v));
  s.file = (p.get('file') ?? '').trim();
  s.file_mode = p.get('file_mode') === 'contains' ? 'contains' : 'prefix';
  s.q = (p.get('q') ?? '').trim();
  s.sort = SORTS.includes(p.get('sort')) ? p.get('sort') : 'file';
  s.order = p.get('order') === 'desc' ? 'desc' : 'asc';
  const page = Number.parseInt(p.get('page') ?? '1', 10);
  s.page = Number.isFinite(page) && page >= 1 ? page : 1;
  const analysis = p.get('analysis') ?? '';
  const change = p.get('change') ?? '';
  if (/^[1-9][0-9]*$/.test(analysis) && CHANGES.includes(change)) {
    s.analysis = analysis;
    s.change = change;
  }
  for (const k of ['from_run', 'in_run_from', 'in_run_to']) {
    const v = p.get(k) ?? '';
    if (/^[1-9][0-9]{0,8}$/.test(v)) s[k] = v;
  }
  const run = p.get('run') ?? '';
  if (/^[1-9][0-9]{0,8}$/.test(run)) s.run = run;
  return s;
}

/** 상태 → '?…'(기본값은 넣지 않음). 비어 있으면 '' */
export function searchFromState(s) {
  const p = new URLSearchParams();
  for (const name of MULTI) if (s[name].length) p.set(name, s[name].join(','));
  if (s.file) p.set('file', s.file);
  if (s.file && s.file_mode !== DEFAULTS.file_mode) p.set('file_mode', s.file_mode);
  if (s.q) p.set('q', s.q);
  if (s.sort !== DEFAULTS.sort) p.set('sort', s.sort);
  if (s.order !== DEFAULTS.order) p.set('order', s.order);
  if (s.page > 1) p.set('page', String(s.page));
  if (s.analysis && s.change) {
    p.set('analysis', s.analysis);
    p.set('change', s.change);
  }
  for (const k of ['from_run', 'in_run_from', 'in_run_to']) if (s[k]) p.set(k, s[k]);
  if (s.run) p.set('run', s.run);
  const text = p.toString();
  return text ? `?${text}` : '';
}

/** 상태 → API 쿼리 객체(createApi의 query). withPage가 false면 page/size를 빼고(요약 API) */
export function apiQuery(s, size = null, withPage = true) {
  const q = {};
  for (const name of MULTI) if (s[name].length) q[name] = s[name].join(',');
  if (s.file) {
    q.file = s.file;
    q.file_mode = s.file_mode;
  }
  if (s.q) q.q = s.q;
  if (s.analysis && s.change) {
    q.analysis = s.analysis;
    q.change = s.change;
  }
  for (const k of ['from_run', 'in_run_from', 'in_run_to']) if (s[k]) q[k] = s[k];
  if (s.run) q.run = s.run;
  q.sort = s.sort;
  q.order = s.order;
  if (withPage) {
    q.page = s.page;
    if (size) q.size = size;
  }
  return q;
}

/** 정렬·페이지 외의 필터가 하나라도 있는지(필터 결과 없음과 결함 0건을 구분) */
export function isFiltered(s) {
  return MULTI.some((n) => s[n].length > 0) || s.file !== '' || s.q !== '' || s.analysis !== '' || s.from_run !== '' || s.in_run_from !== '' || s.in_run_to !== '';
}

/** 필터 지우기: 정렬과 보는 Run은 유지하고 나머지와 페이지를 처음으로 */
export function clearFilters(s) {
  return { ...emptyState(), sort: s.sort, order: s.order, run: s.run };
}

/** 다중 선택 값 하나를 켜고 끈다(새 배열) */
export function toggle(list, value) {
  return list.includes(value) ? list.filter((v) => v !== value) : [...list, value];
}
