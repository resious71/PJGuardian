---
key: auth-ldap
title: LDAP/AD sign-in
order: 9
summary: Preparing LDAP/AD sign-in (ldap extension, migration), the fields on the LDAP/AD settings tab, the search account password, connection test, sign-in behavior, common errors, and a production checklist
keywords: LDAP, AD, Active Directory, OpenLDAP, ldaps, StartTLS, CA certificate, Base DN, search filter, search account, bind, objectGUID, entryUUID, connection test
related: external-auth, accounts, settings, security
audience: admin
---

## What this feature does

Users type their organization account (Active Directory or OpenLDAP) into the ID and password fields on the sign-in screen, and the server checks it directly against the directory server. Someone without an app account gets a pending account at first sign-in. Read [External authentication overview](guide:external-auth) first for the shared rules, such as pending approval and keeping a local admin.

## Preparation

1. **PHP ldap extension**: on XAMPP, remove the `;` from the `;extension=ldap` line in `C:\xampp\php\php.ini` and restart Apache. The worker and command line use the same php.ini. `php -m` must list `ldap`. Without the extension, the LDAP/AD tab and the [Checks](/admin/checks) screen show that the PHP ldap extension is missing, and LDAP sign-in does not work. How to install the extension on a Linux server: verify in your production environment.
2. **Migration**: run `php tools/migrate.php` to apply up to 026. Before that, LDAP behaves as if it were off even when enabled.
3. What to get from your directory administrator: the server addresses (ldaps recommended), the internal CA certificate (PEM), the Base DN to search for users, and the DN and password of a read-only **search account**.

## The LDAP/AD settings tab

Fill in the **{{ui:config_settings.group.auth_ldap}}** tab under System admin > [Settings](/admin/config-settings) and save with the **Review setting changes** button at the bottom. Everything applies from the next sign-in without a restart.

- **{{ui:config_settings.key.ldap.enabled.label}}** (`ldap.enabled`): turns it on or off. Server addresses and a Base DN are required to turn it on.
- **{{ui:config_settings.key.ldap.servers.label}}** (`ldap.servers`): 1 to 3, separated by commas. They are tried in order, and the next server is used **only when the connection fails** (a wrong password does not move on). `ldaps://` addresses are recommended.
- **{{ui:config_settings.key.ldap.use_starttls.label}}** (`ldap.use_starttls`): an `ldap://` address can only be saved with this on. Plain-text transport is not allowed.
- **{{ui:config_settings.key.ldap.ca_file.label}}** (`ldap.ca_file`): absolute path of the internal CA certificate (PEM). When empty, the operating system's trust store is used. **There is no setting to turn off certificate verification (including host name checks).**
- **{{ui:config_settings.key.ldap.base_dn.label}}** (`ldap.base_dn`): where to search for users.
- **{{ui:config_settings.key.ldap.user_filter.label}}** (`ldap.user_filter`): must contain exactly one `%s`, which is replaced by the escaped login ID.
- **{{ui:config_settings.key.ldap.bind_dn.label}}** (`ldap.bind_dn`): the search account. The audit log and change history show a masked value instead.
- **{{ui:config_settings.key.ldap.attr_login.label}}** (`ldap.attr_login`): the attribute that becomes the app login ID.
- **{{ui:config_settings.key.ldap.attr_name.label}}** (`ldap.attr_name`), **{{ui:config_settings.key.ldap.attr_email.label}}** (`ldap.attr_email`): read once, when the pending account is created.
- **{{ui:config_settings.key.ldap.attr_id.label}}** (`ldap.attr_id`): a value that stays the same when the name changes. The DN is not used because it can change.
- **{{ui:config_settings.key.ldap.attr_disabled.label}}** (`ldap.attr_disabled`): `userAccountControl` for AD (sign-in is refused when the disabled bit is set); leave empty for OpenLDAP.
- **{{ui:config_settings.key.ldap.connect_timeout.label}}** (`ldap.connect_timeout`, currently {{limit:ldap.connect_timeout}}), **{{ui:config_settings.key.ldap.search_timeout.label}}** (`ldap.search_timeout`, currently {{limit:ldap.search_timeout}}): how long to wait for each server.

Example values:

- AD: servers `ldaps://dc1.example.local:636, ldaps://dc2.example.local:636`, Base DN `OU=Users,DC=example,DC=local`, default filter and attributes, disabled attribute `userAccountControl`
- OpenLDAP: filter `(&(objectClass=inetOrgPerson)(uid=%s))`, login ID attribute `uid`, stable identifier attribute `entryUUID`, disabled attribute empty

### Search account password

Enter it in the **{{ui:ldap.secret.label}}** field on the same tab and click **{{ui:ldap.secret.save}}**. It is saved separately from the settings, stored encrypted in the database, and never shown again. The status shows only saved, none, or unreadable. Use **{{ui:ldap.secret.replace}}** to change it (`auth.ldap_secret_set`; deletion is `auth.ldap_secret_delete`). Backups contain it encrypted, without the encryption key.

## Connection test

**{{ui:ldap.test.run}}** at the bottom of the tab checks the following in order using the **saved settings**. If a step fails, the later steps are skipped.

1. {{ui:ldap.test.step.connect}}
2. {{ui:ldap.test.step.service_bind}}
3. {{ui:ldap.test.step.base_dn}}
4. {{ui:ldap.test.step.user_search}}, only when **{{ui:ldap.test.login_label}}** is filled in. No password is asked for, and the DN, identifier, and email found are shown partly masked.

Each admin can run it {{limit:ldap.test_per_minute}} times per minute. When LDAP is enabled, the [Checks](/admin/checks) screen also shows an LDAP/AD item (extension, missing settings, search account password, CA file, connection).

## Sign-in behavior

- **Local accounts**: always checked against the app password. The directory is never asked.
- **An ID with no app account**: after a successful directory check, a pending account is created (no session).
- **Approved LDAP accounts**: after the directory check, the stable identifier must match the stored value. An account pre-created by an admin gets its identifier linked at first sign-in.
- **With LDAP turned off**, LDAP accounts get the generic failure.

The directory steps are: bind as the search account → search with the filter (only exactly one match is accepted) → bind as the found DN with the entered password → check the disabled flag. Empty and excessively long passwords fail without asking the directory (so that an anonymous bind is never mistaken for success).

### The same message, for security

A missing ID, a wrong password, no match, more than one match, and an account disabled in AD all show the same failure message. If the directory cannot be reached, users see "{{ui:error.auth_service_unavailable}}".

### Failure limits

- The IP block rule applies to LDAP too.
- The app's account lockout does not apply to LDAP accounts (the directory's policy applies). Instead, after twice the lockout threshold ({{limit:auth.lockout_max_failures}}) of failures for the same login ID within {{limit:auth.ip_failure_window}}, sign-ins are briefly refused without asking the directory. This keeps the directory account from being locked out.

## Common errors

Use the failure reason from the connection test to narrow down the cause.

- "{{ui:ldap.reason.unavailable}}": check the address, port, firewall, whether the server certificate's name matches the address, and the CA file. Using an IP address can fail because it does not match the certificate name.
- "{{ui:ldap.reason.service_bind_failed}}": check the search account DN and password. This error does not move on to the next server.
- "{{ui:ldap.reason.search_failed}}": check the Base DN and attribute names.
- "{{ui:ldap.reason.ambiguous}}": the filter is too broad.
- "{{ui:ldap.reason.not_found}}": check the Base DN scope and the attribute name in the filter (`sAMAccountName` for AD, `uid` for OpenLDAP).
- "{{ui:ldap.reason.secret_unreadable}}": the server's encryption key changed. Save the password again.

Sign-in failure reasons are recorded in the audit log entry `auth.login_failed`: `local_account` (a local account), `invalid_login_id` (does not meet the login ID rules), ambiguous (more than one match), `directory_disabled` (disabled in the directory), `identity_mismatch` (same ID but a different identifier; possible ID reuse, admins are notified), and `identity_conflict` (the identifier is already linked to another account). Passwords, DNs, and filter text are never recorded.

## Production checklist

This feature was tested only against a fake directory. Verify in your production environment:

1. `php -m` lists ldap, and after restarting Apache the LDAP/AD tab shows no extension warning.
2. Server addresses (ldaps), CA file, Base DN, and search account DN are saved, and the search account password shows "saved".
3. The connection test with a test login ID passes all four steps, and the DN and identifier format (a GUID for AD) look right.
4. Clearing the CA file or pointing it to a wrong file makes the connection fail (verification is on). Changing the address to one that does not match the certificate name also fails.
5. Signing in as a directory user with no app account shows the pending notice, and the Users screen shows one pending account and a notification. After approval, that person can sign in.
6. A wrong password and a missing ID show the same message. An empty password is blocked by the sign-in button.
7. Disabling the account in AD refuses sign-in from the next attempt.
8. Deleting an ID and recreating it for someone else refuses sign-in and sends an "external identity mismatch" notification.
9. Stopping the first server lets sign-in go through the second. A wrong search account password does not move on and shows a bind failure.
10. A local admin can still sign in with the app password while the directory is down.

## Related sections

- [External authentication overview](guide:external-auth)
- [Managing accounts](guide:accounts)
- [Settings](guide:settings)
- [Security](guide:security)
