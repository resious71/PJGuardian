# XAMPP·PJGuardian 하드닝 체크리스트 (명세 19.5)

설치를 마친 뒤, 운영을 시작하기 전에 확인한다. 최초 관리자 설치 스크립트가 자동으로 점검할 수 있는 항목은 점검하고 미충족 항목을 경고로 출력한다.

```
php tools/create_admin.php --login=<로그인 ID> --name=<표시 이름>      # 관리자 생성 + 하드닝 점검
php tools/create_admin.php --check-only [--resolve-local] [--insecure]  # 점검만 다시
```

- `--resolve-local`: 호스트 이름 대신 127.0.0.1로 접속한다(hosts에 이름이 없을 때).
- `--insecure`: 인증서 검증을 끈다. 자체 서명 인증서를 쓰는 개발 PC에서만 쓴다.
- `--skip-http`: HTTP 점검을 건너뛴다.
- 서버 자신에서 점검하면 `Require local`로 막은 페이지도 열려 보인다. HTTP 항목은 다른 PC에서 브라우저나 curl로 한 번 더 확인한다.

결과 표기: `[OK]` 충족, `[WARN]` 미충족(운영 전에 고친다), `[INFO]` 자동으로 확인할 수 없어 안내만 한다.

## 0. 가장 먼저: 관리자 초기 비밀번호 확인

`php tools/create_admin.php --login=admin --name=관리자`를 옵션 없이 실행하면 최초 관리자의 **초기 비밀번호는 `admin`으로 고정**된다(사용자 확정 결정, X02 — 개발·운영 모두). **첫 로그인 때 변경을 강제하지 않는다**(2026-10-13 사용자 결정). 누구나 아는 값이므로 운영에서는 다음을 권한다.

1. 브라우저에서 `admin / admin`으로 로그인한다. 바로 모든 기능을 쓸 수 있다.
2. 외부에 공개하는 서버라면 **내 정보**에서 새 비밀번호로 바꾼다. 정책(8자 이상, 대문자·소문자·숫자·특수문자 중 2종 이상)이 그대로 적용된다.
3. **변경하기 전에는 외부에서 접근할 수 없게 한다**: 서버 방화벽에서 HTTPS 포트를 열기 전에 변경하거나, 내부망·서버 자신(`--resolve-local`)에서만 접속한다.
4. `php tools/create_admin.php --check-only`의 `관리자 초기 비밀번호` 항목이 `[OK]`인지 확인한다(바꾸기 전에는 `[WARN]` — 경고만 하고 막지 않는다. `app.base_url`이 localhost·127.0.0.1이 아니면 더 강한 문구, P31).
5. 바꾸기 전까지 관리자에게는 **모든 화면 위에 닫을 수 없는 경고 배너**(본인이면 비밀번호 변경 링크, 다른 관리자면 사용자 관리 링크)와 시스템 모니터링 경고 목록의 경고 한 건이 보인다(P31). 판정은 하드닝 점검과 같은 함수(`UserRepository::initialPasswordPendingAdmins`)이고, 운영 장애가 아니므로 `/health`는 바꾸지 않는다.

- 초기 비밀번호는 만료되지 않는다(임시 비밀번호의 24시간 만료를 적용하지 않음). 변경도 강제하지 않는다.
- 로그인 잠금(5회 실패 15분)과 IP 제한(10분 20회 실패 15분)은 이 계정에도 그대로 적용된다.
- 비밀번호를 잃으면 `php tools/user_cli.php reset-password admin`으로 **무작위 임시 비밀번호**를 받는다(`admin`으로 되돌리지 않는다).
- 고정값을 쓰고 싶지 않으면 `--password-stdin`으로 정책을 만족하는 비밀번호를 표준 입력으로 준다.

## 1. PHP (php.ini)

| 항목 | 권장 | 자동 점검 |
| --- | --- | --- |
| `display_errors` | `Off`. 오류 내용이 화면에 나오지 않게 한다 | WARN |
| `expose_php` | `Off`. `X-Powered-By: PHP/…` 헤더를 내보내지 않는다 | WARN |
| `log_errors` | `On`. 오류는 로그에 남긴다 | WARN |
| `extension=intl` | 켠다(경로·코드 NFC 정규화, 명세 19.10). `php tools/check_env.php`로 확인 | check_env |

XAMPP(Windows)는 CLI와 Apache가 같은 `C:\xampp\php\php.ini`를 쓴다. 바꾼 뒤에는 Apache를 다시 시작한다.

## 2. Apache

| 항목 | 권장 | 자동 점검 |
| --- | --- | --- |
| DocumentRoot | PJGuardian의 `public/`만 노출(`docs/xampp_vhost.md`). `data/`, `config/`, `bin/`, `src/`가 웹에서 열리지 않아야 한다 | WARN(HTTP로 확인) |
| 보안 헤더 | CSP(`script-src 'self'`, `unsafe-inline` 없음), X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy(P31), HSTS. 앱이 모든 응답에 붙인다 — **HSTS는 앱 한 곳**(Apache vhost에 `Header always set`으로 또 넣지 않음, 두 번 나감). 화면 HTML은 `private, no-cache`, API·오류는 `no-store`(P31 `SecurityHeadersTest`) | WARN(HTTP로 확인) |
| `ServerTokens Prod`, `ServerSignature Off` | `Server` 헤더에서 버전을 숨긴다(`httpd.conf` 또는 `httpd-default.conf`) | WARN |
| HTTPS 강제 | HTTP는 HTTPS로 리다이렉트(`docs/xampp_vhost.md`). 앱도 이중 검사(`security.require_https = true`) | WARN(설정) |
| 불필요한 모듈 | `mod_status`, `mod_info`, `mod_autoindex`, `mod_userdir`, `mod_dav`·`mod_dav_fs`·`mod_dav_lock`(WebDAV), `mod_cgi`(쓰지 않으면)를 `httpd.conf`에서 주석 처리 | INFO |
| `/server-status`, `/server-info` | 모듈을 끄거나 설정을 지운다 | WARN(HTTP로 확인) |

## 3. XAMPP 샘플·관리 페이지와 기본 계정

| 항목 | 권장 | 자동 점검 |
| --- | --- | --- |
| `htdocs/dashboard`, `htdocs/xampp`, `htdocs/webalizer` | 지운다 | WARN(폴더 존재) |
| `phpMyAdmin`, `webdav` | PJGuardian은 MySQL을 쓰지 않으므로 지우거나 `conf/extra/httpd-xampp.conf`의 Alias를 지운다 | WARN(폴더 존재, HTTP로 확인) |
| MySQL/MariaDB | 쓰지 않으면 서비스를 끈다. 쓴다면 root 비밀번호를 설정하고 익명 계정을 지운다 | INFO |
| FileZilla·Mercury·Tomcat | 쓰지 않으면 설치하지 않거나 끈다 | — |

## 4. PJGuardian 설정

| 항목 | 권장 | 자동 점검 |
| --- | --- | --- |
| `app.env` | `prod`(dev는 개발 전용 화면 `/dev/styleguide`를 연다) | WARN |
| `security.require_https` | `true`, `app.base_url`은 `https://` | WARN |
| `security.hsts_max_age` | 180일 이상(기본 1년) | WARN |
| `auth.bcrypt_cost` | 10 이상(기본 11). 4~9는 테스트 전용 | WARN |
| 관리자 초기 비밀번호 | 최초 관리자의 고정 초기 비밀번호를 바꿨는지(0절). 비밀번호 값이 아니라 `users.password_kind = 'initial'`로 판정 | WARN |
| DB·로그·업로드 위치 | 모두 `public/` 밖(기본값 `data/…`) | WARN |
| `public/`의 불필요한 파일 | `phpinfo.php`, `*.sqlite`, `*.ini`, `*.log`, `*.zip`, `*.bak` 등이 없어야 한다 | WARN |
| 점검 탭 보안 줄(P31) | 시스템 관리 > 점검 > 서버 환경의 보안 줄이 아래 항목을 **알리기만 하고 막지 않는다**(`EnvironmentCheck::security`): config/ 쓰기(정보 — 허용된 방침), config.ini·secrets.ini 쓰기 가능(경고), secrets.ini 웹 루트 밖·권한(Linux o+r 경고, Windows 확인 불가), public/ 안 금지 이름(config.ini·secrets.ini·settings.local.ini·.git·.env·vendor·composer.*·data·backups·bin·src, *.pjgbak·*.sqlite·*.zip·*.bak·*.sql — 문제), display_errors·expose_php(경고), 세션 저장(DB, PHP 세션 파일 안 씀) | 화면 |
| 시크릿 | `config/secrets.ini`(또는 환경 변수)는 웹 서버 계정만 읽을 수 있게 권한을 좁힌다. `config/` 폴더는 설정 관리 때문에 웹 계정이 쓸 수 있으므로(X14 방침, `docs/settings.md` 1절) 파일 단위로 `config.ini`·`secrets.ini`를 읽기 전용으로 두는 편이 더 안전하다(운영 환경에서 확인할 것) | — |

## 4a. 프록시 뒤 운영 — 신뢰 프록시 (P32B, 명세 19.10 P10)

- `[security] trusted_proxies`(설정 관리 > 모니터링): 앞단 역방향 프록시·부하 분산기 주소(IP·CIDR, IPv6). 비우면(기본) 접속 주소(REMOTE_ADDR)만 쓰고 전달 헤더를 믿지 않는다 — P32B 전과 같은 동작.
- 직접 접속 주소가 목록에 있을 때만 `X-Forwarded-For`(오른쪽부터 프록시를 건너뛴 첫 주소 — 왼쪽은 클라이언트가 넣을 수 있음)와 `X-Forwarded-Proto`를 믿는다. 판정은 `Http\ForwardedHeaders::resolve` 한 곳, `App::handle` 맨 앞에서 한 번 — 로그인 시도 제한·감사 로그 IP·`/health` 허용 IP·HTTPS 강제·HSTS·쿠키 Secure가 모두 그 결과만 본다.
- 목록에 프록시가 아닌 주소(예: 사용자 대역)를 넣으면 주소·https를 위조할 수 있다. 프록시 주소만 넣는다. 점검 탭 보안 줄 "신뢰 프록시"가 이 요청 기준으로 상태를 보여 준다(헤더가 왔는데 목록이 비면 "확인 필요").
- 시험: `tests/Integration/TrustedProxiesTest.php`(목록 안/밖·IPv6·위조·잘못된 값·기본값).

## 5. 운영

- PHP, Apache, Node.js, Python 보안 패치를 분기 1회 점검한다(명세 19.5).
- 관리자 계정이 잠기거나 비밀번호를 잃으면 서버 CLI로 복구한다(감사 로그에 남는다).

  ```
  php tools/user_cli.php status <로그인 ID>
  php tools/user_cli.php unlock <로그인 ID>
  php tools/user_cli.php reset-password <로그인 ID>   # 임시 비밀번호(24시간) 발급
  ```

- 감사 로그 변조 여부: `php tools/audit_verify.php`(정상 0, 변조 탐지 2). 출력의 마지막 해시를 DB 밖(백업 기록 등)에 남겨 두면 체인 전체를 다시 계산한 변조도 찾을 수 있다(P30).
- 감사 로그 DB 밖 기록(P31): 워커가 1시간마다·보존 정리 직후 `logs/audit-anchor.jsonl`에 최신 해시를 남기고, 감사 로그 화면의 무결성 검사·시스템 모니터링(`audit_anchor_mismatch` 오류)이 DB와 대조한다. 이 파일을 서버 밖으로 정기 복사해 두면 서버 전체를 가진 사람의 위조까지 잡을 수 있다(`docs/audit_actions.md` 보존과 체인).

## 6. P31 보안 점검 결과와 보강 (2026-10-14, Windows)

자동 시험으로 고정한 통제(시험 이름)와 이번에 보강한 것.

| 영역 | 통제 | 시험 |
| --- | --- | --- |
| 권한 | 라우트 256개 × 역할 5(비로그인·임시 비밀번호·일반·그룹 관리자·admin)를 손으로 적은 표와 대조, 새 라우트가 표에 없으면 실패. 보이지 않는 프로젝트·자식 자원은 404 | `RouteAuthMatrixTest` |
| CSRF | 상태 변경 라우트 전부 토큰 없으면 403. 예외는 SAML ACS 하나(표와 일치) | `RouteAuthMatrixTest::testStateChangingRoutesRequireCsrf` |
| 세션 | HttpOnly·Secure·SameSite=Lax, 로그인 재발급, 유휴 만료, 비활성화·역할 변경·임시 비밀번호·복원 무효화, 본인 비밀번호 변경 시 다른 세션 끊김, 관리자 비밀번호 지정 시 대상 세션 끊김, 로그아웃 세션 ID 재사용 불가 | `AuthHttpTest`·`SessionServiceTest`·`UserServiceTest`·`SessionLifecycleTest` |
| 보안 헤더 | 모든 응답 유형(화면·API·오류·내려받기·정적·/health·리다이렉트)에 CSP·X-Frame-Options·nosniff·Referrer-Policy·**Permissions-Policy(P31 추가)**, 화면 `private, no-cache`(P31: 공유 캐시 금지), API `no-store`, HSTS는 앱 한 곳 | `SecurityHeadersTest` |
| 경로 | 정적 파일·가이드 그림·백업 이름·토큰·업로드·도구 패키지·규칙·설정 키에 순회 시도 → 4xx, 밖의 파일 그대로. 내려받기 파일 이름 정리 | `PathTraversalTest`·`SafeUnzipTest` |
| SSRF | 숫자형 IP·IPv4 대응 IPv6·**NAT64(64:ff9b::/96)·6to4(2002::/16)(P31 추가)**·DNS 답 전부 판정·주소 고정·리다이렉트 | `SsrfRulesTest`·`RepoSecurityTest`·`OidcSecurityTest` |
| XSS·CSV | 공용 문자열 모음(`tests/Support/xss_corpus.json`)을 서버 화면 21개·API·CSV·마크다운에, HTML은 DOM으로 해석해 코드로 살아났는지 확인. 앱 JS에 innerHTML·eval 등 없음(정적 검사) | `XssCorpusTest`·`tests/js/xss_corpus.test.mjs` |
| 비밀 노출 | 표식 비밀값을 로그인·저장소 토큰·LDAP·OIDC·SMTP·비밀번호 변경으로 넣고 응답·로그·감사·DB 평문·화면·CSV·/health를 훑음 | `SecretLeakScanTest` |
| 남용 방지 | 로그인 잠금·IP 제한, **본인 비밀번호 변경의 현재 비밀번호 대입(P31 추가 — 로그인과 같은 잠금 규칙, 잠기면 세션 모두 끊김)**, 시험 발송·연결 시험 분당 제한, 내보내기 동시 자리, 업로드·도구 패키지 동시 수, /health IP당 분당 | `SessionLifecycleTest` 외 |
| 로그 | **없는 계정으로 로그인 실패 시 입력한 로그인 ID 원문 대신 `첫 글자***(길이, #해시8)`(P31 — 비밀번호를 ID 칸에 잘못 넣는 경우)**, 실제 계정이면 그 계정 ID | `SecretLeakScanTest` |
