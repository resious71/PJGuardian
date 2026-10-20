---
key: install
title: 설치와 환경
order: 1
summary: XAMPP·PHP 확장·HTTPS 가상 호스트·설정 파일 권한·마이그레이션·최초 관리자 생성까지 새 서버를 준비하는 순서
keywords: 설치, XAMPP, Apache, PHP, 확장, intl, ldap, HTTPS, 가상 호스트, php.ini, config.ini, settings.local.ini, config 쓰기 권한, migrate, create_admin, 하드닝
related: deploy-bundle, worker, tools-bin, linux-ops, settings, security, backup-restore
audience: admin
---
## 구성 개요

PJGuardian은 Apache와 PHP(XAMPP 가능), SQLite로 동작하는 웹 앱입니다. 분석은 웹 요청이 아니라 별도의 워커 프로세스가 합니다. 지원 OS는 Windows와 Linux입니다(macOS 미지원).

- 웹에 노출하는 폴더는 `public/` 하나뿐입니다. `data/`(DB·업로드·로그), `config/`, `bin/`, `src/`는 웹 루트 밖에 둡니다.
- 프로젝트 폴더를 XAMPP의 `htdocs/` 아래에 두지 않습니다. 기본 사이트를 통해 `data/`나 `config/`가 열릴 수 있습니다.
- 개발 PC(Windows + XAMPP)에서 확인한 절차입니다. Linux 서버는 [Linux 운영](guide:linux-ops)을 함께 보세요.

## 필요한 것

- PHP 8.2 이상. 필수 확장은 pdo_sqlite, zip, mbstring, openssl, fileinfo, intl입니다. `php tools/check_env.php`가 이 여섯 개를 검사하고, 없으면 FAIL로 표시합니다.
- intl은 경로·코드를 NFC로 정규화하는 데 씁니다. 꺼져 있으면 php.ini에서 켜거나 명령에 `-d extension=intl`을 붙입니다.
- ldap 확장은 LDAP/AD 로그인을 쓸 때만 필요합니다. 환경 점검 명령은 ldap을 검사하지 않습니다. LDAP을 켰는데 확장이 없으면 설정 관리 LDAP/AD 탭과 점검 화면에 경고가 나옵니다.
- Linux 워커에는 posix·pcntl 확장도 필요합니다. 없으면 점검 화면에 경고가 나옵니다.
- SQLite는 점검이 요구하는 최소 버전 이상이어야 합니다. 점검 결과에 판정이 나옵니다.
- ESLint 실행용 Node.js, 분석 도구 ruff·ESLint는 [bin/ 도구 배포](guide:tools-bin)에서 준비합니다.

## 권장 서버 사양과 디스크 용량

- 기본 사양(동시 분석 2개): **CPU 8코어 이상, 메모리 32GB 이상**, SSD. 분석 하나가 메모리를 최대 {{limit:analysis.process_memory_limit}}까지 쓸 수 있습니다.
- 동시 접속 50명까지는 개발 PC 측정에서 화면 응답이 0.5초 안이었습니다. 100명 이상이면 운영 서버에서 부하 시험으로 확인하세요(측정하지 않은 사양의 값은 추정입니다).
- 디스크: {{ui:admin_system.title}} > {{ui:admin_checks.title}}의 **{{ui:capacity.title}}**에 프로젝트 수·결함 수·업로드 크기·보관할 백업 수를 넣으면 지금 설정으로 필요한 데이터 디스크와 백업 디스크를 계산합니다. 업로드 상한으로 계산한 분석 작업 공간은 {{limit:analysis.max_concurrent}} × (최대 zip + 최대 압축 해제)입니다.
- 계산식·근거·예시: `docs/server_sizing.md`(영어 `docs/server_sizing.en.md`).

## 설치 순서

1. 배포 묶음을 웹 루트 밖 폴더에 풉니다(예: `C:\PJGuardian`, Linux는 `/opt/pjguardian`). 묶음을 만들고 점검하는 방법은 [배포 묶음](guide:deploy-bundle)을 보세요.
2. `config/config.ini.example`을 `config/config.ini`로 복사하고 이 서버에 맞게 고칩니다. 특히 `app.base_url`(사용자가 접속하는 `https://` 주소), `app.env`(운영은 prod), `security.require_https`(true)를 확인합니다.
3. `config/secrets.example`을 `config/secrets.ini`로 복사합니다. 저장소 연동을 쓰면 `php tools/make_secret_key.php`로 암호화 키를 만듭니다(키 값은 화면에 출력하지 않고 파일에만 씁니다).
4. Apache 가상 호스트와 HTTPS를 설정합니다(다음 절).
5. 환경을 점검합니다: `php tools/check_env.php`. FAIL이 하나라도 있으면 종료 코드 1입니다.
6. 설정을 검증합니다: `php tools/config_check.php`. 오류가 있으면 원인을 출력하고 종료 코드 1입니다.
7. DB를 만들고 마이그레이션을 적용합니다(아래 "마이그레이션").
8. 최초 관리자를 만듭니다(아래 "최초 관리자").
9. 분석 도구를 배치하고 워커를 등록합니다([bin/ 도구 배포](guide:tools-bin), [분석 워커 운영](guide:worker)).

## Apache 가상 호스트와 HTTPS

- DocumentRoot를 `public/`으로 둔 전용 가상 호스트를 만듭니다. `Options -Indexes -FollowSymLinks`, `AllowOverride None`, `FallbackResource /index.php`를 씁니다.
- 프로젝트 루트 폴더에는 `Require all denied`를 따로 걸어 이중으로 막습니다.
- HTTP(80)는 HTTPS로 영구 리다이렉트하고, HTTPS 응답에 HSTS 헤더를 붙입니다. 운영은 정식 인증서를 씁니다.
- 필요한 Apache 모듈은 rewrite, headers, ssl이며 vhosts·ssl 설정 파일 include가 켜져 있어야 합니다.
- `httpd-vhosts.conf`에서 포트별로 처음 나오는 가상 호스트가 그 포트의 기본 사이트가 됩니다. IP 주소로 접속하게 하려면 `ServerAlias <서버 IP>`를 더하거나 PJGuardian을 기본 가상 호스트로 둡니다.
- 여러 PC에서 쓰는 서버는 `app.base_url`을 다른 PC에서도 열리는 이름으로 정하고 그 이름의 인증서를 씁니다. 앱은 Host 헤더가 아니라 이 값으로 리다이렉트합니다.
- 적용 전 `httpd -t`로 문법을, `httpd -S`로 포트별 기본 사이트를 확인하고 Apache를 다시 시작합니다.

## php.ini와 Apache 한도

청크 업로드는 청크 하나를 원시 본문(PUT)으로 받으므로 요청 하나는 작습니다. 지금 청크 크기는 {{limit:upload.chunk_size}}입니다.

바꾸거나 확인할 값:

- `post_max_size`는 청크 크기보다 커야 합니다. 점검 화면은 `upload_max_filesize`도 청크 크기 이하이면 경고합니다.
- `memory_limit`은 청크 크기의 4배 이상을 권장합니다. `max_execution_time`은 120초 이상을 권장합니다.
- Apache `LimitRequestBody`는 청크 크기 이상이어야 합니다(지정하지 않으면 Apache 기본값). 앞단에 리버스 프록시가 있으면 그 본문 한도도 청크 크기 이상이어야 합니다.
- 하드닝: `display_errors = Off`, `expose_php = Off`, `log_errors = On`, Apache `ServerTokens Prod`, `ServerSignature Off`.

따로 바꾸라고 하지 않는 값: `max_input_time`, Apache `Timeout`, `RequestReadTimeout`은 개발 PC의 XAMPP 기본값으로 통과했습니다. 동시 접속 관련 값(`ThreadsPerChild`)은 아직 부하 시험 전입니다.

XAMPP(Windows)는 CLI와 Apache가 같은 php.ini를 씁니다. Linux는 웹과 CLI의 php.ini가 다를 수 있으니 둘 다 확인합니다.

## 설정 파일 위치와 권한

- `config/config.ini`: 공통 설정. 운영 인프라 값(경로·주소·보안)은 여기서만 바꿉니다.
- `config/secrets.ini`: 암호화 키·비밀번호. 웹 서버·워커 계정 말고는 읽을 수 없게 권한을 좁힙니다.
- `config/settings.local.ini`: [설정 관리](guide:settings) 화면에서 바꾼 값이 저장되는 파일입니다. 저장할 때 같은 폴더에 잠금·임시·백업(`.bak.1`~`.bak.5`) 파일을 만듭니다.
- **쓰기 권한 방침**: 웹 서버와 워커를 실행하는 계정에는 `config/` 폴더 전체의 쓰기를 허용합니다(settings.local.ini와 그 잠금·임시·백업 파일을 쓰기 위해). 없으면 화면 저장이 실패합니다. 이 계정이 쓸 수 있는 곳은 `config/`, `data/`, 백업 폴더뿐이고 나머지(`bin/`, `src/`, `public/` 등)는 읽기 전용입니다([bin/ 도구 배포](guide:tools-bin)).
- Linux에서 settings.local.ini는 0640으로 만들어집니다. 웹 계정과 워커 계정을 같은 그룹에 두면 워커가 읽을 수 있습니다([Linux 운영](guide:linux-ops)).
- `config/config.ini`·`config/secrets.ini`는 git·배포 묶음·메신저로 옮기지 않고 서버마다 따로 만듭니다.

`config/` 쓰기를 허용하면 웹 애플리케이션에 취약점이 생겼을 때 `config.ini`·`secrets.ini`까지 바뀔 수 있습니다. 위험을 줄이는 방법은 다음과 같습니다.

- 파일 단위로 `config.ini`·`secrets.ini`를 가능한 한 읽기 전용으로 두는 편이 더 안전합니다. Windows에서 웹 계정에 읽기만 주고 settings.local.ini와 그 임시 파일 이름에만 쓰기를 허용할 수 있는지는 운영 환경에서 확인할 것.
- `config/`는 웹으로 열리지 않습니다(DocumentRoot는 `public/`). 설정 관리 화면이 쓰는 파일은 settings.local.ini(와 그 잠금·임시·백업 파일)뿐입니다.

## 배포 묶음

운영 묶음에 넣을 것·뺄 것과 `tools/verify_bundle.php` 점검은 [배포 묶음](guide:deploy-bundle)을 보세요.

## 마이그레이션

DB 스키마는 설치·업그레이드 담당자가 명령으로 바꿉니다. 웹 요청이 DB 구조를 바꾸지 않습니다.

```
php tools/migrate.php            # 적용할 마이그레이션을 모두 적용(DB가 없으면 만듦)
php tools/migrate.php --status   # 적용됨·대기·파일 변경됨·파일 없음
```

- 여러 번 실행해도 안전합니다. 실패한 마이그레이션은 롤백되고 이후 적용은 멈추며 종료 코드 1입니다.
- 업그레이드할 때는 백업 → 워커 정지 → 코드 교체 → 마이그레이션 → 워커 다시 시작 순서로 합니다. 코드 교체 때 덮어쓰지 않을 것은 [배포 묶음](guide:deploy-bundle)을 보세요.
- 다른 서버의 데이터를 옮길 때는 DB 파일을 복사하지 말고 [백업과 복원](guide:backup-restore)을 씁니다. 복원이 마이그레이션까지 합니다.

## 최초 관리자

```
php tools/create_admin.php --login=<로그인 ID> --name=<표시 이름>
```

- 마이그레이션 전이면 "먼저 migrate를 실행하라"는 안내와 함께 실패합니다. 활성 관리자가 이미 있으면 만들지 않습니다. 추가 관리자는 사용자 관리 화면에서 만듭니다.
- 옵션 없이 만들면 고정 초기 비밀번호가 설정되고, 명령이 그 값을 출력합니다. 첫 로그인 때 변경을 강제하지 않으므로 바로 쓸 수 있습니다. 외부에 공개하는 서버라면 로그인 뒤 내 정보에서 바꾸세요. 바꾸기 전까지 관리자에게는 모든 화면 위에 닫을 수 없는 경고 배너와 시스템 모니터링 경고가 보이고 하드닝 점검이 경고합니다(app.base_url이 이 PC 밖 주소면 더 강한 문구).
- 고정값을 쓰지 않으려면 `--password-stdin`으로 정책을 만족하는 비밀번호를 표준 입력으로 줍니다. 명령행 인자로는 비밀번호를 받지 않습니다.
- 생성 뒤 하드닝 점검이 함께 실행됩니다. 점검만 다시 하려면 `php tools/create_admin.php --check-only`. 자체 서명 인증서면 `--insecure`, hosts에 이름이 없으면 `--resolve-local`을 붙입니다. `[WARN]` 항목은 운영 전에 고칩니다([보안 운영](guide:security)).
- 관리자 비밀번호를 잃거나 계정이 잠기면 `php tools/user_cli.php reset-password <로그인 ID>` 또는 `unlock <로그인 ID>`로 복구합니다.

## 배포 후 확인

1. `php tools/check_env.php` — FAIL 없음.
2. `php tools/migrate.php --status` — 대기 없음.
3. `php tools/worker.php --status` — 실행 중, 최근 생존 신호.
4. 다른 PC에서 `https://<서버 주소>/data/`, `/config/config.ini`, `/bin/`, `/backups/`가 모두 404인지 확인합니다. HTTP 요청은 HTTPS로 301 리다이렉트되어야 합니다.
5. 관리자로 로그인해 비밀번호를 바꾸고 {{ui:admin_system.title}} > {{ui:admin_tools.title}}에서 ruff·ESLint가 사용 중으로 보이는지 확인합니다.
6. 작은 프로젝트를 올려 분석이 끝까지 되는지 봅니다.
7. 운영 전 {{ui:admin_system.title}} > {{ui:admin_backups.subnav}}에서 첫 백업을 만들고 키를 내보내 둡니다.
8. (선택) 가이드의 {{ui:guide.tab.faq}} 탭을 기본 질문으로 채웁니다: `php tools/faq_import.php --dry-run`으로 들어갈 질문을 확인하고 `php tools/faq_import.php --draft`로 비공개로 넣은 뒤, {{ui:guide.tab.faq}} 탭에서 훑어보고 공개로 바꿉니다. 여러 번 실행해도 이미 있는 질문은 건너뛰고 고친 내용을 덮어쓰지 않습니다.

## 문제 해결

- 화면 대신 파일 목록이나 `config/` 내용이 보입니다: DocumentRoot가 `public/`이 아니거나 프로젝트가 `htdocs/` 아래에 있습니다.
- 설정 관리에서 저장이 실패합니다: 웹 계정에 `config/` 폴더 쓰기 권한이 없습니다.
- SAML 단추가 없습니다: 묶음에 `vendor/`가 빠졌을 수 있습니다([배포 묶음](guide:deploy-bundle)).
- 업로드가 중간에 실패합니다: `post_max_size`, `LimitRequestBody`, 프록시 본문 한도가 청크 크기보다 작은지 확인합니다.

라이선스와 고지: PJGuardian은 Apache License 2.0입니다. 포함된 서드파티 구성요소는 [서드파티 라이선스](/guide/licenses) 화면에, 프로젝트 라이선스·무보증·상표·개인정보 고지는 배포 폴더의 `LICENSE`, `NOTICE`, `docs/legal/`에 있습니다.
