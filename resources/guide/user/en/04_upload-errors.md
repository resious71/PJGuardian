---
key: upload-errors
title: Upload errors
order: 4
summary: What you can do when an upload fails, grouped by cause
keywords: upload error, invalid_zip, zip_too_large, extract_limit, forbidden_entry, filename_encoding, unsupported_name, name_conflict, io_error, assemble_failed, extract_timeout, extract_crashed, extraction, file name
related: upload, errors-help, analysis-failures
audience: user
---

## Where errors appear

When an upload fails, the upload screen shows the error title and description, plus an "Action: … (error code …)" line. If a specific item caused the problem, its name inside the ZIP is shown too.

The cause and action for every error code are listed one per line in the [Error codes](/guide/errors) tab. This section groups them by cause and explains how to fix them.

A failed upload cannot be resumed. Fix the cause and upload again from the beginning.

## Problems with the ZIP file itself

- `invalid_zip` (Invalid zip): the file is not a ZIP, was cut off partway, has duplicate entries with the same name, or contains password-protected entries.

What you can do:

1. Re-create the ZIP with an archive tool. Do not set a password.
2. Or pick the folder directly with {{ui:upload.pick_folder}}. The browser packages it in a suitable format.

## Size and file count limits

- `zip_too_large` (Upload size exceeded): the uploaded file exceeds the upload limit ({{limit:upload.max_zip_size}}).
- `extract_limit` (Total size or file count exceeded after extraction): the extracted size ({{limit:extract.max_extracted_size}}) or file count ({{limit:extract.max_files}}) limit is exceeded.
- `extract_timeout` (Extraction timed out): there were so many files that extraction did not finish within the time limit.

What you can do:

1. Remove files not needed for analysis and package again. Build output, dependency folders, data files, and generated files are common causes.
2. When uploading a folder, keep "{{ui:upload.skip_excluded}}" on.
3. If you keep having to remove the same folders, ask an administrator to add project exclude patterns.

## Items that are not allowed

- `forbidden_entry` (Contains forbidden items): there are items such as path traversal (`..`), absolute paths, paths using backslashes (`\`), symbolic links, or a ZIP inside the ZIP (a `.zip` file).

What you can do:

1. Check the item name shown in the error.
2. Remove that item, or replace a link with the actual file, and package again.
3. Remove any `.zip` files inside the ZIP before uploading.
4. If the problem comes from an archive tool that uses backslashes as path separators, use a different tool or pick the folder directly.

Path traversal, absolute paths, and backslashes are rejected even inside excluded items. Symbolic links and ZIPs inside the ZIP are a problem only outside excluded items.

## File name problems

- `filename_encoding` (File name encoding): file names inside the ZIP cannot be read as UTF-8 or CP949.
- `unsupported_name` (Unsupported file name): there are names Windows cannot use (reserved names like `CON`, forbidden characters, trailing dots or spaces) or paths that are too long.
- `name_conflict` (Name conflict): there are files whose names differ only in letter case or Unicode normalization, or a file and a folder with the same name.

What you can do:

1. `filename_encoding`: package again with an archive tool that supports UTF-8 file names, or pick the folder directly.
2. `unsupported_name`: rename the file shown, or reduce folder depth to shorten the path.
3. `name_conflict`: rename one of the two. For example, `Readme.md` and `README.md` cannot both be present.

Windows naming rules apply only to items outside the excluded items.

## Problems during server processing

- `assemble_failed` (Assembly failed): the received chunks could not be joined into one file, or the joined result's size or hash did not match.
- `io_error` (Server file write error): the server could not write files during extraction.
- `extract_crashed` (Extraction task stopped): the extraction task ended abnormally.

What you can do:

1. Upload again from the beginning after a while.
2. If the same error keeps happening, give the error code and upload time to an administrator.

## When an upload cannot start

Some requests are rejected before the transfer starts. In that case only a message appears on screen.

- You exceeded the number of uploads you can run at the same time ({{limit:upload.max_concurrent_per_user}}): wait for an upload in progress to finish, or cancel it, then try again.
- The upload size limit is exceeded: remove unnecessary files and package again.
- The server is low on storage or disk usage is high: contact an administrator.

## Related

- [Upload](guide:upload)
- [Error messages](guide:errors-help)
- [Analysis failures](guide:analysis-failures)
