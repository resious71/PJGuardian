# Quick installation

[한국어](install_quick.ko.md)

A short path to a working installation. It was verified on **Windows with XAMPP**; Linux has not been verified yet ([unverified.md](unverified.md)). Detailed Korean documents are linked from each step.

## 0. Before you start

| Item | Requirement |
| --- | --- |
| Web server | Apache 2.4 with HTTPS (XAMPP works) |
| PHP | 8.2 or later; extensions `pdo_sqlite`, `zip`, `mbstring`, `openssl`, `fileinfo`, `intl` (+ `ldap` for LDAP sign-in) — for both the web server and the CLI |
| Composer | to create `vendor/` (or use a deployment bundle that already contains it — [vendor_build.md](vendor_build.md)) |
| Node.js | 20.19 or later, visible to the web server and worker accounts |
| Disk | room for uploads, analysis work folders and snapshots; backups preferably on another disk ([server_sizing.md](server_sizing.md)) |

Never share one SQLite database file between two servers over a network share.

## 1. Code and libraries

1. Put the code **outside the web root**, for example `D:\PJGuardian` or `/opt/pjguardian`.
2. Run `composer install --no-dev --optimize-autoloader` in that folder (creates `vendor/`).

## 2. Apache

Create an HTTPS virtual host whose `DocumentRoot` is the `public/` folder and deny everything else ([xampp_vhost.md](xampp_vhost.md)). Do **not** add an HSTS header in Apache — the application sends it. Afterwards, `https://<host>/config/config.ini`, `/data/`, `/bin/` must return 404.

## 3. Configuration and secrets

```
copy config\config.ini.example config\config.ini
copy config\secrets.example   config\secrets.ini
php tools\make_secret_key.php
```

Set `[app] base_url` to the address users open. Keep `config.ini` and `secrets.ini` private and give the web server and worker accounts write access only to `data/`, the backup folder and `config/` (the settings screen writes `config/settings.local.ini`) — [settings.md](settings.md), [hardening.md](hardening.md).

## 4. Analysis tools

Place ruff, ESLint and Node.js as described in [bin_setup.md](bin_setup.md), then:

```
php tools\fetch_bin.php --verify
php tools\check_env.php
```

## 5. Database and first administrator

```
php tools\migrate.php
php tools\create_admin.php --login=admin --name="Administrator"
```

The initial password is **`admin`** and is not forced to change. **Change it right after the first sign-in.**

## 6. Worker and daily cleanup

Analyses run in a background worker, not in web requests. Register `php tools/worker.php` to run continuously (Windows Task Scheduler every minute — a second copy exits immediately — or a systemd/NSSM service) and `php tools/cleanup.php` daily — [worker_setup.md](worker_setup.md).

## 7. Check

1. `php tools/migrate.php --status` — all applied; `php tools/worker.php --status` — running.
2. Open `https://<host>/`, sign in, change the password, and open **System admin > Analysis tools** — ruff and ESLint should be usable.
3. Upload a small project and wait for the analysis to finish.
4. Make the first backup in **System admin > Backup & restore** and export the key file (`.pjgkey`) — [backup_restore.md](backup_restore.md).

Before production use, go through [release_checklist.md](release_checklist.md) and [hardening.md](hardening.md).
