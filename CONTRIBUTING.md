# Contributing

Thank you for helping. This page explains how to set up a development environment, run the tests, and what every change must keep consistent. The detailed rules (in Korean) are in [CLAUDE.md](CLAUDE.md) and [docs/architecture.md](docs/architecture.md); the tests enforce most of them.

## Development environment

1. PHP 8.2 or later with `pdo_sqlite`, `zip`, `mbstring`, `openssl`, `fileinfo`, `intl` (CLI); Node.js 24; Git; Composer.
2. `composer install` (creates `vendor/`), and `npm ci --prefix tests/tools` (a YAML parser used by JavaScript tests).
3. Copy `config/config.ini.example` to `config/config.ini` and `config/secrets.example` to `config/secrets.ini`, run `php tools/make_secret_key.php` and `php tools/migrate.php`.
4. For tests that run the real analysis tools, place ruff, ESLint and Node.js as in [docs/bin_setup.md](docs/bin_setup.md).
5. Serve `public/` with Apache over HTTPS ([docs/xampp_vhost.md](docs/xampp_vhost.md)) or, for quick checks, `php -S 127.0.0.1:8099 -t public` on a separate database and data folder — never on a shared configuration.

## Running the tests

```
php tests/run.php [filter]                 # PHP unit + integration tests
php tests/run.php --exclude-needs-bin      # without tools in bin/ (what CI runs)
node --test "tests/js/*.test.mjs"          # JavaScript tests
php tools/run_all_tests.php [--skip-e2e]   # everything, with a summary
php tools/check_doc_links.php              # broken links and missing repository paths in documents
php tools/scan_release.php --git=.         # secrets and personal data in tracked files
```

- CI (`.github/workflows/ci.yml`) runs the PHP tests with `--exclude-needs-bin` because it has no tools in `bin/`. A test that executes real `bin/` tools (ruff, ESLint, git or p4 binaries, Node) must say so with `@needs-bin <reason>` in its class or method docblock; otherwise it fails in CI. On a development machine, run everything without the option.
- To reproduce CI locally: `composer install`, `npm ci --prefix tests/tools`, `php tools/check_doc_links.php`, `php tools/scan_release.php --git=.`, `node --test "tests/js/*.test.mjs"`, `php tests/run.php --exclude-needs-bin`. Status and policy: [docs/ci_status.md](docs/ci_status.md).
- Never change an expected value just to make a test pass, and never weaken a test. Keep earlier tests passing.
- Browser checks (`tools/check_*.mjs`) run against an isolated install with fake data; they never use your real configuration or database.

## Rules every change must keep

- PHP files start with `declare(strict_types=1);` and use the `PJGuardian\` namespace. SQL only through prepared statements. External commands only through `proc_open` with an argument array (no shell), and only from `bin/` (Node.js is the exception).
- Escape everything shown on screen; no `innerHTML` with user input; CSP is `script-src 'self'`.
- Do not use `\v`, `\R`, `\h` or `\s` in regular expressions without the `/u` flag (they break Korean text); split lines with `/\r\n|\n|\r/`.
- Error and UI messages are string resource keys, not literal text in code.

### When you add …

| You add | Also do (tests check most of this) |
| --- | --- |
| a route | `docs/openapi.yaml`, the access table in `tests/Integration/RouteAuthMatrixTest.php`, `RequireProjectAccess` for project resources |
| UI text | both `ko` and `en` (`src/Lang/`, `public/assets/js/i18n/`), same keys and placeholders, glossary `docs/i18n_glossary.md` |
| a color | a token in `tokens.css` (light and dark), then `node tools/check_theme_contrast.mjs` |
| an audit action | `Support\AuditActions`, `audit.desc.*`/`audit.act.*` in ko/en, `docs/audit_actions.md`, a sample in `tests/Support/AuditSamples.php` |
| a settings key | `ConfigSchema` and `config/config.ini.example` (and `docs/settings.md` if editable on screen) |
| a database change | a new `migrations/<NNN>_<name>.sql` and `docs/db_schema.md` — never edit an applied migration |
| a user-visible feature | the guide section in `resources/guide/user/{ko,en}` (`docs/guide_authoring.md`) |
| a document | a row in `docs/README.md` |
| a third-party library | `THIRD_PARTY_NOTICES`, `NOTICE`, `docs/third_party.md` (`tests/Unit/LicenseNoticeTest.php` checks them) |

### Screenshots

Images in `docs/screenshots/` are taken from an install with fake data. When the screens change, re-create them with `node tools/make_readme_screenshots.mjs` (guide images: `node tools/make_guide_screenshots.mjs`) and look at every image before committing.

## Commits and pull requests

- One topic per pull request; describe what changed and how you tested it (OS included). Fill in the pull request checklist.
- Do not commit passwords, tokens, keys, personal data, `config/config.ini`, `config/secrets.ini`, databases, or executables in `bin/`.
- Security problems go to [SECURITY.md](SECURITY.md), not to issues.

## License of contributions

By submitting a contribution, you agree that it is licensed under the [Apache License 2.0](LICENSE) (section 5), the same as the project.
