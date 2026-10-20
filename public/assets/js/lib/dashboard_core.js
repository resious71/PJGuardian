// 대시보드 화면 계산(P20, 명세 9). DOM·Chart.js를 쓰지 않는 순수 함수라 node --test로 시험한다(tests/js/dashboard.test.mjs).
// 차트 라벨·표 값은 원래 문자열 그대로 돌려준다(HTML로 해석하지 않음): 차트는 캔버스에 글자로 그리고, 표는 textContent로 넣는다.

export const SEVERITIES = ['critical', 'high', 'medium', 'low', 'not_defined'];
export const STATUSES = ['open', 'in_progress', 'postponed', 'intentional', 'not_fix', 'resolved'];
/** 심각도별 선·점 모양(색에만 의존하지 않음). rectRot(마름모)는 "버전/설정 변경" 표식 전용 */
export const SEVERITY_POINT = { critical: 'triangle', high: 'rect', medium: 'circle', low: 'crossRot', not_defined: 'star' };
export const SEVERITY_DASH = { critical: [], high: [8, 4], medium: [2, 3], low: [12, 4, 2, 4], not_defined: [1, 6] };

/** 추이 점의 이름: Run 번호가 있으면 "Run N"(P20R), 없으면(되채움 전) 종료 시각 */
export function pointLabel(p, formatTime = (iso) => iso, runLabel = (n) => `Run ${n}`) {
  return Number.isInteger(p.run) ? runLabel(p.run) : formatTime(p.finished_at);
}

/** 추이 점을 누르면 갈 곳: Run이 있으면 그 Run 시점의 결함 목록(?run=N), 없으면 분석 상세 */
export function pointHref(base, projectId, p) {
  if (p?.detail_deleted) return null; // P21B: 상세가 삭제된 Run은 이동 대신 안내
  return Number.isInteger(p?.run) ? `${base}/projects/${projectId}?run=${p.run}` : `${base}/analyses/${p?.analysis_id}`;
}

/**
 * 추이 차트 데이터(프로젝트 하나). 값은 Run 시점 기준(이후 수동 상태 변경으로 다시 계산하지 않음).
 * @returns {{labels: string[], total: number[], severity: Record<string, number[]>, markers: (number|null)[], analysisIds: number[], runs: (number|null)[]}}
 */
export function trendData(series, formatTime = (iso) => iso, runLabel = undefined) {
  const points = series?.points ?? [];
  const severity = Object.fromEntries(SEVERITIES.map((s) => [s, points.map((p) => p.by_severity?.[s] ?? 0)]));
  return {
    labels: points.map((p) => pointLabel(p, formatTime, runLabel)),
    times: points.map((p) => formatTime(p.finished_at)),
    total: points.map((p) => p.total),
    severity,
    // 변경 표식: 바뀐 지점에만 값(총 건수 위치), 나머지는 null(점 없음)
    markers: points.map((p) => (p.changed ? p.total : null)),
    analysisIds: points.map((p) => p.analysis_id),
    runs: points.map((p) => (Number.isInteger(p.run) ? p.run : null)),
  };
}

/** 표식 설명 줄: [{field, from, to}] (field는 문자열 리소스 키 접미사) */
export function changeLines(point) {
  return Object.entries(point?.changes ?? {}).map(([field, v]) => ({ field, from: v.from, to: v.to }));
}

/** 추이 표(표로 보기): 머리글 키와 행(문자열·숫자) */
export function trendTable(series, formatTime = (iso) => iso) {
  return {
    head: ['run', 'finished_at', 'total', ...SEVERITIES, 'tool_version', 'changed'],
    rows: (series?.points ?? []).map((p) => [
      Number.isInteger(p.run) ? p.run : null, formatTime(p.finished_at), p.total, ...SEVERITIES.map((s) => p.by_severity?.[s] ?? 0),
      `${p.tool_name ?? ''} ${p.tool_version ?? ''}`.trim(), p.changed ? 1 : 0,
    ]),
    analysisIds: (series?.points ?? []).map((p) => p.analysis_id),
  };
}

/** 긴 경로는 끝부분을 남기고 앞을 줄인다(파일 이름이 보이게). 전체 경로는 title·표에서 */
export function shortenPath(path, max = 48) {
  const s = String(path ?? '');
  if (s.length <= max) return s;
  const tail = s.slice(-(max - 1));
  const cut = tail.indexOf('/');
  return `…${cut > 0 && cut < tail.length - 1 ? tail.slice(cut) : tail}`;
}

/** 상위 10 막대 데이터. rules: 라벨 = 규칙 키(도구 접두사 포함), files: 라벨 = (프로젝트 이름이 여럿이면 "이름 · ")경로 끝부분 */
export function barData(items, kind, { multiProject = false, max = 48 } = {}) {
  const list = items ?? [];
  return {
    labels: list.map((it) => (kind === 'rules' ? String(it.rule_key) : `${multiProject ? `${it.project_name} · ` : ''}${shortenPath(it.file_path, max)}`)),
    fullLabels: list.map((it) => (kind === 'rules' ? String(it.rule_key) : `${it.project_name} · ${it.file_path}`)),
    counts: list.map((it) => it.count),
    severity: Object.fromEntries(SEVERITIES.map((s) => [s, list.map((it) => it.by_severity?.[s] ?? 0)])),
    links: list.map((it) => it.link),
  };
}

/** 차트 요약 문장(aria-label)에 넣을 값: 상위 3개 */
export function topSummary(items, kind) {
  return (items ?? []).slice(0, 3).map((it) => `${kind === 'rules' ? it.rule_key : it.file_path} ${it.count}`).join(', ');
}

/**
 * 대시보드 빈 상태(요약 응답으로 판정). null이면 빈 상태 아님.
 *   no_projects: 프로젝트가 하나도 없음(admin) / no_access: 볼 수 있는 프로젝트 없음(일반 사용자)
 *   no_analysis: 보이는 프로젝트는 있으나 성공 분석이 없음 / no_defects: 분석은 있으나 결함 0건
 */
export function emptyState(summary, isAdmin) {
  if (!summary) return null;
  if (summary.projects.visible === 0) return isAdmin ? 'no_projects' : 'no_access';
  if (summary.projects.with_analysis === 0) return 'no_analysis';
  const all = Object.values(summary.by_status ?? {}).reduce((a, b) => a + b, 0);
  if (all === 0) return 'no_defects';
  return null;
}

/** 요약 타일 값: null(모름·해당 없음)과 0을 구분해 표시 문자열을 만든다 */
export function count(value) {
  return value === null || value === undefined ? '—' : String(value);
}

/** 프로젝트 결함 탭 링크(앱 경로 접두사 + 서버가 준 상대 경로). 서버 값이 앱 안 경로가 아니면 null */
export function appLink(base, link) {
  const s = String(link ?? '');
  if (!s.startsWith('/') || s.startsWith('//')) return null;
  return `${base}${s}`;
}

/** 언어·도구 표 행 */
export function toolRows(groups) {
  return (groups ?? []).map((g) => ({
    tool: g.tool, language: g.language, projects: g.projects, withAnalysis: g.projects_with_analysis, open: g.open,
    severity: SEVERITIES.map((s) => g.by_severity?.[s] ?? 0),
    versions: (g.versions ?? []).map((v) => `${v.version ?? '?'} (${v.projects})`).join(', '),
  }));
}

/** 언어 필터(P21C): 주소의 language가 python·javascript면 그 값, 아니면 ''(전체) */
export function languageFromSearch(search) {
  const v = new URLSearchParams(search).get('language');
  return v === 'python' || v === 'javascript' ? v : '';
}

/** 주소에 language를 넣거나(전체면) 뺀다. 다른 쿼리·해시는 그대로 */
export function withLanguage(href, language) {
  const u = new URL(href, 'http://x');
  if (language) u.searchParams.set('language', language); else u.searchParams.delete('language');
  return `${u.pathname}${u.search}${u.hash}`;
}
