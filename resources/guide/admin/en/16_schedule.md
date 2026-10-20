---
key: schedule
title: Scheduled analysis
order: 16
summary: Per-project schedules, how the scheduler runs and catches up, failures, retries and notifications, what Succeeded (unchanged) means and when analysis is skipped, and the Scheduled analysis tab
keywords: scheduled analysis, scheduler, schedule, interval, run time, KST, retry, consecutive failures, skipped, unchanged, forced analysis, notification
related: repository, worker, notifications-admin, settings
audience: admin
---

## What this feature does

For a project with a connected repository, the server can fetch the latest source and analyze it automatically at a set interval and time (KST). Set up [Source repository integration](guide:repository) first.

- No separate program is needed: the analysis worker checks the schedules and queues the analyses. If the worker is not running, no scheduled analyses are queued.
- Scheduled analyses run in order in the same queue as upload analyses and share the same limit on concurrent analyses ({{limit:analysis.max_concurrent}}).
- If the source and conditions are the same as the last successful analysis, the analysis is skipped and recorded as "Succeeded (unchanged)".

## Turning on a schedule

1. In {{ui:icon.admin.projects}}, open the project and select {{ui:projects.edit_button}}.
2. Under {{ui:repo.title}}, in {{ui:schedule.title}}, turn on {{ui:schedule.field.enabled}}.
3. Enter the interval in days: at least {{limit:repository.min_interval}}, at most 365 days. Seven days is recommended (the {{ui:schedule.week_button}} button).
4. Enter the run time in KST, 24-hour format (for example 03:00 or 23:30).
5. Select {{ui:schedule.save}}. After saving, the next scheduled run is shown.

- If there is no repository connection, the host is not on the allowed list, or the credentials cannot be read, the inputs are disabled and the reason is shown. Fix the connection first.
- If the last connection test or fetch failed, or no test has been run yet, you get a warning when you save. Running a connection test is recommended.
- If other projects are already scheduled at the same time, the screen tells you how many. Piling many projects onto one time makes users' upload analyses wait longer, so spread the times out.
- If another administrator changed the schedule first, your save is rejected. Reload the screen and save again.
- Disconnecting the repository turns scheduled analysis off automatically and writes an audit log entry (`schedule.auto_disable`).

## How runs are scheduled

- When you turn the schedule on or change the interval or time, the first run is at the next matching time after the change. Past times are never run retroactively.
- After that it runs every N days at the same time. KST has no daylight saving time, so the time stays the same at month and year ends.
- The worker checks schedules every {{limit:scheduler.tick_seconds}} and handles up to {{limit:scheduler.max_per_tick}} projects per check (the rest wait for the next check).
- If the worker was off and runs were missed, only the most recent missed run is executed once when the worker starts again. The next run is then set to a time after now.

### When a run is skipped

When a run is due, it is skipped (only recorded) in these cases:

- The project already has a queued or running analysis (no notification)
- Disk usage is over the block threshold ({{limit:disk.block_percent}}) or free space is too low
- There is no repository connection, the host is not allowed, or the credentials cannot be read

Skips caused by disk or connection problems notify administrators. Skips do not count as failures.

## Failures and retries

If a fetch fails for a temporary reason, the same analysis is tried again.

- Failures that are retried: `repo_unreachable`, `repo_timeout`, `repo_fetch_failed`
- The next attempt is after {{limit:repository.connect_retry_interval}}. If all {{limit:repository.connect_max_attempts}} attempts fail, the run ends as one failed analysis. The schedule stays on and runs again at the next interval.
- While waiting to retry, the worker handles other analyses. {{ui:repo.fetch_now}} for that project is refused because an analysis is in progress.
- Reasons that fail right away include `repo_auth_failed`, `repo_host_not_allowed`, `repo_tool_unavailable`, `repo_tls_untrusted`, `repo_not_found`, `repo_limit_exceeded`, `repo_empty` and `repo_unsafe_content`. A failure because there are no target files does not resolve existing defects.
- Change the number of attempts and the interval on {{ui:config_settings.title}} > {{ui:config_settings.group.schedule}}.

### Notifications

- When a scheduled analysis fails, every active administrator gets a "scheduled analysis failed" notification. Regular users never get or see it.
- Repeated failures of the same project do not pile up: one notification's count goes up. Its read state is kept and it moves to the top of the list.
- No completion notification is sent. The new and resolved defect summary for assignees works the same as for upload analyses.
- Administrators can turn this notification off in their notification settings.

## Succeeded (unchanged)

### When analysis is skipped

Before analyzing, scheduled analysis and {{ui:repo.fetch_now}} check only the latest revision in the remote repository. If all of the following match the last successful analysis, nothing is fetched or analyzed and the run is recorded as "Succeeded (unchanged)":

- Revision: the branch head commit for Git, the latest changelist under the depot path for Perforce
- Connection: repository type, address, branch, sub-path or depot path
- Analysis conditions: tool version, server analysis configuration including rules turned on or off by administrators, exclude patterns, target extensions

If anything differs or cannot be determined, the analysis runs. It also runs for a project's first analysis and when the last successful analysis was an upload. If the remote check itself fails, that is not "unchanged": the run is queued as usual and handled as a fetch failure with retries. Upload analyses never make this check.

For Git, even with a sub-path, changes are judged by the branch head commit, so a change outside the sub-path still triggers an analysis. Perforce is path-based, so changes outside the path do not.

### Forcing an analysis

When {{ui:repo.fetch_now}} finds no changes, the screen asks whether to analyze anyway. Choose {{ui:repo.force_confirm}} to fetch and analyze the same revision again, which creates a new Run. If you cancel, nothing more is created, but the one "unchanged" entry already recorded stays. Scheduled analysis has no force option.

### What you see on screen

- The history shows the {{ui:icon.analysis_status.unchanged}} badge, "checked N times · last checked", and a link to the baseline Run. If the same revision keeps being checked, one entry's count goes up instead of adding rows.
- Use {{ui:history.hide_unchanged}} on the history screen to hide unchanged entries.
- The {{ui:repo.title}} box on the project screen shows {{ui:repo.field.last_check}} separately from the last analyzed commit.
- Unchanged runs are not analyses, so they are left out of Run numbers, comparison baselines, dashboard trends and notifications.
- Unchanged runs do not clear a failure. If the last real analysis failed, it stays failed however many unchanged checks follow, and the consecutive failure count neither goes up nor resets. Only a real analysis that succeeds (including a forced one) clears it.

## The Scheduled analysis tab

See the overall picture in {{ui:icon.admin.tools}} > {{ui:admin_schedules.subnav}} (/admin/schedules).

- {{ui:admin_schedules.scheduler_title}}: last check time, check interval, and counts of due, queued and skipped runs. A delay warning appears if checks stop, and a notice appears if the worker is not running.
- {{ui:admin_schedules.list_title}}: for each project, interval, next run, last result (linked to the analysis), consecutive failures, last error and skip count
- {{ui:admin_schedules.failures_title}}: attempts and reasons, the next scheduled rerun, and analyses waiting to retry
- {{ui:admin_schedules.skips_title}}: skipped runs and why

The project list shows a scheduled analysis tag with the next run time, and administrators also see the consecutive failure count. On a project's analyses tab you can filter by who started the run (user or schedule).

## When failures keep happening

1. On the {{ui:admin_schedules.subnav}} tab, check the project's last error.
2. For an authentication failure, enter the token or password again in the connection settings.
3. For unreachable or timeout errors, check the server and the network (firewall, proxy), and use the allowed host test to find the failing step.
4. On the project screen, use {{ui:repo.test}} and {{ui:repo.fetch_now}} to confirm. A real analysis that succeeds resets the consecutive failure count to 0.

Scheduled analysis is never turned off automatically because of failures. To pause it, turn it off in the project's edit screen.

## Things to watch out for

- If the worker is stopped for a long time, missed runs do not pile up; only one runs when it starts again.
- When an administrator turns a rule on or off, or a tool version changes, the analysis conditions change, so the next scheduled run analyzes even if the commit is the same.
- Verify in your production environment that runs happen on schedule against real repositories. Start by checking that the last check time on the {{ui:admin_schedules.subnav}} tab updates at each check interval.
