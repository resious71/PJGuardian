---
key: defects
title: Managing defects
order: 7
summary: Severity and status, assigning, bulk edit, change history, automatic resolve and reopen, edit conflicts, searching, and My defects
keywords: defect, severity, status, assignee, bulk edit, change history, automatically resolved, resolved, reopen, file deleted, excluded, conflict, 409, search, filter, my defects
related: code-viewer, rules, history, analysis-status, export, notifications
audience: user
---

## Opening the defect screens

- Open the defect list from the {{ui:defects.tab}} tab on the project details screen, or choose a project from the {{icon:nav.defects}} menu.
- Select a defect in the list to open its details. The details show the defect information, the edit form, the code ([Code viewer](guide:code-viewer)), and the change history.
- Select a rule name to open its [rule description](guide:rules).

A defect is one problem found by an analysis tool. It is tracked as the same defect across analyses; whether two findings are the same defect is decided from the file path, the rule, and the code content involved. When only the line position changes, it is usually still the same defect. If you move or rename a file, the old defects become resolved and the defects at the new location are counted as new.

![Defect area of the project detail page. The Run bar is at the top, followed by the search conditions (keyword, file, severity, status, rule, assignee, sort) with the Search button, then the result count and the export buttons.](guide-img:defect-list)

## Severity

One of {{icon:severity.critical}}, {{icon:severity.high}}, {{icon:severity.medium}}, {{icon:severity.low}}, or {{icon:severity.not_defined}}.

- A new defect gets the default severity set for its rule automatically. Rules without a default get Not set.
- A severity changed by a person is never changed by later analyses.
- Hover over the mark next to the severity to see whether it is "Rule default" or "Manual change".

## Status

One of {{icon:defect_status.open}}, {{icon:defect_status.in_progress}}, {{icon:defect_status.postponed}}, {{icon:defect_status.intentional}}, {{icon:defect_status.not_fix}}, or {{icon:defect_status.resolved}}.

- New defects start as Open.
- You can change the status to any value. Agree with your team on when to use each status.
- Analyses can also change statuses automatically (see "Automatic resolve and reopen" below).

## Changing assignee, severity, and status

1. In "{{ui:defects.edit_title}}" on the defect details, choose the values.
2. If needed, fill in "{{ui:defects.memo_label}}". The reason is kept in the change history.
3. Select {{ui:defects.save}}. The save button is disabled when nothing has changed.

Only active users who can see the project can be chosen as assignee. If a current assignee is deactivated or loses access to the project, the assignment stays and is marked "Inactive" or "No access". For an assignee who lost access, the defect does not appear in their My defects.

## Bulk edit

1. In the defect list, select the checkboxes of the defects to change. "{{ui:defects.select_page}}" selects the whole current page.
2. Choose only the fields to change (assignee, severity, status). Fields left at "(No change)" stay as they are.
3. If needed, enter a reason, then select {{icon:action.bulk_edit}} {{ui:defects.bulk_apply}}.
4. Check the details in the confirmation dialog.

- Selection works within the current page only. Maximum number of defects per bulk edit: {{limit:ui.bulk_update_max}}
- The result shows counts of changed, unchanged, and conflicts. Defects someone else changed in the meantime are not changed; they are listed with their latest values.
- History is recorded separately for each defect.

## Change history

The {{ui:defects.history_title}} on the defect details lists changes, newest first: time, changed by, field, old and new values, type (manual or automatic), and reason. Changes made by analyses are marked "Automatic" and show the reason.

## Automatic resolve and reopen

Defect statuses change automatically **only when an analysis succeeds**. Failed or cancelled analyses change no defects.

- No longer detected in files within this analysis's scope: becomes Resolved (reason "No longer detected by analysis").
- The file is not in this upload: becomes Resolved (reason "File deleted").
- Files matching excluded items: they are out of the analysis scope, so their status is kept. This is distinguished from a deleted file.
- A Resolved defect is detected again: it is reopened as Open (reason "Detected again"). The same applies when a person set it to Resolved but it keeps being detected.

Automatic resolve happens regardless of the current status, including Postponed, Intentional, and Won't fix, and the previous status is kept in the history. This means that if you upload only some of the files, defects in the missing files are resolved as "File deleted". Always upload the whole project.

## Edit conflicts

If another user or an analysis changed the defect after you opened it, your save is rejected and the "Another user changed it first" dialog opens.

1. In the dialog, compare "Your value" with "Latest value". Fields that were changed first are marked.
2. Select {{ui:defects.conflict_retry}} to apply only the fields you meant to change, on top of the latest values. The reason you entered is not lost.

## Search and filters

Choose conditions in the search area above the defect list.

- Search text: part of the message, rule, or file path
- File: path starts with, or path contains
- Severity and status: select chips. The number on a chip is the count based on the other conditions you last applied.
- Rule and assignee: choose from the lists. Hold Ctrl (⌘) and click to select multiple.
- Sort and order, and Run conditions (Run first seen, present in Run)

Choosing conditions alone does not change the list. Select {{icon:action.search}} {{ui:defects.apply}} at the bottom, or press Enter in the search text, file, or Run fields, to apply them all at once. If you changed conditions but have not applied them yet, a "Conditions changed" mark appears. {{icon:action.filter}} {{ui:defects.clear_filters}} applies immediately. Applied conditions are kept in the address, so you can share them as a link or come back with the browser's Back button.

The list shows {{limit:ui.defect_page_size}} defects per page.

## My defects

The {{icon:nav.my_defects}} menu collects the defects assigned to you. Only defects in projects you can see appear, and you can filter by status and project. To edit, select a defect and use its details screen.

## Viewing past Runs

Use the Run selector above the defect list and details to view a past Run. Past Runs are {{icon:ui.read_only}} read-only, so editing and bulk edit are not available. For details, see [History](guide:history).

## Related

- [Code viewer](guide:code-viewer)
- [Rules](guide:rules)
- [History](guide:history)
- [Analysis status](guide:analysis-status)
- [Export](guide:export)
- [Notifications](guide:notifications)
