---
key: settings
title: Settings
order: 12
summary: Review and change operating values on screen without opening config.ini — sources, precedence, saving, conflicts and resets
keywords: settings, config.ini, settings.local.ini, environment variable, source, default, change history, conflict, check path, category tabs
related: retention-disk, backup-restore, notifications-admin, monitoring, audit-log, repository, security
audience: admin
---
## What this screen is for

**System admin > {{ui:config_settings.title}}** ([/admin/config-settings](/admin/config-settings)) is where you review and change values that operators adjust from time to time. It is not a full config.ini editor. Only keys on an allow list can be changed, and sensitive values (passwords, encryption keys) and infrastructure values (base URL, data folders) are not shown at all.

Values you save here go only to settings.local.ini in the config folder. config.ini, secrets.ini and the database are not touched.

## Screen layout

![The System admin > Settings screen. At the top is the System admin tab row with Settings selected, then the search box and Reload button, then the category tabs (Retention periods, Disk, Notifications, Monitoring, Scheduled analysis and fetch, Repository connections, Analysis, Backups, Account approval, LDAP/AD, SSO (OIDC), SSO (SAML)). Each item on the Retention periods tab shows its name, source badge (config.ini), description, value field and format hint.](guide-img:admin-config-settings)

The System admin tab bar is at the top, with a smaller row of category tabs below it.

- **Category tabs**: {{ui:config_settings.group.retention}} · {{ui:config_settings.group.disk}} · {{ui:config_settings.group.notification}} · {{ui:config_settings.group.monitoring}} · {{ui:config_settings.group.schedule}} · {{ui:config_settings.group.repository}} · {{ui:config_settings.group.analysis}} · {{ui:config_settings.group.backup}} · {{ui:config_settings.group.auth}} · {{ui:config_settings.group.auth_ldap}} · {{ui:config_settings.group.auth_oidc}} · {{ui:config_settings.group.auth_saml}}. Add the tab name to the address, such as `#backup`, to open that tab directly (for example [/admin/config-settings#backup](/admin/config-settings#backup)).
- **Each item**: name, source badge and help on the left; input, format hint, range, the "If reset to default: …" note, apply status and {{ui:icon.action.reset_default}} on the right.
- **Search**: matches names, help text and setting keys across all tabs. If the current tab has no match but another tab does, a button takes you there.
- **Tab badges**: the number of changed items and input errors appears next to each tab name.
- **Bottom buttons**: "Review setting changes (N)" and {{ui:config_settings.discard}}. They act on changes in every tab at once.
- **{{ui:config_settings.history_title}}**: recent changes below the tabs, with time, user, old value and new value.

### Two sections that save differently

These two sections are stored in the database, not in settings.local.ini, so each **saves with its own buttons**, separately from "Review setting changes".

- **{{ui:admin_notifications.title}}**: at the end of the {{ui:config_settings.group.notification}} tab — how long read and unread notifications are kept, and the maximum per user. See [Admin notifications](guide:notifications-admin).
- **{{ui:repo_hosts.title}}**: at the top of the {{ui:config_settings.group.repository}} tab — add, test and delete hosts that repositories may be fetched from. See [Repository integration](guide:repository).

Unsaved input in a section shows "N unsaved changes" next to the section title and is added to the tab badge and the leave-tab check. The N in "Review setting changes (N)" counts setting items only.

## Changing values

1. Pick a category tab and change values. Your input stays when you switch tabs.
2. If a format or range is wrong, the reason appears under the field right away.
3. Click "Review setting changes (N)" at the bottom. If anything must be fixed first, the screen moves to the first tab with an error and focuses that item instead of opening the dialog.
4. In the dialog, check each item with its current and new value, then click {{ui:config_settings.save}}.
5. A save **succeeds or fails as a whole**. Nothing is saved partially.

To throw away your input, click {{ui:config_settings.discard}}. To remove a screen setting and fall back to the config.ini value (or the default), click {{ui:icon.action.reset_default}} on that item.

### When changes take effect

- **Web**: settings are read on every request, so a save takes effect immediately. Pages that are already open (such as the notification count refresh interval) pick it up when reopened.
- **Worker**: on every loop it checks whether settings.local.ini changed and reloads it. No restart is needed, and new values are used **from the next job**. A running analysis finishes with the values it started with.
- The apply status next to an item is "{{ui:config_settings.status.applied}}", "{{ui:config_settings.status.pending_reload}}" or "{{ui:config_settings.status.worker_off}}".
- If an item ever needs a worker restart, a banner with a link to the worker screen appears at the top. No item on the current allow list needs one.

## Source badges and precedence

Each item shows where its current value comes from.

- **{{ui:config_settings.source.env}}**: set by the environment variable `PJG_<SECTION>_<KEY>` (for example `PJG_DISK_WARN_PERCENT`). It is read-only on screen.
- **{{ui:config_settings.source.screen}}**: saved on this screen (settings.local.ini).
- **{{ui:config_settings.source.config}}**: written in config.ini on the server.
- **{{ui:config_settings.source.default}}**: not set anywhere, so the default applies.

Precedence is **environment variable > screen setting > config.ini > default**. When several servers share settings, keep common values in config.ini and per-server values in screen settings or environment variables. Use environment variables to pin a value from a service manager.

Items with the "{{ui:config_settings.machine_specific}}" badge (`backup.dir`, `oidc.redirect_base`) differ per server. When settings are restored from a backup, they are unchecked by default.

## Items you cannot change here

The following are not on the screen. To change them, a server administrator edits config.ini or secrets.ini on the server and restarts the worker (for secrets.ini, see [Security operations](guide:security)).

- Base URL, security headers, forced HTTPS, password policy, lockout and sessions (`app.base_url`, `security.require_https`, `auth.session_idle` and so on) — a wrong value can block sign-in. The exception is trusted proxies (`security.trusted_proxies`), which are changed on the {{ui:config_settings.group.monitoring}} tab.
- Database and data folder locations, SQLite settings — infrastructure.
- Secrets such as the encryption key, SMTP and LDAP passwords (secrets.ini) — sensitive.
- Tool and Node paths, the worker start command, upload chunk size and extraction limits — tied to how the server runs and to security limits.

The LDAP/AD search account password, the OIDC client secret and the SAML SP key are entered separately on their tabs and stored encrypted. They are never written to a settings file.

## Path items: check path and create folder

Path items such as the backup folder (`backup.dir`) must pass {{ui:icon.action.check_path}} before they can be saved.

1. Enter an absolute path, a network share path, or a path relative to the project folder.
2. Click {{ui:icon.action.check_path}}. The server checks the location rules, a test write and free space.
3. If the folder does not exist, you are asked whether to create it. Confirm with {{ui:config_settings.path_create}}.
4. If the folder is on the same disk as the operating data, you can still save, but a warning is shown.

Locations inside the project folder (including the web root and data folders), operating system folders and drive roots are refused. The only exception is the backups folder directly under the project. A network share that does not answer within 5 seconds fails the check. The backup folder cannot be changed while a backup or restore is running (409 `setting_busy`).

## Conflicts, ignored values and leaving the tab

- **Conflict (409)**: if another administrator saved after you opened the screen, your save is refused (`settings_conflict`). Click {{ui:config_settings.reload}} to see the latest values and make your change again. Changing a value and changing it back is not treated as a conflict.
- **Ignored values**: if settings.local.ini is broken or a value is out of range, only that value is ignored and config.ini or the default is used. The ignored values and the reasons are listed at the top. A broken file is rewritten on the next save.
- **Leaving the tab**: if you click another System admin tab with unsaved changes, a confirmation dialog opens. Cancel keeps you where you are; {{ui:config_settings.leave_confirm}} discards the changes and moves on.

## Change history and backups

- The change history is read from audit log entries named `config.update`, one line per item (also visible on the [Audit log](guide:audit-log) screen). Values such as the LDAP bind DN are masked.
- Every save keeps the previous settings.local.ini, rotating up to five copies (settings.local.ini.bak.1 is the newest).
- Backup bundles include a copy of settings.local.ini with sensitive values blanked. Restoring settings is off by default; when you turn it on, you pick the items to apply. See [Backup and restore](guide:backup-restore).
- To check settings on the server, run the command below. It reads config.ini and settings.local.ini together and reports ignored values as warnings.

```
php tools/config_check.php
```

## Troubleshooting

- **Save fails and the server log shows a write failure**: the web server account needs write permission on the config folder. It does not need write permission on config.ini or secrets.ini. Verify in your production environment: file permissions and group on Linux (written by the web server account, read by the worker account).
- **The value changed but the worker still behaves the old way**: check whether the apply status says "{{ui:config_settings.status.worker_off}}". If the worker is off, start it from the [Worker](/admin/worker) tab.
- **You want to drop all screen settings**: delete settings.local.ini at a time when neither the web server nor the worker is using it. The previous content stays in the .bak files.

## Related sections

- [Retention and disk](guide:retention-disk)
- [Backup and restore](guide:backup-restore)
- [Admin notifications](guide:notifications-admin)
- [System checks and monitoring](guide:monitoring): health check allowed IPs and the queue stall threshold on the {{ui:config_settings.group.monitoring}} tab
- [Security operations](guide:security)
