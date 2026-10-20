<?php

declare(strict_types=1);

namespace PJGuardian\Support;

/**
 * 설정 키의 형식과 허용 범위. config.ini의 모든 키는 여기 등록되어 있어야 한다(모르는 키는 오류).
 *
 * 형식(type)
 *   size     : 크기 문자열("8MB", "2GB") 또는 바이트 정수 → 바이트(int)
 *   duration : 기간 문자열("30m", "4h", "60d") 또는 초 정수 → 초(int)
 *   int, percent(0~100), bool("true"/"false")
 *   string, enum(values), list(쉼표 구분 → list<string>)
 *   url(http/https), ldap_url, email_list, ip_list(IP 또는 CIDR), timezone, semver, ratio(0~1 실수), time(HH:MM), datetime(YYYY-MM-DD HH:MM, 표시 시간대)
 * 옵션: min, max(size·duration·int), optional(true면 빈 값 허용 → null 또는 빈 목록)
 *
 * 새 키를 추가하면 config.ini.example에도 명세 절 번호 주석과 함께 추가한다(docs/architecture.md).
 */
final class ConfigSchema
{
    private const KB = 1024;
    private const MB = 1024 * 1024;
    private const GB = 1024 * 1024 * 1024;
    private const MIN = 60;
    private const HOUR = 3600;
    private const DAY = 86400;

    /** @return array<string, array<string, array<string, mixed>>> */
    public static function definition(): array
    {
        return [
            'app' => [
                // dev: 개발 전용 화면(/dev/styleguide)과 미정의 아이콘 오류. prod: 운영(기본)
                'env' => ['type' => 'enum', 'values' => ['dev', 'prod']],
                'display_timezone' => ['type' => 'timezone'],
                'base_url' => ['type' => 'url'],
            ],
            'security' => [
                'require_https' => ['type' => 'bool'],
                'hsts_max_age' => ['type' => 'duration', 'min' => 0, 'max' => 2 * 365 * self::DAY],
                // P32B(명세 19.10 P10): 신뢰하는 리버스 프록시(IP·CIDR). 비우면 접속 주소(REMOTE_ADDR)만 — 헤더를 믿지 않음
                'trusted_proxies' => ['type' => 'ip_list', 'optional' => true, 'default' => ''],
            ],
            // P32B 업그레이드 유지보수 모드(명세 19.4): 켜기(직전 DB 백업이 성공해야 켜짐)·안내 문구·사전 공지 예정 시각(표시 시간대)·직전 백업 대기 상한
            'maintenance' => [
                'enabled' => ['type' => 'bool', 'default' => 'false'],
                'message' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'notice_at' => ['type' => 'datetime', 'optional' => true, 'default' => ''],
                'backup_wait' => ['type' => 'duration', 'min' => 30, 'max' => self::HOUR, 'default' => '10m'],
            ],
            'paths' => [
                'db_file' => ['type' => 'string'],
                'uploads_dir' => ['type' => 'string'],
                'work_dir' => ['type' => 'string'],
                'snapshots_dir' => ['type' => 'string'],
                'logs_dir' => ['type' => 'string'],
                'bin_dir' => ['type' => 'string'],
                'backups_dir' => ['type' => 'string'],
            ],
            'database' => [
                // SQLite 잠금 대기(PRAGMA busy_timeout). 웹과 워커가 동시에 쓸 때 즉시 실패하지 않게 한다(명세 2)
                'busy_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 120],
                // WAL 파일이 이보다 크면 워커가 한가할 때 TRUNCATE 체크포인트로 줄인다(P20S, 반영 연결은 자동 체크포인트를 끄고 워커가 분석 사이에 체크포인트)
                'wal_truncate_threshold' => ['type' => 'size', 'min' => 16 * self::MB, 'max' => 16 * self::GB, 'default' => '256MB'],
                // P19C: 체크포인트가 다른 연결의 쓰기를 막는 시간 상한(ms). PASSIVE 뒤 TRUNCATE는 이 시간만 기다리고 안 되면 다음으로 미룬다
                'checkpoint_max_block_ms' => ['type' => 'int', 'min' => 0, 'max' => 60000, 'default' => '2000'],
                // P31 B-2: 웹 요청 연결의 SQLite 자동 체크포인트(페이지 수, 0 = 끔). 큰 반영 직후 웹 요청 하나가 WAL 수백 MB를 혼자 옮기며
                // 수십 초 걸리던 것을 막는다 — 체크포인트는 워커가(분석 사이·한가할 때 idle_checkpoint_interval마다)
                'web_wal_autocheckpoint' => ['type' => 'int', 'min' => 0, 'max' => 1000000, 'default' => '0'],
                'idle_checkpoint_interval' => ['type' => 'duration', 'min' => 60, 'max' => self::DAY, 'default' => '10m'],
                'analysis_cache_mb' => ['type' => 'int', 'min' => 0, 'max' => 4096, 'default' => '128'],
            ],
            'upload' => [
                'max_zip_size' => ['type' => 'size', 'min' => self::MB, 'max' => 16 * self::GB],
                'chunk_size' => ['type' => 'size', 'min' => 256 * self::KB, 'max' => 64 * self::MB],
                'incomplete_chunk_retention' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY],
                'failed_zip_retention' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY],
                'max_concurrent_per_user' => ['type' => 'int', 'min' => 1, 'max' => 20],
                'confirmation_retention' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY, 'default' => '24h'], // P13
                'target_extensions_python' => ['type' => 'list'],
                'target_extensions_javascript' => ['type' => 'list'],
            ],
            'extract' => [
                'max_extracted_size' => ['type' => 'size', 'min' => self::MB, 'max' => 1024 * self::GB],
                'max_files' => ['type' => 'int', 'min' => 1, 'max' => 10_000_000],
                'worker_memory_limit' => ['type' => 'size', 'min' => 128 * self::MB, 'max' => 64 * self::GB, 'default' => '512MB'], // P13 실측: 100만 항목 PHP 최대 172MB
                'default_excludes' => ['type' => 'list'],
            ],
            'analysis' => [
                // 최소 1초: 시험에서 낮춰 주입한다(P14). 운영 기본 4시간
                'timeout' => ['type' => 'duration', 'min' => 1, 'max' => 24 * self::HOUR],
                'max_concurrent' => ['type' => 'int', 'min' => 1, 'max' => 32],
                'process_memory_limit' => ['type' => 'size', 'min' => 256 * self::MB, 'max' => 256 * self::GB],
                'output_limit' => ['type' => 'size', 'min' => self::MB, 'max' => 64 * self::GB],
                // 출력 한도 초과 여유(P15): 분석 시작 전 디스크 여유 판정에 output_limit과 함께 더한다(P14 실측 초과 250~450MB)
                'output_margin' => ['type' => 'size', 'min' => 0, 'max' => 64 * self::GB, 'default' => '512MB'],
                // P19C: 한 파일의 결함이 이보다 많으면 그 파일만 작업 폴더의 임시 SQLite로 정렬·순번(메모리 일정). 이하이면 메모리 경로 그대로
                'file_defect_spill_threshold' => ['type' => 'int', 'min' => 1, 'max' => 10_000_000, 'default' => '20000'],
            ],
            // P19C: 프로젝트 결함 수. 현재 결함(해결 아님)이 warn을 넘으면 관리자 알림·프로젝트 화면 경고, 반영 뒤 max를 넘길 것이 분명하면
            // 분석을 defect_limit_exceeded로 실패(부분 반영 없음). 0 = 경고·제한 없음
            'limits' => [
                'project_defect_warn' => ['type' => 'int', 'min' => 0, 'max' => 100_000_000, 'default' => '200000'],
                'project_defect_max' => ['type' => 'int', 'min' => 0, 'max' => 100_000_000, 'default' => '1000000'],
            ],
            // CLI 워커(P14, 명세 5.2, 19.8). 이미 설치된 config.ini에 없는 섹션이라 모두 기본값을 둔다
            'worker' => [
                // 대기열 확인 간격
                'poll_interval' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '1s'],
                // 취소 요청(cancel_requested) 확인 간격(명세: 약 1초)
                'cancel_check_interval' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '1s'],
                // 생존 신호 기록 간격과, 신호가 끊긴 것으로 보는 시간(가정 10초 / 3분)
                'heartbeat_interval' => ['type' => 'duration', 'min' => 1, 'max' => 5 * self::MIN, 'default' => '10s'],
                'heartbeat_stale' => ['type' => 'duration', 'min' => 2, 'max' => self::HOUR, 'default' => '3m'],
                // 생존 신호 파일이 없을 때(워커를 쓰지 않고 process_upload.php로 처리한 경우)의 안전망(P13의 24시간 규칙)
                'stale_safety' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY, 'default' => '24h'],
                // 업로드 후처리(조립·해제) 동시 처리 수. 분석 슬롯(analysis.max_concurrent)과 따로 센다(가정 1)
                'upload_concurrency' => ['type' => 'int', 'min' => 1, 'max' => 8, 'default' => '1'],
                // 조립·해제 자식 프로세스 상한(분석 타임아웃과 별도, 가정 2시간. P13 실측: 항목 100만 개 해제 216초)
                'extract_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 24 * self::HOUR, 'default' => '2h'],
                // P20A 관리자 화면: 서비스 관리자(NSSM·작업 스케줄러·systemd)가 워커를 다시 띄우는지. false면 "재시작 요청"을 막는다
                'supervised' => ['type' => 'bool', 'default' => 'false'],
                // P20A "시작" 버튼이 실행할 고정 명령(사전 등록 작업 실행). 비우면 버튼을 숨긴다. 셸 없이 인자 배열로 실행
                'start_command' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'start_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 5 * self::MIN, 'default' => '10s'],
                // P20A 관리 작업(도구 재검증·정리 실행·디스크 사용량 계산) 자식 프로세스 상한 [가정 1시간]
                'admin_task_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 24 * self::HOUR, 'default' => '1h'],
                // P20A 로그 보기: 마지막 몇 줄
                'log_tail_lines' => ['type' => 'int', 'min' => 10, 'max' => 2000, 'default' => '200'],
            ],
            'tools' => [
                'node_path' => ['type' => 'string', 'optional' => true],
                'node_min_version' => ['type' => 'semver'],
                // P20A 도구 설치 패키지(zip) 스테이징 크기 한도와, 설치하지 않은 스테이징을 지우기까지의 기간
                'package_max_size' => ['type' => 'size', 'min' => self::MB, 'max' => 16 * self::GB, 'default' => '1GB'],
                'staging_retention' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 365 * self::DAY, 'default' => '7d'],
            ],
            'auth' => [
                'password_min_length' => ['type' => 'int', 'min' => 8, 'max' => 128],
                'password_min_char_classes' => ['type' => 'int', 'min' => 1, 'max' => 4],
                'lockout_max_failures' => ['type' => 'int', 'min' => 1, 'max' => 100],
                'lockout_duration' => ['type' => 'duration', 'min' => self::MIN, 'max' => self::DAY],
                'ip_failure_window' => ['type' => 'duration', 'min' => self::MIN, 'max' => self::DAY],
                'ip_max_failures' => ['type' => 'int', 'min' => 1, 'max' => 10_000],
                'ip_block_duration' => ['type' => 'duration', 'min' => self::MIN, 'max' => self::DAY],
                'session_idle' => ['type' => 'duration', 'min' => self::MIN, 'max' => self::DAY],
                'temp_password_expiry' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY],
                // 세션 마지막 활동 시각 갱신 간격(이 시간 안의 요청은 DB 쓰기를 건너뜀, P10)
                'session_touch_interval' => ['type' => 'duration', 'min' => 0, 'max' => 10 * self::MIN],
                // bcrypt 비용(명세 3). 운영은 10 이상(create_admin 하드닝 점검이 경고). 4~9는 테스트용
                'bcrypt_cost' => ['type' => 'int', 'min' => 4, 'max' => 15],
                // P28B 외부 인증 첫 로그인의 승인 대기 계정: 대기·거절 계정 수 상한(넘으면 새 요청을 받지 않음), 보관 기간(지나면 정리 작업이 지움)
                'pending_max' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '50'],
                'pending_retention_days' => ['type' => 'int', 'min' => 1, 'max' => 365, 'default' => '30'],
            ],
            // 백업·복원(P27B, 명세 14·19.3·19.4). interval·retention은 P27B 전 config.ini 호환용으로만 받는다(읽는 코드 없음 → daily_at·retention_days)
            'backup' => [
                'interval' => ['type' => 'duration', 'min' => self::HOUR, 'max' => 30 * self::DAY, 'default' => '1d'],
                'retention' => ['type' => 'duration', 'min' => self::DAY, 'max' => 3650 * self::DAY, 'default' => '60d'],
                // 백업 폴더(프로젝트 루트 기준 상대 또는 절대·UNC). 기본 프로젝트 바로 아래 backups/(사용자 결정 2026-10-08). 화면 "시스템 관리 > 설정 관리"에서 바꾼다
                'dir' => ['type' => 'path', 'default' => 'backups'],
                // 자동 백업(P27E): 켜기/끄기, 주기 방식(매일·매주·매월), 실행 시각(이름은 P27B 호환으로 daily_at — 세 방식 모두 이 시각), 요일, 날짜(그 달에 없으면 말일)
                'enabled' => ['type' => 'bool', 'default' => 'true'],
                'schedule_mode' => ['type' => 'enum', 'values' => ['daily', 'weekly', 'monthly'], 'default' => 'daily'],
                // 실행 시각(표시 시간대 HH:MM)
                'daily_at' => ['type' => 'time', 'default' => '02:30'],
                'weekly_day' => ['type' => 'enum', 'values' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], 'default' => 'sun'],
                'monthly_day' => ['type' => 'enum', 'values' => [...array_map('strval', range(1, 31)), 'last'], 'default' => '1'],
                'retention_days' => ['type' => 'int', 'min' => 1, 'max' => 3650, 'default' => '60'],
                // 오래돼도 최근 N개는 지우지 않는다
                'retention_min_count' => ['type' => 'int', 'min' => 1, 'max' => 100, 'default' => '3'],
                // 일반 백업 보관 개수 상한(0 = 제한 없음, P27E — 주기가 짧을 때 디스크 보호). 최소 보관 개수·마지막 성공 보호가 앞선다
                'retention_max_count' => ['type' => 'int', 'min' => 0, 'max' => 1000, 'default' => '0'],
                // pre_restore·pre_upgrade 보관 기간
                'safety_retention_days' => ['type' => 'int', 'min' => 1, 'max' => 3650, 'default' => '30'],
                'upload_max_mb' => ['type' => 'int', 'min' => 1, 'max' => 65536, 'default' => '2048'],
                'restore_wait_max_minutes' => ['type' => 'int', 'min' => 1, 'max' => 240, 'default' => '30'],
                'rehearsal_interval_days' => ['type' => 'int', 'min' => 1, 'max' => 3650, 'default' => '90'],
                'backup_stale_hours' => ['type' => 'int', 'min' => 1, 'max' => 24 * 365, 'default' => '36'],
                // 설정 복원 때 기본 체크 해제할 config.ini 키(PC 고유 — 경로·포트·호스트·URL). "섹션.*"는 섹션 전체
                'config_machine_keys' => ['type' => 'list', 'optional' => true, 'default' => 'app.base_url, paths.*, tools.node_path, repository.ca_cert_file, ldap.ca_cert_file, ldap.server_url, smtp.host, smtp.port, health.allowed_ips, monitoring.tls_cert_file, worker.start_command, backup.dir'],
                // 유지보수 플래그가 이보다 오래 남아 있으면 경고(복원 프로세스가 비정상 종료한 흔적)
                'maintenance_stale_minutes' => ['type' => 'int', 'min' => 5, 'max' => 24 * 60, 'default' => '120'],
            ],
            'snapshot' => [
                'full_keep_count' => ['type' => 'int', 'min' => 1, 'max' => 100],
                'excerpt_context_lines' => ['type' => 'int', 'min' => 0, 'max' => 500],
            ],
            // 코드 뷰어 조회 한도(P17, 명세 7·19.6). 모두 [가정] 값이라 기존 config.ini에 없어도 기본값으로 기동한다
            'viewer' => [
                'code_window_lines' => ['type' => 'int', 'min' => 5, 'max' => 5000, 'default' => '200'],
                'code_max_lines' => ['type' => 'int', 'min' => 100, 'max' => 200000, 'default' => '20000'],
                'code_max_line_length' => ['type' => 'int', 'min' => 200, 'max' => 100000, 'default' => '2000'],
                'code_max_file_size' => ['type' => 'size', 'min' => self::KB, 'max' => self::GB, 'default' => '20MB'],
            ],
            'disk' => [
                'warn_percent' => ['type' => 'percent'],
                'block_percent' => ['type' => 'percent'],
            ],
            'repository' => [
                'fetch_timeout' => ['type' => 'duration', 'min' => self::MIN, 'max' => 24 * self::HOUR],
                'min_interval' => ['type' => 'duration', 'min' => self::DAY, 'max' => 365 * self::DAY],
                // 0 = 사용 안 함(명세 18.2)
                'default_interval' => ['type' => 'duration', 'min' => 0, 'max' => 365 * self::DAY],
                'connect_max_attempts' => ['type' => 'int', 'min' => 1, 'max' => 20],
                'connect_retry_interval' => ['type' => 'duration', 'min' => 1, 'max' => 24 * self::HOUR],
                'ca_cert_file' => ['type' => 'string', 'optional' => true],
                // P25: 연결 테스트(웹 요청에서 실행, admin만) 시간 상한과 사용자당 분당 횟수
                'test_timeout' => ['type' => 'duration', 'min' => 5, 'max' => 5 * self::MIN, 'default' => '30s'],
                'test_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 60, 'default' => '6'],
                // P25: 개발용 예외 — 켜면 허용 목록에 등록한 loopback(127.0.0.1·::1·localhost)에도 접속한다(시험용 로컬 저장소). 운영은 끔, 켜면 WARN 로그
                'allow_local_dev' => ['type' => 'bool', 'default' => 'false'],
                // P26B: 허용 호스트 연결 테스트 — 전체 테스트 최대 호스트 수, 단계별 시간 상한
                'host_test_max' => ['type' => 'int', 'min' => 1, 'max' => 200, 'default' => '20'],
                'host_test_tcp_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '5s'],
                'host_test_tls_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '5s'],
                'host_test_http_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 120, 'default' => '10s'],
            ],
            // P26: 주기 분석 스케줄러(워커의 주기 작업). 가져오기 재시도는 repository.connect_max_attempts·connect_retry_interval
            'scheduler' => [
                // 도래 확인 간격(기본 1분, 최소 10초)
                'tick_seconds' => ['type' => 'duration', 'min' => 10, 'max' => self::HOUR, 'default' => '60s'],
                // 한 틱에서 처리하는 도래 프로젝트 수 상한(남으면 다음 틱)
                'max_per_tick' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '50'],
            ],
            'log' => [
                'level' => ['type' => 'enum', 'values' => ['ERROR', 'WARN', 'INFO']],
                'error_access_retention' => ['type' => 'duration', 'min' => self::DAY, 'max' => 3650 * self::DAY],
                'audit_retention' => ['type' => 'duration', 'min' => self::DAY, 'max' => 3650 * self::DAY],
                // P30: 감사 로그 보존 정리(tools/cleanup.php)가 한 트랜잭션에서 지울 최대 행 수 — 쓰기 잠금을 짧게
                'audit_purge_batch' => ['type' => 'int', 'min' => 100, 'max' => 1000000, 'default' => '5000'],
                // P31: 감사 로그 최신 해시를 DB 밖(로그 폴더 audit-anchor.jsonl)에 남기는 간격(워커)
                'audit_anchor_interval' => ['type' => 'duration', 'min' => 60, 'max' => self::DAY, 'default' => '1h'],
            ],
            'notification' => [
                'retention' => ['type' => 'duration', 'min' => self::DAY, 'max' => 3650 * self::DAY],
                'admin_alert_dedupe' => ['type' => 'duration', 'min' => self::MIN, 'max' => 7 * self::DAY],
                'cert_expiry_warn' => ['type' => 'duration', 'min' => self::DAY, 'max' => 365 * self::DAY],
                'admin_emails' => ['type' => 'email_list', 'optional' => true],
            ],
            'smtp' => [
                'host' => ['type' => 'string', 'optional' => true],
                'port' => ['type' => 'int', 'min' => 1, 'max' => 65535, 'optional' => true],
                'tls' => ['type' => 'enum', 'values' => ['none', 'starttls', 'tls'], 'optional' => true],
                'username' => ['type' => 'string', 'optional' => true],
            ],
            'ldap' => [
                // P28B LDAP/AD 로그인(docs/auth_ldap.md). 화면 "시스템 관리 > 설정 관리 > LDAP/AD"에서 바꾼다. 검색 계정 비밀번호는 설정이 아니라 암호화 저장(app_secrets)
                'enabled' => ['type' => 'bool', 'default' => 'false'],
                // 서버 주소 1~3개(쉼표, 앞에서부터 — 연결 실패만 다음 서버로). ldaps:// 권장, ldap://는 use_starttls가 켜져 있어야 함
                'servers' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'use_starttls' => ['type' => 'bool', 'default' => 'false'],
                // 사내 CA 인증서 파일(PEM, 절대 경로). 비우면 운영체제 기본 신뢰 목록. 인증서 검증을 끄는 설정은 없다
                'ca_file' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'base_dn' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // 사용자 검색 필터 — %s(로그인 ID, 이스케이프해서 넣음) 정확히 하나
                'user_filter' => ['type' => 'string', 'default' => '(&(objectClass=user)(sAMAccountName=%s))'],
                'bind_dn' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'attr_login' => ['type' => 'string', 'default' => 'sAMAccountName'],
                'attr_name' => ['type' => 'string', 'optional' => true, 'default' => 'displayName'],
                'attr_email' => ['type' => 'string', 'optional' => true, 'default' => 'mail'],
                // 안정 식별자(이름이 바뀌어도 그대로): AD objectGUID, OpenLDAP entryUUID. DN은 쓰지 않는다
                'attr_id' => ['type' => 'string', 'default' => 'objectGUID'],
                // 비활성 표시 속성(AD userAccountControl — 비트 2가 켜지면 거부). 비우면 검사하지 않음(OpenLDAP)
                'attr_disabled' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'connect_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 30, 'default' => '5s'],
                'search_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '10s'],
                // 예전 키(P28B 전 config.ini 호환, 읽지 않으면 새 키로 대신): server_url → servers, attr_user_id → attr_login, ca_cert_file → ca_file
                'server_url' => ['type' => 'ldap_url', 'optional' => true],
                'attr_user_id' => ['type' => 'string', 'optional' => true],
                'ca_cert_file' => ['type' => 'string', 'optional' => true],
                // P25: 연결 테스트(웹 요청에서 실행, admin만) 시간 상한과 사용자당 분당 횟수
                'test_timeout' => ['type' => 'duration', 'min' => 5, 'max' => 5 * self::MIN, 'default' => '30s'],
                'test_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 60, 'default' => '6'],
                // P25: 개발용 예외 — 켜면 허용 목록에 등록한 loopback(127.0.0.1·::1·localhost)에도 접속한다(시험용 로컬 저장소). 운영은 끔, 켜면 WARN 로그
                'allow_local_dev' => ['type' => 'bool', 'default' => 'false'],
            ],
            'oidc' => [
                // P28C SSO 로그인(OpenID Connect, Authorization Code + PKCE, docs/auth_oidc.md). 화면 "시스템 관리 > 설정 관리 > SSO(OIDC)"에서 바꾼다.
                // 클라이언트 비밀은 설정이 아니라 암호화 저장(app_secrets oidc.client_secret). 제공자는 하나만.
                'enabled' => ['type' => 'bool', 'default' => 'false'],
                // 발급자 URL(https, 디스커버리 문서의 issuer와 정확히 같아야 함 — 끝의 / 포함 여부까지)
                'issuer' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'client_id' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // 토큰 엔드포인트 클라이언트 인증: basic(client_secret_basic) | post(client_secret_post) | none(공개 클라이언트 + PKCE)
                'client_auth' => ['type' => 'enum', 'values' => ['basic', 'post', 'none'], 'default' => 'basic'],
                'scopes' => ['type' => 'string', 'default' => 'openid profile email'],
                // 로그인 ID로 쓸 클레임(X06 규칙 검사). email_localpart = 검증된 email의 @ 앞부분
                'claim_login' => ['type' => 'string', 'default' => 'preferred_username'],
                'claim_name' => ['type' => 'string', 'default' => 'name'],
                'use_userinfo' => ['type' => 'bool', 'default' => 'false'],
                // ID 토큰 서명 알고리즘(RS256·ES256 중에서만 — none·HS*는 받지 않음)
                'allowed_algs' => ['type' => 'list', 'default' => 'RS256, ES256'],
                // 로그인 화면 단추 문구(40자 이내, 비우면 "SSO로 로그인")
                'button_label' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // 로그아웃 때 제공자 로그아웃(RP-Initiated Logout)으로도 보냄. 기본 꺼짐 = 앱 세션만 끝냄
                'provider_logout' => ['type' => 'bool', 'default' => 'false'],
                // 리다이렉트 URI의 공개 주소(비우면 app.base_url). Host 헤더는 쓰지 않는다. https(루프백 개발 주소만 http 허용)
                'redirect_base' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // 디스커버리·JWKS 캐시 시간, 가져오기 실패 때 오래된 캐시를 쓰는 상한
                'jwks_cache_ttl' => ['type' => 'duration', 'min' => 5 * self::MIN, 'max' => self::DAY, 'default' => '1h'],
                'jwks_stale_max' => ['type' => 'duration', 'min' => 0, 'max' => self::DAY, 'default' => '1h'],
                // 로그인 시작 요청 제한(IP당 분당), 연결 시험(admin당 분당)
                'start_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 600, 'default' => '20'],
                'test_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 60, 'default' => '6'],
                // 개발용 예외 — 켜면 루프백 주소의 IdP에도 접속(시험용). 운영은 끔
                'allow_local_dev' => ['type' => 'bool', 'default' => 'false'],
            ],
            'saml' => [
                // P28D SSO 로그인(SAML 2.0 SP, SP 시작 — HTTP-Redirect 요청 → HTTP-POST 응답, docs/auth_saml.md). 화면 "시스템 관리 > 설정 관리 > SSO(SAML)".
                // 서명 검증은 라이브러리(onelogin/php-saml). SP 개인 키·인증서는 설정이 아니라 암호화 저장(app_secrets). 제공자는 하나만. 공개 주소는 OIDC와 같음(oidc.redirect_base, 비우면 app.base_url)
                'enabled' => ['type' => 'bool', 'default' => 'false'],
                'idp_entity_id' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // IdP SSO 주소(HTTP-Redirect 바인딩, https)
                'idp_sso_url' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // IdP 서명 인증서(PEM 본문 base64 한 줄 — 화면에서 PEM을 붙여 넣으면 머리줄·줄바꿈을 뗀다). 롤오버용 다음 인증서
                'idp_cert' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'idp_cert_next' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // SP EntityID(비우면 <공개 주소>/auth/saml/metadata)
                'sp_entity_id' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'name_id_format' => ['type' => 'enum', 'values' => ['persistent', 'unspecified', 'emailAddress', 'transient'], 'default' => 'persistent'],
                // 계정 식별자: nameid(NameID 값) 또는 속성 이름(예: objectGUID 클레임). transient NameID는 식별자로 쓸 수 없다
                'id_source' => ['type' => 'string', 'default' => 'nameid'],
                // 로그인 ID·표시 이름·이메일 속성(속성 Name 또는 FriendlyName, URI 형식 가능)
                'attr_login' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'attr_name' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'attr_email' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // AuthnRequest 서명(SP 키 필요), 암호화된 어서션 요구(SP 키로 복호화)
                'sign_requests' => ['type' => 'bool', 'default' => 'false'],
                'want_encrypted' => ['type' => 'bool', 'default' => 'false'],
                'button_label' => ['type' => 'string', 'optional' => true, 'default' => ''],
                // 로그인 시작 요청 제한(IP당 분당), 설정 확인(admin당 분당), 루프백 IdP(개발)
                'start_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 600, 'default' => '20'],
                'test_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 60, 'default' => '6'],
                'allow_local_dev' => ['type' => 'bool', 'default' => 'false'],
            ],
            'health' => [
                'allowed_ips' => ['type' => 'ip_list', 'optional' => true],
                // P30: 허용 IP당 분당 호출 수(넘으면 429), 응답을 다시 쓰는 시간(같은 판정을 짧게 재사용)
                'per_minute' => ['type' => 'int', 'min' => 1, 'max' => 6000, 'default' => '60'],
                'cache' => ['type' => 'duration', 'min' => 0, 'max' => 300, 'default' => '5s'],
            ],
            // P30 시스템 모니터링·장애 판정(Services\Monitoring\SystemHealth)
            'monitoring' => [
                // 큐 정체: 워커가 살아 있고 빈 자리가 있는데 시작할 수 있는 가장 오래된 대기 분석이 이 시간 넘게 시작되지 않음
                'queue_stall' => ['type' => 'duration', 'min' => 60, 'max' => self::DAY, 'default' => '15m'],
                // 판정 결과 캐시(배너·화면이 요청마다 다시 계산하지 않게). 워커도 이 간격으로 판정해 상태 변화를 경고 로그에 남긴다
                'cache' => ['type' => 'duration', 'min' => 5, 'max' => 600, 'default' => '30s'],
                // 같은 경고가 이어질 때 경고 로그를 다시 쓰는 간격(상태가 바뀔 때는 바로)
                'log_repeat' => ['type' => 'duration', 'min' => 60, 'max' => 7 * self::DAY, 'default' => '1h'],
                // 화면 자동 갱신 간격(탭이 보일 때만)
                'refresh' => ['type' => 'duration', 'min' => 10, 'max' => 600, 'default' => '30s'],
                // 최근 실패 분석 카드 건수
                'recent_failures' => ['type' => 'int', 'min' => 1, 'max' => 50, 'default' => '10'],
                // 저장소 가져오기 연속 실패 경고: 주기 분석 연속 실패가 이 횟수 이상인 프로젝트가 있으면
                'repo_failures' => ['type' => 'int', 'min' => 1, 'max' => 100, 'default' => '2'],
                // HTTPS 인증서 만료일: 웹 서버가 쓰는 인증서 파일(PEM, 절대 경로)을 읽는다. 비우면 "확인 불가(not_configured)"
                'tls_cert_file' => ['type' => 'string', 'optional' => true, 'default' => ''],
            ],
            // P30B 관리자 장애 이메일(Services\Mail, docs/monitoring.md 4절). 화면 "시스템 관리 > 설정 관리 > 관리자 이메일"에서 바꾼다(값은 settings.local.ini).
            // SMTP 비밀번호는 설정이 아니라 암호화 저장(app_secrets mail.smtp_password). 발송은 워커의 주기 판정에서만(웹 요청은 보내지 않음)
            'alert_mail' => [
                'enabled' => ['type' => 'bool', 'default' => 'false'],
                // SMTP 서버 이름 또는 IP(스킴·경로 없이). 사설망 중계 허용(운영자가 입력하는 값)
                'smtp_host' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'smtp_port' => ['type' => 'int', 'min' => 1, 'max' => 65535, 'default' => '587'],
                // none(평문 — localhost·사설망 중계용, 화면 경고) | starttls | smtps. 인증서 검증을 끄는 설정은 없다
                'smtp_tls' => ['type' => 'enum', 'values' => ['none', 'starttls', 'smtps'], 'default' => 'starttls'],
                'smtp_auth' => ['type' => 'enum', 'values' => ['none', 'login'], 'default' => 'login'],
                'smtp_user' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'from_address' => ['type' => 'string', 'optional' => true, 'default' => ''],
                'from_name' => ['type' => 'string', 'default' => 'PJGuardian'],
                // 받는 사람(쉼표, 최대 20). 비우면 활성 admin 계정의 이메일 전체
                'recipients' => ['type' => 'email_list', 'optional' => true, 'default' => ''],
                'language' => ['type' => 'enum', 'values' => ['ko', 'en'], 'default' => 'ko'],
                // 보낼 사건 종류(쉼표): worker_down, queue_stalled, disk, backup, tool, repo, cert
                'events' => ['type' => 'list', 'default' => 'worker_down, queue_stalled, disk, backup, tool, repo, cert'],
                // 같은 사건이 이어질 때 다시 보내는 간격(명세 19.3: 1시간 1회), 10분~24시간
                'resend_interval' => ['type' => 'duration', 'min' => 10 * self::MIN, 'max' => self::DAY, 'default' => '1h'],
                // 사건이 풀리면 복구 메일 한 통
                'recovery' => ['type' => 'bool', 'default' => 'true'],
                // 아래는 config.ini에서만(화면 밖): 연결 시간 상한·전체 시간 상한, 시험 발송 제한, 해소된 사건 상태 보관
                'connect_timeout' => ['type' => 'duration', 'min' => 1, 'max' => 60, 'default' => '10s'],
                'send_timeout' => ['type' => 'duration', 'min' => 5, 'max' => 120, 'default' => '30s'],
                'test_per_minute' => ['type' => 'int', 'min' => 1, 'max' => 60, 'default' => '3'],
                'test_per_hour' => ['type' => 'int', 'min' => 1, 'max' => 600, 'default' => '20'],
                'state_retention' => ['type' => 'duration', 'min' => self::DAY, 'max' => 365 * self::DAY, 'default' => '30d'],
            ],
            // 히스토리(P21 예정): 두 Run의 분석 대상 파일 수가 이 비율 이상 다르면 "분석 범위가 달라졌습니다"(P20R에서 추가, Run 비교 제거 뒤에도 유지)
            'history' => [
                'scope_change_ratio' => ['type' => 'ratio', 'default' => '0.5'],
            ],
            // Run 상세 보관(P21B): 프로젝트마다 상세(과거 Run 보기)를 유지할 최근 Run 수(0 = 자동 삭제 끔, 1~2는 거부), 워커가 한 번에 지울 최대 Run 수
            'runs' => [
                'detail_keep' => ['type' => 'int', 'min' => 0, 'max' => 10000, 'default' => '15'],
                'retention_batch' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '20'],
            ],
            // 규칙 기본 심각도(P21S): 분석이 만든 신규 결함에 resources/severity의 매핑 값을 넣는다. false면 not_defined(이전 동작)
            'severity' => [
                'auto_assign' => ['type' => 'bool', 'default' => 'true'],
            ],
            // 규칙 설명(P22): 워커가 시작 때와 load_interval마다 규칙 데이터 적재(관리 작업 rules_load)를 요청한다
            'rules' => [
                'auto_load' => ['type' => 'bool', 'default' => 'true'],
                'load_interval' => ['type' => 'duration', 'min' => 60, 'max' => 7 * 24 * self::HOUR, 'default' => '1h'],
                // P22B: 관리자 규칙 변경 "모두 기본값으로 되돌리기" 한 번에 되돌리는 최대 건수(넘으면 나눠서)
                'override_reset_max' => ['type' => 'int', 'min' => 1, 'max' => 100000, 'default' => '1000'],
                // P22C: 규칙 목록 일괄 변경 한 번의 대상 상한(넘으면 400 bulk_too_large — 잘라서 적용하지 않음)
                'bulk_max' => ['type' => 'int', 'min' => 1, 'max' => 100000, 'default' => '1000'],
            ],
            // 내보내기(P23): 결함·규칙 목록 CSV·XLSX. 행 상한(넘으면 거부 — 잘라서 주지 않음), 동시 실행(서버·사용자), 시간·메모리 상한
            'export' => [
                'max_rows' => ['type' => 'int', 'min' => 1, 'max' => 200000, 'default' => '50000'],
                'max_concurrent' => ['type' => 'int', 'min' => 1, 'max' => 16, 'default' => '2'],
                'max_per_user' => ['type' => 'int', 'min' => 1, 'max' => 4, 'default' => '1'],
                'timeout_seconds' => ['type' => 'int', 'min' => 5, 'max' => 600, 'default' => '60'],
                'memory_limit' => ['type' => 'size', 'min' => 32 * self::MB, 'max' => 2 * self::GB, 'default' => '128MB'],
            ],
            // 대시보드(P20): 집계 캐시 시간(0이면 끔). 데이터·접근 범위가 바뀌면 TTL과 관계없이 다시 계산한다
            'dashboard' => [
                'cache_ttl' => ['type' => 'duration', 'min' => 0, 'max' => self::HOUR, 'default' => '60s'],
            ],
            'ui' => [
                'defect_page_size' => ['type' => 'int', 'min' => 1, 'max' => 1000],
                'page_size_max' => ['type' => 'int', 'min' => 1, 'max' => 1000],
                'bulk_update_max' => ['type' => 'int', 'min' => 1, 'max' => 5000, 'default' => '500'], // P18 결함 일괄 변경 한 번의 최대 건수
                'dashboard_trend_count' => ['type' => 'int', 'min' => 1, 'max' => 100],
                'dashboard_top_n' => ['type' => 'int', 'min' => 1, 'max' => 100],
                // 히스토리(P21) 분석 이력 한 쪽 기본·최대 건수
                'history_page_size' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '20'],
                'history_page_size_max' => ['type' => 'int', 'min' => 1, 'max' => 1000, 'default' => '100'],
                // 상단 알림 아이콘의 안 읽은 개수 갱신 주기(초, P24). 탭이 보이는 동안만
                'notification_poll_seconds' => ['type' => 'int', 'min' => 15, 'max' => 3600, 'default' => '60'],
            ],
        ];
    }

    /**
     * 화면(관리자 "설정 관리", P27A)에서 바꿀 수 있는 키 — **허용 목록은 여기 한 곳**이다. 형식·범위는 definition()의 spec을 그대로 쓴다.
     * group: 화면 묶음, restart: none(웹은 요청마다, 워커는 설정 파일이 바뀌면 루프에서 다시 읽음 — 다음 작업부터) | worker(워커를 다시 시작해야 함) | web,
     * machine_specific: PC마다 다른 값(백업 설정 복원 때 기본 해제, P27B). 라벨·도움말은 문자열 리소스 config_settings.key.<이름>.label·help.
     * 새 키를 넣을 때(P27B 백업 폴더 등)는 docs/settings.md "키 등록 절차"를 따른다. NOT_EDITABLE에 걸리면 LogicException.
     */
    public const EDITABLE = [
        // 보존 기간
        'upload.incomplete_chunk_retention' => ['group' => 'retention', 'restart' => 'none'],
        'upload.failed_zip_retention' => ['group' => 'retention', 'restart' => 'none'],
        'upload.confirmation_retention' => ['group' => 'retention', 'restart' => 'none'],
        'log.error_access_retention' => ['group' => 'retention', 'restart' => 'none'],
        'snapshot.full_keep_count' => ['group' => 'retention', 'restart' => 'none'],
        'runs.detail_keep' => ['group' => 'retention', 'restart' => 'none'],
        // P30: 감사 로그 보존(정리 작업이 매일 지움, 90일 미만으로 줄이면 화면이 한 번 더 확인)
        'log.audit_retention' => ['group' => 'retention', 'restart' => 'none'],
        // 디스크
        'disk.warn_percent' => ['group' => 'disk', 'restart' => 'none'],
        'disk.block_percent' => ['group' => 'disk', 'restart' => 'none'],
        // 알림
        'ui.notification_poll_seconds' => ['group' => 'notification', 'restart' => 'none'],
        'notification.cert_expiry_warn' => ['group' => 'notification', 'restart' => 'none'],
        // P30 모니터링: 헬스체크 허용 IP(서버마다 다름)·큐 정체 기준
        'health.allowed_ips' => ['group' => 'monitoring', 'restart' => 'none', 'machine_specific' => true],
        // P32B 신뢰 프록시: 웹 요청마다 읽음(워커는 쓰지 않음)
        'security.trusted_proxies' => ['group' => 'monitoring', 'restart' => 'none', 'machine_specific' => true],
        'monitoring.queue_stall' => ['group' => 'monitoring', 'restart' => 'none'],
        // P30B 관리자 이메일: 워커가 판정 때마다 설정에서 읽으므로 재시작 없음. SMTP 비밀번호는 같은 탭의 별도 입력(app_secrets, 암호화)
        'alert_mail.enabled' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.smtp_host' => ['group' => 'admin_mail', 'restart' => 'none', 'machine_specific' => true],
        'alert_mail.smtp_port' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.smtp_tls' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.smtp_auth' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.smtp_user' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.from_address' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.from_name' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.recipients' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.language' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.events' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.resend_interval' => ['group' => 'admin_mail', 'restart' => 'none'],
        'alert_mail.recovery' => ['group' => 'admin_mail', 'restart' => 'none'],
        // 주기 분석·가져오기
        'scheduler.tick_seconds' => ['group' => 'schedule', 'restart' => 'none'],
        'scheduler.max_per_tick' => ['group' => 'schedule', 'restart' => 'none'],
        'repository.fetch_timeout' => ['group' => 'schedule', 'restart' => 'none'],
        'repository.connect_max_attempts' => ['group' => 'schedule', 'restart' => 'none'],
        'repository.connect_retry_interval' => ['group' => 'schedule', 'restart' => 'none'],
        // 저장소 연결 테스트
        'repository.test_timeout' => ['group' => 'repository', 'restart' => 'none'],
        'repository.test_per_minute' => ['group' => 'repository', 'restart' => 'none'],
        'repository.host_test_max' => ['group' => 'repository', 'restart' => 'none'],
        // 분석
        'analysis.max_concurrent' => ['group' => 'analysis', 'restart' => 'none'],
        'analysis.timeout' => ['group' => 'analysis', 'restart' => 'none'],
        'limits.project_defect_warn' => ['group' => 'analysis', 'restart' => 'none'],
        'limits.project_defect_max' => ['group' => 'analysis', 'restart' => 'none'],
        // X21: 업로드·해제 한도(분석 대상 소스 크기·파일 수). 웹은 요청마다, 해제·가져오기는 작업마다 새로 읽으므로 재시작 없음
        'upload.max_zip_size' => ['group' => 'analysis', 'restart' => 'none'],
        'extract.max_extracted_size' => ['group' => 'analysis', 'restart' => 'none'],
        'extract.max_files' => ['group' => 'analysis', 'restart' => 'none'],
        // 백업(P27B): 워커가 틱마다 설정에서 읽으므로 모두 재시작 없이 반영(daily_at 포함)
        'backup.dir' => ['group' => 'backup', 'restart' => 'none', 'machine_specific' => true],
        // P27E 자동 백업 켜기/끄기·주기(워커가 확인할 때마다 읽음)
        'backup.enabled' => ['group' => 'backup', 'restart' => 'none'],
        'backup.schedule_mode' => ['group' => 'backup', 'restart' => 'none'],
        'backup.daily_at' => ['group' => 'backup', 'restart' => 'none'],
        'backup.weekly_day' => ['group' => 'backup', 'restart' => 'none'],
        'backup.monthly_day' => ['group' => 'backup', 'restart' => 'none'],
        'backup.retention_days' => ['group' => 'backup', 'restart' => 'none'],
        'backup.retention_min_count' => ['group' => 'backup', 'restart' => 'none'],
        'backup.retention_max_count' => ['group' => 'backup', 'restart' => 'none'],
        'backup.safety_retention_days' => ['group' => 'backup', 'restart' => 'none'],
        'backup.rehearsal_interval_days' => ['group' => 'backup', 'restart' => 'none'],
        'backup.backup_stale_hours' => ['group' => 'backup', 'restart' => 'none'],
        'backup.restore_wait_max_minutes' => ['group' => 'backup', 'restart' => 'none'],
        'backup.upload_max_mb' => ['group' => 'backup', 'restart' => 'none'],
        // 계정 승인 대기(P28B): 외부 인증 첫 로그인이 만드는 승인 대기 계정의 상한·보관 기간
        'auth.pending_max' => ['group' => 'auth', 'restart' => 'none'],
        'auth.pending_retention_days' => ['group' => 'auth', 'restart' => 'none'],
        // LDAP/AD(P28B): 로그인 때마다 설정에서 읽으므로 재시작 없음. 검색 계정 비밀번호는 같은 탭의 별도 입력(app_secrets, 암호화)
        'ldap.enabled' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.servers' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.use_starttls' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.ca_file' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.base_dn' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.user_filter' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.bind_dn' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.attr_login' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.attr_name' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.attr_email' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.attr_id' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.attr_disabled' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.connect_timeout' => ['group' => 'auth_ldap', 'restart' => 'none'],
        'ldap.search_timeout' => ['group' => 'auth_ldap', 'restart' => 'none'],
        // SSO(OIDC, P28C): 로그인 때마다 설정에서 읽으므로 재시작 없음. 클라이언트 비밀은 같은 탭의 별도 입력(app_secrets, 암호화)
        'oidc.enabled' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.issuer' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.client_id' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.client_auth' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.scopes' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.claim_login' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.claim_name' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.use_userinfo' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.allowed_algs' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.button_label' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.provider_logout' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.redirect_base' => ['group' => 'auth_oidc', 'restart' => 'none', 'machine_specific' => true],
        'oidc.jwks_cache_ttl' => ['group' => 'auth_oidc', 'restart' => 'none'],
        'oidc.jwks_stale_max' => ['group' => 'auth_oidc', 'restart' => 'none'],
        // SSO(SAML, P28D): 로그인 때마다 읽으므로 재시작 없음. SP 키는 같은 탭의 별도 단추(생성·교체, app_secrets), IdP 메타데이터 가져오기는 값 채우기만
        'saml.enabled' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.idp_entity_id' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.idp_sso_url' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.idp_cert' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.idp_cert_next' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.sp_entity_id' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.name_id_format' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.id_source' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.attr_login' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.attr_name' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.attr_email' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.sign_requests' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.want_encrypted' => ['group' => 'auth_saml', 'restart' => 'none'],
        'saml.button_label' => ['group' => 'auth_saml', 'restart' => 'none'],
        // P32B 업그레이드 유지보수 모드: 웹은 요청마다, 워커는 루프마다 설정에서 읽음(재시작 없음). 켜기는 SettingsService가 직전 백업을 먼저 한다
        'maintenance.enabled' => ['group' => 'maintenance', 'restart' => 'none'],
        'maintenance.message' => ['group' => 'maintenance', 'restart' => 'none'],
        'maintenance.notice_at' => ['group' => 'maintenance', 'restart' => 'none'],
    ];

    /** 화면 묶음 순서 */
    public const EDITABLE_GROUPS = ['retention', 'disk', 'notification', 'monitoring', 'admin_mail', 'schedule', 'repository', 'analysis', 'backup', 'auth', 'auth_ldap', 'auth_oidc', 'auth_saml', 'maintenance']; // P32B 유지보수, P28B 계정 승인·LDAP/AD, P28C SSO(OIDC), P28D SSO(SAML), P30 모니터링, P30B 관리자 이메일

    /**
     * 화면에서 절대 편집·표시하지 않는 키(이름 또는 "섹션." 접두사). 허용 목록에 들어가면 SettingsRegistry가 거부한다.
     * 시크릿(encryption_key 등)은 애초에 이 표가 아니라 secrets.ini·환경 변수(Config::SECRET_KEYS)에만 있다.
     */
    /** NOT_EDITABLE 접두사 안에서 화면 편집을 허용하는 키(P32B: 신뢰 프록시 — admin만, 형식 검증·감사 로그·출처 표시는 다른 키와 같다) */
    public const NOT_EDITABLE_EXCEPTIONS = ['security.trusted_proxies'];

    public const NOT_EDITABLE = [
        'app.', 'security.', 'paths.', 'database.', 'smtp.', 'tools.',
        // P30: 헬스체크는 허용 IP만 화면에서(호출 제한·캐시는 config.ini), 인증서 파일 경로는 서버 파일이라 config.ini에서만
        'health.per_minute', 'health.cache', 'monitoring.tls_cert_file', 'monitoring.cache', 'monitoring.log_repeat', 'monitoring.refresh', 'monitoring.recent_failures', 'monitoring.repo_failures', 'log.audit_purge_batch', 'log.audit_anchor_interval',
        // P28B: ldap·auth는 섹션 전체 대신 화면에서 다루지 않을 키만(승인 대기 상한·LDAP 연결 설정은 허용 목록)
        'auth.password_min_length', 'auth.password_min_char_classes', 'auth.lockout_max_failures', 'auth.lockout_duration', 'auth.ip_failure_window',
        'auth.ip_max_failures', 'auth.ip_block_duration', 'auth.session_idle', 'auth.temp_password_expiry', 'auth.session_touch_interval', 'auth.bcrypt_cost',
        'ldap.server_url', 'ldap.attr_user_id', 'ldap.ca_cert_file', 'ldap.test_timeout', 'ldap.test_per_minute', 'ldap.allow_local_dev',
        'oidc.start_per_minute', 'oidc.test_per_minute', 'oidc.allow_local_dev', 'saml.start_per_minute', 'saml.test_per_minute', 'saml.allow_local_dev',
        // X21: upload.max_zip_size·extract.max_extracted_size·extract.max_files는 화면 편집(분석 탭) — 해제 메모리·제외 대상은 config.ini에서만
        'upload.chunk_size', 'extract.worker_memory_limit', 'extract.default_excludes', 'repository.ca_cert_file', 'repository.allow_local_dev',
        'worker.start_command', 'worker.supervised',
        // P30B: 메일 시간 상한·시험 발송 제한·상태 보관은 config.ini에서만
        'alert_mail.connect_timeout', 'alert_mail.send_timeout', 'alert_mail.test_per_minute', 'alert_mail.test_per_hour', 'alert_mail.state_retention',
    ];

    /**
     * LDAP 설정 검사(P28B): 서버 주소 1~3개(ldaps:// 또는 StartTLS를 켠 ldap://), 필터 템플릿(%s 정확히 하나·괄호 짝), CA 파일(절대 경로),
     * 속성 이름 형식. 켜져 있으면 서버·Base DN이 있어야 한다. 예전 키(server_url·attr_user_id·ca_cert_file)는 새 키가 비었을 때 대신 쓴다.
     *
     * @param array<string, mixed> $l 형식 변환이 끝난 [ldap] 값
     * @return list<string>
     */
    public static function ldapErrors(array $l): array
    {
        $errors = [];
        $servers = self::ldapServers($l);
        if (count($servers) > 3) {
            $errors[] = 'ldap.servers: 서버는 3개까지입니다';
        }
        foreach ($servers as $s) {
            if (preg_match('#^ldaps?://[A-Za-z0-9.\-]+(:\d{1,5})?/?$#', $s) !== 1) {
                $errors[] = 'ldap.servers: ldap:// 또는 ldaps:// 주소여야 합니다';
                break;
            }
            if (str_starts_with($s, 'ldap://') && !$l['use_starttls']) {
                $errors[] = 'ldap.servers: ldap:// 주소는 use_starttls를 켜야 합니다(평문 전송 금지 — ldaps:// 권장)';
                break;
            }
        }
        $filter = (string) ($l['user_filter'] ?? '');
        if (substr_count($filter, '%s') !== 1 || preg_match('/%[^s]/', str_replace('%s', '', $filter)) === 1 || !self::balancedParens($filter) || !str_starts_with($filter, '(')) {
            $errors[] = 'ldap.user_filter: (로 시작하고 괄호 짝이 맞으며 %s가 정확히 하나여야 합니다';
        }
        foreach (['attr_login', 'attr_name', 'attr_email', 'attr_id', 'attr_disabled'] as $k) {
            $a = (string) ($l[$k] ?? '');
            if ($a !== '' && preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/', $a) !== 1) {
                $errors[] = "ldap.{$k}: 속성 이름 형식(영문으로 시작, 영문·숫자·-)이어야 합니다";
            }
        }
        if (($l['attr_login'] ?? '') === '' || ($l['attr_id'] ?? '') === '') {
            $errors[] = 'ldap.attr_login·attr_id: 비울 수 없습니다';
        }
        $ca = self::ldapCaFile($l);
        if ($ca !== '' && (strlen($ca) > 260 || preg_match('/["\x00-\x1f\x7f]/', $ca) === 1 || preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $ca) !== 1)) {
            $errors[] = 'ldap.ca_file: 절대 경로여야 합니다';
        }
        foreach (['base_dn', 'bind_dn'] as $k) {
            if (strlen((string) ($l[$k] ?? '')) > 1000 || preg_match('/[\x00-\x1f\x7f]/', (string) ($l[$k] ?? '')) === 1) {
                $errors[] = "ldap.{$k}: DN 형식이 아닙니다";
            }
        }
        if ($l['enabled'] && ($servers === [] || trim((string) ($l['base_dn'] ?? '')) === '')) {
            $errors[] = 'ldap.enabled: true이면 servers와 base_dn이 필요합니다';
        }
        return $errors;
    }

    /** P30B 관리자 이메일이 다루는 사건 종류(설정 alert_mail.events의 값) */
    public const ALERT_MAIL_EVENTS = ['worker_down', 'queue_stalled', 'disk', 'backup', 'tool', 'repo', 'cert'];
    public const ALERT_MAIL_MAX_RECIPIENTS = 20;

    /**
     * 관리자 이메일 설정 검사(P30B): SMTP 호스트(이름·IPv4·[IPv6]만 — 스킴·경로·개행·공백 거부, 사설망은 허용), 보낸 사람 주소·이름(개행 거부),
     * 받는 사람 상한, 사건 종류 값. 켜져 있으면 호스트·보낸 사람이 있어야 하고, 인증 login이면 사용자 이름이 있어야 한다.
     *
     * @param array<string, mixed> $m 형식 변환이 끝난 [alert_mail] 값
     * @return list<string>
     */
    public static function alertMailErrors(array $m): array
    {
        $errors = [];
        $host = (string) ($m['smtp_host'] ?? '');
        if ($host !== '' && !self::isMailHost($host)) {
            $errors[] = 'alert_mail.smtp_host: 서버 이름 또는 IP만 넣습니다(스킴·경로·공백 없이)';
        }
        $from = (string) ($m['from_address'] ?? '');
        if ($from !== '' && (filter_var($from, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $from) === 1)) {
            $errors[] = 'alert_mail.from_address: 이메일 형식이어야 합니다';
        }
        $name = (string) ($m['from_name'] ?? '');
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || mb_strlen($name) > 100) {
            $errors[] = 'alert_mail.from_name: 한 줄, 100자 이하여야 합니다';
        }
        $user = (string) ($m['smtp_user'] ?? '');
        if (preg_match('/[\x00-\x1f\x7f]/', $user) === 1 || strlen($user) > 200) {
            $errors[] = 'alert_mail.smtp_user: 한 줄, 200자 이하여야 합니다';
        }
        if (count((array) ($m['recipients'] ?? [])) > self::ALERT_MAIL_MAX_RECIPIENTS) {
            $errors[] = 'alert_mail.recipients: 받는 사람은 ' . self::ALERT_MAIL_MAX_RECIPIENTS . '명까지입니다';
        }
        foreach ((array) ($m['events'] ?? []) as $ev) {
            if (!in_array($ev, self::ALERT_MAIL_EVENTS, true)) {
                $errors[] = 'alert_mail.events: 알 수 없는 사건 종류입니다(' . implode(', ', self::ALERT_MAIL_EVENTS) . ')';
                break;
            }
        }
        if (($m['enabled'] ?? false) === true) {
            if ($host === '' || $from === '') {
                $errors[] = 'alert_mail.enabled: true이면 smtp_host와 from_address가 필요합니다';
            }
            if (($m['smtp_auth'] ?? 'login') === 'login' && $user === '') {
                $errors[] = 'alert_mail.smtp_user: 인증 방식이 login이면 사용자 이름이 필요합니다';
            }
        }
        return $errors;
    }

    /** SMTP 호스트 형식: DNS 이름(라벨 1~63자, 전체 253자), IPv4, [IPv6] */
    public static function isMailHost(string $host): bool
    {
        if (strlen($host) > 253) {
            return false;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        return preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1;
    }

    /** OIDC가 받는 서명 알고리즘(이 밖은 설정할 수 없음 — none·HS*는 키 혼동 공격 때문에 받지 않는다) */
    public const OIDC_ALGS = ['RS256', 'ES256'];

    /**
     * OIDC 설정 검사(P28C): 발급자 https(질의·조각 없음), 클라이언트 ID 형식, scope에 openid, 클레임 이름 형식, 알고리즘 목록(RS256·ES256의 부분 집합),
     * 단추 문구 40자, 리다이렉트 공개 주소(비우면 app.base_url) https — 루프백 개발 주소만 http. 켜져 있으면 발급자·클라이언트 ID가 필요하다.
     *
     * @param array<string, mixed> $o 형식 변환이 끝난 [oidc] 값
     * @return list<string>
     */
    public static function oidcErrors(array $o, string $appBaseUrl): array
    {
        $errors = [];
        $issuer = (string) ($o['issuer'] ?? '');
        if ($issuer !== '' && !self::httpsUrl($issuer)) {
            $errors[] = 'oidc.issuer: https:// 주소여야 합니다(질의·조각 없이)';
        }
        $cid = (string) ($o['client_id'] ?? '');
        if (strlen($cid) > 255 || preg_match('/[^\x21-\x7E]/', $cid) === 1) {
            $errors[] = 'oidc.client_id: 공백 없는 ASCII 255자 이내여야 합니다';
        }
        $scopes = preg_split('/ +/', trim((string) ($o['scopes'] ?? ''))) ?: [];
        if (!in_array('openid', $scopes, true) || count($scopes) > 20 || preg_grep('/^[\x21\x23-\x5B\x5D-\x7E]{1,64}$/', $scopes, PREG_GREP_INVERT) !== []) {
            $errors[] = 'oidc.scopes: 공백으로 나눈 scope 20개 이내이고 openid가 있어야 합니다';
        }
        $claim = '/^[A-Za-z_][A-Za-z0-9_.:-]{0,63}$/';
        if (preg_match($claim, (string) ($o['claim_login'] ?? '')) !== 1) {
            $errors[] = 'oidc.claim_login: 클레임 이름 형식(영문·숫자·_ . : -, 64자 이내)이어야 합니다';
        }
        if (preg_match($claim, (string) ($o['claim_name'] ?? '')) !== 1) {
            $errors[] = 'oidc.claim_name: 클레임 이름 형식(영문·숫자·_ . : -, 64자 이내)이어야 합니다';
        }
        $algs = (array) ($o['allowed_algs'] ?? []);
        if ($algs === [] || array_diff($algs, self::OIDC_ALGS) !== []) {
            $errors[] = 'oidc.allowed_algs: RS256·ES256 중에서 하나 이상이어야 합니다';
        }
        $label = (string) ($o['button_label'] ?? '');
        if (mb_strlen($label) > 40 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            $errors[] = 'oidc.button_label: 40자 이내여야 합니다';
        }
        $base = (string) ($o['redirect_base'] ?? '');
        if ($base !== '' && !self::redirectBaseOk($base)) {
            $errors[] = 'oidc.redirect_base: https:// 공개 주소여야 합니다(루프백 개발 주소만 http 허용)';
        }
        if ($o['enabled'] ?? false) {
            if ($issuer === '' || $cid === '') {
                $errors[] = 'oidc.enabled: true이면 issuer와 client_id가 필요합니다';
            }
            if ($base === '' && !self::redirectBaseOk(rtrim($appBaseUrl, '/'))) {
                $errors[] = 'oidc.redirect_base: app.base_url이 https가 아니면 redirect_base를 https 공개 주소로 정해야 합니다';
            }
        }
        return $errors;
    }

    /**
     * SAML 설정 검사(P28D): IdP SSO 주소 https, EntityID 형식, 인증서(base64 한 줄 X.509 — 읽을 수 있어야), 속성 이름 형식(URI 포함),
     * transient NameID를 식별자로 쓰는 조합 거부, 단추 문구 40자. 켜져 있으면 IdP EntityID·SSO 주소·인증서·로그인 ID 속성·https 공개 주소가 필요하다.
     *
     * @param array<string, mixed> $s 형식 변환이 끝난 [saml] 값
     * @return list<string>
     */
    public static function samlErrors(array $s, string $publicBase): array
    {
        $errors = [];
        $sso = (string) ($s['idp_sso_url'] ?? '');
        if ($sso !== '' && (strlen($sso) > 1000 || preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s\#]*)?$#', $sso) !== 1)) {
            $errors[] = 'saml.idp_sso_url: https:// 주소여야 합니다';
        }
        foreach (['idp_entity_id', 'sp_entity_id'] as $k) {
            $v = (string) ($s[$k] ?? '');
            if (strlen($v) > 1024 || preg_match('/[^\x21-\x7E]/', $v) === 1) {
                $errors[] = "saml.{$k}: 공백 없는 ASCII 1024자 이내여야 합니다";
            }
        }
        foreach (['idp_cert', 'idp_cert_next'] as $k) {
            $v = (string) ($s[$k] ?? '');
            if ($v !== '' && self::samlCertInfo($v) === null) {
                $errors[] = "saml.{$k}: X.509 인증서(PEM 본문 base64)가 아닙니다";
            }
        }
        $attr = '/^[A-Za-z][A-Za-z0-9_.:\/#?=&%~+-]{0,255}$/';
        foreach (['attr_login', 'attr_name', 'attr_email'] as $k) {
            $v = (string) ($s[$k] ?? '');
            if ($v !== '' && preg_match($attr, $v) !== 1) {
                $errors[] = "saml.{$k}: 속성 이름 형식(영문으로 시작, URI 가능, 공백 없음)이어야 합니다";
            }
        }
        $idSource = (string) ($s['id_source'] ?? '');
        if ($idSource !== 'nameid' && preg_match($attr, $idSource) !== 1) {
            $errors[] = 'saml.id_source: nameid 또는 속성 이름이어야 합니다';
        }
        if ($idSource === 'nameid' && ($s['name_id_format'] ?? '') === 'transient') {
            $errors[] = 'saml.name_id_format: transient NameID는 로그인마다 바뀌어 식별자로 쓸 수 없습니다(persistent 또는 id_source에 속성)';
        }
        $label = (string) ($s['button_label'] ?? '');
        if (mb_strlen($label) > 40 || preg_match('/[\x00-\x1F\x7F]/', $label) === 1) {
            $errors[] = 'saml.button_label: 40자 이내여야 합니다';
        }
        if ($s['enabled'] ?? false) {
            if ((string) ($s['idp_entity_id'] ?? '') === '' || $sso === '' || (string) ($s['idp_cert'] ?? '') === '' || (string) ($s['attr_login'] ?? '') === '') {
                $errors[] = 'saml.enabled: true이면 idp_entity_id·idp_sso_url·idp_cert·attr_login이 필요합니다';
            }
            if (!self::redirectBaseOk($publicBase)) {
                $errors[] = 'saml.enabled: 공개 주소(oidc.redirect_base 또는 app.base_url)가 https여야 합니다(루프백 개발 주소만 http)';
            }
        }
        return $errors;
    }

    /**
     * SAML 인증서 값(base64 한 줄) → {pem, not_after(UTC 시각), fingerprint(SHA-256 16진수)}. 읽을 수 없으면 null.
     *
     * @return array{pem:string, not_after:int, fingerprint:string, subject:string}|null
     */
    public static function samlCertInfo(string $b64): ?array
    {
        if (strlen($b64) > 8000 || preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $b64) !== 1) {
            return null;
        }
        $der = base64_decode($b64, true);
        if ($der === false || $der === '') {
            return null;
        }
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n";
        $info = @openssl_x509_parse($pem);
        while (openssl_error_string() !== false) {
            // 오류 큐 비우기
        }
        if (!is_array($info) || !isset($info['validTo_time_t'])) {
            return null;
        }
        $cn = is_array($info['subject'] ?? null) ? (string) ($info['subject']['CN'] ?? '') : '';
        return ['pem' => $pem, 'not_after' => (int) $info['validTo_time_t'], 'fingerprint' => hash('sha256', $der), 'subject' => mb_substr($cn, 0, 200)];
    }

    /** https://호스트[:포트][/경로] — 사용자 정보·질의·조각·공백 없음, 500자 이내 */
    public static function httpsUrl(string $url): bool
    {
        if (strlen($url) > 500 || preg_match('#^https://[A-Za-z0-9.\-]+(:\d{1,5})?(/[^\s?\#]*)?$#', $url) !== 1) {
            return false;
        }
        return !str_contains((string) parse_url($url, PHP_URL_HOST), '..');
    }

    /** 리다이렉트 공개 주소: https, 또는 루프백(127.0.0.1·localhost·[::1])의 http(개발) */
    public static function redirectBaseOk(string $base): bool
    {
        if (self::httpsUrl($base)) {
            return true;
        }
        return preg_match('#^http://(127\.0\.0\.1|localhost|\[::1\])(:\d{1,5})?(/[^\s?\#]*)?$#', $base) === 1;
    }

    /** @param array<string, mixed> $l @return list<string> 서버 주소(새 키 servers, 비었으면 예전 server_url) */
    public static function ldapServers(array $l): array
    {
        $raw = trim((string) ($l['servers'] ?? ''));
        if ($raw === '' && is_string($l['server_url'] ?? null)) {
            $raw = $l['server_url'];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    /** @param array<string, mixed> $l CA 파일(새 키 ca_file, 비었으면 예전 ca_cert_file) */
    public static function ldapCaFile(array $l): string
    {
        $ca = trim((string) ($l['ca_file'] ?? ''));
        return $ca !== '' ? $ca : trim((string) ($l['ca_cert_file'] ?? ''));
    }

    private static function balancedParens(string $s): bool
    {
        $depth = 0;
        foreach (str_split($s) as $ch) {
            $depth += $ch === '(' ? 1 : ($ch === ')' ? -1 : 0);
            if ($depth < 0) {
                return false;
            }
        }
        return $depth === 0;
    }

    /**
     * 여러 키에 걸친 검사. 오류 문장 목록을 돌려준다.
     *
     * @param array<string, array<string, mixed>> $v 형식 변환이 끝난 값
     * @return list<string>
     */
    public static function crossCheck(array $v): array
    {
        $errors = [];
        if ($v['disk']['block_percent'] <= $v['disk']['warn_percent']) {
            $errors[] = 'disk.block_percent: warn_percent보다 커야 합니다';
        }
        if ($v['upload']['chunk_size'] >= $v['upload']['max_zip_size']) {
            $errors[] = 'upload.chunk_size: max_zip_size보다 작아야 합니다';
        }
        if ($v['worker']['heartbeat_stale'] < 2 * $v['worker']['heartbeat_interval']) {
            $errors[] = 'worker.heartbeat_stale: heartbeat_interval의 2배 이상이어야 합니다';
        }
        if (in_array($v['runs']['detail_keep'], [1, 2], true)) {
            $errors[] = 'runs.detail_keep: 0(자동 삭제 끔)이거나 3 이상이어야 합니다';
        }
        if ($v['ui']['defect_page_size'] > $v['ui']['page_size_max']) {
            $errors[] = 'ui.defect_page_size: page_size_max 이하여야 합니다';
        }
        $default = $v['repository']['default_interval'];
        if ($default !== 0 && $default < $v['repository']['min_interval']) {
            $errors[] = 'repository.default_interval: 0(사용 안 함)이거나 min_interval 이상이어야 합니다';
        }
        foreach (self::alertMailErrors($v['alert_mail']) as $e) {
            $errors[] = $e;
        }
        foreach (self::ldapErrors($v['ldap']) as $e) {
            $errors[] = $e;
        }
        foreach (self::oidcErrors($v['oidc'], (string) ($v['app']['base_url'] ?? '')) as $e) {
            $errors[] = $e;
        }
        $samlBase = (string) ($v['oidc']['redirect_base'] ?? '') !== '' ? (string) $v['oidc']['redirect_base'] : rtrim((string) ($v['app']['base_url'] ?? ''), '/');
        foreach (self::samlErrors($v['saml'], $samlBase) as $e) {
            $errors[] = $e;
        }
        if ($v['backup']['retention_max_count'] !== 0 && $v['backup']['retention_max_count'] < $v['backup']['retention_min_count']) {
            $errors[] = 'backup.retention_max_count: 0(제한 없음)이거나 retention_min_count 이상이어야 합니다';
        }
        if ($v['security']['require_https'] && !str_starts_with((string) $v['app']['base_url'], 'https://')) {
            $errors[] = 'app.base_url: security.require_https가 true이면 https:// 주소여야 합니다';
        }
        return $errors;
    }
}
