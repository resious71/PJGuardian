---
key: repository
title: 소스 저장소 연동
order: 15
summary: Git(GitHub)·Perforce 연결, 자격 증명 암호화, 허용 호스트와 연결 테스트, 지금 가져와 분석, 실패 사유와 심볼릭 링크 규칙
keywords: 저장소, Git, GitHub, GitHub Enterprise, Perforce, 토큰, 자격 증명, 허용 호스트, 연결 테스트, 인증서, 지문, 가져오기, 심볼릭 링크
related: schedule, settings, projects-admin, security
audience: admin
---

## 무엇을 하는 기능인가

프로젝트에 Git 저장소(GitHub, GitHub Enterprise, 사내 HTTP Git) 또는 Perforce를 연결하면, 업로드 없이 서버가 최신 소스를 직접 가져와 분석합니다. 업로드 분석은 연결한 뒤에도 그대로 쓸 수 있습니다.

- 가져오기는 워커가 분석 작업의 첫 단계로 합니다. 대기열·동시 실행 규칙과 안전 해제 규칙(제외 대상, 크기·파일 수 한도, 경로·이름 검사)은 업로드 분석과 같습니다.
- 서버는 허용 호스트에 등록한 호스트에만 접속합니다. 저장소 주소는 `https://`(허용한 경우 `http://`)만 쓸 수 있고 SSH·file 주소는 쓸 수 없습니다.
- git·p4 실행 파일은 서버의 `bin/`에 있는 검증된 버전만 씁니다. {{ui:admin_tools.title}} 탭에서 읽기 전용으로 확인합니다.

연결 설정과 {{ui:repo.fetch_now}}은 관리자만 합니다. 일반 사용자는 프로젝트 화면의 {{ui:repo.title}} 상자에서 출처, 연결 상태, 마지막 가져오기, 마지막 분석한 커밋만 봅니다(주소·사용자명·자격 증명은 보이지 않습니다).

## 준비: 암호화 키와 허용 호스트

### 암호화 키

토큰·비밀번호·티켓은 서버의 암호화 키로 암호화해 DB에 저장합니다. 키는 DB 밖(서버의 시크릿 파일 또는 환경 변수)에 있습니다.

키가 없으면 자격 증명을 저장할 수 없고 연결 화면에 안내가 나옵니다. 서버에서 키를 만듭니다(바꿀 때는 `--rotate`).

```
php tools/make_secret_key.php
```

- 키 값은 화면·로그에 나오지 않고 파일에만 쓰입니다. 만들거나 바꾼 뒤 웹 서버와 워커를 다시 시작합니다.
- `--rotate`는 이전 키도 보관해 바로 동작하고, 각 연결을 다시 저장하면 새 키로 다시 암호화됩니다.
- 키를 잃으면 저장된 자격 증명은 복구할 수 없습니다. 연결 화면에 "읽을 수 없음"이 보이면 새 키를 만들고 자격 증명을 다시 입력하세요. 키 파일은 DB 백업과 따로 보관하세요.

### 허용 호스트

![설정 관리 > 저장소 연결 탭 맨 위의 허용 호스트 구역. 규칙 안내 세 줄, 호스트·포트·HTTP 허용·GitHub 호환·메모 입력과 추가 단추, 전체 테스트 단추, 그리고 호스트 표(포트·HTTP 허용·GitHub 호환·메모·마지막 테스트·인증서 만료·동작 열, 행마다 연결 테스트·삭제 단추)가 있습니다(예시 호스트).](guide-img:admin-repo-hosts)

{{ui:icon.admin.tools}} > {{ui:config_settings.title}} > {{ui:config_settings.group.repository}} 탭의 맨 위 {{ui:repo_hosts.title}} 구역에서 등록합니다.

1. 호스트(이름 또는 IP)와 포트를 입력합니다. 포트를 0으로 두거나 비우면 모든 포트를 허용합니다.
2. 평문 HTTP 주소를 써야 하면 {{ui:repo_hosts.field.allow_http}}을 켭니다. 토큰이 평문으로 전송되므로 가능하면 HTTPS를 쓰세요.
3. GitHub Enterprise처럼 GitHub 형식의 커밋 주소를 쓰는 서버면 {{ui:repo_hosts.field.github_links}}를 켭니다. 분석 이력에 커밋 링크가 생깁니다.
4. {{ui:repo_hosts.add}}를 누릅니다. 표의 값은 바로 저장되며, 화면 아래의 설정 항목 저장 단추와는 따로 동작합니다.

등록한 이름이 링크 로컬·메타데이터 주소로 풀리면 접속하지 않습니다. 허용 목록은 접속할 때마다 다시 확인하므로, 호스트를 지우면 그 호스트를 쓰는 프로젝트의 가져오기와 주기 분석이 멈춥니다. 지우기 전에 그 호스트를 쓰는 프로젝트를 확인하세요.

## 허용 호스트 연결 테스트

저장소나 자격 증명 없이 호스트 자체에 닿는지 확인합니다.

행 옆의 {{ui:host_test.test}}는 그 호스트 하나를, 표 위의 {{ui:host_test.test_all}}은 등록된 호스트를 한 번에 최대 {{limit:repository.host_test_max}}개까지 차례로 확인합니다(나머지는 행별로). 테스트는 이름 풀이 → TCP 접속 → TLS(인증서) → HTTP 상태 줄 순서로 진행하고 단계별 결과를 보여 줍니다. 결과는 표의 {{ui:host_test.col.last}}·{{ui:host_test.col.cert}} 열에 남습니다.

- 인증서: 만료일, 남은 일수, 발급자, 이름 일치, 신뢰 여부를 봅니다. 만료가 {{limit:notification.cert_expiry_warn}} 안으로 다가오면 경고로 표시합니다.
- HTTP: 401·403은 "응답함(인증 필요)"으로 정상입니다. 루트 경로가 404처럼 응답해도 호스트에는 닿은 것이라 정상으로 봅니다. 5xx와 407(프록시 인증 필요)은 실패입니다.
- Perforce 지문: HTTP가 아닌 TLS 포트면 서버 지문을 보여 줍니다. 자동으로 신뢰하지 않습니다. 서버 관리자에게 확인한 값만 연결 설정에 입력하세요.
- 사내 CA 인증서를 쓰는 서버가 "신뢰할 수 없는 인증서"로 나오면 서버의 `repository.ca_cert_file` 설정에 사내 CA 인증서 파일을 지정합니다(인증서 검증을 끄는 기능은 없습니다).
- 프록시를 거쳐야만 닿는 호스트는 "접속 실패"로 나옵니다. 저장소용 프록시 설정은 없습니다.

이 테스트 결과는 프로젝트의 연결 상태와 별개입니다. 연결 테스트 횟수는 사용자당 1분에 {{limit:repository.test_per_minute}}번이며, 프로젝트의 연결 테스트와 합산합니다(전체 테스트는 1번으로 셉니다).

## 프로젝트에 저장소 연결하기

1. {{ui:icon.admin.projects}}에서 프로젝트를 열고 {{ui:projects.edit_button}}을 누릅니다.
2. {{ui:repo.title}}에서 {{ui:repo.field.kind}}을 고릅니다({{ui:repo.kind.none}}, Git, Perforce).
3. Git이면 저장소 주소, 브랜치(비우면 기본 브랜치), 하위 경로(선택), 사용자명(선택), 읽기 전용 토큰을 입력합니다. 주소에 사용자명·토큰을 넣지 마세요.
4. Perforce면 서버 주소(SSL은 `ssl:호스트:포트`), depot 경로 하나, 사용자명, 비밀번호 또는 티켓을 입력합니다. SSL 서버면 {{ui:repo.field.fingerprint}}에 확인한 지문을 넣습니다.
5. 필요하면 {{ui:repo.field.fetch_timeout}}을 정합니다. 비우면 서버 기본값 {{limit:repository.fetch_timeout}}입니다.
6. {{ui:repo.test}}를 눌러 확인합니다. 저장 전 입력값으로도 테스트할 수 있고, Git은 최신 커밋, Perforce는 최신 changelist가 나오면 성공입니다. 테스트는 {{limit:repository.test_timeout}} 안에 끝나야 합니다.
7. {{ui:repo.save}}을 누릅니다. 호스트가 허용 목록에 없으면 저장되지 않고 등록 위치를 안내합니다.

- 읽기 권한만 있는 전용 계정·토큰을 쓰세요. 저장한 자격 증명은 다시 보이지 않고 "설정됨(종류)"으로만 표시되며, 비워 두고 저장하면 그대로 둡니다.
- {{ui:repo.disconnect}}는 연결과 저장된 자격 증명을 지우고 업로드 전용으로 되돌립니다. 주기 분석도 함께 꺼집니다. 이미 끝난 분석의 출처 기록은 남습니다.

## 지금 가져와 분석

프로젝트 화면의 {{ui:repo.title}} 상자에서 {{icon:action.fetch_now}} {{ui:repo.fetch_now}}을 누르면 분석이 대기열에 들어갑니다. 진행은 프로젝트의 분석 탭에서 봅니다.

- 단추가 꺼져 있으면 이유가 함께 나옵니다: 연결 없음, 호스트가 허용 목록에 없음, 자격 증명을 읽을 수 없음, 대기·실행 중인 분석이 있음.
- 직전 성공 분석과 같은 커밋·changelist이고 분석 조건도 같으면 "변경 없음"으로 끝나고, 화면이 그래도 분석할지 묻습니다. {{ui:repo.force_confirm}}을 고르면 같은 리비전을 다시 분석합니다. 자세한 기준은 [주기 분석](guide:schedule)을 보세요.
- 분석 이력에는 출처(브랜치와 짧은 커밋 해시, 또는 depot 경로와 changelist)가 남습니다.

## 실패했을 때

가져오기 실패는 분석 실패 사유로 기록되고 분석 상세에 보입니다. 기존 결함은 그대로 둡니다.

- `repo_auth_failed`: 토큰·비밀번호를 다시 입력하고 계정의 읽기 권한을 확인한 뒤 연결 테스트를 합니다.
- `repo_unreachable`, `repo_timeout`: 주소·포트, 서버 상태, 방화벽·프록시를 확인합니다. 허용 호스트의 연결 테스트로 어느 단계에서 막히는지 봅니다. 큰 저장소면 가져오기 시간 제한을 늘립니다.
- `repo_not_found`: 주소, 브랜치, 하위 경로, depot 경로의 오타와 읽기 권한을 확인합니다.
- `repo_host_not_allowed`: 허용 호스트에 등록했는지, HTTP 주소라면 HTTP 허용을 켰는지 확인합니다.
- `repo_tls_untrusted`: 사내 CA 설정 또는 Perforce 서버 지문을 확인합니다.
- `repo_secret_unreadable`: 암호화 키가 바뀌었거나 없습니다. 키를 확인하거나 자격 증명을 다시 입력합니다.
- `repo_tool_unavailable`: {{ui:admin_tools.title}} 탭에서 git·p4의 체크섬·스모크 결과를 확인합니다.
- `repo_limit_exceeded`: 가져온 소스가 {{limit:extract.max_extracted_size}} 또는 파일 {{limit:extract.max_files}}개를 넘었습니다. 하위 경로·depot 경로를 좁히거나 제외 패턴을 더합니다. 한도는 **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}** 탭에서 바꿉니다.
- `repo_empty`: 분석 대상 확장자 파일이 없습니다. 브랜치·경로와 프로젝트 언어를 확인합니다.
- `repo_unsafe_content`: 경로 이탈, 대소문자만 다른 같은 이름, Windows에서 쓸 수 없는 이름, 너무 긴 경로, 중첩 zip이 있습니다. 해당 경로를 제외 패턴에 넣거나 저장소에서 정리합니다.
- `repo_fetch_failed`, `repo_not_configured`: 연결 설정을 다시 확인하고, 계속되면 서버 로그를 봅니다.

사용자가 누른 {{ui:repo.fetch_now}}은 실패해도 다시 시도하지 않습니다. 주기 분석의 재시도는 [주기 분석](guide:schedule)에서 설명합니다.

## 심볼릭 링크와 안전 규칙

- 저장소의 심볼릭 링크(Git 링크 항목, Perforce symlink 유형)는 따라가지도 파일로 만들지도 않고 건너뜁니다(대상 내용도 읽지 않음). 분석은 계속하고, 분석 상세에 "저장소의 심볼릭 링크 N개를 건너뛰었습니다(분석 대상 아님)" 안내와 처음 몇 개의 경로가 보입니다. 링크만 있어 분석 대상이 0개면 `repo_empty`로 실패합니다.
- 이 건너뛰기는 저장소에서 가져온 소스에만 적용됩니다. 업로드한 zip 안의 심볼릭 링크는 지금처럼 거부합니다.
- Git의 hook, submodule, LFS 필터는 실행하지 않습니다. 저장소 안의 파일이 서버에서 명령을 실행하지 않습니다.

## 주의할 점

- 개발용 예외 설정 `repository.allow_local_dev`는 운영 서버에서 꺼 둡니다. 켜져 있으면 {{ui:repo_hosts.title}} 구역에 경고가 보입니다.
- 사내 Perforce·GitHub Enterprise에 실제로 연결되는지는 운영 환경에서 확인할 것. 허용 호스트 연결 테스트 → 프로젝트 연결 테스트 → {{ui:repo.fetch_now}} 순서로 점검합니다.
