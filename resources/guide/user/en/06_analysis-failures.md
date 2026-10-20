---
key: analysis-failures
title: Analysis failures
order: 6
summary: What you can do yourself when an analysis fails, grouped by cause, and when to contact an administrator
keywords: analysis failed, timeout, output_limit, memory_limit, tool_crash, tool_config_error, tool_unavailable, tool_failed, insufficient_storage, result_save_failed, worker_crashed, worker_stopped, analysis_crashed, analysis_start_failed, repo, repository, defect_limit_exceeded, defect limit
related: analysis-status, upload, upload-errors, errors-help
audience: user
---

## Where failure information appears

When an analysis fails, the "{{ui:analyses.failure_title}}" area of the analysis details screen shows the cause, what you can do, and the {{ui:analyses.failure_code}}. The {{ui:analyses.reupload}} button on that screen opens the upload screen for the same project.

All failure codes are listed in the [Error codes](/guide/errors) tab. This section groups them by cause.

Keep in mind:

- A failed analysis changes no defects. The baseline (the last successful analysis) stays the same too.
- There is no way to rerun a failed analysis. Fix the cause and upload again.
- Syntax errors in your code do not cause a failure. They are recorded as `syntax-error` defects and the analysis succeeds.

## The analysis scope is too large

- `timeout` (Analysis timed out): the analysis exceeded the time limit ({{limit:analysis.timeout}}).
- `output_limit` (Tool output limit exceeded): the tool's results exceeded the output size limit. This happens when there are very many defects or generated code is included.
- `memory_limit` (Tool memory limit exceeded): the analysis tool exceeded its memory limit.
- `defect_limit_exceeded` (Defect limit exceeded): applying these results would push the project's defect count over the limit ({{limit:limits.project_defect_max}}). Existing defects and earlier analysis results are left as they are.

What you can do yourself:

1. Remove generated code, minified JS, third-party library folders, and very large files, then upload again.
2. For folders you always have to remove, ask an administrator to add them as project exclude patterns.
3. If it still fails, ask an administrator about adjusting the limits.

## Problems with uploaded configuration files

- `tool_config_error` (Tool configuration error): an uploaded configuration file (`pyproject.toml`, `ruff.toml`, and so on) has values the tool does not accept.

What you can do yourself:

1. Fix the rule names and options in the configuration file, then upload again.
2. Or upload without the configuration file. The server default configuration is then used.

For which configuration files are used, see "Tool configuration file rules" in [Upload](guide:upload).

## Analysis tool problems

- `tool_crash` (Tool terminated abnormally): the analysis tool ended abnormally or its results could not be read.
- `tool_unavailable` (No tool available): no usable analysis tool version is available.
- `tool_failed` (Tool failed to run): the analysis tool could not be started.

For `tool_crash`, try uploading once more. If the same failure keeps happening, or you see `tool_unavailable` or `tool_failed`, contact an administrator. The tool status on the server needs to be checked.

## Server storage and saving results

- `insufficient_storage` (Not enough free disk space): the server did not have enough free disk space to start the analysis.
- `result_save_failed` (Failed to save results): the analysis finished, but its results could not be saved, so they were not applied to the defects.

For `insufficient_storage`, contact an administrator and upload again once space is available. For `result_save_failed`, try uploading again, and contact an administrator if it keeps happening.

## The task stopped partway

- `worker_crashed` (Worker terminated abnormally): the worker processing the analysis ended abnormally.
- `worker_stopped` (Worker stopped): an administrator stopped the worker, which interrupted the analysis in progress.
- `analysis_crashed` (Analysis task terminated abnormally): the analysis task ended without leaving results.
- `analysis_start_failed` (Analysis failed to start): the analysis task could not be started.

In most cases, uploading again is enough. For `worker_stopped`, upload after the worker has been started again. For `analysis_start_failed`, or if the same failure repeats, contact an administrator.

## Repository fetch failures (codes starting with repo_)

When fetching code for a project connected to a repository fails, a code starting with `repo_` appears. Repository connections and scheduled analysis are set up by administrators, so most of these need an administrator to check.

- Connection and authentication: `repo_auth_failed` (Repository authentication failed), `repo_unreachable` (Cannot connect to the repository), `repo_tls_untrusted` (Server certificate or fingerprint not trusted), `repo_host_not_allowed` (Repository URL not allowed)
- Settings: `repo_not_found` (Repository, branch, or path not found), `repo_not_configured` (No repository connection), `repo_secret_unreadable` (Credentials cannot be read), `repo_tool_unavailable` (git or p4 unavailable)
- Fetched content: `repo_empty` (No source to analyze), `repo_limit_exceeded` (Fetched source exceeds the limit), `repo_unsafe_content` (Unsafe source content)
- Other: `repo_timeout` (Fetch timed out), `repo_fetch_failed` (Fetch failed)

What you can do yourself:

1. Give the error code, analysis number, and time shown on screen to an administrator.
2. For `repo_empty`, check whether the branch or subpath contains files in this project's language and let the administrator know. Existing defects are left as they are.
3. Temporary problems such as `repo_unreachable` or `repo_timeout` may clear up on their own at the next scheduled run.

## When you contact an administrator

Including the following helps them check quickly:

- The project name and analysis number (Analysis #id)
- The error code shown on screen
- When you uploaded and the approximate file size

## Related

- [Analysis status](guide:analysis-status)
- [Upload](guide:upload)
- [Upload errors](guide:upload-errors)
- [Error messages](guide:errors-help)
