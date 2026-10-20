// 히스토리 화면 계산(P21). DOM·Chart.js 없이 시험한다(tests/js/history.test.mjs).
// 용어: 성공 결과는 "Run N", 분석 과정·상태는 "분석". 실패·취소 행은 "Run —(실패)".
import { t } from '../core/i18n.js';

export const CHART_SIZES = [10, 20, 50];
export const SOURCES = { upload: 'source.upload', git: 'source.git', perforce: 'source.perforce' }; // 소스 출처 → 아이콘(한 곳, P25가 늘림)

/** 주소 → { analysis, page, n, hide } (틀린 값은 버림). hide = "변경 없음 숨기기"(P27, unchanged=hide, 기본 보이기) */
export function historyState(search) {
  const p = new URLSearchParams(search);
  const num = (v) => (/^[1-9][0-9]{0,17}$/.test(v ?? '') ? Number(v) : null);
  const n = Number(p.get('n'));
  return { analysis: num(p.get('analysis')), page: num(p.get('page')) ?? 1, n: CHART_SIZES.includes(n) ? n : 20, ...(p.get('unchanged') === 'hide' ? { hide: true } : {}) };
}

export function historySearch(s) {
  const p = new URLSearchParams();
  if (s.analysis) p.set('analysis', String(s.analysis));
  if (s.page > 1) p.set('page', String(s.page));
  if (s.n !== 20) p.set('n', String(s.n));
  if (s.hide) p.set('unchanged', 'hide');
  const q = p.toString();
  return q ? `?${q}` : '';
}

/**
 * 차트 데이터: 이력 행(최신순)에서 성공 행만 오래된 순으로. 해결은 아래(음수) 막대, 열린 결함은 선, 변경 지점 표식.
 * @param {Array<object>} rows
 */
export function historyChartData(rows) {
  const ok = rows.filter((r) => r.status === 'succeeded' && r.counts).slice().reverse();
  return {
    labels: ok.map((r) => (Number.isInteger(r.run) ? t('runs.label', { run: r.run }) : t('analyses.title_of', { id: r.analysis_id }))),
    newer: ok.map((r) => r.counts.new),
    resolved: ok.map((r) => -r.counts.resolved),
    reopened: ok.map((r) => r.counts.reopened),
    open: ok.map((r) => r.open_total),
    markers: ok.map((r) => (r.changed ? (r.open_total ?? 0) : null)),
    analysisIds: ok.map((r) => r.analysis_id),
  };
}

/** 표로 보기 행(차트와 같은 데이터) */
export function historyChartTable(rows) {
  const d = historyChartData(rows);
  return d.labels.map((label, i) => [label, d.newer[i], -d.resolved[i], d.reopened[i], d.open[i] ?? '—', d.markers[i] !== null ? t('history.changed_yes') : '—']);
}

/** 증감 표시: 방향(아이콘)과 글자(색 단독 금지) */
export function delta(n) {
  if (n === null || n === undefined) return { dir: null, text: '—' };
  if (n > 0) return { dir: 'up', text: `+${n.toLocaleString('ko-KR')}` };
  if (n < 0) return { dir: 'down', text: `−${Math.abs(n).toLocaleString('ko-KR')}` };
  return { dir: 'same', text: '0' };
}

/** 이력 행의 Run 이름. 변경 없음(P27)은 Run이 아니다: "Run — · 분석 #id"(변경 없음은 상태 배지가 말한다 — 표가 넓어지지 않게 짧게) */
export function rowName(r) {
  if (r.unchanged) return `${t('runs.none')} · ${t('analyses.title_of', { id: r.analysis_id })}`;
  if (Number.isInteger(r.run)) return t('runs.title_of', { run: r.run, id: r.analysis_id });
  return `${t('runs.none_status', { status: t(`icon.analysis_status.${r.status}`) })} · ${t('analyses.title_of', { id: r.analysis_id })}`;
}

/** 변경 근거 줄: "도구 버전: 0.16.10 → 0.17.0" */
export function changeLines(changes) {
  return Object.entries(changes ?? {}).map(([field, v]) => t('history.change_line', { field: t(`history.field.${field}`), from: v.from ?? '—', to: v.to ?? '—' }));
}

/** 빈·특수 상태: 'no_analysis' | 'not_finished' | 'failed_only' | 'single' | null */
export function emptyKind(rows, total) {
  if (total === 0) return 'no_analysis';
  if (!rows.some((r) => r.status === 'succeeded')) return rows.some((r) => r.status === 'queued' || r.status === 'running') ? 'not_finished' : 'failed_only';
  if (rows.filter((r) => r.status === 'succeeded').length === 1 && total === 1) return 'single';
  return null;
}

/** 비교가 "변화 없음"인가(신규·해결·재오픈 모두 0) */
export function noChange(cmp) {
  return cmp.counts.new === 0 && cmp.counts.resolved === 0 && cmp.counts.reopened === 0;
}

// ---- 성공(변경 없음, P27) ----

/** 상태 배지 이름: 변경 없음이면 analysis_status.unchanged(상태 값은 succeeded 그대로) */
export function statusBadgeName(r) {
  return r?.unchanged ? 'analysis_status.unchanged' : `analysis_status.${r?.status}`;
}

/** "2026-10-08 19:05" → "26-10-08 19:05"(이력 접기 표시의 yy-mm-dd HH:mm) */
export function shortDateTime(text) {
  return /^\d{4}-\d{2}-\d{2} /.test(text ?? '') ? text.slice(2) : (text ?? '—');
}

/**
 * 같은 리비전 연속 확인을 한 줄로: "3회 확인 · 마지막 확인 26-10-08 19:05" (1회면 "마지막 확인 …"만)
 * @param {object} r 이력·분석 행(unchanged_check_count, unchanged_last_checked_at)
 * @param {(iso: string) => string} fmt 시각 → "yyyy-mm-dd HH:mm"(KST)
 */
export function unchangedFold(r, fmt) {
  const at = shortDateTime(r.unchanged_last_checked_at ? fmt(r.unchanged_last_checked_at) : '—');
  const n = Number(r.unchanged_check_count ?? 1);
  return n > 1 ? t('unchanged.fold', { n: n.toLocaleString('ko-KR'), at }) : t('unchanged.fold_one', { at });
}

/** 짧은 리비전: Git 7자리, Perforce @번호 */
export function shortRevision(kind, rev) {
  if (!rev) return '—';
  return kind === 'git' && /^[0-9a-f]{7,64}$/.test(rev) ? rev.slice(0, 7) : `@${rev}`;
}

/** 상세 문장: "변경 없음 — 리비전 abcdef0은 Run 3(26-10-01 09:00)에서 이미 분석했습니다" */
export function unchangedBaseText(kind, rev, base, fmt) {
  const r = shortRevision(kind, rev);
  if (!base) return t('unchanged.base_unknown', { rev: r });
  const at = shortDateTime(base.finished_at ? fmt(base.finished_at) : '—');
  return Number.isInteger(base.run)
    ? t('unchanged.base_run', { rev: r, run: base.run, at })
    : t('unchanged.base_analysis', { rev: r, id: base.analysis_id, at });
}

/** 이력 표의 짧은 기준 링크 글: "기준 Run 2" / "기준 분석 #12"(전체 문장은 툴팁·상세) */
export function unchangedBaseShort(base) {
  if (!base) return null;
  return Number.isInteger(base.run) ? t('unchanged.base_short_run', { run: base.run }) : t('unchanged.base_short_analysis', { id: base.analysis_id });
}

// ---- 결함 전체 이력(타임라인) ----

/** Run 표기: "Run 3" / "Run 3 이후"(Run 사이 수동 변경) / "—" */
export function timelineRun(item) {
  if (!Number.isInteger(item.run)) return '—';
  const text = t(item.run_after ? 'history.tl_run_after' : 'runs.label', { run: item.run });
  return item.run_detail_deleted ? `${text}(${t('runs.detail_deleted_short')})` : text; // P21B: 이력 행은 그대로, 표시만
}

/** 한 줄 요약: "Run 1에서 발생 → Run 2에서 사라짐 → Run 3에서 다시 나타남 → 현재 존재" */
export function timelineSummary(s) {
  const parts = [];
  (s.intervals ?? []).forEach((i, k) => {
    parts.push(t(k === 0 ? 'history.tl_sum_appeared' : 'history.tl_sum_again', { run: i.from }));
    if (i.to !== null) parts.push(t('history.tl_sum_gone', { run: i.to + 1 }));
  });
  if (parts.length === 0 && Number.isInteger(s.first_run)) parts.push(t('history.tl_sum_appeared', { run: s.first_run }));
  parts.push(t(s.exists_now ? 'history.tl_sum_now' : 'history.tl_sum_not_now'));
  return parts.join(' → ');
}
