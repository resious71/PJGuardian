---
key: export
title: Export
order: 13
summary: Download the defect list, defects assigned to you, or the rule list as an Excel or CSV file
keywords: export, Excel, xlsx, CSV, report, download, defect list, rule list, past Run, row limit
related: defects, rules, history, errors-help
audience: user
---

## What it does

Export downloads the list on the screen as a file, in Excel (.xlsx) or CSV format. There is no PDF export.

You can export these three lists:

- The defect list in a project's "{{ui:defects.tab}}" tab
- The {{icon:nav.my_defects}} list (only defects in projects you can view)
- The {{icon:nav.rules}} list (only the rules in the language tab you have open)

## Steps

1. On the list screen, set filters or search conditions and click the search button to apply them.
2. Click {{ui:export.xlsx}} or {{ui:export.csv}} at the top right, just above the table.
3. When the file is ready, the browser downloads it. A message then shows how many rows were exported.

Both buttons are turned off while the file is being created. Clicking several times still sends only one request.

## What gets exported

- All results of the **conditions from your last search** are exported: every matching row, not just the page you see. The sort order is the same as on the screen.
- If you changed conditions in the search area but have not searched yet, the changed conditions are not used. In that case "{{ui:export.applied_note}}" appears next to the buttons. Search first if you want to export with the new conditions.
- If you are viewing a **past Run**, the values as of that Run are exported ("As of Run N"), and the file name includes the Run number ([History](guide:history)).
- Defect file columns: defect number, tool, rule, severity, status, assignee, file, line, message, last detected Run, last detected date and time, first detected Run. The my-defects file has an extra project column at the front.
- Rule file columns: tool, rule key, name, category, default severity, in analysis, changed by admin, default enabled, autofix, deprecated, summary, baseline tool version, official docs, version change.
- Column names and values such as status and severity use your current screen language. Defect messages and rule descriptions stay in the tool's original text.
- The file name includes the project name and the export time.

## Excel or CSV

- **Excel (.xlsx)**: the first sheet holds the list, with a frozen header row and an auto filter. The second sheet, "{{ui:export.sheet.info}}", records the export time, project, applied conditions, sort, viewed Run, and row count. Information about who exported it is not included.
- **CSV**: a comma-separated UTF-8 file (with BOM). Korean text displays correctly when opened in Excel. Handy for processing the data in other programs.

## Formula character protection

When you open an exported file in a spreadsheet, cell values are prevented from running as formulas.

- In CSV, a single quote (`'`) is added in front of values that start with `=`, `+`, `-`, `@`, a tab, or a line break. So such values may show a leading `'`.
- The Excel file writes all text as text cells only and never creates formula cells.
- A cell holds up to 32,767 characters; longer values are cut and end with "{{ui:export.truncated}}".

## Limits and errors

- Maximum rows per export: {{limit:export.max_rows}}
- If there are more results, no file is created and you are told the count and the limit. The result is not cut down to a partial file. Narrow the conditions and export in several parts.
- If there are 0 results, the buttons are turned off (hover to see why).
- If "{{ui:export.busy_error}}" appears, click again after the running export finishes. One person cannot create several files at the same time, and there is also a limit on concurrent exports for the whole server.
- If the export does not finish in time, it is stopped. Narrow the conditions and try again.

For other errors, see [Error messages](guide:errors-help).

## Cautions

- Exported files contain code locations and messages from the project. Follow your organization's information security rules when sharing them.
- The export buttons do not appear while you are viewing a Run whose details were deleted. Go back to the latest Run to export.

## Related

- [Defects](guide:defects)
- [Rules](guide:rules)
- [History](guide:history)
- [Error messages](guide:errors-help)
