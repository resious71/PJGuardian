---
key: admin-mail
title: Admin email alerts
order: 21
summary: Email errors and warnings such as a stopped worker, disk or backup problems to administrators — SMTP settings, choosing TLS, test email, resend rules, recovery email, limits
keywords: email, mail, alert email, SMTP, STARTTLS, SMTPS, TLS, certificate, test email, admin email, failure alert, recovery email, resend, relay
related: monitoring, settings, worker, security, troubleshooting
audience: admin
---
## What it does

When an error or warning from [System checks and monitoring](guide:monitoring) starts or clears, an email goes to administrators. **It is off by default** and only administrators configure it. There is no email feature for regular users.

- Email is sent from **the worker only**. Each time the worker checks health (every {{limit:monitoring.cache}}), the same result decides what to send. The web screens never send email (except the test email).
- When one check has several things to send, they are **bundled into one email**.
- The email holds only the alert name, level, first detection time and the monitoring screen address. Server file paths, passwords and user input are never included.

## Setting it up

Go to **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.admin_mail}}**. The top of the tab shows the sending status for the saved settings (off, setup needed, on).

- **{{ui:config_settings.key.alert_mail.enabled.label}}**: Must be on to send. If required items below are empty, the status is "setup needed" and nothing is sent.
- **{{ui:config_settings.key.alert_mail.smtp_host.label}}, {{ui:config_settings.key.alert_mail.smtp_port.label}}**: Mail server name (or IP) and port, without a scheme such as `smtp://` or a path. It differs per server, so it carries the "this server only" badge.
- **{{ui:config_settings.key.alert_mail.smtp_tls.label}}**: See "Choosing TLS" below.
- **{{ui:config_settings.key.alert_mail.smtp_auth.label}}, {{ui:config_settings.key.alert_mail.smtp_user.label}}**: Login authentication needs a user name and password. Choose "None" when an internal relay allows this server by IP.
- **{{ui:alert_mail.secret.label}}**: Saved separately with **{{ui:alert_mail.secret.save}}** in the field under the user name (apart from saving settings). It is kept encrypted in the database only and never shown again. The screen shows only whether it is saved; **{{ui:alert_mail.secret.delete}}** removes it.
- **{{ui:config_settings.key.alert_mail.from_address.label}}, {{ui:config_settings.key.alert_mail.from_name.label}}**: The mail server must accept the from address.
- **{{ui:config_settings.key.alert_mail.recipients.label}}**: Up to 20, separated by commas. Leave empty to send to **every active admin account** that has an email.
- **{{ui:config_settings.key.alert_mail.language.label}}**: Language of the subject and body (Korean or English), set apart from the screen language.
- **{{ui:config_settings.key.alert_mail.events.label}}**: Which kinds of events to send. All by default.
- **{{ui:config_settings.key.alert_mail.resend_interval.label}}**: Default {{limit:alert_mail.resend_interval}} (10 minutes to 24 hours).
- **{{ui:config_settings.key.alert_mail.recovery.label}}**: Whether to send one recovery email when an event clears.

After you save, the worker uses the new settings from its next check (no restart needed). Malformed addresses are rejected when you save.

## Choosing TLS

- **STARTTLS (recommended)**: Most mail servers and cloud mail services — usual port 587
- **SMTPS**: Servers that use TLS from the start — usual port 465
- **Plain text (no TLS)**: Only a relay on the same server or the internal network — usual port 25

- **Certificate verification is always on and cannot be turned off.** The server name must match the certificate (connecting by IP usually fails, so use the name on the certificate).
- If the mail server uses an internal CA or a self-signed certificate, add that CA to **the server's trust store**. On Linux use the OS trust store (for example `update-ca-certificates`); on Windows (XAMPP) append the CA to the CA bundle that the openssl.cafile entry in php.ini points to, then restart the worker and the web server.
- Choosing plain text shows a warning on screen. The password and email content travel unencrypted, so never use it with an external mail server.

## Test email

Under **{{ui:alert_mail.test.title}}** at the bottom of the tab, **{{ui:alert_mail.test.run}}** sends one email right away with **the saved settings**. Leave the recipient empty to send to your own account email.

- With unsaved changes the button is disabled and asks you to save first. If required settings are missing it is also disabled and lists what is missing.
- Up to 3 per minute per user and 20 per hour in total.
- The result shows "Sent" or the failure reason. Each test email is recorded in the audit log as `mail.test` with the recipient masked (first letter and domain only).

## Sending rules

- **`worker_down`**: Worker stopped (error)
- **`queue_stalled`**: Queue stall (warning)
- **`disk`**: Disk warning and blocking
- **`backup`**: Backup failed, backup too old
- **`tool`**: Analysis tool verification failed (per tool)
- **`repo`**: Scheduled analysis failing in a row (per project)
- **`cert`**: HTTPS certificate expiring or expired

- **When it starts**, it is sent at the next check.
- **While it continues**, the same event is sent again as "ongoing" only once per resend interval ({{limit:alert_mail.resend_interval}}), with the number of times it was detected in between.
- **When the level rises** (disk warning to blocking, certificate expiring to expired), it is sent right away regardless of the interval.
- **When it clears**, one recovery email is sent (none if recovery alerts are off). A lower level that cleared because it rose to a higher one is not reported as recovered.
- With several workers, the same event is still sent only once.
- A failed send never stops the worker or analyses. The failure goes to the app log with its reason (connection, TLS, authentication, rejected, timed out), and if the event continues it is retried at the interval.
- Records of cleared events are removed by the cleanup job after 30 days.

The app info card on the monitoring screen shows "Admin email: Off, Setup needed or On"; clicking it opens this settings tab.

## Limits

- **No email goes out while the worker is stopped**, because the worker does the checking and the sending. If you must know when the worker stops, connect the [health check (/health)](guide:monitoring) to an external monitoring tool.
- PJGuardian cannot see what happens after the mail server accepts the email (spam filtering, bounces). If the test email does not arrive, check the spam folder and the mail server logs.
- Without database migration 031, the status is "setup needed (database migration 031)" and nothing is sent. Apply it with `php tools/migrate.php`.

## Troubleshooting

- **Connection failed**: Server name and port, the firewall (outgoing connection from this server to the mail server), a corporate proxy
- **TLS failed**: TLS type and port pairing (587 = STARTTLS, 465 = SMTPS), the name on the certificate, whether the internal CA is in the trust store
- **Authentication failed**: User name and password, whether the mail service requires an app password
- **Rejected**: Whether the mail server accepts the from address, the recipient address
- **Timed out**: Mail server response, whether the port is blocked and the connection drops without a reply
- **"Setup needed"**: The sending status at the top of the tab lists what is missing
- **No email arrives**: Whether the worker is running ([Worker](guide:worker)), the event kinds list, whether you are still inside the resend interval
- **Password "Unreadable"**: The encryption key changed. Save the password again

## Related sections

- [System checks and monitoring](guide:monitoring)
- [Settings](guide:settings)
- [Worker](guide:worker)
- [Security operations](guide:security)
- [Troubleshooting](guide:troubleshooting)
