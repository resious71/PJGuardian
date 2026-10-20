// 설정 관리(P27A) 순수 로직: 변경 목록, 입력 형식 확인(서버가 다시 검사), 반영 상태, 검색, 표시 문장. DOM 없음(node --test).
import { t } from '../core/i18n.js';

const DURATION = /^(\d+)\s*(s|m|h|d|w)?$/i;
const UNIT = { s: 1, m: 60, h: 3600, d: 86400, w: 604800 };

/** "30m" → 1800(초). 형식이 아니면 null */
export function durationSeconds(text) {
  const m = DURATION.exec(String(text).trim());
  if (!m) return null;
  return Number(m[1]) * UNIT[(m[2] || 's').toLowerCase()];
}

/** P30: 감사 로그 보존 값이 90일(명세 기본)보다 짧은가 — 저장 전 한 번 더 확인 */
export const AUDIT_RETENTION_MIN_DAYS = 90;
export function auditRetentionTooShort(value) {
  const s = durationSeconds(value);
  return s !== null && s < AUDIT_RETENTION_MIN_DAYS * 86400;
}

/** 항목 이름·설명(문자열 리소스, 없으면 키) */
export function labelOf(item) {
  const k = `config_settings.key.${item.key}.label`;
  const v = t(k);
  return v === k ? item.key : v;
}
export function helpOf(item) {
  const k = `config_settings.key.${item.key}.help`;
  const v = t(k);
  return v === k ? '' : v;
}

/** 긴 값(P28D SAML 인증서 base64 등)은 안내 줄에서 앞 24자…끝 8자로 줄인다(전체는 title) */
export function shortValue(v) {
  const s = String(v);
  return s.length > 60 ? `${s.slice(0, 24)}…${s.slice(-8)}` : s;
}

/** 형식별 입력 안내 */
export function hintOf(item) {
  const k = `config_settings.hint.${item.type}`;
  const v = t(k);
  return v === k ? '' : v;
}

/** 범위 문장(없으면 '') */
export function rangeOf(item) {
  if (item.min !== null && item.max !== null) return t('config_settings.range', { min: item.min, max: item.max });
  if (item.min !== null) return t('config_settings.range_min', { min: item.min });
  return '';
}

/**
 * 보내기 전 형식·범위 확인(편의 — 서버가 다시 검사한다). 통과면 null, 아니면 오류 문장.
 * 기간은 초로, 정수·비율은 수로 비교한다.
 */
export function checkInput(item, text) {
  const v = String(text).trim();
  const bad = () => (rangeOf(item) ? t('config_settings.invalid_range', { range: rangeOf(item) }) : t('config_settings.invalid'));
  if (v === '') return bad();
  if (item.type === 'duration') {
    const s = durationSeconds(v);
    if (s === null) return bad();
    if (item.min !== null && s < durationSeconds(item.min)) return bad();
    if (item.max !== null && s > durationSeconds(item.max)) return bad();
    return null;
  }
  if (item.type === 'int' || item.type === 'percent') {
    if (!/^-?\d{1,12}$/.test(v)) return bad();
    const n = Number(v);
    if (item.min !== null && n < Number(item.min)) return bad();
    if (item.max !== null && n > Number(item.max)) return bad();
    return null;
  }
  if (item.type === 'enum') {
    return Array.isArray(item.values) && item.values.includes(v) ? null : t('config_settings.invalid');
  }
  if (item.type === 'bool') {
    return v === 'true' || v === 'false' ? null : t('config_settings.invalid');
  }
  if (item.type === 'time') {
    return /^([01]\d|2[0-3]):[0-5]\d$/.test(v) ? null : bad(); // P27B backup.daily_at(HH:MM, 표시 시간대)
  }
  if (item.type === 'datetime') {
    // P32B maintenance.notice_at(YYYY-MM-DD HH:MM, 표시 시간대) — 실제로 있는 날짜만
    const m = /^(\d{4})-(\d{2})-(\d{2}) ([01]\d|2[0-3]):([0-5]\d)$/.exec(v);
    if (!m) return bad();
    const d = new Date(Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])));
    return d.getUTCFullYear() === Number(m[1]) && d.getUTCMonth() === Number(m[2]) - 1 && d.getUTCDate() === Number(m[3]) ? null : bad();
  }
  if (item.type === 'path') {
    return v.length > 200 || /["\u0000-\u001f\u007f]/.test(v) ? t('config_settings.path.fail_too_long') : null;
  }
  return null;
}

/** 같은 값인지(표기만 다른 기간 "60s"·"1m"은 같은 값) */
export function sameValue(item, a, b) {
  if (item.type === 'duration') {
    const x = durationSeconds(a);
    const y = durationSeconds(b);
    if (x !== null && y !== null) return x === y;
  }
  return String(a).trim() === String(b).trim();
}

/** edits(키 → 입력 문자열) 중 지금 값과 다른 것만 [{key, before, after, item}] */
export function pendingChanges(items, edits) {
  const out = [];
  for (const item of items) {
    if (!(item.key in edits)) continue;
    const after = String(edits[item.key]).trim();
    if (!sameValue(item, item.value, after)) out.push({ key: item.key, before: item.value, after, item });
  }
  return out;
}

/**
 * 항목의 반영 상태.
 *  restart: 워커 재시작이 필요한 키로 워커가 아직 시작 때 값을 쓰는 중
 *  pending_reload: 워커가 아직 새 파일을 읽지 않음(몇 초 안에 읽음)
 *  worker_off: 워커가 꺼져 있음
 *  applied: 반영됨
 */
export function statusOf(item, worker, version) {
  if (item.restart === 'worker' && (worker?.pending_restart || []).includes(item.key)) return 'restart';
  if (!worker?.running) return 'worker_off';
  if ((worker.version ?? '') !== (version ?? '')) return 'pending_reload';
  return 'applied';
}

/** 검색: 이름·설명·키·묶음 이름에 모든 낱말이 들어 있으면 true(대소문자 무시) */
export function matches(item, query) {
  const words = String(query).toLowerCase().split(/\s+/).filter(Boolean);
  if (!words.length) return true;
  const hay = [labelOf(item), helpOf(item), item.key, t(`config_settings.group.${item.group}`)].join(' ').toLowerCase();
  return words.every((w) => hay.includes(w));
}

/** 출처 배지 문장 */
export function sourceText(source) {
  const k = `config_settings.source.${source}`;
  const v = t(k);
  return v === k ? source : v;
}

/** 경로 확인 결과 문장 */
export function pathResultText(result, formatBytes = (n) => `${n}B`) {
  const free = result.free_bytes === null || result.free_bytes === undefined ? '—' : formatBytes(result.free_bytes);
  const base = t(`config_settings.path.${result.code}`, { path: result.path ?? '', free });
  return result.created ? `${t('config_settings.path.created')} ${base}` : base;
}

/** 경로 확인이 저장을 허락하는 결과인지 */
export function pathOk(result) {
  return result?.code === 'ok' || result?.code === 'warn_same_volume';
}

/** 바이트 → "1.5 GB" */
export function formatBytes(n) {
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let v = Number(n);
  let i = 0;
  while (v >= 1024 && i < units.length - 1) { v /= 1024; i++; }
  return `${i === 0 ? v : v.toFixed(1)} ${units[i]}`;
}

/** 선택 목록 값의 표시 이름(config_settings.value.<키>.<값>, 없으면 값 그대로) — P27E */
export function valueLabel(item, value) {
  const k = `config_settings.value.${item.key}.${value}`;
  const v = t(k);
  return v === k ? String(value) : v;
}

// ---- P30B 관리자 이메일 탭 ----

/** 평문(TLS 없음) 연결인지(화면 경고, 저장 전 입력 기준) */
export function mailPlaintext(valueOf) {
  return valueOf('alert_mail.smtp_tls') === 'none';
}

/** 빠진 설정 이름 목록(서버 status.missing → 화면 문구) */
export function mailMissingText(missing) {
  return (missing ?? []).map((m) => t(`alert_mail.missing.${m}`)).join(', ');
}

/** 발송 상태 한 줄(서버 status — 저장된 설정 기준) */
export function mailStatusText(st) {
  if (!st) return '';
  if (st.state === 'on') return t('alert_mail.status.on', { n: st.recipients });
  if (st.state === 'setup') return t('alert_mail.status.setup', { missing: mailMissingText(st.missing) });
  return st.ready ? t('alert_mail.status.ready_off') : t('alert_mail.status.off');
}

/**
 * 시험 메일을 지금 보낼 수 없는 이유({key, params}) 또는 null. 시험은 저장된 설정으로만 보내므로
 * alert_mail.* 저장 전 변경이 있으면 막는다. 받는 사람·마이그레이션은 시험에 필요 없음(서버 sendTest와 같음).
 */
export function mailTestBlock(st, pendingKeys) {
  if ((pendingKeys ?? []).some((k) => k.startsWith('alert_mail.'))) return { key: 'alert_mail.test.unsaved' };
  const missing = (st?.missing ?? []).filter((m) => m !== 'recipients' && m !== 'migration');
  if (!st || missing.length) return { key: 'alert_mail.test.not_ready', params: { missing: mailMissingText(missing) } };
  return null;
}

/** 시험 결과 문장(code null = 성공) */
export function mailTestResultText(r) {
  return r.ok ? t('alert_mail.test.ok') : t('alert_mail.test.fail', { reason: t(`alert_mail.reason.${r.code}`) });
}

/** 켜기/끄기 칸 옆 글자 */
export function boolText(value) {
  return t(value === 'true' ? 'config_settings.bool.on' : 'config_settings.bool.off');
}

/**
 * 지금 값으로 쓰이지 않는 항목인가(P27E 자동 백업) — 쓰이지 않으면 이유 문자열 키, 쓰이면 null.
 *   valueOf(key): 지금 화면 값(고친 값 우선)
 */
export function inactiveReason(key, valueOf) {
  const scheduleKeys = ['backup.schedule_mode', 'backup.daily_at', 'backup.weekly_day', 'backup.monthly_day'];
  // P30B: 인증 없음이면 SMTP 사용자 이름을 쓰지 않음
  if (key === 'alert_mail.smtp_user') return valueOf('alert_mail.smtp_auth') === 'none' ? 'config_settings.inactive.mail_auth_none' : null;
  if (!scheduleKeys.includes(key)) return null;
  if (valueOf('backup.enabled') === 'false') return 'config_settings.inactive.auto_off';
  if (key === 'backup.weekly_day' && valueOf('backup.schedule_mode') !== 'weekly') return 'config_settings.inactive.weekly_day';
  if (key === 'backup.monthly_day' && valueOf('backup.schedule_mode') !== 'monthly') return 'config_settings.inactive.monthly_day';
  return null;
}

// ---- P27F 카테고리 탭: 묶음 → 탭. 탭 목록은 서버 등록부의 묶음(view.groups = ConfigSchema::EDITABLE_GROUPS 순서 + 새 묶음)에서만 만든다 ----

/** 묶음(탭) 이름(config_settings.group.<묶음>, 없으면 묶음 id) */
export function groupLabel(group) {
  const k = `config_settings.group.${group}`;
  const v = t(k);
  return v === k ? group : v;
}

/** 탭 모델 [{id, label, keys}] — 등록부 묶음 순서, 항목이 없는 묶음은 탭을 만들지 않는다 */
export function tabsFrom(groups, items) {
  return groups
    .map((id) => ({ id, label: groupLabel(id), keys: items.filter((i) => i.group === id).map((i) => i.key) }))
    .filter((tab) => tab.keys.length > 0);
}

/** 주소 해시 → 탭 id. "#backup", 예전 링크 "#group-backup"(P27B·P27E)도 같은 탭. 없거나 모르는 값이면 첫 탭 */
export function tabFromHash(hash, tabs) {
  let id = String(hash ?? '').replace(/^#/, '');
  try { id = decodeURIComponent(id); } catch { /* 잘못된 인코딩은 그대로(모르는 값 → 첫 탭) */ }
  if (id.startsWith('group-')) id = id.slice(6);
  return tabs.some((tab) => tab.id === id) ? id : (tabs[0]?.id ?? null);
}

/** 키가 속한 탭 id(없으면 null) */
export function tabOfKey(tabs, key) {
  return tabs.find((tab) => tab.keys.includes(key))?.id ?? null;
}

/** 탭별 건수 {탭 id: n} — keys(변경·오류 키 목록)를 탭으로 센다 */
export function countByTab(tabs, keys) {
  const set = new Set(keys);
  return Object.fromEntries(tabs.map((tab) => [tab.id, tab.keys.filter((k) => set.has(k)).length]));
}

/** 탭 순서·항목 순서로 첫 키 {tab, key}(저장 시도 때 첫 오류로 이동). 없으면 null */
export function firstKeyByTab(tabs, keys) {
  const set = new Set(keys);
  for (const tab of tabs) {
    const key = tab.keys.find((k) => set.has(k));
    if (key) return { tab: tab.id, key };
  }
  return null;
}

/** 검색의 탭별 일치 건수 {탭 id: n}. 검색어가 비면 null(탭 머리에 건수를 보이지 않음) */
export function searchCounts(tabs, items, query) {
  if (!String(query ?? '').trim()) return null;
  const byKey = new Map(items.map((i) => [i.key, i]));
  return Object.fromEntries(tabs.map((tab) => [tab.id, tab.keys.filter((k) => matches(byKey.get(k), query)).length]));
}

/**
 * 검색 안내: 'none'(모든 탭 0건 — 기존 "찾는 설정이 없습니다"), 'other'(지금 탭 0건, 다른 탭에 n건 — 첫 일치 탭으로 가는 단추), null(안내 없음)
 * @returns {null|{kind:'none'}|{kind:'other', n:number, tab:string}}
 */
export function searchHint(tabs, counts, current) {
  if (!counts) return null;
  const total = Object.values(counts).reduce((s, n) => s + n, 0);
  if (total === 0) return { kind: 'none' };
  if ((counts[current] ?? 0) > 0) return null;
  return { kind: 'other', n: total, tab: tabs.find((tab) => counts[tab.id] > 0).id };
}

/** 탭의 접근 가능한 이름: "분석, 변경 2건, 입력 오류 1건, 검색 결과 3건"(보이는 숫자·아이콘만으로 뜻을 전하지 않게) */
export function tabAccessibleName(tab, { changes = 0, errors = 0, matches: found = null } = {}) {
  const parts = [tab.label];
  if (changes) parts.push(t('config_settings.tab_sr.changes', { n: changes }));
  if (errors) parts.push(t('config_settings.tab_sr.errors', { n: errors }));
  if (found !== null) parts.push(t('config_settings.tab_sr.matches', { n: found }));
  return parts.join(', ');
}
