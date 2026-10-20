---
key: dashboard
title: Dashboard
order: 11
summary: See defect status and analysis trends for all the projects you can view on one screen
keywords: dashboard, summary, trend, top rules, top files, language, tool, view as table, open defects, chart
related: history, defects, rules, projects
audience: user
---

## What it does

The {{icon:nav.dashboard}} screen brings together defect status and analysis trends for the projects you can view. Projects you cannot view are not counted anywhere, neither by name nor by number.

Here, **open defects** are defects whose status is anything other than Resolved (Open, In progress, Postponed, Intentional, Won't fix).

![Top of the dashboard. The summary shows the number of projects, open defects, counts by status, open defects assigned to you and analyses in the last 7 days, followed by the analysis language selector.](guide-img:dashboard)

## Screen layout

The screen is divided into the sections below. Each section loads separately, so if one is slow or fails, the others still appear. Only a section that failed shows a {{ui:dashboard.retry}} button.

### {{ui:dashboard.summary_title}}

- Number of projects you can view (analyzed and not yet analyzed)
- Number of open defects and counts by severity
- Counts by status (all, including Resolved)
- Number of open defects assigned to you, with a {{ui:dashboard.mine_link}} link
- {{ui:dashboard.analyses_7d}}: succeeded, failed, and cancelled counts
- {{ui:dashboard.failed_projects}}: click a project name to open that analysis's failure screen.

### {{ui:dashboard.trend_title}}

Shows the number of defects in recent Runs as a line for each project.

- You can choose one project in the "{{ui:dashboard.project}}" box. If you don't, a few recently analyzed projects are shown.
- Values are **as of the end of each Run**. If someone changes a status later, earlier points are not recalculated.
- Succeeded (unchanged) analyses do not create a new Run, so they do not appear in the trend.
- A diamond marker means the tool version or settings changed in that Run ({{icon:ui.changed}}). Hover over the marker to see what changed.
- Click a point to go to the defect list as of that Run.

### Top rules and files

"{{ui:dashboard.top_rules_title}}" and "{{ui:dashboard.top_files_title}}" show, as bars, the rules and files with the most open defects.

- In the "{{ui:dashboard.scope}}" box, choose all projects you can view or a single project.
- Click a bar to go to the defect list filtered to that rule's or file's open defects. When the scope is all projects, clicking a rule bar opens the list of the project with the most defects for that rule.
- Each bar also shows its count as text at the end.

### {{ui:dashboard.tools_title}}

For Python (ruff) and JS/TS (ESLint), shows the number of projects, open defects and counts by severity, and the distribution of tool versions used in the latest successful analyses.

## Filters and project selection

1. In the "{{ui:dashboard.language}}" box at the top, choose all languages, Python (ruff), or JS/TS (ESLint).
2. The language filter applies **only to {{ui:dashboard.trend_title}} and top rules**. The summary, top files, and stats by language and tool always cover everything.
3. In the selection boxes of the trend and top sections, you can type to narrow the list. You can only pick items in the list; clearing the box goes back to all.

The chosen language is kept in the address, so it stays after a refresh or when you share the address.

## View as table

Every chart has a {{ui:dashboard.show_table}} button. It shows the same data as a table; click again ({{ui:dashboard.show_chart}}) to return to the chart. Use it to read exact values or with a screen reader. Runs, rules, and files in the tables are links too and go to the same places as clicking the chart.

## Refresh

The {{ui:dashboard.refresh}} button at the top right reloads all sections. The time the figures were calculated is shown next to it. When an analysis finishes or a defect changes, the next load reflects it.

## Cautions

- If you cannot view any project, the screen says so. Ask an admin for access to projects ([Projects](guide:projects)).
- Points for old Runs whose details were deleted still appear, but clicking them only shows a note instead of opening a list.
- To look at one project's changes Run by Run, use [History](guide:history).

## Related

- [History](guide:history)
- [Defects](guide:defects)
- [Rules](guide:rules)
- [Projects](guide:projects)
