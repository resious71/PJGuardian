---
key: upload
title: Upload
order: 3
summary: How to upload a folder or ZIP to start an analysis, resuming uploads, pre-check warnings, limits, and tool configuration file rules
keywords: upload, ZIP, folder, resume, pause, chunk, pre-check, warning, excluded, top-level folder, ruff config, ESLint config, pyproject.toml
related: upload-errors, analysis-status, projects, analysis-failures
audience: user
---

## What uploading does

When you upload code as a folder or ZIP file, the server extracts it, runs a pre-check, and puts it in the analysis queue. Open the upload screen with the {{icon:action.upload}} {{ui:upload.button}} button on the project details screen or from the [upload screen](/upload).

The processing steps are shown on screen in order: {{ui:upload.step.upload}} → {{ui:upload.step.assemble}} → {{ui:upload.step.extract}} → {{ui:upload.step.queue}}.

![Upload screen. Choose a project, select a ZIP file or a folder, then press the start upload button.](guide-img:upload-screen)

## How to upload

1. In {{ui:upload.project}}, choose the target project. Only projects you can see are listed.
2. Choose what to upload. {{ui:upload.pick_file}} picks a ZIP file you already made; {{ui:upload.pick_folder}} picks a folder. The browser packages a folder into a ZIP for upload (it only packages, without compression).
3. Check the file count and estimated size of your selection. You can view the list with "Show selected files".
4. Select {{icon:action.upload}} {{ui:upload.start}}. Choosing a target alone does not send anything.
5. Once queued, follow the analysis with the "{{ui:upload.go_analysis}}" link ([Analysis status](guide:analysis-status)).

If you chose the wrong target, select {{ui:upload.clear_selection}} and choose again.

Some browsers show an "Upload" confirmation after you pick a folder. That dialog only asks permission to read the folder; the actual transfer starts when you select {{ui:upload.start}}. For browser security reasons, the full path on your PC is not shown; the name of the chosen folder is shown instead.

## Limits

- Maximum upload size: {{limit:upload.max_zip_size}}
- Maximum total size after extraction: {{limit:extract.max_extracted_size}}
- Maximum number of files after extraction: {{limit:extract.max_files}}
- Uploads one person can run at the same time: {{limit:upload.max_concurrent_per_user}}

Uploads over the size limit are blocked before sending. For folders, the estimated size after packaging is used. If the server is low on disk space, an upload may not start; contact an administrator in that case.

## Default exclusions

Server default exclusions, such as dependency folders, build output, and version control folders, are always left out during extraction. You can see the list in the upload screen's help text and under {{ui:projects.excludes_title}} on the project details screen.

- When uploading a folder, keep "{{ui:upload.skip_excluded}}" on (the default) to drop them before packaging. This makes the upload smaller; the result is the same.
- An item is excluded only when one part of its path **exactly** matches a name. For example, `builder/` and `src/dist.py` are not excluded.
- Exclude patterns an administrator added to the project are applied by the server during extraction. They are not shown in the upload screen's list.

## Resuming and pausing

The file is sent in pieces (chunks of {{limit:upload.chunk_size}} each), and the server checks the integrity of each chunk. If sending a chunk fails, it is retried automatically a few times.

- Select {{ui:upload.pause}} to stop sending. Select {{ui:upload.start}} again to continue.
- If the network dropped or you closed the screen, choose the same project and the same target again **in the same browser** and select {{ui:upload.start}}. Chunks already received are skipped.
- A folder whose contents have not changed packages to the same result, so it can be resumed too.
- If an upload in progress is idle for more than {{limit:upload.incomplete_chunk_retention}}, the server may delete its chunks. Then upload from the beginning.
- Select {{icon:action.cancel}} {{ui:upload.abort}} to delete all chunks the server received. Once the transfer has finished, the upload cannot be cancelled; you can only follow its status.

## Pre-check and confirming warnings

After extraction, the server checks the contents. In the cases below you are asked to confirm before the analysis ("Confirmation required"):

- No target files to analyze: analyzing as is may turn all existing defects in this project into resolved.
- The top-level folder differs from the previous upload: file paths change, so all existing defects may become resolved and the same defects may become new.
- Same file as the previous upload: unless you mean to analyze the same code again, check that this is the correct ZIP.

In the warning dialog you can choose:

- {{ui:upload.confirm_continue}}: confirm the warning and put the upload in the analysis queue.
- {{ui:upload.decide_later}}: just close the dialog. Reopening the upload screen in the same browser shows the upload waiting for confirmation again.
- {{ui:upload.abort}}: delete the extracted result and the uploaded file without analyzing.

An upload waiting for confirmation may be cancelled automatically after {{limit:upload.confirmation_retention}}. Only the person who uploaded it can confirm.

The upload summary on screen shows the number of extracted files, the number of target files, excluded items, the file hash (SHA-256), and a comparison with the previous upload.

## Top-level folder handling

If the whole ZIP is wrapped in a single folder, whether to strip that folder is decided at the **project's first upload**, and the same rule is used afterwards. Defects are tracked by file path, so always upload the same structure (a ZIP made the same way, or the same folder). If the structure changes, you get the top-level folder warning above.

## Tool configuration file rules

**Python (ruff)**

- Only the configuration at the analysis root is used. The order of precedence is `.ruff.toml`, `ruff.toml`, then the ruff settings in `pyproject.toml`.
- ruff configuration in subfolders is not used.
- If the configuration's `extend` points outside the project, that configuration is not used and the server default configuration is used instead.
- An uploaded `.gitignore` is ignored.

**JavaScript/TypeScript (ESLint)**

- Uploaded ESLint configuration is **ignored entirely**; the server default configuration is used.
- ESLint configuration files written in JS (`eslint.config.*`, `.eslintrc.*`) are also excluded from analysis.
- Only the `.eslintignore` at the analysis root is converted into server exclude patterns and applied. `.gitignore` is ignored.

When configuration is ignored, the analysis details screen shows a "configuration ignored" notice. If an analysis fails because of a configuration file, see [Analysis failures](guide:analysis-failures).

## Common mistakes

- Choosing the wrong project: the uploaded code updates that project's defects. Check the project name before you start.
- Uploading an empty ZIP or code in another language: if you continue past the "no target files" warning, all existing defects become resolved.
- Putting a ZIP inside the ZIP: a `.zip` file outside the excluded items causes an upload error ([Upload errors](guide:upload-errors)).

## Related

- [Upload errors](guide:upload-errors)
- [Analysis status](guide:analysis-status)
- [Viewing projects](guide:projects)
