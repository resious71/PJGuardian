# 빠른 설치

[English](install_quick.en.md)

동작하는 설치본까지 가는 짧은 순서입니다. **Windows + XAMPP**에서 확인했고 Linux는 아직 확인하지 않았습니다([unverified.md](unverified.md)). 단계마다 자세한 문서를 링크했습니다.

## 0. 준비물

| 항목 | 요구 |
| --- | --- |
| 웹 서버 | HTTPS를 쓰는 Apache 2.4(XAMPP 가능) |
| PHP | 8.2 이상, 확장 `pdo_sqlite`·`zip`·`mbstring`·`openssl`·`fileinfo`·`intl`(LDAP 로그인을 쓰면 `ldap`) — 웹 서버용과 명령줄용 모두 |
| Composer | `vendor/`를 만들 때(이미 들어 있는 배포 묶음을 쓰면 필요 없음 — [vendor_build.md](vendor_build.md)) |
| Node.js | 20.19 이상, 웹 서버·워커 실행 계정에서 보여야 함 |
| 디스크 | 업로드·분석 작업 폴더·스냅샷 공간, 백업은 가능하면 다른 디스크([server_sizing.md](server_sizing.md)) |

SQLite DB 파일 하나를 네트워크 공유로 두 서버가 함께 쓰지 않습니다.

## 1. 코드와 라이브러리

1. 코드를 **웹 루트 밖**에 둡니다. 예: `D:\PJGuardian`, `/opt/pjguardian`.
2. 그 폴더에서 `composer install --no-dev --optimize-autoloader`(`vendor/` 생성).

## 2. Apache

DocumentRoot가 `public/` 폴더인 HTTPS 가상 호스트를 만들고 나머지는 모두 막습니다([xampp_vhost.md](xampp_vhost.md)). HSTS 헤더는 Apache에 넣지 **않습니다**(앱이 보냄). 끝나면 `https://<호스트>/config/config.ini`, `/data/`, `/bin/`이 404여야 합니다.

## 3. 설정과 비밀 키

```
copy config\config.ini.example config\config.ini
copy config\secrets.example   config\secrets.ini
php tools\make_secret_key.php
```

`[app] base_url`에 사용자가 여는 주소를 적습니다. `config.ini`·`secrets.ini`는 외부에 보내지 않고, 웹 서버·워커 계정에는 `data/`·백업 폴더·`config/`(설정 관리 화면이 `config/settings.local.ini`를 씀)에만 쓰기 권한을 줍니다 — [settings.md](settings.md), [hardening.md](hardening.md).

## 4. 분석 도구

[bin_setup.md](bin_setup.md)대로 ruff·ESLint·Node.js를 준비한 뒤:

```
php tools\fetch_bin.php --verify
php tools\check_env.php
```

## 5. DB와 최초 관리자

```
php tools\migrate.php
php tools\create_admin.php --login=admin --name="관리자"
```

초기 비밀번호는 **`admin`**이며 변경을 강제하지 않습니다. **첫 로그인 직후 바로 바꾸세요.**

## 6. 워커와 매일 정리 작업

분석은 웹 요청이 아니라 백그라운드 워커가 합니다. `php tools/worker.php`가 계속 돌도록(Windows 작업 스케줄러 1분마다 — 이미 돌고 있으면 새로 뜬 것은 바로 끝남 — 또는 systemd·NSSM 서비스) 등록하고, `php tools/cleanup.php`를 매일 실행하게 합니다 — [worker_setup.md](worker_setup.md).

## 7. 확인

1. `php tools/migrate.php --status` — 모두 적용, `php tools/worker.php --status` — 실행 중.
2. `https://<호스트>/`에 접속해 로그인 → 비밀번호 변경 → **시스템 관리 > 분석 도구**에서 ruff·ESLint가 사용 가능인지.
3. 작은 프로젝트를 올려 분석이 끝까지 되는지.
4. **시스템 관리 > 백업·복원**에서 첫 백업을 만들고 키 파일(`.pjgkey`)을 내보내 둡니다 — [backup_restore.md](backup_restore.md).

운영 전에는 [release_checklist.md](release_checklist.md)와 [hardening.md](hardening.md)를 따르세요. 다른 PC의 데이터를 옮겨 오려면 백업 묶음과 키 파일로 복원합니다([backup_restore.md](backup_restore.md)).
