---
key: projects-admin
title: Creating and deleting projects
order: 17
summary: How to create and edit projects, what is deleted with a project and when deletion is blocked, and automatic retention and manual deletion of Run details
keywords: project, new project, create, edit, delete, delete permanently, language, exclude patterns, Run management, Run details, retention, delete details
related: groups-access, repository, schedule, retention-disk
audience: admin
---

## What this screen does

A project groups the code to analyze. Only administrators create, edit and delete projects.

- {{icon:admin.projects}} {{ui:icon.admin.projects}} opens the project list. Administrators see every project and its access (administrators only, or the number of mapped groups and users).
- The project details screen has {{ui:projects.edit_button}} and {{ui:projects.delete_button}} at the top. Regular users do not see these buttons.
- Details of past Runs (viewing a past Run) are cleaned up automatically, oldest first, and you can also delete them yourself in {{ui:icon.admin.tools}} > {{ui:admin_runs.title}}.

## Creating a project

1. In the project list, select {{ui:projects.new_button}}.
2. Enter the {{ui:projects.field_name}}: 1 to 100 characters, with no control characters such as line breaks. Names that differ only in case count as the same name.
3. Choose the {{ui:projects.field_language}}. Python is analyzed with ruff, JavaScript/TypeScript with ESLint. The language cannot be changed after the project is created.
4. Enter a {{ui:projects.field_description}} if you like (up to 2,000 characters).
5. Select {{ui:projects.create_submit}}.

A new project is not mapped to anyone, so only administrators can see it. Next, set up:

- Access: map groups and users in {{ui:access.title}} on the edit screen. See [Groups and project access](guide:groups-access).
- Exclude patterns: add one pattern per line on the edit screen. The server's default exclude list always applies and cannot be changed here.
- Source repository and scheduled analysis: see [Source repository integration](guide:repository) and [Scheduled analysis](guide:schedule).

## Editing a project

Select {{ui:projects.edit_button}} on the project details screen to change the name, description, exclude patterns, access, source repository and scheduled analysis on one screen. Name, description and exclude patterns are saved with {{ui:projects.save_submit}}; the repository and schedule sections each have their own button.

- Exclude patterns are matched against paths relative to the analysis root. You can use `*`, `?` and `**`. A leading `/` anchors the pattern at the root, and a trailing `/` matches folders only.
- Empty patterns, `.` or `..` segments, absolute paths, backslashes, and bracket, brace or exclamation mark syntax cannot be saved.
- Pattern changes apply from the next analysis. Defects in newly excluded files keep their status; unlike deleted files, they are not resolved automatically.

## Deleting a project

### Steps

1. On the project details screen, select {{ui:projects.delete_button}}.
2. Check the counts of what will be deleted on the delete screen.
3. Type the project name exactly to confirm. The {{ui:projects.delete_submit}} button turns on only when case and spaces match.
4. Select {{ui:projects.delete_submit}}. The project is deleted at once and you return to the project list.

### What is deleted

Deletion cannot be undone. All of the following are removed:

- Analysis history and Run records
- Defects and their change history
- Code snapshot files
- Upload records (including leftover chunks and work files)
- Access mappings (the groups and user accounts themselves remain)
- The source repository connection, its stored credentials, and the schedule

The audit log keeps a deletion entry (`project.delete`) with the name, language and the numbers of analyses and defects. The only way to bring a deleted project back is to restore the whole server from a backup taken before the deletion. For an important project, check that a backup exists in [Backup and restore](guide:backup-restore) first.

### When deletion is blocked

If the project has a queued or running analysis, or the worker is assembling or extracting one of its uploads, the screen says it cannot be deleted. Try again after the analysis finishes or is cancelled. If a scheduled analysis is about to run, turning the schedule off first is safer.

If removing files fails after the database rows are deleted, the leftovers are kept on a cleanup list and the cleanup job removes them later. The count is shown in the cleanup section of the {{ui:admin_checks.title}} tab.

## Run detail retention

For each Run you can see the defect summary, per-Run details, and defect details and code as they were at that time (viewing a past Run). These details are cleaned up automatically for older Runs.

- Each project keeps details for its latest {{limit:runs.detail_keep}} Runs. 0 turns automatic cleanup off. 1 and 2 are not allowed.
- Cleanup runs after the worker applies a successful analysis, at most {{limit:runs.retention_batch}} Runs at a time; the rest continue after the next successful analysis. It does not run after failed or cancelled analyses.
- The latest Run is always kept.
- Change the number on {{ui:config_settings.title}} > {{ui:config_settings.group.retention}}. Runs already cleaned up do not come back if you raise it.

### What remains after deletion

- Kept: Run number, date, counts (detected, new, resolved, reopened, unchanged, open defects), tool version, the history table, dashboard trends, and defects with their history
- No longer viewable: the defect list, details and code as of that Run. Opening it by address shows "Details of Run N have been deleted" with a link to the latest Run. The Run selector shows it as details deleted and it cannot be chosen.

Deleting details only marks them, so the database file barely shrinks. If your goal is to free disk space, see [Retention and disk](guide:retention-disk).

## Deleting Run details by hand

1. Open {{ui:icon.admin.tools}} > {{ui:admin_runs.title}}.
2. Choose a project. Its Runs are listed newest first with each Run's detail status (details kept, deleted manually, deleted automatically).
3. Select the checkboxes of the Runs to delete. Selections are kept across pages. To select everything except the latest few, set the number and select {{ui:admin_runs.select_old}}.
4. Select {{ui:admin_runs.delete}}.
5. In the confirmation dialog, check the list of Runs, type the project name exactly, and confirm.

- The latest Run and Runs already deleted cannot be selected.
- The result shows how many Runs were deleted and which were skipped because they were already deleted, and an audit log entry (`run.detail_delete`) is written.
- If a new analysis finishes in the meantime and one of your selected Runs becomes the latest, nothing is deleted. Reload the list and select again.

## Troubleshooting

- "A project with the same name already exists": names are compared without case, so a name that differs only in case is a duplicate.
- The delete button stays off: the typed name must match exactly, including leading and trailing spaces.
- A past Run says its details were deleted: it was removed by automatic retention or by hand. Its counts are still in the history table.
