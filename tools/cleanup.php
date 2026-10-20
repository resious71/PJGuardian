<?php

declare(strict_types=1);

/**
 * 정리 작업(명세 5.4, 14). 스케줄러(Windows 작업 스케줄러·cron·systemd 타이머)가 매일 부른다(docs/worker_setup.md).
 * 항목은 단계마다 추가한다(CleanupService).
 *   P12: 완료되지 않은 업로드 조각 — 마지막 활동 후 upload.incomplete_chunk_retention(24시간) 지나면 삭제(상태 aborted)
 *   P13: 실패·취소 업로드의 zip 1일(upload.failed_zip_retention) 뒤 삭제, 확인 대기 24시간(upload.confirmation_retention) 초과 취소,
 *        비정상 종료로 남은 작업 디렉토리 정리
 *   P14: 죽은 워커의 작업 되돌리기(생존 신호 기준, 신호 파일이 없으면 24시간 안전망), 분석이 실패·취소된 업로드의 zip 1일 뒤 삭제
 *        (성공한 분석의 남은 zip은 바로), 정리 대상 목록(work/cleanup_pending.jsonl) 재시도, 작업 디렉토리 휴지통 비우기,
 *        에러·접근 로그 보존 기간(log.error_access_retention) 지난 파일 삭제
 *   P20A: 도구 설치 패키지 스테이징 보관 기간(tools.staging_retention) 지난 것 삭제. 실행 결과를 <work>/admin/cleanup_last.json에 기록
 *         (관리자 점검 화면이 읽음). 관리자 화면의 "지금 정리 실행"은 같은 작업을 워커 자식(tools/run_admin_task.php)으로 한다.
 *   P30: 감사 로그 보존 기간(log.audit_retention, 기본 90일)이 지난 행을 log.audit_purge_batch행씩 지우고 해시 체인 기준점을 옮긴다(audit.purge 기록).
 *        --dry-run은 지울 건수만 보인다. 경고 로그(logs/alert-*.log)도 앱 로그와 같은 보존 기간으로 지운다.
 *
 * 사용법: php tools/cleanup.php [--dry-run] [--config=<config.ini>]
 * 종료 코드: 성공 0, 오류 2
 */

require dirname(__DIR__) . '/src/bootstrap.php';

use PJGuardian\App;
use PJGuardian\Services\Admin\SystemChecks;
use PJGuardian\Support\Config;
use PJGuardian\Support\SystemClock;

$opt = getopt('', ['dry-run', 'config:']);
$dry = isset($opt['dry-run']);

try {
    $config = isset($opt['config']) ? Config::load((string) $opt['config']) : null;
    $app = App::create(PJG_ROOT, $config);
    $r = $app->cleanup()->runAll($dry);
    $verb = $dry ? '삭제 예정' : '삭제';
    printf("워커 판정: %s, 되돌린 업로드 후처리 %d개, 실패 처리한 분석 %d개\n", $r['worker_state'], $r['stuck_extractions'], $r['recovered_analyses']);
    printf("미완료 업로드 조각: 만료 %d개, DB 행 없는 폴더 %d개 %s, 유지 %d개\n", $r['expired_uploads'], $r['orphan_dirs'], $verb, $r['kept']);
    printf("실패·취소 업로드·분석의 zip: %d개 %s\n", $r['failed_zips'], $verb);
    printf("확인 대기 시간 초과: %d개 취소\n", $r['expired_confirmations']);
    printf("작업 디렉토리: 남은 디렉토리 %d개 %s, 휴지통 %d개 %s\n", $r['orphan_work_dirs'], $verb, $r['trash_emptied'], $verb);
    printf("정리 대상 목록: %d개 처리, %d개 남음\n", $r['pending_removed'], $r['pending_left']);
    printf("로그: 보존 기간 지난 파일 %d개 삭제%s\n", $r['logs_deleted'], $dry ? ' (dry-run은 로그를 지우지 않음)' : '');
    printf("분석 결과 파일·결함 임시 저장소(1일 지난 것): %d개 %s\n", $r['results_deleted'], $verb);
    printf("스냅샷 정리: %d개(발췌 %d건 저장)%s\n", $r['snapshots_pruned'], $r['excerpts_saved'], $dry ? ' (dry-run은 정리하지 않음)' : '');
    printf("도구 패키지 스테이징(보관 기간 지난 것): %d개 %s\n", $r['staging_purged'], $verb);
    printf("승인 대기·거절 계정(보관 기간 지난 것): %d개 %s\n", $r['pending_accounts_purged'], $verb);
    // P30 감사 로그 보존(log.audit_retention): 앞에서부터 묶음으로 지우고 해시 체인 기준점을 옮긴다(audit.purge 기록)
    printf("감사 로그(보존 기간 지난 것): %d건 %s%s\n", $r['audit_purged'], $verb, $r['audit_through_at'] !== null ? " — {$r['audit_through_at']}까지" : '');
    if (!$dry) {
        // 관리자 점검 화면의 "마지막 실행"(P20A). 서버 경로 없음
        SystemChecks::recordCleanup($app->paths->work(), SystemClock::iso($app->clock->now()), 'cli', true, $r);
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(2);
}
