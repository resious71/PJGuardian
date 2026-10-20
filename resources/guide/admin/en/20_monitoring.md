---
key: monitoring
title: System checks and monitoring
order: 20
summary: The System admin > Checks tab, the Monitoring screen's cards, errors and warnings, the admin banner, the alert log, and the health check (/health) for external monitors
keywords: checks, system checks, monitoring, errors, warnings, banner, alert log, health check, health, allowed IPs, queue stall, certificate expiry, disk usage, cleanup job, failed analyses, environment
related: admin-mail, audit-log, retention-disk, worker, tools-bin, settings, security, troubleshooting
audience: admin
---
## What this covers

- **Checks tab**: **System admin > {{ui:admin_checks.title}}** ([/admin/checks](/admin/checks)) shows the disk, the cleanup job, recent failed analyses, the server environment and key settings, read-only.
- **{{ui:monitoring.title}}**: {{icon:admin.system}} {{ui:icon.admin.system}} in the admin menu ([/admin/monitoring](/admin/monitoring)) gathers the current operating state, errors and warnings on one screen.
- **Health check**: an address (/health) that external monitoring tools can query for status without signing in.

The audit log is described in [Audit log](guide:audit-log).

## Reading the Checks tab

Status is shown with an icon and the words "{{ui:admin_checks.status.ok}}", "{{ui:admin_checks.status.warn}}" or "{{ui:admin_checks.status.fail}}"; rows that report an allowed policy show "{{ui:admin_checks.status.info}}", and rows that cannot be checked on this OS show "{{ui:admin_checks.status.unknown}}". Start with warnings and problems.

- **{{ui:admin_checks.disk_title}}**: usage bar with the warning ({{limit:disk.warn_percent}}) and block ({{limit:disk.block_percent}}) marks, plus free and total size. At the block level new uploads are refused. {{ui:admin_checks.disk_scan}} asks the worker to compute usage by category. See [Retention and disk](guide:retention-disk) for what to do.
- **{{ui:admin_checks.cleanup_title}}**: time, source and result counts of the last cleanup. "No run history" means the cleanup job is not registered in the server scheduler. If {{ui:admin_checks.cleanup_trash}} or {{ui:admin_checks.cleanup_pending}} keeps growing, suspect file locks or permissions. {{ui:admin_checks.cleanup_run}} asks the worker to run the cleanup.
- **{{ui:admin_checks.failures_title}}**: failed analyses of the last 7 days by reason, plus the 20 most recent. Reasons that need administrator action link to [Analysis tools](/admin/tools), [Worker](/admin/worker) or the disk area.
- **{{ui:admin_checks.env_title}}**: as seen by the web server's PHP — OS, PHP version and extensions, SQLite and proc_open, php.ini values, a write test for each data folder (a warning if the web account can write to the bin folder), server time, HTTPS and Node.js, and the connection, secret and certificate state of enabled external authentication. Security rows (report only, never block): write access to the config folder (info when writable — allowed policy), a warning when config.ini or secrets.ini is writable by this account (read-only per file is recommended), whether secrets.ini is outside the web root and its permissions (on Linux a warning when other users can read it; unavailable on Windows), a problem when settings, .git, vendor or backups are inside the public folder, a warning when display_errors or expose_php is on, and where sessions are stored (database).
- **{{ui:admin_checks.config_title}}**: the current value of key settings and whether each differs from the default. Secrets show only "set" or "not set". Change values in [Settings](guide:settings).

The command-line PHP used by the worker may be configured differently from the web PHP, so check it separately on the server.

```
php tools/check_env.php
```

## The Monitoring screen

![The Monitoring screen. At the top are the overall status line, the last update time and the Refresh now button; below them the list of active alerts and a grid of cards that all look alike: worker, queue and disk; backups, bin/ tools and HTTPS certificate; scheduled analysis and recent failed analyses; then app info.](guide-img:admin-monitoring)

This screen is read-only. At the top are the overall status ("All checks normal", "N warnings", "N errors") and the last update time, then the list of active alerts.

- **Automatic refresh**: while the browser tab is visible the screen reloads every {{limit:monitoring.refresh}}, keeping focus and scroll position.
- **{{ui:monitoring.refresh_now}}**: judges again right away without the cache. Otherwise the same result is reused for {{limit:monitoring.cache}}.
- **Unavailable**: a card whose values cannot be computed shows only "Unavailable: reason" and raises no alert. The other cards are shown as usual.

### Reading a card

Every card has the same structure. The **header** has a status icon (blue when OK, red for a warning or an error) and the card name, with a status badge at the right end. The badge is one of {{icon:system.ok}} {{ui:monitoring.badge.ok}}, {{icon:system.warn}} {{ui:monitoring.badge.warn}} (there is a warning), {{icon:system.error}} {{ui:monitoring.badge.fail}} (a failure) and {{icon:system.unknown}} {{ui:monitoring.badge.unknown}} (the values could not be read); only the app info card, which has nothing to judge, shows {{icon:ui.info}} {{ui:monitoring.badge.info}}. The stripe on the left edge has the badge's color, but always read the state from the badge's icon and text. Below the header comes the **key value** in large type (for example the number queued, disk usage or days left on the certificate), then the **details** as label-value pairs or a table. Tables show up to 5 rows and add "N more" when there are more. The **footer** always has, in the same place, a link to the screen with the full details (empty on cards without one) and the time of the check. The cards are ordered worker, queue, disk; backups, bin/ tools, HTTPS certificate; scheduled analysis, recent failed analyses; then app info. Cards in one row have the same height.

### Cards

- **{{ui:monitoring.card.worker}}**: state, last heartbeat, number of jobs in progress. A stopped or unresponsive worker is an error.
- **{{ui:monitoring.card.queue}}**: number waiting, longest wait, stall judgement, running analyses and their elapsed time.
- **{{ui:monitoring.card.disk}}**: usage, free/total, warning and block thresholds.
- **{{ui:monitoring.card.backup}}**: automatic backup on or off, last success, last result, next scheduled run. A note appears when the backup folder is on the same disk as the live data.
- **{{ui:monitoring.card.tools}}**: active ruff and ESLint versions, pinned and blocked marks, checksum and smoke test results, last verification time. git and p4 are shown only.
- **{{ui:monitoring.card.cert}}**: the web server certificate's expiry date and days left. The server does not connect to its own address; it reads `monitoring.tls_cert_file` (a PEM file path) from config.ini. If that is empty, the card shows "Unavailable: not configured".
- **{{ui:monitoring.card.schedules}}**: upcoming runs and projects with consecutive failures.
- **{{ui:monitoring.card.failures}}**: the latest {{limit:monitoring.recent_failures}} failed analyses. The key value is their count; the table shows the newest 5 (project, Run, reason code, time; select the project to open the analysis) and "N more".
- **{{ui:monitoring.card.app}}**: app version, database schema (migration), PHP, SQLite, operating system.

## Errors and warnings

The alert names and levels are below. Any error makes the overall status an error; only warnings make it a warning.

- `worker_down` (error): no worker heartbeat, or the worker is stopped. See [Worker](guide:worker).
- `queue_stalled` (warning): the worker is alive and has a free slot, but the oldest **startable** queued analysis (its project has no running analysis) has not started for more than {{limit:monitoring.queue_stall}}. Waiting because of the one-per-project rule is not a stall.
- `disk_warn` (warning) and `disk_block` (error): disk usage above the warning or block threshold.
- `backup_failed` (warning): the last backup failed. `backup_stale` (warning): automatic backup is on but the last success is older than the "stale" limit used on the backup screen.
- `tool_unhealthy` (warning): ruff or ESLint has no usable version, or a checksum or smoke test failed.
- `repo_fetch_failing` (warning): a project's scheduled analysis has failed {{limit:monitoring.repo_failures}} or more times in a row.
- `cert_expiring` (warning): the certificate expires within {{limit:notification.cert_expiry_warn}}. `cert_expired` (error): it has expired.

Change the stall threshold under **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}}**, "{{ui:config_settings.key.monitoring.queue_stall.label}}".

### Admin banner and alert log

- When there are active alerts, administrators (only) see "N alerts: first alert" with a "{{ui:monitoring.banner_link}}" link at the top of every page. Regular users never see it. The screen, the banner and the health check use the same judgement.
- The worker judges every {{limit:monitoring.cache}} and writes lines to alert-YYYYMMDD.log under logs in the data folder: when an alert starts, every {{limit:monitoring.log_repeat}} while it continues, and when it clears. It is kept as long as the app log ({{limit:log.error_access_retention}}).
- **Email**: to receive errors and warnings by email, turn on [Admin email alerts](guide:admin-mail) (off by default). No email goes out while the worker is stopped, so if you must know about a stopped worker right away, also connect the health check below to an external monitoring tool.

## Health check (/health)

An address for external monitoring tools. It needs no sign-in and answers **only allowed IP addresses**; any other address gets the same 404 as an unknown page.

1. Under **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.monitoring}}**, enter the monitoring server addresses in "{{ui:config_settings.key.health.allowed_ips.label}}" (`health.allowed_ips`), separated by commas (CIDR allowed). Empty (the default) refuses everyone.
2. Check the status code from the monitoring server.

```
curl -s -o /dev/null -w "%{http_code}" https://<server>/health
```

- 200: OK or warning; 503: error (including when the database cannot be reached); 429: more than {{limit:health.per_minute}} calls per minute from one allowed IP.
- The body is JSON: status (ok, warn, fail), time, maintenance (upgrade maintenance mode — not part of the judgement), and checks (worker, db, disk, backup, queue). It contains no project or user names, paths or secrets. The same response is reused for {{limit:health.cache}}.
- **Behind a proxy**: by default only the connecting address counts and headers such as X-Forwarded-For are not trusted. Behind a reverse proxy, enter the proxy's address in "{{ui:config_settings.key.security.trusted_proxies.label}}" on the same tab. Then only requests that come through that proxy have their allowed IP judged from the X-Forwarded-For address ([Security operations](guide:security)). Also restrict /health at the proxy to the monitoring servers. Verify in your production environment: the /health restriction in your proxy configuration.

## Related sections

- [Admin email alerts](guide:admin-mail)
- [Audit log](guide:audit-log)
- [Retention and disk](guide:retention-disk)
- [Worker](guide:worker)
- [Analysis tools and the bin folder](guide:tools-bin)
- [Settings](guide:settings)
- [Security operations](guide:security)
- [Troubleshooting](guide:troubleshooting)
