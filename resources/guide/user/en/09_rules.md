---
key: rules
title: Rules
order: 9
summary: Look up what a ruff or ESLint rule means, its default severity, whether it is used in analysis, and its changes by version
keywords: rule, rule descriptions, ruff, ESLint, search, language tab, changes by version, default severity, in analysis, changed by admin, off, syntax error
related: defects, code-viewer, export, dashboard
audience: user
---

## What it does

The {{icon:nav.rules}} menu shows the ruff (Python) and ESLint (JavaScript/TypeScript) rules the server uses for analysis. Use it to see why a defect was reported, whether a rule is currently used in analysis, and what changed when a tool version changed.

- Everything is based on the tool versions the server currently uses for analysis (active versions). The current versions are ruff {{version:ruff}} and ESLint {{version:eslint}}.
- Descriptions come straight from the installed tools. Items a tool does not provide are shown as "{{ui:rules.no_description}}".
- Rule descriptions are not translated. They stay in the tool's original text even when the screen language is English.

## Language tabs

There are two tabs above the list.

- **{{ui:rules.tab.python}} · ruff**: Python rules
- **{{ui:rules.tab.javascript}} · ESLint**: JavaScript/TypeScript rules

Each tab header shows the total number of rules for that tool. Switching tabs reloads only the list, with the new tab's default conditions. The last tab you viewed is remembered in this browser and opens first next time. Tabs only split the view; they do not switch automatically to match a project's language.

## Finding a rule

1. Choose a tab.
2. Set conditions in the search area.
   - **Search**: part of a rule key, name, or description (for example `F401`, `unused`)
   - **{{ui:rules.prefix_label}}**: only rules whose key starts with this text (for example `D`, `no-`)
   - Category, {{ui:rules.col.severity}} (you can pick several)
   - {{ui:rules.filter_analysis}}, {{ui:rules.filter_overridden}}, autofix, deprecated, {{ui:rules.filter_change}}
   - Sort field and order
3. Click {{ui:defects.apply}} or press Enter in the search box.

If you change conditions without searching, the list stays as it is and a note shows that the conditions changed. {{ui:defects.clear_filters}} returns the conditions to the initial state. Applied conditions are kept in the address, so you can copy the address to share the same list.

The list columns are rule, summary, category, default severity, in analysis, autofix, and status. Click a rule to open its details.

## Rule details

The details page shows:

- **Summary and status**: stable, preview (ruff preview), deprecated, or removed. A deprecated rule may also show its replacement.
- **Default severity and its basis**: the severity a defect gets when analysis first finds it. Anyone can change it per defect, and a value set by a person takes priority.
- **{{ui:rules.analysis_title}}**: whether the rule is currently used in analysis (see "Rules that are turned off" below)
- **Whether autofix is available**
- **{{ui:rules.description}}**: the description and examples from the tool. ESLint does not provide detailed descriptions, so you see "{{ui:rules.no_description}}" and an {{ui:rules.docs}} link; ruff does not provide a docs address, so there is no link.
- **{{ui:rules.history}}**: see the next section
- **{{ui:rules.defects_title}}**: the number of open defects for this rule in projects you can view, with a link to each project's defect list

The tabs above the details are links to the other language's list. "{{ui:rules.back_to_list}}" takes you back to the list with the conditions you last searched in that tab.

## Changes by version

This compares the same rule across the tool versions loaded on the server. For each version it shows:

- Added, removed, renamed (only when the tool names the replacement)
- Description changed (you can expand the before and after descriptions)
- Changes to default enabled, default severity, autofix, and deprecation

The oldest loaded version is the baseline, so it cannot be compared with anything earlier. Use the {{ui:rules.filter_change}} condition in the list to see only rules that are new or changed in this version.

## From a defect to its rule

The rule column in defect lists and the rule name on a defect's details page link to the rule description ([Defects](guide:defects)).

- If a defect came from an older tool version and its rule is not in the current active version, it is marked "{{ui:rules.missing_short}}". The rule page shows the last version that had it and whether it was renamed or removed.
- Special keys added by PJGuardian, such as `syntax-error`, are not tool rules. They have no version history; the page only explains how the app records such defects.

## Rules that are turned off

An admin can turn rules on or off for the whole server. Regular users only see the result.

- The {{ui:rules.filter_analysis}} column in the list shows {{ui:rules.analysis_on}} or {{ui:rules.analysis_off}}.
- A rule changed from its default by an admin is marked {{icon:ui.changed}} "{{ui:rules.overridden_badge}}". Who changed it and why is not shown to regular users.
- Changes apply from the next analysis. Defects in Runs that already finished do not change, and open defects of a rule that was turned off may become Resolved at the next analysis.
- If a Python project uploaded its own ruff settings file, admin changes do not apply to that project.

If you think a rule should be turned on or off, ask an admin.

## Cautions

- If "Rule data is out of date" appears above the list, the server has not loaded the rules for a new tool version yet. Open the page again a little later.
- If the rule list is empty and says there is no rule data yet, contact an admin.
- You can export the rule list to Excel or CSV. Only the rules in the current tab are exported ([Export](guide:export)).

## Related

- [Defects](guide:defects)
- [Code viewer](guide:code-viewer)
- [Export](guide:export)
- [Dashboard](guide:dashboard)
