# 아이콘 세트와 아이콘 모듈 (명세 20장, P09)

## 1. 선정: Lucide (lucide-static 1.50.0, ISC)

- 라이선스: ISC(MIT와 같은 수준으로 재배포 자유). 원문 `public/assets/icons/lucide/LICENSE`, 출처·무결성 값 `lucide/SOURCE.txt`, 고지 `THIRD_PARTY_NOTICES`.
- 스타일 일관성: 24×24, 선 굵기 2, 둥근 끝, `currentColor` 선 아이콘으로 통일되어 있어 직접 만든 아이콘을 같은 규칙으로 맞추기 쉽다.
- 보유: 명세 20.3의 46개 항목 중 언어 배지·로고를 뺀 전부를 세트 안에서 찾을 수 있었다.
- 필요한 아이콘만 `public/assets/icons/lucide/`에 복사했다(P09 52개, 이후 단계에서 같은 패키지·같은 무결성 값으로 추가: X05 `user-pen` = `action.edit_profile`, P18 `user-check` = `nav.my_defects`(내게 지정된 결함), P20A `wrench` = `admin.tools`, `play`·`power`·`rotate-ccw` = `action.start`·`stop`·`restart`, `pin`·`lock`·`package-x`·`archive`·`hourglass` = `tool_status.pinned`·`blocked`·`removal`·`unused`·`verifying`, `terminal` = `ui.command`, P20R `milestone` = `ui.run`(Run 선택기), `eye` = `ui.read_only`(과거 Run 읽기 전용 보기 — `lock`은 `tool_status.blocked`가 쓰므로 한 아이콘 = 한 뜻을 지키려고 눈 모양), P21 `arrow-up`·`arrow-down`·`equal` = `delta.up`·`down`·`same`(증감, 숫자와 함께), `diamond` = `ui.changed`(버전/설정 변경 — 차트의 변경 표식도 마름모), `scroll-text` = `action.timeline`(결함 전체 이력 보기 — `history`는 `nav.history`가 쓰므로 다른 모양), P27A `sliders-horizontal` = `admin.settings`(설정 관리), `undo-2` = `action.reset_default`(기본값으로 — `rotate-ccw`는 `action.restart`), `folder-search` = `action.check_path`(경로 확인); 환경 변수로 잠긴 설정은 기존 `ui.read_only`, P27B `database-backup` = `action.backup_now`(지금 백업), `archive-restore` = `action.restore`(복원), `file-check` = `action.verify`(묶음 다시 검증), `flask-conical` = `action.rehearse`(복원 리허설 — 시험), `key-round` = `action.export_key`(키 내보내기); 묶음 내려받기는 기존 `action.export`, 올리기는 `action.upload`, P27D `languages` = `ui.language`(화면 언어 전환), `sun`·`moon`·`monitor` = `ui.theme_light`·`theme_dark`·`theme_system`(테마 선택), P29B `messages-square` = `nav.faq`(가이드 FAQ 탭), `plus` = `action.add`(FAQ 추가), `pencil` = `action.edit`(FAQ 수정 — `user-pen`은 `action.edit_profile`), `chevron-up`·`chevron-down` = `action.move_up`·`move_down`(같은 분류 안 순서), `link` = `action.copy_link`(항목 링크 복사 — `copy`는 값 복사), `eye-off` = `ui.draft`(비공개 항목, 글자와 함께 — `eye`는 `ui.read_only`)). CDN과 아이콘 폰트는 쓰지 않는다.
- X18: 같은 패키지(lucide-static 1.50.0, 같은 무결성 값 — npm 캐시에서)에서 `circle-minus` = `system.unknown`(모니터링 카드 배지 "확인 불가"). X19에서 아래 카드 머리 아이콘 그룹은 뺐다(제목 앞 아이콘은 상태 아이콘 하나만 — 파일·매핑·문구 삭제). 모니터링 카드 머리 아이콘 그룹 `monitor`: `cpu`·`list-ordered`·`hard-drive`·`save`·`hammer`·`lock-keyhole`·`calendar-clock`·`list-x`·`app-window` = `monitor.worker`·`queue`·`disk`·`backup`·`tools`·`cert`·`schedules`·`failures`·`app`(한 아이콘 = 한 뜻 — 이미 쓰는 모양과 겹치지 않음).

## 2. 직접 만든 아이콘 (`public/assets/icons/custom/`)

| 파일 | 용도 | 비고 |
| --- | --- | --- |
| `pjg-badge-py.svg` | `language.python` | 둥근 사각형 + 텍스트 `Py`. 외부 공식 로고 아님 |
| `pjg-badge-js.svg` | `language.javascript` | 둥근 사각형 + 텍스트 `JS`. 24px 안에서 `JS/TS`는 읽기 어려워 `JS`로 줄이고, 라벨 텍스트는 "JS/TS · ESLint" |
| `pjg-logo.svg` | `ui.logo` | 방패 + 코드 괄호 |
| `pjg-circle-equal.svg` | `analysis_status.unchanged` | 원 + 같음표(P27 성공(변경 없음)). Lucide `equal`(증감 "같음")과 `check-check`(성공)와 겹치지 않게 원으로 감쌌다. 배지는 성공 색 + 점선 테두리 |

Git·Perforce는 공식 로고 대신 일반 아이콘(`git-branch`, `server`)을 쓴다.

## 3. 매핑 (한 곳에서 관리)

- 의미 이름 → 아이콘·라벨 키·접근성 라벨 키: `public/assets/icons/icons.json` **한 파일**.
- PHP `PJGuardian\Support\Icons`와 JS `public/assets/js/ui/icons.js`가 같은 JSON을 읽는다. 라벨 문자열은 `src/Lang/ko.php`와 `public/assets/js/i18n/ko.js`(값이 같아야 함, `check_icons.mjs`가 검사).
- 헬퍼: `icon(name, size)` 장식용(`aria-hidden`), `meaningful(name, size)`/`meaningfulIcon` 아이콘만 있을 때(`role="img"` + `aria-label`), `withLabel`/`iconWithLabel` 아이콘 + 텍스트. 크기는 16, 20, 24만.
- 매핑에 없는 이름: `app.env=dev`면 오류, 운영이면 `ui.fallback`(circle-dashed)으로 대체하고 WARN 로그.
- 화면 코드(템플릿, pages/*.js)에는 SVG를 직접 쓰지 않는다. 범례는 `src/Views/partials/icon_legend.php`(가이드 16.1에서 재사용).

### 명세 20.3 예시와 다르게 정한 것 (한 아이콘 = 한 뜻, 명세 20.1)

| 의미 이름 | 사용 아이콘 | 이유 |
| --- | --- | --- |
| `defect_status.not_fix` | circle-slash | `analysis_status.cancelled`(ban, 금지)와 겹치지 않게 |
| `analysis_status.succeeded` | check-check | `defect_status.resolved`(circle-check)와 구분 |
| `system.ok/warn/error` | badge-check / badge-alert / badge-x | 심각도·분석 상태 아이콘과 겹치지 않게 |
| `nav.guide` | message-circle-question-mark | `severity.not_defined`(circle-question-mark)와 구분 |
| `admin.guide` | book-key | `nav.rules`(book-open)와 구분 |
| `action.cancel` | circle-stop | `analysis_status.cancelled`(ban)와 구분 |
| `ui.danger` | shield-alert | 파괴적 동작 확인 대화상자 전용(명세 20.4) |

같은 개념 공유는 `source.upload` = `action.upload`(upload, `concept: upload`) 한 건뿐이다. 회전 애니메이션은 `analysis_status.running`만.

## 4. 스프라이트 빌드

```
php tools/build_icons.php     # icons.json에서 쓰는 아이콘만 모아 public/assets/icons/sprite.svg 생성(결과가 항상 같음)
node tools/check_icons.mjs    # 20.3 항목 매핑, 스프라이트 존재, 라벨, 한 아이콘 = 한 뜻 점검(실패 시 종료 코드 1)
```

빌드는 viewBox 0 0 24 24, `currentColor`, script/foreignObject/on* 속성/외부 href가 없는지 검사한다. icons.json이나 SVG를 바꾸면 빌드 후 sprite.svg를 함께 커밋한다.

## 5. 캐시

- 화면은 `<use href="/assets/icons/sprite.svg?v=<sha256 앞 12자>#i-…">`로 참조한다(`Support\Assets::url`). 파일이 바뀌면 주소가 바뀌므로 오래 캐시해도 안전하다. CSS·JS도 같은 방식.
- 권장 Apache 설정(vhost, `mod_headers`):

```apache
<Directory "<설치경로>/public/assets">
    Header set Cache-Control "public, max-age=31536000, immutable"
</Directory>
```

설정하지 않아도 동작은 같다(브라우저 기본 캐시 + ETag 재검증).
