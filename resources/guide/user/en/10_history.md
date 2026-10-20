---
key: history
title: History
order: 10
summary: Analysis history and Run numbers, new and resolved defects compared with the previous Run, the version/setting change badge, and viewing past Runs
keywords: history, Run, Run number, analysis history, comparison, new, resolved, reopened, version/setting change, unchanged, past Run, read-only
related: analysis-status, defects, dashboard, rules
audience: user
---

## What it does

The {{icon:nav.history}} screen shows how a project's analysis results changed over time. For each analysis you can compare with the previous result and see which defects are new and which were resolved.

Analysis progress, cancellation, and failures are handled in the project's "{{ui:analyses.tab}}" tab, not in History ([Analysis status](guide:analysis-status)). The {{ui:history.to_analyses_tab}} button at the top of the History screen takes you there.

## Runs and analysis numbers

- An **analysis** is one job started by an upload or a fetch. It is Queued, Running, Succeeded, Failed, or Cancelled, and is called "analysis #number".
- A **Run** is the result of a successful analysis. Each project numbers its Runs Run 1, Run 2, and so on.
- Failed or cancelled analyses do not get a Run number, so Run numbers never skip.
- The screens show both together, for example "Run 3 · analysis #12".

## Opening History

1. Click {{icon:nav.history}} in the top menu and choose a project. Or click the {{ui:history.title}} button at the top of a project screen.
2. From top to bottom the screen shows the trend chart, the comparison panel, and the analysis history table.

## Trend chart

The "{{ui:history.chart_title}}" chart shows, for recent Runs, the number of new and reopened defects (upward), resolved defects (downward), and open defects.

- Use the "{{ui:history.chart_count}}" box to choose 10, 20, or 50 Runs.
- Click a point to switch the comparison panel below to that Run.
- A diamond marker means the tool version or settings changed in that Run.
- Click {{ui:dashboard.show_table}} to see the same values as a table.

## Comparing with the previous Run

The comparison panel compares the selected Run with the **previous successful analysis (previous Run)**. Failed and cancelled analyses are left out. A project's first Run has nothing to compare with, so all its defects count as new.

1. In the analysis history table, click {{ui:history.show_compare}} on the row you want. Or click a point in the chart.
2. Use the {{ui:history.prev}} and {{ui:history.next}} buttons above the panel to move to neighboring Runs.
3. Check the counts on the cards.
   - **{{ui:history.card.new}}**: defects detected for the first time in this Run
   - **{{ui:history.card.resolved}}**: defects resolved automatically because they were not detected in this Run or their file was deleted
   - **{{ui:history.card.reopened}}**: defects that were resolved and are open again because they were detected again
   - **{{ui:history.card.retained}}**: defects detected both previously and now
   - **{{ui:history.card.out_of_scope}}**: defects whose status was kept because they were outside this analysis's scope
4. Click a card's list link to open the defect list filtered to those defects.

The panel also shows the distribution by severity and the top rules and files among new and resolved defects.

## Version/setting change badge

In the analysis history table, a Run with the {{icon:ui.changed}} badge differs from the previous Run in at least one of: tool, tool version, settings source, or settings content. Hover over the badge or focus it with the keyboard to see what changed and how.

In such a Run, new and resolved defects may come from rule changes rather than code changes. The comparison panel shows the same caution. To see how rules changed, check the changes by version in [Rules](guide:rules). A note that the analysis scope changed also appears when the number of analyzed files changes a lot.

## Succeeded (unchanged)

For a project connected to a repository, analysis is skipped when the repository's latest revision and the analysis conditions (tool version, settings, rules, exclude patterns) are the same as the last successful analysis. The analysis history then gets a {{icon:analysis_status.unchanged}} row.

- Defects and Runs do not change. No new Run number is created.
- The row shows how many times it was checked, the last check time, and a link to the base Run that was already analyzed.
- These rows are left out of the trend chart and comparisons.
- Turn on {{ui:history.hide_unchanged}} to hide them from the table.

## Viewing a past Run

Use the Run selector above a project's "{{ui:defects.tab}}" tab or a defect's details to choose an earlier Run and see the defects as of that Run. The latest Run is the default.

- With the keyboard, choose a Run with the arrow keys and press Enter.
- While you view a past Run, a {{icon:ui.read_only}} "Viewing as of Run N (read-only)" note appears. Status, assignee, and severity are the values at the end of that Run.
- Line numbers and messages are from the latest detected location.
- You cannot change defects in a past Run. Use the link to the latest Run next to the note, then make your changes there.

Clicking the Run column in a defect list opens the comparison panel for that Run.

## Cautions

- The resolved list shows "defects resolved in this Run". Some may have been reopened since, so also check the current status column.
- Defects that a person set to Resolved are not counted as resolved. The resolved count only includes defects the analysis resolved automatically.
- Details of old Runs may be deleted. Such Runs are marked "{{ui:history.run_deleted_mark}}"; the counts remain, but you cannot open their defect list.
- If no analysis has succeeded yet, there is nothing to compare. Check the cause of the failure in the "{{ui:analyses.tab}}" tab ([Analysis failures](guide:analysis-failures)).

## Related

- [Analysis status](guide:analysis-status)
- [Defects](guide:defects)
- [Dashboard](guide:dashboard)
- [Rules](guide:rules)
