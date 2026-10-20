# Handover checklist (P32B, spec 19.9)

Filled in together by the person handing over operations and the person taking over. Korean version: [handover_checklist.md](handover_checklist.md).
**Do not write secret values (passwords, keys, tokens, passphrases) in this checklist** — write only where they are kept and who knows them. Store the filled-in checklist in your organization's document store (do not commit it to the repository).

- Change `[ ]` to `[x]` when checked. Rows with "Check on screen" are judged automatically by PJGuardian, so open that screen as well.
- Date: ________ Handing over: ________ Taking over: ________ Server (address, OS): ________ App version (System monitoring app card): ________

## 1. Accounts, secrets, and keys

| Done | Item | Check on screen | Location / owner (fill in) |
| --- | --- | --- | --- |
| [ ] | Local administrator accounts (at least one active local admin) | User management (`/admin/users`, role admin, sign-in method local) | |
| [ ] | No administrator still has the initial password (`admin`) | Checks hardening and the admin banner (`/admin/checks`) | |
| [ ] | Location and permissions of `config/secrets.ini` (repository credential encryption key) | Checks security rows "secrets file location and permissions" | |
| [ ] | Location of the latest key file (`.pjgkey`) and its passphrase (kept in different places) | Backup & restore (`/admin/backups`) "Export key" | |
| [ ] | Server OS accounts (web server and worker accounts, administrator accounts) | — | |
| [ ] | Who can re-enter the LDAP search account password, OIDC client secret, SAML SP key, and SMTP password | Each Settings tab (values are never shown again — only "set") | |

## 2. Backup and restore

| Done | Item | Check on screen | Fill in |
| --- | --- | --- | --- |
| [ ] | Last successful backup within a day | System monitoring backup card (`/admin/monitoring`) | |
| [ ] | Backup folder on a different disk (or network storage) | "Same disk" on the same card, Settings > Backup | Location: |
| [ ] | Latest restore rehearsal result (within the recommended interval) | Backup & restore "Restore rehearsal"; the screen tells you when it is overdue | Date / result: |
| [ ] | Record of restoring to another PC (installation guide section 10) | — | Date / result: |
| [ ] | Automatic backup schedule and retention | Settings > Backup | |

## 3. Worker and cleanup

| Done | Item | Check on screen | Fill in |
| --- | --- | --- | --- |
| [ ] | How the worker is registered (service, Task Scheduler, systemd) and how to restart it | System admin > Worker (`/admin/worker`), monitoring worker card | Method: |
| [ ] | Daily cleanup (`tools/cleanup.php`) registered, last run time | Checks "Cleanup" | |
| [ ] | Active analysis tool versions (ruff, ESLint) and how `bin/` is deployed | System admin > Analysis tools (`/admin/tools`) | |

## 4. Monitoring and alerts

| Done | Item | Check on screen | Fill in |
| --- | --- | --- | --- |
| [ ] | Whether an external monitor calls `/health`, and the allowed IPs | Settings > Monitoring "Health check allowed IPs" and "Trusted proxies" | Monitor: |
| [ ] | Admin alert email recipients (whether they need to change) | Settings > Admin email, test email | Recipients: |
| [ ] | Current warnings and errors | System monitoring cards and banner | |
| [ ] | Audit log integrity check passes | Audit log (`/admin/audit-logs`) > Integrity check, `php tools/audit_verify.php` | |

## 5. External integrations

| Done | Integration | Contact on the other side (name, contact) | Notes |
| --- | --- | --- | --- |
| [ ] | LDAP/AD directory | | Settings > LDAP/AD connection test |
| [ ] | SSO (OIDC, SAML) IdP — registered callback addresses | | If `base_url` changes, update the IdP registration too |
| [ ] | Git hosts and Perforce (allowed hosts) | | Settings > Repository connections > allowed host connection test |
| [ ] | SMTP relay | | |
| [ ] | Front proxy and firewall | | Trusted proxy addresses |

## 6. Certificate expiry dates

| Done | Certificate | Expiry date | Check on screen |
| --- | --- | --- | --- |
| [ ] | Web server HTTPS certificate | | System monitoring certificate card (when `monitoring.tls_cert_file` is set) |
| [ ] | SAML IdP signing certificate and SP certificate | | Settings > SSO (SAML) certificate expiry |
| [ ] | Internal CA (for repository and LDAP verification) | | — |

## 7. Where the operating procedures are

| Done | Document | Location (fill in) |
| --- | --- | --- |
| [ ] | Installation, migration, upgrade: `docs/install_guide.md`, admin guide "Installation and environment" and "Deployment bundle" | |
| [ ] | Release and upgrade checklist (including maintenance mode): `docs/release_checklist.md` | |
| [ ] | Backup and restore: `docs/backup_restore.md` | |
| [ ] | Incident response: admin guide "Troubleshooting", `docs/worker_setup.md` section 7, `docs/monitoring.md` | |
| [ ] | Server size and capacity: `docs/server_sizing.md` | |
| [ ] | Items not yet verified: `docs/unverified.md` | |
