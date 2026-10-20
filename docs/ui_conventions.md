# 화면 규칙 (P27D 기준)

화면을 고치거나 새로 만들 때 지키는 규칙. 자세한 구조는 `docs/architecture.md` 10절(문자열 리소스)·47절(언어·테마), 아이콘은 `docs/icons.md`, 용어는 `docs/i18n_glossary.md`.

## 1. 문구

- 화면 문구는 **문자열 리소스만**: PHP `src/Lang/ko.php`·`en.php`, JS `public/assets/js/i18n/ko.js`·`en.js`. 새 키는 **ko와 en에 함께** 더한다(키 집합·자리표시자 이름이 같아야 한다).
- 뷰(`src/Views`)·컨트롤러·화면 스크립트에 한글 문구를 직접 쓰지 않는다. 뷰의 설명 주석은 PHP 주석(`<?php /* */ ?>`)으로(HTML 주석은 브라우저로 나감).
  예외는 화면에 나가지 않는 개발자 오류만이며 `tests/Unit/I18nTest::JS_HANGUL_ALLOW`에 파일과 사유를 적는다.
- 수와 결합하는 문구: 한국어 `{n}건`, 영어 `{n} {n:defect|defects}`. 시간 "N분 전"도 같은 방식.
- 사용자 입력(프로젝트·그룹 이름, 메모, 사유)과 도구 원문(결함 메시지·규칙 설명)은 번역하지 않고 `textContent`로 그대로 보인다.
- 영어는 문자열이 길다. 버튼·메뉴·표 머리글은 짧게 쓰고, 한 줄 요소(`white-space: nowrap`)에 넣을 때는 1280px에서 잘리지 않는지 `tools/check_i18n_theme_ui.mjs`로 확인한다.

## 2. 색과 테마

- 색은 **`public/assets/css/tokens.css`의 토큰만** 쓴다(`#…`·`rgb(…)` 직접 금지). 다크 테마는 같은 토큰을 다른 값으로 바꾼다.
- 새 토큰을 더하면 라이트(`:root`)와 다크 두 블록(`:root[data-theme="dark"]`, `@media (prefers-color-scheme: dark)` 안)에 같이 넣고 `node tools/check_theme_contrast.mjs`가 실패 0인지 본다(필요하면 점검 쌍도 더한다).
- 글자로 쓰는 강조색은 `--color-link`(링크·현재 탭), 버튼·테두리 같은 면은 `--color-primary`. 코드 뷰어 문법 색은 `--code-*`.
- 상태는 색만으로 구분하지 않는다(아이콘 + 글자). 아이콘은 `currentColor`.
- 인라인 스타일·인라인 스크립트는 쓰지 않는다(CSP). 차트(캔버스)는 그릴 때 토큰 색을 읽는다.

## 3. 접근성

- 대화상자·메뉴는 키보드로(Esc 닫기, 화살표 이동, 포커스 되돌림), 상태 표시는 `aria-live`, 토글은 `aria-pressed`/`aria-checked`.
- 포커스 링은 `--color-focus`(바탕·카드 위 3:1 이상).

## 4. 모니터링 카드 공용 구조 (X18)

시스템 모니터링(`/admin/monitoring`)의 카드는 모두 공용 틀 하나(`src/Views/admin/_monitor_card.php`, 화면 스크립트 `pages/admin_monitoring.js`)로 그린다. 카드마다 따로 마크업·CSS를 만들지 않는다. 새 카드도 같은 틀을 쓴다.

- **구조**(위 → 아래):
  1. 머리: 상태 아이콘 + 제목(X19 — 정상·정보 파랑 `--info-fg`, 경고·오류 빨강 `--error-fg`, 확인 불가 회색, 장식이며 상태 글자는 배지), 오른쪽 끝에 상태 배지(정상 배지·왼쪽 띠도 파랑 info 토큰)
  2. 핵심 값 줄: 큰 값 + 작은 단위(`.mon-card__num`·`.mon-card__unit`)
  3. 세부: 라벨-값 두 열(`.mon-card__pairs`, 긴 라벨은 말줄임 + 툴팁) 또는 같은 표 스타일(`.mon-table` — caption·머리글 연결, 최대 `MAX_ROWS` = 5행 + "외 N건")
  4. 바닥 줄: 상세 화면 링크(없으면 빈 자리) · 확인 시각 — 늘 같은 자리
- **상태 배지 한 사전** `monitoring_core.js`의 `BADGES`/`badgeView`:
  - 정상(`system.ok`)·경고(`system.warn`)·오류(`system.error`)·확인 불가(`system.unknown`)
  - 판정이 없는 카드(앱 정보)만 정보(`ui.info`)
  - 아이콘 + 글자로 표시하고 색만으로 구분하지 않는다. 상세 줄에는 상태 아이콘을 다시 쓰지 않는다(표 안 개별 항목의 체크섬·스모크 결과는 예외).
- **빈 상태·확인 불가**: 같은 회색 보조 글자(`.mon-card__empty`)를 같은 자리(핵심 값 줄)에 쓴다. 확인 불가 이유는 `data-unavailable`.
- **격자**: `.mon-grid`
  - 열 수: 641px 이상 2열, 1200px 이상 3열, 그 아래 1열
  - 넓은 카드 `.mon-card--wide`(2열 폭), `.mon-card--wide-md`(2열 격자에서만 2열 폭)
  - 간격·안쪽 여백·모서리·테두리 1px은 모든 카드가 같다. 한 줄의 카드는 높이가 같다(격자 행 늘이기 + 바닥 줄 `margin-top: auto`).
- **상태 색**: 카드 배경은 바꾸지 않는다. 왼쪽 4px 띠(`data-badge`)와 배지만 토큰 색(`--ok-*`·`--warn-*`·`--error-*`·`--info-*`·`--muted-*`)을 쓴다.
- **검사**: `tools/check_monitoring_ui.mjs`가 구조·한 줄 높이 차 1px·간격·열 수(1280 3 · 1024 2 · 640 1)·상태가 바뀌어도 높이 그대로를 검사한다.
