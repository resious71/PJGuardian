---
key: admin-mail
title: 관리자 이메일 알림
order: 21
summary: 워커 정지·디스크·백업 같은 오류·경고를 관리자에게 메일로 보내기 — SMTP 설정, TLS 선택, 시험 메일, 같은 사건 재발송 규칙, 복구 메일, 한계
keywords: 이메일, 메일, 알림 메일, SMTP, STARTTLS, SMTPS, TLS, 인증서, 시험 메일, 관리자 이메일, 장애 알림, 복구 메일, 재발송, 중계 서버
related: monitoring, settings, worker, security, troubleshooting
audience: admin
---
## 무엇을 하나요

[시스템 점검과 모니터링](guide:monitoring)의 오류·경고가 생기거나 풀리면 관리자에게 메일을 보냅니다. **기본은 꺼짐**이며, 관리자만 설정합니다. 일반 사용자에게 보내는 메일 기능은 없습니다.

- 보내는 곳은 **워커 한 곳**입니다. 워커가 {{limit:monitoring.cache}}마다 상태를 판정할 때 같은 판정으로 보낼지 정합니다. 웹 화면은 메일을 보내지 않습니다(시험 메일만 예외).
- 한 번 판정에서 보낼 것이 여러 건이면 **메일 한 통**에 묶습니다.
- 메일에는 경고 이름·수준·처음 감지 시각과 모니터링 화면 주소만 들어갑니다. 서버 파일 경로, 비밀번호, 사용자가 입력한 내용은 넣지 않습니다.

## 설정하기

**시스템 관리 > {{ui:config_settings.title}} > {{ui:config_settings.group.admin_mail}}** 탭에서 설정합니다. 탭 맨 위에 지금 저장된 설정 기준의 발송 상태(꺼짐·설정 필요·켜짐)가 보입니다.

- **{{ui:config_settings.key.alert_mail.enabled.label}}**: 켜야 보냅니다. 켜도 아래 필수 항목이 비어 있으면 "설정 필요"로 보내지 않습니다.
- **{{ui:config_settings.key.alert_mail.smtp_host.label}}·{{ui:config_settings.key.alert_mail.smtp_port.label}}**: 메일 서버 이름(또는 IP)과 포트. `smtp://` 같은 스킴이나 경로는 넣지 않습니다. 서버마다 다른 값이라 "이 서버만" 배지가 붙습니다.
- **{{ui:config_settings.key.alert_mail.smtp_tls.label}}**: 아래 "TLS 고르기"를 보세요.
- **{{ui:config_settings.key.alert_mail.smtp_auth.label}}·{{ui:config_settings.key.alert_mail.smtp_user.label}}**: 로그인 인증이면 사용자 이름과 비밀번호가 필요합니다. 사내 중계 서버가 IP로 허용하면 "인증 없음".
- **{{ui:alert_mail.secret.label}}**: 사용자 이름 아래 입력란에서 **{{ui:alert_mail.secret.save}}**로 따로 저장합니다(설정 저장과 별개). 암호화해 DB에만 두고 다시 보여 주지 않습니다. 화면에는 저장됨·없음 상태만 보이고, **{{ui:alert_mail.secret.delete}}**로 지웁니다.
- **{{ui:config_settings.key.alert_mail.from_address.label}}·{{ui:config_settings.key.alert_mail.from_name.label}}**: 메일 서버가 허용하는 보낸 사람 주소여야 합니다.
- **{{ui:config_settings.key.alert_mail.recipients.label}}**: 쉼표로 구분해 최대 20개. 비우면 이메일이 있는 **활성 관리자 계정 전체**로 보냅니다.
- **{{ui:config_settings.key.alert_mail.language.label}}**: 메일 제목·본문의 언어(한국어·영어). 화면 언어와 따로 정합니다.
- **{{ui:config_settings.key.alert_mail.events.label}}**: 보낼 사건 종류. 기본은 전부입니다.
- **{{ui:config_settings.key.alert_mail.resend_interval.label}}**: 기본 {{limit:alert_mail.resend_interval}}(10분~24시간).
- **{{ui:config_settings.key.alert_mail.recovery.label}}**: 사건이 풀리면 복구 메일을 한 통 보낼지.

저장하면 워커가 다음 판정부터 새 설정을 씁니다(재시작 필요 없음). 비밀번호와 주소 형식 오류는 저장할 때 막힙니다.

## TLS 고르기

- **STARTTLS(권장)**: 대부분의 메일 서버·클라우드 메일 서비스 — 보통 포트 587
- **SMTPS**: 처음부터 TLS로 접속하는 서버 — 보통 포트 465
- **평문(TLS 없음)**: 같은 서버나 사내망의 중계 서버만 — 보통 포트 25

- **인증서 검증은 늘 켜져 있고 끌 수 없습니다.** 서버 이름이 인증서와 맞아야 합니다(IP로 접속하면 대개 실패하므로 인증서에 적힌 이름을 쓰세요).
- 사내 CA나 자체 서명 인증서를 쓰는 메일 서버라면 그 CA를 **서버의 신뢰 저장소**에 넣으세요. Linux는 OS 신뢰 저장소(예: `update-ca-certificates`), Windows(XAMPP)는 php.ini의 openssl.cafile 항목이 가리키는 CA 묶음 파일에 CA를 덧붙인 뒤 워커와 웹 서버를 다시 시작합니다.
- 평문을 고르면 화면에 경고가 보입니다. 비밀번호와 메일 내용이 암호화되지 않고 오가므로 외부 메일 서버에는 쓰지 마세요.

## 시험 메일

탭 아래 **{{ui:alert_mail.test.title}}**에서 **{{ui:alert_mail.test.run}}**을 누르면 **저장된 설정**으로 한 통을 바로 보냅니다. 받는 사람을 비우면 내 계정 이메일로 갑니다.

- 저장하지 않은 변경이 있으면 단추가 꺼지고 "먼저 저장" 안내가 보입니다. 필수 설정이 비어 있어도 무엇이 빠졌는지 보여 주고 꺼집니다.
- 사용자당 분당 3회, 전체 시간당 20회까지입니다.
- 결과는 "보냈습니다" 또는 실패 사유로 보입니다. 시험 메일은 감사 로그 `mail.test`에 받는 사람을 가려(첫 글자와 도메인만) 남깁니다.

## 보내는 규칙

- **`worker_down`**: 워커 정지(오류)
- **`queue_stalled`**: 큐 정체(경고)
- **`disk`**: 디스크 경고·차단
- **`backup`**: 백업 실패, 백업 오래됨
- **`tool`**: 분석 도구 검증 실패(도구마다 따로)
- **`repo`**: 주기 분석 연속 실패(프로젝트마다 따로)
- **`cert`**: HTTPS 인증서 만료 임박·만료

- **새로 생기면** 다음 판정에서 바로 보냅니다.
- **이어지면** 같은 사건은 재발송 간격({{limit:alert_mail.resend_interval}})에 한 번만 "지속 중"으로 다시 보냅니다. 그 사이 다시 감지된 횟수를 함께 적습니다.
- **수준이 오르면**(디스크 경고 → 차단, 인증서 만료 임박 → 만료) 간격과 상관없이 바로 보냅니다.
- **풀리면** 복구 메일을 한 통 보냅니다(복구 알림을 끄면 보내지 않음). 더 높은 수준으로 올라가며 풀린 낮은 수준은 복구로 보내지 않습니다.
- 워커가 여러 개여도 같은 사건을 두 번 보내지 않습니다.
- 보내기에 실패해도 워커와 분석은 계속됩니다. 실패는 앱 로그에 사유(연결·TLS·인증·수신 거부·시간 초과)와 함께 남고, 다음 판정에서 사건이 이어지면 간격에 맞춰 다시 시도합니다.
- 풀린 사건 기록은 30일 뒤 정리 작업이 지웁니다.

모니터링 화면의 앱 정보 카드에 "관리자 이메일: 꺼짐·설정 필요·켜짐"이 보이고, 누르면 이 설정 탭으로 갑니다.

## 한계

- **워커가 멈추면 메일도 가지 않습니다.** 판정과 발송을 워커가 하기 때문입니다. 워커 정지를 반드시 알아야 하면 [헬스체크(/health)](guide:monitoring)를 외부 감시 도구에 연결하세요.
- 메일 서버가 받은 뒤의 배달(스팸 분류, 수신 거부)은 PJGuardian이 알 수 없습니다. 시험 메일이 오지 않으면 스팸함과 메일 서버 기록을 확인하세요.
- DB 마이그레이션 031이 적용되지 않았으면 "설정 필요(DB 마이그레이션 031)"로 보내지 않습니다. `php tools/migrate.php`로 적용하세요.

## 문제 해결

- **연결 실패**: 서버 이름·포트, 방화벽(서버에서 메일 서버로 나가는 연결), 사내 프록시
- **TLS 실패**: TLS 방식과 포트 짝(587=STARTTLS, 465=SMTPS), 인증서의 이름, 사내 CA를 신뢰 저장소에 넣었는지
- **인증 실패**: 사용자 이름·비밀번호, 메일 서비스의 앱 비밀번호 요구 여부
- **수신 거부**: 보낸 사람 주소를 메일 서버가 허용하는지, 받는 사람 주소
- **시간 초과**: 메일 서버 응답, 포트가 막혀 응답 없이 끊기는지
- **"설정 필요"**: 탭 위 발송 상태에 빠진 항목이 나옵니다
- **메일이 오지 않음**: 워커가 실행 중인지([워커](guide:worker)), 사건 종류 목록, 재발송 간격 안인지
- **비밀번호 "읽을 수 없음"**: 암호화 키가 바뀌었습니다. 비밀번호를 다시 저장하세요

## 관련 절

- [시스템 점검과 모니터링](guide:monitoring)
- [설정 관리](guide:settings)
- [워커](guide:worker)
- [보안 운영](guide:security)
- [문제 해결](guide:troubleshooting)
