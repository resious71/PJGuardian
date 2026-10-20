---
key: deploy-bundle
title: Deployment bundle
order: 2
summary: How to build the deployment bundle for a production server, what goes in and what stays out, and how to check it with tools/verify_bundle.php
keywords: build_bundle, deployment bundle, bundle, vendor, Composer, composer install, resources/guide, verify_bundle, bundle check, upgrade, config.ini, secrets.ini, settings.local.ini, pjgkey
related: install, tools-bin, backup-restore, auth-saml
audience: admin
---
## What must be included

A production bundle must include `vendor/` (Composer libraries) and `resources/guide/` (guide text and images).

- If `vendor/` is missing, only SAML sign-in is disabled: the sign-in screen shows no SAML button, and when SAML is enabled the Checks screen reports that the library is missing.
- `vendor/` is not in git, so add it on the build PC when you build the bundle. You do not need to run Composer on the production server.
- The analysis tools in `bin/` are deployed separately from the bundle ([Deploying tools in bin/](guide:tools-bin)).

## Building the bundle

1. On the build PC, run `composer install --no-dev --optimize-autoloader`.
2. Build the bundle. The script copies the repository files and `vendor/` into a new folder under `dist/`, writes the bundle information (`BUNDLE.json`) and file checksums (`SHA256SUMS`), and then checks the bundle right away.

```
php tools/build_bundle.php --zip
```

- Test code, development tools, step reports, and test zips are left out. The sample code in `samples/` used by the analysis tool check (smoke test) is included. `data/` contains only the empty folder skeleton.
- `bin/` contains only the checksum lists. To carry the analysis tools to a server without internet access, add `--with-bin`.
- The script stops if there are uncommitted changes.

Check a folder you bundled by hand separately.

```
php tools/verify_bundle.php <bundle folder>
```

## What is checked and the result

- Must be present: `vendor/autoload.php`, guide text in `resources/guide/user/ko` and `en`, the default FAQ questions `resources/faq/seed.ko.json`, the analysis tool check samples (`samples/python_sample`, `python_syntax_error`, `js_sample_noconfig`, `ts_sample` — without them every analysis tool is unusable on a new installation), every image listed in `resources/guide/screenshots.json`, and matching package versions in `composer.lock` and `vendor/composer/installed.json`.
- Must be absent: `tests/`, `.git`, data files in `data/` (only the empty-folder marker `.gitkeep` is allowed), the per-server config and secret files (`config/config.ini`, `config/secrets.*` except `config/secrets.example`, and `config/settings.local.ini`), and `.pjgkey` key files.
- If config, secret, or key files are in the bundle, the tool reports them as problems. Example files in `config/`, such as `config/config.ini.example`, are allowed.
- Result: 0 when it passes, 1 with a list of FAIL lines when there are problems, 2 on usage errors such as a missing folder argument. The tool only reads; it does not change any files.

## When upgrading

Use upgrade maintenance mode to notify users in advance and block changes while you upgrade.

1. **Announce in advance**: under {{ui:admin_system.title}} > {{ui:config_settings.title}} > {{ui:config_settings.group.maintenance}}, save "{{ui:config_settings.key.maintenance.notice_at.label}}" and "{{ui:config_settings.key.maintenance.message.label}}". Until that time, every user sees an advance notice at the top of the screen; it disappears by itself after the time passes.
2. **Turn on maintenance mode**: on the same tab, turn on "{{ui:config_settings.key.maintenance.enabled.label}}" and save. When you save, the worker first backs up the database (`pre_upgrade`, without snapshots — an upgrade only changes the database structure). If the worker is off, the backup fails, or it does not finish within {{limit:maintenance.backup_wait}}, the mode is not turned on and the reason is shown.
   - While it is on, non-administrators can only view. Uploads and changes are refused with the notice text, and a maintenance banner appears at the top of the screen. Administrators can do everything.
   - The worker starts no new or scheduled analyses and finishes the ones in progress. The health check response includes `maintenance: true`.
3. When the analyses in progress finish (0 running under {{ui:admin_system.title}} > {{ui:admin_worker.title}}), stop the worker, replace the code, run migrations, then start the worker again (see the migrations part of [Installation and environment](guide:install)).
4. When you have checked everything, turn maintenance mode off. The setting stays in the screen settings file, so it is still on after the upgrade.

- If something goes wrong, put the previous bundle's code back and restore the `pre_upgrade` backup made when the mode was turned on ([Backup and restore](guide:backup-restore)).
- When working only from the command line, back up with `php tools/backup.php --kind=pre_upgrade` and follow the same order.
- When replacing the code, do not overwrite `config/config.ini`, `config/secrets.ini`, `config/settings.local.ini`, `data/`, `bin/`, or `backups/`; replace `vendor/` with the one from the new bundle.

## Troubleshooting

- There is no SAML button: check with `tools/verify_bundle.php` whether `vendor/` is missing from the bundle.
- The bundle check fails because of config or secret files: delete those files from the bundle folder and check again. Create the production server's configuration on the server, following [Installation and environment](guide:install).
