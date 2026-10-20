---
key: overview
title: About the admin guide
order: 0
summary: What administrators can do, where the admin menu and System admin tabs are, and a map of this guide
keywords: administrator, overview, menu, system admin, settings, tabs, permissions
related: install, settings, troubleshooting
audience: admin
---

## About this guide

The admin guide is visible only to administrators (admin). Other users never see it in the menu, the contents or search results, and they cannot open it by entering the address.

It covers work that only administrators do: installation, operations, accounts and access, repository integration, backups and so on. Features everyone uses, such as viewing defects, uploading and checking analysis status, are in the [User guide](/guide/user).

Each section follows the same order: what the screen does, the steps, things to watch out for, then troubleshooting. Numbers shown in the guide (limits, durations, defaults) come from the server's current settings, so they change when you change the settings.

## What administrators can do

Administrators can see every project and do the following, which regular users cannot:

- Accounts: create users, change roles and status, unlock accounts, issue temporary passwords, approve accounts created by a first external sign-in, and import accounts from CSV
- Groups and access: create groups and map groups and users to projects
- Projects: create, edit and delete projects, set exclude patterns, connect a source repository, and set up scheduled analysis
- Analysis environment: pin or block analysis tool versions, check the worker and ask it to start or stop, and turn rules on or off
- Server settings: change retention periods, disk thresholds, notifications, repository connections and authentication (LDAP/AD, SSO) from the browser
- Data protection: create and verify backups, restore, and clean up Run details

There must always be at least one active local administrator. The last active local administrator cannot be deactivated or changed to a regular user.

## Admin menu

When you sign in as an administrator, a separate admin menu appears at the top.

1. {{icon:admin.users}} {{ui:icon.admin.users}}: account list, accounts waiting for approval, and CSV import
2. {{icon:admin.groups}} {{ui:icon.admin.groups}}: groups, members and group managers
3. {{icon:admin.projects}} {{ui:icon.admin.projects}}: the project list. Create new projects here and open each project's edit and delete screens.
4. {{icon:admin.tools}} {{ui:icon.admin.tools}}: the server operations screens (tabs below). The menu opens the first tab, {{ui:admin_tools.title}}.
5. {{icon:admin.system}} {{ui:icon.admin.system}}: worker, queue, disk, backup, tool and certificate status with failure alerts (read-only)
6. {{icon:admin.audit}} {{ui:icon.admin.audit}}: audit log search, integrity check and CSV export (read-only)
7. {{icon:admin.guide}} {{ui:icon.admin.guide}}: this guide

## System admin tabs

Use the tabs at the top of the {{ui:icon.admin.tools}} screen to move between screens.

- {{ui:admin_tools.title}} (/admin/tools): ruff and ESLint versions, checksum and smoke test results, pinning and blocking, Node.js status, git and p4 (read-only)
- {{ui:admin_worker.title}} (/admin/worker): worker status, stop, restart and start requests, recent logs
- {{ui:admin_checks.title}} (/admin/checks): configuration and environment checks, disk usage, cleanup, recently failed analyses
- {{ui:admin_runs.title}} (/admin/runs): Runs per project and deleting Run details
- {{ui:admin_rule_settings.title}} (/admin/rule-settings): rules that administrators turned on or off, and resetting them
- {{ui:config_settings.title}} (/admin/config-settings): server settings you can change from the browser
- {{ui:admin_schedules.subnav}} (/admin/schedules): scheduler status, schedules by project, recent failures and skips
- {{ui:admin_backups.subnav}} (/admin/backups): backup list, back up now, verify, restore

### Category tabs in Settings

{{ui:config_settings.title}} has a second row of category tabs. You can also open a tab directly with the hash at the end of the address.

- {{ui:config_settings.group.retention}} (#retention), {{ui:config_settings.group.disk}} (#disk)
- {{ui:config_settings.group.notification}} (#notification): ends with the notification retention section, which has its own save button.
- {{ui:config_settings.group.monitoring}} (#monitoring): health check allowed IPs and the queue stall threshold
- {{ui:config_settings.group.schedule}} (#schedule)
- {{ui:config_settings.group.repository}} (#repository): the allowed hosts section at the top (add, connection test, delete), then the connection test settings
- {{ui:config_settings.group.analysis}} (#analysis), {{ui:config_settings.group.backup}} (#backup)
- {{ui:config_settings.group.auth}} (#auth), {{ui:config_settings.group.auth_ldap}} (#auth_ldap), {{ui:config_settings.group.auth_oidc}} (#auth_oidc), {{ui:config_settings.group.auth_saml}} (#auth_saml)

The review button at the bottom of the screen saves setting items only. Values in separate sections, such as allowed hosts and notification retention, are saved with that section's own button. See [Settings](guide:settings) for details.

## Sections in this guide

Installation and operations

- [Installation and first setup](guide:install)
- [Deployment bundle](guide:deploy-bundle)
- [Running the analysis worker](guide:worker)
- [Managing analysis tools (bin)](guide:tools-bin)
- [Running on Linux](guide:linux-ops)

Accounts and access

- [Managing accounts](guide:accounts)
- [Groups and project access](guide:groups-access)
- [Account CSV import](guide:account-csv)
- [External authentication overview](guide:external-auth)
- [LDAP/AD authentication](guide:auth-ldap)
- [SSO (OIDC)](guide:auth-oidc)
- [SSO (SAML)](guide:auth-saml)

Server settings and data

- [Settings](guide:settings)
- [Retention and disk](guide:retention-disk)
- [Backup and restore](guide:backup-restore)
- [Managing notifications](guide:notifications-admin)
- [System checks and monitoring](guide:monitoring)
- [Audit log](guide:audit-log)
- [Security checks](guide:security)

Projects and analysis

- [Creating and deleting projects](guide:projects-admin)
- [Source repository integration](guide:repository)
- [Scheduled analysis](guide:schedule)
- [Managing rules](guide:rules-admin)

When something goes wrong

- [Troubleshooting](guide:troubleshooting): step-by-step checks for sign-in, analyses stuck in the queue, uploads, SSO, saving settings and disk space.

## Using the guide

- Pick a section in the contents to show only that section. Bookmark the hash in the address (#section name) to come back to it directly.
- Guide search looks only inside the admin guide. Its results are not mixed with user guide results.
- The help link on an admin screen opens the section that explains that screen.
- Error code meanings and actions are on the [Error codes](/guide/errors) tab.
