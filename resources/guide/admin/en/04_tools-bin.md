---
key: tools-bin
title: Deploying tools in bin/
order: 4
summary: How ruff, ESLint, git, and p4 are laid out by version in bin/, installing and removing versions, SHA-256 manifests and verification, pinning and blocking, the Node.js runtime, and how version changes affect analyses
keywords: bin, ruff, ESLint, Node.js, git, p4, version, install, package, staging, tools_ctl, SHA256SUMS, manifest, checksum, smoke test, pin, block, reverify, remove, node_path
related: install, deploy-bundle, worker, linux-ops, rules-admin, repository, troubleshooting
audience: admin
---
## Analysis tools and bin/

Every executable used for analysis lives under `bin/`, organized by tool and version. The server does not run executables outside this folder (the only exception is the Node.js runtime, set by a config path).

```
bin/ruff/<version>/windows/ruff.exe, linux/ruff, SHA256SUMS
bin/eslint/<version>/package.json, package-lock.json, node_modules/, SHA256SUMS
bin/git/<version>/windows/cmd/git.exe, linux/bin/git, SHA256SUMS, VERSION.txt
bin/p4/<version>/windows/p4.exe, linux/p4, SHA256SUMS, VERSION.txt
```

- Version folders are named with three numbers only (semver), such as `0.16.10`.
- `SHA256SUMS` is a manifest in `sha256sum` output format (`<hash> *<relative path>`). It lists every file the version needs to run.
- Versions currently used for analysis: ruff {{version:ruff}}, ESLint {{version:eslint}}.
- Do not give the web or worker accounts write permission on `bin/` or the manifests. Installation and removal are done from the command line by a server administrator account that can write `bin/`.

## How versions are selected and verified

For every analysis, the server picks the version to use for each tool.

1. If a version is pinned, only that version is used. Otherwise the highest semver version is considered first. Blocked versions are skipped. ESLint uses 10.x only.
2. A version seen for the first time, or whose files changed, has every file in its manifest checked with SHA-256 and then gets a smoke test (it analyzes built-in samples and compares the defect counts with the expected ones). It is used only if both pass.
3. If either fails, the version is marked as failed verification and the previous valid version is used.
4. For already verified versions, the full hash is not recomputed for every analysis; only a fingerprint of file sizes and modification times is compared. If the fingerprint changes, step 2 runs again.
5. Tampering that also preserves the fingerprint is not caught until the next full verification. That is why write access is restricted, and why you should rerun full verification during regular checks with `php tools/tools_ctl.php verify ruff` and `verify eslint`.

If no version is usable, or the pinned version cannot be used, analyses for that tool fail with `tool_unavailable`.

## Admin screen: Analysis tools tab

{{ui:admin_system.title}} > {{ui:admin_tools.title}} ([Analysis tools screen](/admin/tools)) shows a version table for each tool.

- States: in use, pinned, blocked, marked for removal, removed, failed verification, not yet verified, files changed, unsupported major version, unused, and reverification queued or running.
- A line above the table explains which version analyses use now and why (pinned, latest, fell back to the previous valid version, pinned version unusable, or to be verified at the next analysis).
- Every action asks for a reason in a confirmation dialog and is recorded in the audit log.
- {{ui:admin_tools.action.pin}} and {{ui:admin_tools.action.unpin}}: stop automatic selection of the latest version and use one version only, or undo that (`tool.pin`, `tool.unpin`).
- {{ui:admin_tools.action.block}} and {{ui:admin_tools.action.unblock}}: stop using a version and fall back to the previous valid one, or undo that (`tool.block`, `tool.unblock`).
- {{ui:admin_tools.action.verify}}: asks the worker to run the full checksum and smoke test (`tool.reverify_request`).
- {{ui:admin_tools.action.mark_removal}} and {{ui:admin_tools.action.unmark_removal}}: a marker before deletion (`tool.mark_removal`, `tool.unmark_removal`).
- Only verified versions can be pinned. Web requests never run verification, so for a version with no record or with changed files, request reverification first. The worker performs it, and analyses for the same tool may wait briefly while it runs.
- A pinned version cannot be blocked or marked for removal. Unblocking a version makes it go through verification again at the next selection.
- git and p4 are shown read-only (version, checksum, and check results). Block, pin, and install them on the server.

The command line uses the same functions: `php tools/tools_ctl.php list|select|verify|pin|unpin|block|unblock|node [tool] [version]`. Exit codes: 0 success, 1 failure, 2 usage or configuration error.

## Installing a new version

1. Download the tool from its publisher and compare it with the official checksum. Keep downloaded files in an empty temporary folder outside `bin/`.
2. Build a zip with the same layout as `bin/`: a single top-level `<tool>/<version>/` folder containing `SHA256SUMS` and the executable for this OS (ruff `windows/ruff.exe` or `linux/ruff`, ESLint `node_modules/eslint/bin/eslint.js`).
3. In {{ui:admin_tools.staging_title}} on the Analysis tools tab, choose the tool and upload the zip (limit {{limit:tools.package_max_size}}). If you enter the SHA-256 published by the vendor, it is compared, and on a mismatch the install command is not shown. The web app only stores the package.
4. Run the command shown on the screen with the server administrator account.

```
php tools/tools_ctl.php install --staging=<id>
```

- Check order: staged state, vendor hash, same hash as at upload time, safe extraction (path traversal, absolute paths, symbolic links, and nested zips are rejected), layout, version name and executable, full manifest checksum, rejection if the version folder already exists, move into `bin/`, then full checksum and smoke test.
- On failure nothing is left in `bin/`. The result (success or the failure reason) appears on the package entry on the screen. The zip is deleted after a successful install.
- Packages that are not installed are cleaned up after {{limit:tools.staging_retention}}.
- The install command handles ruff and ESLint only. If you build ESLint with npm yourself, install exact versions with `npm install --save-exact --ignore-scripts` and create the manifest.

## Removing old versions

1. Versions no longer selected show as unused in the table. {{ui:admin_tools.action.mark_removal}} stops them from being used and shows the removal command.
2. Run `php tools/tools_ctl.php remove <tool> <version>` on the server.

- It refuses a pinned version, a version used by a running analysis, and the last valid version (removing it would leave nothing usable).
- The folder is moved to `bin/.trash/` and then deleted. The version record stays as history to explain the tool versions of past analyses.

## How version changes affect analyses

- Each analysis records the tool version (for ESLint, including the Node version), the config source, and the config hash.
- If any of these differ from the previous successful analysis, the Run table in history shows "{{ui:runs.changed}}", and the dashboard trend point gets a change marker too. History comparison shows the notice "{{ui:history.warn_tool_change}}".
- A new version with added or changed rules can produce new or resolved defects even when the code is unchanged. Tell users before upgrading in production, and pin a version if you need to control the timing.
- For projects with a repository connection, a tool or config change means the analysis runs instead of being skipped as unchanged, even if the source is the same.
- Pinning or blocking can change the active version, so when automatic rule data loading (`rules.auto_load`) is on, a rule description data load is requested from the worker ([Rule administration](guide:rules-admin)).

## Node.js runtime

ESLint runs on a Node.js installed outside `bin/`.

- Setting `tools.node_path` to the absolute path of the node executable is recommended. If empty, node is looked up on the worker account's PATH, and a service account's PATH can differ from a user's PATH.
- The minimum version is `tools.node_min_version` (currently {{limit:tools.node_min_version}}). The worker checks it at startup; if there is a problem, ESLint analyses fail with `tool_unavailable`.
- The Node.js entry on the Analysis tools tab shows the status, version, and where the path came from (setting or PATH). The path itself is not displayed.
- After replacing Node or changing the path, select {{ui:admin_tools.node_refresh}} to have the worker reread the settings and check again. The Node path that analyses use changes only after the worker restarts ([Running the analysis worker](guide:worker)).

## Placing git and p4

git and p4, used by repository integration, follow the same rules in `bin/git/<version>/` and `bin/p4/<version>/`.

- On Windows, extract the MinGit archive into `windows/` as is (entry point `windows/cmd/git.exe`). For p4, verify the executable from the official site with its checksum or signature before placing it.
- There is no official Linux git binary, so build it with `tools/build_git_linux.sh --gpg` on the same distribution as the production server ([Linux operations](guide:linux-ops)).
- For a new build of the same version, replace the whole folder and regenerate the manifest and `VERSION.txt`.
- Checks: `php tools/check_bin_vcs.php` (manifest, tamper detection, executable format), `php tools/tools_ctl.php verify git`, and `verify p4`.
- If git or p4 cannot be used, repository fetches fail with `repo_tool_unavailable`.

## Permissions

- Windows: in the security settings of the `bin` folder, give the web server and worker accounts read and execute only, and give modify permission only to the administrator account that installs tools (check with `icacls`).
- Linux: the installing account owns the folder; the web and worker accounts are in the group with read access only.
- The Checks screen warns if the web account can write to `bin/`. On Windows this check may not reflect ACLs, so verify directly with `icacls bin`.

## Troubleshooting

- Analyses fail with `tool_unavailable`: look at the "version in use" line and the failure reason (file with a checksum mismatch, smoke test result) on the Analysis tools tab, then reinstall the version or unpin. For ESLint, also check the Node status.
- The state is "files changed": someone modified the version folder. If the change was intended, regenerate the manifest and request reverification.
- The install command does not appear: the vendor hash does not match. Download the file again and upload it.
- Installation is refused: the version folder already exists or the zip layout is wrong. Check the reason in the result.
