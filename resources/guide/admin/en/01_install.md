---
key: install
title: Installation and environment
order: 1
summary: Preparing a new server: XAMPP, PHP extensions, HTTPS virtual host, config file permissions, migrations, and the first administrator
keywords: install, XAMPP, Apache, PHP, extension, intl, ldap, HTTPS, virtual host, php.ini, config.ini, settings.local.ini, config write permission, migrate, create_admin, hardening
related: deploy-bundle, worker, tools-bin, linux-ops, settings, security, backup-restore
audience: admin
---
## Overview

PJGuardian is a web app on Apache and PHP (XAMPP works) with SQLite. Analyses run in a separate worker process, not in web requests. Supported systems: Windows and Linux (not macOS).

- `public/` is the only folder exposed to the web. Keep `data/` (DB, uploads, logs), `config/`, `bin/`, and `src/` outside the web root.
- Do not put the project folder under XAMPP's `htdocs/`; the default site could then serve `data/` or `config/`.
- These steps were verified on a Windows + XAMPP development PC. For Linux, also read [Linux operations](guide:linux-ops).

## Requirements

- PHP 8.2 or later with pdo_sqlite, zip, mbstring, openssl, fileinfo, and intl. `php tools/check_env.php` checks these six and reports FAIL for any that are missing.
- intl normalizes paths and code to NFC. If it is off, enable it in php.ini or add `-d extension=intl` to commands.
- The ldap extension is needed only for LDAP/AD sign-in, and the environment check command does not check it. If LDAP is on without it, the LDAP/AD tab in Settings and the Checks screen warn.
- A Linux worker also needs posix and pcntl; without them the Checks screen warns.
- SQLite must meet the minimum version the checks require; the check result says whether it does.
- Prepare Node.js (for ESLint), ruff, and ESLint as described in [Deploying tools in bin/](guide:tools-bin).

## Recommended server size and disk capacity

- Baseline (2 concurrent analyses): **8 or more CPU cores, 32 GB or more memory**, SSD. One analysis can use up to {{limit:analysis.process_memory_limit}} of memory.
- Up to 50 concurrent users, screen responses stayed under 0.5 s in the development PC measurements. With 100 or more, confirm with a load test on the production server (values for unmeasured hardware are estimates).
- Disk: in {{ui:admin_system.title}} > {{ui:admin_checks.title}}, enter the number of projects, defects, upload size, and backups to keep in the **{{ui:capacity.title}}** to calculate the data disk and backup disk needed with the current settings. The analysis workspace at the upload limits is {{limit:analysis.max_concurrent}} × (largest zip + largest extraction).
- Formula, basis, and examples: `docs/server_sizing.en.md` (Korean `docs/server_sizing.md`).

## Installation steps

1. Extract the deployment bundle into a folder outside the web root (for example `C:\PJGuardian`, or `/opt/pjguardian` on Linux). To build and check the bundle, see [Deployment bundle](guide:deploy-bundle).
2. Copy `config/config.ini.example` to `config/config.ini` and adjust it. In particular, check `app.base_url` (the `https://` address users open), `app.env` (prod in production), and `security.require_https` (true).
3. Copy `config/secrets.example` to `config/secrets.ini`. For repository integration, create the encryption key with `php tools/make_secret_key.php` (written only to the file, never printed).
4. Set up the Apache virtual host and HTTPS (next section).
5. Check the environment: `php tools/check_env.php`. Any FAIL gives exit code 1.
6. Validate the configuration: `php tools/config_check.php`. On errors it prints the cause and exits with code 1.
7. Create the database and apply migrations (see "Migrations").
8. Create the first administrator (see "First administrator").
9. Deploy the analysis tools and register the worker ([Deploying tools in bin/](guide:tools-bin), [Running the analysis worker](guide:worker)).

## Apache virtual host and HTTPS

- Create a dedicated virtual host with DocumentRoot `public/`, `Options -Indexes -FollowSymLinks`, `AllowOverride None`, and `FallbackResource /index.php`.
- Add a separate `Require all denied` for the project root folder as a second layer.
- Permanently redirect HTTP (port 80) to HTTPS and send HSTS on HTTPS responses. Use a proper certificate in production.
- Required Apache modules: rewrite, headers, and ssl, with the vhosts and ssl config includes enabled.
- In `httpd-vhosts.conf`, the first virtual host for each port is that port's default site. To allow access by IP address, add `ServerAlias <server IP>` or make PJGuardian the default virtual host.
- For a server used from several PCs, set `app.base_url` to a name other PCs can reach, with a certificate for that name. The app redirects to this value, not to the Host header.
- Check the syntax with `httpd -t` and the default site per port with `httpd -S`, then restart Apache.

## php.ini and Apache limits

Chunked uploads send each chunk as a raw request body (PUT), so each request is small. The current chunk size is {{limit:upload.chunk_size}}.

- `post_max_size` must be larger than the chunk size. The Checks screen also warns if `upload_max_filesize` is not larger.
- Recommended: `memory_limit` at least four times the chunk size, `max_execution_time` at least 120 seconds.
- Apache `LimitRequestBody` (Apache's default if unset) and any reverse proxy body limit must be at least the chunk size.
- Hardening: `display_errors = Off`, `expose_php = Off`, `log_errors = On`, Apache `ServerTokens Prod` and `ServerSignature Off`.

Not changed by the app: `max_input_time`, Apache `Timeout`, and `RequestReadTimeout` passed with XAMPP defaults on the development PC. Concurrency settings (`ThreadsPerChild`) have not been load-tested yet.

On Windows, XAMPP uses one php.ini for the CLI and Apache. On Linux the web and CLI php.ini files can differ, so check both.

## Config file locations and permissions

- `config/config.ini`: shared configuration. Change infrastructure values (paths, addresses, security) only here.
- `config/secrets.ini`: encryption keys and passwords. Restrict it so only the web server and worker accounts can read it.
- `config/settings.local.ini`: values changed on the [Settings](guide:settings) screen. Saving also creates lock, temporary, and backup (`.bak.1` to `.bak.5`) files in the same folder.
- **Write permission policy**: the accounts that run the web server and the worker may write the whole `config/` folder (to write settings.local.ini and its lock, temporary, and backup files); without it, saving on the screen fails. These accounts can write only `config/`, `data/`, and the backup folder; everything else (`bin/`, `src/`, `public/`, and so on) stays read-only ([Deploying tools in bin/](guide:tools-bin)).
- On Linux, settings.local.ini is created with mode 0640. Put the web and worker accounts in the same group so the worker can read it ([Linux operations](guide:linux-ops)).
- Do not move `config/config.ini` or `config/secrets.ini` through git, bundles, or messengers; create them on each server.

Allowing writes to `config/` means a vulnerability in the web app could lead to `config.ini` or `secrets.ini` being changed. To reduce the risk:

- Per file, keeping `config.ini` and `secrets.ini` read-only as far as possible is safer. Verify in your production environment whether Windows lets you give the web account read-only access while allowing writes only to settings.local.ini and its temporary file names.
- `config/` is not reachable from the web (DocumentRoot is `public/`), and the Settings screen writes only settings.local.ini (and its lock, temporary, and backup files).

## Deployment bundle

For what goes into the production bundle and the `tools/verify_bundle.php` check, see [Deployment bundle](guide:deploy-bundle).

## Migrations

The installation or upgrade owner changes the DB schema with a command. Web requests never change the DB structure.

```
php tools/migrate.php            # apply all pending migrations (creates the DB if needed)
php tools/migrate.php --status   # applied, pending, file changed, or file missing
```

- Safe to run repeatedly. A failed migration is rolled back, later ones are not applied, and the exit code is 1.
- For an upgrade: back up, stop the worker, replace the code, run migrations, then start the worker. For what not to overwrite when replacing the code, see [Deployment bundle](guide:deploy-bundle).
- To move data from another server, do not copy the DB file; use [Backup and restore](guide:backup-restore), which also runs migrations.

## First administrator

```
php tools/create_admin.php --login=<login ID> --name=<display name>
```

- Before migrations it fails and tells you to run migrate first. If an active administrator exists, it creates none. Add more administrators on the user management screen.
- Without options, a fixed initial password is set and printed. A change is not forced at first sign-in, so you can start right away. On a server reachable from outside, change it in My account after signing in. Until you do, administrators see a warning banner that cannot be closed on every screen and a warning on the Monitoring screen, and the hardening check warns (with stronger wording when app.base_url is reached from other PCs).
- To avoid the fixed value, pass a password that meets the policy on standard input with `--password-stdin`. Passwords are never accepted as arguments.
- A hardening check runs after creation. To rerun only the check: `php tools/create_admin.php --check-only` (add `--insecure` for a self-signed certificate, `--resolve-local` if the name is not in hosts). Fix `[WARN]` items before going live ([Security operations](guide:security)).
- If an administrator loses the password or is locked out, use `php tools/user_cli.php reset-password <login ID>` or `unlock <login ID>`.

## Post-deployment checks

1. `php tools/check_env.php` — no FAIL.
2. `php tools/migrate.php --status` — nothing pending.
3. `php tools/worker.php --status` — running, with a recent heartbeat.
4. From another PC, confirm that `https://<server address>/data/`, `/config/config.ini`, `/bin/`, and `/backups/` all return 404, and that HTTP gets a 301 redirect to HTTPS.
5. Sign in as an administrator, change the password, and check under {{ui:admin_system.title}} > {{ui:admin_tools.title}} that ruff and ESLint are in use.
6. Upload a small project and confirm the analysis completes.
7. Before going live, create the first backup and export the key under {{ui:admin_system.title}} > {{ui:admin_backups.subnav}}.
8. (Optional) Fill the guide {{ui:guide.tab.faq}} tab with starter questions: check what will be added with `php tools/faq_import.php --dry-run`, add them as drafts with `php tools/faq_import.php --draft`, then review them in the {{ui:guide.tab.faq}} tab and publish. Running it again skips questions that already exist and never overwrites edited ones.

## Troubleshooting

- A file listing or `config/` contents appear instead of the app: DocumentRoot is not `public/`, or the project is under `htdocs/`.
- Saving in Settings fails: the web account cannot write the `config/` folder.
- There is no SAML button: `vendor/` may be missing from the bundle ([Deployment bundle](guide:deploy-bundle)).
- Uploads fail partway: check whether `post_max_size`, `LimitRequestBody`, or a proxy body limit is smaller than the chunk size.

License and notices: PJGuardian is licensed under the Apache License 2.0. Bundled third-party components are listed on the [Third-party licenses](/guide/licenses) page; the project license and the warranty, trademark and privacy notices are in `LICENSE`, `NOTICE` and `docs/legal/` in the installation folder.
