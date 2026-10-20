---
key: deploy-bundle
title: 배포 묶음
order: 2
summary: 운영 서버에 올릴 배포 묶음을 만드는 순서, 넣을 것·뺄 것과 tools/verify_bundle.php로 묶음을 점검하는 방법
keywords: 배포 묶음, build_bundle, vendor, Composer, composer install, resources/guide, verify_bundle, 묶음 점검, 업그레이드, config.ini, secrets.ini, settings.local.ini, pjgkey
related: install, tools-bin, backup-restore, auth-saml
audience: admin
---
## 무엇이 들어가야 하나요

운영 묶음에는 `vendor/`(Composer 라이브러리)와 `resources/guide/`(가이드 본문·그림)가 반드시 들어가야 합니다.

- `vendor/`가 빠지면 SAML 로그인만 꺼집니다. 로그인 화면에 SAML 단추가 나오지 않고, SAML을 켠 상태면 점검 화면에 라이브러리가 없다는 문제가 표시됩니다.
- `vendor/`는 git에 없으므로 묶음을 만들 때 빌드 PC에서 넣습니다. 운영 서버에서 Composer를 돌릴 필요는 없습니다.
- `bin/`의 분석 도구는 묶음과 따로 배치합니다([bin/ 도구 배포](guide:tools-bin)).

## 묶음 만들기

1. 빌드 PC에서 `composer install --no-dev --optimize-autoloader`를 실행합니다.
2. 묶음을 만듭니다. 스크립트가 저장소 파일과 `vendor/`를 `dist/` 아래 새 폴더에 복사하고, 묶음 정보(`BUNDLE.json`)와 파일 체크섬(`SHA256SUMS`)을 쓴 뒤 바로 묶음을 점검합니다.

```
php tools/build_bundle.php --zip
```

- 시험 코드·개발용 도구·단계 보고·시험용 zip은 넣지 않습니다. 분석 도구 점검(스모크 테스트)에 쓰는 `samples/`의 시료 코드는 들어갑니다. `data/`는 빈 폴더 뼈대만 들어갑니다.
- `bin/`은 체크섬 목록만 들어갑니다. 인터넷이 막힌 서버에 분석 도구까지 함께 옮기려면 `--with-bin`을 붙입니다.
- 커밋하지 않은 변경이 있으면 멈춥니다.

손으로 묶은 폴더는 따로 점검합니다.

```
php tools/verify_bundle.php <묶음 폴더>
```

## 점검 항목과 결과

- 있어야 하는 것: `vendor/autoload.php`, `resources/guide/user/ko`·`en`의 본문, FAQ 기본 질문 `resources/faq/seed.ko.json`, 분석 도구 점검 시료(`samples/python_sample`·`python_syntax_error`·`js_sample_noconfig`·`ts_sample` — 없으면 새 설치에서 분석 도구가 모두 사용 불가), `resources/guide/screenshots.json`이 가리키는 그림 전부, `composer.lock`과 `vendor/composer/installed.json`의 패키지 버전 일치.
- 없어야 하는 것: `tests/`, `.git`, `data/`의 데이터 파일(빈 폴더 표지 `.gitkeep`만 허용), 서버마다 따로 만드는 설정·비밀 파일(`config/config.ini`, `config/secrets.*`(`config/secrets.example` 제외), `config/settings.local.ini`), `.pjgkey` 키 파일.
- 설정·비밀 파일과 키 파일이 묶음에 들어 있으면 도구가 문제로 보고합니다. `config/config.ini.example` 같은 `config/` 안의 예시 파일은 들어 있어도 됩니다.
- 결과: 통과 0, 문제가 있으면 FAIL 목록을 출력하고 1, 폴더를 주지 않는 등 사용법 오류는 2. 이 도구는 읽기만 하고 파일을 바꾸지 않습니다.

## 업그레이드 때

업그레이드 유지보수 모드로 사용자에게 미리 알리고, 업그레이드하는 동안 변경을 막습니다.

1. **미리 알리기**: {{ui:admin_system.title}} > {{ui:config_settings.title}} > {{ui:config_settings.group.maintenance}} 탭에서 "{{ui:config_settings.key.maintenance.notice_at.label}}"과 "{{ui:config_settings.key.maintenance.message.label}}"을 저장합니다. 그 시각 전까지 모든 사용자 화면 위에 예정 공지가 보이고, 시각이 지나면 저절로 사라집니다.
2. **유지보수 모드 켜기**: 같은 탭에서 "{{ui:config_settings.key.maintenance.enabled.label}}"을 켜고 저장합니다. 저장하는 순간 워커가 DB 백업(`pre_upgrade`, 스냅샷 제외 — 업그레이드는 DB 구조만 바꿉니다)을 먼저 합니다. 워커가 꺼져 있거나 백업이 실패하거나 {{limit:maintenance.backup_wait}} 안에 끝나지 않으면 켜지지 않고 이유가 보입니다.
   - 켜져 있는 동안 관리자가 아닌 사용자는 조회만 할 수 있습니다. 업로드·변경은 안내 문구와 함께 거부되고, 화면 위에 유지보수 배너가 보입니다. 관리자는 모두 할 수 있습니다.
   - 워커는 새 분석과 주기 분석을 시작하지 않고, 진행 중인 분석은 끝냅니다. 헬스체크 응답에 `maintenance: true`가 붙습니다.
3. 진행 중인 분석이 끝나면({{ui:admin_system.title}} > {{ui:admin_worker.title}}에서 실행 중 0) 워커 정지 → 코드 교체 → 마이그레이션 → 워커 다시 시작 순서로 합니다([설치와 환경](guide:install)의 마이그레이션).
4. 확인이 끝나면 유지보수 모드를 끕니다. 설정은 화면 설정 파일에 남아 있으므로 업그레이드 뒤에도 켜진 상태입니다.

- 문제가 생기면 코드를 이전 묶음으로 되돌리고, 켤 때 만든 `pre_upgrade` 백업으로 복원합니다([백업·복원](guide:backup-restore)).
- 명령줄로만 할 때는 `php tools/backup.php --kind=pre_upgrade`로 직접 백업한 뒤 같은 순서로 합니다.
- 코드 교체 때 `config/config.ini`, `config/secrets.ini`, `config/settings.local.ini`, `data/`, `bin/`, `backups/`는 덮어쓰지 않고 `vendor/`는 새 묶음 것으로 바꿉니다.

## 문제 해결

- SAML 단추가 없습니다: 묶음에 `vendor/`가 없는지 `tools/verify_bundle.php`로 확인합니다.
- 묶음 점검이 설정·비밀 파일 때문에 실패합니다: 그 파일을 묶음 폴더에서 지우고 다시 점검합니다. 운영 서버의 설정은 [설치와 환경](guide:install) 순서대로 서버에서 만듭니다.
