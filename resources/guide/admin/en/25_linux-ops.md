---
key: linux-ops
title: Linux operations
order: 25
summary: What a Linux server needs, the order in which to verify it, what behaves differently from Windows, and what has not yet been confirmed on a production Linux server
keywords: Linux, Ubuntu, RHEL, systemd, setsid, prlimit, posix, pcntl, SIGTERM, permissions, www-data, verification, unconfirmed, git build
related: install, worker, tools-bin, security, troubleshooting
audience: admin
---
## Scope of this section

PJGuardian supports Windows and Linux. So far, however, everything has been checked on a Windows development PC, and **it has not yet been run on Linux.** The code branches by operating system, and there is no place with only a Windows branch. This section lists the order in which an administrator should verify a production Linux server (or a test Linux machine). Each step is independent; keep the results to support later operating decisions.

Installation itself is the same as in [Installation and environment](guide:install), and worker registration follows the systemd and cron part of [Running the analysis worker](guide:worker).

## Requirements

- Distribution: Ubuntu 22.04/24.04 or the RHEL 9 family.
- PHP 8.2 or later, both CLI and the Apache module (or PHP-FPM). Extensions: pdo_sqlite, zip, mbstring, openssl, fileinfo, intl, posix, pcntl, and curl (for the check tools). posix and pcntl are used for the worker's process groups and stop signals. The web PHP and CLI PHP configurations can differ, so check both.
- `setsid` and `prlimit` from util-linux (they must be in `/usr/bin`).
- Node.js: put its absolute path in `tools.node_path`. The minimum version is {{limit:tools.node_min_version}}.
- `bin/`: `bin/ruff/<version>/linux/ruff`, `bin/eslint/<version>/`, `bin/p4/<version>/linux/p4`, and `bin/git/<version>/linux/bin/git`. You must build Linux git yourself (see below).
- Accounts: the web account (for example `www-data`) can write only `config/` (for settings.local.ini saved by the Settings screen), `data/`, and the backup folder; it has no write permission on `bin/`, `src/`, or anything else. For this policy and its risks and mitigations, see [Installation and environment](guide:install). Run the worker as the same account or in the same group.

## Verification order

1. Environment check: `tools/check_env.php` reports no FAIL (exit code 0), configuration validation and migration status (nothing pending) look right, and `setsid` and `prlimit` are present.
2. Automated tests: the deployment bundle has no `tests/`, so run them on a test server with a repository copy. Tests that matter most on Linux are ProcessRunner (process group termination after timeout or cancel), WorkerProcess (orphaned grandchildren are terminated), and ToolRunners (sample results match Windows).
3. Tool registry: the Node check and full verification of ruff, ESLint, git, and p4 pass (git after the Linux build).
4. Process termination and memory limits: while the worker runs, confirm the pid and running state with `--status`; kill the worker forcibly, then confirm that `--check` cleans up the dead worker and that the next start marks its analyses `worker_crashed`. For the ruff memory limit, lower `analysis.process_memory_limit` in a test configuration and analyze a large Python project (expect a `memory_limit` failure or a normal result).
5. Worker run-through: start a worker with a separate configuration (`--config=<test ini>`, its own DB and data folder), upload the sample zips, and check that the defect counts match Windows. Send `kill -TERM <worker pid>` and confirm it waits for the running analysis before exiting, and that a second signal forces a stop (`worker_stopped`).
6. Path rules: a zip with two files differing only in case must be rejected as a name conflict on Linux too. NFD file names must be normalized to NFC so defect paths and signatures match Windows.
7. File permissions: the web account cannot create a file in `bin/` but can in `data/work/` and `config/`, and check the hardening check result.
8. Repository integration: after building Linux git, the repository tests and scheduled analysis tests pass without "skipped".
9. Apache: move the virtual host to Linux paths, expose only `public/` (`AllowOverride None` plus `FallbackResource /index.php`), and repeat the exposure checks (404, 301, HSTS) from [Installation and environment](guide:install). The config file is `/opt/lampp/etc/extra/httpd-vhosts.conf` for XAMPP for Linux and under `/etc/apache2/sites-available/` for Debian/Ubuntu Apache.

```
# 1. Environment check
php tools/check_env.php
php tools/config_check.php config/config.ini
php tools/migrate.php --status
which setsid prlimit
# 2. Automated tests (test server)
php tests/run.php
node --test "tests/js/*.test.mjs"
php tools/run_all_tests.php
# 3. Tool registry
php tools/tools_ctl.php node
php tools/tools_ctl.php verify ruff
php tools/tools_ctl.php verify eslint
php tools/tools_ctl.php verify git
php tools/tools_ctl.php verify p4
php tools/check_bin_vcs.php
# 7. File permissions
sudo -u www-data touch bin/x               # must be Permission denied
sudo -u www-data touch data/work/x && rm data/work/x
sudo -u www-data touch config/x && rm config/x
php tools/create_admin.php --check-only
```

## What behaves differently from Windows

- Process tree termination: Windows uses `taskkill /T /F`; Linux sends SIGKILL to the whole process group created with `setsid`. By design, on Linux a grandchild whose parent already exited is also terminated if it is in the same group (verify in your production environment).
- Memory limit (`analysis.process_memory_limit`, currently {{limit:analysis.process_memory_limit}}): ruff is limited with `prlimit --as` on Linux only. Windows applies no limit. ESLint (Node) limits only the heap size on both systems.
- The output size limit (`analysis.output_limit`, currently {{limit:analysis.output_limit}}) is enforced by polling on both systems.
- Worker stop signals: Linux uses SIGTERM and SIGINT (the pcntl extension is required), and systemd `stop` sends SIGTERM. A second signal forces a stop.
- Start button command: on Linux, use the form `sudo -n systemctl start pjguardian-worker` and allow only that command in sudoers.
- Path comparison: some internal path comparisons ignore case on Windows and compare as is on Linux. The upload file name conflict rule is the same on both systems.
- `config/settings.local.ini`, written by the Settings screen, is created with mode 0640 on Linux.

## Building git for Linux

There is no official Linux git binary, so build it on the same distribution as the production server (same glibc, libcurl, and OpenSSL). Use a server administrator account that can write to `bin/`.

1. Install the build tools and development packages (libcurl, OpenSSL, zlib, expat).
2. Run `tools/build_git_linux.sh --gpg`. It checks the official checksum, builds into `bin/git/<version>/linux/` without installing anything system-wide, and writes the manifest.
3. Check the manifest and executable format with `php tools/check_bin_vcs.php`.
4. Restrict permissions so the web and worker accounts cannot write to that folder.

When moving to a server with a different distribution, build again on that server.

## Verify in your production environment

The items below exist only as code and procedures and have not been run on Linux. Verify in your production environment.

- The Linux output of `tools/check_env.php` (including posix and pcntl) and the environment items on the Checks screen.
- Process group termination with `setsid`, liveness checks via `/proc`, and cleanup of orphaned grandchildren.
- The ruff memory limit with `prlimit --as`. `prlimit` is called at the fixed path `/usr/bin/prlimit`; check this if your distribution differs.
- Limiting output size in the kernel (`prlimit --fsize`) is not applied (polling only).
- Graceful stop on SIGTERM and forced stop on a second signal.
- Actual systemd service and timer registration, whether `Restart=on-failure` brings the worker back after exit code 75, and `sudo -n systemctl start` from the start button with its sudoers line.
- Linux-built git, the Linux p4 executable, and the repository integration and scheduled analysis tests.
- The Linux Apache virtual host and chunked upload limits.
- Whether ruff and ESLint sample results match Windows.
- Whether settings.local.ini is created with mode 0640 and the worker account can read what the web account wrote through the same group.
- Whether per-file permissions that keep only `config.ini` and `secrets.ini` read-only for the web account, with `config/` writable, still let the Settings screen save.

## Troubleshooting

- `tools/check_env.php` reports FAIL for intl: paths cannot be normalized to NFC, so uploads are blocked. Install the php-intl package and confirm it is enabled for both web and CLI.
- The Checks screen warns about posix or pcntl: the CLI PHP lacks the extension, so the worker cannot create process groups or receive stop signals.
- For failed tests or analyses, look at the ERROR lines in `data/logs/app-YYYYMMDD.log` (one JSON object per line).
