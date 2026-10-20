---
key: retention-disk
title: Retention and disk
order: 13
summary: What gets deleted and when, running the daily cleanup job, disk warning and block thresholds, and checking logs
keywords: retention, cleanup job, cleanup, disk, warning percent, block percent, snapshot, work folder, trash, logs, Run detail retention
related: settings, monitoring, audit-log, backup-restore, projects-admin, worker
audience: admin
---
## What this covers

PJGuardian deletes files that are no longer needed — upload chunks, zip files, work folders, logs, code snapshots — after set periods. This section explains what is deleted and when, how to run the cleanup job, and what is blocked when the disk fills up.

Retention values are changed on **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.retention}}** ([/admin/config-settings#retention](/admin/config-settings#retention)). The values in parentheses below are the current settings.

## What the cleanup job deletes

Each run of the cleanup job (tools/cleanup.php) handles the following.

- **Unfinished upload chunks**: deleted {{limit:upload.incomplete_chunk_retention}} after the last activity; the upload is marked as aborted.
- **Zip files of failed or cancelled uploads and analyses**: kept for {{limit:upload.failed_zip_retention}} so the cause can be investigated, then deleted. Leftover zip files of uploads whose analysis succeeded are deleted right away.
- **Uploads waiting for warning confirmation**: cancelled if not confirmed within {{limit:upload.confirmation_retention}}.
- **Work folders and trash**: work folders left behind by abnormal exits are removed and the trash is emptied.
- **Files that could not be deleted**: files that were locked earlier (cleanup_pending) are retried.
- **Error and access logs**: log files (including the alert log) older than {{limit:log.error_access_retention}} are deleted. The audit log in the database is cleaned up separately (below).
- **Analysis result files and temporary defect stores**: deleted after one day.
- **Code snapshots**: each project keeps full snapshots only for its last {{limit:snapshot.full_keep_count}} analyses. Older analyses keep only the excerpts around defects; their full snapshots are deleted.
- **Tool package staging**: staged copies of tool packages uploaded on the admin screen (not installed, or already installed) are deleted after {{limit:tools.staging_retention}}.
- **Audit log**: entries older than {{limit:log.audit_retention}} are removed and the hash chain checkpoint is moved. Change the retention in the {{ui:config_settings.group.retention}} tab, "{{ui:config_settings.key.log.audit_retention.label}}"; shortening it below 90 days asks for one more confirmation before saving. See [Audit log](guide:audit-log).
- **Pending and rejected accounts**: accounts created by a first external sign-in that are still pending or were rejected are deleted after {{limit:auth.pending_retention_days}} days.
- **Work of a dead worker**: if the worker heartbeat has stopped and its lock is released, the remaining analyses are marked as failed.

Some things are not handled by the cleanup job.

- **Notifications**: the worker cleans them up separately every hour, using the notification retention periods described in [Admin notifications](guide:notifications-admin).
- **Backup bundles**: pruned by the retention rules after a successful automatic backup. See [Backup and restore](guide:backup-restore).
- **Past Run details**: after each successful analysis, Run details beyond the per-project limit ({{limit:runs.detail_keep}}) are closed. Manual deletion is described in [Project administration](guide:projects-admin).
- **Defects and defect history**: never deleted.

## Running the cleanup job

The cleanup job must run **once a day**. The app does not schedule it itself, so register it with the server's scheduler.

1. Register the command below to run daily — Task Scheduler on Windows, cron or a systemd timer on Linux. The account must be able to write to the data folders (the same account as the worker is recommended).

```
php tools/cleanup.php
```

2. To see what would be deleted first, add `--dry-run`. Nothing is deleted; only counts are shown.

```
php tools/cleanup.php --dry-run
```

3. Confirm the schedule works under "{{ui:admin_checks.cleanup_title}}" on **System admin > {{ui:admin_checks.title}}** ([/admin/checks](/admin/checks)), which shows the last run time and result. If it says "No run history", check the scheduler entry.
4. To clean up right now, click {{ui:admin_checks.cleanup_run}} in the same place. The worker runs the job, so the worker must be on. Deleted files cannot be recovered.

Exit codes: 0 on success, 2 on error. Verify in your production environment: the cron or systemd timer entry and the account permissions on Linux.

## Disk warning and block

Disk usage (the share of the disk in use) is compared with two thresholds. Change them on the {{ui:config_settings.group.disk}} tab; the warning percentage must be lower than the block percentage.

- **Warning** (at {{limit:disk.warn_percent}} or more): a warning is logged and the disk bar on the Checks tab turns to warning. Work continues.
- **Block** (at {{limit:disk.block_percent}} or more): new uploads are refused (507 `disk_usage_blocked`). Repository fetches are subject to the same disk check, and scheduled analysis skips that run and notifies administrators. If the disk holding the backup folder is above this level, backups are not made either (`backup_disk_full`).

Separately from the usage percentage, starting an upload reserves enough space for extraction. If there is not enough free space, the upload is refused or the analysis fails with `insufficient_storage`.

### When space runs low

1. On the Checks tab, click {{ui:admin_checks.disk_scan}} to compute usage by category (upload chunks, zip files, work folders, result files, snapshots, trash, logs, backups, database and so on). With many files this can take a few minutes.
2. Start with the largest category. If uploads, work folders or trash are large, click {{ui:admin_checks.cleanup_run}}.
3. If snapshots are large, lower the snapshot retention count on the {{ui:config_settings.group.retention}} tab. It applies from the next cleanup.
4. If backups are large, move the backup folder to another disk or tighten the retention rules ([Backup and restore](guide:backup-restore)).
5. If logs are large, shorten the log retention period.

Deleting Run details hardly frees any disk space. For the manual procedure to shrink the database file, see [Project administration](guide:projects-admin).

## Checking logs

- The app log is written as one JSON object per line to app-YYYYMMDD.log (UTC date) under logs in the data folder. The web server and the worker write to the same file. Each line has the time, level, request ID, analysis job ID, user ID and message; passwords and tokens are masked.
- Recent log lines are also shown under "{{ui:admin_worker.log_title}}" on **System admin > {{ui:admin_worker.title}}**, where you can choose worker-only or everything including web.
- The log level is set by `log.level` in config.ini (not changeable on screen).
- The cleanup job deletes log files after {{limit:log.error_access_retention}}. Copy them elsewhere before then if you need them longer.

## Cautions

- Shortening a retention period makes the next cleanup delete everything past the new limit at once. Check the counts with `--dry-run` first.
- Zip files of failed uploads are used to investigate problems. If you keep them too briefly, you cannot look into user reports.
- The default backup folder is on the same disk as the operating data. A full disk stops backups too, so move it to another disk in production.

## Related sections

- [Settings](guide:settings)
- [System checks and monitoring](guide:monitoring)
- [Audit log](guide:audit-log)
- [Backup and restore](guide:backup-restore)
- [Project administration](guide:projects-admin)
- [Worker](guide:worker)
