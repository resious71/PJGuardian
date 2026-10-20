// 권장 디스크 용량 추정(P32B, 명세 19.3) — 순수 함수(서버 상태를 바꾸지 않음). 시스템 관리 > 점검의 "용량 추정 도우미"와
// docs/server_sizing.md의 계산식·예시가 이 함수 하나를 쓴다(시험 tests/js/capacity.test.mjs).
//
// 데이터 디스크 = (분석 작업 공간 + 스냅샷 + DB + 로그) ÷ 디스크 경고 비율(경고선 아래에서 운영)
//   분석 작업 공간 = 동시 분석 수 × (업로드 zip + 압축 해제 크기)          ← 명세 19.3 식의 앞부분
//   스냅샷        = 프로젝트 수 × 전체 스냅샷 보관 회수 × 업로드 zip       ← 스냅샷은 분석 대상 파일의 zip(업로드보다 작거나 같음 — 위쪽 추정)
//   DB            = 결함 × 결함당 바이트 × (1 + 이력·존재 구간 여유) + Run 요약 + 감사 로그(보존 일수 × 하루 행 수 × 행당 바이트)
//   로그          = 고정 여유
// 백업 디스크(다른 디스크 권장) = 백업 보관 수 × (DB + 스냅샷 포함이면 스냅샷)
//
// 실측: 결함 1건 약 560바이트(P08, 인덱스 포함), 감사 로그 100만 행 393MB(P30, 행당 약 412바이트).
// 추정(실측 아님 — 화면·문서에 "추정"으로 표시): 압축 해제 = zip × 4, 이력·존재 구간 여유 20%, Run 요약 2KB, 감사 로그 하루 1,000행, 로그 1GB.

export const MEASURED = Object.freeze({ bytesPerDefect: 560, bytesPerAuditRow: 412 });
export const ASSUMED = Object.freeze({ expandRatio: 4, historyFactor: 0.2, bytesPerRunSummary: 2048, auditRowsPerDay: 1000, logBytes: 1024 ** 3 });

const MB = 1024 ** 2;

/**
 * @param {{projects: number, defectsPerProject: number, uploadMb: number, backupsKept: number}} input 사용자가 넣는 값
 * @param {{maxConcurrent: number, snapshotKeep: number, runsKept: number, auditDays: number, warnPercent: number, backupSnapshots: boolean}} settings 지금 설정
 * @returns {{work: number, snapshots: number, db: number, logs: number, dataTotal: number, dataDisk: number, backupDisk: number}} 바이트
 */
export function estimateCapacity(input, settings) {
  const n = (v, min = 0) => (Number.isFinite(Number(v)) ? Math.max(min, Number(v)) : min);
  const projects = n(input.projects);
  const defects = projects * n(input.defectsPerProject);
  const upload = n(input.uploadMb) * MB;
  const work = n(settings.maxConcurrent, 1) * (upload + upload * ASSUMED.expandRatio);
  const snapshots = projects * n(settings.snapshotKeep) * upload;
  const db = defects * MEASURED.bytesPerDefect * (1 + ASSUMED.historyFactor)
    + projects * n(settings.runsKept) * ASSUMED.bytesPerRunSummary
    + n(settings.auditDays) * ASSUMED.auditRowsPerDay * MEASURED.bytesPerAuditRow;
  const logs = ASSUMED.logBytes;
  const dataTotal = work + snapshots + db + logs;
  const ratio = Math.min(1, Math.max(0.1, n(settings.warnPercent, 10) / 100));
  const backupDisk = n(input.backupsKept) * (db + (settings.backupSnapshots ? snapshots : 0));
  return { work, snapshots, db, logs, dataTotal, dataDisk: Math.ceil(dataTotal / ratio), backupDisk };
}
