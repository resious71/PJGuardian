<?php

declare(strict_types=1);

/**
 * 공개 전 스캐너 규칙(P33 2절, tools/scan_release.php · tools/lib/ReleaseScanner.php). 사용자가 고치는 파일이다.
 * 정규식은 PHP preg 형식(구분자 포함). 이름은 대소문자를 무시하고 단어 경계로 찾는다.
 * 이 파일 자체는 이름·경로 목록을 담으므로 공개용 트리에서는 허용 목록(scan_release.allow.php)에 파일 전체 허용으로 들어 있다.
 */
return [
    // 사용자 이름·계정·다른 프로젝트 이름(공개 저장소에 나오면 안 되는 것). 개인 이메일의 앞부분도 여기에.
    'names' => [
        'resious',
        'resisa',
        'Ncrumban',
        'Vibe_Coding',
    ],

    // 예약 도메인이 아니어도 공개해도 되는 이메일(서드파티 저작권 고지의 저자 주소, 공개 커밋 서명 등)
    'allowed_emails' => [
        'noreply@anthropic.com',
    ],

    // 드라이브 절대 경로 중 개인 정보가 아닌 일반 설치 위치·문서 예시(통과). 개발 PC 고유 경로(D:\Vibe_Coding, C:\Users\<이름>)는 넣지 않는다.
    'generic_paths' => [
        '#^[A-Za-z]:[\\\\/]xampp([\\\\/]|$)#i',                 // XAMPP 기본 설치 위치(설치 안내)
        '#^[A-Za-z]:[\\\\/]Program(Data| Files)?([\\\\/]|$)#i',     // 공백에서 끊겨 C:/Program 으로도 들어옴
        '#^[A-Za-z]:[\\\\/]Windows([\\\\/]|$)#i',
        '#^[A-Za-z]:[\\\\/]tools([\\\\/]|$)#i',                 // 포터블 도구 예시(C:\tools\nodejs)
        '#^[A-Za-z]:[\\\\/](backups?|pjguardian|PJGuardian|data|srv|x|b|bk|path|transfer)([\\\\/]|$)#',   // 문서·시험의 예시 폴더(transfer: 설치 가이드의 PC 이전 예시, X22)
        '#^[A-Za-z]:[\\\\/]?$#',                                // 드라이브만(D:\)
    ],

    // /home/<이름>, /Users/<이름>에서 개인 이름이 아닌 자리표시자(통과)
    'generic_home_names' => ['user', 'username', 'me', 'pjguardian', 'www-data', 'apache', 'example', 'runner', 'deploy', 'app', '<user>', 'you'],

    // 내부 호스트 이름 패턴(소문자로 비교)
    'internal_host_patterns' => [
        '/\.(local|corp|internal|intranet|lan|home|localdomain)$/',
    ],

    // 내부 호스트 패턴에 걸려도 통과: 앱의 문서화된 개발용 가상 호스트 이름(실제 네트워크가 아님, docs/xampp_vhost.md)
    'allowed_hosts' => [
        'pjguardian.local',
        'localhost.localdomain',   // EHLO 기본 이름(PHPMailer와 같은 규칙)
    ],

    // 경로별로 끄는 종류(이유 필수). 번들 서드파티 코드는 원본과 같은지 SOURCE.txt의 SHA-256으로 따로 확인한다(LicenseNoticeTest).
    'path_rules' => [
        ['glob' => 'public/assets/vendor/**', 'kinds' => ['token', 'email', 'password_line'], 'reason' => '번들 서드파티 JS(압축·원 저작자 주소) — 원본 동일성은 SOURCE.txt 해시로 확인'],
        ['glob' => '**/package-lock.json', 'kinds' => ['token', 'email'], 'reason' => 'npm 잠금 파일의 무결성 값(sha512-…)·패키지 저자 주소(공개 레지스트리 정보)'],
        ['glob' => 'composer.lock', 'kinds' => ['email'], 'reason' => 'Composer 잠금 파일의 패키지 저자 주소(공개 레지스트리 정보)'],
        ['glob' => 'THIRD_PARTY_NOTICES', 'kinds' => ['email'], 'reason' => '서드파티 저작권 고지 원문의 저자 주소(지우면 안 됨)'],
        ['glob' => '**/LICENSE*', 'kinds' => ['email'], 'reason' => '라이선스 원문의 저작권자 주소'],
        ['glob' => '**/SHA256SUMS', 'kinds' => ['token'], 'reason' => '체크섬 목록'],
    ],
];
