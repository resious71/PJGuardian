---
key: analysis-status
title: Analysis status
order: 5
summary: What the analysis statuses (Queued, Running, Succeeded, Failed, Cancelled, Succeeded (unchanged)) mean, queue rules, how to cancel, and Run numbers
keywords: analysis status, queued, running, succeeded, failed, cancelled, unchanged, queue, queue position, Run, baseline, compare
related: analysis-failures, upload, defects, history, notifications
audience: user
---

## Viewing analysis status

When an upload enters the queue, an analysis is created. Follow its progress on these screens:

- The {{ui:analyses.tab}} tab on the project details screen: the project's analysis list (newest first). Use "Triggered by" to separate user runs from scheduled runs.
- Analysis details: open it by selecting an analysis in the list, or with the "{{ui:upload.go_analysis}}" link on the upload screen. The screen refreshes automatically while the analysis is in progress.

## What the statuses mean

- {{icon:analysis_status.queued}} (queued): waiting for its turn in the queue. The analysis details show the queue position, which is approximate.
- {{icon:analysis_status.running}} (running): the analysis tool is running.
- {{icon:analysis_status.succeeded}} (succeeded): the analysis finished and its results were applied to the defects.
- {{icon:analysis_status.failed}} (failed): the analysis could not finish. No defects change. For causes and actions, see [Analysis failures](guide:analysis-failures).
- {{icon:analysis_status.cancelled}} (cancelled): a user cancelled it. No defects change.
- {{icon:analysis_status.unchanged}}: appears only for analyses that fetch from a repository. The analysis was skipped because the repository's latest revision and analysis conditions (tool version, settings, rules, exclude patterns) are the same as the last successful analysis. Defects and Runs do not change, and the base Run that analyzed the same revision is shown.

An analysis still succeeds when the code has syntax errors. Syntax errors are recorded as `syntax-error` defects.

## Queue rules

- Analyses are processed in the order they arrive.
- Analyses running at the same time across the server: {{limit:analysis.max_concurrent}}
- Only one analysis runs at a time per project. The next analysis of the same project waits until the earlier one finishes; meanwhile, analyses of other projects may run first.

## Cancelling an analysis

A queued or running analysis can be cancelled by the person who requested it or by an administrator.

1. In the analysis list or analysis details, select {{icon:action.cancel}} {{ui:analyses.cancel}}.
2. Confirm in the dialog.

- A queued analysis is cancelled immediately.
- A running analysis is cancelled after the analysis tool is stopped. It shows "Cancel requested" for a moment.
- A finished analysis cannot be cancelled.

Cancelling does not change defect statuses or the baseline (the last successful analysis). To analyze again, upload again.

## Results of a successful analysis

The result summary on the analysis details screen shows:

- The number of detected defects and counts by severity
- New: defects detected for the first time
- Resolved: defects automatically resolved by this analysis
- Reopened: defects that were resolved, were detected again, and went back to open
- Baseline: the last successful analysis. Failed and cancelled analyses are skipped. A project's first successful analysis has no baseline, so all its defects count as new.

Select the new, resolved, or reopened count to open the defect list filtered to just those defects. For the automatic resolve and reopen rules, see [Managing defects](guide:defects).

If uploaded configuration was not used, or a warning was confirmed before the analysis, the analysis details show a notice about it.

## Run numbers

Successful analyses get a Run number per project (Run 1, Run 2, …).

- Failed and cancelled analyses get no Run number, so numbers do not skip.
- Succeeded (unchanged) does not create a new Run.
- In the analysis list and details, a successful analysis is shown as "Run N · Analysis #id".

To compare Runs, see [History](guide:history).

## Scheduled analysis and retries

For projects where an administrator enabled scheduled analysis, code is fetched from the repository and analyzed at set times. In the analysis list, these analyses show {{icon:source.schedule}} in place of the requester. If the repository connection fails temporarily, the analysis may show "Waiting to retry" and try again.

## Notes

- There is no button to rerun a failed analysis. Fix the cause and upload again.
- You can get notified when an analysis finishes, depending on your [notification](guide:notifications) settings.

## Related

- [Analysis failures](guide:analysis-failures)
- [Upload](guide:upload)
- [Managing defects](guide:defects)
- [History](guide:history)
