// 앱 안 알림(P24): 배지 글자(0 숨김·99+)·접근 가능한 이름, 문구(종류 × params, 사용자 입력은 글자 그대로), 종류 아이콘, 상대 시각,
// 목록 조건 ↔ 주소, 안 읽은 개수 갱신기(보이는 동안만·숨기면 멈춤·다시 보이면 즉시·오류 때 이전 값 유지), 패널 키보드.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  KINDS, badgeText, bellLabel, notificationText, kindIcon, relativeTime, filterFromSearch, searchFromFilter, apiQuery, sameFilter,
  createUnreadCounter, panelKey,
} from '../../public/assets/js/lib/notification_core.js';
import { createPoller } from '../../public/assets/js/core/poll.js';
import { has } from '../../public/assets/js/core/i18n.js';

test('배지 글자: 0·잘못된 값은 숨김, 1~99 숫자, 99 초과 99+', () => {
  assert.equal(badgeText(0), '');
  assert.equal(badgeText(-3), '');
  assert.equal(badgeText(undefined), '');
  assert.equal(badgeText('abc'), '');
  assert.equal(badgeText(1), '1');
  assert.equal(badgeText(99), '99');
  assert.equal(badgeText(100), '99+');
  assert.equal(badgeText(12345), '99+');
  assert.equal(bellLabel(0), '알림, 안 읽은 알림 없음');
  assert.equal(bellLabel(3), '알림, 안 읽은 알림 3개');
  assert.equal(bellLabel(150), '알림, 안 읽은 알림 99+개');
});

test('문구: 종류 × params', () => {
  const project = { id: 1, name: 'Pandas' };
  assert.equal(notificationText({ kind: 'analysis_done', project, params: { run: 3, new: 1200, resolved: 4, reopened: 0, no_change: false } }),
    'Pandas: 분석이 끝났습니다 — Run 3, 신규 1,200건 · 해결 4건 · 재오픈 0건.');
  assert.equal(notificationText({ kind: 'analysis_done', project, params: { run: null, new: 1, resolved: 0, reopened: 0 } }),
    'Pandas: 분석이 끝났습니다 — 신규 1건 · 해결 0건 · 재오픈 0건.');
  assert.equal(notificationText({ kind: 'analysis_done', project, params: { no_change: true } }), 'Pandas: 분석이 끝났습니다(변경 없음).');
  assert.equal(notificationText({ kind: 'analysis_failed', project, params: { status: 'failed', code: 'timeout' }, failure: { title: '시간 초과' } }),
    'Pandas: 분석이 실패했습니다(시간 초과).');
  assert.equal(notificationText({ kind: 'analysis_failed', project, params: { status: 'failed', code: 'x_unknown' } }), 'Pandas: 분석이 실패했습니다(x_unknown).');
  assert.equal(notificationText({ kind: 'analysis_failed', project, params: { status: 'cancelled', code: null } }), 'Pandas: 분석이 취소되었습니다.');
  assert.equal(notificationText({ kind: 'defect_assigned', project, defect_id: 42, params: { count: 1 } }), 'Pandas: 결함 #42이(가) 회원님에게 지정되었습니다.');
  assert.equal(notificationText({ kind: 'defect_assigned', project, defect_id: null, params: { count: 12 } }), 'Pandas: 결함 12건이 회원님에게 지정되었습니다.');
  assert.equal(notificationText({ kind: 'defect_changed', project, defect_id: 7, params: { count: 1, status: 'in_progress', severity: null } }),
    'Pandas: 담당 결함 #7의 상태가 변경되었습니다(진행 중).');
  assert.equal(notificationText({ kind: 'defect_changed', project, defect_id: 7, params: { count: 1, status: null, severity: 'high' } }),
    'Pandas: 담당 결함 #7의 심각도가 변경되었습니다(높음).');
  assert.equal(notificationText({ kind: 'defect_changed', project, defect_id: null, params: { count: 3, status: 'open', severity: 'low' } }),
    'Pandas: 담당 결함 3건의 상태·심각도가 변경되었습니다.');
  assert.equal(notificationText({ kind: 'analysis_summary', project, params: { run: 5, new: 2, resolved: 9, reopened: 1, mine_resolved: 0, mine_reopened: 0 } }),
    'Pandas: Run 5 분석 — 신규 2건 · 해결 9건 · 재오픈 1건.');
  assert.equal(notificationText({ kind: 'analysis_summary', project, params: { run: 5, new: 2, resolved: 9, reopened: 1, mine_resolved: 3, mine_reopened: 1 } }),
    'Pandas: Run 5 분석 — 신규 2건 · 해결 9건 · 재오픈 1건. 내 담당 결함: 해결 3건 · 재오픈 1건.');
  for (const k of KINDS) {
    assert.ok(has(`notif.kind.${k}`) && has(`notif.kind_desc.${k}`), k);
  }
});

test('문구: 사용자 입력(프로젝트 이름)은 글자 그대로(HTML로 해석하지 않음 — 화면은 textContent), 이름이 없으면 일반 이름', () => {
  const evil = '<img src=x onerror=alert(1)>';
  const s = notificationText({ kind: 'analysis_done', project: { name: evil }, params: { no_change: true } });
  assert.ok(s.startsWith(evil), '그대로 문자열에 남고 escape·해석은 화면(textContent)이 맡음');
  assert.equal(notificationText({ kind: 'analysis_done', project: null, params: { no_change: true } }), '프로젝트: 분석이 끝났습니다(변경 없음).');
  assert.equal(notificationText({ kind: 'defect_assigned', project: { name: '{n}' }, params: { count: 2 } }), '{n}: 결함 2건이 회원님에게 지정되었습니다.', '이름 속 자리표시자는 바꾸지 않음');
});

test('종류 아이콘: 기존 아이콘 재사용(새 아이콘 없음)', () => {
  assert.equal(kindIcon({ kind: 'analysis_done' }), 'analysis_status.succeeded');
  assert.equal(kindIcon({ kind: 'analysis_failed', params: { status: 'failed' } }), 'analysis_status.failed');
  assert.equal(kindIcon({ kind: 'analysis_failed', params: { status: 'cancelled' } }), 'analysis_status.cancelled');
  assert.equal(kindIcon({ kind: 'defect_assigned' }), 'nav.my_defects');
  assert.equal(kindIcon({ kind: 'defect_changed' }), 'ui.changed');
  assert.equal(kindIcon({ kind: 'analysis_summary' }), 'ui.run');
});

test('상대 시각', () => {
  const now = Date.parse('2026-10-07T12:00:00Z');
  assert.equal(relativeTime('2026-10-07T11:59:30Z', now), '방금');
  assert.equal(relativeTime('2026-10-07T11:15:00Z', now), '45분 전');
  assert.equal(relativeTime('2026-10-07T02:00:00Z', now), '10시간 전');
  assert.equal(relativeTime('2026-10-01T12:00:00Z', now), '6일 전');
  assert.equal(relativeTime('2026-09-01T12:00:00Z', now, () => '날짜'), '날짜');
  assert.equal(relativeTime('2026-10-07T12:05:00Z', now), '방금', '시계가 조금 어긋나도 음수 없음');
  assert.equal(relativeTime('nope', now), '');
});

test('목록 조건 ↔ 주소(새로 고침·뒤로 가기 복원), 잘못된 값은 기본값', () => {
  assert.deepEqual(filterFromSearch('?kind=defect_assigned&unread=1&page=3'), { kind: 'defect_assigned', unread: true, page: 3 });
  assert.deepEqual(filterFromSearch('?kind=email&unread=yes&page=0'), { kind: '', unread: false, page: 1 });
  assert.deepEqual(filterFromSearch(''), { kind: '', unread: false, page: 1 });
  assert.equal(searchFromFilter({ kind: 'analysis_done', unread: true, page: 2 }), '?kind=analysis_done&unread=1&page=2');
  assert.equal(searchFromFilter({ kind: '', unread: false, page: 1 }), '');
  for (const f of [{ kind: 'analysis_summary', unread: false, page: 4 }, { kind: '', unread: true, page: 1 }]) {
    assert.deepEqual(filterFromSearch(searchFromFilter(f)), f, '왕복');
  }
  assert.deepEqual(apiQuery({ kind: '', unread: true, page: 2 }, 50), { kind: undefined, unread: '1', page: 2, size: 50 });
  assert.ok(sameFilter({ kind: 'a', unread: false }, { kind: 'a', unread: false, page: 3 }), '쪽은 비교하지 않음');
  assert.ok(!sameFilter({ kind: '', unread: true }, { kind: '', unread: false }));
});

/** 가짜 타이머 + 보임/숨김 문서 */
function fakeEnv() {
  const timers = [];
  const listeners = {};
  const doc = {
    visibilityState: 'visible',
    addEventListener: (t, fn) => { (listeners[t] ??= []).push(fn); },
    removeEventListener: (t, fn) => { listeners[t] = (listeners[t] ?? []).filter((f) => f !== fn); },
    fire: (t) => (listeners[t] ?? []).forEach((f) => f()),
  };
  return {
    doc,
    timers,
    setTimer: (fn, ms) => { const id = timers.length; timers.push({ fn, ms, live: true }); return id; },
    clearTimer: (id) => { if (id !== null && timers[id]) timers[id].live = false; },
    live: () => timers.filter((x) => x.live),
    async runNext() {
      const tm = timers.find((x) => x.live);
      if (!tm) return false;
      tm.live = false;
      await tm.fn();
      return true;
    },
  };
}

test('안 읽은 개수: 열 때 한 번 + 주기마다, 숨기면 멈추고 다시 보이면 즉시, 오류는 이전 값 유지', async () => {
  const env = fakeEnv();
  const values = [3, 3, new Error('net'), 120];
  let calls = 0;
  const seen = [];
  const c = createUnreadCounter({
    fetchCount: async () => { const v = values[calls++]; if (v instanceof Error) throw v; return v; },
    onChange: (n) => seen.push(n), interval: 60000, createPoller, doc: env.doc, setTimer: env.setTimer, clearTimer: env.clearTimer,
  });
  c.start();
  assert.equal(env.live()[0].ms, 0, '열 때 바로');
  await env.runNext();
  assert.deepEqual(seen, [3]);
  assert.equal(env.live()[0].ms, 60000, '다음은 주기(분당 1회)');
  await env.runNext();
  assert.deepEqual(seen, [3], '같은 값이면 다시 그리지 않음');
  await env.runNext(); // 오류
  assert.equal(c.value, 3, '오류 때 이전 값 유지');
  assert.equal(env.live()[0].ms, 120000, '실패 뒤 간격 늘림(최대 4배)');
  env.doc.visibilityState = 'hidden';
  env.doc.fire('visibilitychange');
  assert.equal(env.live().length, 0, '숨기면 요청 없음');
  env.doc.visibilityState = 'visible';
  env.doc.fire('visibilitychange');
  assert.equal(env.live()[0].ms, 0, '다시 보이면 즉시');
  await env.runNext();
  assert.deepEqual(seen, [3, 120]);
  c.set(0);
  assert.deepEqual(seen, [3, 120, 0], '모두 읽음 → 바로 0');
  c.stop();
  assert.equal(env.live().length, 0);
  assert.equal(calls, 4);
});

test('패널 키보드: ↓↑ 돌기, Home·End, Esc 닫기, 항목 없으면 이동 없음', () => {
  let s = { open: true, index: -1, count: 3 };
  let r = panelKey(s, 'ArrowDown');
  assert.deepEqual([r.state.index, r.action], [0, 'focus']);
  r = panelKey({ ...s, index: 2 }, 'ArrowDown');
  assert.equal(r.state.index, 0, '끝에서 처음으로');
  r = panelKey({ ...s, index: 0 }, 'ArrowUp');
  assert.equal(r.state.index, 2);
  assert.equal(panelKey(s, 'ArrowUp').state.index, 2, '밖에서 ↑ = 마지막');
  assert.equal(panelKey(s, 'End').state.index, 2);
  assert.equal(panelKey({ ...s, index: 2 }, 'Home').state.index, 0);
  r = panelKey({ ...s, index: 1 }, 'Escape');
  assert.deepEqual([r.state.open, r.action], [false, 'close']);
  assert.equal(panelKey({ open: true, index: -1, count: 0 }, 'ArrowDown').action, null);
  assert.equal(panelKey(s, 'a').action, null);
});
