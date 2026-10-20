# XAMPP VirtualHost 설정 — DocumentRoot를 public/으로

PJGuardian은 웹에 `public/` 폴더만 노출한다. `data/`(DB·업로드·로그·스냅샷), `bin/`, `config/`, `src/`는 웹 루트 밖에 있어야 한다 (명세 2, 10).

**프로젝트 폴더를 XAMPP의 `htdocs/` 아래에 두지 않는다.** `htdocs/` 아래에 두면 기본 DocumentRoot를 통해 `data/`, `config/`가 노출될 수 있다.

아래 예시의 경로와 호스트 이름(`pjguardian.local`)은 환경에 맞게 바꾼다.

## 1. Windows (XAMPP)

프로젝트 경로 예: `D:\PJGuardian`

### 1.1 필요한 모듈 확인

`C:\xampp\apache\conf\httpd.conf`에서 아래 줄의 주석(`#`)이 풀려 있는지 확인한다.

```apache
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule ssl_module modules/mod_ssl.so
Include conf/extra/httpd-vhosts.conf
Include conf/extra/httpd-ssl.conf
```

### 1.2 VirtualHost 추가

`C:\xampp\apache\conf\extra\httpd-vhosts.conf`에 추가한다. Apache 설정에서는 Windows 경로도 `/`로 쓴다.

> **주의 — 기본 사이트가 바뀐다**: 이 파일에서 포트별로 **처음 나오는 VirtualHost가 그 포트의 기본 사이트**가 된다. 파일에 다른 VirtualHost가 없는 상태에서 아래 설정만 넣으면 `http://localhost`가 `https://pjguardian.local`로 전환되고, `https://localhost`는 PJGuardian의 `public/`을 연다. 같은 PC의 다른 `htdocs` 사이트를 쓰려면 PJGuardian 설정보다 **앞에** localhost 기본 사이트를 둔다(2026-10-02 개발 PC에서 실제로 발생해 추가함).
>
> ```apache
> <VirtualHost *:80>
>     ServerName localhost
>     DocumentRoot "C:/xampp/htdocs"
> </VirtualHost>
> <VirtualHost *:443>
>     ServerName localhost
>     DocumentRoot "C:/xampp/htdocs"
>     SSLEngine on
>     SSLCertificateFile    "conf/ssl.crt/server.crt"
>     SSLCertificateKeyFile "conf/ssl.key/server.key"
> </VirtualHost>
> ```
>
> 적용 후 `httpd.exe -S`로 포트별 `default server`가 localhost인지 확인한다.

```apache
# HTTP → HTTPS 리다이렉트 (명세 10: HTTPS 강제)
<VirtualHost *:80>
    ServerName pjguardian.local
    Redirect permanent / https://pjguardian.local/
</VirtualHost>

<VirtualHost *:443>
    ServerName pjguardian.local
    DocumentRoot "D:/PJGuardian/public"

    SSLEngine on
    SSLCertificateFile    "C:/xampp/apache/conf/ssl.crt/pjguardian.crt"
    SSLCertificateKeyFile "C:/xampp/apache/conf/ssl.key/pjguardian.key"

    # HSTS는 앱이 보낸다(security.hsts_max_age). 여기서 Header always set으로 또 넣으면 PHP 응답에 두 번 나가므로 넣지 않는다(P31)

    <Directory "D:/PJGuardian/public">
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted
        # 실제 파일이 아니면 프런트 컨트롤러(index.php)로 보낸다. AllowOverride None이라 public/.htaccess는 쓰지 않는다
        FallbackResource /index.php
    </Directory>

    # 프로젝트 루트의 나머지 폴더는 명시적으로 차단 (이중 방어)
    <Directory "D:/PJGuardian">
        Require all denied
    </Directory>

    ErrorLog  "logs/pjguardian-error.log"
    CustomLog "logs/pjguardian-access.log" combined
</VirtualHost>
```

`<Directory>`는 더 구체적인 경로가 우선하므로, `public/`은 허용되고 나머지는 거부된다.

> **프런트 컨트롤러**(P07): `public/`의 실제 파일(정적 자산)이 아닌 요청은 모두 `public/index.php`가 처리한다. vhost에서는 `FallbackResource /index.php`를 쓴다(위 예시). vhost를 고칠 수 없고 `AllowOverride`가 켜진 환경에서는 `public/.htaccess`의 재작성 규칙이 같은 일을 한다.


### 1.3 호스트 이름 등록

관리자 권한으로 `C:\Windows\System32\drivers\etc\hosts`에 추가한다.

```
127.0.0.1  pjguardian.local
```

XAMPP Control Panel에서 Apache를 재시작한다. 설정 문법은 `C:\xampp\apache\bin\httpd.exe -t`로 검사한다.

## 2. Linux

Linux에서도 XAMPP를 쓰는 경우와 배포판 Apache를 쓰는 경우의 설정 파일 위치만 다르고 내용은 같다.

| 구성 | vhost 설정 파일 | 재시작 |
| --- | --- | --- |
| XAMPP for Linux | `/opt/lampp/etc/extra/httpd-vhosts.conf` (`/opt/lampp/etc/httpd.conf`에서 Include 주석 해제) | `sudo /opt/lampp/lampp restart` |
| Debian/Ubuntu Apache | `/etc/apache2/sites-available/pjguardian.conf` → `sudo a2ensite pjguardian`, `sudo a2enmod ssl headers rewrite` | `sudo systemctl reload apache2` |

프로젝트 경로 예: `/opt/pjguardian`

```apache
<VirtualHost *:80>
    ServerName pjguardian.example.local
    Redirect permanent / https://pjguardian.example.local/
</VirtualHost>

<VirtualHost *:443>
    ServerName pjguardian.example.local
    DocumentRoot "/opt/pjguardian/public"

    SSLEngine on
    SSLCertificateFile    "/etc/ssl/certs/pjguardian.crt"
    SSLCertificateKeyFile "/etc/ssl/private/pjguardian.key"

    # HSTS는 앱이 보낸다(security.hsts_max_age) — 여기 넣지 않는다(P31)

    <Directory "/opt/pjguardian/public">
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /index.php
    </Directory>

    <Directory "/opt/pjguardian">
        Require all denied
    </Directory>

    ErrorLog  "/var/log/apache2/pjguardian-error.log"
    CustomLog "/var/log/apache2/pjguardian-access.log" combined
</VirtualHost>
```

권한(명세 12, X14 방침): `bin/`과 체크섬 매니페스트는 웹/애플리케이션 계정에 쓰기 권한을 주지 않는다. 예를 들어 소유자는 root, 웹 계정(`daemon` 또는 `www-data`)은 읽기·실행만 허용한다. 웹 계정이 쓸 수 있는 곳은 `data/`, 백업 폴더(`[backup] dir`), **`config/` 폴더**(설정 관리 화면이 `settings.local.ini`와 그 임시·백업·잠금 파일을 쓴다, `docs/settings.md` 1절)뿐이다. 워커 계정도 같다(같은 계정 또는 같은 그룹).

```bash
sudo chown -R root:root /opt/pjguardian
sudo chown -R www-data:www-data /opt/pjguardian/data
sudo chmod -R go-w /opt/pjguardian/bin
sudo chown root:www-data /opt/pjguardian/config && sudo chmod 0770 /opt/pjguardian/config
```

위험: 웹 계정이 `config/`에 쓸 수 있으면 웹 애플리케이션 취약점이 `config.ini`·`secrets.ini` 변조(설정·비밀 바꿔치기)로 이어질 수 있다. 완화: ① 파일 단위로 `config.ini`·`secrets.ini`는 가능한 한 웹 계정에 읽기 전용으로 두는 편이 더 안전하다(Linux는 두 파일을 root 소유 0640 등으로, Windows는 웹 계정에 폴더 읽기 + `settings.local.ini`와 그 임시·백업·잠금 파일(`settings.local.ini*`)에만 쓰기를 줄 수 있는지 — **운영 환경에서 확인할 것**). ② `config/`는 웹으로 열리지 않고(DocumentRoot는 `public/`), 설정 관리 화면이 쓰는 파일은 `settings.local.ini`(와 그 임시·백업·잠금 파일)뿐이다.

## 3. 개발 환경의 HTTPS

명세는 HTTPS만 허용하고 세션 쿠키에 Secure 속성을 요구한다 (명세 3, 10). 로컬 개발에서도 자체 서명 인증서로 HTTPS를 구성한다. 예(OpenSSL, Windows는 `C:\xampp\apache\bin\openssl.exe`):

```
openssl req -x509 -newkey rsa:2048 -nodes -days 365 \
  -keyout pjguardian.key -out pjguardian.crt \
  -subj "/CN=pjguardian.local" -addext "subjectAltName=DNS:pjguardian.local"
```

브라우저 경고가 나오면 개발 PC에서만 해당 인증서를 신뢰하도록 등록한다. 운영에서는 정식 인증서를 사용한다.

## 4. data/, bin/, config/가 웹에서 접근되지 않는지 확인

설정 후, 그리고 배포할 때마다 확인한다. 예시는 `curl`(Windows 10 이상 기본 포함)이며, 자체 서명 인증서면 `-k`를 붙인다. Windows 명령 프롬프트에서는 `/dev/null` 대신 `NUL`, `grep -i` 대신 `findstr /i`를 쓴다(PowerShell에서는 `curl` 대신 `curl.exe`).

### 4.1 파일 기준 확인

확인용 파일을 임시로 만든 뒤 요청한다.

```
# 프로젝트 루트에서
echo test > data/probe.txt
echo test > config/probe.txt
echo test > bin/probe.txt

curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/data/probe.txt
curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/config/probe.txt
curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/bin/probe.txt
curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/config/config.ini
curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/PJGuardian_requirements.md
```

- 모든 응답이 **404**여야 한다(DocumentRoot가 `public/`이라 해당 URL이 존재하지 않음). 200이 나오면 DocumentRoot 설정이 잘못된 것이다.
- 확인 후 `probe.txt` 파일을 삭제한다.

### 4.2 경로 조작 확인

```
curl -k -s -o /dev/null -w "%{http_code}\n" --path-as-is "https://pjguardian.local/../data/probe.txt"
curl -k -s -o /dev/null -w "%{http_code}\n" --path-as-is "https://pjguardian.local/%2e%2e/config/config.ini"
```

400 또는 404여야 하며, 파일 내용이 반환되면 안 된다.

### 4.3 디렉토리 목록과 HTTP 리다이렉트

```
curl -k -s -o /dev/null -w "%{http_code}\n" https://pjguardian.local/assets/
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" http://pjguardian.local/
curl -k -s -D - -o /dev/null https://pjguardian.local/ | grep -i strict-transport-security
```

- `/assets/`는 403이어야 한다(`Options -Indexes`).
- HTTP 요청은 301로 `https://`로 리다이렉트되어야 한다.
- HTTPS 응답에 `Strict-Transport-Security` 헤더가 **한 번** 있어야 한다(앱이 보냄 — `curl -skI https://<주소>/login | grep -ci strict-transport`가 1). 예전 안내대로 vhost에 `Header always set Strict-Transport-Security`가 있으면 지운다(P31: 두 번 나감).
- 보안 헤더(CSP·X-Frame-Options·X-Content-Type-Options·Referrer-Policy·Permissions-Policy)는 앱을 거치는 모든 응답에 앱이 붙인다. Apache가 직접 주는 `public/assets/` 아래 파일에 `nosniff`를 더하려면 vhost에 `Header setifempty X-Content-Type-Options "nosniff"`(앱 응답과 겹치지 않음)를 넣는다(선택).

### 4.4 기본 htdocs 노출 확인

XAMPP 기본 DocumentRoot(`C:/xampp/htdocs`, `/opt/lampp/htdocs`) 아래에 프로젝트 폴더나 그 사본·심볼릭 링크가 없는지 확인한다. XAMPP 하드닝(기본 계정·샘플 페이지 제거, 보안 헤더 등)은 설치 문서의 체크리스트를 따른다 (명세 19.5).

## 5. (개발 PC 선택) `https://localhost/PJGuardian/`으로 접속하기 — X01

hosts 파일을 고치지 않고 개발 PC에서 접속하려는 경우의 설정이다. 운영 서버에는 쓰지 않는다(운영은 1~2절처럼 전용 이름과 DocumentRoot를 쓴다).

`C:\xampp\apache\conf\extra\httpd-vhosts.conf`의 **localhost** VirtualHost 두 개에 추가한다.

```apache
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
    RedirectMatch permanent "^/PJGuardian(/.*)?$" "https://localhost/PJGuardian$1"
</VirtualHost>

<VirtualHost *:443>
    ServerName localhost
    DocumentRoot "C:/xampp/htdocs"
    SSLEngine on
    ...
    Alias /PJGuardian "D:/PJGuardian/public"
    <Directory "D:/PJGuardian/public">
        Options -Indexes -FollowSymLinks
        AllowOverride None
        Require all granted
        FallbackResource /PJGuardian/index.php
    </Directory>
</VirtualHost>
```

- 웹에 노출되는 폴더는 `public/` 하나뿐이다(Alias 대상). `data/`, `config/`, `bin/`, `src/`, `tests/`, `docs/`는 `localhost/PJGuardian/...`로 열리지 않는다. `htdocs`에 복사본을 만들지 않는다.
- 앱은 `SCRIPT_NAME`(`/PJGuardian/index.php`)에서 배포 경로 접두사 `/PJGuardian`을 계산해 라우팅·자산 주소·세션 쿠키 `Path`에 쓴다(`Request::basePathFromScript`).
- 개발용 `config/config.ini`만 바꾼다(`config.ini.example`의 운영 기본값은 그대로).

  ```ini
  [app]
  base_url = "https://localhost/PJGuardian"
  [security]
  ; localhost 전체에 HSTS가 걸려 다른 로컬 사이트가 HTTPS로 강제되지 않게
  hsts_max_age = 0
  ```

- 대표 주소는 하나다. `app.base_url`이 `localhost/PJGuardian`이면 앱의 HTTP→HTTPS 리다이렉트와 하드닝 점검이 이 주소를 쓴다. `pjguardian.local` vhost는 남아 있어 hosts가 있으면 그대로 열리지만 대표 주소가 아니다.
- 점검 도구(`tools/check_styleguide.mjs`, `tools/check_auth_ui.mjs`)는 주소를 생략하면 `config.ini`의 `app.base_url`을 쓴다.
- URL은 대소문자를 구분한다(`/PJGuardian`). `/pjguardian`은 열리지 않는다.
- 되돌리기: `httpd-vhosts.conf.bak_before_x01`을 `httpd-vhosts.conf`로 복사하고, `config.ini`의 두 값을 되돌린 뒤(백업 `data/backups/config.ini.bak_before_x01`) Apache를 재시작한다.

## 6. 다른 PC에서 IP 주소로 접속할 때 (P21C, 제안)

- **메뉴 글자**: 다른 PC에서 메뉴가 아이콘만 보이던 것은 IP 때문이 아니라 그 PC의 화면 폭이었다(배율 125~150%면 CSS 폭이 1280px 미만). P21C부터 1024px까지 글자가 보인다(주 메뉴가 둘째 줄로 내려감). 정적 파일·CSP는 주소(Host)와 무관하다.
- **HTTPS 리다이렉트**: `security.require_https = true`면 앱은 HTTP 요청을 `app.base_url`로 보낸다. `base_url`이 `https://localhost/...`이면 다른 PC에서는 그 PC 자신의 localhost로 가 버리므로, 여러 PC에서 쓰는 서버는 `base_url`을 **다른 PC에서도 열리는 이름**(예: `https://pjguardian.사내도메인` 또는 서버 IP)으로 정하고 그 이름의 인증서를 쓴다. 이 값은 운영 설정이라 서버 관리자가 바꾼다(앱이 Host 헤더를 믿고 리다이렉트하지 않는 것은 의도 — Host 위조 방지).
- **가상 호스트**: IP로 들어온 요청은 `ServerName`이 맞는 VirtualHost가 없으면 **첫 번째(기본) VirtualHost**로 간다. 이름 대신 IP로 접속하게 하려면 PJGuardian VirtualHost에 `ServerAlias <서버 IP>`를 더하거나 PJGuardian을 기본 VirtualHost로 둔다(설정 파일은 서버 관리자가 바꾸고 `httpd -t`로 확인 후 재시작).
- **확인 방법**: 다른 PC의 브라우저에서 개발자 도구 콘솔에 `innerWidth`를 입력해 CSS 폭을 본다(900 미만이면 메뉴가 아이콘만 보이는 것이 설계 — 899px 이하). 네트워크 탭에서 `/assets/v/<해시>/…`가 모두 200인지, 콘솔에 CSP 위반이 없는지 본다.

## 7. 업로드·요청 한도 (P19B 확인, XAMPP 기본값)

청크 업로드는 **원시 본문 PUT(청크 8MB 이하)**이고 조립·해제는 워커가 하므로, 웹 요청 하나의 크기·시간은 작다. 2026-10-08 개발 PC(XAMPP, Windows)에서 읽은 값과 판단:

| 설정 | 현재 값 | 필요한 값 | 판단 |
| --- | --- | --- | --- |
| `upload_max_filesize` (php.ini) | 40M | 해당 없음(multipart 업로드를 쓰지 않음) | 그대로 |
| `post_max_size` (php.ini) | 40M | ≥ 청크 크기(8MB) + 여유 | 통과 |
| `max_execution_time` (php.ini, 웹) | 120 | ≥ 연결 테스트 30초(P25) + 여유 | 통과 |
| `max_input_time` (php.ini) | 60 | 느린 회선에서 8MB를 60초 안에(약 140KB/s 이상) | 통과(더 느린 회선이면 늘리거나 `upload.chunk_size`를 줄임) |
| `memory_limit` (php.ini, 웹) | 512M | 청크 요청 최대 6~16MB(P12·P19B 실측) | 통과 |
| `LimitRequestBody` (Apache) | 지정 없음(2.4.54+ 기본 1GiB) | ≥ 8MB | 통과 |
| `Timeout` (httpd-default.conf) | 300 | ≥ 청크 전송 시간 | 통과 |
| `RequestReadTimeout body=20,MinRate=500` | 본문 시작 20초, 이후 500B/s 이상 | 느린 회선도 500B/s 이상 | 통과 |
| `ThreadsPerChild` (mpm_winnt) | 150 | 동시 사용자 100명 기준 | P31에서 부하 시험 |

- `upload.chunk_size`를 키우면 `post_max_size`와 Apache·프록시의 본문 한도를 함께 확인한다. 앞단에 리버스 프록시(nginx 등)가 있으면 `client_max_body_size`도 청크 크기 이상이어야 한다.
- 읽기 전용 점검 결과(P19B): `https://localhost/PJGuardian/`에서 세션 쿠키 `Path=/PJGuardian; HttpOnly; Secure; SameSite=Lax`, CSP `script-src 'self'`, `X-Frame-Options: DENY`, 정적 파일(`/assets/v/<해시>/…`) 200, `config/`·`data/`·`src/` 404, `.htaccess` 403, `http://` → `https://` 301.
