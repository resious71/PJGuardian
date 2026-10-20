---
key: worker
title: Running the analysis worker
order: 3
summary: Registering the worker that runs analyses and upload processing as a service, checking its status, stopping and restarting it, handling failures, and reading the Worker tab
keywords: worker, worker.php, Task Scheduler, NSSM, systemd, cron, stop, restart, exit code 75, heartbeat, queue, waiting, unresponsive, cleanup, checks
related: install, tools-bin, linux-ops, settings, troubleshooting, monitoring
audience: admin
---
## What the worker does

Analyses and upload processing (assembly, extraction, pre-check) run in the CLI worker `tools/worker.php`, not in web requests. **If the worker is not running, uploads stay waiting for assembly and analyses never start.**

- It processes the analysis queue in order: up to {{limit:analysis.max_concurrent}} analyses at once, one per project. If an earlier job waits because its project is busy, a later job for another project runs first.
- Upload processing is counted separately ({{limit:worker.upload_concurrency}} at a time).
- The scheduled analysis scheduler runs inside the worker every {{limit:scheduler.tick_seconds}}. Schedules missed while the worker was off run once when it starts again.
- Reverification, usage calculation, and cleanup requests from admin screens are handled by the worker, one at a time.
- Analyses and processing run as child processes that the worker supervises. Time limits: {{limit:analysis.timeout}} per analysis, {{limit:worker.extract_timeout}} per processing job.
- Only one worker runs per server; a second one exits immediately with code 3.

## Commands

```
php tools/worker.php                # run continuously (until asked to stop)
php tools/worker.php --status       # running or not, last heartbeat, jobs in progress
php tools/worker.php --stop         # graceful stop: take no new work, exit when running jobs finish
php tools/worker.php --check        # if the worker died and the heartbeat stopped, clean up leftover jobs
php tools/worker.php --once         # exit when there is nothing left to do (manual processing)
php tools/worker.php --force-stop   # on a stop request, exit without waiting for running jobs
php tools/cleanup.php [--dry-run]   # cleanup job (daily)
```

- If intl is off in php.ini, add `-d extension=intl`. Children get the same php.ini and extensions.
- Exit codes: 0 normal, 2 configuration or runtime error, 3 another worker is running, 75 exited on a restart request from the admin screen.
- While the worker runs, manual processing with `tools/process_upload.php` exits with code 3.

## Registering it as a service

The worker must always run, and `tools/cleanup.php` must run once a day. Their account needs write permission on `data/` (the Apache account is recommended) and must not be able to write `bin/`.

### Windows Task Scheduler

No extra software is needed. Register "run every minute, do not start a new instance if already running", and a crashed worker is back within a minute.

1. Create a task, for example `PJGuardianWorker`, with "Run whether user is logged on or not".
2. Triggers: at system startup, plus repeat every 1 minute indefinitely.
3. Action: program `C:\xampp\php\php.exe`, arguments `-d extension=intl <install folder>\tools\worker.php`, start in `<install folder>`.
4. Settings: "If the task is already running: Do not start a new instance"; turn off "Stop the task if it runs longer than".
5. Register `tools\cleanup.php` the same way, once a day (for example at 03:00).

Task Scheduler has no console, so stop with `php tools/worker.php --stop`. "End task" is a forced kill.

### Windows service (NSSM or similar)

Install a service wrapper separately (not included). Make it wait long after Ctrl+C on stop (until analyses finish). For automatic restart, keep exit code 0 stopped and restart on any other code (`AppExit Default Restart`, `AppExit 0 Exit`).

### Linux systemd and cron

Create `pjguardian-worker.service` with `ExecStart` `/usr/bin/php <install folder>/tools/worker.php` and `ExecStop` the same command with `--stop`.

- `Restart=on-failure`: a stop request (exit code 0) stays stopped; a restart request (75) or a crash restarts. With `Restart=always` it comes back even after a stop request.
- Make `TimeoutStopSec` at least the analysis time limit. If it runs out, the worker is killed and cleaned up on the next start.
- Register cleanup as `pjguardian-cleanup.service` (oneshot) with a daily `.timer`.
- With cron only, call `tools/worker.php` every minute (one instance runs) and `tools/cleanup.php` daily.
- `setsid` and `prlimit` (util-linux) must be in `/usr/bin`.

## Admin screen: Worker tab

{{ui:admin_system.title}} > {{ui:admin_worker.title}} ([Worker screen](/admin/worker)) reads the heartbeat file and the queue. The web app never launches the worker directly or kills it by PID.

- **Status**: {{ui:admin_worker.state.running}} / {{ui:admin_worker.state.stopping}} / {{ui:admin_worker.state.stopped}} / {{ui:admin_worker.state.unresponsive}}. Unresponsive means the last heartbeat is older than {{limit:worker.heartbeat_stale}}. Also shown: PID, start time, last heartbeat, concurrent analysis limit, last recovery, automatic restart, and the Node check result.
- **Running analyses and queue**: read-only, with no admin cancel button (only the requesting user can cancel).
- If analyses or uploads wait with no worker running, a red warning appears and the start help opens.
- **{{ui:admin_worker.stop}}**: no new work; exits with code 0 after running jobs finish. Without automatic restart, the dialog warns that you must start it again.
- **{{ui:admin_worker.restart}}**: exits with code 75 after running jobs finish, and the service manager starts it again. Shown only when `worker.supervised` is on (now: {{limit:worker.supervised}}).
- **{{ui:admin_worker.start}}**: runs the single fixed command in `worker.start_command` (config.ini, starting a pre-registered task) without a shell. Hidden if the setting is empty or proc_open is disabled; disabled while the worker runs. The screen then watches 30 seconds for a heartbeat and reports started or not confirmed. The audit log records only `worker.start_request` (success and exit code).
- **Recent log**: the last {{limit:worker.log_tail_lines}} lines of today's app log; "{{ui:admin_worker.log_scope_worker}}" hides web request lines. Server paths and secrets are masked; no raw download.

The first word of `worker.start_command` must be an absolute path or `schtasks`, `systemctl`, or `sudo`; commands pointing directly to PHP or `tools/worker.php` are rejected. Examples: Windows `schtasks /run /tn PJGuardianWorker`, Linux `sudo -n systemctl start pjguardian-worker` (allow only this command in sudoers). The web server account must be allowed to run that task.

## Admin screen: Checks tab

{{ui:admin_system.title}} > {{ui:admin_checks.title}} ([Checks screen](/admin/checks)) shows disk usage, cleanup, recent failed analyses, the environment, and settings, read-only. For reading each item and what to do, see [System checks and monitoring](guide:monitoring). For the worker, note only:

- {{ui:admin_checks.disk_scan}} and {{ui:admin_checks.cleanup_run}} queue requests for the worker, so they run only while it is running. Files deleted by cleanup cannot be recovered.
- If cleanup shows no run recorded, check the cleanup schedule above.

## When the worker must be restarted

- When applying migrations: stop it, apply them, start it again.
- After editing `config/config.ini` or `config/secrets.ini` directly (for example, rotating the encryption key).
- After changing `tools.node_path` or replacing Node: {{ui:admin_tools.node_refresh}} only checks; analyses use the new Node path only after a restart.
- When the Settings screen shows a restart banner. Most settings changed there are reread by the worker from the next job.

Restart by requesting a stop, confirming {{ui:admin_worker.state.stopped}}, then starting it. With automatic restart, {{ui:admin_worker.restart}} does it in one step.

## Handling failures

- **Analyses keep waiting**: check `php tools/worker.php --status` or the Worker tab. If it is running, look for an earlier analysis on the same project, the concurrency limit, or a tool reverification in progress.
- **The worker died**: on the next start it fails analyses left running with `worker_crashed` and ends leftover child processes. No retry; users upload again. Uploads being assembled or extracted are rolled back and reprocessed.
- **While it cannot be restarted**: the cleanup job or `--check` does the same cleanup once the heartbeat has been silent for over {{limit:worker.heartbeat_stale}} and the lock is released.
- **Unresponsive**: the lock is held but the heartbeat stopped. Jobs are not rolled back; only a warning is logged. Check the process on the server.
- **Forced stop**: `--force-stop` or a second stop signal fails running analyses with `worker_stopped`.
- **Memory**: on Windows, analysis tool memory is not limited. If memory is short, set concurrent analyses to 1 or use a Linux server.
- Logs: `data/logs/app-YYYYMMDD.log` (one JSON object per line, UTC date); lines from worker children carry the job ID.

## Verify in your production environment

- Whether Task Scheduler's "restart on failure" treats exit code 75 as a failure and restarts the worker is unconfirmed. If unsure, keep `worker.supervised` off and use the start button.
- Verify in your production environment: the web server account's permission to run `schtasks /run`, the Linux sudoers line, actual NSSM and systemd registration, and stopping with Ctrl+C in a Windows console.
