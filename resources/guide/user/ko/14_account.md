---
key: account
title: 내 정보
order: 14
summary: 표시 이름·이메일 수정, 인증 방식 확인, 비밀번호 변경, 화면 언어·테마 설정
keywords: 내 정보, 계정, 표시 이름, 이메일, 인증 방식, 비밀번호 변경, LDAP, SSO, OIDC, SAML, 언어, 테마
related: getting-started, login-errors, notifications, shortcuts-and-icons
audience: user
---

## 무엇을 하나요

"{{ui:account.title}}" 화면에서 내 계정 정보를 확인하고, 다른 사용자에게 보이는 이름과 이메일을 바꿉니다. 상단 바 오른쪽의 내 이름을 누르면 열립니다.

## 계정 정보 확인

화면 위쪽 "{{ui:account.info_title}}" 구역에 다음이 보입니다. 이 값들은 여기서 바꿀 수 없습니다.

- 로그인 ID
- 역할(관리자 또는 일반 사용자)
- {{ui:account.auth_type}}: {{ui:account.auth_local}}, LDAP, OIDC(SSO), SAML(SSO) 중 하나

역할이나 인증 방식을 바꿔야 하면 관리자에게 요청하세요.

## 이름과 이메일 바꾸기

1. "{{ui:users.display_name}}" 칸에 다른 사용자에게 보일 이름을 입력합니다. 꼭 입력해야 하며 100자 이하입니다.
2. "{{ui:users.email}}" 칸은 선택입니다. 관리자와 본인에게만 보입니다.
3. {{ui:account.save}} 단추를 누릅니다. 저장되면 "{{ui:account.saved}}" 안내가 나옵니다.

표시 이름은 다른 사용자의 로그인 ID와 같을 수 없습니다. 형식이 맞지 않으면 칸 아래에 이유가 나옵니다.

조직 계정(LDAP·SSO)도 이름과 이메일을 여기서 고칠 수 있습니다. 이름과 이메일은 계정을 처음 만들 때 조직에서 한 번만 가져오고, 그 뒤 조직 쪽 값이 바뀌어도 자동으로 따라 바뀌지 않습니다.

## 비밀번호 바꾸기

비밀번호 변경은 **{{ui:account.auth_local}}(로컬 계정)만** 할 수 있습니다.

1. "{{ui:account.title}}" 화면 아래의 {{ui:account.password_link}} 링크를 누릅니다.
2. 현재 비밀번호, 새 비밀번호, 새 비밀번호 확인을 입력하고 저장합니다.
3. 바꾸고 나면 다른 기기에서 로그인된 세션은 모두 끊깁니다.

새 비밀번호의 규칙은 다음과 같습니다.

- 최소 길이(글자 수): {{limit:auth.password_min_length}}
- 대문자, 소문자, 숫자, 특수문자 중 포함해야 하는 종류 수: {{limit:auth.password_min_char_classes}}

## 조직 계정(LDAP·SSO)의 비밀번호

인증 방식이 LDAP, OIDC(SSO), SAML(SSO)인 계정은 PJGuardian이 비밀번호를 갖고 있지 않습니다. 화면에 조직의 디렉터리에서 비밀번호를 바꾸라는 안내가 보이고, 비밀번호 변경 링크가 없습니다. 비밀번호는 조직이 쓰는 계정 관리 방법으로 바꾸세요. 잘 모르면 조직의 IT 담당자에게 문의하세요.

## 화면 언어와 테마

언어와 테마는 "{{ui:account.title}}" 화면이 아니라 상단 바 오른쪽의 단추로 바꿉니다.

- {{icon:ui.language}} 단추: 한국어와 영어를 바꿉니다. 화면을 다시 불러오므로 입력 중인 내용이 있으면 먼저 확인을 묻습니다.
- 테마 단추: "{{ui:ui_switch.theme_menu}}" 메뉴에서 {{ui:ui_switch.theme.light}}, {{ui:ui_switch.theme.dark}}, {{ui:ui_switch.theme.system}} 중 하나를 고릅니다. 바로 적용됩니다.

로그인한 상태에서 고른 값은 계정에 저장되어 다른 브라우저에서도 같게 보입니다. 계정에 저장하지 못하면 그 브라우저에만 적용된다는 안내가 나옵니다. 자세한 내용은 [시작하기](guide:getting-started)를 보세요.

## 주의

- 다른 사람의 계정 정보는 바꿀 수 없습니다. 계정 잠금 해제, 임시 비밀번호 발급, 역할 변경은 관리자가 합니다.
- 받을 알림 종류는 "{{ui:account.title}}"가 아니라 알림 화면에서 정합니다([앱 내 알림](guide:notifications)).

## 관련 항목

- [시작하기](guide:getting-started)
- [로그인 오류 해결](guide:login-errors)
- [앱 내 알림](guide:notifications)
- [화면 읽는 법](guide:shortcuts-and-icons)
