---
key: code-viewer
title: Code viewer
order: 8
summary: How to view the code where a defect occurred on the defect details screen, view ranges, excerpts, and code snapshot retention
keywords: code viewer, code, highlight, line number, full file, around defect, wrap lines, excerpt, snapshot, read-only
related: defects, history, rules
audience: user
---

## What it shows

The {{ui:defects.code_title}} area of the defect details shows the code of the file where the defect occurred.

- It is the code as of the **analysis that last detected** the defect. It may differ from the code on your PC or the code you uploaded most recently.
- Above the code, you can see which analysis (or Run) and which file it comes from.
- Python, JavaScript, and TypeScript code is shown with syntax highlighting.
- Line numbers match the original file.
- It is read-only. You cannot edit or save the code.
- Code is always shown as plain text, so any HTML or script inside the code is never run.

## Defect location

When you open the defect details, the code scrolls to the defect line. The defect line has a background color, and if the tool reported a column range, that part gets a wavy underline.

## View range and line wrapping

Choose under "{{ui:defects.code_mode_label}}":

- {{ui:defects.code_mode_window}}: shows only part of the file around the defect line (lines before and after: {{limit:viewer.code_window_lines}}).
- {{ui:defects.code_mode_full}}: shows the whole file.

If long lines run off the screen, turn on {{ui:defects.code_wrap}}.

Very large files may be shown only in part, or not at all:

- If the file has more lines than the limit ({{limit:viewer.code_max_lines}}), only the area around the defect is shown even when you choose the full file.
- If a line is longer than the limit ({{limit:viewer.code_max_line_length}}), only its beginning is shown.
- If the file is larger than the limit ({{limit:viewer.code_max_file_size}}), it is not opened in the viewer.
- Files that are not text are not shown.
- If the original file is not UTF-8, some characters may appear as replacement characters.

## Code snapshots and excerpts

Each time an analysis succeeds, the code of the analyzed files is kept as a snapshot.

- For each project, the full code is kept for a number of the most recent successful analyses (current setting: {{limit:snapshot.full_keep_count}}).
- For older analyses, only part of the code around each defect line is kept as an excerpt (lines before and after: {{limit:snapshot.excerpt_context_lines}}).
- When an excerpt is shown, a notice says it is an excerpt, and lines outside the excerpt cannot be viewed.
- If no excerpt remains either, "Cannot display code" appears with the reason.

To see the full code for an old defect again, upload and analyze the latest code. If the defect is detected again, you can view it from the new snapshot.

## Viewing a past Run

If you choose a past Run on the defect details, the viewer shows the code from the last time the defect existed up to that Run, labeled as code as of Run N. If the defect did not exist in that Run or any earlier Run, you are told there is no code to show. For Runs, see [History](guide:history).

## Common questions

- The defect line does not seem to match the code: the code viewer shows the code as of the last detection. If you changed the code after that, upload and analyze again.
- What does the rule mean: select the rule name on the defect details to open its [rule description](guide:rules).

## Related

- [Managing defects](guide:defects)
- [History](guide:history)
- [Rules](guide:rules)
