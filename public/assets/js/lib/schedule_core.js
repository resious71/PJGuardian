// 주기 분석(P26)의 순수 함수: 설정 폼 검증, 저장 본문, 같은 시각 예약 안내, 설정을 막는 이유(연결 없음 등),
// 상태 한 줄(사용 중 · N일마다 · 다음 예정), 연속 실패 경고 문장. DOM을 쓰지 않는다(Node 시험).
import { t as defaultT } from '../core/i18n.js';

export const RECOMMENDED_DAYS = 7;

/** "HH:MM"(00:00~23:59, 24시간제) */
export function parseRunTime(value) {
  const m = /^([01][0-9]|2[0-3]):([0-5][0-9])$/.exec(String(value ?? '').trim());
  return m ? `${m[1]}:${m[2]}` : null;
}

/** "3:5", "0300", "3" 같은 입력을 "HH:MM"으로 고친다(고칠 수 없으면 원래 값) */
export function normalizeRunTime(value) {
  const v = String(value ?? '').trim();
  let m = /^(\d{1,2}):(\d{1,2})$/.exec(v);
  if (!m) m = /^(\d{2})(\d{2})$/.exec(v);
  if (!m && /^\d{1,2}$/.test(v)) m = [v, v, '0'];
  if (!m) return v;
  const h = Number(m[1]);
  const mi = Number(m[2]);
  if (h > 23 || mi > 59) return v;
  return `${String(h).padStart(2, '0')}:${String(mi).padStart(2, '0')}`;
}

/**
 * 폼 값 검증 → { ok, errors: {field: 메시지 키} }. 끄는 경우에도 간격·시각은 형식이 맞아야 저장한다(서버와 같음).
 * @param {{enabled: boolean, interval_days: string|number, run_time: string}} form
 */
export function validate(form, { min = 1, max = 365 } = {}) {
  const errors = {};
  const days = Number(String(form.interval_days ?? '').trim());
  if (String(form.interval_days ?? '').trim() === '' || !Number.isInteger(days) || days < min || days > max) {
    errors.interval_days = 'schedule.invalid.interval_days';
  }
  if (!parseRunTime(form.run_time)) errors.run_time = 'schedule.invalid.run_time';
  return { ok: Object.keys(errors).length === 0, errors };
}

/** 폼 → PUT 본문 */
export function buildBody(form, revision) {
  const body = { enabled: Boolean(form.enabled), interval_days: Number(String(form.interval_days).trim()), run_time: parseRunTime(form.run_time) ?? String(form.run_time ?? '') };
  if (Number.isInteger(revision)) body.revision = revision;
  return body;
}

/** 같은 시각에 이미 예약된 다른 프로젝트 수 안내(0이면 null) */
export function sameTimeNote(timeCounts, runTime, t = defaultT) {
  const time = parseRunTime(runTime);
  const n = time ? Number(timeCounts?.[time] ?? 0) : 0;
  return n > 0 ? t('schedule.same_time', { time, n: n.toLocaleString('ko-KR') }) : null;
}

/** 주기를 켤 수 없는 이유(서버 view.blocker) → 문구 키. 없으면 null */
export function blockerKey(blocker) {
  if (!blocker) return null;
  return ['repo_not_configured', 'repo_host_not_allowed', 'repo_secret_unreadable'].includes(blocker)
    ? `schedule.blocker.${blocker}` : 'schedule.blocker.other';
}

/** 저장 뒤 경고(connection_error, connection_unchecked) → 문구 키 목록 */
export function warningKeys(warnings) {
  return (Array.isArray(warnings) ? warnings : []).filter((w) => ['connection_error', 'connection_unchecked'].includes(w)).map((w) => `schedule.warn.${w}`);
}

/**
 * 상태 한 줄(프로젝트 화면·목록). summary: { enabled, interval_days, next_run_at } — 시각 글자는 fmt(iso)로.
 * @returns {string}
 */
export function statusLine(summary, fmt, t = defaultT) {
  if (!summary?.enabled) return t('schedule.off');
  const next = summary.next_run_at ? fmt(summary.next_run_at) : '—';
  return t('schedule.on_line', { days: summary.interval_days ?? '?', next });
}

/** 연속 실패 경고 문장(admin 요약에만 있음). 실패가 없으면 null */
export function failureLine(summary, t = defaultT) {
  const n = Number(summary?.consecutive_failures ?? 0);
  if (!summary?.enabled || n <= 0) return null;
  return t('schedule.failing', { n: n.toLocaleString('ko-KR'), reason: summary.last_error_title ?? summary.last_error_code ?? '' });
}

/** 마지막 결과 글자 키(succeeded·failed·cancelled, 없으면 none) */
export function lastResultKey(result) {
  return ['succeeded', 'failed', 'cancelled'].includes(result) ? `schedule.result.${result}` : 'schedule.result.none';
}

/** 건너뜀 사유 → 문구 키(도래 결과 skipped_* 또는 연결·디스크 코드) */
export function skipReasonKey(reason) {
  const known = ['skipped_busy', 'repo_not_configured', 'repo_host_not_allowed', 'repo_secret_unreadable', 'disk_usage_blocked', 'insufficient_storage'];
  return known.includes(reason) ? `schedule.skip.${reason}` : 'schedule.skip.other';
}
