---
key: shortcuts-and-icons
title: 화면 읽는 법
order: 17
summary: 아이콘 범례 보는 법, 화면별 도움말 링크, 키보드로 화면을 다루는 방법
keywords: 아이콘, 아이콘 범례, 도움말, 이 화면 도움말, 키보드, 단축키, Tab, Esc, 화살표, 접근성, 화면 낭독기
related: getting-started, account, errors-help, dashboard
audience: user
---

## 무엇을 하나요

PJGuardian 화면의 아이콘이 무슨 뜻인지, 각 화면의 도움말을 어떻게 여는지, 마우스 없이 키보드로 어떻게 다루는지를 설명합니다.

## 아이콘 읽는 법

- 화면의 아이콘은 늘 글자나 접근 가능한 이름과 함께 쓰입니다. 아이콘만 보고 뜻을 짐작하지 않아도 됩니다.
- 같은 아이콘은 어느 화면에서나 늘 같은 뜻입니다. 예를 들어 {{icon:ui.changed}}는 어디서든 도구 버전이나 설정이 바뀌었다는 뜻입니다.
- 심각도와 상태는 색만으로 구분하지 않습니다. 아이콘 모양과 글자가 함께 있으므로 색을 구분하기 어려워도 읽을 수 있습니다.

자주 보는 아이콘의 예입니다.

- {{icon:severity.critical}}, {{icon:severity.high}}, {{icon:severity.medium}}, {{icon:severity.low}}, {{icon:severity.not_defined}}: 결함 심각도
- {{icon:analysis_status.succeeded}}, {{icon:analysis_status.failed}}, {{icon:analysis_status.unchanged}}: 분석 상태
- {{icon:ui.read_only}}: 과거 Run을 읽기 전용으로 보는 중
- {{icon:action.export}}: 내보내기

## 아이콘 범례

아래 표가 아이콘 범례입니다. 아이콘이 종류별로 묶여 있고, 아이콘마다 화면에 보이는 이름과 접근 가능한 이름이 함께 나옵니다. 범례는 앱의 아이콘 목록에서 만들어지므로 새 아이콘이 생기면 저절로 더해집니다. 다른 절에서는 화면 맨 아래 한 줄의 "{{ui:guide.legend_link}}" 링크로 이 절에 올 수 있습니다. 같은 줄에서 PJGuardian 버전과 지금 쓰는 ruff·ESLint 버전, 서드파티 라이선스 고지도 볼 수 있습니다.

{{legend:icons}}

## 도움말 링크

주요 화면의 머리 오른쪽에는 {{icon:nav.guide}} "{{ui:help.link}}" 링크가 있습니다. 누르면 그 화면을 설명하는 가이드 절이 열립니다.

- 같은 탭에서 열리므로 브라우저의 뒤로 가기로 원래 화면에 돌아갑니다.
- 붙어 있는 화면: 프로젝트 목록, 프로젝트 화면, 업로드, 분석 상세, 결함 목록과 결함 상세, 내 결함, 히스토리, 대시보드, 규칙 설명, 알림, 내 정보, 비밀번호 변경

오류 화면의 "{{ui:guide.detail_link}}" 링크는 [오류 메시지 해결](guide:errors-help)을 보세요.

## 키보드로 다루기

### 이동

- Tab으로 다음 항목, Shift+Tab으로 이전 항목으로 갑니다.
- 화면을 연 뒤 처음 Tab을 누르면 "{{ui:layout.skip_to_content}}" 링크가 나타납니다. Enter를 누르면 상단 메뉴를 건너뛰고 본문으로 갑니다.

### 대화상자

- 확인 대화상자가 열리면 포커스가 대화상자 안에만 머뭅니다.
- Esc를 누르면 취소하고 닫힙니다.
- 확인 대화상자는 {{ui:component.cancel}} 단추에 먼저 포커스가 있어, 실수로 Enter를 눌러도 실행되지 않습니다.
- 닫히면 대화상자를 열었던 단추로 포커스가 돌아갑니다.

### 탭

프로젝트 화면의 탭, 알림 화면의 탭, 규칙 설명의 언어 탭은 왼쪽·오른쪽 화살표로 옮기고 Home, End로 처음과 끝으로 갑니다.

### 목록과 메뉴

- **테마 메뉴**: 위아래 화살표로 고르고 Enter나 Space로 정합니다. Esc로 닫습니다.
- **알림 패널**: 위아래 화살표, Home, End로 알림 사이를 옮기고 Esc로 닫습니다.
- **대시보드 선택 상자**: 글자를 입력해 목록을 좁히고, 위아래 화살표로 고른 뒤 Enter를 누릅니다. Esc는 목록을 닫습니다.
- **Run 선택 상자**: 화살표로 Run을 고른 뒤 Enter를 누르면 그 Run으로 이동합니다.

### 검색과 툴팁

- 결함·규칙의 검색 영역에서는 검색어 칸에서 Enter를 누르면 검색됩니다.
- 가이드 화면의 "{{ui:guide.search_label}}" 칸은 두 글자 이상 입력해야 찾고, Esc로 지웁니다.
- 긴 파일 경로처럼 줄여서 보이는 값은 키보드로 포커스해도 전체 값이 툴팁으로 보입니다. Esc로 툴팁을 닫습니다.

### 차트

차트는 마우스로 누르는 대신 {{ui:dashboard.show_table}} 단추로 같은 값을 표로 볼 수 있습니다. 표 안의 링크는 Tab으로 옮겨 다니며 Enter로 엽니다([대시보드](guide:dashboard)).

## 화면 언어와 테마

화면 언어(한국어·영어)와 테마(라이트·다크·시스템 따라가기)는 상단 바 오른쪽 단추로 바꿉니다. 자세한 내용은 [내 정보](guide:account)를 보세요.

## 관련 항목

- [시작하기](guide:getting-started)
- [내 정보](guide:account)
- [오류 메시지 해결](guide:errors-help)
- [대시보드](guide:dashboard)
