// 관리자 도구·워커·점검 화면 계산(P20A): node --test "tests/js/*.test.mjs"
import test from 'node:test';
import assert from 'node:assert/strict';
import {
  toolBadges, selectionLine, toolActions, pinNeedsVerification, workerBadge, workerButtons, stopConfirm, beatAge,
  diskLevel, diskCategories, stagingView, taskBadge, elapsed,
} from '../../public/assets/js/lib/admin_core.js';
import { has } from '../../public/assets/js/core/i18n.js';

const v = (over = {}) => ({
  version: '1.0.0', on_disk: true, status: 'active', supported: true, fingerprint_changed: false, removal_requested_at: null, removed_at: null,
  selected: false, unused: false, verify_task: null, smoke_ok: 1, ...over,
});

test('도구 상태: 아이콘 + 글자(색에만 의존하지 않음), 모든 키가 있음', () => {
  assert.deepEqual(toolBadges(v({ selected: true })).map((b) => b.key), ['admin_tools.state.selected']);
  assert.deepEqual(toolBadges(v({ status: 'pinned', selected: true })).map((b) => b.icon), ['system.ok', 'tool_status.pinned']);
  assert.deepEqual(toolBadges(v({ status: 'invalid', unused: true })).map((b) => b.key), ['admin_tools.state.invalid', 'admin_tools.state.unused']);
  assert.deepEqual(toolBadges(v({ status: 'blocked', unused: true })).map((b) => b.key), ['admin_tools.state.blocked', 'admin_tools.state.unused']);
  assert.deepEqual(toolBadges(v({ removal_requested_at: 'x', unused: true })).map((b) => b.key), ['admin_tools.state.removal']);
  assert.deepEqual(toolBadges(v({ removed_at: 'x' })).map((b) => b.key), ['admin_tools.state.removed']);
  assert.deepEqual(toolBadges(v({ status: 'new', verify_task: { status: 'running' } })).map((b) => b.key), ['admin_tools.state.verify_running', 'admin_tools.state.new']);
  assert.deepEqual(toolBadges(v({ supported: false, unused: true })).map((b) => b.key), ['admin_tools.state.unsupported', 'admin_tools.state.unused']);
  assert.deepEqual(toolBadges(v({ fingerprint_changed: true })).map((b) => b.key), ['admin_tools.state.changed']);
  for (const over of [{}, { status: 'pinned' }, { status: 'invalid' }, { status: 'blocked' }, { removal_requested_at: 'x' }, { removed_at: 'x' }, { status: 'new' },
    { verify_task: { status: 'queued' } }, { supported: false }, { unused: true }]) {
    for (const b of toolBadges(v(over))) {
      assert.ok(b.icon && has(b.key), `${b.key} 없음`);
    }
  }
});

test('선택 이유 한 줄과 도구 동작', () => {
  for (const reason of ['pinned', 'latest', 'fallback', 'pinned_unusable', 'pending', 'none']) {
    const s = selectionLine('ruff', { reason, version: '1.0.0', newest: '1.1.0', pending: '1.1.0' });
    assert.ok(has(s.key), s.key);
  }
  assert.equal(selectionLine('ruff', { reason: 'fallback', version: '1.0.0', newest: '1.1.0' }).params.newest, '1.1.0');
  assert.deepEqual(toolActions(v()), ['pin', 'block', 'verify', 'mark_removal']);
  assert.deepEqual(toolActions(v({ status: 'pinned' })), ['unpin', 'verify']);
  assert.deepEqual(toolActions(v({ status: 'blocked' })), ['unblock', 'mark_removal']);
  assert.deepEqual(toolActions(v({ removal_requested_at: 'x' })), ['block', 'unmark_removal']);
  assert.deepEqual(toolActions(v({ verify_task: { status: 'queued' } })), ['pin', 'block', 'mark_removal'], '검증 중에는 재검증 버튼 없음');
  assert.deepEqual(toolActions(v({ on_disk: false })), []);
  assert.deepEqual(toolActions(v({ supported: false })), ['block', 'mark_removal'], '지원하지 않는 주 버전은 고정·재검증 없음');
  assert.deepEqual(toolActions(v({ status: 'invalid' })), ['block', 'verify', 'mark_removal'], '검증 실패 버전은 고정 버튼 없음');
  assert.deepEqual(toolActions(v({ status: 'new' })), ['block', 'verify', 'mark_removal'], '검증 전 버전은 재검증 먼저');
  assert.equal(pinNeedsVerification(v()), false);
  assert.equal(pinNeedsVerification(v({ status: 'new' })), true);
  assert.equal(pinNeedsVerification(v({ fingerprint_changed: true })), true);
});

test('워커 상태·버튼', () => {
  for (const s of ['running', 'stopping', 'stopped', 'unresponsive']) assert.ok(has(workerBadge(s).key), s);
  const base = { state: 'running', supervised: false, stop_request: null, running_analyses: [{}, {}], start: { configured: true, available: false, reason: 'already_running' } };
  assert.deepEqual(workerButtons(base), { stop: true, restart: false, restartReason: 'not_supervised', startVisible: true, start: false, startReason: 'already_running' });
  assert.equal(workerButtons({ ...base, supervised: true }).restart, true);
  assert.equal(workerButtons({ ...base, stop_request: { kind: 'stop' } }).stop, false, '이미 정지 요청을 보냄');
  const stopped = { ...base, state: 'stopped', start: { configured: true, available: true, reason: null } };
  assert.deepEqual([workerButtons(stopped).stop, workerButtons(stopped).start], [false, true]);
  assert.equal(workerButtons({ ...stopped, start: { configured: false, available: false, reason: 'not_configured' } }).startVisible, false, '비어 있으면 숨김');
  assert.equal(workerButtons({ ...stopped, start: { configured: true, available: false, reason: 'proc_open_disabled' } }).startVisible, false, 'proc_open 비활성이면 숨김');
  assert.deepEqual(stopConfirm(base, 'stop'), { key: 'admin_worker.confirm_stop', params: { n: 2 } }, 'supervised=false: 다시 시작해야 한다는 경고 문구');
  assert.equal(stopConfirm({ ...base, supervised: true }, 'stop').key, 'admin_worker.confirm_stop_supervised');
  assert.equal(stopConfirm(base, 'restart').key, 'admin_worker.confirm_restart');
  assert.equal(beatAge(null, 180).key, 'admin_worker.beat_none');
  assert.equal(beatAge(5, 180).key, 'admin_worker.beat_ok');
  assert.equal(beatAge(200, 180).key, 'admin_worker.beat_stale');
  assert.deepEqual(elapsed(3725), { key: 'admin.elapsed_h', params: { h: 1, m: 2 } });
  assert.deepEqual(elapsed(65), { key: 'admin.elapsed_m', params: { m: 1, s: 5 } });
});

test('디스크·스테이징·작업 표시', () => {
  assert.equal(diskLevel(50, 80, 90).tone, 'ok');
  assert.equal(diskLevel(85, 80, 90).tone, 'warn');
  assert.equal(diskLevel(90, 80, 90).tone, 'error');
  assert.equal(diskLevel(null, 80, 90).key, 'admin_checks.disk_unknown');
  const rows = diskCategories({ logs: { bytes: 100, files: 1 }, snapshots: { bytes: 300, files: 2 }, db: { bytes: 0, files: 0 } });
  assert.deepEqual(rows.map((r) => [r.key, r.percent]), [['snapshots', 75], ['logs', 25], ['db', 0]]);
  assert.deepEqual(stagingView({ status: 'staged', hash_match: null, install_command: 'php tools/tools_ctl.php install --staging=x' }).showCommand, true);
  assert.deepEqual(stagingView({ status: 'staged', hash_match: false, install_command: null }), { status: 'staged', hash: 'mismatch', tone: 'error', showCommand: false });
  assert.equal(stagingView({ status: 'installed', hash_match: true, install_command: null }).tone, 'ok');
  for (const s of ['queued', 'running', 'succeeded', 'failed']) assert.ok(has(taskBadge({ status: s }).key), s);
  assert.equal(taskBadge(null), null);
});
