# 설정 관리 (P27A)

관리자가 `config.ini`를 직접 열지 않고 **관리자 메뉴 "시스템 관리" > "설정 관리" 탭**(`/admin/config-settings`, admin만 — X10 전에는 별도 메뉴, 주소는 그대로)에서 운영 값을 확인하고 바꾼다.
`config.ini` 전체 편집기가 아니다 — **허용 목록에 등록된 키만** 바꿀 수 있고, 민감·운영 인프라 값은 편집도 표시도 하지 않는다.

DB에 저장하는 화면 표시 설정(대시보드 추이 프로젝트 수, 알림 보관 3개 값 — P21C·P24 `app_settings`)은 여기서 다루지 않는다.
"설정 관리" 화면 머리에 그 화면으로 가는 안내 링크만 있다.

## 0. 화면 구성 (P27F 카테고리 탭)

- **P30 모니터링 탭**: 알림 다음에 "모니터링"(`health.allowed_ips` — /health 허용 IP, 서버마다 다른 값·`monitoring.queue_stall` — 큐 정체 기준, `docs/monitoring.md`). 감사 로그 보존 `log.audit_retention`은 "보존 기간" 탭(90일 미만으로 줄이면 변경 확인 뒤 한 번 더 확인 대화상자).
- **저장소가 다른 구역(X12)**: "알림" 탭 끝에 **알림 보관 기간**(읽은·안 읽은 알림 보관 일수, 사용자당 최대 개수 — DB `app_settings`, 예전 별도 탭 "알림 보관"), "저장소 연결" 탭 맨 위에 **허용 호스트**(목록·추가·수정·삭제·GitHub 호환·행별/전체 연결 테스트·인증서 만료·Perforce 지문 — DB 표, 예전 별도 탭 "저장소 허용 호스트"). 이 값들은 settings.local.ini가 아니라 **각 구역의 단추로 바로 저장**되고(아래 "설정 항목 변경 확인"(X13 전 이름 "변경 내용 확인") 단추와 별개), 저장 전 변경은 구역 제목 옆 "저장하지 않은 변경 N건"·탭 머리 건수·바깥 탭 이동 확인에 함께 센다. 검색은 구역의 이름·라벨·호스트도 찾는다. 옛 주소 /admin/notifications·/admin/repo-hosts는 이 탭들로 이동한다.
- **바깥 탭(X10, X12)**: 화면 위는 "시스템 관리"의 탭 줄(분석 도구 · 워커 · 점검 · Run 관리 · 규칙 설정 · **설정 관리** · 주기 분석 · 백업·복원 — 각각 다른 화면 주소, 메뉴는 분석 도구를 엶). 그 아래 카테고리 탭은 한 단계 작은 폴더 모양으로 구분한다.
  저장 전 변경이 있는 채로 바깥 탭을 누르면 "저장하지 않은 변경 N건" 확인 대화상자(취소 = 그대로 남음, "저장하지 않고 이동" = 이동). 카테고리 탭 사이에서는 P27F처럼 입력이 유지된다.
- **묶음마다 탭 하나**: 보존 기간 / 디스크 / 알림 / 주기 분석·가져오기 / 저장소 연결 / 분석 / 백업 / 계정 승인 / LDAP/AD / SSO(OIDC) / SSO(SAML)(지금 11개). 탭 목록은 화면 코드에 따로 두지 않고
  API 응답의 `groups`(= `ConfigSchema::EDITABLE_GROUPS` 순서, 그 뒤에 등록부에만 있는 묶음)와 항목의 `group`에서 만든다(`config_settings_core.tabsFrom`). 항목이 없는 묶음은 탭을 만들지 않는다.
- **주소 해시**: `#<묶음>`(예: `/admin/config-settings#backup`)으로 그 탭이 열린다. 예전 링크 `#group-<묶음>`(P27B·P27E)도 같은 탭을 열고 주소를 `#<묶음>`으로 고쳐 쓴다. 해시가 없거나 모르는 값이면 첫 탭. 마지막 탭은 기억하지 않는다.
  다른 화면의 링크: 백업·복원 탭 요약의 "폴더 변경"·"주기 변경" → `#backup`. 점검(시스템 모니터링) 화면에는 설정 관리로 가는 링크가 없다(P27F 조사).
- **탭 키보드**(WAI-ARIA tabs): ←→로 이동하며 선택, Home·End, 지금 탭만 Tab 순서. 선택한 탭은 밑줄 + 굵게(색만으로 구분하지 않음).
- **탭 안은 한 열 폼 목록**: 항목마다 이름·출처 배지·"이 PC 전용" 배지·도움말(왼쪽) | 입력·형식 안내·오류·쓰이지 않는 이유·경로 확인 결과·"기본값으로 하면 X"·반영 상태·"기본값으로"(오른쪽, 1100px 이하는 위아래). 구역 사이는 구분선.
  백업 탭의 디스크 영향 추정은 "보관 개수 상한" 항목 바로 아래. "모두 펼치기/접기"는 없다.
- **한 폼 상태**: 모든 패널을 그려 두고 숨기기만 하므로 탭을 옮겨도 입력이 남는다. "설정 항목 변경 확인"(X13 전 이름 "변경 내용 확인")·"변경 취소"는 모든 탭의 변경을 한꺼번에(저장은 그대로 전체 성공·전체 실패).
  저장·다시 불러오기 뒤에도 지금 탭 유지. 변경 이력은 탭 아래 공통 영역.
- **탭 머리**: 바꾼 항목 수(숫자), 입력 오류(아이콘 + "오류 n"), 검색 중이면 일치 건수 "(n)"(0건 탭은 보조 색). 접근 가능한 이름에 같은 내용을 글로("분석, 변경 2건, 입력 오류 1건").
- **저장 시도와 오류**: 막는 오류(형식·범위, 확인하지 않은/실패한 경로, 서버가 거부한 항목)가 있으면 "설정 항목 변경 확인"(X13 전 이름 "변경 내용 확인")을 눌렀을 때 대화상자 대신 **첫 오류 탭(탭 순서 → 항목 순서)으로 옮겨 그 항목에 포커스**하고 이유를 한 줄 보인다.
  단추는 바꾼 것이 있으면 눌린다(막힘은 `data-blocked="1"`). 서버 422도 같은 방식으로 이동.
- **검색**: 모든 탭의 이름·도움말·키·묶음 이름. 지금 탭은 맞는 항목만, 지금 탭에 없고 다른 탭에 있으면 "이 탭에는 … 다른 탭에 N건" + 첫 일치 탭으로 가는 단추, 모두 없으면 "찾는 설정이 없습니다."
- **새 탭**: 새 묶음 이름으로 키를 등록하면(7절) 탭이 저절로 생긴다 — 순서를 정하려면 `EDITABLE_GROUPS`에 넣고, 이름은 `config_settings.group.<묶음>`(ko·en JS). 화면 코드는 고치지 않는다.

## 1. 저장 위치와 우선순위

- 화면에서 바꾼 값은 **`config/settings.local.ini`**(config.ini와 같은 폴더)에만 쓴다. `config.ini`·`secrets.ini`·DB는 쓰지 않는다.
  DB에 두지 않는 이유: 복원(P27B)이 DB를 통째로 바꾸므로, 백업 폴더 같은 PC별 값이 복원으로 되돌아가지 않게.
- 유효값 우선순위(허용 목록 키): **환경 변수 `PJG_<섹션>_<키>` > `settings.local.ini` > `config.ini` > 형식 기본값.**
  예: `PJG_DISK_WARN_PERCENT=75`. 환경 변수가 정한 키는 화면에서 읽기 전용(눈 아이콘과 안내)이다. 허용 목록 밖 키의 `PJG_…` 환경 변수는 무시한다
  (시크릿 `PJG_ENCRYPTION_KEY` 등은 기존 규칙 그대로).
- 화면은 항목마다 **출처 배지**(기본값 / config.ini / 화면 설정 / 환경 변수)와 "기본값으로 하면 X(출처)"를 보여 준다. "기본값으로"는 화면 설정을 지워
  config.ini 값(없으면 형식 기본값)으로 돌아간다.
- 파일 쓰기: 잠금 전용 파일(`settings.local.ini.lock`) 안에서 다시 읽어 **버전 표지**(파일 내용 SHA-256)를 비교 → 임시 파일 → `fsync` → 이름 바꾸기(원자적).
  쓰기 도중 프로세스가 죽어도 원본은 그대로이고 남은 임시 파일은 다음 쓰기가 지운다. 이전 파일은 `settings.local.ini.bak.1`(가장 최근)~`.bak.5`로 순환 보관.
- 동시 편집: 화면이 읽은 뒤 다른 관리자가 먼저 바꿨으면 409 `settings_conflict` → "다시 불러오기". 내용이 같아진 경우(바꿨다가 되돌림)는 충돌로 보지 않는다.
- 파일이 깨졌거나 값이 틀리면 **그 키만 무시**하고 config.ini·기본값으로 기동하며, 화면 상단에 무시한 값과 이유를 보여 준다
  (`file_unreadable` 문법 오류 — 파일 전체 무시, `not_editable` 허용 목록 밖 키, `invalid_value` 형식·범위, `cross_check` 키 간 규칙, `env_invalid` 환경 변수 값).
  깨진 파일은 다음 저장 때 `.bak.1`로 남고 새로 쓴다.
- **P32B 추가 키**: `security.trusted_proxies`(모니터링 탭 — `security.*` 가운데 유일하게 화면 편집, `ConfigSchema::NOT_EDITABLE_EXCEPTIONS`), `maintenance.enabled`·`maintenance.message`·`maintenance.notice_at`(유지보수 탭 — 켜기는 직전 백업이 성공해야 저장, `maintenance.on/off` 감사). 날짜·시각 형식 `datetime`(표시 시간대 `YYYY-MM-DD HH:MM`, 있는 날짜만). `maintenance.backup_wait`(직전 백업 대기 상한)는 config.ini에서만.
- 키 간 규칙은 **적용 순서와 무관하게** 본다(P32): 파일에는 키가 알파벳 순서로 적히므로, 하나씩 검사하면 한 번에 저장한 값(예: 관리자 이메일 켜기 + SMTP 서버, 경고·차단 비율을 함께 낮추기)의 앞 키가 아직 덧씌우지 않은 config.ini 값과 어긋나 버려졌다. 지금은 더 적용할 수 없을 때까지 여러 번 돌고, 남은 것은 한꺼번에 맞으면 함께 적용, 아니면 그 키만 `cross_check`로 무시한다(`Config::overlay`, 시험 `ConfigSettingsTest::testOverlayIsOrderIndependentForCrossChecks`). 같은 키는 환경 변수 값이 우선(후보 하나).
- 권한(X14 방침): 웹 서버·워커를 실행하는 계정에 **`config/` 폴더 전체의 쓰기를 허용**한다(임시 파일·`.bak.N`·잠금 파일을 같은 폴더에 만들기 때문). 없으면 저장이 실패하고 로그에 남는다.
  Linux에서 `settings.local.ini`는 0640(웹 서버 계정이 쓰고 워커 계정이 읽을 수 있게 같은 그룹). `config.ini`·`secrets.ini`는 앱이 쓰지 않는다.
  위험: 웹 계정이 `config/`에 쓸 수 있으면 웹 애플리케이션 취약점이 `config.ini`·`secrets.ini` 변조(설정·비밀 바꿔치기)로 이어질 수 있다. 완화: ① 파일 단위로 `config.ini`·`secrets.ini`는 가능한 한 웹 계정에 읽기 전용으로 두는 편이 더 안전하다(Linux는 두 파일을 root 소유 0640 등으로, Windows는 웹 계정에 폴더 읽기 + `settings.local.ini`와 그 임시·백업·잠금 파일(`settings.local.ini*`)에만 쓰기를 줄 수 있는지 — **운영 환경에서 확인할 것**). ② `config/`는 웹으로 열리지 않고(DocumentRoot는 `public/`), 설정 관리 화면이 쓰는 파일은 `settings.local.ini`(와 그 임시·백업·잠금 파일)뿐이다.
- `.gitignore`: `/config/settings.local.ini*`(백업·잠금·임시 파일 포함).

## 2. 반영 시점

- **웹**: 요청마다 설정 파일을 다시 읽으므로(`App::create` → `Config::fromRoot`) 저장 즉시 반영된다.
- **워커**: 루프마다 `settings.local.ini`의 수정 시각·크기·내용 해시(P32 — 같은 초에 같은 길이로 다시 저장해도 잡음)를 확인해 바뀌었으면 설정을 다시 읽고 `App::replaceConfig()`로 바꾼다(재시작 없음).
  워커가 쓸 때마다 설정에서 읽는 값은 **다음 작업부터** 새 값이다(분석 시간 상한·동시 수 → 새로 시작하는 분석, 가져오기 시간 상한 → 새 가져오기 등).
  분석·업로드·관리 작업 자식 프로세스는 시작할 때 설정 파일을 직접 읽는다. 반영한 버전은 생존 신호(`config.version`)에 남고, 화면 항목 옆에
  "적용됨 / 워커가 곧 다시 읽습니다 / 워커가 꺼져 있음 — 다음 시작 때 적용 / 워커를 다시 시작해야 반영"을 보여 준다.
- 등록부의 `restart`: `none`(위처럼 재시작 없이), `worker`(워커 시작 때만 읽는 값 — 다시 읽은 뒤 값이 다르면 생존 신호 `config.pending_restart`에 올리고
  화면에 "워커를 다시 시작해야 반영" 배너), `web`(예약). **현재 허용 목록 36개는 모두 `none`**이다(0절 조사: 워커가 쓰는 값은 모두 쓸 때마다 읽거나
  매번 새로 만드는 서비스에 들어가고, 재사용 서비스인 디스크 감시·업로드는 설정을 바꿀 때 다시 만든다).

## 3. 허용 목록(편집 가능, 96개 — P27A 22개 + P27B 백업 9개 + P27E 자동 백업 5개 + P28B 계정 승인 2개·LDAP/AD 14개 + P28C SSO(OIDC) 14개 + P28D SSO(SAML) 14개 + P30·X12 3개 + P30B 관리자 이메일 13개)

| 묶음 | 키 | 형식·범위 | 비고 |
| --- | --- | --- | --- |
| 보존 기간 | `upload.incomplete_chunk_retention` | 기간 1h~30d | 정리 작업 |
| 보존 기간 | `upload.failed_zip_retention` | 기간 1h~30d | 정리 작업 |
| 보존 기간 | `upload.confirmation_retention` | 기간 1h~30d | 정리 작업 |
| 보존 기간 | `log.error_access_retention` | 기간 1d~3650d | 정리 작업(감사 로그 제외) |
| 보존 기간 | `snapshot.full_keep_count` | 정수 1~100 | 분석 반영·정리 |
| 보존 기간 | `runs.detail_keep` | 정수 0~10000(1~2 금지) | 워커(분석 뒤 Run 상세 보관) |
| 디스크 | `disk.warn_percent` | 0~100(%) | 차단보다 작아야 함 |
| 디스크 | `disk.block_percent` | 0~100(%) | |
| 알림 | `ui.notification_poll_seconds` | 정수 15~3600 | 열린 화면은 새로 열 때 |
| 알림 | `notification.cert_expiry_warn` | 기간 1d~365d | 허용 호스트 인증서 |
| 주기 분석·가져오기 | `scheduler.tick_seconds` | 기간 10s~1h | 워커 |
| 주기 분석·가져오기 | `scheduler.max_per_tick` | 정수 1~1000 | 워커 |
| 주기 분석·가져오기 | `repository.fetch_timeout` | 기간 1m~24h | 새 가져오기부터 |
| 주기 분석·가져오기 | `repository.connect_max_attempts` | 정수 1~20 | 재시도(분석 자식) |
| 주기 분석·가져오기 | `repository.connect_retry_interval` | 기간 1s~24h | 재시도 |
| 저장소 연결 | `repository.test_timeout` | 기간 5s~5m | 연결 테스트·"변경 없음" 확인 |
| 저장소 연결 | `repository.test_per_minute` | 정수 1~60 | 사용자당 |
| 저장소 연결 | `repository.host_test_max` | 정수 1~200 | 전체 테스트 |
| 분석 | `analysis.max_concurrent` | 정수 1~32 | 새 분석부터(실행 중인 것은 끝까지) |
| 분석 | `analysis.timeout` | 기간 1s~24h | 새 분석부터 |
| 분석 | `limits.project_defect_warn` | 정수 0~1억(0 끔) | P19C |
| 분석 | `limits.project_defect_max` | 정수 0~1억(0 끔) | P19C |
| 분석 | `upload.max_zip_size` | 크기 1MB~16GB | X21. 새 업로드부터(대기 중인 zip도 해제 때 새 값으로 검사). 청크 크기보다 커야 함(키 간 규칙). 늘리면 업로드마다 디스크 예약도 커짐 |
| 분석 | `extract.max_extracted_size` | 크기 1MB~1024GB | X21. 새 해제·가져오기부터(Git·Perforce 크기 한도 겸용). 디스크 예약 = zip + max(zip, 이 값) |
| 분석 | `extract.max_files` | 정수 1~1000만 | X21. 새 해제·가져오기부터. 100만 개 초과면 `extract.worker_memory_limit`(config.ini)도 늘림 |
| 백업 | `backup.dir` | 경로(PathPolicy) | **PC 고유**(설정 복원 때 기본 해제). 기본 `backups`(프로젝트 바로 아래). 바꿔도 이전 묶음은 옮기지 않음, 백업·복원 진행 중이면 409 `setting_busy` |
| 백업 | `backup.enabled` | 켜기/끄기(기본 켬) | P27E 자동 백업. 끄면 자동 백업·재시도·실패 알림·자동 정리 멈춤 |
| 백업 | `backup.schedule_mode` | 선택 `daily`·`weekly`·`monthly`(기본 daily) | P27E 주기. 바꾸면 지난 예정은 건너뜀 |
| 백업 | `backup.daily_at` | 시각 HH:MM(표시 시간대) | 실행 시각(세 방식 공통, 이름은 P27B 호환). 워커가 틱마다 읽음(재시작 없음) |
| 백업 | `backup.weekly_day` | 선택 `mon`~`sun`(기본 sun) | P27E 매주일 때만 씀(그 밖엔 화면에서 흐리게) |
| 백업 | `backup.monthly_day` | 선택 `1`~`31`·`last`(기본 1) | P27E 매월일 때만 씀. 그 달에 없으면 말일 |
| 백업 | `backup.retention_days` | 정수 1~3650 | 자동 백업 성공 뒤 정리(꺼져 있으면 정리 안 함) |
| 백업 | `backup.retention_min_count` | 정수 1~100 | 오래돼도 최근 N개 보관 |
| 백업 | `backup.retention_max_count` | 정수 0~1000(0 = 제한 없음) | P27E. 0이거나 최소 보관 개수 이상(키 간 규칙) |
| 백업 | `backup.safety_retention_days` | 정수 1~3650 | pre_restore·pre_upgrade |
| 백업 | `backup.rehearsal_interval_days` | 정수 1~3650 | 리허설 기한 알림 |
| 백업 | `backup.backup_stale_hours` | 정수 1~8760 | 화면 경고 |
| 백업 | `backup.restore_wait_max_minutes` | 정수 1~240 | 복원 전 대기 상한 |
| 백업 | `backup.upload_max_mb` | 정수 1~65536 | 묶음 올리기 |
| 계정 승인 | `auth.pending_max` | 정수 1~1000(기본 50) | 승인 대기·거절 계정 상한(P28B) |
| 계정 승인 | `auth.pending_retention_days` | 정수 1~365(기본 30) | 대기·거절 계정 보관 기간 |
| LDAP/AD | `ldap.enabled`, `ldap.servers`, `ldap.use_starttls`, `ldap.ca_file`, `ldap.base_dn`, `ldap.user_filter`, `ldap.bind_dn`, `ldap.attr_login`·`attr_name`·`attr_email`·`attr_id`·`attr_disabled`, `ldap.connect_timeout`·`search_timeout` | 켜기/끄기·문자열·기간(교차 검사 `ConfigSchema::ldapErrors`) | P28B, `docs/auth_ldap.md`. `ldap.bind_dn`은 감사·변경 이력에 `***`. 검색 계정 비밀번호는 같은 탭의 별도 입력(암호화, 설정 파일 아님) |
| SSO(OIDC) | `oidc.enabled`, `oidc.issuer`, `oidc.client_id`, `oidc.client_auth`, `oidc.scopes`, `oidc.claim_login`·`claim_name`, `oidc.use_userinfo`, `oidc.allowed_algs`, `oidc.button_label`, `oidc.provider_logout`, `oidc.redirect_base`(이 PC 전용), `oidc.jwks_cache_ttl`·`jwks_stale_max` | 켜기/끄기·문자열·목록·기간(교차 검사 `ConfigSchema::oidcErrors` — https·알고리즘 RS256/ES256·클레임 이름·40자) | P28C, `docs/auth_oidc.md`. 클라이언트 비밀은 같은 탭의 별도 입력(암호화, 마이그레이션 027). 리다이렉트 URI·로그아웃 후 주소 표시·복사, 연결 시험. `oidc.start_per_minute`·`test_per_minute`·`allow_local_dev`는 편집 안 함 |
| SSO(SAML) | `saml.enabled`, `saml.idp_entity_id`, `saml.idp_sso_url`, `saml.idp_cert`·`idp_cert_next`(롤오버), `saml.sp_entity_id`, `saml.name_id_format`, `saml.id_source`, `saml.attr_login`·`attr_name`·`attr_email`, `saml.sign_requests`, `saml.want_encrypted`, `saml.button_label` | 켜기/끄기·문자열·선택(교차 검사 `ConfigSchema::samlErrors` — https·X.509 인증서·속성 이름(URI)·transient 조합·40자) | P28D, `docs/auth_saml.md`. 같은 탭에 IdP 메타데이터 가져오기(칸 채우기만)·인증서 만료일·SP 정보 복사/메타데이터 내려받기·SP 키 생성(암호화, 027)·설정 확인. 긴 값(인증서)은 기본값 안내 줄에서 줄여 보임. `saml.start_per_minute`·`test_per_minute`·`allow_local_dev`는 편집 안 함 |
| 관리자 이메일 | `alert_mail.enabled`(기본 끔), `alert_mail.smtp_host`(이 PC 전용)·`smtp_port`(1~65535, 587), `alert_mail.smtp_tls`(`none`·`starttls`·`smtps`), `alert_mail.smtp_auth`(`none`·`login`), `alert_mail.smtp_user`, `alert_mail.from_address`·`from_name`, `alert_mail.recipients`(최대 20, 비우면 활성 admin), `alert_mail.language`(`ko`·`en`), `alert_mail.events`(worker_down·queue_stalled·disk·backup·tool·repo·cert), `alert_mail.resend_interval`(10m~1d, 1h), `alert_mail.recovery` | 켜기/끄기·문자열·선택·목록·기간(교차 검사 `ConfigSchema::alertMailErrors` — 서버 이름 형식(스킴·경로·포트 없음)·주소 형식·제어 문자·받는 사람 20개·사건 종류) | P30B, `docs/monitoring.md` 4절. 워커가 다음 판정부터 씀(재시작 없음). SMTP 비밀번호는 같은 탭의 별도 입력(암호화 `mail.smtp_password`, 설정 파일 아님)·발송 상태·평문 경고·시험 메일. `connect_timeout`(10s)·`send_timeout`(30s)·`test_per_minute`(3)·`test_per_hour`(20)·`state_retention`(30d)은 편집 안 함 |

P28B: `[ldap]`·`[auth]`는 예전엔 섹션 전체가 편집 금지(NOT_EDITABLE 접두사)였다 — 이제 위 키만 허용하고 나머지(`auth.password_*`·잠금·세션·bcrypt, `ldap.server_url`·`attr_user_id`·`ca_cert_file`·`test_*`·`allow_local_dev`)는 키 단위로 편집 금지다.

`[backup] config_machine_keys`·`maintenance_stale_minutes`는 화면 편집 대상이 아니다(config.ini). 진행 중인 백업은 시작할 때 읽은 값으로 끝까지 가고(보관 정리는 그 백업이 성공한 뒤 새 값으로), 바꾼 값은 다음 작업부터 쓴다.

## 4. 제외(편집·표시 안 함)와 이유

| 키 | 이유 |
| --- | --- |
| `app.*`(env·base_url·display_timezone), `security.*`, `auth.*` | 접속 주소·보안 헤더·HTTPS·세션·비밀번호 정책 — 잘못 바꾸면 접속·로그인이 막힘 |
| `paths.*`, `database.*` | DB·데이터 폴더 위치, SQLite 잠금·체크포인트 — 운영 인프라(P31 `database.web_wal_autocheckpoint`·`idle_checkpoint_interval` 포함, `docs/performance.md` 3.2) |
| `log.audit_anchor_interval` | (P31) 감사 로그 DB 밖 기록 간격 — 무결성 통제라 화면에서 바꾸지 않음 |
| 시크릿(`secrets.ini`: 암호화 키·SMTP·LDAP 비밀번호) | 민감 값(애초에 설정 표에 없음) |
| `ldap.*`, `smtp.*` | 인증·메일 서버(계정 이름 포함) — 이번 범위 밖. `smtp.*`는 P30B부터 읽지 않음(관리자 이메일은 `alert_mail.*`) |
| `tools.*`, `worker.start_command`, `worker.supervised` | `bin/`·Node 경로, 서버에서 실행할 명령 |
| `health.allowed_ips` | 상태 확인 허용 IP(접근 통제) |
| `repository.ca_cert_file`, `repository.allow_local_dev` | 운영 인프라·개발용 예외 |
| `upload.chunk_size`, `extract.worker_memory_limit`, `extract.default_excludes` | 업로드 청크(클라이언트와 맞물림), 해제 프로세스 메모리, 서버 기본 제외 대상(X21 전까지는 `upload.max_zip_size`·`extract.*` 전체가 여기 — X21에서 크기·파일 수 한도 3개를 분석 탭으로 옮김) |
| `log.audit_retention`, `notification.retention`, `notification.admin_alert_dedupe`(P30B부터도 읽지 않음 — `alert_mail.resend_interval`), `backup.*` | 지금 읽는 코드가 없음(바꿔도 효과 없음 — 감사 로그 정리·백업은 P27B·이후 단계) |
| 그 밖의 키(`ui.*` 화면 크기, `export.*`, `rules.*`, `viewer.*`, `history.*`, `dashboard.*`, `severity.*` 등) | 이번 허용 목록 권장 후보 밖 — 필요하면 아래 절차로 추가 |

허용 목록 밖 키를 API로 보내면 400 `setting_not_editable`. 코드의 `ConfigSchema::NOT_EDITABLE`(접두사·이름)에 걸리는 키는 등록부에 넣으면 기동 때
`LogicException` — 실수로 민감 값을 편집 대상으로 만들 수 없다.

## 5. 경로 규칙(`Services\Settings\PathPolicy`, P27B 백업 폴더와 공유)

경로 형식 값은 저장 전에 서버가 모두 검사하고 하나라도 어기면 저장하지 않는다(422 `setting_path_invalid`). 화면의 "경로 확인"은 저장 없이 같은 검사만 한다.

1. 절대 경로(Windows `D:\backups`, UNC `\\서버\공유\폴더`, Linux `/backups`) 또는 프로젝트 루트 기준 상대 경로. `C:abc`(드라이브 기준 상대)·`\abc`(드라이브 없는 루트)는 거부.
2. 길이 200자 이하(백업 파일 이름을 붙여도 Windows 259자 안).
3. `..`·`.`을 풀고, 이미 있는 가장 깊은 상위 폴더를 `realpath`로 **정규화한 결과**(심볼릭 링크·정션을 따라간 실제 위치)로 금지 위치와 비교한다.
4. 금지 위치: 드라이브 루트·`/` 자체, **프로젝트 폴더 전체**(웹 문서 루트 `public/`, `data/`, `bin/`, `config/`, `src/`, `migrations/`, `tests/` 포함 —
   업그레이드 때 교체되는 폴더), 설정의 데이터 폴더(`paths.*` — 프로젝트 밖에 둔 경우도), 운영체제 폴더(`%SystemRoot%`·`Program Files`·`ProgramData`,
   `/etc`·`/proc`·`/sys`·`/dev`·`/usr`·`/bin`·`/sbin`·`/lib*`·`/boot`·`/run`). Windows는 대소문자를 구분하지 않고 비교한다.
   **예외**: 프로젝트 바로 아래 `backups/`(정확히 그 폴더와 하위, P27B 사용자 결정 2026-10-08)만 허용(운영체제 폴더 규칙은 예외에도 적용, `data/` 안은 계속 거부).
   P27A의 예외였던 `paths.backups_dir`(기본 `data/backups`)는 P27B에서 없앴다 — 그 폴더는 사용자가 손으로 둔 DB 사본만 있었고 읽는 코드가 없었다(백업 위치는 `backup.dir` 하나).
5. 폴더가 없으면 `fail_not_found`(만들 수 있음) → 화면이 "만들까요?"를 묻고 확인하면 만든다(정션을 따라 금지 위치로 이어지면 만들지 않음).
6. 쓰기 시험(임시 파일 만들고 지움)이 통과해야 하고, 여유 공간을 함께 보여 준다.
7. 운영 데이터(DB 폴더)와 같은 볼륨이면 `warn_same_volume`(저장 가능, 디스크 고장에 함께 잃음을 알림).
8. UNC 경로는 PHP 자식 프로세스(`tools/lib/path_probe.php`)로 확인하고 **5초**를 넘으면 `fail_unc_unreachable`(실측: 응답 없는 주소 5.08초에 끊음).

결과 코드: `ok`, `warn_same_volume`, `fail_not_absolute`, `fail_forbidden_location`, `fail_not_found`, `fail_not_writable`, `fail_too_long`, `fail_unc_unreachable`.
사용자 문구는 정제한 한 문장이고 상세는 서버 로그(INFO "경로 확인 실패").

## 6. API(admin만, 쓰기는 CSRF)

- `GET /api/v1/admin/config-settings` — 항목(키·묶음·형식·유효값·출처·기준 값·범위·restart·machine_specific·env_locked), 버전 표지, 무시한 값 경고,
  워커 반영 상태, 변경 이력(감사 `config.update` 최근 50건).
- `PUT /api/v1/admin/config-settings` `{changes: {키: 값}, version, create_paths?: [키]}` — 전체 성공 또는 전체 실패(400/409/422).
- `POST /api/v1/admin/config-settings/validate` `{key, value, create?}` — 경로 확인(저장 없음, 실패도 200 + 결과 코드).
- `DELETE /api/v1/admin/config-settings/{key}?version=` — 기본값으로.
- 감사: 키마다 한 줄 `config.update`(대상 `config`/키, `details {old, new, old_source, file}`). 변경 이력은 `(target_type, target_id)` 색인으로 읽는다.

## 7. 키 등록 절차(P27B 백업 폴더 등)

1. `ConfigSchema::definition()`에 키를 둔다(형식·범위·**기본값** — 기존 config.ini에 없어도 기동되게). 경로면 `'type' => 'path'`.
2. `config.ini.example`에 명세 절 번호 주석과 함께 적는다.
3. `ConfigSchema::EDITABLE`에 `'섹션.키' => ['group' => …, 'restart' => 'none'|'worker', 'machine_specific' => true|false]`를 더한다
   (묶음이 새로우면 `EDITABLE_GROUPS`와 `ko.js`·`en.js`의 `config_settings.group.*`도 — 그러면 화면에 탭이 하나 생긴다, P27F). `NOT_EDITABLE`에 걸리면 기동 때 오류 — 그 경우 허용 목록에 넣을 수 없다.
4. `ko.js`에 `config_settings.key.<키>.label`·`.help`(JS 시험이 모든 허용 키의 라벨·도움말을 검사).
5. 워커가 그 값을 언제 읽는지 확인해 `restart`를 정한다: 쓸 때마다 `$this->app->config`에서 읽거나 매번 새로 만드는 서비스에 들어가면 `none`,
   워커 시작 때 한 번 읽어 붙잡아 두면 `worker`.
6. 경로 키는 저장 때 `PathPolicy`가 검사한다. 백업 폴더처럼 금지 위치 안의 예외가 필요하면 `App::pathPolicy()`의 예외 목록에 더한다.
7. `docs/settings.md` 허용 목록 표와 개수 단언(`tests/Integration/ConfigSettingsTest`, `tests/js/config_settings.test.mjs` — 지금 103)을 고친다.
8. 진행 중인 작업 때문에 지금 바꾸면 안 되는 키는 `App::settingsService()`의 키별 검사(guards)에 더한다(P27B `backup.dir` → 409 `setting_busy`, details `reason`).

**입력 형식(P27E)**: 화면은 `enum`을 선택 목록(값 이름은 `ko.js config_settings.value.<키>.<값>`), `bool`을 켜기/끄기 칸으로 그린다(그 밖은 글자 칸 그대로).
지금 값으로 쓰이지 않는 항목은 숨기지 않고 흐리게(비활성) 하고 이유를 한 줄 보인다(`config_settings_core.inactiveReason` — 자동 백업 꺼짐이면 주기 항목, 방식에 맞지 않는 요일·날짜).

**P27B 반영**: `[backup]` 9개를 묶음 "백업"으로 등록했다(위 표). `paths.backups_dir`는 읽지 않으며(제외 목록 그대로), config.ini에 기본값이 아닌 값이 남아 있으면 백업·복원 화면이 한 번 안내한다. 새 형식 `time`(HH:MM)을 더했다. 자세한 동작은 `docs/backup_restore.md`.

## 8. 운영 팁

- 여러 대를 같은 설정으로: 공통 값은 `config.ini`, PC별 값(백업 폴더 등 `machine_specific`)은 화면 설정이나 환경 변수로.
- 컨테이너·서비스 관리자에서 값을 고정하려면 환경 변수 `PJG_<섹션>_<키>`(화면에서 읽기 전용이 된다).
- 화면 설정을 모두 버리려면 워커·웹이 쓰지 않는 시간에 `config/settings.local.ini`를 지운다(이전 내용은 `.bak.*`).
- 설정 점검: `php tools/config_check.php`(config.ini와 settings.local.ini를 함께 읽어 무시한 값을 경고로 보여 준다).
