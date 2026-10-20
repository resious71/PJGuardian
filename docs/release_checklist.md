# 릴리스 체크리스트 (1.0.0, P32)

운영 서버에 PJGuardian을 올리기 전·올린 직후에 위에서부터 확인한다. 각 줄의 자세한 절차는 오른쪽 문서를 따른다. 이 단계(P32)의 인수 시험 결과 요약과 릴리스 판정은 맨 아래 "인수 시험 결과"에 있다.

## 1. 묶음 준비 (빌드 PC)

- [ ] 커밋한 뒤 `php tools/build_bundle.php --zip`(작업 트리 변경이 있으면 멈춤 — `--force` 묶음은 시험용). `BUNDLE.json`의 `app_version`이 `1.0.0`, `dirty: false`인지 본다. → `docs/vendor_build.md` 4절
- [ ] 스크립트가 끝에서 `tools/verify_bundle.php`를 돌려 "묶음 점검 통과"인지(vendor·가이드·FAQ 시드·**도구 스모크 시료 `samples/`**·설정/비밀/데이터 없음).
- [ ] 묶음 zip과 `SHA256SUMS`를 운영 서버로 옮긴다. 분석 도구(`bin/`)는 묶음과 따로(인터넷이 막힌 서버면 `--with-bin` 묶음 또는 `bin/<도구>/<버전>` 폴더 복사). → `docs/tool_setup.md`, `docs/vcs_tools.md`

## 2. 설치 또는 업그레이드 (운영 서버)

새 설치는 `docs/install_guide.md` 1~9절, 옛 PC 데이터 이전은 10절, 업그레이드는 11절.

- [ ] **업그레이드라면 미리 알리고 유지보수 모드로**(P32B): 설정 관리 > 유지보수에서 예정 시각·안내 문구 저장(모든 사용자 화면에 사전 공지) → 시각이 되면 "업그레이드 유지보수 모드"를 켬. 켜는 순간 워커가 `pre_upgrade` DB 백업을 먼저 하고, 실패하면 켜지지 않는다(워커가 실행 중이어야 함). 켜진 동안 관리자가 아닌 사용자는 조회만, 워커는 새 분석을 시작하지 않는다. → 관리자 가이드 "배포 묶음 > 업그레이드 때"
- [ ] 화면에서 **키 내보내기**(`.pjgkey`, 암호문구 따로 보관). 명령줄로만 할 때는 `php tools/backup.php --kind=pre_upgrade`로 직접 백업. → `docs/backup_restore.md`
- [ ] 진행 중인 분석이 끝나면(시스템 관리 > 워커에서 실행 중 0) 워커 정지: `php tools/worker.php --stop` → `--status`가 "실행 중 아님".
- [ ] 코드 교체: `config/config.ini`·`config/secrets.ini`·`config/settings.local.ini*`·`data/`·`bin/`·백업 폴더는 덮어쓰지 않는다. `vendor/`·`samples/`·`resources/`는 새 묶음 것으로.
- [ ] **마이그레이션 적용 순서**: `php tools/migrate.php`가 `migrations/001_…`부터 `031_alert_mail_state.sql`까지 번호 순서로, 아직 적용하지 않은 것만 적용한다(파일을 고르거나 건너뛰지 않는다). 끝나면 `php tools/migrate.php --status`가 모두 "적용됨". 웹 요청은 DB 구조를 바꾸지 않는다.
- [ ] `php tools/check_env.php` — FAIL 0(PHP 8.2 이상, intl·pdo_sqlite·zip·mbstring·openssl·fileinfo, 폴더 쓰기, 디스크).
- [ ] 업그레이드라면 워커를 다시 시작하고 확인한 뒤 **유지보수 모드를 끈다**(화면 설정 파일에 남아 있어 업그레이드 뒤에도 켜진 상태).
- [ ] 서버 사양·디스크: 시스템 관리 > 점검 > 용량 추정 도우미로 계산한 값 이상인지(명세 19.1 기본: CPU 8코어·메모리 32GB). → `docs/server_sizing.md`
- [ ] 새 설치라면 `php tools/create_admin.php --login=<ID> --name=<이름>`(초기 비밀번호 `admin`).

## 3. 설정 확인

- [ ] `config/config.ini`: `[app] base_url`(실제 https 주소), `[app] env = prod`, `[security] require_https = true`, `hsts_max_age`, 경로(데이터·백업 폴더 — 백업은 **다른 디스크**), `[tools] node_path`(서비스 계정에서 보이는 Node 24).
- [ ] `config/secrets.ini`: `encryption_key`가 있다(`php tools/make_secret_key.php`, 값은 출력되지 않음). 파일 권한은 관리자·서비스 계정만(Linux `0640`). → `docs/install_guide.md` 5절
- [ ] 개발용 예외가 꺼져 있다: `[repository] allow_local_dev`, `[ldap]`·`[oidc]`·`[saml] allow_local_dev`가 `false`(켜면 로그에 경고).
- [ ] `php tools/config_check.php` — 오류 없음.
- [ ] 화면 **시스템 관리 > 설정 관리**에서 보존 기간·디스크 기준·알림·백업 주기를 운영 값으로(변경은 감사 로그 `config.update`).

## 4. 워커·정리 작업 등록

- [ ] 워커를 서비스(NSSM·systemd) 또는 작업 스케줄러(1분마다, 이미 있으면 새로 띄우지 않음)로 등록. 늘 **1개만**. → `docs/worker_setup.md` 3·4절, `docs/install_guide.md` 8절
- [ ] 정리 작업 `php tools/cleanup.php`를 매일(예: 03:00) 등록.
- [ ] `php tools/worker.php --status` — "실행 중(잠금 있음)", 생존 신호 최근.
- [ ] **시스템 관리 > 분석 도구**: ruff·ESLint 활성 버전이 선택돼 있다(체크섬·스모크 통과). "시료 없음"이면 묶음에 `samples/`가 빠진 것(1절).

## 5. 첫 로그인과 하드닝

- [ ] **초기 관리자 비밀번호(`admin`)를 바로 바꾼다.** 바꾸기 전까지 관리자 화면에 경고 배너, 하드닝 점검·모니터링이 경고한다. → `docs/hardening.md` 0절
- [ ] `php tools/create_admin.php --check-only`(하드닝 점검) — 경고 0을 목표로. 시스템 관리 > 점검 탭의 보안 줄 확인.
- [ ] XAMPP 샘플·관리 페이지 삭제, 불필요한 Apache 모듈 끄기, MySQL을 안 쓰면 서비스 끄기. → `docs/hardening.md` 1~3절

## 6. HTTPS·HSTS·외부 노출

- [ ] Apache vhost: DocumentRoot = `public/`, `FallbackResource /index.php`, HTTP → HTTPS 301. → `docs/xampp_vhost.md`
- [ ] `curl -skI https://<주소>/login`에 `Strict-Transport-Security`가 **한 줄**(앱 한 곳에서만 보냄 — vhost의 `Header always set Strict-Transport-Security`는 지운다), `Content-Security-Policy`에 `script-src 'self'`.
- [ ] `https://<주소>/data/…`, `/config/config.ini`, `/bin/…`, 백업 폴더가 모두 404. → `docs/xampp_vhost.md` 4절, `docs/install_guide.md` 3.6절

## 7. 방화벽·프록시·헬스체크

- [ ] 방화벽: 443(필요하면 80 → 443 이동용)만 연다. 워커는 외부로 나가는 연결만(허용 호스트로 등록한 Git·Perforce, IdP, SMTP).
- [ ] 앞단 프록시가 있으면 설정 관리 > 모니터링 "신뢰 프록시"에 프록시 주소만(P32B — 그래야 로그인 시도 제한·감사 IP·HTTPS 판정이 실제 사용자 기준). 점검 탭 "신뢰 프록시" 줄이 "확인 필요"가 아닌지. → `docs/hardening.md` 4a절
- [ ] `/health`: 설정 관리 > 모니터링 "헬스체크 허용 IP"에 감시 도구(또는 앞단 프록시) 주소만. 허용 밖은 404, 정상·경고 200, 오류 503. 프록시 뒤라면 프록시에서 `/health`를 감시 도구에만 연다. → `docs/monitoring.md` 6절
- [ ] 프록시 시간 상한이 계정 CSV 1,000행(약 78초)보다 길다. → `docs/performance.md` 6절

## 8. 백업·로그 보관

- [ ] 시스템 관리 > 백업·복원: 자동 백업 켬(주기·보관 개수), **지금 백업 → 검증 → 복원 리허설** 한 번, **키 내보내기** 보관(묶음과 다른 곳).
- [ ] 로그 보관: `[log] error_access_retention`(기본 30일), 감사 로그 `audit_retention`(기본 90일, DB 밖 기록 `data/logs/audit-anchor.jsonl`), 알림 30일. 정리 작업이 매일 지운다. 감사 로그를 더 오래 보관해야 하면 CSV 내보내기를 주기적으로 보관.
- [ ] 관리자 이메일(선택): 설정 관리 > 관리자 이메일 → 시험 메일 → 받은 편지함(스팸함) 확인. → `docs/monitoring.md` 4절

## 9. 외부 연동(쓰는 것만)

- [ ] 저장소 연동: 허용 호스트 등록 → 연결 테스트 → 프로젝트 연결 → 지금 가져와 분석. → `docs/repo_integration.md`
- [ ] LDAP/AD·OIDC·SAML: 설정 관리 탭의 연결 시험 → 시험 계정 첫 로그인 → 승인. IdP에 등록할 콜백 주소는 `base_url` 기준. → `docs/auth_ldap.md`, `docs/auth_oidc.md`, `docs/auth_saml.md`

## 10. 롤백 절차

문제가 생기면 업그레이드 전 상태로 되돌린다(데이터는 업그레이드 직전 백업 시점으로 돌아간다 — 그 뒤 변경은 잃는다).

1. 워커 정지(`php tools/worker.php --stop`), 유지보수 모드를 켜 두었으면 그대로 두고(사용자 쓰기 차단), 아니면 Apache 정지 또는 사용자 공지.
2. 코드를 이전 묶음으로 되돌린다(설정·`data/`·`bin/`은 그대로).
3. `php tools/restore.php --file=<업그레이드 직전 pre_upgrade 묶음 — 유지보수 모드를 켤 때 만든 것> --dry-run` → `--yes`로 복원(키를 바꾸지 않았으면 `--key` 불필요). 묶음 스키마가 이전 앱보다 새것이면 거부되므로 **반드시 업그레이드 직전 묶음**을 쓴다.
4. `php tools/migrate.php --status`, 워커 다시 시작, 로그인·대표 프로젝트 확인.
5. 복원 도중 실패하면 자동으로 되돌려진다. 유지보수 표시가 남으면 `php tools/restore.php --leave-maintenance`. → `docs/install_guide.md` 10.9절, `docs/backup_restore.md` 7절

## 10a. 인수인계

- [ ] 운영 담당자가 바뀌면 `docs/handover_checklist.md`(영어 `handover_checklist.en.md`)를 함께 채운다(비밀 값은 적지 않고 보관 위치만).

## 11. 운영 서버에서 사용자가 직접 할 확인

자동 시험으로 대신할 수 없어 운영 환경에서 확인해야 하는 것. 순서와 방법은 `docs/unverified.md` "운영 반영 순서"를 따른다(Linux → Apache https·HSTS → 방화벽/프록시 뒤 `/health` → 실제 LDAP/AD·IdP → 실제 SMTP·인증서 → 실제 Excel로 열기 → 스크린 리더).

## 인수 시험 결과 (P32, 2026-10-14, Windows 11 — 개발 PC)

- 릴리스 묶음(1.0.0)을 새 폴더에 풀어 설치 가이드대로 설치한 설치본(가짜 설정·새 DB·별도 포트·별도 워커)에서 `node tools/acceptance/run.mjs`로 16개 시나리오를 끝에서 끝까지 확인했다: **16개 모두 통과, 확인 169개 실패 0**(`docs/results/P32_acceptance/results.md`, 캡처 같은 폴더).
  설치 → 계정·권한 → 업로드(대형·이어 올리기·폴더) → 분석(실패·재시도·취소·큐) → 결함 → 대시보드·Run(15개 보관) → 규칙 → 내보내기·알림 → 저장소·주기 분석(가짜 Git, 연결 실패 재시도) → 외부 인증(가짜 LDAP·OIDC·SAML) → 설정·모니터링·/health·관리자 이메일(가짜 수신기) → 백업 → **다른 새 폴더에 키와 함께 복원** → 감사 로그 → 가이드·FAQ → 보안 점검 → 정리·보존.
- 그 과정에서 찾은 **릴리스 차단 결함 2건은 고쳤다**(묶음에 도구 스모크 시료 `samples/` 누락 → 새 설치에서 분석 불가 / 키를 가져오는 복원이 secrets.ini 한글 주석을 깨뜨려 실패). 비차단 2건도 고쳤다(설정 덧씌우기 순서, 워커가 같은 초의 두 번째 저장을 놓침). 모두 시험을 더했다.
- 전체 시험(`php tools/run_all_tests.php`, 프로토타입 삭제·수정 뒤): PHP 828·실패 0, JS 213, 아이콘, 1단계 E2E 12 — 전체 통과.
- **릴리스 차단 항목: 0건.** 남은 비차단 항목(명세 일치표의 미구현 6건, 쓰이지 않는 문구 2개, 영어 대시보드 콤보박스 잘림, 개발 PC 성능 목표)은 `docs/results/P32.md` 발견한 문제 표.
- 릴리스 조건: ① 커밋한 뒤 `--force` 없이 묶음을 다시 만든다(지금 `dist/`의 묶음은 `dirty: true` 시험용). ② 운영 서버에서 11절 확인(Linux·Apache https·실제 외부 시스템 — 개발 PC에서는 가짜로만 확인).
