---
key: login-errors
title: Sign-in problems
order: 15
summary: What sign-in failures, account lockout, IP restriction, expired temporary passwords, session expiry, pending approval, and SSO error codes (E-SSO-*) mean and what to do
keywords: sign-in failed, login failed, lockout, IP block, temporary password, session expired, pending approval, not approved, authentication service, SSO, OIDC, SAML, E-SSO-STATE, E-SSO-IDP, E-SSO-TOKEN, E-SSO-CONFIG, E-SSO-UNAVAILABLE, E-SSO-RATE, E-SSO-DENIED
related: getting-started, account, errors-help
audience: user
---

## What it does

This section explains the notices and errors on the sign-in screen and what to do about them. For how to sign in, see [Getting started](guide:getting-started).

For security, PJGuardian does not show the detailed reason for a failed sign-in on the screen. If you need the reason, contact an admin. Admins can check it in the records.

## When the ID or password is incorrect

This message appears:

"{{ui:error.login_failed}}"

The message is the same for an ID that does not exist, a wrong password, a locked account, and a deactivated account. This is so that the screen does not reveal whether an account exists.

1. Check your login ID and password again. Also check Caps Lock and your keyboard input language.
2. Make sure you entered the app password for an app account, or your organization password for an organization account (LDAP/AD). App accounts cannot sign in with an organization password.
3. If it keeps failing, stop trying and contact an admin. Repeated failures lead to a lockout or restriction.

## Account lockout

App accounts are locked after consecutive failed sign-ins.

- Consecutive failures before lockout: {{limit:auth.lockout_max_failures}}
- Lockout duration: {{limit:auth.lockout_duration}}

While locked, even the correct password shows the general failure message above. Try again after the lockout ends, or if it is urgent, ask an admin to unlock your account. Organization accounts (LDAP/AD) are not locked by the app; they follow your organization directory's lockout policy.

## When sign-in is temporarily restricted

This message means there were too many failed sign-ins from the same IP address:

"{{ui:error.too_many_attempts}}"

- Threshold: {{limit:auth.ip_max_failures}} failures within {{limit:auth.ip_failure_window}}
- Restriction period: {{limit:auth.ip_block_duration}}

When several people share one IP address, as in the same office, you can be restricted because of someone else's failures. Try again after the period ends. Organization accounts are also briefly restricted with the same message when the same login ID fails repeatedly.

## When the temporary password has expired

"{{ui:error.temp_password_expired}}"

A temporary password stops working {{limit:auth.temp_password_expiry}} after it is issued. Ask an admin for a new temporary password. After signing in with a temporary password, you must change it to a new password before you can use other features.

## When your session has expired

If the sign-in screen shows "{{ui:login.notice_expired}}", you were logged out automatically because of inactivity.

- You are logged out after {{limit:auth.session_idle}} without activity.
- Just keeping a page open does not count as activity. Background requests that check the notification count are not activity either.
- After you sign in again, you return to the screen you were on. Unsaved input may be lost, so save before leaving for a long time.

## Notices for organization accounts

When you sign in with an organization account (LDAP/AD, SSO), a notice box may appear above the sign-in form. These notices are shown only to people who passed the organization's authentication (password check). If the password is wrong, you see the general failure message.

### Pending approval

"{{ui:error.account_pending}}"

Your account was created at your first sign-in and is waiting for admin approval. You cannot sign in before it is approved. Ask an admin to approve it, then sign in again after approval.

### Not approved

"{{ui:error.account_rejected}}"

An admin rejected the account request. Signing in again does not change this. Contact an admin.

### Account requests not accepted

"{{ui:error.account_requests_closed}}"

Too many accounts are waiting for approval, so new account requests cannot be accepted right now. Contact an admin.

### Authentication service problem

"{{ui:error.auth_service_unavailable}}"

PJGuardian could not connect to your organization's directory server. Try again later, and if it continues, tell an admin.

## SSO error codes

If signing in with an SSO button fails, the message "SSO sign-in failed" appears with an error code. There is only one message; the code tells the causes apart. Find the code below and follow the steps. When you try again, don't use an earlier address from the address bar or a bookmark; click the SSO button again on the sign-in screen.

### E-SSO-STATE

The sign-in request could not be confirmed. This happens when a long time (10 minutes) passed after you started signing in, when the same sign-in request was used again, or when the sign-in finished in a different browser from the one where it started.

- Open the sign-in screen again in the same browser and click the SSO button again.
- Don't resend the organization's sign-in page with Back or Refresh.

### E-SSO-IDP

Your organization's sign-in service (IdP) returned an error. For example, you declined consent or cancelled on the organization's sign-in screen.

- Try again.
- If it keeps appearing, check with an admin whether your organization account is allowed to use this app.

### E-SSO-TOKEN

The sign-in result sent by your organization's sign-in service could not be verified.

- Try once more.
- If it keeps appearing, tell an admin. It may be a settings or certificate problem that users cannot fix.

### E-SSO-CONFIG

There is a problem with PJGuardian's SSO settings. Trying again will not fix it. Tell an admin.

### E-SSO-UNAVAILABLE

PJGuardian could not reach your organization's sign-in service, or it responded too slowly. Try again later, and if it continues, tell an admin.

### E-SSO-RATE

There were too many sign-in requests in a short time, or the IP address you are connecting from is briefly restricted because of many failed sign-ins. Wait a while and try again.

### E-SSO-DENIED

You passed your organization's authentication, but PJGuardian refused because it could not determine your account. This happens when another account with the same login ID already exists, the account is deactivated, or the login ID sent by your organization cannot be used. Trying again will not change it, so contact an admin.

## What to tell an admin

When you contact an admin, including the following helps them check quickly:

- The message or error code on the screen (for example E-SSO-TOKEN)
- The time you tried to sign in
- The sign-in method you used (ID and password, or the SSO button name) and your login ID

Never tell anyone your password, including admins. For more, see [Error messages](guide:errors-help).

## Related

- [Getting started](guide:getting-started)
- [My account](guide:account)
- [Error messages](guide:errors-help)
