# 백업·복원 (P27B)

명세 14장·19.3·19.4. 관리자 화면 **시스템 관리 > 백업·복원**(`/admin/backups`, admin만)과 웹 서버 없이 쓰는 명령줄(`tools/backup.php`, `tools/restore.php`)이
같은 서비스(`src/Services/Backup/`)를 쓴다. 목적은 두 가지다: 운영 중 사고에 대비한 일일 백업, 그리고 **다른 PC로 지금의 데이터·설정을 옮기기**.

## 1. 무엇을 백업하는가

| 묶음 항목 | 내용 | 비고 |
| --- | --- | --- |
| `manifest.json` | 형식·버전, 종류, 앱·스키마 버전, 만든 시각·PC·OS·PHP, 건수(사용자·프로젝트·분석·결함), 비운 설정 키, 빠진 스냅샷, bin 도구 목록, **항목별 크기·SHA-256** | 묶음 검증의 기준 |
| `db/pjguardian.sqlite` | DB 전체 | `VACUUM INTO`(읽기 연결) — 쓰기가 계속돼도 한 시점의 일관된 사본, `-wal`·`-shm` 없음 |
| `config/config.ini` | 설정 파일 사본 | **민감 값은 비운다**(키 이름의 마지막 조각이 password·passwd·pass·secret·token·key·apikey·passphrase) |
| `config/settings.local.ini` | P27A 화면 설정(있으면) | 같은 규칙으로 비움 |
| `bin/manifest.json` | `bin/<도구>/<버전>/`의 체크섬 목록 파일 내용(실행 파일은 넣지 않음) | 새 PC에서 `restore.php --check-bin`으로 비교 |
| `snapshots/<프로젝트>/<분석>/source.zip` | 코드 스냅샷(선택, 기본 포함) | 이미 압축 — 저장만(STORE) |

**넣지 않는 것**: `config/secrets.ini`(암호화 키·SMTP·LDAP 비밀번호), 업로드 원본·작업 폴더·로그. 암호화 키는 "키 내보내기"로 따로 보관한다(4절).

묶음 이름: `pjguardian-YYYYMMDD-HHMMSS-<daily|manual|pre_restore|pre_upgrade>[-n].pjgbak`(표시 시간대 기준). 확장자만 다른 zip이다.
옆 파일 `<묶음>.json`에 manifest 사본과 마지막 검증 결과를 둔다(목록이 묶음을 열지 않게). 백업 폴더의 `backup_state.json`이 마지막 성공·실패·리허설·일일 상태다
(DB가 아닌 파일 — 복원이 DB를 통째로 바꾸므로).

manifest 예(줄임):

```json
{
  "format": "pjguardian-backup", "format_version": 1, "kind": "daily", "app_version": "0.1.0-dev", "schema_version": "024",
  "created_at": "2026-10-09T17:30:00Z", "created_at_local": "2026-10-10 02:30:00 KST", "host": "PC-NAME", "os": "Windows", "php": "8.3.35",
  "created_by": "system", "options": {"snapshots": true},
  "counts": {"users": 11, "projects": 2, "analyses": 18, "defects": 6980},
  "blanked": {"config": [], "settings_local": []}, "missing_snapshots": [], "bin_tools": [{"tool": "ruff", "version": "0.16.10"}],
  "entries": {"db/pjguardian.sqlite": {"size": 15532032, "sha256": "…"}, "snapshots/1/3/source.zip": {"size": 3221225, "sha256": "…"}}
}
```

## 2. 백업 폴더와 보관

- 위치: `[backup] dir`(기본 `backups` = 프로젝트 바로 아래, 사용자 결정 2026-10-08). **바꾸기는 시스템 관리 > 설정 관리 > 백업 > 백업 폴더**(P27A, `settings.local.ini`에만 저장).
  검사는 P27A `PathPolicy`(절대·UNC 허용, 금지 위치 거부, 없으면 만들기·쓰기 시험). 프로젝트 안에서는 `backups/`만 예외로 허용하고 `data/` 안은 거부한다.
  - 폴더를 바꿔도 **기존 묶음은 옮기지 않는다**. 목록·`backup_state.json`은 새 폴더 기준으로 새로 시작한다(새 폴더가 비어 있으면 "아직 백업 없음").
  - 백업·복원이 진행·대기 중이면 폴더 변경은 409 `setting_busy`.
  - 폴더에는 `.gitignore`(모두 무시)를 두고, 프로젝트 안이면 `.htaccess`(`Require all denied`)도 둔다. 루트 `.gitignore`에도 `/backups/`.
  - **웹 노출**: Apache는 `public/`만 서비스한다(vhost DocumentRoot·`/PJGuardian` Alias, `docs/xampp_vhost.md`). `https://pjguardian.local/backups/…`와
    `https://localhost/PJGuardian/backups/…`는 404였다(P27B 확인). 내려받기는 PHP가 인증·재인증 뒤 스트리밍한다.
  - 기본 `backups/`는 운영 데이터와 **같은 디스크**라 명세 19.4(다른 저장 위치)를 채우지 못한다 — 화면이 항상 경고한다. 운영에서는 별도 디스크·네트워크 드라이브로 바꾼다.
- **자동 백업(P27E)**: 시스템 관리 > 설정 관리 > 백업에서 켜고 끄고(`backup.enabled`, 기본 켬) 주기를 고른다(`backup.schedule_mode` = 매일 `daily` / 매주 `weekly`
  + `weekly_day` / 매월 `monthly` + `monthly_day` 1~31·`last`, 실행 시각은 세 방식 모두 `daily_at` 기본 02:30, 표시 시간대). 그 달에 없는 날짜는 그 달 마지막 날.
  - 예정 계산은 `Services\Backup\BackupSchedule`(순수) 한 곳. 현지 날짜마다 한 번이라 서머타임으로 시각이 사라지는 날은 앞으로 밀린 시각에, 두 번 오는 날은 한 번만.
  - 워커가 30초마다 확인: **가장 최근 예정(≤ 지금) 이후 그 예정으로 성공·시도를 마친 자동 백업이 없으면 한 번** 요청한다. 워커가 오래 꺼져 있어 예정이 여러 번
    지나갔어도 **한 번만**(밀린 횟수만큼 하지 않음). 상태는 `backup_state.json`의 `auto`(`rule`·`slot`·재시도). P27B의 `daily.date`는 처음 한 번 이어받는다.
  - 실패하면 10분 뒤 한 번 더(같은 예정), 그래도 실패하면 그 예정은 끝나고 관리자 알림 `backup_failed`(같은 사유는 1시간에 한 번).
  - **주기를 바꾸면**(방식·시각·쓰이는 요일·날짜) 그때 지난 예정은 지난 것으로 보고 새 규칙의 **다음** 예정부터 — 바꾼 직후 즉시 백업이 돌지 않는다.
    쓰이지 않는 값(매일일 때 요일 등)은 저장돼도 무시한다.
  - **끄면** 자동 백업·재시도·실패 알림·**자동 보관 정리**가 모두 멈춘다(끈 동안 파일이 지워지지 않음). 수동 백업·복원·리허설·명령줄은 그대로.
    진행 중이던 자동 백업은 끝까지 끝낸다(그때 꺼져 있으면 정리는 하지 않음). **다시 켜면** 최근 예정 이후 자동 백업이 없을 때 다음 확인에서 한 번.
    꺼진 동안 주기를 바꾸면 그 변경 규칙(지난 예정은 지난 것)이 먼저라, 켜도 다음 예정을 기다린다.
  - 새 설치(상태 없음)는 최근 예정 하나를 바로 만든다(P27B의 "오늘 몫"과 같음).
  - 자동 백업 묶음의 종류 이름은 호환을 위해 `daily` 그대로, 화면 표시는 "자동".
- 보관 정리(자동 백업 성공 뒤, 켜져 있을 때만): 일반(daily·manual)은 `retention_days`(60) 지난 것, 단 최근 `retention_min_count`(3)개는 남김.
  `retention_max_count`(P27E, 기본 0 = 제한 없음)가 있으면 그 개수를 넘는 오래된 일반 백업도 지운다(최소 보관 개수 이상이어야 함 — 키 간 규칙, 최소 개수·마지막 성공 보호가 앞섬).
  매주면 60일에 약 9개, 매월이면 약 2개뿐이다(최소 3개 보호로 3개는 남음) — 주기가 길면 보관 기간을 늘린다.
  안전 백업(pre_restore·pre_upgrade)은 `safety_retention_days`(30). **마지막 성공 백업은 어떤 경우에도 남기고**, 검증 실패·검증 기록 없는 묶음은 건드리지 않는다.
  수동 삭제는 비밀번호 재입력, 마지막 성공 백업은 409.
- 백업은 만든 직후 검증한다(구조·manifest·해시·`integrity_check`·건수). 실패하면 그 묶음을 지우고 실패로 기록한다.
- 디스크: 백업 폴더의 사용률이 `disk.block_percent` 이상이면 거부(`backup_disk_full`), 여유가 (DB + 스냅샷) × 2 + 64MB보다 적으면 `backup_disk_low`.
- **디스크 영향 추정(P27E, 화면만)**: 마지막 백업 크기 × 보관 기간 안에 쌓일 자동 백업 개수(= 보관 일수 ÷ 주기 1·7·30.44일 올림, 최소 보관 개수 이상,
  상한이 있으면 상한까지). 백업·복원 탭 요약 "보관 추정"과 설정 관리 백업 묶음 아래에 보이고, 백업 폴더 여유의 50%를 넘으면 경고(막지는 않음).
- **"백업이 오래됨" 기준**: 매일 = `backup_stale_hours`(36), 매주 = 8일, 매월 = 33일(설정값이 더 크면 설정값). 자동 백업이 꺼져 있으면 "오래됨·없음" 경고 대신
  "자동 백업이 꺼져 있습니다. 수동 백업은 언제든 할 수 있습니다." 정보 한 줄. 요약 "자동 백업" 줄은 "매일 02:30 · 다음 …" / "매주 일요일 02:30 · 다음 …" /
  "매월 1일·말일 02:30 · 다음 …" 또는 "자동 백업 꺼짐".

## 3. 복원

### 흐름(화면)

1. 목록에서 **복원** → 사전 검증(`POST …/{name}/precheck`, 바꾸지 않음): 묶음 구조(허용 항목만, 경로 이탈·링크·중복·압축 비율), manifest와 항목 대조, 항목 해시,
   묶음 DB `integrity_check`·건수·스키마 대조, 앱보다 새 스키마면 거부, 필요한 디스크 여유.
2. 미리보기: 백업 시각·PC·버전, **지금과 백업의 건수 나란히**, "이 시각 이후의 변경은 사라집니다: 이후 분석 N건·결함 변경 M건·계정 변경 K건"
   (현재 DB에서 `analyses.queued_at`·`defect_history.changed_at`·`users.updated_at` > 백업 시각).
3. 설정 복원(선택, **기본 끔**): 키별 차이(지금 유효 값 → 백업 값, 민감 값 제외). PC 고유 항목(`backup.dir` 등 레지스트리 `machine_specific`과
   `[backup] config_machine_keys`)은 기본 해제. `secrets.ini`는 절대 바꾸지 않는다.
4. 키 상태: 지금 키로 묶음의 저장소 자격 증명을 풀 수 있는지(`ok`·`mismatch`·`none`). 필요하면 **키 가져오기**(.pjgkey + 암호문구).
5. 진행 중인 작업: 끝나기를 기다림(기본, 상한 `restore_wait_max_minutes`) 또는 분석 취소.
6. 관리자 비밀번호 재입력 + 확인 문구 "복원" → 요청 파일(`<work>/run/restore_request.json`)만 남기고 **진행 상태 페이지**(`/restore-status`)로 이동.

### 워커가 하는 일

웹 요청 안에서는 DB를 바꾸지 않는다. 워커가 요청 파일을 보면:

1. 유지보수 모드 진입(파일 `<work>/run/maintenance.flag`, DB 아님) — 새 작업을 집지 않고 주기 스케줄러·일일 백업도 멈춘다.
2. 진행 중인 분석·업로드·관리 작업·백업이 끝나기를 기다린다(취소를 고르면 실행 중 분석에 취소 요청). 상한을 넘으면 `restore_busy`로 포기.
3. DB 연결을 모두 닫고 별도 프로세스 `php tools/restore.php --request`를 띄운다.
4. 자식이 끝나면 설정을 다시 읽고, 연결을 새로 열고, 실행 중이던 행을 정리한다(`recoverAll`).

### 복원 프로세스 단계(`RestoreService::execute`, 상태 파일 `<work>/run/restore_state.json`)

| 단계 | 하는 일 |
| --- | --- |
| precheck | 사전 검증을 다시 |
| maintenance | 유지보수 플래그(명령줄은 여기서 켬) |
| pre_restore_backup | **복원 직전 자동 백업**(종류 pre_restore, 30일 보관). 실패하면 시작하지 않음 |
| replace_db | 묶음 DB를 같은 볼륨 임시 이름으로 풀어 `integrity_check` → 지금 DB는 체크포인트(TRUNCATE) 뒤 `.before_restore`로, `-wal`·`-shm` 정리, 원자적 교체 |
| replace_snapshots | 묶음에 스냅샷이 있으면 폴더 단위 교체(지금 폴더는 `snapshots.before_restore`). 없으면 지금 스냅샷 유지 |
| migrate | 묶음 스키마가 앱보다 낮으면 마이그레이션 |
| finalize | **모든 세션 무효**, 복원된 DB의 감사 로그에 `restore.finish`, 고른 설정 적용(화면 설정은 P27A 저장소로, config.ini는 `config.ini.before_restore` 보관 뒤 키별로, 적용한 설정이 기동 검사를 못 넘으면 실패), 가져온 키 적용 |

replace_db 이후 어디서든 실패하면 **자동 롤백**(`.before_restore` 되돌림, 복원 전 DB에 `restore.rollback`) — 상태 `rolled_back`.
롤백까지 실패하면 상태 `rollback_failed`와 **수동 복구 절차**(어느 파일을 어디로 되돌릴지, 프로젝트 기준 상대 경로)를 상태 페이지에 보인다.
복원 프로세스가 강제 종료되면(상태 `running`인데 프로세스 없음) 워커 시작·명령줄 복원 시작 때 `recoverInterrupted()`가 이번 복원이 옮긴 파일만 되돌린다.
유지보수 플래그가 `maintenance_stale_minutes`(120)보다 오래 남으면 워커 로그와 화면이 경고한다 — `php tools/restore.php --leave-maintenance`로 끈다.

### 유지보수 중 화면

`MaintenanceMiddleware`(세션보다 먼저, DB를 열지 않음): `/restore-status`·`/api/v1/restore-status`는 그대로, 그 밖의 API는 503 `maintenance`,
그 밖의 화면(로그인 포함)은 503 + 진행 상태 페이지. 진행 상태 페이지는 로그인·DB 없이 상태 파일만 읽어 2초마다 갱신하며 비밀·절대 경로·요청자 이름이 없다.
복원이 끝나면 모든 사용자가 다시 로그인한다.

### 복원 리허설(분기 1회, 명세 19.4)

"복원 리허설"은 묶음(기본 최신)을 임시 폴더에 풀어 사전 검증 + 마이그레이션 시험 + 주요 조회 몇 개를 실행한다. **운영 데이터는 바뀌지 않는다.**
결과는 `backup_state.json`의 `last_rehearsal`. 마지막 리허설(없으면 첫 성공 백업)이 `rehearsal_interval_days`(90)를 넘으면 관리자 알림 `restore_rehearsal_due`(기한마다 한 번)와 화면 경고.

## 4. 암호화 키(.pjgkey)

저장소 연동 자격 증명(P25)은 `secrets.ini`의 `encryption_key`로 암호화돼 DB에 있다. 키는 묶음에 넣지 않는다.

- **키 내보내기**(화면, 비밀번호 재입력 + 암호문구 12자 이상 두 번): `pjguardian-key-<날짜>.pjgkey`(JSON).
  `encryption_key`·`encryption_key_previous`를 AES-256-GCM으로 암호화하고 키는 PBKDF2-HMAC-SHA256 **310,000회**(무작위 salt 16바이트, nonce 12바이트)로
  암호문구에서 만든다(`sodium`이 없어 Argon2id 대신). 겉에는 형식·버전·키 지문(SHA-256 앞 8자)·키 식별자·KDF 매개변수만 있고 평문 키는 없다.
  암호문구는 어디에도 저장·기록하지 않는다. **키 파일과 백업 묶음은 다른 곳에 보관한다. 암호문구를 잃으면 복구할 수 없다.**
- **키 가져오기**(복원 화면 또는 `restore.php --key`): ① 복호화 성공(틀린 암호문구와 변조는 같은 `key_import_failed`), ② **복원할 묶음 DB의 자격 증명을
  실제로 풀어 보는 시험** 통과(`key_test_failed`면 바꾸지 않음) → 복원 마무리 때 `secrets.ini`를 쓴다: 가져온 키가 현재, 원래 키는 `encryption_key_previous`
  (P25 교체 규칙), 원래 파일은 `secrets.ini.before_restore`, SMTP·LDAP 줄은 그대로. 사용자당 분당 5회.
- **키 없이 복원**(다른 PC·키 분실): 앱은 정상 기동한다. 풀 수 없는 저장소 연동은 기존 `repo_secret_unreadable`("연결 정보를 다시 입력하세요")로 보이고,
  그 프로젝트의 주기 분석은 건너뛰며 관리자에게 **프로젝트마다 한 번** 알린다(같은 사유가 이어지면 다시 알리지 않음). 복원 결과에 영향받는 연동 수를 보인다.

## 5. 명령줄(웹 서버 없이) — 다른 PC로 옮기기

```
# 옛 PC
php tools/backup.php                                     # 묶음 만들기(스냅샷 포함, 만든 직후 검증). --no-snapshots, --kind=pre_upgrade
php tools/backup.php --list | --verify[=<이름>] | --prune | --rehearse[=<이름>]
# 화면의 "키 내보내기"로 .pjgkey를 받아 둔다(명령줄 내보내기는 없음 — 비밀번호 재입력이 필요한 화면에서만)

# 새 PC: 코드 배치 → config/config.ini.example을 config/config.ini로 복사(경로 등 이 PC에 맞게) → php tools/make_secret_key.php(키가 없을 때)
php tools/restore.php --file=<묶음 경로> --dry-run       # 사전 검증·미리보기만(바꾸지 않음)
php tools/restore.php --file=<묶음> --check-bin          # 묶음의 bin 도구·체크섬과 이 PC의 bin/ 비교(도구는 OS마다 다시 배치)
PJG_KEY_PASSPHRASE=... php tools/restore.php --file=<묶음> --key=<.pjgkey> [--with-config] [--yes]
php tools/migrate.php --status                           # 복원이 마이그레이션까지 한다(확인용)
php tools/worker.php                                     # 워커 시작
```

- DB가 아직 없는 새 설치에서도 복원한다(직전 백업은 건너뜀). 워커가 돌고 있으면 거부한다(화면 복원은 워커가 직접 처리).
- 확인은 "복원" 입력(`--yes`면 생략). 암호문구는 프롬프트(화면에 보일 수 있음) 또는 환경 변수 `PJG_KEY_PASSPHRASE`만 — 명령줄 인자로 받지 않는다.
- `--with-config`: 설정 차이를 키마다 묻는다(PC 고유 항목은 기본 N). `--yes`와 함께면 PC 고유가 아닌 항목만 적용.
- 종료 코드: 0 성공, 1 실패·거부, 2 사용법·설정 오류.
- 묶음·키를 옮길 때: 묶음 안에는 DB 전체(계정·결함)가 있다. 키 파일과 따로 옮기고, 옮긴 뒤 옛 PC·이동 매체의 사본을 정리한다.
- DB와 스냅샷에는 절대 경로가 없다(스냅샷 `storage_path`는 `프로젝트/분석` 상대, 업로드 작업 폴더는 ID) — 다른 경로·다른 드라이브에서도 그대로 동작한다.

## 6. API(admin만, 쓰기는 CSRF, 문구 `backup.error.<code>`)

| 메서드·경로 | 내용 |
| --- | --- |
| `GET /api/v1/admin/backups` | 요약·진행·복원 상태·목록 |
| `POST /api/v1/admin/backups` `{snapshots}` | 지금 백업(워커 작업, 202) |
| `POST /api/v1/admin/backups/cancel` | 진행 중 백업 취소 |
| `POST /api/v1/admin/backups/rehearse` `{name?}` | 복원 리허설(워커 작업, 202) |
| `POST /api/v1/admin/backups/key-export` `{password, passphrase, passphrase2}` | 키 파일 내용 |
| `POST /api/v1/admin/backups/{name}/verify` | 다시 검증 |
| `POST /api/v1/admin/backups/{name}/download` `{password}` → `GET …/download/{token}` | 일회용 주소(60초, 같은 관리자) |
| `POST /api/v1/admin/backups/{name}/precheck` | 사전 검증·미리보기 |
| `DELETE /api/v1/admin/backups/{name}` `{password}` | 삭제(마지막 성공 409) |
| `POST /api/v1/admin/restore` | 복원 요청(202) |
| `POST /api/v1/admin/backup-uploads` → `PUT …/{id}/chunks/{n}` → `POST …/{id}/complete` / `DELETE …/{id}` | 묶음 올리기(8MB 조각, 조각마다 SHA-256, `upload_max_mb`) |
| `GET /api/v1/restore-status` | 복원 상태(로그인 없이) |

비밀번호 재입력(로컬 계정 `password_verify`)은 사용자당 분당 5회(`reauth_rate_limited`). 올린 묶음은 "검증 필요"로만 보이고 **검증을 통과해야 복원**할 수 있다(409 `bundle_needs_verify`).
백업 폴더에 묶음 파일을 직접 복사해도 목록에 나타난다(큰 묶음의 기본 경로).

감사 로그: `backup.create`(요청·결과), `backup.delete`(수동·보관 정리), `backup.download`, `backup.upload`, `backup.verify`, `restore.start`, `restore.finish`(복원된 DB),
`restore.rollback`(복원 전 DB), `restore.rehearsal`, `key.export`, `key.import`(대상 = 키 지문). 비밀번호·암호문구·키는 어디에도 남기지 않는다.

알림(관리자 전용, 마이그레이션 024): `backup_failed`(1시간에 한 번), `backup_done`(지금 백업을 요청한 관리자), `restore_rehearsal_due`(기한마다 한 번).

## 7. 문제 해결

| 증상 | 확인 |
| --- | --- |
| "백업 폴더를 쓸 수 없어 백업하지 않습니다" | 시스템 관리 > 설정 관리 > 백업 > 백업 폴더의 "경로 확인". 웹 서버·워커 계정의 쓰기 권한 |
| 자동 백업이 안 생김 | 시스템 관리 > 설정 관리 > 백업의 "자동 백업" 켜짐·주기, 워커 실행 여부(시스템 관리 > 워커), 탭 요약의 "다음" 시각, `backup_state.json`의 `auto` |
| 복원이 "요청됨"에서 멈춤 | 워커가 진행 중 작업을 기다리는 중(상한 `restore_wait_max_minutes`). 워커가 꺼져 있으면 시작하거나 명령줄 복원 |
| 모든 화면이 "복원 진행 상태" | 유지보수 플래그. 복원이 끝났는데 남았다면 `php tools/restore.php --leave-maintenance` |
| `rollback_failed` | 웹 서버·워커를 멈추고 상태 페이지의 수동 복구 절차대로 `.before_restore`를 되돌린 뒤 다시 시작 |
| 복원 뒤 저장소 연동 "연결 정보를 다시 입력" | 키 없이 복원함 — 키 파일을 가져와 다시 복원하거나 프로젝트 설정에서 다시 입력 |
