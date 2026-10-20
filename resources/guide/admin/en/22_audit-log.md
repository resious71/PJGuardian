---
key: audit-log
title: Audit log
order: 22
summary: What the audit log records, the Audit log screen's filters, descriptions, details (summary, changed items, technical information), integrity check and CSV export, retention cleanup and the hash chain checkpoint, the server verification command, and the list of action names
keywords: audit log, audit, hash chain, integrity check, audit_verify, CSV export, retention, cleanup, action names, actor, request ID, description, changed items, no description, raw
related: monitoring, security, retention-disk, settings, troubleshooting
audience: admin
---
## What is recorded

The audit log keeps a record of administrator actions and important security events that cannot be changed. Each entry has the time (UTC), the actor (login ID, or cli or system), the action name, the target type and ID, details, the IP address and a hash. Passwords, tokens and keys are never recorded.

- **Not recorded**: successful and failed sign-ins and sign-outs go to the app log, not the audit log (only security events such as IP blocks and lockouts are recorded, as `auth.login_failed`). Defect edits and analysis cancellations are not audited either.
- **No changes or deletions**: the database refuses row updates, and the screen has no edit or delete functions. Each entry carries the hash of the previous one in a **hash chain**, so changing or removing an entry shows up in the check.

## The Audit log screen

Open {{icon:admin.audit}} {{ui:icon.admin.audit}} in the admin menu ([/admin/audit-logs](/admin/audit-logs)). Only administrators can see it, and it is read-only. The newest entries are at the top.

### Filters

- **{{ui:audit.filter.period}}**: all, the last 1, 7, 30 or 90 days, or custom (from and to dates). Dates are in the display time zone; storage is in UTC.
- **{{ui:audit.filter.actor}}**: matches part of a login ID.
- **{{ui:audit.filter.action}}**: grouped by category (users, groups, projects, backups…); each action shows a readable name together with the action name (for example "Users: Change role (user.role_change)"). You can also pick a whole category (for example "Backups: all (backup.*)").
- **{{ui:audit.filter.target}}** and **{{ui:audit.filter.q}}**: the target, and a free search (in actor, action, target and details).

Choosing a filter does not apply it. Select **{{ui:audit.apply}}** or press Enter in an input to apply. **{{ui:audit.reset}}** empties the filters. Applied filters are part of the address, so you can share them as a link, and back and forward work as expected.

### List and descriptions

From left to right the columns are **{{ui:audit.col_rule}}**, **{{ui:audit.col_summary}}**, actor, target, result, time and request ID.

- **{{ui:audit.col_rule}}** shows which action it was: it shows the technical action name (for example `user.role_change`); hover to see the category and action name (for example "Users: Change role"). Actions without description text also show the **{{ui:audit.unknown_badge}}** badge.
- **{{ui:audit.col_summary}}** says in one sentence who did what (for example "Admin alex changed the role of user Jordan Lee (jordan) from Regular user to Admin."). Long sentences show up to two lines; hover or open the details to see all of it.
- **Target** shows names instead of numbers (display name and login ID for users, project and group names, the FAQ question). A target that was already deleted shows the name kept in the record, or "Deleted item (#number)".
- **Result** is Succeeded, Failed or Requested, each with an icon of a different shape before the text (a blue check for Succeeded, a red cross for Failed, a gray clock for Requested).
- **Time** is `YY/MM/DD HH:MM` in the display time zone (for example `26/10/13 18:53`). Hover to see the full time with seconds and UTC; the details show the display time zone and UTC.
- Descriptions are made when the screen is shown; the stored audit entries and the hash chain do not change. When you change the screen language, the descriptions follow it.

### Reading the details

Select **{{ui:audit.detail_button}}** on a row. From top to bottom you see:

1. **The one-sentence summary** (large).
2. **{{ui:audit.section.changes}}**: the changed items as an item, before and after table. Item names and values use the words of the screens (for example `admin` → Admin, On/Off, "(none)" when empty, times in the display time zone). Actions that change nothing have no table.
3. **{{ui:audit.section.context}}**: the other recorded values, such as counts and reasons.
4. **{{ui:audit.section.who}}**: the actor (name and login ID), the time in the display time zone and in UTC, the source (web, CLI, worker or scheduled task, sign-in page) and the IP address.
5. **{{ui:audit.section.target}}**: the target name and a link to its screen (no link for deleted targets).
6. **{{ui:audit.section.result}}**: for a failure, the reason in words, with a link to the explanation when the code is on the guide's error codes tab.
7. **{{ui:audit.section.tech}}** (collapsed): the action name, request ID, row number, the first 8 characters of the hashes and the raw details (JSON). **{{ui:audit.copy_json}}** copies the raw details.

Masked values (values of password-like names, secrets inside strings, everything after the first letter of an email address) stay masked in both the description and the raw details; they are never unmasked. Very long values without spaces are shortened to "(long value omitted)" in the description and kept only in the raw details.

The **{{ui:audit.unknown_badge}}** badge means the action has no description text (for example an action added by a newer version of the app). You then see a general sentence such as "Action xxx was recorded." and the raw values; check the raw details under technical information. Old entries whose details are missing show a general sentence with only the action name.

### Integrity check

**{{ui:audit.integrity}}** checks the whole hash chain in chunks and shows a progress bar.

- If it is intact, it shows how many entries form an unbroken chain.
- On a mismatch, it shows the number and time of the first broken entry and the reason (it does not link to the previous entry, meaning one was removed or inserted, or its content does not match its hash).
- It also shows the boundary removed by retention cleanup (up to which number and time) and the remaining range.

Running the check is written only to the app log, not to the audit log.

### CSV export

**{{ui:audit.export}}** downloads the results of the currently applied filters as CSV (UTF-8 with BOM). The last column, "Description", holds the one-sentence summary in the screen language. The maximum is {{limit:export.max_rows}} rows; above that no file is made and you are asked to narrow the filters (`export_too_large`). Cells that could be read as formulas get a leading apostrophe. The export itself is recorded as `audit.export`.

## Retention and cleanup

- The daily cleanup job (tools/cleanup.php) removes entries older than {{limit:log.audit_retention}} from the oldest end, in batches of {{limit:log.audit_purge_batch}}. {{ui:admin_checks.cleanup_run}} on the Checks tab does the same.
- Each removal moves the chain's **checkpoint** (number, time and hash of the last removed entry), so checks of the remaining entries keep passing. A cleanup writes one `audit.purge` entry (count removed and the boundary).
- Add `--dry-run` to see only the number that would be removed.
- Change the retention under **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.retention}}**, "{{ui:config_settings.key.log.audit_retention.label}}". Shortening it below 90 days asks for one more confirmation before saving. After shortening, older entries are removed at the next cleanup and cannot be brought back.
- Before migration 030 is applied, the boundary shows only its number, without a time, but cleanup still works.

## Verifying on the server

```
php tools/audit_verify.php
php tools/audit_verify.php --db=<path to a database copy>
```

- Exit code 0: OK. Prints the last row and the last hash. If cleanup has removed entries, it says the check started from the checkpoint.
- Exit code 2: tampering detected. Prints the row id where the chain breaks and why.
- Exit code 1: execution error (for example, no database).

Anyone who can write to the database file could recompute the whole chain. So every hour (and right after retention cleanup) the worker records the number and hash of the last entry and the cleanup boundary **outside the database** (`audit-anchor.jsonl` under the logs folder of the data directory, append only), and **{{ui:audit.integrity}}** compares the chain against this record when it finishes and shows one line (matches, does not match, or no record yet). A mismatch raises an error on the Monitoring screen. Removing the last entries or recomputing the chain is caught here too. A restore takes the database back in time, so the record file is moved aside (`audit-anchor.<time>.before-restore.jsonl`) and a new one starts. Copying this file off the server regularly (for example with your backup records) is safer still. If tampering is detected, review server access records and compare with an earlier backup ([Backup and restore](guide:backup-restore)). Never edit the live database file.

A restore puts the database back to the backup time, so the audit log goes back to that point too. `restore.finish` is written to the restored database and `restore.rollback` to the database as it was before the restore.

## Action names

Action names have the form `<target>.<action>` and match the choices in the screen's {{ui:audit.filter.action}} filter. The meaning and detail items of each action are in `docs/audit_actions.md` on the server.

- **Accounts**: `user.create`, `user.update`, `user.role_change`, `user.deactivate`, `user.activate`, `user.temp_password`, `user.password_set`, `user.unlock`, `user.auth_type_change`, `user.bulk_import`, `user.pending_create`, `user.approve`, `user.reject`, `user.pending_delete`, `user.pending_purge`, `user.external_link`
- **Account recovery from the server command line**: `cli.user_unlock`, `cli.user_password_reset`
- **Authentication**: `auth.login_failed` (reason in the details), `auth.ldap_secret_set`, `auth.ldap_secret_delete`, `auth.oidc_secret_set`, `auth.oidc_secret_delete`, `auth.saml_sp_key_generate`, `auth.saml_sp_key_delete`, `auth.saml_metadata_import`
- **Groups**: `group.create`, `group.rename`, `group.update`, `group.delete`, `group.member_add`, `group.member_remove`, `group.manager_set`, `group.manager_unset`
- **Projects and Runs**: `project.create`, `project.update`, `project.delete`, `project.access_add`, `project.access_remove`, `run.detail_delete`
- **Repositories and scheduled analysis**: `repo.save`, `repo.delete`, `repo.test`, `repo.fetch_now`, `repo.host_test`, `repo_host.add`, `repo_host.update`, `repo_host.delete`, `schedule.save`, `schedule.auto_disable`
- **Rules**: `rules.override_set`, `rules.override_clear`, `rules.override_reset_all`, `rules.override_bulk`, `rules.reload_request`, `rules.load`
- **Exports**: `export.defects`, `export.rules`
- **Analysis tools**: `tool.block`, `tool.unblock`, `tool.pin`, `tool.unpin`, `tool.mark_removal`, `tool.unmark_removal`, `tool.package_staged`, `tool.package_discarded`, `tool.package_hash_set`, `tool.reverify_request`
- **Worker and checks**: `worker.start_request`, `worker.stop_request`, `worker.restart_request`, `disk.scan_request`, `cleanup.run_request`
- **Settings**: `config.update` (setting items on the Settings screen), `setting.update` (database-stored screen settings such as notification retention)
- **Backup and restore**: `backup.create`, `backup.delete`, `backup.download`, `backup.upload`, `backup.verify`, `restore.start`, `restore.finish`, `restore.rollback`, `restore.rehearsal`, `key.export`, `key.import`
- **FAQ**: `faq.create`, `faq.update`, `faq.publish`, `faq.delete`, `faq.reorder`, `faq.import` (one line per seed import, with added and skipped counts) (answers are not stored, only their length and hash)
- **Audit log**: `audit.export` (CSV export), `audit.purge` (retention cleanup)
- **Admin email**: `mail.secret_set`, `mail.secret_delete` (SMTP password — the value is never recorded), `mail.test` (test email; the recipient is masked). Automatic alert emails are written to the app log, not the audit log

## Related sections

- [System checks and monitoring](guide:monitoring)
- [Security operations](guide:security)
- [Retention and disk](guide:retention-disk)
- [Settings](guide:settings)
- [Backup and restore](guide:backup-restore)
