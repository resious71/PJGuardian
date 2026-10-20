---
key: account
title: My account
order: 14
summary: Change your display name and email, check your authentication method, change your password, and set the screen language and theme
keywords: my account, account, display name, email, authentication method, change password, LDAP, SSO, OIDC, SAML, language, theme
related: getting-started, login-errors, notifications, shortcuts-and-icons
audience: user
---

## What it does

On the "{{ui:account.title}}" screen you check your account information and change the name other users see and your email. Click your name at the right of the top bar to open it.

## Checking your account information

The "{{ui:account.info_title}}" section at the top shows the following. You cannot change these values here.

- Login ID
- Role (admin or regular user)
- {{ui:account.auth_type}}: one of {{ui:account.auth_local}}, LDAP, OIDC (SSO), or SAML (SSO)

If your role or authentication method needs to change, ask an admin.

## Changing your name and email

1. In "{{ui:users.display_name}}", enter the name other users will see. It is required and can be up to 100 characters.
2. "{{ui:users.email}}" is optional. It is visible only to admins and you.
3. Click {{ui:account.save}}. When it is saved, "{{ui:account.saved}}" appears.

Your display name cannot be the same as another user's login ID. If the format is not valid, the reason appears under the field.

Organization accounts (LDAP, SSO) can also change their name and email here. The name and email are taken from your organization only once, when the account is first created; later changes on the organization side are not copied automatically.

## Changing your password

Only **{{ui:account.auth_local}} (local account)** users can change their password.

1. Click the {{ui:account.password_link}} link at the bottom of the "{{ui:account.title}}" screen.
2. Enter your current password, the new password, and the new password again, then submit.
3. After the change, all sessions signed in on other devices are ended.

The new password must meet these rules:

- Minimum length (characters): {{limit:auth.password_min_length}}
- Number of character types required among uppercase, lowercase, digits, and special characters: {{limit:auth.password_min_char_classes}}

## Passwords for organization accounts (LDAP, SSO)

For accounts whose authentication method is LDAP, OIDC (SSO), or SAML (SSO), PJGuardian does not hold a password. The screen tells you to change your password in your organization's directory, and there is no password change link. Change your password the way your organization manages accounts. If you are not sure how, ask your organization's IT staff.

## Screen language and theme

You change the language and theme with the buttons at the right of the top bar, not on the "{{ui:account.title}}" screen.

- {{icon:ui.language}} button: switches between Korean and English. The page reloads, so if you have unsaved input you are asked to confirm first.
- Theme button: in the "{{ui:ui_switch.theme_menu}}" menu, choose {{ui:ui_switch.theme.light}}, {{ui:ui_switch.theme.dark}}, or {{ui:ui_switch.theme.system}}. It applies immediately.

When you are signed in, your choice is saved to your account and looks the same in other browsers. If it cannot be saved to your account, a message says it applies to this browser only. For more, see [Getting started](guide:getting-started).

## Cautions

- You cannot change other people's account information. Unlocking accounts, issuing temporary passwords, and changing roles are done by an admin.
- Which notifications you receive is set on the notifications screen, not in "{{ui:account.title}}" ([In-app notifications](guide:notifications)).

## Related

- [Getting started](guide:getting-started)
- [Sign-in problems](guide:login-errors)
- [In-app notifications](guide:notifications)
- [Reading the screen](guide:shortcuts-and-icons)
