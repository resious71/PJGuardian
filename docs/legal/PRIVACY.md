# Privacy

PJGuardian is self-hosted software. This page describes what an installation stores and where data can go, so that the **operator** (whoever runs the server) can inform users. The operator is the data controller; informing users and complying with applicable laws and policies is the operator's responsibility. This document is not legal advice.

## No telemetry

PJGuardian does not send usage data, crash reports or analytics anywhere. There is no "phone home", no update check and no external script or font: all browser assets are served from the same server and the Content-Security-Policy allows only `'self'` for scripts and connections.

The code paths that open network connections were listed from the source (P33) and **all of them go only to servers the administrator configured**:

| Feature | Code | Destination |
| --- | --- | --- |
| Git / Perforce import and scheduled analysis | `src/Services/Repo/GitClient.php`, `PerforceClient.php` (runs `bin/git`, `bin/p4`) | repository hosts on the admin's allowed-host list |
| Allowed-host connection test | `src/Services/Repo/HostProbe.php` | the host being tested (admin action) |
| LDAP / Active Directory login | `src/Services/Auth/Ldap/ExtLdapTransport.php` | the directory servers in the LDAP settings |
| SSO (OpenID Connect) | `src/Services/Auth/Oidc/OidcHttp.php`, `StreamHttpTransport.php` | the configured issuer (discovery, keys, token endpoint) |
| SSO (SAML) metadata import | `src/Services/Auth/Saml/SamlAdmin.php` (through the same HTTP client) | the metadata URL an admin enters |
| Admin alert e-mail | `src/Services/Mail/SmtpMailSender.php` (PHPMailer) | the configured SMTP server and recipients |
| Hardening check (CLI only) | `src/Support/HttpProbe.php` via `tools/create_admin.php --check-only` | the installation's own address |

The analysis tools (ruff, ESLint) run locally on the server and do not need network access.

## What is stored

| Data | Where | Retention (default, setting key) |
| --- | --- | --- |
| Accounts: login ID, display name, e-mail, role, group membership, authentication method, external identity (issuer/subject or directory ID), password hash (bcrypt, local accounts only), UI language and theme | database | kept while the account exists (admins deactivate accounts; only pending sign-ups can be deleted); pending external sign-ups `auth.pending_retention_days` (30 days) |
| Sessions (only a SHA-256 hash of the session ID, last activity) | database | idle timeout `auth.session_idle` (30 minutes) |
| Uploaded source code (zip) and extracted working copies | `data/uploads`, `data/work` | removed after processing; incomplete uploads `upload.incomplete_chunk_retention` (24 h), failed zips `upload.failed_zip_retention` (1 day) |
| Source snapshots of analyzed files (for the code viewer and history) | `data/snapshots` | full snapshots per project `snapshot.full_keep_count` (5), Run details `runs.detail_keep` (15) |
| Analysis results, defects, assignees, severity/status change history | database | as long as the project exists |
| In-app notifications | database | read 7 days, unread 30 days, at most 1,000 per user (admin-configurable in System admin > Settings > Notifications) |
| Audit log (who did what, when, from which IP, with sensitive values masked) | database + hash anchor file `logs/audit-anchor.jsonl` | `log.audit_retention` (90 days) |
| Application, error and access logs (one-line JSON: time, level, request ID, user ID, message; passwords and tokens masked; failed-login IDs not logged in clear) | `data/logs` | `log.error_access_retention` (30 days) |
| Backups (database, snapshots, configuration without secrets) | backup folder | `backup.retention_days` (60 days) and related settings |
| Secrets: repository credentials, LDAP bind password, OIDC client secret, SAML SP key, SMTP password | database, encrypted with a key stored outside the web root | until an admin removes them |

The browser keeps only small UI conveniences in local storage (for example, the selected rules tab or upload options) and a session cookie (`SameSite=Lax`, `HttpOnly`).

## Deleting data

- Accounts: an admin deactivates accounts in **Users**; deactivated accounts cannot sign in. Audit entries that reference them are kept until the audit retention period ends (the log is append-only by design).
- Projects: deleting a project (an admin types the project name to confirm) permanently removes its analyses, defects, snapshots and upload/work files.
- Everything: stop the web server and worker and delete the `data/` folder, the database and the backup folder.

## Operator checklist

- Tell users what is collected (this page) and who can see projects (project access mappings).
- Restrict who can read the server's `data/`, backup folder and `config/secrets.ini`.
- Review retention settings in **System admin > Settings**.

[한국어 요약] PJGuardian은 사용 통계·오류 보고를 어디에도 보내지 않습니다(텔레메트리 없음). 외부 연결은 관리자가 설정한 저장소 호스트·LDAP·OIDC/SAML·SMTP뿐입니다. 저장하는 데이터와 보존 기간은 위 표와 같고, 사용자에게 알리고 법을 지키는 것은 운영자의 책임입니다.
