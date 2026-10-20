// 시스템 모니터링 화면의 순수 함수(P30, tests/js/monitoring.test.mjs). 판정은 서버(SystemHealth)가 하고, 화면은 결과를 글자·아이콘으로 바꾸기만 한다.

/** 수준 → 아이콘(색만으로 구분하지 않음 — 아이콘 + 글자) */
export function levelIcon(level) {
  return level === 'fail' || level === 'block' || level === 'expired' ? 'system.error' : (level === 'warn' || level === 'expiring' || level === 'stale' ? 'system.warn' : 'system.ok');
}

/** 전체 상태 줄: {key, params, icon} */
export function statusLine(status, alerts) {
  const fail = (alerts ?? []).filter((a) => a.level === 'fail').length;
  const warn = (alerts ?? []).length - fail;
  if (status === 'fail' && warn > 0) return { key: 'monitoring.status.mixed', params: { fail, warn }, icon: 'system.error' };
  if (status === 'fail') return { key: 'monitoring.status.fail', params: { n: fail }, icon: 'system.error' };
  if (status === 'warn') return { key: 'monitoring.status.warn', params: { n: (alerts ?? []).length }, icon: 'system.warn' };
  return { key: 'monitoring.status.ok', params: {}, icon: 'system.ok' };
}

/** 경고 한 줄의 문구 키와 값 */
export function alertText(alert) {
  return { key: `monitoring.alert.${alert.code}`, params: alert.params ?? {} };
}

/** 카드가 "확인 불가"인가 → 이유 문구 키(아니면 null) */
export function unavailableReason(card) {
  if (!card || card.available !== false) return null;
  return `monitoring.reason.${['error', 'not_configured', 'unreadable', 'invalid', 'not_installed'].includes(card.reason) ? card.reason : 'error'}`;
}

/** 체크섬·스모크 결과(true/false/null) → 문구 키·아이콘 */
export function checkView(v) {
  if (v === true) return { key: 'monitoring.check.ok', icon: 'system.ok' };
  if (v === false) return { key: 'monitoring.check.fail', icon: 'system.error' };
  return { key: 'monitoring.check.unknown', icon: 'ui.info' };
}

/** 이 카드의 경고(같은 판정 — 카드 머리에 아이콘으로) */
export const CARD_ALERTS = {
  worker: ['worker_down'],
  queue: ['queue_stalled'],
  disk: ['disk_warn', 'disk_block'],
  backup: ['backup_failed', 'backup_stale'],
  tools: ['tool_unhealthy'],
  schedules: ['repo_fetch_failing'],
  cert: ['cert_expiring', 'cert_expired'],
};

/** 카드의 수준: 그 카드에 걸린 경고 중 가장 높은 것(없으면 ok, 확인 불가면 unknown) */
export function cardLevel(name, card, alerts) {
  if (unavailableReason(card)) return 'unknown';
  const mine = (alerts ?? []).filter((a) => (CARD_ALERTS[name] ?? []).includes(a.code));
  if (mine.some((a) => a.level === 'fail')) return 'fail';
  return mine.length ? 'warn' : 'ok';
}

/** 갱신할 때 포커스·스크롤을 지키기 위한 키: 포커스가 화면 안 요소면 그 data-focus-key */
export function focusKey(active, root) {
  if (!active || !root || !root.contains?.(active)) return null;
  return active.dataset?.focusKey ?? null;
}

/** X18: 카드 상태 배지 한 사전 — 정상·주의·오류·확인 불가(카드 수준) + 정보(앱 정보처럼 판정이 없는 카드). 아이콘 + 글자(색만으로 구분하지 않음) */
export const BADGES = {
  ok: { icon: 'system.ok', key: 'monitoring.badge.ok' },
  warn: { icon: 'system.warn', key: 'monitoring.badge.warn' },
  fail: { icon: 'system.error', key: 'monitoring.badge.fail' },
  unknown: { icon: 'system.unknown', key: 'monitoring.badge.unknown' },
  info: { icon: 'ui.info', key: 'monitoring.badge.info' },
};

/** 수준 → 배지 {state, icon, key}(알 수 없는 값은 확인 불가) */
export function badgeView(level) {
  const state = Object.hasOwn(BADGES, level) ? level : 'unknown';
  return { state, ...BADGES[state] };
}

/** X18: 카드 안 목록 표의 최대 행 수(모든 목록 카드 같은 규칙 — 넘으면 "외 N건") */
export const MAX_ROWS = 5;

/** 목록을 최대 n행으로: {shown, more} */
export function limitRows(items, n = MAX_ROWS) {
  const list = Array.isArray(items) ? items : [];
  return { shown: list.slice(0, n), more: Math.max(0, list.length - n) };
}
