// Run(분석 회차, P20R): 주소 쿼리 ↔ Run 번호, 선택기 항목 표기, 존재 구간 표기. DOM 없이 시험한다(tests/js/runs.test.mjs).
// 용어: 분석 = 과정(대기·실행 중·실패·취소), Run = 성공한 분석의 회차(프로젝트별 1부터). 사용자에게는 "Run N", 내부 id는 "분석 #id"로 함께.
import { t } from '../core/i18n.js';
import { formatDateTime, formatShortDate } from '../core/time.js';

const RUN = /^[1-9][0-9]{0,8}$/;

/** location.search → Run 번호 문자열(없거나 형식이 틀리면 ''). 틀린 값은 서버가 422로 알려 주지만 화면은 최신 Run으로 둔다 */
export function runFromSearch(search) {
  const v = new URLSearchParams(search).get('run') ?? '';
  return RUN.test(v) ? v : '';
}

/** 상대 주소(경로 + 쿼리 + 해시)에 run을 넣거나(값이 있으면) 뺀다(''·최신). 다른 쿼리와 해시는 그대로, page는 1로 */
export function withRun(href, run, { latest = null } = {}) {
  const url = new URL(href, 'http://x');
  const v = run === null || run === undefined ? '' : String(run);
  if (v === '' || (latest !== null && v === String(latest))) url.searchParams.delete('run');
  else url.searchParams.set('run', v);
  url.searchParams.delete('page');
  const q = url.searchParams.toString();
  return `${url.pathname}${q ? `?${q}` : ''}${url.hash}`;
}

/** 결함 상세 링크 뒤에 붙일 쿼리('' 또는 '?run=N') */
export function runSuffix(run) {
  return RUN.test(String(run ?? '')) ? `?run=${run}` : '';
}

/**
 * 선택기 항목 글자: "Run 7 (최신) · 2026-10-04 14:02 · 결함 120건 · 신규 3 · 해결 2 · 버전/설정 변경"
 * @param {{run:number, finished_at:string, total:number, new:number, resolved:number, changed:boolean}} r
 */
export function runOptionLabel(r, { latest, tz }) {
  const parts = [t(r.run === latest ? 'runs.label_latest' : 'runs.label', { run: r.run })];
  if (r.finished_at) parts.push(formatDateTime(r.finished_at, tz));
  parts.push(t('runs.defects', { total: Number(r.total).toLocaleString('ko-KR') }));
  if (r.new || r.resolved) parts.push(t('runs.delta', { new: r.new ?? 0, resolved: r.resolved ?? 0 }));
  if (r.changed) parts.push(t('runs.changed'));
  return parts.join(' · ');
}

/** "Run 7" / 번호가 없으면(실패·취소·진행 중·되채움 전) "Run —" + 상태 */
export function runName(run, status = null) {
  if (Number.isInteger(run) && run > 0) return t('runs.label', { run });
  return status && status !== 'succeeded' ? t('runs.none_status', { status: t(`icon.analysis_status.${status}`) }) : t('runs.none');
}

/**
 * 존재 구간 → "Run 1~4, 6~현재". 한 Run뿐이면 "Run 3".
 * @param {{from:number, to:number|null}[]} intervals
 */
export function intervalsText(intervals) {
  if (!intervals?.length) return t('runs.intervals_none');
  const parts = intervals.map((i) => {
    if (i.to === null) return t('runs.interval_open', { from: i.from });
    if (i.to === i.from) return String(i.from);
    return `${i.from}~${i.to}`;
  });
  return t('runs.intervals', { list: parts.join(', ') });
}

/**
 * 결함 표 "Run"·"최근 감지" 열 값(P21U). 서버가 준 last_detected_*(과거 Run 보기면 이미 그 Run으로 한정)를 그대로 쓴다.
 *   run: 'Run 3' 또는 '—', href: 그 Run의 히스토리 비교 패널(번호가 없으면 null)
 *   date: 'yy-mm-dd'(표시 시간대), full: 툴팁용 전체 일시(초까지), capped: 과거 Run 보기에서 한정된 값인가(실제 마지막 감지가 더 뒤)
 * @param {{project_id:number, last_detected_run:number|null, last_detected_at:string|null, last_detected_analysis_id:number, last_seen:{run:number|null}}} d
 */
export function lastDetectedCells(d, { base = '', tz, viewRun = null } = {}) {
  const run = Number.isInteger(d.last_detected_run) && d.last_detected_run > 0 ? d.last_detected_run : null;
  let date = '—';
  let full = '';
  try {
    if (d.last_detected_at) {
      date = formatShortDate(d.last_detected_at, tz);
      full = formatDateTime(d.last_detected_at, tz, { seconds: true });
    }
  } catch {
    date = '—';
    full = '';
  }
  return {
    run: run === null ? '—' : t('runs.label', { run }),
    href: run === null ? null : `${base}/projects/${d.project_id}/history?analysis=${d.last_detected_analysis_id}`,
    date,
    full,
    capped: viewRun !== null && Number.isInteger(d.last_seen?.run) && d.last_seen.run > viewRun,
  };
}
