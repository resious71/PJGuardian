---
key: external-auth
title: External authentication overview
order: 8
summary: How LDAP/AD, OIDC, and SAML differ, the pending approval flow with its limit and retention, switching sign-in methods, emergency sign-in for local admins, and common troubleshooting (E-SSO-*)
keywords: external authentication, LDAP, AD, OIDC, SAML, SSO, pending approval, rejected, sign-in method, local admin, emergency sign-in, E-SSO-STATE, E-SSO-IDP, E-SSO-TOKEN, E-SSO-CONFIG, E-SSO-UNAVAILABLE, E-SSO-RATE, E-SSO-DENIED
related: auth-ldap, auth-oidc, auth-saml, accounts, account-csv
audience: admin
---

## What this feature does

Besides app (local) accounts, PJGuardian supports three ways to sign in with your organization's identity system. All three are off by default; turn them on in their tabs under System admin > Settings.

- **LDAP/AD** ([LDAP/AD sign-in](guide:auth-ldap)): users type their organization ID and password into the ID and password fields on the sign-in screen. The server checks them directly against the directory server. Requires the PHP ldap extension.
- **OIDC** ([SSO (OIDC) sign-in](guide:auth-oidc)): the SSO button on the sign-in screen sends users to your organization's sign-in service (IdP) and back. Only one provider can be configured.
- **SAML 2.0** ([SSO (SAML) sign-in](guide:auth-saml)): like OIDC, a button sends users to the IdP and back. Only sign-ins started from the app are supported; sign-ins started from the IdP portal and single logout are not. Only one IdP can be configured.

You can enable several methods together. With both OIDC and SAML on, the sign-in screen shows two buttons. The ID and password fields are always there.

## Rules common to all methods

- **Each account has exactly one sign-in method.** Check it in the sign-in method column on the Users screen.
- **Local accounts never sign in through external authentication.** Even if the directory or IdP has an account with the same login ID, the local account is not taken over or switched automatically. To switch it, an admin uses **{{ui:users.action_auth_change}}**.
- **A first sign-in creates a pending account.** Until it is approved, the account is inactive and no session is created.
- **Name and email are filled in from external values once, when the account is created.** Later sign-ins do not update them; if they change, edit them on the Users screen.
- **Accounts are never linked just because the email matches.** Accounts are identified by an external identifier that does not change (for LDAP the stable ID attribute, for OIDC issuer + sub, for SAML the IdP entity ID + NameID or a chosen attribute).
- **There is no periodic sync.** Blocking someone in the directory or IdP leaves the app account as is, so also deactivate departed users in the app.
- The directory search account password, the OIDC client secret, and the SAML SP key are **stored encrypted** in the database, not in the settings file, and are never shown again (replace or delete only). The encryption key lives in secrets.ini on the server and is not included in backups. If the key changes or is missing, the screen shows "unreadable"; enter the value again.

## Pending approval flow

1. When someone who has no app account passes external authentication, a pending account is created (`user.pending_create`). They see "{{ui:error.account_pending}}".
2. Admins get an in-app notification: one per request for up to 5 a day, then a single summary for the rest of that day.
3. On the Users screen, switch the view to **{{ui:users.approval_pending}}** and choose **{{ui:users.action_approve}}** (with a role), **{{ui:users.action_reject}}**, or **{{ui:users.action_delete_pending}}**. See [Managing accounts](guide:accounts) for details.
4. Once approved, the person can sign in from the next attempt. A rejected person sees "{{ui:error.account_rejected}}".

The pending and rejected notices are shown **only to people who passed** external authentication (the password check). A wrong password shows only the generic failure message, so the screen never reveals whether an account exists.

### Limit and retention

Set these on the **{{ui:config_settings.group.auth}}** tab under System admin > Settings. They apply immediately without a restart.

- **Pending account limit** (`auth.pending_max`, currently {{limit:auth.pending_max}}): when this many pending and rejected accounts exist, new requests are not accepted. Newcomers see "{{ui:error.account_requests_closed}}", and admins get a limit notification once a day. Clearing the pending list reopens requests.
- **Pending account retention** (`auth.pending_retention_days`, currently {{limit:auth.pending_retention_days}} days): pending and rejected accounts whose state has not changed for this long are deleted by the daily cleanup job (`tools/cleanup.php`), recorded as `user.pending_purge` with the count only. Approved accounts are never deleted.

### External accounts pre-created by an admin

External accounts created through [Bulk accounts from CSV](guide:account-csv) (an external method in `auth_type`) or **{{ui:users.action_auth_change}}** are already approved and skip pending approval. At the person's first external sign-in, the account is linked to the external account with the same login ID (`user.external_link`). From then on the external identifier is checked, so if the directory gives the same ID to someone else, sign-in is refused and admins get an "external identity mismatch" notification.

## Switching sign-in methods

When an external method is enabled, every row on the Users screen gets **{{ui:users.action_auth_change}}**.

- Local to external: the app password is cleared and the account is linked at the next external sign-in.
- External to local: a temporary password is shown once (valid for {{limit:auth.temp_password_expiry}}).
- You cannot switch yourself or the last active local admin.
- The user is signed out immediately and `user.auth_type_change` is recorded.

If you turn an external method off, its accounts remain but cannot sign in. Turning it back on lets them sign in as before.

## Emergency sign-in with a local admin

When the directory server or IdP is down, only sign-ins through that method fail. **Local admins can always sign in with the ID and password fields on the sign-in screen.**

- The app ensures that at least one active local admin always remains (deactivation, demotion, and sign-in method changes are all checked).
- In production, keep at least two local admins and store their passwords somewhere safe.
- If even local admins cannot get in, use `php tools/user_cli.php` on the server to unlock an account or issue a temporary password ([Managing accounts](guide:accounts)).

## Common troubleshooting

The sign-in screen never shows the detailed reason for a failure (to hide whether an account exists). The reason is recorded only as a reason code in the audit log entry `auth.login_failed`. To see the reasons, filter the [Audit log](guide:audit-log) screen by the action `auth.login_failed`.

### SSO error codes

SSO (OIDC and SAML) failures show "SSO sign-in failed" and a code on the sign-in screen.

- **E-SSO-STATE**: the sign-in request could not be verified: too old (10 minutes), reused, finished in another browser, or a SAML sign-in started from the IdP. Ask the user to click the button again on the sign-in screen. Users who habitually start from an app tile in the IdP portal need to be told.
- **E-SSO-IDP**: the IdP returned an error (consent declined, no permission to use this app, and so on). Check the app assignment in the IdP.
- **E-SSO-TOKEN**: the response failed verification: signature, time, audience, or issuer mismatch. Check the server clock, the IdP certificate or keys, and the settings.
- **E-SSO-CONFIG**: the app is not fully configured, or a secret or key is missing or unreadable. Run the connection test or settings check on the settings tab.
- **E-SSO-UNAVAILABLE**: the IdP could not be reached or timed out. Check the firewall, proxy, and DNS.
- **E-SSO-RATE**: too many sign-in start requests, or the IP is briefly blocked after failed sign-ins. It clears after a while.
- **E-SSO-DENIED**: external authentication passed but no account could be chosen: another account with the same login ID exists (local, LDAP, or linked to a different identifier), the account is inactive, or the login ID has an invalid format.

### Frequent cases

- **SSO refused because a local account has the same login ID** (E-SSO-DENIED, admin notification): if it is the same person, switch that local account to the external method with **{{ui:users.action_auth_change}}**. It is linked at the next sign-in. If it is a different person, the login ID on the IdP side has to change.
- **LDAP sign-in shows "{{ui:error.auth_service_unavailable}}"**: the directory server cannot be reached. Check the System admin > [Checks](/admin/checks) screen and the connection test on the LDAP/AD tab.
- **"Unreadable" status**: the server's encryption key changed or is missing. Save the password or secret again, or recreate the SP key.
- **A settings tab shows a migration notice**: run `php tools/migrate.php` on the server. Until then, that method behaves as if it were off.

## Related sections

- [LDAP/AD sign-in](guide:auth-ldap)
- [SSO (OIDC) sign-in](guide:auth-oidc)
- [SSO (SAML) sign-in](guide:auth-saml)
- [Managing accounts](guide:accounts)
- [Bulk accounts from CSV](guide:account-csv)
