// 앱 안 알림(P24)의 순수 함수: 배지 글자, 접근 가능한 이름, 알림 문구(kind + params), 종류 아이콘, 상대 시각, 목록 조건 ↔ 주소.
// DOM을 쓰지 않는다(Node 시험). 문구는 문자열만 만들고 화면은 textContent로 넣는다(프로젝트 이름 등 사용자 입력이 HTML로 해석되지 않음).
import { t as defaultT } from '../core/i18n.js';
import { formatDateTime, DEFAULT_TIMEZONE } from '../core/time.js';
import { skipReasonKey } from './schedule_core.js';

export const KINDS = ['analysis_done', 'analysis_failed', 'defect_assigned', 'defect_changed', 'analysis_summary', 'schedule_failed', 'defect_count_warn',
  'backup_failed', 'backup_done', 'restore_rehearsal_due', 'account_pending', 'account_pending_limit', 'auth_identity_mismatch', 'saml_cert_expiring'];

/** 안 읽은 개수 → 배지 글자. 0·잘못된 값이면 ''(배지 숨김), 99 초과는 "99+" */
export function badgeText(count) {
  const n = Number(count);
  if (!Number.isFinite(n) || n <= 0) return '';
  return n > 99 ? '99+' : String(Math.floor(n));
}

/** 헤더 알림 버튼의 접근 가능한 이름("알림, 안 읽은 알림 N개") */
export function bellLabel(count, t = defaultT) {
  const text = badgeText(count);
  return text === '' ? t('notif.bell_none') : t('notif.bell_count', { n: text });
}

/** 종류(와 상태) → 아이콘 의미 이름(기존 아이콘 재사용) */
export function kindIcon(item) {
  switch (item?.kind) {
    case 'analysis_done': return 'analysis_status.succeeded';
    case 'analysis_failed': return item?.params?.status === 'cancelled' ? 'analysis_status.cancelled' : 'analysis_status.failed';
    case 'defect_assigned': return 'nav.my_defects';
    case 'defect_changed': return 'ui.changed';
    case 'analysis_summary': return 'ui.run';
    case 'schedule_failed': return 'source.schedule';
    case 'defect_count_warn': return 'system.warn';
    case 'backup_failed': return 'system.error';
    case 'backup_done': return 'system.ok';
    case 'restore_rehearsal_due': return 'system.warn';
    case 'account_pending': return 'admin.users';
    case 'account_pending_limit': return 'system.warn';
    case 'saml_cert_expiring': return 'system.warn';
    case 'auth_identity_mismatch': return 'system.error';
    default: return 'nav.notifications';
  }
}

const num = (v) => (Number.isFinite(Number(v)) ? Number(v).toLocaleString('ko-KR') : '0');

/**
 * 알림 한 건의 문구. item: API 항목 { kind, params, project: {name}|null, defect_id, failure: {title}|undefined }
 * 프로젝트 이름은 서버가 보여 줄 때 조회한 값(접근 가능한 것만), 없으면 일반 이름.
 */
export function notificationText(item, t = defaultT) {
  const p = item?.params ?? {};
  const project = item?.project?.name || t('notif.project_hidden');
  const counts = { project, new: num(p.new), resolved: num(p.resolved), reopened: num(p.reopened) };
  switch (item?.kind) {
    case 'analysis_done':
      if (p.no_change) return t('notif.text.analysis_done_nochange', { project });
      return Number.isInteger(p.run) ? t('notif.text.analysis_done', { ...counts, run: p.run }) : t('notif.text.analysis_done_norun', counts);
    case 'analysis_failed': {
      if (p.status === 'cancelled') return t('notif.text.analysis_cancelled', { project });
      const reason = item?.failure?.title || (typeof p.code === 'string' ? p.code : '');
      return reason ? t('notif.text.analysis_failed', { project, reason }) : t('notif.text.analysis_failed_nocode', { project });
    }
    case 'defect_assigned': {
      const n = Number(p.count) || 1;
      return n === 1 && item?.defect_id ? t('notif.text.defect_assigned_one', { project, id: item.defect_id })
        : t('notif.text.defect_assigned_many', { project, n: num(n) });
    }
    case 'defect_changed': {
      const n = Number(p.count) || 1;
      const status = typeof p.status === 'string' ? p.status : null;
      const severity = typeof p.severity === 'string' ? p.severity : null;
      const what = status && severity ? t('notif.text.what_both') : (severity ? t('notif.text.what_severity') : t('notif.text.what_status'));
      const value = status && !severity ? t(`icon.defect_status.${status}`) : (severity && !status ? t(`icon.severity.${severity}`) : null);
      const to = value ? t('notif.text.to', { value }) : '';
      return n === 1 && item?.defect_id ? t('notif.text.defect_changed_one', { project, id: item.defect_id, what, to })
        : t('notif.text.defect_changed_many', { project, n: num(n), what, to });
    }
    case 'analysis_summary': {
      const mineResolved = Number(p.mine_resolved) || 0;
      const mineReopened = Number(p.mine_reopened) || 0;
      const base = { ...counts, run: Number.isInteger(p.run) ? p.run : '-' };
      return mineResolved + mineReopened > 0
        ? t('notif.text.analysis_summary_mine', { ...base, mine_resolved: num(mineResolved), mine_reopened: num(mineReopened) })
        : t('notif.text.analysis_summary', base);
    }
    case 'schedule_failed': {
      // P26(admin 전용): 주기 분석 실패(연속이면 한 건의 횟수가 늘어남) 또는 건너뜀(디스크·연결 문제)
      const next = typeof p.next_run_at === 'string' ? formatDateTime(p.next_run_at, DEFAULT_TIMEZONE) : null;
      const nextText = next ? t('notif.text.schedule_next', { next }) : t('notif.text.schedule_no_next');
      if (typeof p.skipped === 'string') {
        return t('notif.text.schedule_skipped', { project, reason: t(skipReasonKey(p.code)), next: nextText });
      }
      const reason = item?.failure?.title || (typeof p.code === 'string' ? p.code : '');
      const n = Number(p.count) || 1;
      return n > 1 ? t('notif.text.schedule_failed_many', { project, reason, n: num(n), next: nextText })
        : t('notif.text.schedule_failed', { project, reason, next: nextText });
    }
    case 'defect_count_warn':
      // P19C(admin 전용): 프로젝트 결함이 경고 기준을 넘음(한 건에 건수 갱신)
      return t('notif.text.defect_count_warn', { project, n: num(p.count), warn: num(p.warn) });
    case 'backup_failed': {
      // P27B(admin 전용): 일일·수동 백업 실패(같은 사유 1시간 한 번) — 사유는 백업 오류 문구, 모르면 코드
      const code = String(p.code ?? '');
      const key = `backups.err.${code}`;
      const text = t(key); // 없는 키는 키를 그대로 돌려준다
      return t('notif.text.backup_failed', { reason: text !== key ? text : code });
    }
    case 'backup_done':
      return t('notif.text.backup_done', { file: String(p.file ?? '') });
    case 'restore_rehearsal_due':
      return t('notif.text.restore_rehearsal_due', { days: num(p.days) });
    case 'account_pending':
      // P28B(admin 전용): 외부 인증 첫 로그인 → 승인 대기 계정. 하루 5건을 넘으면 그날 요약 1건(count)
      return p.count !== undefined
        ? t('notif.text.account_pending_many', { n: num(p.count) })
        : t('notif.text.account_pending', { login: String(p.login_id ?? ''), method: t(`account.auth_${String(p.auth_type ?? 'ldap')}`) });
    case 'account_pending_limit':
      return t('notif.text.account_pending_limit', { n: num(p.max) });
    case 'saml_cert_expiring':
      // P28D(admin 전용): IdP 서명 인증서(현재·다음) 만료 임박 또는 만료 — 인증서마다 하루 한 건
      return t(Number(p.days) < 0 ? 'notif.text.saml_cert_expired' : 'notif.text.saml_cert_expiring', { which: t(p.which === 'next' ? 'saml.cert.next' : 'saml.cert.current'), n: num(p.days), date: String(p.not_after ?? '').slice(0, 10) });
    case 'auth_identity_mismatch':
      // P28C: SSO 로그인 ID가 다른 계정과 겹쳐 거부(reason login_id_conflict)는 문구만 다르다
      return t(p.reason === 'login_id_conflict' ? 'notif.text.auth_login_conflict' : 'notif.text.auth_identity_mismatch', { login: String(p.login_id ?? '') });
    default:
      return t('notif.bell');
  }
}

/** 상대 시각: 1분 미만 "방금", 60분 미만 "N분 전", 24시간 미만 "N시간 전", 7일 미만 "N일 전", 그 뒤는 fallback(날짜) */
export function relativeTime(iso, now = Date.now(), fallback = (s) => s, t = defaultT) {
  const at = Date.parse(iso);
  if (!Number.isFinite(at)) return '';
  const sec = Math.max(0, Math.floor((now - at) / 1000));
  if (sec < 60) return t('notif.time.now');
  if (sec < 3600) return t('notif.time.minutes', { n: Math.floor(sec / 60) });
  if (sec < 86400) return t('notif.time.hours', { n: Math.floor(sec / 3600) });
  if (sec < 7 * 86400) return t('notif.time.days', { n: Math.floor(sec / 86400) });
  return fallback(iso);
}

/** 목록 주소(search 문자열) → 적용된 조건 { kind, unread, page }. 잘못된 값은 기본값 */
export function filterFromSearch(search) {
  const q = new URLSearchParams(search ?? '');
  const kind = KINDS.includes(q.get('kind')) ? q.get('kind') : '';
  const unread = q.get('unread') === '1';
  const page = /^[1-9][0-9]{0,6}$/.test(q.get('page') ?? '') ? Number(q.get('page')) : 1;
  return { kind, unread, page };
}

/** 조건 → 주소 search(기본값은 넣지 않음) */
export function searchFromFilter({ kind = '', unread = false, page = 1 } = {}) {
  const q = new URLSearchParams();
  if (kind) q.set('kind', kind);
  if (unread) q.set('unread', '1');
  if (page > 1) q.set('page', String(page));
  const s = q.toString();
  return s ? `?${s}` : '';
}

/** 조건 → API 쿼리 */
export function apiQuery({ kind = '', unread = false, page = 1 } = {}, size = 50) {
  return { kind: kind || undefined, unread: unread ? '1' : undefined, page, size };
}

/** 같은 조건인지(검색 버튼 전 "바뀜" 표시) */
export function sameFilter(a, b) {
  return (a?.kind ?? '') === (b?.kind ?? '') && Boolean(a?.unread) === Boolean(b?.unread);
}

/**
 * 헤더의 안 읽은 개수 갱신기. 화면을 열 때 한 번 + 탭이 보이는 동안 interval마다(Page Visibility: 숨기면 멈추고 다시 보이면 바로 한 번).
 * 오류가 나면 조용히 두고(이전 값 유지) 다음 주기에 다시 시도한다. 장시간 연결·웹소켓은 쓰지 않는다.
 *   fetchCount(): Promise<number>, onChange(count): 값이 바뀔 때만
 * createPoller는 core/poll.js(실패 시 간격을 늘리되 interval의 4배까지).
 */
export function createUnreadCounter({ fetchCount, onChange, interval, createPoller, doc = globalThis.document, setTimer, clearTimer }) {
  let value = null;
  const apply = (n) => {
    const c = Math.max(0, Math.floor(Number(n) || 0));
    if (c !== value) {
      value = c;
      onChange(c);
    }
  };
  const poller = createPoller(async () => {
    apply(await fetchCount());
    return true;
  }, { interval, maxInterval: interval * 4, doc, setTimer, clearTimer });
  return {
    start: () => poller.start({ immediate: true }),
    stop: () => poller.stop(),
    /** 패널·목록에서 읽음 처리 뒤 바로 다시 센다(실패는 무시, 이전 값 유지) */
    async refresh() {
      try { apply(await fetchCount()); } catch { /* 다음 주기에 다시 */ }
    },
    /** 서버를 거치지 않고 아는 값으로 바꾼다(모두 읽음 → 0) */
    set: (n) => apply(n),
    get value() { return value; },
    get running() { return poller.running; },
  };
}

/**
 * 미리보기 패널 키보드: 항목 사이 ↓↑(처음·끝에서 돎), Home·End, Esc 닫기. 상태 { open, index(-1 = 항목 밖), count }
 * @returns {{ state: object, action: null|'close'|'focus' }}
 */
export function panelKey(state, key) {
  const { count } = state;
  if (key === 'Escape') return { state: { ...state, open: false, index: -1 }, action: 'close' };
  if (!state.open || count === 0) return { state, action: null };
  const at = state.index;
  const next = {
    ArrowDown: at < 0 ? 0 : (at + 1) % count,
    ArrowUp: at < 0 ? count - 1 : (at - 1 + count) % count,
    Home: 0,
    End: count - 1,
  }[key];
  return next === undefined ? { state, action: null } : { state: { ...state, index: next }, action: 'focus' };
}
