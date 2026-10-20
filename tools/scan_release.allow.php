<?php

declare(strict_types=1);

/**
 * 공개 전 스캐너 허용 목록(P33 2절). 항목마다:
 *   file   공개용 트리 기준 경로 glob(** · * · ?)
 *   line   줄 번호(정수) 또는 '*'(파일 전체) — line·match 중 하나 이상 필요
 *   match  발견 값에 맞출 정규식(선택, 값 전체를 적지 말고 앞부분·모양만)
 *   kind   종류 하나로 좁힘(선택: email, abs_path, name, private_key, token, private_ip, internal_host, password_line)
 *   reason 이유(필수 — 이유 없는 허용은 스캐너가 거부하고 종료 코드 2)
 * 시험용 가짜 값·문서 속 예시만 넣는다. 실제 개인 정보·비밀은 허용하지 말고 지운다.
 * 시험 폴더라도 name(사용자·프로젝트 이름)은 허용하지 않는다 — 시험 입력에 개발 PC 경로가 남아 있으면 일반 이름으로 바꾼다.
 */
return [
    // 스캐너 자신
    ['file' => 'tools/scan_release.rules.php', 'line' => '*', 'reason' => '스캐너 규칙 파일 — 찾을 이름·경로 목록 자체'],
    ['file' => 'tools/scan_release.allow.php', 'line' => '*', 'reason' => '허용 목록 자체(값 대신 줄 번호·모양만 적는다)'],
    ['file' => 'tools/lib/ReleaseScanner.php', 'line' => '*', 'reason' => '스캐너 코드 — 검사 패턴과 예시'],
    ['file' => 'tests/Unit/ScanReleaseTest.php', 'line' => '*', 'reason' => '스캐너 시험 — 일부러 만든 가짜 값(이어 붙여 만듦)'],

    // 시험 폴더의 가짜 값(종류별)
    ['file' => 'tests/**', 'kind' => 'private_ip', 'line' => '*', 'reason' => '시험용 가짜 사설 IP(신뢰 프록시·허용 IP·SSRF 판정 시험)'],
    ['file' => 'tests/**', 'kind' => 'password_line', 'line' => '*', 'reason' => '시험용 가짜 비밀번호(표식 값)'],
    ['file' => 'tests/**', 'kind' => 'internal_host', 'line' => '*', 'reason' => '시험용 가짜 내부 호스트(ghe.corp·p4.corp 등 — 허용 호스트·SSRF 판정 시험, 실제 연결 없음)'],
    ['file' => 'tests/**', 'kind' => 'email', 'line' => '*', 'reason' => '시험용 형식 검사 주소(a@b.co, x@y.z 등)'],
    ['file' => 'tests/**', 'kind' => 'abs_path', 'line' => '*', 'reason' => '시험용 가짜 경로(경로 정책·가림·Windows 경로 판정 시험)'],
    ['file' => 'tests/**', 'kind' => 'token', 'match' => '/^MDEyMzQ1Njc4OWFi/', 'reason' => '시험 고정 암호화 키(base64("0123456789abcdef…"))'],
    ['file' => 'tests/Integration/SecretLeakScanTest.php', 'kind' => 'token', 'line' => 23, 'reason' => '비밀 노출 시험의 표식 암호화 키(가짜)'],
    ['file' => 'tests/Integration/AuditDescriberTest.php', 'kind' => 'token', 'match' => '/^AKIAABCD/', 'reason' => '감사 설명 가림 시험의 가짜 AWS 키 모양'],
    ['file' => 'tests/Unit/OidcSecurityTest.php', 'kind' => 'token', 'line' => '*', 'reason' => 'RFC 7515/7518 예시 JWK·JWS 시험 벡터(공개 표준 문서 값)'],

    // 점검 스크립트의 가짜 값(격리 설치본 전용)
    ['file' => 'tools/check_audit_ui.mjs', 'kind' => 'password_line', 'line' => 48, 'reason' => '감사 로그 가림 점검의 표식 비밀번호(가짜, 화면에 가려져야 함)'],
    ['file' => 'tools/check_audit_ui.mjs', 'kind' => 'private_ip', 'line' => 53, 'reason' => '감사 로그 점검용 가짜 행위자 IP'],
    ['file' => 'tools/acceptance/scenarios_ops.mjs', 'kind' => 'password_line', 'line' => 64, 'reason' => '인수 시험의 일부러 틀린 토큰(연결 실패 확인)'],
    ['file' => 'tools/make_bad_zips.py', 'kind' => 'abs_path', 'match' => '#^C:/evil#', 'reason' => '비정상 zip 시료: 절대 경로 항목을 거부하는지 시험'],

    // 서드파티 저작권 고지(지우면 안 됨 — 라이선스 조건)
    ['file' => 'NOTICE', 'kind' => 'email', 'match' => '/^mar/', 'reason' => 'CodeMirror 저작권 문구의 저작자 이메일(MIT 고지 유지 의무, THIRD_PARTY_NOTICES와 같은 문구)'],

    // 사용자가 공개하기로 한 연락처(X22) — 이 주소만, 연락처를 적는 문서에서만. 다른 이메일은 허용하지 않는다
    ['file' => 'SECURITY.md', 'kind' => 'email', 'match' => '/^resious@gmail\.com$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처(보안 신고 보조 이메일)'],
    ['file' => 'SECURITY.md', 'kind' => 'name', 'match' => '/^resious$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처 주소의 계정 이름'],
    ['file' => 'CODE_OF_CONDUCT.md', 'kind' => 'email', 'match' => '/^resious@gmail\.com$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처(행동 강령 신고)'],
    ['file' => 'CODE_OF_CONDUCT.md', 'kind' => 'name', 'match' => '/^resious$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처 주소의 계정 이름'],
    ['file' => 'docs/publish_checklist.md', 'kind' => 'email', 'match' => '/^resious@gmail\.com$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처(정한 것 목록)'],
    ['file' => 'docs/publish_checklist.md', 'kind' => 'name', 'match' => '/^resious$/', 'line' => '*', 'reason' => '사용자가 공개하기로 한 연락처 주소의 계정 이름'],

    // 문서·화면 문구의 예시(P33에서 확인 — 실제 사람·서버 아님)
    ['file' => 'docs/auth_oidc.md', 'kind' => 'email', 'match' => '/^kim@/', 'reason' => 'Google 계정의 로그인 ID 형식을 설명하는 예시(gmail 주소 모양이 필요)'],
    ['file' => 'public/assets/js/i18n/*.js', 'kind' => 'private_ip', 'match' => '/^10\.0\./', 'reason' => '신뢰 프록시 입력 예시 — 앞단 프록시는 보통 사설 주소라 사설 IP 예시가 맞다'],
];
