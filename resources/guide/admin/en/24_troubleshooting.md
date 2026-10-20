---
key: troubleshooting
title: Troubleshooting
order: 24
summary: Step-by-step checks for sign-in problems, analyses stuck in the queue, failed uploads, SSO errors, settings that will not save, and low disk space
keywords: troubleshooting, sign-in, locked, unlock, temporary password, pending approval, queued, worker, upload failed, SSO, E-SSO, saving settings, conflict, low disk space, cleanup
related: worker, accounts, external-auth, settings, retention-disk, monitoring, audit-log
audience: admin
---

## Where to look first

Whatever the symptom, look here first.

- {{ui:icon.admin.tools}} > {{ui:admin_worker.title}}: whether the worker is running, how many jobs are waiting, recent logs
- {{ui:icon.admin.tools}} > {{ui:admin_checks.title}}: configuration and environment checks, disk usage, cleanup, recently failed analyses
- The error code the user saw: look up its meaning and the fix on the [Error codes](/guide/errors) tab.

## When someone cannot sign in

The sign-in failure message is deliberately identical for an unknown ID, a wrong password, and a locked or inactive account, so an administrator has to check the account.

1. Find the user in {{ui:icon.admin.users}} and check the status.
2. "Locked": {{limit:auth.lockout_max_failures}} failed sign-ins in a row lock the account for {{limit:auth.lockout_duration}}. It unlocks when that time passes; to unlock it now, select {{ui:users.action_unlock}}.
3. Forgotten password: select {{ui:users.action_temp}}. The temporary password is valid for {{limit:auth.temp_password_expiry}} and must be changed at first sign-in; if it expired, issue a new one.
4. An inactive account has to be made active before it can sign in.
5. Several accounts failing at once may be an IP block: {{limit:auth.ip_max_failures}} failures from one IP within {{limit:auth.ip_failure_window}} block it for {{limit:auth.ip_block_duration}}, after which it lifts.
6. Being signed out after no use is not a fault: sessions end after {{limit:auth.session_idle}} without activity.

### Externally authenticated accounts

- A first sign-in with external authentication (LDAP/AD, SSO) creates an account waiting for approval, with no session. It can sign in only after you select {{ui:users.action_approve}} in the pending list in {{ui:icon.admin.users}}.
- When {{limit:auth.pending_max}} accounts are waiting, new requests are not accepted. Approve or delete older requests.
- Local accounts cannot sign in through external authentication. Check the account's authentication method.
- If the directory cannot be reached, only LDAP/AD sign-ins fail. Check with {{ui:ldap.test.run}} on {{ui:config_settings.title}} > {{ui:config_settings.group.auth_ldap}}. See [LDAP/AD authentication](guide:auth-ldap) for details.

### When no administrator can sign in

Use the account recovery commands on the server (recorded in the audit log).

```
php tools/user_cli.php status <login ID>
php tools/user_cli.php unlock <login ID>
php tools/user_cli.php reset-password <login ID>
```

`reset-password` shows the temporary password once and ends all sessions of that account.

## When analyses seem stuck in the queue

1. Check the worker on the {{ui:admin_worker.title}} tab. Analyses, upload assembly and extraction, queuing scheduled analyses and admin tasks all need it.
2. If it is stopped, start it. The start button appears only when a start command is registered in the server settings; otherwise run the worker on the server ([Running the analysis worker](guide:worker)).
3. "Not responding" (process hung or heartbeat stopped): check on the server with the commands below and clean up jobs left by a dead worker.
4. If the worker is fine but jobs wait long, check the queue rules below.

```
php tools/worker.php --status
php tools/worker.php --check
```

- Queue: at most {{limit:analysis.max_concurrent}} analyses at once; a project's next analysis starts only after its previous one finishes. One analysis can run up to {{limit:analysis.timeout}}.
- A scheduled analysis "waiting to retry" tries again after {{limit:repository.connect_retry_interval}} ([Scheduled analysis](guide:schedule)).
- After a worker crash, analyses it was running are failed with `worker_crashed` on the next start. There is no automatic retry; the user uploads again.

## When uploads fail

Most upload errors are zip content problems that users fix from the code and details link on their screen. Administrators should check:

- Refused right at the start: check the upload size limit {{limit:upload.max_zip_size}}, the per-user limit of {{limit:upload.max_concurrent_per_user}} concurrent uploads, and the disk thresholds (`disk_usage_blocked`, `insufficient_storage`).
- Stays at "waiting to assemble": the worker does assembly and extraction, so check the worker.
- `io_error`: the server could not write files. Check free disk space and permissions on the data folder.
- `extract_timeout`, `extract_crashed`: usually very many files or too little memory. Check the recent log on the {{ui:admin_worker.title}} tab.
- `extract_limit`: the extracted size exceeded {{limit:extract.max_extracted_size}} or {{limit:extract.max_files}} files. Ask the user to leave out build output and dependency folders. If the limits are smaller than the real source, raise them on the **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}** tab (the upload zip limit is there too).

Chunks of an interrupted upload are kept {{limit:upload.incomplete_chunk_retention}} for resuming, then cleanup removes them.

## SSO sign-in errors

A failed SSO sign-in shows the user "SSO sign-in failed" with a code such as `E-SSO-STATE`. The detailed reason is only in the audit log (`auth.login_failed`), never on screen.

- `E-SSO-STATE`: the sign-in request expired or came back to a different browser. Ask the user to start again in the same browser.
- `E-SSO-IDP`: the identity provider (IdP) refused (consent denied, no permission for the app, and so on). Check the IdP's settings and the user's permissions.
- `E-SSO-TOKEN`: the response failed verification (signature, validity time, audience). Check the server clock and the IdP certificate and settings.
- `E-SSO-CONFIG`: SSO is off or not fully configured. This also covers an OIDC client secret that is missing or cannot be read.
- `E-SSO-UNAVAILABLE`: the IdP cannot be reached. Check the network and the IdP.
- `E-SSO-RATE`: too many attempts in a short time. Try again shortly.
- `E-SSO-DENIED`: authentication succeeded but the account cannot be linked (another account uses the same login ID, the account is inactive, and so on). Check the account in {{ui:icon.admin.users}}.

Check in this order:

1. In {{ui:config_settings.title}}, use {{ui:oidc.test.run}} on the {{ui:config_settings.group.auth_oidc}} tab or {{ui:saml.test.run}} on the {{ui:config_settings.group.auth_saml}} tab to check the settings and the connection.
2. Look for warnings in the SSO items on the {{ui:admin_checks.title}} tab.
3. Even while the IdP is down, local administrators can sign in with their ID and password on the sign-in screen.

Verify in your production environment how the integration behaves with your real IdP. See [SSO (OIDC)](guide:auth-oidc) and [SSO (SAML)](guide:auth-saml) for details.

## When settings will not save

Saving in {{ui:config_settings.title}} fully succeeds or fully fails (if anything is wrong, nothing changes).

- Format or range errors: the screen moves to the item on the first tab with an error and shows why. Correct it and save again.
- `setting_cross_check`: items do not agree with each other. For example, the disk warning threshold must be lower than the block threshold, and the number of Runs whose details are kept must be 0 or at least 3.
- `settings_conflict`: another administrator saved first. Reload the screen and enter your changes again.
- `setting_env_locked`: set by a server environment variable, so read-only on screen. Change the variable on the server.
- `setting_path_invalid`: the path check failed. Check that it is an absolute path, that the folder exists, and that the web server account can write to it.
- `setting_busy`: a backup or restore is in progress, so the backup folder cannot be changed. Try again when it finishes.
- Saving itself fails: the on-screen settings file (`config/settings.local.ini`) could not be written. Check that, as the policy says, the web server and worker accounts can write the `config/` folder ([Installation and environment](guide:install)).

Allowed hosts and notification retention are saved with their own section buttons. Items that need a restart show a notice after saving ([Settings](guide:settings)).

## When disk space runs low

Above {{limit:disk.warn_percent}} usage you get a warning; above {{limit:disk.block_percent}} new uploads are refused (`disk_usage_blocked`). With too little free space, analyses also fail to start (`insufficient_storage`) and scheduled analyses skip that run.

1. On the {{ui:admin_checks.title}} tab, check usage and free space, and select {{ui:admin_checks.disk_scan}} to calculate usage by category (the worker must be running).
2. Check when cleanup last ran. With no record, check the daily cleanup schedule on the server; to clean up now, select {{ui:admin_checks.cleanup_run}}.
3. Shorten the retention periods for the categories that take the most space ({{ui:config_settings.title}} > {{ui:config_settings.group.retention}}). If backups are large, keep fewer of them or move the backup folder to another disk.
4. If the database file is still large after deleting projects, stop the service and shrink the database by hand. Deleting Run details hardly frees any space.

For reading each item see [System checks and monitoring](guide:monitoring); for thresholds and retention items see [Retention and disk](guide:retention-disk).

## When saving briefly fails (`db_busy`)

While the analysis results of a very large project (hundreds of thousands of defects) are being saved for a few seconds, other writes (defect edits, sign-in) that wait longer than {{limit:database.busy_timeout}} show "Timed out waiting for another large task to finish" (`db_busy`, 503). Nothing was saved, so do the same thing again a few seconds later. If it happens often, reduce the number of defects in the project (exclude patterns, the defect count warning), and if it is still frequent the server administrator can consider raising `database.busy_timeout` to 10s. Measurements and recommended settings are in `docs/performance.md` on the server.

## If the problem remains

- Look for error lines in the recent logs on the {{ui:admin_worker.title}} tab. Server paths and secret values are masked.
- If you edited the server configuration file directly, validate it (errors print their cause) and check the environment:

```
php tools/config_check.php
php tools/check_env.php
```

- Note the error code and time; they help find the same request in the server logs.
