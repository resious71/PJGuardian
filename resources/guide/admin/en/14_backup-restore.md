---
key: backup-restore
title: Backup and restore
order: 14
summary: Manual and automatic backups, retention rules, exporting the encryption key, restoring on screen or from the command line, restore rehearsals and post-restore checks
keywords: backup, restore, automatic backup, manual backup, pjgbak, pjgkey, encryption key, export key, passphrase, restore rehearsal, maintenance mode, move to another PC
related: settings, retention-disk, security, notifications-admin, troubleshooting
audience: admin
---
## What this screen is for

**System admin > {{ui:backups.title}}** ([/admin/backups](/admin/backups)) bundles the database, settings and code snapshots into a backup and restores from such a bundle. The command-line tools (tools/backup.php, tools/restore.php) do the same without a web server. There are two purposes:

- Regular backups to recover from incidents
- **Moving** the current data and settings to another PC

Backups, restores and rehearsals are all run by the worker. If the worker is stopped, a warning appears at the top and nothing proceeds.

## What a backup bundle contains

A backup bundle is a zip file with the .pjgbak extension, named after its creation time and kind (for example pjguardian-20261010-023000-daily.pjgbak).

- The whole database (a consistent point-in-time copy, even while writes continue)
- Copies of config.ini and the screen settings (settings.local.ini) — **sensitive values such as passwords, tokens and keys are blanked**
- The checksum list of tools in the bin folder (executables are not included)
- Code snapshots (optional, included by default)
- A manifest with the size and SHA-256 of every entry

**Not included**: secrets.ini (encryption key, SMTP and LDAP passwords), original uploads, work folders and logs. Keep the encryption key that unlocks repository credentials separately with "{{ui:icon.action.export_key}}".

A bundle holds the entire database, including accounts and defects. Store it somewhere safe when you download or move it.

## Making backups

### Manual backup

1. Click {{ui:icon.action.backup_now}}. Turn off "{{ui:backups.snapshots_option}}" for a smaller bundle without snapshots.
2. Watch the progress (copy database → copy snapshots → build bundle → verify). Use {{ui:backups.job.cancel}} to stop it; the partial file is deleted.
3. When it finishes, it appears in the list as "{{ui:backups.kind.manual}}" and the administrator who requested it gets a backup-done notification.

Each backup is verified right after it is made (structure, hashes, database integrity, counts). If verification fails, the bundle is deleted and the backup is recorded as failed.

### Automatic backup

Automatic backups are configured on **{{ui:config_settings.title}} > {{ui:config_settings.group.backup}}** ([/admin/config-settings#backup](/admin/config-settings#backup)).

- Choose on/off, the cycle (daily, weekly, monthly), the weekday (weekly), the day of month (monthly: 1–31 or the last day) and the time (currently {{limit:backup.daily_at}}, display time zone). If the day does not exist in a month, the last day of that month is used.
- The worker checks every 30 seconds and runs one backup if none has run since the most recent scheduled time. Even if the worker was off long enough to miss several runs, it runs **only once**.
- On failure it retries once after 10 minutes. If that also fails, administrators get a backup-failed notification. The same reason is notified at most once an hour.
- Changing the schedule does not start a backup right away; the new rule applies from its **next** scheduled time.
- Turning it off stops automatic backups, retries, failure notifications and **automatic pruning**. Manual backups, restores and rehearsals still work.
- Automatic backups appear in the list as "{{ui:backups.kind.daily}}".

### Backup folder

The default backup folder is backups directly under the project. It is on the **same disk** as the operating data, so a disk failure loses both. The screen always warns about this; in production, move it to a separate disk or network drive. See the path items in [Settings](guide:settings). Existing bundles are not moved when you change the folder.

A .pjgbak file copied directly into the backup folder also shows up in the list. This is the easiest way for large bundles.

## Retention rules

After a successful automatic backup (only while automatic backups are on), old bundles are pruned.

- Automatic and manual backups are deleted after {{limit:backup.retention_days}} days, but the latest {{limit:backup.retention_min_count}} are always kept.
- If a maximum count is set ({{limit:backup.retention_max_count}}; 0 means no limit), older bundles beyond it are deleted as well.
- Pre-restore and pre-upgrade backups are kept for {{limit:backup.safety_retention_days}} days.
- **The last successful backup is always kept.** Bundles that failed verification or have no verification record are never pruned automatically.
- With a longer cycle (weekly or monthly), fewer bundles remain in the same period, so lengthen the retention. "{{ui:backups.sum.estimate}}" in the tab summary estimates the space needed.

If the last backup is too old ({{limit:backup.backup_stale_hours}} hours for daily backups), a warning appears on screen.

## Exporting the encryption key

Repository credentials are stored in the database, encrypted with the key in secrets.ini. The key is not in the bundle, so save a key file (.pjgkey) separately in case you move to another PC or lose the key.

1. Click {{ui:icon.action.export_key}}.
2. Re-enter your administrator password and enter a passphrase (12 characters or more) twice.
3. Store the downloaded .pjgkey file **in a different place from the backup bundles**.

The passphrase is not stored anywhere. **If you lose the passphrase, the key file cannot be opened.** There is no command-line key export.

## Restoring (on screen)

1. Click {{ui:icon.action.restore}} on the bundle in the list. A bundle made on another PC must first be uploaded with "{{ui:backups.upload.title}}" and pass {{ui:icon.action.verify}} (up to {{limit:backup.upload_max_mb}} MB).
2. **Pre-check**: the bundle structure, hashes, database integrity and schema are checked. Bundles with a schema newer than the app are refused.
3. **Preview**: check the backup time, source PC and versions, current versus backup counts, and "changes after this time will be lost" (later analyses, defect changes and account changes).
4. **Restore settings (optional, off by default)**: when on, items whose current value differs from the backup are listed. Per-PC items such as the backup folder are unchecked by default. secrets.ini is never changed.
5. **Encryption key**: shows whether the current key can unlock the credentials in the backup. If not, use "import key" with the .pjgkey file and passphrase. The key is replaced only after it actually unlocks the backup's credentials; the current key is kept as the previous key.
6. **Running work**: wait for it to finish (default, up to {{limit:backup.restore_wait_max_minutes}} minutes) or cancel running analyses and restore right away.
7. **Confirm**: enter your administrator password and the confirmation word "{{ui:backups.restore.confirm_word}}", then click {{ui:backups.restore.submit}}.

You are taken to the restore status page (/restore-status). The worker then proceeds in this order:

1. It enters maintenance mode and takes no new work. Every other page shows the status page meanwhile.
2. It makes a **pre-restore backup** (kind "{{ui:backups.kind.pre_restore}}"). If this fails, the restore does not start. To undo a restore later, restore from this bundle.
3. It replaces the database, and the snapshot folder if the bundle includes snapshots.
4. If the bundle's schema is older than the app, it runs migrations.
5. It **ends all sessions** and applies the selected settings and the imported key.

If anything fails along the way, the original database is put back automatically (status "{{ui:restore_status.status.rolled_back}}"). If even that fails, the status page shows manual recovery steps. In that case stop the web server and the worker, put the files back as instructed, and start them again.

## Command-line backup and restore (moving to another PC)

On the old PC, make a bundle and download a .pjgkey with {{ui:icon.action.export_key}} on screen.

```
php tools/backup.php
php tools/backup.php --list
php tools/backup.php --verify
php tools/backup.php --kind=pre_upgrade
```

On the new PC, put the code in place, create config.ini for this PC (and create a key with tools/make_secret_key.php if there is none), then restore. A command-line restore is refused while the worker is running.

```
php tools/restore.php --file=<bundle path> --dry-run
php tools/restore.php --file=<bundle path> --check-bin
php tools/restore.php --file=<bundle path> --key=<key file> --with-config
php tools/worker.php
```

- `--dry-run` runs only the pre-check and preview. Nothing changes.
- `--check-bin` compares the tool checksums in the bundle with this PC's bin folder. Tools are installed per OS.
- The passphrase is read only from a prompt or the `PJG_KEY_PASSPHRASE` environment variable, never from a command-line argument.
- `--yes` skips typing the confirmation word. With it, settings restore applies only items that are not PC-specific.
- Exit codes: 0 success, 1 failure or refusal, 2 usage or configuration error.
- After moving, remove leftover bundles and key files from the old PC and the transfer media.

## Restore rehearsal

Check regularly that backups actually restore. {{ui:icon.action.rehearse}} unpacks a bundle (the latest by default) into a temporary folder and runs the pre-check, a migration test and some key queries. **Operating data is not changed.**

If {{limit:backup.rehearsal_interval_days}} days pass since the last rehearsal (or the first successful backup, if none), administrators get a rehearsal-due notification and a warning on screen. From the command line, use `php tools/backup.php --rehearse`.

## After a restore

1. Every user must sign in again. Tell users in advance.
2. In the result on the status page, check the number of repository connections the current key cannot unlock. If there are any, import the key file and restore again, or re-enter the connection details for each project. Scheduled analysis for those projects is skipped until then.
3. Check under System admin > {{ui:admin_worker.title}} that the worker is running.
4. Check that project and defect counts match the backup counts shown in the preview.
5. If you restored settings, check their values and sources in [Settings](guide:settings).

## Troubleshooting

- **"No backups are made because the backup folder cannot be written"**: click {{ui:icon.action.check_path}} for the backup folder under Settings > Backups. Check write permissions for the web server and worker accounts.
- **No automatic backups appear**: check that automatic backup is on, the worker is running, and the "next" time in the summary.
- **Backup refused because of the disk**: `backup_disk_full` when the backup folder's disk is at {{limit:disk.block_percent}} or more, `backup_disk_low` when free space is short.
- **Restore stays at "requested"**: the worker is waiting for running work. If the worker is off, start it or restore from the command line.
- **Restore finished but every page shows the status page**: maintenance mode was left on. After {{limit:backup.maintenance_stale_minutes}} minutes the screen and the worker log warn about it. Turn it off on the server with `php tools/restore.php --leave-maintenance`.
- **The last successful backup cannot be deleted**: this protection is intended (409).
- Password re-entry is limited to 5 attempts per minute per user.

Verify in your production environment: backup, restore and permissions on a Linux server and with a backup folder on a network drive.

## Related sections

- [Settings](guide:settings)
- [Retention and disk](guide:retention-disk)
- [Security operations](guide:security)
- [Admin notifications](guide:notifications-admin)
- [Troubleshooting](guide:troubleshooting)
