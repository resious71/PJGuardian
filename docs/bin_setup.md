# Preparing `bin/` (analysis and repository tools)

[한국어 상세 절차: `docs/tool_setup.md`(ruff·Node.js·ESLint), `docs/vcs_tools.md`(git·p4)]

PJGuardian runs external tools only from `bin/` (Node.js is the one exception: it is a server runtime set by `tools.node_path`). The repository contains **only the checksum manifests** for the tool versions it was tested with — no executables. The operator downloads the tools from their official locations and places them so that every file matches the manifest; the server verifies the checksums (and runs a smoke test) before it uses a version.

```
bin/ruff/<version>/SHA256SUMS                 manifest (in the repository)
bin/ruff/<version>/windows/ruff.exe           you add (Windows)
bin/ruff/<version>/linux/ruff                 you add (Linux)
bin/eslint/<version>/package.json, package-lock.json, SHA256SUMS   in the repository
bin/eslint/<version>/node_modules/            you add (npm ci)
bin/git/<version>/VERSION.txt, SHA256SUMS     in the repository (Git is optional — repository import)
bin/p4/<version>/VERSION.txt, SHA256SUMS      in the repository (p4 is optional — Perforce import)
```

The versions currently registered are in the manifests themselves (`bin/*/<version>/`).

## 1. ruff (Python analysis)

- Source: the official ruff GitHub releases (project `astral-sh/ruff`), files `ruff-x86_64-pc-windows-msvc.zip` (Windows) or `ruff-x86_64-unknown-linux-gnu.tar.gz` (Linux), each with a `.sha256` file.
- Check the archive hash against the `.sha256` file, extract only the `ruff` executable into `bin/ruff/<version>/<os>/`, then compare it with `bin/ruff/<version>/SHA256SUMS`.
- License: MIT.

## 2. Node.js (runtime for ESLint)

- Source: the official Node.js download site (an LTS release, at least `tools.node_min_version`, 20.19.0 by default). Install it system-wide so that the web server and worker accounts find `node`, or set `tools.node_path`.
- License: MIT. Node.js is not placed in `bin/`.

## 3. ESLint (JavaScript/TypeScript analysis)

- Source: the npm registry. In `bin/eslint/<version>/` run `npm ci --ignore-scripts` — it installs exactly the versions pinned in `package-lock.json`. Without internet access, build the folder on another machine and copy it.
- Then compare all files with `bin/eslint/<version>/SHA256SUMS`.
- License: MIT (dependencies: MIT, Apache-2.0, BSD, ISC and similar — see `package-lock.json`).

## 4. Git (optional)

- Windows: MinGit from the official Git for Windows releases; the expected zip and its SHA-256 are written in `bin/git/<version>/VERSION.txt`. Extract it into `bin/git/<version>/windows/`.
- Linux: there is no official binary — build from the official kernel.org source with `tools/build_git_linux.sh` (see `docs/vcs_tools.md`).
- License: GPL-2.0. If you redistribute Git, you must also offer its source (see `THIRD_PARTY_NOTICES`).

## 5. Perforce p4 (optional)

- **p4 is proprietary software and is never redistributed by this project.** Download it yourself from Perforce's official site after accepting Perforce's license terms, and place it in `bin/p4/<version>/<os>/`. `bin/p4/<version>/VERSION.txt` records the file names and checksums the server expects.

## 6. Checking

```
php tools/fetch_bin.php --verify          # compare every bin/ version with its manifest (offline, read-only)
php tools/check_env.php                   # PHP, extensions, folders and tools
php tools/tools_ctl.php list              # what the server will use (checksum + smoke test)
```

In the web UI: **System admin > Analysis tools**. Admins can also add a new tool version there (package upload, installed by a server administrator with `tools/tools_ctl.php install`).

## 7. Download helper

`tools/fetch_bin.php` can download ruff and MinGit from their official locations and keeps a file **only if** its SHA-256 matches the manifest in this repository (otherwise it deletes it):

```
php tools/fetch_bin.php --plan                         # what would be downloaded, from where, to where (no network)
php tools/fetch_bin.php --download=ruff [--os=linux]   # ruff for the registered version
php tools/fetch_bin.php --download=git                 # MinGit (Windows)
```

ESLint (`npm ci`), Node.js and p4 are not downloaded by the helper; `--plan` prints the steps. The helper was written without network access during development and **has not been run against the real download sites yet** — check its output the first time you use it.
