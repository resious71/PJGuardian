// 백업·복원(P27B) 화면 로직: 검증 상태·복원/삭제 가능, 요약 경고(아이콘 + 문구, 순서), 진행 문구, 설정 차이 기본 선택(PC 고유 해제),
// 복원 요청 본문(설정 복원 기본 끔), 건수 비교·손실 문구, 복원 상태·단계, 백업 알림 문구, 모든 오류 코드의 화면 문구.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  verifyState, canRestore, canDelete, summaryWarnings, jobText, jobPercent, defaultChecked, restoreBody, countRows, lossText,
  restoreStatusView, stepRows, isFinal, validBundleName, errorMessage, RESTORE_STEPS,
} from '../../public/assets/js/lib/backup_core.js';
import { notificationText, kindIcon, KINDS } from '../../public/assets/js/lib/notification_core.js';
import { has } from '../../public/assets/js/core/i18n.js';

test('검증 상태: 통과·실패·올린 뒤 미검증(복원 불가)·기록 없음(폴더에 직접 복사)', () => {
  assert.equal(verifyState({ verified: { ok: true } }).state, 'ok');
  assert.equal(verifyState({ verified: { ok: false, code: 'bundle_hash_mismatch' } }).state, 'failed');
  assert.equal(verifyState({ verified: null, uploaded: true }).state, 'needs_verify');
  assert.equal(verifyState({ verified: null, uploaded: false }).state, 'unverified');
  assert.equal(canRestore({ verified: { ok: true } }), true);
  assert.equal(canRestore({ verified: null, uploaded: true }), false, '올린 묶음은 검증 뒤에만');
  assert.equal(canRestore({ verified: { ok: false } }), false);
  assert.equal(canRestore({ verified: null }), true, '직접 복사한 묶음은 복원 때 사전 검증');
  assert.equal(canDelete({ name: 'a' }, 'a'), false, '마지막 성공 백업은 보호');
  assert.equal(canDelete({ name: 'b' }, 'a'), true);
  for (const s of [{ verified: { ok: true } }, { verified: { ok: false } }, { uploaded: true }, {}]) {
    const v = verifyState(s);
    assert.ok(v.icon && has(v.key), '아이콘 + 문구(색에만 의존하지 않음)');
  }
});

test('요약 경고: 폴더 오류·마지막 실패·백업 없음/오래됨·리허설 기한·유지보수 오래됨·같은 볼륨·옛 설정·워커 멈춤', () => {
  const base = { dir: 'backups', dir_check: 'ok', last_success: { at: '2026-10-09T00:00:00Z' }, stale: false, rehearsal_due: false, inside_project: false, worker_running: true };
  assert.deepEqual(summaryWarnings(base), []);
  const ids = (d) => summaryWarnings({ ...base, ...d }).map((w) => w.id);
  assert.deepEqual(ids({ inside_project: true }), ['same_volume'], '기본 backups/는 항상 같은 볼륨 경고');
  assert.deepEqual(ids({ last_success: null }), ['none']);
  assert.deepEqual(ids({ stale: true }), ['stale']);
  assert.deepEqual(ids({ rehearsal_due: true, worker_running: false }), ['rehearsal_due', 'worker']);
  assert.deepEqual(ids({ last_failure: { at: '2026-10-09T01:00:00Z', code: 'backup_disk_low', params: { need_mb: 10, free_mb: 1 } } }), ['last_failed']);
  assert.deepEqual(ids({ last_failure: { at: '2026-10-08T01:00:00Z', code: 'backup_io_failed' } }), [], '성공보다 오래된 실패는 경고하지 않음');
  const dir = summaryWarnings({ ...base, dir: 'D:/x', dir_check: 'fail_not_writable' });
  assert.equal(dir[0].id, 'dir_invalid');
  assert.equal(dir[0].tone, 'error');
  assert.ok(dir[0].text.includes('D:/x'), '사유에 경로');
  assert.deepEqual(ids({ dir_check: 'warn_same_volume' }), ['same_volume']);
  assert.deepEqual(ids({ legacy_backups_dir: 'E:/old' }), ['legacy']);
  assert.deepEqual(ids({ maintenance_stale: true }), ['maintenance_stale']);
  const failed = summaryWarnings({ ...base, last_failure: { at: '2026-10-09T01:00:00Z', code: 'backup_disk_low', params: { need_mb: 10, free_mb: 1 } } })[0];
  assert.ok(failed.text.includes('10MB') && failed.text.includes('1MB'), '오류 문구 자리 채우기');
});

test('진행 문구·진행률', () => {
  assert.equal(jobText(null), '');
  assert.match(jobText({ type: 'backup', started: false }), /요청됨/);
  assert.match(jobText({ type: 'rehearsal', started: true }), /리허설/);
  assert.match(jobText({ type: 'backup', started: true, cancelling: true }), /취소/);
  assert.equal(jobText({ type: 'backup', started: true, progress: { step: 'snapshots', done: 50, total: 200 } }), '백업 중: 스냅샷 복사 25%');
  assert.equal(jobText({ type: 'backup', started: true, progress: { step: 'zip', done: 0, total: 0 } }), '백업 중: 묶음 만들기…');
  assert.equal(jobPercent({ progress: { done: 5, total: 0 } }), null, '셀 수 없는 단계');
  assert.equal(jobPercent({ progress: { done: 300, total: 200 } }), 100);
});

test('설정 차이 기본 선택과 복원 요청 본문(설정 복원 기본 끔, 키는 고를 때만)', () => {
  const diff = [
    { file: 'local', key: 'backup.dir', machine: true },
    { file: 'local', key: 'retention.audit_days', machine: false },
    { file: 'config', key: 'app.base_url', machine: true },
    { file: 'config', key: 'worker.max_concurrent', machine: false },
  ];
  assert.deepEqual(diff.map(defaultChecked), [false, true, false, true], 'PC 고유(backup.dir 포함)는 기본 해제');
  const checked = new Set(diff.filter(defaultChecked).map((d) => `${d.file}:${d.key}`));
  const off = restoreBody({ name: 'n', password: 'p', confirm: '복원', waitMode: 'wait', withConfig: false, checked, diff, key: null });
  assert.deepEqual(off.config_keys, []);
  assert.deepEqual(off.local_keys, []);
  assert.equal('key' in off, false);
  const on = restoreBody({ name: 'n', password: 'p', confirm: '복원', waitMode: 'cancel', withConfig: true, checked, diff, key: { content: '{}', passphrase: 'x' } });
  assert.deepEqual(on.local_keys, ['retention.audit_days']);
  assert.deepEqual(on.config_keys, ['worker.max_concurrent']);
  assert.equal(on.wait_mode, 'cancel');
  assert.deepEqual(on.key, { content: '{}', passphrase: 'x' });
  assert.equal(restoreBody({ waitMode: 'other', checked: new Set() }).wait_mode, 'wait');
});

test('건수 비교·손실 문구', () => {
  const rows = countRows({ users: 11, projects: 3, analyses: 40, defects: 900 }, { counts: { users: 12, projects: 3, analyses: 45, defects: 950 } });
  assert.deepEqual(rows.map((r) => r.key), ['users', 'projects', 'analyses', 'defects']);
  assert.deepEqual(rows[0], { key: 'users', backup: 11, current: 12, delta: -1 });
  assert.equal(countRows({ users: 1 }, null)[0].current, null, '지금 DB를 못 읽으면 —');
  assert.equal(lossText({ loss: { analyses: 5, defect_changes: 1234, accounts: 1 } }), '이 시각 이후의 변경은 사라집니다: 이후 분석 5건·결함 변경 1,234건·계정 변경 1건');
  assert.match(lossText({ loss: { analyses: 0, defect_changes: 0, accounts: 0 } }), /없습니다/);
  assert.match(lossText({ loss: null }), /셀 수 없습니다/);
});

test('복원 상태·단계·끝 판정', () => {
  for (const s of [null, 'requested', 'running', 'succeeded', 'failed', 'rolled_back', 'rollback_failed']) {
    const v = restoreStatusView(s);
    assert.ok(v.icon && has(v.key), String(s));
  }
  const rows = stepRows({ steps: [{ name: 'precheck', status: 'done' }, { name: 'maintenance', status: 'done' }, { name: 'pre_restore_backup', status: 'running' }] });
  assert.deepEqual(rows.map((r) => r.status), ['done', 'done', 'running', 'pending', 'pending', 'pending', 'pending']);
  for (const n of RESTORE_STEPS) assert.ok(has(`restore_status.step.${n}`), n);
  assert.equal(isFinal({ status: 'succeeded', maintenance: false }), true);
  assert.equal(isFinal({ status: 'succeeded', maintenance: true }), false, '플래그가 풀릴 때까지 계속 확인');
  assert.equal(isFinal({ status: 'running' }), false);
});

test('묶음 이름 규칙(서버 BundleFormat::validName과 같음)', () => {
  assert.equal(validBundleName('pjguardian-20261009-023000-daily.pjgbak'), true);
  assert.equal(validBundleName('pjguardian-20261009-023000-manual-2.pjgbak'), true);
  assert.equal(validBundleName('pjguardian-20261009-023000-other.pjgbak'), false);
  assert.equal(validBundleName('../pjguardian-20261009-023000-daily.pjgbak'), false);
  assert.equal(validBundleName('x.zip'), false);
});

test('오류 코드 문구: 서버의 모든 backup.error.* 코드가 화면 문구(backups.err.*)에도 있고 자리를 채운다', () => {
  const php = readFileSync(new URL('../../src/Lang/ko.php', import.meta.url), 'utf8');
  const codes = [...php.matchAll(/'backup\.error\.([a-z_]+)'/g)].map((m) => m[1]);
  assert.ok(codes.length >= 40);
  for (const c of codes) assert.ok(has(`backups.err.${c}`), c);
  assert.equal(errorMessage('restore_schema_newer', { bundle: '030', app: '024' }), '더 새로운 버전의 백업입니다(백업 스키마 030, 앱 024). 앱을 먼저 업그레이드하세요.');
  assert.equal(errorMessage('unknown_code_x'), 'unknown_code_x', '모르는 코드는 코드 그대로');
  assert.equal(errorMessage(null), '');
});

test('백업 알림 3종: 종류·설명·문구·아이콘', () => {
  for (const k of ['backup_failed', 'backup_done', 'restore_rehearsal_due']) {
    assert.ok(KINDS.includes(k), k);
    assert.ok(has(`notif.kind.${k}`) && has(`notif.kind_desc.${k}`) && has(`notif.text.${k}`), k);
  }
  assert.equal(notificationText({ kind: 'backup_failed', params: { code: 'backup_io_failed' } }), '백업이 실패했습니다: 백업 파일을 쓰지 못했습니다(백업 폴더 권한·여유 공간 확인).');
  assert.equal(notificationText({ kind: 'backup_failed', params: { code: 'zzz' } }), '백업이 실패했습니다: zzz');
  assert.equal(notificationText({ kind: 'backup_done', params: { file: 'pjguardian-20261009-023000-manual.pjgbak' } }), '백업이 끝났습니다: pjguardian-20261009-023000-manual.pjgbak');
  assert.match(notificationText({ kind: 'restore_rehearsal_due', params: { days: 90 } }), /90일/);
  assert.equal(kindIcon({ kind: 'backup_failed' }), 'system.error');
  assert.equal(kindIcon({ kind: 'backup_done' }), 'system.ok');
  assert.equal(kindIcon({ kind: 'restore_rehearsal_due' }), 'system.warn');
});

test('P27E 주기 문구·디스크 추정·꺼짐 정보 줄', async () => {
  const { scheduleText, diskEstimate, estimateText, summaryEstimateInput } = await import('../../public/assets/js/lib/backup_core.js');
  assert.equal(scheduleText({ mode: 'daily', at: '02:30' }), '매일 02:30');
  assert.equal(scheduleText({ mode: 'weekly', at: '02:30', weekly_day: 'sun' }), '매주 일요일 02:30');
  assert.equal(scheduleText({ mode: 'monthly', at: '02:30', monthly_day: '1' }), '매월 1일 02:30');
  assert.equal(scheduleText({ mode: 'monthly', at: '04:00', monthly_day: 'last' }), '매월 말일 04:00');
  const MB = 1024 * 1024;
  const base = { enabled: true, retentionDays: 60, minCount: 3, maxCount: 0, lastSize: 36 * MB, free: 10 * 1024 * MB };
  assert.equal(diskEstimate({ ...base, mode: 'daily' }).count, 60);
  assert.equal(diskEstimate({ ...base, mode: 'weekly' }).count, 9);
  assert.equal(diskEstimate({ ...base, mode: 'monthly' }).count, 3, '매월 60일 = 2개지만 최소 3개');
  assert.equal(diskEstimate({ ...base, mode: 'daily', maxCount: 10 }).count, 10, '상한');
  assert.equal(diskEstimate({ ...base, mode: 'daily', maxCount: 2 }).count, 3, '상한보다 최소 보관이 앞섬');
  assert.equal(diskEstimate({ ...base, mode: 'daily' }).bytes, 60 * 36 * MB);
  assert.equal(diskEstimate({ ...base, mode: 'daily' }).warn, false, '2.1GB < 10GB의 절반');
  const tight = diskEstimate({ ...base, mode: 'daily', free: 4 * 1024 * MB });
  assert.equal(tight.warn, true, '2.1GB > 4GB의 절반');
  assert.match(estimateText(tight), /절반을 넘습니다/);
  assert.equal(estimateText(diskEstimate({ ...base, mode: 'weekly' })), '자동 백업 약 9개 보관 시 약 324.0MB');
  assert.equal(diskEstimate({ ...base, mode: 'daily', enabled: false }), null, '꺼짐');
  assert.equal(diskEstimate({ ...base, mode: 'daily', lastSize: null }), null, '크기 모름');
  // 요약 경고: 꺼지면 "없음·오래됨" 대신 정보 한 줄, 켜진 상태의 경고는 그대로, 추정이 넘치면 경고
  const sched = (over = {}) => ({ enabled: true, mode: 'daily', stale_hours: 36, retention_min_count: 3, retention_max_count: 0, ...over });
  const d = { dir: 'x', dir_check: 'ok', worker_running: true, retention_days: 60, dir_free_bytes: 10 * 1024 * MB };
  const ids = (x) => summaryWarnings({ ...d, ...x }).map((w) => `${w.id}:${w.tone}`);
  assert.deepEqual(ids({ schedule: sched({ enabled: false }), last_success: null }), ['auto_off:info']);
  assert.deepEqual(ids({ schedule: sched({ enabled: false }), last_success: { at: 'x', size: MB }, stale: true }), ['auto_off:info']);
  assert.deepEqual(ids({ schedule: sched(), last_success: null }), ['none:warn']);
  assert.deepEqual(ids({ schedule: sched(), last_success: { at: 'x', size: MB }, stale: true }), ['stale:warn']);
  assert.match(summaryWarnings({ ...d, schedule: sched({ stale_hours: 192 }), last_success: { at: 'x', size: MB }, stale: true })[0].text, /192시간/);
  assert.deepEqual(ids({ schedule: sched(), last_success: { at: 'x', size: 200 * MB } }), ['disk_estimate:warn'], '200MB × 60 = 11.7GB > 5GB');
  assert.equal(summaryEstimateInput({ ...d, schedule: sched({ mode: 'weekly' }), last_success: { size: 5 } }).mode, 'weekly');
});
