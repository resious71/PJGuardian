---
key: rules-admin
title: Managing rules
order: 18
summary: Turning rules on and off one by one or in bulk, the Rule settings tab and resetting changes, default severity per rule, and conflicts between administrators
keywords: rules, turn on rule, turn off rule, in analysis, changed by admin, bulk change, rule settings, reset, default, default severity, severity mapping, conflict, ruff, ESLint
related: tools-bin, projects-admin, settings, schedule
audience: admin
---

## What this feature does

ruff and ESLint analyze with the rules set in the server's default configuration files. Administrators can turn individual rules on or off on top of that. The default configuration files stay as they are; only the changes are stored.

- Where to turn rules on and off: rule details in {{icon:nav.rules}} {{ui:icon.nav.rules}}, and the switches and bulk change in the rule list
- Where to review and reset changed rules: {{ui:icon.admin.tools}} > {{ui:admin_rule_settings.title}}
- Default severity per rule: the severity given automatically to newly detected defects

### Scope and timing

- ESLint: applies to every JavaScript/TypeScript project. Uploaded ESLint configuration is never used anyway.
- ruff: applies only to projects analyzed with the server's default configuration. Projects whose uploaded source contains a ruff configuration file use that file, so administrator changes do not apply to them.
- Changes apply from the next analysis. An analysis reads the rules once when it starts, so queued analyses pick up the change and running ones do not.
- Defects of finished Runs do not change. Open defects of a rule you turn off are no longer detected, so the next analysis may mark them resolved.

## Turning one rule on or off

1. In {{ui:icon.nav.rules}}, choose the tool tab (Python · ruff or JavaScript/TypeScript · ESLint), then find and open the rule.
2. In the {{ui:rules.col.analysis}} section of the rule details, select {{ui:rules.enable}} or {{ui:rules.disable}}. If the rule was already changed, {{ui:rules.reset_default}} is also shown.
3. In the confirmation dialog, check the change and the number of open defects for this rule across the server. Add a reason (up to 200 characters) if you like.
4. Confirm. When the screen says the change applies from the next analysis, you are done.

Administrators see the last change time, who made it and the reason in the rule details. Regular users see only whether the rule is on or off and whether an administrator changed it.

### Rules that cannot be turned on

For these rules the turn-on button is disabled and the reason is shown. Turning a rule off always works.

- Rules removed from the tool, and deprecated rules
- ruff preview rules
- ESLint rules that need type information
- Rules that check nothing without options
- Special keys added by the app (such as `syntax-error`)

If you turn on a pair of conflicting ruff rules together (D203 and D211, D212 and D213), ruff turns one of them (D203, D213) off by itself. The screen shows it as on, but it never appears in results, so do not turn both on.

## Changing rules from the list, and bulk changes

Administrators see a switch in the {{ui:rules.col.analysis}} column of the rule list. The switch opens the same confirmation dialog as the details screen and changes only that row (page, search conditions and scroll position stay).

To change many rules at once:

1. Narrow the results with search conditions: {{ui:rules.filter_analysis}} (all, on, off), {{ui:rules.filter_overridden}} (changed only), a rule key prefix and so on.
2. Select rows with their checkboxes, or use the header checkbox to select the whole page. If there are more results, "Select all M search results" selects every result of the last search.
3. In the bar below, select {{ui:rules.bulk_enable}}, {{ui:rules.bulk_disable}} or {{ui:rules.bulk_reset}}.
4. The preview shows how many rules will actually change, how many are already in that state, how many will be skipped and why, and examples.
5. If you selected all search results or more than 20 rules, type the tool name (ruff or eslint) to enable the confirm button.
6. Confirm. The result shows how many rules changed and how many were skipped.

- Up to {{limit:rules.bulk_max}} rules can be changed at once. A larger set is refused rather than cut short, so narrow the search.
- In bulk changes, rules that cannot be turned on are counted as skipped instead of causing an error.
- Example: on the ruff tab, search with rule key prefix `D` and In analysis set to off, select the whole page, select all results, then {{ui:rules.bulk_enable}} to turn on the docstring rules at once. To undo, search for changed-by-admin rules only, select all, then {{ui:rules.bulk_reset}}.

## The Rule settings tab

{{ui:icon.admin.tools}} > {{ui:admin_rule_settings.title}} (/admin/rule-settings) lists every rule an administrator changed.

- Summary per tool: number of rules turned on and off, the last change, the start of the effective configuration hash, and the number of projects with an uploaded ruff configuration (where administrator changes do not apply)
- Filters by tool and state
- Table: rule, name, change, default, reason, changed by, time, state, and a reset button
- A state of {{ui:admin_rule_settings.state.missing}} or {{ui:admin_rule_settings.state.not_enableable}} means the tool version changed and the change cannot be applied to analyses now. Reset it or decide again for the new version.

### Resetting

- One rule: select the reset button in its row. The rule goes back to the value in the server's default configuration.
- A whole tool: select {{ui:admin_rule_settings.reset_all}} and type the tool name. Up to {{limit:rules.override_reset_max}} changes are reset at a time; if more remain, the screen tells you how many. Select it again to continue.

Changes and resets are recorded in the audit log (`rules.override_set`, `rules.override_clear`, `rules.override_bulk`, `rules.override_reset_all`).

## Revision numbers and conflicts

Each tool has a revision number for rule changes that goes up by one with every turn-on, turn-off or reset. It keeps two administrators from overwriting each other's changes.

- One rule: if another administrator changed the rule after you opened the screen, your change is refused with 409 `rule_override_conflict`. The screen redraws the latest state and tells you. Look at the latest state and decide again.
- Bulk change: if anything else changed for the same tool after your preview, nothing is changed. The screen says another change happened in the meantime and shows a new preview. Check it and confirm again.
- A target set that is too large is refused with `bulk_too_large`.

Administrator rule changes are part of the analysis conditions, so the next scheduled analysis after a change analyzes again even if the commit is the same, instead of skipping as unchanged.

## Default severity per rule

ruff and ESLint do not give defects a severity, so PJGuardian defines a default severity for each rule (critical, high, medium, low, not_defined). It appears as {{ui:rules.col.severity}} in the rule list and details.

- Only newly detected defects get the rule's default severity. Existing defects are not recalculated on each analysis.
- A severity changed by a person always wins. Once someone changes a defect's severity, no later analysis or bulk fill changes it.
- Hover over a severity badge in the defect list to see whether it is the rule default or a manual change.
- Automatic assignment can be turned off with the server setting `severity.auto_assign`. When it is off, new defects get not_defined.

### Filling default severity for existing defects

Defects created before default severities existed (not_defined) are filled with a server command. It cannot be run from the web screens.

```
php tools/severity_apply.php                 # preview (changes nothing)
php tools/severity_apply.php --apply         # fill for real
php tools/severity_apply.php --project=<id> --apply
```

- The default is a preview; it changes data only with `--apply`. It also prints a backup command to run first.
- Severities changed by a person are never touched, whatever the options. Each changed defect gets a "rule default severity applied" history entry.
- If it stops halfway, running it again processes only what is left. With a very large number of defects, run it while the worker is idle.
- When a new tool version adds rules, compare the mapping with the tool's rule list using the first command below. After editing the mapping files, use the second command to recalculate values that were assigned automatically.

```
php tools/severity_coverage.php
php tools/severity_apply.php --reapply-auto --apply
```

## Troubleshooting

- A rule you turned on never shows up in results: check whether the ruff project has an uploaded ruff configuration file (the project count in the Rule settings summary). Also check that you did not turn on a conflicting ruff pair.
- The rules screen says rule data is out of date: the worker reloads rule data when it starts and every {{limit:rules.load_interval}}. To refresh right away, select {{ui:rules.reload}} on the rules screen (the worker must be running to process it).
- A bulk change keeps returning to the preview: another administrator is changing rules for the same tool. Try again shortly.
