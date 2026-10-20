## What and why

<!-- One or two sentences. Link the issue if there is one. -->

## Checklist

- [ ] `php tests/run.php` passes (or `--exclude-needs-bin` without tools in `bin/`), and `node --test "tests/js/*.test.mjs"` passes. Tests were not weakened to make them pass.
- [ ] New or changed routes are in `docs/openapi.yaml` and in the access table of `tests/Integration/RouteAuthMatrixTest.php`.
- [ ] New UI strings are added in **both** Korean and English (`src/Lang/{ko,en}.php`, `public/assets/js/i18n/{ko,en}.js`); colors use `tokens.css` tokens only.
- [ ] New audit actions have descriptions (`Support\AuditActions`, `audit.desc.*`/`audit.act.*` in ko/en, `docs/audit_actions.md`, test samples).
- [ ] New settings keys are in `ConfigSchema` and `config/config.ini.example`.
- [ ] Database changes are a new `migrations/<NNN>_<name>.sql` file plus `docs/db_schema.md` (applied files are never edited).
- [ ] User-visible changes update the guide (`resources/guide/user/{ko,en}`) and, for new documents, `docs/README.md`.
- [ ] `php tools/check_doc_links.php` and `php tools/scan_release.php --git=.` report no problems.
- [ ] No passwords, tokens, keys, personal data or machine-specific paths in code, tests, logs or screenshots.

## How I tested it

<!-- Commands and results; OS (Windows / Linux). -->
