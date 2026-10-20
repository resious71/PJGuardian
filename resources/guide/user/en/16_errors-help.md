---
key: errors-help
title: Error messages
order: 16
summary: How to read an error message's title, cause, action, and error code, the error codes and FAQ tabs, and Learn more links, and what to tell an admin
keywords: FAQ, error, error code, error message, cause, action, learn more, request ID, contact admin, failure
related: upload-errors, analysis-failures, login-errors, analysis-status
audience: user
---

## What it does

PJGuardian's error messages tell you both what went wrong and what you can do. This section explains how to read error messages and what to tell an admin when you cannot fix the problem yourself. Detailed explanations for each code are in the [{{ui:guide.tab.errors}}](/guide/errors) tab.

## How to read an error message

Upload and analysis errors come in four parts:

- **Title**: what happened, in one line (for example {{ui:failure.upload.zip_too_large.title}})
- **{{ui:analyses.failure_cause}}**: why it happened
- **{{ui:analyses.failure_action}}**: what you can do
- **{{ui:analyses.failure_code}}**: a code in lowercase English, such as `zip_too_large`

The analysis failure screen shows the four parts separately. The upload screen shows the action and code together under the error sentence, in the form "Action: … (error code …)". SSO errors on the sign-in screen use uppercase codes such as `E-SSO-STATE` ([Sign-in problems](guide:login-errors)).

Other screens show a single short sentence. Some messages, such as errors inside dialogs, end with a "Request ID"; admins use it to find that request in the server records.

Messages never include internal server paths or program error details. If an unknown code appears, a general message asking you to tell an admin the code comes with it.

## Error codes tab

The [{{ui:guide.tab.errors}}](/guide/errors) tab of the {{icon:nav.guide}} screen lists every error code users can see, grouped into upload errors, analysis failures, and SSO sign-in errors.

- Each code has a title, cause, what you can do, and a link to the related guide section.
- Internal server errors that only admins handle are not in this tab.

## FAQ tab

The [{{ui:guide.tab.faq}}](/guide/faq) tab of the same screen collects frequently asked questions that admins have written, grouped by category.

- Click a question to expand its answer. Type a search term and press Enter to search categories, questions, and answers.
- Use "{{ui:faq.copy_link}}" to copy the address of a question and send it to a colleague. Opening that address expands the question.
- If there are no items yet, you only see a notice that the list is empty. Tell an admin which questions you need.

## Learn more links

Below an error on the upload screen and below the failure information on the analysis details screen there is a {{icon:nav.guide}} "{{ui:guide.detail_link}}" link. It takes you straight to the guide section that explains the error. On the upload screen, clicking the error code next to it opens that code's row in the error codes tab.

- Upload errors: [Upload errors](guide:upload-errors)
- Analysis failures: [Analysis failures](guide:analysis-failures)
- Notices and SSO error codes on the sign-in screen: [Sign-in problems](guide:login-errors)

## What to try first

1. Do what "{{ui:analyses.failure_action}}" says. Most problems are solved by cleaning up files and uploading again, narrowing conditions, or trying again a little later.
2. If "{{ui:error.network}}" appears, check your network connection and refresh the page.
3. If you repeat the same action and get the same error again, stop retrying and contact an admin.
4. A **maintenance banner** at the top of the screen means an upgrade is in progress or scheduled. During maintenance you can only view; uploads and changes show a notice that changes are unavailable during maintenance. Try again after the banner disappears.

## Contacting an admin

Tell an admin when the action says to contact an admin, or when the same error keeps coming back. Many causes, such as server disk space, analysis tools, or repository connections, are things users cannot change.

Including the following helps them check quickly:

- **Error code**: exactly as shown on the screen (for example `tool_crash`, `E-SSO-TOKEN`)
- **Time**: when the error happened. Write the time as shown on the screen.
- **Project**: the project name
- **Analysis or upload number**: any number shown on the screen, such as "analysis #number"
- **Request ID**: if the message includes one
- **What you did**: one or two sentences on what you were doing when the error occurred

Never send secrets such as passwords or tokens, not even to admins. When you send a screenshot, check that no secrets are visible in it.

## Related

- [Upload errors](guide:upload-errors)
- [Analysis failures](guide:analysis-failures)
- [Sign-in problems](guide:login-errors)
- [Analysis status](guide:analysis-status)
