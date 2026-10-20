<?php

declare(strict_types=1);

/**
 * 공개용 트리 내보내기 규칙(P33, tools/export_open_source.php가 읽는다). 사용자가 고칠 수 있는 설정 파일이다.
 *
 * - exclude: 저장소 경로 접두사(끝이 /면 폴더) 또는 정확한 경로. 공개하지 않는 개발 기록·비밀·데이터.
 * - exclude_regex: 경로 정규식(비밀·DB·키 파일 이름 — 실수로 추적돼도 빠지게).
 * - data_skeleton: data/ 아래는 .gitkeep(빈 폴더 뼈대)만.
 * - bin_current_only: 이 도구들은 현재 사용(활성·고정) 버전 폴더 하나만. bin_single_version: 버전 폴더가 하나여야 하는 도구(둘 이상이면 멈춤).
 * - rewrite: 공개용 사본에서만 바꿀 글(개발 저장소 파일은 그대로). 파일이 있는데 바꿀 글이 없으면 멈춘다(원본이 바뀐 것 — 규칙을 고친다).
 *   from/to는 글 그대로 바꾸기, regex/to는 정규식 바꾸기(/u 필수). 이미 공개용인 원본(공개 저장소에서 다시 내보낼 때)은 건너뛴다 —
 *   from/to는 결과(to)가 이미 있으면, 그 밖은 already_present(이 글이 있으면)·already_absent(이 글이 없으면)·already_absent_regex(이 정규식이 안 맞으면)로 판단한다.
 */

return [
    'exclude' => [
        'prompts/',                     // 개발 과정 프롬프트
        'docs/results/',                // 단계별 보고서·캡처(개발 PC 경로·계정이 보일 수 있음)
        'docs/presentations/',          // 소개·교육 PPT(사용자 결정, git 무시)
        'PJGuardian_requirements.md',   // 요구사항 명세 원본
        'tools/proto/',                 // 프로토타입(P32에서 삭제됨 — 다시 생겨도 빠지게)
        'tests/proto/',
        'dist/',
        'backups/',
        'vendor/',                      // 설치 때 composer install(composer.json·composer.lock은 포함)
        'config/config.ini',
        'config/secrets.ini',
    ],
    'exclude_regex' => [
        '#^config/settings\.local\.ini#u',
        '#^config/secrets\.(?!example$)#u',
        '#(^|/)\.env(\.|$)#u',
        '#\.pjgkey$#u',
        '#\.(sqlite|sqlite3|db)(-wal|-shm|-journal)?$#u',
        '#\.(pem|key|p12|pfx)$#u',
    ],
    // --with-untracked(커밋 전 확인)에서도 넣지 않는 새 파일: 사용자가 일부러 커밋하지 않는 문서. 커밋하면 git ls-files로 들어간다
    // (X22: docs/install_guide.md는 공개하기로 정해 이 목록에서 뺐다 — 커밋 전 확인에서도 공개용 트리에 들어간다)
    'untracked_exclude' => [],
    'data_skeleton' => true,
    'bin_current_only' => ['ruff', 'eslint'],
    'bin_single_version' => ['git', 'p4'],
    'rewrite' => [
        'CLAUDE.md' => [
            [
                'from' => "개발 PC(Windows)에서 PHP는 `C:\\xampp\\php\\php.exe`, Node.js는 `C:\\tools\\nodejs\\node.exe`(포터블)다.",
                'to' => "Windows 개발 PC에서는 XAMPP의 PHP(`<XAMPP 폴더>\\php\\php.exe`)와 Node.js(`node`, 포터블이면 그 폴더의 `node.exe`)를 쓴다.",
            ],
            [
                'regex' => '/\A(# CLAUDE\.md\n)/u',
                'already_present' => '> 공개 저장소 안내:',
                'to' => "\$1\n> 공개 저장소 안내: 이 파일은 AI 보조 개발(Claude Code) 규칙이다. 개발 기록인 `prompts/`(단계별 프롬프트), 단계 보고(docs/results), 요구사항 명세 `PJGuardian_requirements.md`는 공개 저장소에 없다. 아래에서 그것들을 가리키는 문장은 개발 저장소 기준이다. 규칙 자체(코딩·보안·시험)는 그대로 적용된다.\n",
            ],
        ],
        '.gitignore' => [
            // 공개용 저장소에는 개발 기록이 없으므로 "공개할 때만" 주석 블록은 필요 없다
            [
                'regex' => '/\n# =+\n# 오픈소스로 공개할 때만 사용.*?# \/samples\/private\/[^\n]*\n/su',
                'to' => "\n",
                'already_absent' => '오픈소스로 공개할 때만 사용',
            ],
            // X22: 공개 제외 폴더를 무시하는 활성 줄(사용자가 더한 /docs/presentations/ 등)과 바로 위 주석 한 줄 — 공개 저장소에 없는 폴더 이름을 알릴 필요가 없다
            [
                'regex' => '/(?:^#[^\n]*\n)?(?:^\/(?:prompts|docs\/results|tools\/proto|docs\/presentations)\/[^\n]*\n|^\/PJGuardian_requirements\.md[^\n]*\n)+/mu',
                'to' => '',
                'already_absent_regex' => '/^\/(?:prompts|docs\/results|tools\/proto|docs\/presentations)\/|^\/PJGuardian_requirements\.md/mu',
            ],
        ],
        'docs/README.md' => [
            // 상세 설치 가이드(docs/install_guide.md)는 개발 저장소에만 있다(커밋되지 않은 사용자 문서) — 공개용은 빠른 설치로
            [
                'from' => "| [install_guide.md](install_guide.md) | 설치 가이드(새 설치·옛 PC에서 옮기기·점검 목록) |\n",
                'to' => '',
                'when_missing' => 'docs/install_guide.md', // 그 파일이 공개용 트리에 있으면(커밋하면) 이 바꾸기는 건너뛴다
                'already_absent' => '(install_guide.md)',
            ],
        ],
    ],
    // 여러 파일에 걸친 바꾸기(맞는 곳이 없어도 멈추지 않음, 바꾼 파일 수를 출력). path_regex에 맞는 공개용 사본에만 적용
    'rewrite_all' => [
        [
            // 커밋된 문서 여러 곳이 docs/install_guide.md를 가리킨다 — 그 파일을 커밋하기 전까지는 공개용에서 빠른 설치 문서로
            'path_regex' => '#\.md$#u',
            'from' => 'docs/install_guide.md',
            'to' => 'docs/install_quick.ko.md',
            'when_missing' => 'docs/install_guide.md',
        ],
    ],
];
