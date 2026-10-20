// 시스템 모니터링·감사 로그 화면 순수 함수(P30): 상태 줄·카드 수준·확인 불가 사유·체크 표시, 감사 조건 ↔ 주소, 화면 시간대 날짜 → UTC, 진행률, 세부 정보 줄, 문구 ko·en.
import test from 'node:test';
import assert from 'node:assert/strict';
import { levelIcon, statusLine, alertText, unavailableReason, checkView, cardLevel, badgeView, BADGES, limitRows, MAX_ROWS } from '../../public/assets/js/lib/monitoring_core.js';
import { fromQuery, toQuery, apiParams, zonedToUtc, progress, detailLines } from '../../public/assets/js/lib/audit_core.js';
import { auditRetentionTooShort } from '../../public/assets/js/lib/config_settings_core.js';
import ko from '../../public/assets/js/i18n/ko.js';
import en from '../../public/assets/js/i18n/en.js';

test('모니터링: 상태 줄·수준 아이콘·카드 수준', () => {
  assert.deepEqual(statusLine('ok', []), { key: 'monitoring.status.ok', params: {}, icon: 'system.ok' });
  const alerts = [{ code: 'worker_down', level: 'fail', params: {} }, { code: 'disk_warn', level: 'warn', params: { percent: 81 } }];
  assert.deepEqual(statusLine('fail', alerts), { key: 'monitoring.status.mixed', params: { fail: 1, warn: 1 }, icon: 'system.error' });
  assert.deepEqual(statusLine('fail', [alerts[0]]), { key: 'monitoring.status.fail', params: { n: 1 }, icon: 'system.error' });
  assert.deepEqual(statusLine('warn', [alerts[1]]), { key: 'monitoring.status.warn', params: { n: 1 }, icon: 'system.warn' });
  assert.equal(levelIcon('block'), 'system.error');
  assert.equal(levelIcon('expiring'), 'system.warn');
  assert.equal(levelIcon('ok'), 'system.ok');
  assert.deepEqual(alertText(alerts[1]), { key: 'monitoring.alert.disk_warn', params: { percent: 81 } });
  assert.equal(cardLevel('worker', { available: true }, alerts), 'fail');
  assert.equal(cardLevel('disk', { available: true }, alerts), 'warn');
  assert.equal(cardLevel('queue', { available: true }, alerts), 'ok');
  assert.equal(cardLevel('cert', { available: false, reason: 'not_configured' }, alerts), 'unknown');
  assert.equal(unavailableReason({ available: false, reason: 'not_configured' }), 'monitoring.reason.not_configured');
  assert.equal(unavailableReason({ available: false, reason: 'weird' }), 'monitoring.reason.error');
  assert.equal(unavailableReason({ available: true }), null);
  assert.deepEqual([checkView(true).key, checkView(false).key, checkView(null).key], ['monitoring.check.ok', 'monitoring.check.fail', 'monitoring.check.unknown']);
});

test('감사 로그: 주소 ↔ 조건(공유 링크), 형식이 틀린 값은 버림', () => {
  const s = fromQuery('?days=7&actor=kim&action=user.&q=%25_&page=3&evil=1');
  assert.deepEqual(s, { days: '7', from: '', to: '', actor: 'kim', action: 'user.', target: '', q: '%_', page: 3 });
  assert.equal(toQuery(s), '?days=7&actor=kim&action=user.&q=%25_&page=3');
  assert.deepEqual(fromQuery('?days=2&from=2026-13&page=-1'), { days: '', from: '', to: '', actor: '', action: '', target: '', q: '', page: 1 });
  assert.equal(toQuery({ days: '7', from: '2026-10-01', to: '', actor: '', action: '', target: '', q: '', page: 1 }), '?from=2026-10-01', '직접 지정이 우선');
  assert.equal(toQuery(fromQuery('')), '');
  assert.equal(fromQuery(`?actor=${'a'.repeat(150)}`).actor.length, 100);
});

test('감사 로그: 화면 시간대 날짜 → UTC(시작 00:00:00, 종료 23:59:59), 시작 > 종료면 null', () => {
  assert.equal(new Date(zonedToUtc(2026, 10, 2, 0, 0, 0, 'Asia/Seoul')).toISOString(), '2026-10-01T15:00:00.000Z');
  assert.equal(new Date(zonedToUtc(2026, 3, 8, 3, 0, 0, 'America/New_York')).toISOString(), '2026-03-08T07:00:00.000Z', '서머타임 시작일');
  assert.deepEqual(apiParams({ days: '', from: '2026-10-01', to: '2026-10-02', actor: 'k', action: '', target: '', q: '' }, 'Asia/Seoul'),
    { actor: 'k', from: '2026-09-30T15:00:00Z', to: '2026-10-02T14:59:59Z' });
  assert.deepEqual(apiParams({ days: '30', from: '', to: '', actor: '', action: 'faq.', target: '', q: '' }, 'Asia/Seoul'), { action: 'faq.', days: '30' });
  assert.equal(apiParams({ days: '', from: '2026-10-05', to: '2026-10-01', actor: '', action: '', target: '', q: '' }, 'Asia/Seoul'), null);
});

test('감사 로그: 무결성 진행률·세부 정보 줄', () => {
  const range = { first: { id: 101 }, last: { id: 200 } };
  assert.equal(progress(100, range), 0);
  assert.equal(progress(150, range), 50);
  assert.equal(progress(200, range), 100);
  assert.equal(progress(5, null), 100);
  assert.deepEqual(detailLines({ a: 1, b: null, c: { d: 'x' }, e: '<b>' }), [['a', '1'], ['b', 'null'], ['c', '{"d":"x"}'], ['e', '<b>']]);
});

test('설정 관리: 감사 로그 보존 90일 미만이면 확인', () => {
  assert.equal(auditRetentionTooShort('89d'), true);
  assert.equal(auditRetentionTooShort('90d'), false);
  assert.equal(auditRetentionTooShort('2160h'), false);
  assert.equal(auditRetentionTooShort('abc'), false);
});

test('P30 문구 ko·en', () => {
  const keys = Object.keys(ko).filter((k) => k.startsWith('monitoring.') || k.startsWith('audit.') || k.startsWith('config_settings.audit_short')
    || k === 'config_settings.group.monitoring' || k.startsWith('config_settings.key.health.') || k.startsWith('config_settings.key.monitoring.')
    || k.startsWith('config_settings.key.log.audit_retention'));
  assert.ok(keys.length > 100, String(keys.length));
  for (const k of keys) assert.ok(en[k], k);
  for (const code of ['worker_down', 'queue_stalled', 'disk_warn', 'disk_block', 'backup_failed', 'backup_stale', 'tool_unhealthy', 'repo_fetch_failing', 'cert_expiring', 'cert_expired']) {
    assert.ok(ko[`monitoring.alert.${code}`] && en[`monitoring.alert.${code}`], code);
  }
});

test('모니터링 카드(X18): 상태 배지 한 사전·목록 최대 행', () => {
  assert.deepEqual(Object.keys(BADGES), ['ok', 'warn', 'fail', 'unknown', 'info']);
  assert.deepEqual(badgeView('ok'), { state: 'ok', icon: 'system.ok', key: 'monitoring.badge.ok' });
  assert.deepEqual(badgeView('warn').icon, 'system.warn');
  assert.deepEqual(badgeView('fail').icon, 'system.error');
  assert.deepEqual(badgeView('unknown').icon, 'system.unknown');
  assert.equal(badgeView('info').icon, 'ui.info');
  assert.equal(badgeView('nonsense').state, 'unknown', '알 수 없는 값은 확인 불가');
  // 아이콘이 서로 다르고(색만으로 구분하지 않음), 문구가 ko·en 모두 있다
  assert.equal(new Set(Object.values(BADGES).map((b) => b.icon)).size, 5);
  for (const b of Object.values(BADGES)) {
    assert.ok(ko[b.key] && en[b.key], b.key);
    assert.ok(!/[가-힣]/.test(en[b.key]), b.key);
  }
  assert.equal(MAX_ROWS, 5);
  assert.deepEqual(limitRows([1, 2, 3], 5), { shown: [1, 2, 3], more: 0 });
  assert.deepEqual(limitRows([1, 2, 3, 4, 5, 6, 7], 5), { shown: [1, 2, 3, 4, 5], more: 2 });
  assert.deepEqual(limitRows(null), { shown: [], more: 0 });
  // 카드 수준 → 배지: 확인 불가 카드
  assert.equal(badgeView(cardLevel('cert', { available: false, reason: 'not_configured' }, [])).state, 'unknown');
});

test('감사 로그(X19): 시간 칸 YY/MM/DD HH:MM — 화면 시간대·0채움·자정·연말', async () => {
  const { shortDateTime } = await import('../../public/assets/js/lib/audit_core.js');
  assert.equal(shortDateTime('2026-10-13T09:53:00Z', 'Asia/Seoul'), '26/10/13 18:53');
  assert.equal(shortDateTime('2026-01-04T15:00:00Z', 'Asia/Seoul'), '26/01/05 00:00', '자정·한 자리 월/일 0채움');
  assert.equal(shortDateTime('2026-12-31T15:30:59Z', 'Asia/Seoul'), '27/01/01 00:30', '연말 → 다음 해, 초 버림');
  assert.equal(shortDateTime('2026-12-31T23:05:00Z', 'UTC'), '26/12/31 23:05');
  assert.equal(shortDateTime('2026-03-08T10:00:00Z', 'America/New_York'), '26/03/08 06:00', '서머타임 시작일');
  assert.match(shortDateTime('2026-10-13T09:53:00Z', 'Asia/Seoul'), /^\d{2}\/\d{2}\/\d{2} \d{2}:\d{2}$/);
  assert.equal(shortDateTime('', 'Asia/Seoul'), '');
  assert.equal(shortDateTime('not a date', 'Asia/Seoul'), '');
});
