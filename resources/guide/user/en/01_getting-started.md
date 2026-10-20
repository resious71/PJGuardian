---
key: getting-started
title: Getting started
order: 1
summary: How to log in, accounts waiting for approval, password rules, session timeout and lockout, changing the display language and theme
keywords: log in, sign in, password, temporary password, pending approval, LDAP, AD, SSO, OIDC, SAML, session, lockout, language, theme, dark mode
related: login-errors, account, projects, shortcuts-and-icons
audience: user
---

## Logging in

Only logged-in users can use PJGuardian. The ways you can log in depend on how your organization has set it up.

### App account or organization account (LDAP/AD)

1. Enter your "Login ID" and "Password".
2. Select {{ui:login.submit}}.

If directory login (LDAP/AD) is enabled, enter your organization ID and organization password in the same fields. An account created in the app (a local account) logs in only with its app password, never with an organization password.

### SSO (OIDC, SAML)

If SSO is enabled, buttons appear under "{{ui:login.sso_title}}" below the login form (default names {{ui:login.sso_oidc}} or {{ui:login.sso_saml}}; your organization can rename them).

1. Select the SSO button.
2. Sign in on your organization's sign-in page.
3. When sign-in completes, you return to PJGuardian.

If sign-in fails and an error code is shown, see [Login errors](guide:login-errors).

## Accounts waiting for approval

The **first** time you log in with an organization account (LDAP/AD or SSO), PJGuardian creates your account in a "pending approval" state. If an administrator created your account in advance, you can log in right away without approval.

- A pending account cannot log in or use any feature until an administrator approves it.
- Each time you log in, you see the notice that your account is waiting for administrator approval.
- Your name and email are copied from the organization directory once, when the account is created. After approval you can edit them in [My account](guide:account).

### What to do while you wait

1. Ask an administrator to approve your account. The administrator chooses your role when approving.
2. Tell them which projects you need. Approval alone may not make any project visible ([Viewing projects](guide:projects)).
3. Log in again after approval.

If you see "Your account was not approved" or "Account requests can't be accepted right now", contact an administrator. Pending accounts that stay unhandled for a long time may be cleaned up automatically.

## Password rules

Passwords for app accounts (local accounts) must follow these rules:

- Minimum length (characters): {{limit:auth.password_min_length}}
- Number of character types required among uppercase, lowercase, digits, and special characters: {{limit:auth.password_min_char_classes}}
- 72 bytes or less
- The new password must differ from the current one.

Changing your password ends all sign-ins on other devices. Passwords for organization accounts (LDAP/AD, SSO) are changed in your organization's directory, not in PJGuardian.

## Temporary passwords

When an administrator or a group manager creates your account, or an administrator resets your password, you receive a temporary password.

1. Log in with the temporary password.
2. The {{ui:password.title}} screen opens right away. You cannot use other features until you change your password.
3. Enter the "Temporary password", "New password", and the confirmation, then submit the form.

A temporary password expires {{limit:auth.temp_password_expiry}} after it is issued. If it expires, ask an administrator to issue a new one.

If an administrator sets your password directly, you are not forced to change it; instead a notice that an admin changed your password appears once at your next login. If you did not request it, tell an administrator.

## Session timeout

After {{limit:auth.session_idle}} without activity, you are logged out automatically. When you log in again, you return to the screen you were on.

- Just leaving a screen open may not keep the session alive. For example, background requests that check the notification count do not count as activity.
- Sending upload chunks counts as activity. If your session expires during an upload, log in again and select the same target to resume ([Upload](guide:upload)).

## Account lockout

App accounts are locked after repeated failed logins. Entering the wrong current password repeatedly on the change password screen also locks the account after the same number of tries and signs you out everywhere.

- Consecutive failures that lock the account: {{limit:auth.lockout_max_failures}}
- Lockout duration: {{limit:auth.lockout_duration}}

While locked, even the correct password does not work. Try again after the lockout ends, or ask an administrator to unlock the account. Repeated failures from the same IP address are also limited per IP for a while. Organization accounts (LDAP/AD) are not locked by the app; your organization's directory policy applies.

## Changing the display language and theme

Use the buttons at the right end of the top bar. The login screen has the same buttons.

- **Language**: Select the {{icon:ui.language}} button (icon only; hover to see the current language) to switch between Korean and English. The screen reloads, so if you have unsaved input you are asked to confirm first.
- **Theme**: Select the theme button and choose {{icon:ui.theme_light}}, {{icon:ui.theme_dark}}, or {{icon:ui.theme_system}}. It applies immediately without reloading.

If you change these while logged in, they are saved to your account and follow you to other browsers. If you change them before logging in, they apply only to that browser.

Defect messages and rule descriptions from the tools, and names or notes entered by users, are not translated when you switch languages.

## Related

- [Login errors](guide:login-errors)
- [My account](guide:account)
- [Viewing projects](guide:projects)
- [Reading the screen](guide:shortcuts-and-icons)
