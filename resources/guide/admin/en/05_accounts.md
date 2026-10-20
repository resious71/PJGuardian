---
key: accounts
title: Managing accounts
order: 5
summary: Adding accounts, editing profiles, changing roles, deactivating, temporary passwords, setting passwords, unlocking, and handling pending accounts on the Users screen
keywords: users, accounts, role, admin, deactivate, temporary password, change password, unlock, pending approval, sign-in method, user_cli
related: groups-access, account-csv, external-auth, troubleshooting
audience: admin
---

## What this screen does

![The user list on the Users screen. Below the search box, the role, status and account filters and the Search button are the Login ID, Display name, Email, Role, Status, Authentication method, Groups, Account status and Actions columns. Each row has buttons to edit the profile, change the role, deactivate, change the password and issue a temporary password (sample accounts).](guide-img:admin-users)

Use **Users** in the admin menu ([/admin/users](/admin/users)) to create and manage PJGuardian accounts. Only admins can use this screen and its API. Group managers cannot open it either (for how group managers create accounts, see [Groups and project access](guide:groups-access)).

The screen has an **Add user** area at the top and the **user list** below it. Above the list are a search box (login ID, name, email) and selectors for role, status, and view. The view is one of **Accounts** (default), **{{ui:users.approval_pending}}**, and **{{ui:users.approval_rejected}}**.

The list columns are login ID, display name, email, role, status, sign-in method, groups, account state, and actions.

- **Sign-in method**: one of {{ui:account.auth_local}}, {{ui:account.auth_ldap}}, {{ui:account.auth_oidc}}, and {{ui:account.auth_saml}}. Each account has exactly one method.
- **Groups**: read-only here. Change membership on the group screens.
- **Account state**: Normal, Locked (until when), Temporary password (valid until when), Password change required, "Not signed in yet (external identity not linked)" for external accounts, or the request time for pending accounts.

Accounts are never deleted, only deactivated. The only exception is a pending or rejected account that was never approved.

## Adding an account

1. In the **Add user** area, enter the login ID, display name, email (optional), and role.
2. Click **{{ui:users.create_submit}}**.
3. Copy the temporary password from the dialog and give it to the person through a secure channel. Once you close the dialog you cannot see it again, and the server does not keep it in plain text.

- A login ID starts with a letter or digit and may contain letters, digits, `.` `_` `@` and `-` (up to 100 characters). IDs that differ only in letter case are not allowed, and an ID cannot be changed later.
- A display name is 1 to 100 characters and cannot be the same as another user's login ID (to prevent impersonation).
- The temporary password is valid for {{limit:auth.temp_password_expiry}} and must be changed at first sign-in.
- This screen creates app (local) accounts only. To pre-create external accounts, use [Bulk accounts from CSV](guide:account-csv) or the sign-in method change described below.
- The audit action name is `user.create`.

To create many accounts at once, click **{{ui:account_import.open}}** at the top of the screen.

## Editing a profile

Use **{{ui:users.action_edit}}** on a row to change the display name and email. The login ID cannot be changed.

- External (LDAP/AD and SSO) accounts are edited in the app too. External values are copied once when the account is created and are not overwritten at later sign-ins, so if a name changes in the directory, update it here.
- Input errors appear right below the field.
- Users can edit their own profile under **My account**. Admins can edit anyone's profile.
- The audit log (`user.update`) records the changed fields, with the email masked.

## Changing roles and deactivating

- **{{ui:users.action_promote}}** / **{{ui:users.action_demote}}**: applied after a confirmation dialog. The user is signed out immediately and must sign in again (`user.role_change`).
- **{{ui:users.action_deactivate}}**: the user is signed out immediately and can no longer sign in. The account and its records (assigned defects, history) stay (`user.deactivate`).
- **{{ui:users.action_activate}}**: lets a deactivated account sign in again (`user.activate`).

Defects assigned to a deactivated user keep that assignee, shown with an "Inactive" tag. The user cannot be chosen as a new assignee.

### Last admin protection

Two conditions are always kept:

- At least one active admin
- At least one active **local (app account)** admin, so that someone can still sign in with an app password when the directory or IdP is down.

The server refuses any deactivation, demotion, or sign-in method change (local to external) that would break this (`last_admin`). The buttons are still shown, but clicking them shows an error message. In production, keep at least two local admins.

## Passwords: temporary and admin-set

These actions exist for app (local) accounts only. Passwords for external accounts are managed in your organization's directory or IdP.

- **{{ui:users.action_temp}}**: the current password stops working at once and the user is signed out. Any lockout is cleared too. The temporary password is shown once in the next dialog, is valid for {{limit:auth.temp_password_expiry}}, and must be changed at first sign-in (`user.temp_password`).
- **{{ui:users.action_set_password}}**: you set a new password. The password policy applies and the user is signed out immediately. The user is not forced to change it again; at the next sign-in they see a one-time notice that an admin changed their password (`user.password_set`). You cannot change your own password with this button; use the change password screen.

Password values are never written to the audit log or logs. Hand them over through a secure channel that does not keep a history.

## Unlocking

An app account is locked for {{limit:auth.lockout_duration}} after {{limit:auth.lockout_max_failures}} consecutive failed sign-ins. A locked account shows "Locked" in the account state column and gets an **{{ui:users.action_unlock}}** button.

1. Click **{{ui:users.action_unlock}}**.
2. Confirm in the dialog. The failure count is reset and the user can sign in right away (`user.unlock`).

The app does not lock LDAP/AD accounts; the directory's lockout policy applies.

### When no admin can get in

If every admin is locked out or has lost their password, use the account recovery command on the server. It is recorded in the audit log with the actor "cli".

```
php tools/user_cli.php status <login ID>
php tools/user_cli.php unlock <login ID>
php tools/user_cli.php reset-password <login ID>
```

- `status`: shows role, status, sign-in method, lockout, and whether a temporary password is set.
- `unlock`: clears the lockout.
- `reset-password`: issues a temporary password and prints it once. It also clears the lockout and ends all of that user's sessions. Works for app (local) accounts only.

## Pending accounts

When someone signs in for the first time through external authentication (LDAP/AD, OIDC, SAML), they get a **pending account**. Until it is approved, the account is inactive and has no permissions. When pending accounts exist, the Accounts view shows an "N accounts pending approval" notice with a **{{ui:users.pending_show}}** button, and admins get an in-app notification.

1. Switch the view to **{{ui:users.approval_pending}}** (or click **{{ui:users.pending_show}}** in the notice).
2. Check the login ID, name, email, sign-in method, and request time. A **{{ui:users.name_conflict}}** tag next to the name means the display name matches another user's login ID. Fix the name with **{{ui:users.action_edit}}** first.
3. Click **{{ui:users.action_approve}}** and choose a role (regular user by default). Approving as admin asks for one more confirmation. Once approved, the person can sign in right away (`user.approve`).
4. Use **{{ui:users.action_reject}}** for requests you do not accept (`user.reject`). A rejected account stays, so the same person signing in again does not add new requests. You can still approve it later.
5. **{{ui:users.action_delete_pending}}** removes only requests that were never approved (`user.pending_delete`). If the same person signs in again, a new request appears.

- There is no bulk approval. If another admin handled the request first, you get `approval_conflict`; reload the list.
- You cannot change the role of, or activate, an account that has not been approved (`account_not_approved`).
- The limit on pending accounts and how long they are kept are described in [External authentication overview](guide:external-auth).

## Changing the sign-in method

When at least one external method is enabled, every row except your own gets **{{ui:users.action_auth_change}}**. The user is signed out at once (`user.auth_type_change`).

- **Local to external**: the app password, temporary password, and lockout data are cleared. At the next external sign-in the account is linked to the external account with the same login ID. Accounts changed this way by an admin skip pending approval.
- **External to local**: a temporary password is shown once (valid for {{limit:auth.temp_password_expiry}}, must be changed at first sign-in).
- You cannot change your own method (`self_auth_type_change`), and the last active local admin cannot be switched to an external method (`last_admin`).

## Troubleshooting

- **"The last active admin…" error**: create or activate another local admin first, then try again.
- **The temporary password was lost**: click **{{ui:users.action_temp}}** again. A new value is issued and the old one stops working.
- **The temporary password expired**: the user cannot sign in. Issue a new one.
- **An external user asks for a password change**: this cannot be done in the app. Refer them to your directory or IdP administrator.
- **Someone leaves the organization**: blocking them in the directory does not deactivate the app account (there is no periodic sync). Use **{{ui:users.action_deactivate}}** in the app as well.

## Related sections

- [Groups and project access](guide:groups-access)
- [Bulk accounts from CSV](guide:account-csv)
- [External authentication overview](guide:external-auth)
- [Troubleshooting](guide:troubleshooting)
