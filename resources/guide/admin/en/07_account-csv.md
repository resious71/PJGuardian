---
key: account-csv
title: Bulk accounts from CSV
order: 7
summary: Create or deactivate many accounts from one CSV file — format, encoding, limits, preview and run, temporary passwords, and common errors
keywords: CSV, bulk, create accounts, deactivate, import, encoding, CP949, UTF-8, preview, temporary password, result CSV
related: accounts, external-auth, groups-access
audience: admin
---

## What this screen does

It **creates or deactivates many accounts at once** from a single CSV file. Open it with **{{ui:account_import.open}}** at the top of the Users screen ([/admin/users/import](/admin/users/import), admins only).

What this screen does not do:

- Change existing accounts (role, name, reactivation)
- Assign groups. Manage group members on the group screens.
- Set passwords. App accounts get a temporary password.

There are three steps: **choose file → preview → result**. The preview saves nothing, and running either applies everything or nothing.

## CSV format

The safest start is the template from **{{ui:account_import.template}}** (header plus two example rows). The template includes a UTF-8 BOM so that Excel shows non-ASCII text correctly.

The first line is the header. Column order is free, and surrounding spaces and letter case are ignored.

- `action`: `create` (the default when empty) or `deactivate`
- `login_id`: required. Starts with a letter or digit; letters, digits, `.` `_` `@` `-`; up to 100 characters. Duplicates against existing accounts and within the file are checked without regard to letter case.
- `display_name`: required for create. 1 to 100 characters, no control characters, and not the same as another user's login ID (including other rows in the file).
- `email`: optional. Only the format is checked (up to 254 characters). If it matches another account, you get a warning and the account is still created.
- `role`: `user` (default) or `admin`
- `auth_type`: the default method when empty. Only **currently enabled sign-in methods** are accepted. The "CSV format" notes on the screen list the values you can use now.

Other rules:

- Values are separated by commas. Wrap values that contain commas or line breaks in double quotes (a quote itself is written as `""`).
- Empty lines are skipped. Unknown columns are ignored with a warning, and group columns (`group`, `groups`, and so on) are ignored with a "groups are not managed by CSV" warning.
- The limits are **1,000 rows and 2 MiB**. Split larger files.
- Row numbers are line numbers in the file (the header is 1), so they match Excel row numbers when no cell contains a line break.

### Pre-creating external accounts

When LDAP/AD, OIDC, or SAML is enabled, put that value in `auth_type` to pre-create external accounts. These accounts have no password, so no temporary password is issued, and they are created as approved. When the person first signs in through external authentication, the account is linked to the external account with the same login ID. A method that is not enabled makes the row an error.

## Encoding

- **Auto-detect** (default): with a BOM the file is read as UTF-8; otherwise UTF-8 is tried first, then CP949 (the default when Korean Excel saves CSV). CP949 is accepted only if converting back gives the original bytes.
- If the result looks wrong, choose UTF-8 or CP949 in the preview and click **{{ui:account_import.reread}}**.
- The detected encoding and whether a BOM was found appear above the preview. Do not run the import if names look garbled.
- If neither works, you see "The file isn't valid UTF-8 or CP949". Save the file again as "CSV UTF-8" in Excel and upload it.

## From preview to run

1. Choose the CSV file (auto-detect is usually fine) and click **{{ui:account_import.preview}}**.
2. Review the summary (rows, create, deactivate, skipped, errors, warnings, admins to create) and the per-row table. Switch the view to errors only or warnings only to see just the problem rows.
3. If there are error rows, fix the file and upload it again with **{{ui:account_import.restart}}**, or choose to run while skipping error rows. Without that choice the run is blocked (`import_has_errors`).
4. Click **{{ui:account_import.execute}}**. The confirmation dialog shows how many accounts will be created and deactivated, how many admins will be created, and how many error rows will be skipped. Confirm to run.
5. The result screen shows the counts and the per-row result (created, deactivated, skipped, error (skipped)).

- When you run, the browser **sends the same file again** and the server repeats the same checks. The server does not keep preview results.
- If the outcome would differ from the preview, for example because the file changed or an account with the same ID was created in the meantime, nothing is applied and the run is refused with `import_preview_stale`. The screen then previews the same file again and shows the new result.
- If there is nothing to process, you get `import_nothing_to_do`.
- With many app accounts, the run can take over a minute while password hashes are computed (a little over a minute for 1,000 rows). Other work is not blocked meanwhile.

## Temporary passwords and the result CSV

- Temporary passwords for new app accounts are shown **only once, on the result screen**. If you leave or reload the page they are gone, and the server does not keep them in plain text.
- **{{ui:account_import.result_csv}}** works **only once** on this screen. The file contains the temporary password column, so hand it over securely and then delete it. Values that could be read as formulas (starting with `=` `+` `-` `@`) are saved with a leading single quote.
- Temporary passwords are valid for {{limit:auth.temp_password_expiry}} and must be changed at first sign-in (the same rule as adding an account on the Users screen).
- If you missed the result, issue a new temporary password for that account on the Users screen.

## Deactivate rows

- An account that does not exist, or your own account, is an error.
- An account that is already inactive gets a warning and is skipped.
- Values in the name, email, role, or sign-in method columns are ignored (warning).
- A row is an error if applying all deactivations in the file would leave **no active local (app account) admin**.
- Deactivated users are signed out immediately.

## Audit log

- One `user.bulk_import` per run (counts, row count, encoding, the start of the file hash, whether error rows were skipped)
- One `user.create` or `user.deactivate` per user (source csv)

Temporary passwords, email addresses, and the CSV content are never written to the audit log, logs, or error messages. The preview does not write to the audit log.

## Common errors

- **Names look garbled**: read again as CP949 or UTF-8. If that does not help, save the file again as "CSV UTF-8".
- **"More cells than header columns"**: a value containing a comma (for example "Kim, Minsu") is not wrapped in double quotes.
- **"This account already exists"**: IDs that differ only in letter case count as the same account.
- **"The same login ID appears earlier in the file"**: the ID appears twice. The earlier row wins.
- **"This authentication method isn't enabled yet"**: enable that external method first, or leave `auth_type` empty.
- **"The header has no login_id column"**: check that the first line is the header and that the column name is spelled correctly.
- **File too large or too many rows**: split it into files of up to 1,000 rows and 2 MiB.
- **Timeout while running**: the app raises the time limit for this request only, but a web server or proxy in front may have a shorter request timeout. Verify in your production environment. A failed run applies all or nothing, so check the result on the Users screen and start again from the preview.

## Related sections

- [Managing accounts](guide:accounts)
- [External authentication overview](guide:external-auth)
- [Groups and project access](guide:groups-access)
