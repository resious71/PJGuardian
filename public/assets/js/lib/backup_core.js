// 백업·복원(P27B)의 순수 함수: 묶음 검증 상태, 요약 경고, 진행 문구, 복원 단계·결과, 설정 차이 기본 선택, 복원 요청 본문.
// DOM을 쓰지 않는다(Node 시험). 문구는 문자열만 만들고 화면은 textContent로 넣는다(경로·호스트 이름도 텍스트).
import { t as defaultT } from '../core/i18n.js';

export const RESTORE_STEPS = ['precheck', 'maintenance', 'pre_restore_backup', 'replace_db', 'replace_snapshots', 'migrate', 'finalize'];
export const FINAL_STATUSES = ['succeeded', 'failed', 'rolled_back', 'rollback_failed'];

/** 오류 코드 → 문구(backups.err.<code>, 없으면 코드 그대로). params는 문구 자리 채우기 */
export function errorMessage(code, params = {}, t = defaultT) {
  const c = String(code ?? '');
  if (c === '') return '';
  const key = `backups.err.${c}`;
  const text = t(key, params ?? {});
  return text !== key ? text : c;
}

/**
 * 목록 한 줄의 검증 상태 → { state, tone, icon, key }
 *   ok(검증 통과) | failed(검증 실패) | needs_verify(올린 묶음, 아직 검증 안 함 — 복원 불가) | unverified(옆 파일 없음 — 폴더에 직접 복사한 묶음)
 */
export function verifyState(item) {
  const v = item?.verified;
  if (v && v.ok === true) return { state: 'ok', tone: 'ok', icon: 'system.ok', key: 'backups.verify.ok' };
  if (v && v.ok === false) return { state: 'failed', tone: 'error', icon: 'system.error', key: 'backups.verify.failed' };
  if (item?.uploaded) return { state: 'needs_verify', tone: 'warn', icon: 'system.warn', key: 'backups.verify.needs_verify' };
  return { state: 'unverified', tone: 'neutral', icon: 'ui.info', key: 'backups.verify.unverified' };
}

/** 복원할 수 있는 묶음인가(검증 실패·올린 뒤 미검증은 불가) */
export function canRestore(item) {
  const s = verifyState(item).state;
  return s === 'ok' || s === 'unverified';
}

/** 지울 수 있는가(마지막 성공 백업은 보호) */
export function canDelete(item, lastSuccessFile) {
  return item?.name !== lastSuccessFile;
}

/**
 * 요약 경고 목록(색에만 의존하지 않게 아이콘 + 문구). 순서 = 중요도.
 * @returns {Array<{id: string, tone: 'error'|'warn'|'info', text: string}>}
 */
export function summaryWarnings(d, t = defaultT) {
  const out = [];
  if (!d) return out;
  if (d.dir_check && d.dir_check !== 'ok' && d.dir_check !== 'warn_same_volume') {
    out.push({ id: 'dir_invalid', tone: 'error', text: t('backups.warn.dir_invalid', { reason: t(`config_settings.path.${d.dir_check}`, { path: d.dir ?? '' }) }) });
  }
  if (d.last_failure && (!d.last_success || String(d.last_failure.at) > String(d.last_success.at))) {
    out.push({ id: 'last_failed', tone: 'error', text: t('backups.warn.last_failed', { reason: errorMessage(d.last_failure.code, d.last_failure.params, t) }) });
  }
  // P27E: 자동 백업이 꺼져 있으면 "없음·오래됨" 경고 대신 정보 한 줄(수동 백업은 언제든)
  if (d.schedule && d.schedule.enabled === false) out.push({ id: 'auto_off', tone: 'info', text: t('backups.warn.auto_off') });
  else if (!d.last_success) out.push({ id: 'none', tone: 'warn', text: t('backups.warn.none') });
  else if (d.stale) out.push({ id: 'stale', tone: 'warn', text: t('backups.warn.stale', { hours: d.schedule?.stale_hours ?? '' }) });
  if (d.rehearsal_due) out.push({ id: 'rehearsal_due', tone: 'warn', text: t('backups.warn.rehearsal_due') });
  if (d.maintenance_stale) out.push({ id: 'maintenance_stale', tone: 'error', text: t('backups.warn.maintenance_stale') });
  if (d.inside_project || d.dir_check === 'warn_same_volume') out.push({ id: 'same_volume', tone: 'warn', text: t('backups.warn.same_volume') });
  if (d.legacy_backups_dir) out.push({ id: 'legacy', tone: 'warn', text: t('backups.warn.legacy_dir', { dir: d.legacy_backups_dir }) });
  if (!d.worker_running) out.push({ id: 'worker', tone: 'warn', text: t('backups.warn.worker_off') });
  const est = d.schedule ? diskEstimate(summaryEstimateInput(d)) : null;
  if (est?.warn) out.push({ id: 'disk_estimate', tone: 'warn', text: estimateText(est, t) });
  return out;
}

/** 진행 중 작업(job) → 한 줄 문구. progress { step, done, total } */
export function jobText(job, t = defaultT) {
  if (!job) return '';
  if (job.cancelling) return t('backups.job.cancelling');
  if (!job.started) return t(job.type === 'rehearsal' ? 'backups.job.rehearsal_waiting' : 'backups.job.waiting');
  if (job.type === 'rehearsal') return t('backups.job.rehearsal_running');
  const p = job.progress;
  const step = p?.step ? t(`backups.job.step.${p.step}`) : t('backups.job.step.db');
  const pct = jobPercent(job);
  return pct === null ? t('backups.job.running', { step }) : t('backups.job.running_pct', { step, pct });
}

/** 진행률(%) — 스냅샷 복사 단계만 바이트로 셀 수 있다. 셀 수 없으면 null */
export function jobPercent(job) {
  const p = job?.progress;
  if (!p || !(p.total > 0)) return null;
  return Math.max(0, Math.min(100, Math.floor((p.done / p.total) * 100)));
}

/** 설정 차이 항목의 기본 체크: PC 고유 항목(machine)은 해제, 나머지는 선택 */
export function defaultChecked(diffItem) {
  return diffItem?.machine !== true;
}

/**
 * 복원 요청 본문. withConfig가 꺼져 있으면 설정 키를 보내지 않는다(설정 복원 기본 끔).
 *   checked: Set<string> — "<file>:<key>"
 */
export function restoreBody({ name, password, confirm, waitMode, withConfig, checked, diff, key }) {
  const config = [];
  const local = [];
  if (withConfig) {
    for (const d of diff ?? []) {
      if (!checked.has(`${d.file}:${d.key}`)) continue;
      (d.file === 'local' ? local : config).push(d.key);
    }
  }
  const body = { name, password, confirm, wait_mode: waitMode === 'cancel' ? 'cancel' : 'wait', config_keys: config, local_keys: local };
  if (key && key.content) body.key = { content: key.content, passphrase: key.passphrase };
  return body;
}

/** 건수 비교 행: [{ key, backup, current, delta }] — 표 순서 고정 */
export function countRows(counts, current) {
  return ['users', 'projects', 'analyses', 'defects'].map((k) => {
    const b = Number(counts?.[k] ?? 0);
    const c = current ? Number(current.counts?.[k] ?? 0) : null;
    return { key: k, backup: b, current: c, delta: c === null ? null : b - c };
  });
}

/** 이후 변경 손실 문구(loss 없으면 최신 분석 시각으로 대체 안내) */
export function lossText(pre, t = defaultT, num = (n) => Number(n).toLocaleString('ko-KR')) {
  const l = pre?.loss;
  if (!l) return t('backups.restore.loss_unknown');
  if (l.analyses === 0 && l.defect_changes === 0 && l.accounts === 0) return t('backups.restore.loss_none');
  return t('backups.restore.loss', { analyses: num(l.analyses), changes: num(l.defect_changes), accounts: num(l.accounts) });
}

/** 복원 상태 → { tone, icon, key } */
export function restoreStatusView(status) {
  switch (status) {
    case 'requested': return { tone: 'neutral', icon: 'tool_status.verifying', key: 'restore_status.status.requested' };
    case 'running': return { tone: 'warn', icon: 'tool_status.verifying', key: 'restore_status.status.running' };
    case 'succeeded': return { tone: 'ok', icon: 'system.ok', key: 'restore_status.status.succeeded' };
    case 'rolled_back': return { tone: 'warn', icon: 'system.warn', key: 'restore_status.status.rolled_back' };
    case 'failed': return { tone: 'error', icon: 'system.error', key: 'restore_status.status.failed' };
    case 'rollback_failed': return { tone: 'error', icon: 'ui.danger', key: 'restore_status.status.rollback_failed' };
    default: return { tone: 'neutral', icon: 'ui.info', key: 'restore_status.status.none' };
  }
}

/** 단계 목록(아직 시작 안 한 단계는 pending) → [{ name, status }] */
export function stepRows(state) {
  const seen = new Map((state?.steps ?? []).map((s) => [s.name, s]));
  return RESTORE_STEPS.map((name) => ({ name, status: seen.get(name)?.status ?? 'pending', at: seen.get(name)?.at ?? null }));
}

/** 끝났는가(폴링 멈춤) */
export function isFinal(state) {
  return FINAL_STATUSES.includes(state?.status) && !state?.maintenance;
}

/** 묶음 이름 확인(서버 규칙과 같음): pjguardian-YYYYMMDD-HHMMSS-<종류>[-n].pjgbak */
export function validBundleName(name) {
  return /^pjguardian-\d{8}-\d{6}-(daily|manual|pre_restore|pre_upgrade)(-\d{1,3})?\.pjgbak$/.test(String(name ?? ''));
}

// ---------------------------------------------------------------- 자동 백업 주기(P27E)

const PERIOD_DAYS = { daily: 1, weekly: 7, monthly: 30.44 };

/** 주기 문구: "매일 02:30" · "매주 일요일 02:30" · "매월 1일 02:30" · "매월 말일 02:30" */
export function scheduleText(s, t = defaultT) {
  if (!s) return '';
  if (s.mode === 'weekly') return t('backups.sched.weekly', { day: t(`backups.sched.day.${s.weekly_day}`), at: s.at });
  if (s.mode === 'monthly') {
    return s.monthly_day === 'last' ? t('backups.sched.monthly_last', { at: s.at }) : t('backups.sched.monthly', { day: s.monthly_day, at: s.at });
  }
  return t('backups.sched.daily', { at: s.at });
}

/**
 * 디스크 영향 추정: 마지막 백업 크기 × 보관 기간 안에 쌓일 자동 백업 개수(최소 보관 개수 이상, 상한이 있으면 상한까지).
 * 예상이 백업 폴더 여유의 50%를 넘으면 warn(막지는 않음). 크기를 모르거나 꺼져 있으면 null.
 *   { enabled, mode, retentionDays, minCount, maxCount, lastSize, free }
 */
export function diskEstimate({ enabled, mode, retentionDays, minCount, maxCount, lastSize, free }) {
  if (!enabled || !(lastSize > 0) || !PERIOD_DAYS[mode]) return null;
  let count = Math.max(minCount || 0, Math.ceil((retentionDays || 0) / PERIOD_DAYS[mode]));
  if (maxCount > 0) count = Math.min(count, Math.max(maxCount, minCount || 0));
  const bytes = lastSize * count;
  return { count, bytes, warn: Number.isFinite(free) && free !== null && bytes > free * 0.5, free };
}

/** 추정 문구(크기는 MB·GB) */
export function estimateText(est, t = defaultT) {
  if (!est) return '';
  const size = formatSize(est.bytes);
  return est.warn ? t('backups.estimate.warn', { n: est.count, size, free: formatSize(est.free) }) : t('backups.estimate.ok', { n: est.count, size });
}

function formatSize(n) {
  const v = Number(n) || 0;
  if (v >= 1024 ** 3) return `${(v / 1024 ** 3).toFixed(1)}GB`;
  return `${(v / 1024 ** 2).toFixed(1)}MB`;
}

/** 요약 API 자료 → 추정 입력 */
export function summaryEstimateInput(d) {
  const s = d.schedule ?? {};
  return { enabled: s.enabled, mode: s.mode, retentionDays: d.retention_days, minCount: s.retention_min_count, maxCount: s.retention_max_count,
    lastSize: d.last_success?.size ?? null, free: d.dir_free_bytes ?? null };
}
