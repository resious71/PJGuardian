---
key: security
title: Security operations
order: 23
summary: Storing secrets and the encryption key, TLS verification policy, session and lockout policy, confirmations for irreversible actions, and hardening checks
keywords: security, encryption key, secrets.ini, key rotation, lost key, TLS, certificate verification, internal CA, session, account lockout, IP block, password policy, re-authentication, hardening, create_admin
related: backup-restore, settings, monitoring, audit-log, install, external-auth, repository
audience: admin
---
## What this covers

This section collects the security rules operators need to follow: where secrets live, how to create and rotate the encryption key, how certificates of outside connections are verified, sign-in lockout and sessions, confirmations for irreversible actions, and the hardening check before going live.

## Secrets and the encryption key

### Where secrets live

- Secrets are kept only in secrets.ini in the config folder or in environment variables. Environment variables (`PJG_` plus the key name in capitals, such as `PJG_ENCRYPTION_KEY`) take precedence over the file.
- secrets.ini holds the encryption key (the current key and, during rotation, the previous key), the SMTP password and the LDAP password. The file is not included in backup bundles and does not appear on the Settings screen.
- Restrict file permissions so that only the web server account (and the worker account) can read it. Never place it inside the web root (public).

### What the encryption key protects

The encryption key encrypts the following secrets stored in the database (AES-256-GCM). The database holds only ciphertext; screens, logs and the audit log show only a state such as set, none or unreadable.

- Credentials (tokens, passwords) of each project's repository connection
- The LDAP/AD search account password, the OIDC client secret, and the SAML SP private key and certificate

### Creating and rotating the key

If there is no key, create one on the server. The key value is written only to the file, never to the screen or logs. If a key already exists, the command refuses (overwriting it would make stored secrets undecryptable).

```
php tools/make_secret_key.php
```

To rotate the key, use `--rotate`.

```
php tools/make_secret_key.php --rotate
```

1. The new key becomes the current key and the old key moves to the previous key. Both can decrypt, so everything keeps working.
2. Restart the web server and the worker so they read the new key.
3. **Save each project's repository connection again**; it is re-encrypted with the new key. Re-enter and save the LDAP/AD, OIDC and SAML secrets as well.
4. Once everything has been saved again, remove the previous key from secrets.ini and restart.

After rotating, download a new key file with {{ui:icon.action.export_key}} in [Backup and restore](guide:backup-restore).

### If the key is lost

Stored secrets **cannot be recovered.**

- Repository connections show as unreadable and fetches fail with `repo_secret_unreadable`. Scheduled analysis skips those projects and notifies administrators.
- LDAP/AD, OIDC and SAML secrets become unreadable too and show as warnings or problems on the Checks tab.

To recover, create a new key and re-enter every project's repository credentials and the external authentication secrets. To avoid this, keep the key file (.pjgkey) and its passphrase **in a different place** from the backup bundles.

## TLS certificate verification

Outside connections verify certificates, and **there is no setting to turn verification off.**

- **Repositories (Git)**: use https. http is allowed only for hosts registered with "allow HTTP" in the allowed hosts list, and the screen shows a warning. Set an internal CA with `repository.ca_cert_file` in config.ini (when set, only that file is trusted).
- **Perforce SSL**: server fingerprints are never trusted automatically. Only a fingerprint you confirmed with the server administrator and entered in the connection settings is trusted; the connection test shows the fingerprint the server sends.
- **Allowed host connection test**: checks certificate expiry, trust and name match, and warns in the table when expiry is within {{limit:notification.cert_expiry_warn}}.
- **LDAP/AD**: use `ldaps://`, or turn on `ldap.use_starttls` with `ldap://` (plain text is not allowed). Set an internal CA with `ldap.ca_file`; if empty, the operating system's trust store is used. Host name verification cannot be turned off either.
- **OIDC**: the issuer and every request must use https. An internal CA uses the same `repository.ca_cert_file` as repositories.
- **SAML**: XML signatures in IdP responses are verified only by the library. Administrators are notified when an IdP certificate is about to expire.

The development exceptions `repository.allow_local_dev`, `ldap.allow_local_dev`, `oidc.allow_local_dev` and `saml.allow_local_dev` allow loopback addresses. **Keep them all off in production.**

Verify in your production environment: certificate verification against your real internal CA, LDAP/AD and IdP. Use each tab's connection test or configuration check.

## Sessions and sign-in lockout

These values are changed only in config.ini (they are not on the Settings screen). The values in parentheses are the current settings.

- **Session expiry**: users are signed out after {{limit:auth.session_idle}} without activity.
- **Account lockout**: {{limit:auth.lockout_max_failures}} failed sign-ins in a row lock the account for {{limit:auth.lockout_duration}}.
- **IP block**: {{limit:auth.ip_max_failures}} failures from one IP within {{limit:auth.ip_failure_window}} block it for {{limit:auth.ip_block_duration}}.
- **Password policy (local accounts)**: at least {{limit:auth.password_min_length}} characters and at least {{limit:auth.password_min_char_classes}} of uppercase, lowercase, digits and symbols.
- **Temporary passwords**: temporary passwords issued by an administrator expire after {{limit:auth.temp_password_expiry}} and must be changed at first sign-in.

Unlock accounts from Users. If an administrator is locked out, recover on the server (recorded in the audit log as `cli.user_unlock` and `cli.user_password_reset`).

```
php tools/user_cli.php status <login ID>
php tools/user_cli.php unlock <login ID>
php tools/user_cli.php reset-password <login ID>
```

A restore ends all sessions, and every user must sign in again.

## Confirmations for irreversible actions

Dangerous actions ask for an extra confirmation so they are not run by mistake.

- **Password re-entry**: downloading or deleting a backup, exporting the key, restoring. Limited to 5 attempts per minute per user.
- **Typing a confirmation**: restore ("{{ui:backups.restore.confirm_word}}"), deleting a project and deleting Run details (the project name), resetting all rules and bulk rule changes (the tool name).
- **Confirmation dialogs**: running the cleanup job, deleting an allowed host, deleting an external authentication secret, cancelling a backup and so on.
- **Protection rules**: the last successful backup cannot be deleted, and at least one active local administrator must always remain (`last_admin`).

## Hardening check

Run the check on the server before going live and after changing settings.

```
php tools/create_admin.php --check-only
```

Results are `[OK]` (met), `[WARN]` (not met — fix before going live) and `[INFO]` (guidance only). Main items:

1. **Initial administrator password**: whether the fixed initial password of the first administrator was changed. A change is not forced, so change it yourself on a server reachable from outside.
2. **PHP**: display_errors Off, expose_php Off, log_errors On.
3. **Apache**: only the public folder exposed, security headers, server version hidden, HTTP redirected to HTTPS, unused modules and /server-status removed.
4. **XAMPP samples and admin pages**: remove dashboard, phpMyAdmin, webdav and similar.
5. **App settings**: `app.env` set to prod, `security.require_https` on, `security.hsts_max_age` at least 180 days, `auth.bcrypt_cost` at least 10.
6. **Data location and the public folder**: the database, logs and uploads are outside public, and public contains no stray files (phpinfo, sqlite, ini, log, zip, bak).

Options: `--resolve-local` (connect to 127.0.0.1 instead of the host name), `--skip-http` (skip HTTP checks), `--insecure` (no certificate verification — only on development PCs with self-signed certificates). Run from the server itself, pages restricted to local access appear open, so check the HTTP items once more from another PC.

Verify in your production environment: the check results and HTTP items on a Linux and Apache production server. Review PHP, Apache, Node.js and Python security patches every quarter.

## Audit log and health check

- Administrator actions are recorded in the [Audit log](guide:audit-log) as a hash chain. Run {{ui:audit.integrity}} regularly and record the last hash outside the database.
- The health check (/health) answers without sign-in, so narrow the allowed IPs (`health.allowed_ips`) to your monitoring servers. Empty refuses everyone. Behind a proxy, block it at the proxy too ([System checks and monitoring](guide:monitoring)).

## Running behind a proxy (trusted proxies)

With a reverse proxy or load balancer in front, the app sees the proxy's address as the connecting address, and HTTPS may end at the proxy.

- Enter the proxy addresses, separated by commas, in "{{ui:config_settings.key.security.trusted_proxies.label}}" (`security.trusted_proxies`) under {{ui:admin_system.title}} > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}} (CIDR and IPv6 allowed).
- Only requests from those addresses have X-Forwarded-For (from the right — the first address after skipping proxies, nearest first) and X-Forwarded-Proto trusted. The result is used the same way for sign-in attempt limits, the IP in the audit log, the health check allowed IPs, and HTTPS enforcement and HSTS.
- The same headers from any other address are ignored (to prevent forgery). Listing an address that is not a proxy lets users fake their address, so list proxies only.
- Empty (the default) trusts no headers. If the app is behind a proxy and the list is empty, the "{{ui:env.item.trusted_proxies}}" row on the {{ui:admin_checks.title}} tab shows "Check needed".

## Related sections

- [Backup and restore](guide:backup-restore)
- [Settings](guide:settings)
- [System checks and monitoring](guide:monitoring)
- [Audit log](guide:audit-log)
- [Installation](guide:install)
- [External authentication overview](guide:external-auth)
- [Repository integration](guide:repository)
