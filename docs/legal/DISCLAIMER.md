# Disclaimer

[한국어](DISCLAIMER.ko.md)

PJGuardian is provided **"AS IS", without warranty of any kind**, express or implied, as stated in sections 7 and 8 of the [Apache License 2.0](../../LICENSE). In no event shall the authors or contributors be liable for any claim, damages or other liability arising from the use of this software.

## What PJGuardian does and does not do

- PJGuardian collects and displays the results of third-party static analysis tools (ruff for Python, ESLint for JavaScript/TypeScript) and helps a team track them.
- It **does not guarantee** the quality, correctness or security of the analyzed code. A clean result means only that the configured rules of those tools reported nothing. Rules may miss problems or report false positives.
- Rule descriptions and "plain-language" rule guides are helper text. The tools' own documentation is authoritative.

## Operator responsibilities

Whoever installs and runs PJGuardian (the operator) is responsible for:

- backing up the database, configuration, encryption keys and snapshots, and testing restores (`docs/backup_restore.md`);
- securing the server: HTTPS, changing the initial `admin` password right after installation, file permissions, firewall, keeping PHP and other components updated (`docs/hardening.md`);
- the licenses of the external tools placed in `bin/` (ruff, ESLint, Node.js, Git, and in particular the proprietary Perforce `p4` client, which this project never redistributes);
- informing users about the data the service stores ([PRIVACY.md](PRIVACY.md)) and complying with the laws and policies that apply to the organization.

This document is not legal advice.
