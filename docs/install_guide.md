# PJGuardian 설치 가이드

이 문서는 PJGuardian을 새 PC(서버)에 설치하는 순서와, **이미 운영 중인 PC의 DB 데이터를 새 설치에서 그대로 쓰는 방법**을 설명한다.
세부 설정은 각 전문 문서(`docs/xampp_vhost.md`, `docs/worker_setup.md`, `docs/tool_setup.md`, `docs/backup_restore.md`, `docs/vendor_build.md`)를 따른다.

> 검증 상태: Windows + XAMPP 개발 PC에서 확인한 절차다. **Linux 운영, 실제 LDAP/AD·IdP 연동, 운영 Apache의 정식 인증서 환경은 아직 검증되지 않았다.** 해당 부분은 "참고"로 읽는다.

---

## 0. 먼저 정할 것 — 기존 DB를 어떻게 쓸 것인가

| 상황 | 권장 방법 | 이 문서 |
| --- | --- | --- |
| 새 PC에서 운영하고, 옛 PC의 계정·결함·프로젝트 데이터를 이어서 쓴다 | **백업 → 복원** (옛 PC에서 묶음을 만들고 새 PC에서 복원) | 10절 |
| 옛 PC는 그대로 두고 데이터만 복사해 시험해 본다 | 같은 방법(백업 → 복원). 옛 PC는 바뀌지 않는다 | 10절 |
| 옛 PC가 계속 운영 서버이고, 다른 PC는 접속만 한다 | 설치할 필요 없음. 브라우저로 옛 PC 주소에 접속 | 10.6절 |
| 두 PC가 DB 파일 하나를 공유한다 | **하지 않는다.** SQLite 파일을 네트워크 공유로 함께 쓰면 DB가 깨질 수 있다 | 10.5절 |

**DB 파일(`data/…sqlite`)을 직접 복사하지 않는다.** 백업 묶음은 `VACUUM INTO`로 일관된 사본을 만들고, 검증·마이그레이션·세션 무효화·자동 되돌리기를 함께 처리한다.

---

## 1. 준비물

| 항목 | 요구 | 비고 |
| --- | --- | --- |
| OS | Windows 11 / Server (검증됨), Linux (미검증) | |
| 웹 서버 | Apache 2.4 (XAMPP 가능), HTTPS 필수 | 프로젝트 폴더를 `htdocs/` 아래에 두지 않는다 |
| PHP | 8.2 또는 8.3 | 확장: sqlite3/pdo_sqlite, mbstring, intl, openssl, zip. LDAP 로그인을 쓰면 ldap |
| Node.js | 24 LTS | ESLint 실행용. Apache·워커를 실행하는 계정의 PATH에서 `node`가 보여야 한다 |
| 분석 도구 | ruff, ESLint | `bin/` 아래에 버전별로 배치 (6절) |
| git / p4 | 저장소 연동을 쓸 때만 | `docs/vcs_tools.md` |
| 설치 파일 | 배포 묶음 (코드 + **`vendor/`** + `resources/guide/`) | 서버에서 Composer를 돌릴 필요 없음 |
| 디스크 | 업로드·분석 작업 공간 + 백업 공간 | 백업은 가능하면 다른 디스크 |

배포 묶음에 `vendor/`가 없으면 SAML 로그인만 꺼진 것처럼 동작한다(나머지 기능은 영향 없음).

---

## 2. 코드 배치

1. 배포 묶음을 풀어 **웹 루트 밖**에 둔다. 예: `D:\PJGuardian` (Linux: `/opt/pjguardian`).
   - 이 문서에서는 `<설치폴더>`라고 부른다.
2. 확인할 구성:

```
<설치폴더>\
  public\        ← Apache DocumentRoot (웹에 노출되는 유일한 폴더)
  src\  config\  migrations\  resources\  tools\  vendor\  bin\  data\
```

3. 개발용 파일(`tests/`, `tools/proto/`, `.git/`)과 비밀(`config/config.ini`, `config/secrets.ini`, `*.pjgkey`)은 묶음에 없어야 한다. 묶음 점검:

```
php tools\verify_bundle.php <묶음 폴더>
```

문제가 있으면 목록을 출력하고 종료 코드 1로 끝난다(읽기 전용).

---

## 3. Apache 가상 호스트와 HTTPS

자세한 설명: `docs/xampp_vhost.md`.

### 3.1 모듈 확인

`C:\xampp\apache\conf\httpd.conf`에서 `mod_ssl`, `mod_headers`, `mod_rewrite`(필요 시), `httpd-vhosts.conf` include 줄의 `#`이 풀려 있어야 한다.

### 3.2 인증서

운영은 정식 인증서를 쓴다. 시험용 자체 서명 인증서 예:

```
openssl req -x509 -newkey rsa:2048 -nodes -days 365 ^
  -keyout pjguardian.key -out pjguardian.crt ^
  -subj "/CN=pjguardian.local" -addext "subjectAltName=DNS:pjguardian.local"
```

(Windows XAMPP: `C:\xampp\apache\bin\openssl.exe`. 줄 이음은 PowerShell이면 `` ` ``.) 결과 파일을 `C:\xampp\apache\conf\ssl.crt\`, `ssl.key\`에 둔다.

### 3.3 VirtualHost

`C:\xampp\apache\conf\extra\httpd-vhosts.conf`에 추가한다(경로는 `/`로 쓴다). `<설치폴더>`를 실제 경로로 바꾼다.

```apache
<VirtualHost *:80>
    ServerName pjguardian.local
    Redirect permanent / https://pjguardian.local/
</VirtualHost>

<VirtualHost *:443>
    ServerName pjguardian.local
    # 다른 PC가 IP로 접속하게 하려면: ServerAlias <서버 IP>(예: 192.0.2.10)
    DocumentRoot "D:/PJGuardian/public"

    SSLEngine on
    SSLCertificateFile    "C:/xampp/apache/conf/ssl.crt/pjguardian.crt"
    SSLCertificateKeyFile "C:/xampp/apache/conf/ssl.key/pjguardian.key"

    # HSTS는 앱이 보낸다(config.ini [security] hsts_max_age). 여기서 Header always set으로 또 넣으면 응답에 두 번 나가므로 넣지 않는다

    <Directory "D:/PJGuardian/public">
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <Directory "D:/PJGuardian">
        Require all denied
    </Directory>

    ErrorLog  "logs/pjguardian-error.log"
    CustomLog "logs/pjguardian-access.log" combined
</VirtualHost>
```

### 3.4 hosts 파일 (이름으로 접속할 PC마다)

관리자 권한으로 `C:\Windows\System32\drivers\etc\hosts`에 추가한다. 사내 DNS가 있으면 DNS에 등록한다.

```
127.0.0.1  pjguardian.local
```

### 3.5 적용과 확인

```
C:\xampp\apache\bin\httpd.exe -t     # 문법 검사 → Syntax OK
C:\xampp\apache\bin\httpd.exe -S     # 80/443의 default server 확인
```

XAMPP Control Panel에서 Apache를 재시작한다.
IP로 접속할 때 PJGuardian이 첫 번째(기본) VirtualHost가 아니면 `ServerAlias <서버 IP>`를 더한다.

### 3.6 외부 노출 점검

아래 주소가 모두 **404**여야 한다(200이면 DocumentRoot가 잘못됨).

```
https://pjguardian.local/data/
https://pjguardian.local/config/config.ini
https://pjguardian.local/bin/
https://pjguardian.local/backups/
```

점검 절차 전체는 `docs/xampp_vhost.md` 4절.

---

## 4. php.ini

`C:\xampp\php\php.ini`(Apache용)와 CLI에서 쓰는 `php.ini`를 확인한다(`php --ini`).

- `extension=intl`, `extension=mbstring`, `extension=openssl`, `extension=zip`, `extension=pdo_sqlite`, `extension=sqlite3` 활성화. LDAP를 쓰면 `extension=ldap`.
- 업로드 한도: `upload_max_filesize`, `post_max_size`, `memory_limit`을 업로드 크기에 맞춘다(기본 한도와 설정 표는 `docs/xampp_vhost.md` 7절). PJGuardian은 큰 파일을 조각으로 올리므로 조각 크기보다 `post_max_size`가 커야 한다.
- intl이 CLI에서 꺼져 있으면 워커·도구 명령에 `-d extension=intl`을 붙인다.

---

## 5. 설정 파일과 비밀 키

```
copy config\config.ini.example config\config.ini
copy config\secrets.example   config\secrets.ini     # 이미 secrets.ini가 있으면 건너뜀
php tools\make_secret_key.php                         # 저장소 자격 증명 암호화 키 생성(키가 없을 때)
```

`config\config.ini`에서 **이 PC에 맞게** 바꿀 항목:

| 항목 | 내용 |
| --- | --- |
| `[app] base_url` | 사용자가 접속하는 주소. 예 `https://pjguardian.local` |
| `[security] hsts_max_age` | HSTS 유지 기간(예 `365d`, 기본 1년). HSTS 헤더는 앱이 모든 HTTPS 응답에 붙인다 — Apache vhost에는 넣지 않는다(3.3절, `docs/hardening.md`) |
| 경로 항목(데이터·백업 등) | 이 PC의 폴더. 백업 폴더는 다른 디스크 권장 |

주의:
- `config.ini`, `secrets.ini`는 **비밀이므로 git·배포 묶음·메신저로 보내지 않는다.** PC마다 따로 만든다.
- `secrets.ini`의 `encryption_key`는 저장소 연동 자격 증명(예: git/p4 비밀번호)을 암호화한다. **옛 PC의 데이터를 복원할 때는 이 키도 함께 옮겨야 한다**(10절).

### 5.1 폴더 권한

- Windows: Apache와 워커를 실행하는 계정에 `data\`, 백업 폴더, `config\`(설정 관리가 `settings.local.ini`와 임시·백업·잠금 파일을 씀)의 **쓰기** 권한. 나머지는 읽기만.
- Linux(미검증): `data/`·백업 폴더·`config/`만 웹 서버·워커 계정이 쓰게 한다.

```
chown -R root:www-data /opt/pjguardian
chown -R www-data:www-data /opt/pjguardian/data
chmod -R u=rwX,g=rX,o= /opt/pjguardian
chmod -R ug=rwX,o= /opt/pjguardian/data
chmod 0770 /opt/pjguardian/config
```

주의: 웹 계정이 `config/`에 쓸 수 있으면 웹 애플리케이션 취약점이 `config.ini`·`secrets.ini` 변조로 이어질 수 있습니다. 두 파일은 가능하면 파일 단위로 읽기 전용으로 두세요(Windows에서 `settings.local.ini*`에만 쓰기를 주는 방법은 **운영 환경에서 확인할 것**). `config/`는 웹으로 열리지 않습니다(DocumentRoot는 `public/`).

---

## 6. 분석 도구 (`bin/`)

자세한 절차와 해시 확인: `docs/tool_setup.md`.

```
bin\ruff\<버전>\windows\ruff.exe        (Linux: bin/ruff/<버전>/linux/ruff)
bin\ruff\<버전>\SHA256SUMS
bin\eslint\<버전>\package.json, package-lock.json, node_modules\, SHA256SUMS
```

1. **ruff**: GitHub 릴리스에서 OS별 파일을 받아 SHA-256을 비교하고 `bin\ruff\<버전>\windows\`에 푼다. `ruff.exe --version`으로 확인.
2. **Node.js 24 LTS**: 설치 프로그램(.msi)으로 설치하고 시스템 PATH에 추가한다(서비스 계정은 사용자 PATH를 쓰지 못한다).
3. **ESLint**: `bin\eslint\<버전>\`에서
   ```
   npm install --save-exact --ignore-scripts eslint@10.11.0 @eslint/js@10.0.1 @eslint/eslintrc@3.3.7 @typescript-eslint/parser@8.71.0 @typescript-eslint/eslint-plugin@8.71.0 typescript@6.0.3
   ```
   (현재 `bin/eslint/10.11.0/package.json` 기준. 버전은 설치 시점에 다시 확인한다.)
4. 운영 PC가 인터넷이 막혀 있으면 다른 PC에서 위 폴더를 만들어 통째로 복사한다. 복사한 뒤 `SHA256SUMS`와 비교한다.
5. 옛 PC에서 복원하는 경우: 묶음에는 **도구 실행 파일이 없고 체크섬 목록만** 있다. 도구는 이 PC에 직접 배치하고 10.4절의 `--check-bin`으로 맞는지 비교한다.

관리자 화면 **시스템 관리 > 분석 도구**에서 설치 상태를 확인할 수 있다.

---

## 7. 데이터베이스 만들기 (새 설치일 때)

> **옛 PC의 데이터를 쓸 때는 이 절을 건너뛰고 10절로 간다.** 복원이 DB 생성과 마이그레이션까지 처리한다.

```
php tools\check_env.php          # PHP 버전·확장·폴더 권한·도구 점검
php tools\migrate.php            # 모든 마이그레이션 적용(DB 생성 포함)
php tools\migrate.php --status   # 적용 상태 확인
php tools\create_admin.php --login=admin --name="관리자" [--email=<이메일>]   # 최초 관리자 만들기(--login, --name 필수)
```

- 초기 관리자: `--login`에 준 아이디, 초기 비밀번호는 고정값 `admin`(만들 때 화면에 안내됨). **첫 로그인에서 바로 사용할 수 있으나, 운영 서버는 설치 직후 직접 변경하기를 권한다.** 바꾸기 전까지 관리자 화면 위에 경고 배너가 보이고 하드닝 점검이 경고한다.
- 마이그레이션은 설치·업그레이드 담당자가 직접 실행한다. 앱이 웹 요청 중에 DB 구조를 바꾸지 않는다.

---

## 8. 워커와 정리 작업 등록

분석과 업로드 후처리는 웹 요청이 아니라 워커(`tools\worker.php`)가 한다. **워커가 꺼져 있으면 분석이 시작되지 않는다.** 워커는 항상 1개만 돌아야 한다(이미 있으면 종료 코드 3).

관리자 PowerShell에서(경로는 실제 설치 폴더로):

```powershell
# 워커: 1분마다 실행, 이미 실행 중이면 새로 띄우지 않음
$action  = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' -Argument '-d extension=intl D:\PJGuardian\tools\worker.php' -WorkingDirectory 'D:\PJGuardian'
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit ([TimeSpan]::Zero) -StartWhenAvailable
Register-ScheduledTask -TaskName 'PJGuardianWorker' -Action $action -Trigger $trigger -Settings $settings -User 'SYSTEM' -RunLevel Highest

# 정리 작업: 매일 03:00
$a = New-ScheduledTaskAction -Execute 'C:\xampp\php\php.exe' -Argument '-d extension=intl D:\PJGuardian\tools\cleanup.php' -WorkingDirectory 'D:\PJGuardian'
Register-ScheduledTask -TaskName 'PJGuardianCleanup' -Action $a -Trigger (New-ScheduledTaskTrigger -Daily -At 3am) -User 'SYSTEM'
```

- 서비스로 돌리려면 NSSM(Windows), systemd(Linux)를 쓴다. 설정 예는 `docs/worker_setup.md` 3.2절·4절.
- 확인·정지: `php tools\worker.php --status`, `php tools\worker.php --stop`.
- 계정이 `SYSTEM`이면 `data\` 권한과 `node` PATH가 그 계정에서 유효한지 확인한다.

---

## 9. 설치 확인

1. `php tools\check_env.php` — 이상 없음.
2. `php tools\migrate.php --status` — 모두 적용됨.
3. `php tools\worker.php --status` — 실행 중, 최근 생존 신호.
4. 브라우저에서 `https://pjguardian.local/` 접속 → 로그인 화면. 인증서 경고가 있으면 시험용 인증서를 신뢰 등록한다.
5. 관리자로 로그인 → 비밀번호 변경 → **시스템 관리 > 분석 도구**에서 ruff·ESLint가 정상으로 보이는지 확인.
6. 작은 프로젝트로 업로드 → 분석이 끝까지 되는지 확인.
7. 3.6절의 외부 노출 점검이 모두 404인지 확인.
8. 운영 전 관리자 화면 **시스템 관리 > 백업·복원**에서 첫 백업을 만들고 **키 내보내기**로 `.pjgkey`를 받아 둔다(10.1절).
9. 관리자 메뉴 **시스템 모니터링**에서 워커·디스크·백업 카드가 정상인지 본다. 외부 감시 도구(서버 감시·Zabbix 등)를 쓰면 **시스템 관리 > 설정 관리 > 모니터링**의 "헬스체크 허용 IP"에 감시 도구 주소를 넣고 `curl -s -o /dev/null -w "%{http_code}" https://pjguardian.local/health` 같은 호출을 등록한다(200 = 정상·경고, 503 = 장애, 허용 밖은 404). 앞단 프록시를 쓰면 프록시 주소를 넣고 프록시에서 `/health`를 감시 도구에만 연다(`docs/monitoring.md` 6절). 관리자 장애 이메일은 **시스템 관리 > 설정 관리 > 관리자 이메일** 탭에서 켠다(`docs/monitoring.md`).

---

## 10. 기존(운영 중인) PC의 DB 데이터를 쓰는 방법

핵심: **DB 파일을 복사하지 말고, 옛 PC에서 백업 묶음(`.pjgbak`)과 키 파일(`.pjgkey`)을 만들어 새 PC에서 복원한다.**

묶음 안에는 DB 전체(계정·결함·프로젝트 설정)와 분석 스냅샷이 있다. 설정 파일의 비밀 값은 비워져 있고, 실행 파일(`bin/`)은 들어 있지 않다. DB와 스냅샷에는 절대 경로가 없으므로 새 PC의 설치 경로·드라이브가 달라도 그대로 동작한다.

### 10.1 옛 PC에서 — 묶음과 키 만들기

옛 PC가 새 버전과 같거나 **더 낮은 앱 버전**이어야 한다. 묶음의 스키마가 새 PC의 앱보다 **새것이면 거부**되고, 더 오래된 것이면 복원 때 자동으로 마이그레이션된다. 먼저 새 PC를 옛 PC 이상의 버전으로 설치한다.

1. **분석이 돌고 있지 않은 시간**을 고른다(백업은 워커 작업이며, 일관된 사본을 만든다).
2. 백업 묶음 만들기 — 둘 중 하나:
   - 화면: 관리자 **시스템 관리 > 백업·복원 > 지금 백업**(스냅샷 포함 여부 선택)
   - 명령줄: `php tools\backup.php` (만든 직후 자동 검증. 스냅샷 제외는 `--no-snapshots`)
3. 묶음 확인: `php tools\backup.php --verify` (또는 화면에서 "다시 검증").
4. **키 내보내기**(화면에서만 가능, 명령줄 없음): 백업·복원 화면의 "키 내보내기" → 비밀번호 재입력 → 암호문구 12자 이상 두 번 입력 → `pjguardian-key-<날짜>.pjgkey` 저장.
   - 이 키가 있어야 DB에 저장된 **저장소 연동 자격 증명**(git/p4 비밀번호 등)이 새 PC에서도 풀린다.
   - 암호문구는 어디에도 저장되지 않는다. 잃으면 키를 복구할 수 없다(저장소 연동 정보는 다시 입력하면 된다).
5. 묶음 파일 위치: 기본은 옛 PC 설치 폴더의 `backups\` (설정에서 바꿨다면 그 폴더). 화면의 **내려받기**(비밀번호 재입력, 60초 일회용 주소)를 쓰거나 폴더에서 파일을 직접 복사한다.

### 10.2 옮기기

- 묶음(`.pjgbak`)과 키(`.pjgkey`)는 **서로 다른 경로로** 옮긴다. 예: 묶음은 USB, 키는 암호문구와 함께 별도 전달.
  - 묶음에는 계정·결함 전체가 있고, 키 파일은 암호문구 없이는 쓸 수 없지만 둘이 같이 유출되면 위험하다.
- 사내 파일 서버·네트워크 드라이브를 거치면 전송 후 크기가 같은지 확인한다. 복원 전에 검증이 한 번 더 있다.
- **옮긴 뒤 옛 PC·이동 매체의 사본은 정리한다**(10.8절).

### 10.3 새 PC에서 — 설치까지

1절~6절, 8절을 마친다. 7절(DB 만들기)은 하지 않아도 된다. 단 다음은 필요하다:

```
copy config\config.ini.example config\config.ini     (이 PC 경로·base_url에 맞게 수정)
php tools\make_secret_key.php                        (secrets.ini의 키가 없을 때)
```

워커는 **복원이 끝날 때까지 시작하지 않는다.** 이미 돌고 있으면 정지한다(`php tools\worker.php --stop`). 복원 명령은 워커가 돌고 있으면 거부한다.

### 10.4 새 PC에서 — 복원

묶음 파일을 새 PC로 옮긴다(예: `D:\transfer\pjguardian-20261010.pjgbak`).

1. **사전 검증(바꾸지 않음)**

   ```
   php tools\restore.php --file=D:\transfer\pjguardian-20261010.pjgbak --dry-run
   ```
   묶음이 손상되지 않았는지, 스키마가 맞는지, 복원 후 무엇이 바뀌는지 미리 본다.

2. **분석 도구 비교** (도구는 OS·PC마다 다시 배치하므로 따로 확인)

   ```
   php tools\restore.php --file=D:\transfer\pjguardian-20261010.pjgbak --check-bin
   ```
   옛 PC에서 쓰던 ruff·ESLint 버전이 새 PC의 `bin\`에 같은 버전·같은 체크섬으로 있는지 비교한다. 다르면 6절로 돌아가 맞춘다.

3. **복원 실행** — 키를 함께 가져오는 경우

   PowerShell:
   ```powershell
   $env:PJG_KEY_PASSPHRASE = '<암호문구>'
   php tools\restore.php --file=D:\transfer\pjguardian-20261010.pjgbak --key=D:\transfer\pjguardian-key-20261010.pjgkey
   Remove-Item Env:PJG_KEY_PASSPHRASE
   ```
   - 암호문구는 명령줄 인자로 받지 않는다. 환경 변수 `PJG_KEY_PASSPHRASE`를 쓰거나 비워 두고 프롬프트에 입력한다(화면에 보일 수 있음).
   - 확인 프롬프트에 **`복원`** 을 입력한다(`--yes`면 생략).
   - `--with-config`: 묶음의 설정 값을 가져온다. 설정 차이를 항목마다 묻는다. **PC 고유 항목(경로·URL 등)은 기본 N**이다. 처음에는 쓰지 말고 설정은 새 PC에 맞게 직접 정리하는 편이 안전하다.
   - 키를 가져올 때는 ① 복호화, ② **복원 DB의 자격 증명을 실제로 풀어 보는 시험**을 통과해야 한다. 실패하면 아무것도 바꾸지 않는다.
   - DB가 아직 없는 새 설치에서도 동작한다(직전 백업은 건너뜀).

4. 복원 내용(자동 처리):
   - 유지보수 모드로 전환 → DB 교체 → 필요한 마이그레이션 적용 → 모든 로그인 세션 무효화 → 유지보수 해제.
   - 도중 실패하면 **자동으로 직전 상태로 되돌린다**(이전 DB가 있었던 경우 `pre_restore` 백업 생성).
   - 종료 코드: 0 성공, 1 실패·거부, 2 사용법·설정 오류.

5. **키 없이 복원**(키 파일을 못 옮겼거나 암호문구를 잃은 경우)
   - `--key` 없이 같은 명령을 실행한다. 앱은 정상 기동한다.
   - 암호화된 **저장소 연동 자격 증명은 풀리지 않는다.** 해당 프로젝트에는 "연결 정보를 다시 입력하세요"가 표시되고, 그 프로젝트의 주기 분석은 건너뛴다. 관리자에게 프로젝트마다 한 번 알림이 간다.
   - 관리자가 프로젝트 설정에서 저장소 자격 증명을 다시 입력하면 된다. 그 외 데이터(계정·결함·업로드 분석)는 영향 없다.

### 10.5 하면 안 되는 방식

- **SQLite DB 파일을 네트워크 공유(SMB/NFS)에 두고 두 PC에서 함께 쓰기**: 파일 잠금이 보장되지 않아 DB가 깨질 수 있다. 하나의 DB 파일에는 **한 대의 PJGuardian(웹+워커)만** 붙인다.
- **`data\` 폴더·`.sqlite` 파일을 단순 복사**: 쓰는 중이면 일관되지 않은 파일이 복사된다. 마이그레이션·세션·키 처리도 빠진다.
- **옛 PC와 새 PC를 동시에 운영**하며 데이터를 맞추는 것: 복원은 한 방향 덮어쓰기이며 병합 기능이 없다. 복원 시점 이후 옛 PC에서 생긴 변경은 새 PC에 없다. 전환 시점을 정해 옛 PC는 읽기 전용으로 멈춘 뒤(워커 정지, 새 업로드 중단) 마지막 백업을 복원한다.
- **`config.ini`/`secrets.ini` 통째로 복사**: 경로·URL이 PC마다 다르고 비밀이 섞여 있다. 키만 `.pjgkey`로 옮긴다.

### 10.6 대안 — 옛 PC를 그대로 서버로 쓰기

데이터를 옮길 이유가 "다른 PC에서 쓰고 싶다"뿐이라면 설치가 필요 없다.

1. 옛 PC의 vhost에 `ServerAlias <옛 PC IP>` 또는 사내 DNS 이름을 추가(`docs/xampp_vhost.md` 6절)하고 `config.ini`의 `[app] base_url`을 그 주소로 맞춘다.
2. 방화벽에서 443을 연다.
3. 다른 PC는 브라우저로 `https://<옛 PC 주소>/`에 접속한다.

### 10.7 복원 직후 확인

1. `php tools\migrate.php --status` — 모두 적용됨(복원이 마이그레이션까지 한다).
2. `php tools\worker.php` 또는 8절 작업 스케줄러 시작 → `php tools\worker.php --status`.
3. 브라우저로 로그인 — **모든 세션이 무효화되므로 다시 로그인해야 한다.** 로컬 계정은 옛 PC의 비밀번호 그대로다(초기 `admin/admin`이 아님).
4. 관리자 **시스템 관리 > 설정 관리**에서 이 PC에 맞게 점검할 항목:
   - 저장소 연결(허용 호스트, 자격 증명 재입력 필요 여부), 알림(SMTP), 백업 폴더, 디스크 임계값.
   - 외부 계정(LDAP/AD·OIDC·SAML)을 쓰면 이 PC에서 IdP·LDAP 서버에 접속 가능한지, SSO 콜백 주소(base_url 기준)가 IdP에 등록돼 있는지. **base_url이 바뀌면 IdP 등록도 바꿔야 한다.** 이 연동은 실제 환경에서 아직 검증되지 않았다.
5. 대표 프로젝트의 결함 목록·상세, 최근 분석 결과가 보이는지 확인. 새 분석 하나를 돌려 끝까지 되는지 확인.
6. **시스템 관리 > 백업·복원**에서 새 PC에서 첫 백업을 만든다.

### 10.8 마무리 — 정리

- 옛 PC·USB·전송 폴더의 `.pjgbak`, `.pjgkey` 사본 삭제. 암호문구는 안전한 곳에만 보관.
- 새 PC에서 백업 폴더와 `.pjgkey`를 다른 위치에 둔다(같은 디스크에 두면 디스크 고장 시 함께 잃는다).
- 옛 PC를 폐기·재사용하기 전에 새 PC에서 며칠간 문제가 없는지 확인한다. 그 사이에는 옛 PC의 워커를 정지해 두어 이중 운영을 막는다.

### 10.9 문제가 생겼을 때

| 증상 | 조치 |
| --- | --- |
| 복원이 "묶음 스키마가 앱보다 새것"이라며 거부 | 새 PC의 앱 버전이 옛 PC보다 낮다. 새 PC를 같거나 높은 버전으로 설치한다 |
| `key_import_failed` | 암호문구가 틀렸거나 `.pjgkey` 파일이 변조·손상됨. 다시 시도하거나 키 없이 복원 후 자격 증명을 재입력 |
| `key_test_failed` | 가져온 키로 복원 DB의 자격 증명이 풀리지 않는다(다른 시점의 키). 올바른 짝의 키를 쓰거나 키 없이 복원 |
| 모든 화면이 "복원 진행 상태" | 유지보수 플래그가 남음. `php tools\restore.php --leave-maintenance` |
| 복원 도중 실패 | 자동으로 되돌려진다. 메시지와 `data\logs`를 확인한다 |
| 로그인이 안 됨 | 세션 무효화는 정상. 옛 PC의 계정·비밀번호로 로그인. 외부 계정은 연동 설정 확인 |
| 분석 시작이 안 됨 | 워커 미실행 또는 `bin\` 도구 버전/체크섬 불일치(`--check-bin`) |
| 저장소 가져오기 실패 | "연결 정보를 다시 입력하세요" — 키 없이 복원한 경우. 자격 증명 재입력 |

---

## 11. 업그레이드 (참고)

1. 업그레이드 전 `php tools\backup.php --kind=pre_upgrade` (또는 화면 백업)과 키 내보내기.
2. 워커 정지(`php tools\worker.php --stop`).
3. 새 배포 묶음으로 코드 교체(`config\config.ini`, `config\secrets.ini`, `data\`, `bin\`, `backups\`는 덮어쓰지 않는다. `vendor\`는 새 묶음 것으로 교체).
4. `php tools\migrate.php` → `php tools\migrate.php --status`.
5. 워커 재시작, 9절 확인.

---

## 12. 체크리스트

- [ ] 프로젝트 폴더가 `htdocs/` 밖이고 DocumentRoot가 `public/`이다
- [ ] HTTPS 동작, HTTP는 HTTPS로 이동, `data/`·`config/`·`bin/`·`backups/`가 404
- [ ] `check_env.php` 이상 없음, `migrate.php --status` 모두 적용
- [ ] ruff·ESLint(Node 24) 동작, 서비스 계정 PATH에서 `node` 확인
- [ ] 워커·정리 작업 등록, 워커 1개만 실행
- [ ] 초기 관리자 비밀번호 변경(또는 복원된 계정으로 로그인)
- [ ] 백업 폴더를 다른 디스크로, 첫 백업과 `.pjgkey` 보관 확인
- [ ] (옛 데이터 이전 시) 10.7절 확인, 10.8절 사본 정리
