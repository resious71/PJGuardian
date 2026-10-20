---
key: notifications
title: 앱 내 알림
order: 12
summary: 분석 결과와 내게 지정된 결함 소식을 알림으로 받고, 종류별로 켜고 끄는 방법
keywords: 알림, 배지, 알림 패널, 읽음, 삭제, 알림 설정, 담당자 지정, 내 결함, 분석 완료, 분석 실패, 보관 기간
related: defects, analysis-status, history, account
audience: user
---

## 무엇을 하나요

PJGuardian은 분석이 끝났을 때나 결함이 나에게 지정됐을 때 앱 안에서 알림을 보냅니다. 알림은 나만 볼 수 있습니다. 관리자도 다른 사람의 알림은 보지 않습니다.

![상단 알림 아이콘을 눌러 연 알림 패널. 최근 알림, 모두 읽음, 모두 보기가 있습니다.](guide-img:notification-panel)

## 알림 종류

일반 사용자가 받는 알림은 다섯 종류입니다.

- **{{ui:notifications.kind.analysis_done}}**: 내가 요청한 분석이 성공했을 때. Run 번호와 신규·해결·재오픈 건수가 함께 나옵니다.
- **{{ui:notifications.kind.analysis_failed}}**: 내가 요청한 분석이 실패·취소·시간 초과로 끝났을 때
- **{{ui:notifications.kind.defect_assigned}}**: 결함이 나에게 지정됐을 때(내가 나를 지정한 경우 포함)
- **{{ui:notifications.kind.defect_changed}}**: 내가 담당한 결함의 상태나 심각도를 누군가(나 포함) 바꿨을 때
- **{{ui:notifications.kind.analysis_summary}}**: 내가 담당한 결함이 있는 프로젝트의 분석이 끝났을 때. 프로젝트의 신규·해결·재오픈 건수와 내 담당 결함의 해결·재오픈 건수를 알려 줍니다.

알아 둘 점:

- 분석이 자동으로 해결하거나 다시 연 결함은 결함마다 알리지 않고, 요약 알림의 건수로만 알립니다.
- 프로젝트에 변화가 하나도 없으면 요약 알림은 오지 않습니다. 분석을 요청한 본인은 요약 대신 완료 알림을 받습니다.
- 여러 결함을 한꺼번에 바꾸면 한 사람에게 알림 한 건으로 모아 보냅니다.
- 관리자에게는 백업·주기 분석·계정 승인 같은 관리자용 알림이 더 있습니다.

## 헤더 배지와 알림 패널

상단 메뉴의 {{icon:nav.notifications}} 항목에 안 읽은 알림 수가 숫자로 보입니다. 0이면 숫자가 없고, 100건 이상이면 "99+"로 보입니다. 화면을 열어 두면 {{limit:ui.notification_poll_seconds}}초마다 개수를 다시 확인합니다. 브라우저 탭이 숨겨져 있는 동안은 확인을 멈춥니다.

1. {{icon:nav.notifications}}를 누르면 "{{ui:notif.panel_title}}" 패널이 열리고 최근 알림이 보입니다.
2. 알림을 누르면 읽음으로 바뀌고 관련 화면(분석 결과, 결함, 내 결함 목록 등)으로 이동합니다.
3. {{ui:notif.read_all}}를 누르면 안 읽은 알림을 모두 읽음으로 바꿉니다.
4. {{ui:notif.view_all}}를 누르면 알림 화면으로 갑니다.

패널은 Esc, 바깥 클릭, 포커스가 패널 밖으로 나가면 닫힙니다. 패널 안에서는 위아래 화살표, Home, End로 알림 사이를 옮겨 다닙니다.

## 알림 목록에서 정리하기

알림 화면의 "{{ui:notifications.tab_list}}" 탭에서 알림을 관리합니다.

1. "{{ui:notifications.filter_kind}}" 상자와 "{{ui:notifications.unread_only}}"로 조건을 정하고 검색 단추를 누릅니다.
2. 알림마다 {{ui:notif.mark_read}} 또는 {{ui:notif.mark_unread}}, {{ui:notif.delete}}를 누를 수 있습니다.
3. {{ui:notifications.read_all}}는 조건과 관계없이 내 모든 알림을 읽음으로 바꿉니다.
4. {{ui:notifications.delete_read}}는 확인을 거쳐 읽은 알림을 모두 지웁니다. 지운 알림은 되돌릴 수 없습니다.

## 알림 켜기와 끄기

"{{ui:notifications.tab_settings}}" 탭에서 종류마다 스위치로 받을지 정합니다.

- 스위치를 바꾸면 바로 저장됩니다.
- 끈 종류의 알림은 아예 만들어지지 않습니다. 다시 켜도 그동안의 알림은 생기지 않습니다.
- {{ui:notifications.settings_reset}}을 누르면 모든 종류를 다시 켭니다.

## 내게 지정된 결함

결함의 담당자로 지정되면 {{ui:notifications.kind.defect_assigned}} 알림이 옵니다. 내가 담당한 결함은 상단 메뉴 {{icon:nav.my_defects}}에서 상태별로 모아 볼 수 있습니다. 여러 결함이 한꺼번에 지정된 알림을 누르면 이 목록으로 이동합니다. 결함을 다루는 방법은 [결함 관리](guide:defects)를 보세요.

## 보관 기간

읽은 알림과 오래된 안 읽은 알림은 관리자가 정한 기간이 지나면 자동으로 정리됩니다. 한 사람이 가질 수 있는 알림 수에도 상한이 있어, 넘으면 오래된 읽은 알림부터 지워집니다. 남겨 둘 내용이 있으면 결함 메모 등 다른 곳에 기록하세요.

## 주의

- 프로젝트에 대한 접근 권한을 잃으면 그 프로젝트의 알림은 목록과 개수에서 사라집니다.
- 주기 분석처럼 요청한 사람이 없는 분석은 완료·실패 알림을 보내지 않습니다.
- 알림은 앱 안에만 보입니다. 열어 둔 화면이 알림 개수를 확인해도 로그인 활동으로 치지 않으므로, 오래 자리를 비우면 로그아웃될 수 있습니다([로그인 오류 해결](guide:login-errors)).

## 관련 항목

- [결함 관리](guide:defects)
- [분석 상태](guide:analysis-status)
- [히스토리](guide:history)
- [내 정보](guide:account)
