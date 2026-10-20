// 감사 로그 화면의 순수 함수(P30, tests/js/audit.test.mjs): 주소 질의 문자열 ↔ 조건, 직접 지정 날짜(화면 시간대) → UTC, 무결성 검사 진행률.

export const QUICK_DAYS = ['1', '7', '30', '90'];
const KEYS = ['days', 'from', 'to', 'actor', 'action', 'target', 'q'];

/** 주소 질의 문자열 → 조건(알 수 없는 키·형식이 틀린 값은 버림). from·to는 날짜(YYYY-MM-DD, 화면 시간대) */
export function fromQuery(search) {
  const p = new URLSearchParams(search ?? '');
  const date = (v) => (/^\d{4}-\d{2}-\d{2}$/.test(v ?? '') ? v : '');
  const text = (v) => String(v ?? '').slice(0, 100);
  const days = p.get('days') ?? '';
  const page = Number.parseInt(p.get('page') ?? '1', 10);
  return {
    days: QUICK_DAYS.includes(days) ? days : '',
    from: date(p.get('from')),
    to: date(p.get('to')),
    actor: text(p.get('actor')),
    action: text(p.get('action')),
    target: text(p.get('target')),
    q: text(p.get('q')),
    page: Number.isFinite(page) && page >= 1 ? page : 1,
  };
}

/** 조건 → 주소 질의 문자열(빈 값 생략, page는 1이 아닐 때만) */
export function toQuery(state) {
  const p = new URLSearchParams();
  for (const k of KEYS) {
    if (k === 'days' && (state.from || state.to)) continue; // 직접 지정이 우선
    if (state[k]) p.set(k, state[k]);
  }
  if ((state.page ?? 1) > 1) p.set('page', String(state.page));
  const s = p.toString();
  return s ? `?${s}` : '';
}

/** 시간대 tz에서 그 날짜·시각이 UTC로 몇 시인지(ms). 서머타임 경계도 Intl로 두 번 맞춘다 */
export function zonedToUtc(y, m, d, hh, mm, ss, tz) {
  const guess = Date.UTC(y, m - 1, d, hh, mm, ss);
  const offsetAt = (ms) => {
    const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
      timeZone: tz, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
    }).formatToParts(new Date(ms)).filter((x) => x.type !== 'literal').map((x) => [x.type, Number(x.value)]));
    return Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second) - ms;
  };
  let utc = guess - offsetAt(guess);
  utc = guess - offsetAt(utc);
  return utc;
}

const iso = (ms) => new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');

/** API 질의 값: 날짜(화면 시간대) → UTC ISO(시작일 00:00:00, 종료일 23:59:59). 시작 > 종료면 null(화면이 오류 안내) */
export function apiParams(state, tz) {
  const out = {};
  for (const k of ['actor', 'action', 'target', 'q']) if (state[k]) out[k] = state[k];
  if (state.from || state.to) {
    if (state.from) {
      const [y, m, d] = state.from.split('-').map(Number);
      out.from = iso(zonedToUtc(y, m, d, 0, 0, 0, tz));
    }
    if (state.to) {
      const [y, m, d] = state.to.split('-').map(Number);
      out.to = iso(zonedToUtc(y, m, d, 23, 59, 59, tz));
    }
    if (out.from && out.to && out.from > out.to) return null;
  } else if (state.days) {
    out.days = state.days;
  }
  return out;
}

/** 무결성 검사 진행률(0~100, 정수) — 남은 범위의 id로 어림 */
export function progress(lastId, range) {
  if (!range || !range.first || !range.last) return 100;
  const span = range.last.id - range.first.id + 1;
  if (span <= 0) return 100;
  return Math.max(0, Math.min(100, Math.floor(((lastId - range.first.id + 1) / span) * 100)));
}

/** 세부 정보 객체 → 줄 목록(키 = 값, 중첩은 JSON). 값은 서버가 이미 가린 것 */
export function detailLines(details) {
  return Object.entries(details ?? {}).map(([k, v]) => [k, v === null || typeof v !== 'object' ? String(v) : JSON.stringify(v)]);
}

/**
 * X19 감사 로그 표의 시간 칸: 화면 시간대 기준 "YY/MM/DD HH:MM"(24시간제, 초·시간대 이름 없음, ko·en 같음).
 * 전체 시각은 툴팁·상세에서 보인다. 형식이 틀린 값은 빈 글자.
 */
export function shortDateTime(iso, timeZone) {
  const d = new Date(iso);
  if (!iso || Number.isNaN(d.getTime())) return '';
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-GB', {
    timeZone, year: '2-digit', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
  }).formatToParts(d).map((p) => [p.type, p.value]));
  return `${parts.year}/${parts.month}/${parts.day} ${parts.hour}:${parts.minute}`;
}
