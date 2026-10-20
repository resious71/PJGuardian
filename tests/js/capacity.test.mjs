// 용량 추정(P32B, 명세 19.3) — public/assets/js/lib/capacity.js. 기대값은 docs/server_sizing.md의 식으로 손으로 계산한 값.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { estimateCapacity, MEASURED, ASSUMED } from '../../public/assets/js/lib/capacity.js';

const MB = 1024 ** 2;
const settings = { maxConcurrent: 2, snapshotKeep: 5, runsKept: 15, auditDays: 90, warnPercent: 80, backupSnapshots: true };

test('문서 예시(프로젝트 10 × 결함 1만, zip 50MB, 백업 7개)', () => {
  const r = estimateCapacity({ projects: 10, defectsPerProject: 10000, uploadMb: 50, backupsKept: 7 }, settings);
  assert.equal(r.work, 2 * (50 * MB + 200 * MB)); // 동시 2 × (zip + 압축 해제 4배)
  assert.equal(r.snapshots, 10 * 5 * 50 * MB);
  assert.equal(r.db, 100000 * 560 * 1.2 + 10 * 15 * 2048 + 90 * 1000 * 412);
  assert.equal(r.logs, 1024 ** 3);
  assert.equal(r.dataTotal, 4324057024);
  assert.equal(r.dataDisk, 5405071280); // ÷ 0.8(경고 80% 아래)
  assert.equal(r.backupDisk, 7 * (r.db + r.snapshots));
  assert.deepEqual([MEASURED.bytesPerDefect, MEASURED.bytesPerAuditRow, ASSUMED.expandRatio], [560, 412, 4]);
});

test('설정이 결과에 반영(경고 비율·스냅샷 미포함 백업)', () => {
  const base = { projects: 3, defectsPerProject: 1000, uploadMb: 10, backupsKept: 2 };
  const a = estimateCapacity(base, { ...settings, warnPercent: 100, backupSnapshots: false });
  assert.equal(a.dataDisk, a.dataTotal);
  assert.equal(a.backupDisk, 2 * a.db);
  const b = estimateCapacity(base, { ...settings, maxConcurrent: 4 });
  assert.equal(b.work, 2 * estimateCapacity(base, settings).work);
});

test('입력이 커지면 결과도 커지고, 잘못된 값은 0으로', () => {
  const small = estimateCapacity({ projects: 1, defectsPerProject: 100, uploadMb: 1, backupsKept: 1 }, settings);
  const big = estimateCapacity({ projects: 100, defectsPerProject: 100000, uploadMb: 500, backupsKept: 30 }, settings);
  for (const k of ['work', 'snapshots', 'db', 'dataDisk', 'backupDisk']) assert.ok(big[k] > small[k], k);
  const bad = estimateCapacity({ projects: -5, defectsPerProject: 'x', uploadMb: NaN, backupsKept: null }, settings);
  assert.deepEqual([bad.snapshots, bad.backupDisk], [0, 0]);
  assert.ok(Number.isFinite(bad.dataDisk) && bad.dataDisk > 0, '로그·감사 로그 몫은 남음');
});
