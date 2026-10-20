---
key: projects
title: Viewing projects
order: 2
summary: Which projects you can see, what the project list and project details show, and what group managers can do
keywords: project, access, mapping, group, group manager, member, excluded, defect summary
related: upload, analysis-status, defects, getting-started
audience: user
---

## What a project is

Code to analyze is managed per project. Each project has a language, and the language decides the analysis tool.

- Python projects: analyzed with ruff.
- JavaScript/TypeScript projects: analyzed with ESLint.

Only administrators create, edit, and delete projects and decide who can see them.

![Project list screen. The projects you can see are listed with name, language, description and creation date.](guide-img:projects-list)

## Projects you can see

These people can see a project:

- Administrators (admin): all projects
- Users mapped directly to the project
- Members of groups mapped to the project (including that group's group managers)

A project mapped to no one is visible only to administrators.

If you can see a project, you can view it, upload to it, and manage its defects. There is no separate view-only level.

### Projects you cannot see

A project you cannot see does not appear in lists, and opening its address directly shows the same screen as a project that does not exist. The same applies to its uploads, analyses, defects, code, history, and notifications.

If a project you need is missing, ask an administrator for access. Mapping changes take effect from the next request, so just refresh the page.

## Project list

Select the {{icon:nav.projects}} menu to see the projects you can access.

- The name, language (Py or JS/TS), description, and creation date are shown.
- Projects with scheduled analysis enabled show a {{icon:source.schedule}} tag with the next scheduled run time.
- Select a name to open the project details.

If the list is empty, you do not have access to any project yet. Ask an administrator.

## Project details

The project details screen contains:

1. Buttons at the top: {{icon:action.upload}} {{ui:upload.button}} (opens the upload screen for this project), {{ui:history.title}}
2. Repository connection status: shown only for projects an administrator connected to a Git or Perforce repository. It shows the last fetch time and revision, the next scheduled run, and more.
3. Defect summary: defect counts by severity, status, and assignee
4. {{ui:projects.summary_latest}}: status and results of the most recent analysis
5. Tabs: {{ui:defects.tab}} (defect list), {{ui:analyses.tab}} (analysis list)
6. {{ui:projects.excludes_title}}: items left out of extraction and analysis

If unresolved defects exceed the warning threshold ({{limit:limits.project_defect_warn}}), a warning appears at the top. Check whether generated or minified files are mixed in.

### Excluded items

There are two kinds of exclusions:

- {{ui:projects.excludes_default}}: always applied to every project. An item is excluded only when one part of its path exactly matches a name.
- {{ui:projects.excludes_custom}}: patterns an administrator added for this project.

To change exclude patterns, ask an administrator.

## What group managers can do

Group manager is not a system role; it is assigned per group. Group managers see a {{icon:ui.group_manager}} mark next to their role in the top bar and manage their groups from the {{icon:nav.groups}} menu.

In their own groups, group managers can:

- Find existing active users and add them to or remove them from the group (search by login ID or name)
- Make other members of the same group group managers, or remove that role
- Create new accounts: they are created with the regular user role and become members of that group. The temporary password is shown only once, on the creator's screen.

They cannot (ask an administrator instead):

- Change an account's role, deactivate it, or reset or change its password
- Create, rename, or delete groups
- Map projects to groups or users
- Remove themselves from the group or remove their own group manager role

When group membership changes, the set of people who can see the projects mapped to that group changes immediately.

## Related

- [Upload](guide:upload)
- [Analysis status](guide:analysis-status)
- [Managing defects](guide:defects)
- [Getting started](guide:getting-started)
