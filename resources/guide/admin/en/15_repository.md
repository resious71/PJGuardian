---
key: repository
title: Source repository integration
order: 15
summary: Connecting Git (GitHub) and Perforce, encrypted credentials, allowed hosts and connection tests, fetch and analyze now, failure reasons and symbolic link rules
keywords: repository, Git, GitHub, GitHub Enterprise, Perforce, token, credentials, allowed hosts, connection test, certificate, fingerprint, fetch, symbolic link
related: schedule, settings, projects-admin, security
audience: admin
---

## What this feature does

When a project is connected to a Git repository (GitHub, GitHub Enterprise, in-house HTTP Git) or Perforce, the server fetches the latest source itself and analyzes it, with no upload. Uploads keep working after you connect.

- The worker fetches the source as the first step of the analysis job. Queue and concurrency rules and safe extraction rules (excludes, size and file count limits, path and name checks) are the same as for uploads.
- The server connects only to hosts on the allowed hosts list. Addresses must be `https://` (or `http://` where allowed); SSH and file addresses are not supported.
- Only verified git and p4 executables from the server's `bin/` are used; see them, read-only, on the {{ui:admin_tools.title}} tab.

Only administrators configure connections and use {{ui:repo.fetch_now}}. Regular users see only the source, connection status, last fetch and last analyzed commit in the {{ui:repo.title}} box on the project screen (no address, user name or credentials).

## Before you start: encryption key and allowed hosts

### Encryption key

Tokens, passwords and tickets are stored encrypted with the server's encryption key, which lives outside the database (secrets file or environment variable).

Without a key, credentials cannot be saved and the connection screen says so. Create it on the server (use `--rotate` to change it):

```
php tools/make_secret_key.php
```

- The key value is never shown or logged; it goes only into the file. Restart the web server and the worker after creating or changing it.
- With `--rotate` the previous key still decrypts, so everything keeps working; saving each connection again re-encrypts it with the new key.
- A lost key means stored credentials cannot be recovered. If the connection screen says they cannot be read, create a new key and enter them again. Keep the key file separate from database backups.

### Allowed hosts

![The Allowed hosts section at the top of the Settings > Repository connections tab. It shows three rule lines, the host, port, Allow HTTP, GitHub-compatible and note fields with the Add button, the Test all button, and the host table (port, HTTP allowed, GitHub-compatible, note, last test, certificate expiry and actions columns, with Connection test and Delete buttons on each row) (sample hosts).](guide-img:admin-repo-hosts)

Register hosts in the {{ui:repo_hosts.title}} section at the top of {{ui:icon.admin.tools}} > {{ui:config_settings.title}} > {{ui:config_settings.group.repository}}.

1. Enter the host (name or IP) and port. A port of 0, or empty, allows every port.
2. Only if you must use plain HTTP, turn on {{ui:repo_hosts.field.allow_http}}. Tokens are then sent in plain text, so prefer HTTPS.
3. For servers with GitHub-style commit addresses, such as GitHub Enterprise, turn on {{ui:repo_hosts.field.github_links}} so the analysis history links to commits.
4. Select {{ui:repo_hosts.add}}. Table values are saved right away, separately from the settings save button at the bottom.

If a registered name resolves to a link-local or metadata address, the server does not connect. The list is checked on every connection, so removing a host stops fetching and scheduled analysis for every project that uses it. Check those projects before removing a host.

## Testing allowed hosts

This checks whether the host itself is reachable, without a repository or credentials. {{ui:host_test.test}} next to a row checks that host; {{ui:host_test.test_all}} above the table checks registered hosts in turn, up to {{limit:repository.host_test_max}} at a time (check the rest row by row). The test runs name resolution, TCP connect, TLS (certificate) and the HTTP status line, and shows each step. Results stay in the {{ui:host_test.col.last}} and {{ui:host_test.col.cert}} columns.

- Certificate: expiry date, days left, issuer, name match and trust; a warning when expiry is within {{limit:notification.cert_expiry_warn}}.
- HTTP: 401 and 403 ("responded, authentication required") are fine, and so is a 404-style answer at the root (the host was reached). 5xx and 407 (proxy authentication required) are failures.
- Perforce fingerprint: for a non-HTTP TLS port, the server fingerprint is shown but never trusted automatically. Enter only a value confirmed with the server administrator.
- An in-house CA server shown as untrusted: set the server's `repository.ca_cert_file` to the CA certificate file (verification cannot be turned off).
- A host reachable only through a proxy shows as a connect failure; there is no repository proxy setting.

These results are separate from a project's connection status. Connection tests are limited to {{limit:repository.test_per_minute}} per minute per user, shared with project connection tests (Test all counts as one).

## Connecting a repository to a project

1. In {{ui:icon.admin.projects}}, open the project and select {{ui:projects.edit_button}}.
2. In {{ui:repo.title}}, choose the {{ui:repo.field.kind}} ({{ui:repo.kind.none}}, Git, Perforce).
3. Git: repository address, branch (empty = default branch), sub-path (optional), user name (optional) and a read-only token. Do not put a user name or token in the address.
4. Perforce: server address (`ssl:host:port` for SSL), one depot path, user name and a password or ticket. For SSL, enter the confirmed fingerprint in {{ui:repo.field.fingerprint}}.
5. Set {{ui:repo.field.fetch_timeout}} if needed (empty = server default {{limit:repository.fetch_timeout}}).
6. Select {{ui:repo.test}}; unsaved values can be tested. It succeeds when it shows the latest commit (Git) or changelist (Perforce), and must finish within {{limit:repository.test_timeout}}.
7. Select {{ui:repo.save}}. If the host is not on the allowed list, nothing is saved and the screen says where to register it.

- Use a dedicated read-only account or token. Saved credentials are never shown again (only that they are set, and their kind); leaving the field empty keeps the stored value.
- {{ui:repo.disconnect}} removes the connection and its credentials and returns the project to upload only, turning off scheduled analysis. Source records of finished analyses remain.

## Fetch and analyze now

Select {{icon:action.fetch_now}} {{ui:repo.fetch_now}} in the {{ui:repo.title}} box on the project screen to queue an analysis; follow it on the project's analyses tab.

- A disabled button shows why: no connection, host not allowed, credentials unreadable, or an analysis already queued or running.
- If the commit or changelist and the analysis conditions match the last successful analysis, it ends as "unchanged" and the screen asks whether to analyze anyway; {{ui:repo.force_confirm}} analyzes the same revision again. See [Scheduled analysis](guide:schedule) for the rules.
- The analysis history records the source: branch and short commit hash, or depot path and changelist.

## When a fetch fails

The failure reason is recorded on the analysis and shown in its details. Existing defects are left as they are.

- `repo_auth_failed`: re-enter the token or password, check read access, then run a connection test.
- `repo_unreachable`, `repo_timeout`: check address and port, the server, firewalls or proxies; the allowed host test shows which step fails. For a large repository, raise the fetch time limit.
- `repo_not_found`: check address, branch, sub-path and depot path for typos, and read access.
- `repo_host_not_allowed`: check that the host is allowed, and Allow HTTP for an HTTP address.
- `repo_tls_untrusted`: check the in-house CA setting or the Perforce fingerprint.
- `repo_secret_unreadable`: the encryption key changed or is missing. Check the key or re-enter the credentials.
- `repo_tool_unavailable`: check the git and p4 checksum and smoke test results on the {{ui:admin_tools.title}} tab.
- `repo_limit_exceeded`: the source exceeded {{limit:extract.max_extracted_size}} or {{limit:extract.max_files}} files. Narrow the sub-path or depot path, or add exclude patterns. The limits are set on the **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}** tab.
- `repo_empty`: no files with target extensions. Check the branch, path and project language.
- `repo_unsafe_content`: path traversal, names differing only in case, names Windows cannot use, overlong paths, or nested zips. Exclude those paths or clean up the repository.
- `repo_fetch_failed`, `repo_not_configured`: recheck the connection settings; if it persists, check the server log.

A {{ui:repo.fetch_now}} run started by a person is not retried. For scheduled analysis retries, see [Scheduled analysis](guide:schedule).

## Symbolic links and safety rules

- Symbolic links in a repository (Git link entries, the Perforce symlink type) are skipped: neither followed nor turned into files, and their targets are not read. The analysis continues, and its details show "Skipped N symbolic links in the repository (not analyzed)" with the first few paths. If only links remain, it fails with `repo_empty`.
- This skipping applies only to fetched source; symbolic links inside an uploaded zip are still rejected.
- Git hooks, submodules and LFS filters are never run; repository files cannot run commands on the server.

## Things to watch out for

- Keep the development exception `repository.allow_local_dev` off on production servers. When it is on, the {{ui:repo_hosts.title}} section shows a warning.
- Verify in your production environment that your in-house Perforce or GitHub Enterprise server actually connects. Check in this order: allowed host test, project connection test, then {{ui:repo.fetch_now}}.
