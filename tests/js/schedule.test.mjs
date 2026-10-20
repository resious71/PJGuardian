// 주기 분석(P26): 설정 폼 검증(간격·시각), 입력 시각 정리, 저장 본문(개정 번호), 같은 시각 예약 안내, 켜기를 막는 이유(연결 없음 등),
// 저장 뒤 경고, 상태 한 줄(사용 중·N일마다·다음 예정 / 사용 안 함), 연속 실패 경고, 건너뜀 사유, 알림 문구(schedule_failed·건너뜀)와 아이콘.
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  RECOMMENDED_DAYS, parseRunTime, normalizeRunTime, validate, buildBody, sameTimeNote, blockerKey, warningKeys, statusLine, failureLine, lastResultKey, skipReasonKey,
} from '../../public/assets/js/lib/schedule_core.js';
import { notificationText, kindIcon, KINDS } from '../../public/assets/js/lib/notification_core.js';
import { t, has } from '../../public/assets/js/core/i18n.js';

test('실행 시각 형식·정리', () => {
  for (const ok of ['00:00', '03:00', '23:59', ' 12:30 ']) assert.ok(parseRunTime(ok), ok);
  for (const bad of ['24:00', '3:00', '03:60', '0300', '', null, undefined, '03:00:00', 'ab:cd']) assert.equal(parseRunTime(bad), null, String(bad));
  assert.equal(normalizeRunTime('3:5'), '03:05');
  assert.equal(normalizeRunTime('0300'), '03:00');
  assert.equal(normalizeRunTime('7'), '07:00');
  assert.equal(normalizeRunTime(' 23:30 '), '23:30');
  assert.equal(normalizeRunTime('25:00'), '25:00', '고칠 수 없으면 그대로(검증이 막음)');
  assert.equal(normalizeRunTime('abc'), 'abc');
});

test('폼 검증과 저장 본문', () => {
  assert.deepEqual(validate({ enabled: true, interval_days: '7', run_time: '03:00' }), { ok: true, errors: {} });
  assert.deepEqual(validate({ enabled: true, interval_days: '0', run_time: '03:00' }).errors, { interval_days: 'schedule.invalid.interval_days' });
  assert.deepEqual(validate({ enabled: false, interval_days: '366', run_time: '24:00' }).errors,
    { interval_days: 'schedule.invalid.interval_days', run_time: 'schedule.invalid.run_time' }, '끄는 저장도 형식은 맞아야 한다(서버와 같음)');
  for (const bad of ['', ' ', '1.5', 'abc', '-1']) assert.equal(validate({ interval_days: bad, run_time: '03:00' }).ok, false, bad);
  assert.equal(validate({ interval_days: '2', run_time: '03:00' }, { min: 3 }).ok, false, '서버가 준 최소 간격');
  assert.deepEqual(buildBody({ enabled: true, interval_days: ' 7 ', run_time: '03:00' }, 4), { enabled: true, interval_days: 7, run_time: '03:00', revision: 4 });
  assert.deepEqual(buildBody({ enabled: false, interval_days: '14', run_time: '22:30' }, null), { enabled: false, interval_days: 14, run_time: '22:30' }, '처음 저장은 revision 없음');
  assert.equal(RECOMMENDED_DAYS, 7);
});

test('같은 시각 예약 안내·켜기를 막는 이유·저장 뒤 경고', () => {
  assert.equal(sameTimeNote({ '03:00': 2 }, '04:00'), null);
  assert.equal(sameTimeNote({}, '03:00'), null);
  assert.equal(sameTimeNote({ '03:00': 2 }, 'bad'), null);
  assert.equal(sameTimeNote({ '03:00': 2 }, '03:00'), t('schedule.same_time', { time: '03:00', n: '2' }));
  assert.ok(sameTimeNote({ '03:00': 1200 }, '03:00').includes('1,200'));
  assert.equal(blockerKey(null), null);
  for (const b of ['repo_not_configured', 'repo_host_not_allowed', 'repo_secret_unreadable']) assert.equal(blockerKey(b), `schedule.blocker.${b}`);
  assert.equal(blockerKey('other_reason'), 'schedule.blocker.other');
  assert.deepEqual(warningKeys(['connection_error', 'x', 'connection_unchecked']), ['schedule.warn.connection_error', 'schedule.warn.connection_unchecked']);
  assert.deepEqual(warningKeys(undefined), []);
});

test('상태 한 줄·연속 실패 경고·결과·건너뜀 사유', () => {
  const fmt = (iso) => `F(${iso})`;
  assert.equal(statusLine(null, fmt), t('schedule.off'));
  assert.equal(statusLine({ enabled: false }, fmt), t('schedule.off'));
  assert.equal(statusLine({ enabled: true, interval_days: 7, next_run_at: '2026-10-09T18:00:00Z' }, fmt), t('schedule.on_line', { days: 7, next: 'F(2026-10-09T18:00:00Z)' }));
  assert.equal(failureLine({ enabled: true, consecutive_failures: 0 }), null);
  assert.equal(failureLine({ enabled: true }), null, '일반 사용자 요약에는 실패 정보 없음');
  assert.equal(failureLine({ enabled: false, consecutive_failures: 3 }), null);
  const line = failureLine({ enabled: true, consecutive_failures: 2, last_error_title: '저장소 서버에 연결하지 못함' });
  assert.ok(line.includes('2') && line.includes('저장소 서버에 연결하지 못함'), line);
  assert.equal(lastResultKey('failed'), 'schedule.result.failed');
  assert.equal(lastResultKey(null), 'schedule.result.none');
  assert.equal(skipReasonKey('skipped_busy'), 'schedule.skip.skipped_busy');
  assert.equal(skipReasonKey('disk_usage_blocked'), 'schedule.skip.disk_usage_blocked');
  assert.equal(skipReasonKey('weird'), 'schedule.skip.other');
});

test('알림 schedule_failed 문구·아이콘', () => {
  assert.ok(KINDS.includes('schedule_failed'));
  assert.equal(kindIcon({ kind: 'schedule_failed' }), 'source.schedule');
  const project = { name: '<b>앱</b>' };
  const one = notificationText({ kind: 'schedule_failed', project, params: { code: 'repo_unreachable', count: 1, next_run_at: '2026-10-09T18:00:00Z' }, failure: { title: '연결 실패' } });
  assert.ok(one.startsWith('<b>앱</b>: ') && one.includes('연결 실패') && one.includes('2026-10-10 03:00'), one);
  const many = notificationText({ kind: 'schedule_failed', project, params: { code: 'repo_auth_failed', count: 3, next_run_at: null } });
  assert.ok(many.includes('3') && many.includes('repo_auth_failed') && many.includes(t('notif.text.schedule_no_next')), many);
  const skip = notificationText({ kind: 'schedule_failed', project, params: { code: 'disk_usage_blocked', skipped: 'skipped_disk', next_run_at: '2026-10-03T18:00:00Z' } });
  assert.ok(skip.includes(t('schedule.skip.disk_usage_blocked')) && skip.includes('2026-10-04 03:00'), skip);
  assert.ok(notificationText({ kind: 'schedule_failed', project: null, params: {} }).startsWith(t('notif.project_hidden')));
});

test('문구 키가 모두 있다', () => {
  const keys = ['schedule.invalid.interval_days', 'schedule.invalid.run_time', 'schedule.same_time', 'schedule.off', 'schedule.on_line', 'schedule.on_badge', 'schedule.failing',
    'schedule.next_none', 'schedule.failures_n', 'schedule.failures_none', 'schedule.saved_on', 'schedule.saved_off', 'schedule.saved_toast_on', 'schedule.saved_toast_off',
    'schedule.warn.connection_error', 'schedule.warn.connection_unchecked', 'schedule.blocker.other', 'error.schedule_unavailable', 'error.schedule_connection_required',
    'error.schedule_conflict', 'error.invalid_schedule_input', 'error.invalid_trigger_filter', 'notif.kind.schedule_failed', 'notif.kind_desc.schedule_failed',
    'notif.text.schedule_failed', 'notif.text.schedule_failed_many', 'notif.text.schedule_skipped', 'analyses.retry_wait', 'analyses.retry_attempts',
    'admin_schedules.last_tick', 'admin_schedules.delayed', 'admin_schedules.worker_off', 'admin_schedules.retry_wait', 'admin_schedules.failed_item', 'admin_schedules.rerun_at',
    'admin_schedules.skip_item', 'admin_schedules.failures_intro', 'admin_schedules.interval', 'admin_schedules.skips', 'admin_schedules.analysis_link'];
  for (const r of ['succeeded', 'failed', 'cancelled', 'none']) keys.push(`schedule.result.${r}`);
  for (const s of ['skipped_busy', 'repo_not_configured', 'repo_host_not_allowed', 'repo_secret_unreadable', 'disk_usage_blocked', 'insufficient_storage', 'other']) keys.push(`schedule.skip.${s}`);
  for (const b of ['repo_not_configured', 'repo_host_not_allowed', 'repo_secret_unreadable']) keys.push(`schedule.blocker.${b}`);
  for (const k of keys) assert.ok(has(k), k);
});
