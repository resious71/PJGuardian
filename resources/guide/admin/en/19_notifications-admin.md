---
key: notifications-admin
title: Admin notifications
order: 19
summary: Notifications that only administrators receive, what to do about each, and the notification retention settings
keywords: admin notifications, notifications, pending approval, pending limit, identity mismatch, SAML certificate expiry, scheduled analysis failed, defect count warning, backup failed, backup done, restore rehearsal, notification retention
related: accounts, external-auth, auth-saml, schedule, backup-restore, settings
audience: admin
---
## What this covers

Besides the five notification kinds every user gets, administrators receive **admin-only notifications**. These are never created for or shown to regular users. They also appear only for administrators in the kind filter of the notification list and on the notification settings tab.

- Recipients: **all active administrators**. The exception is backup done, which goes only to the administrator who requested the backup.
- Notifications are in-app only. See them from the notification icon at the top and on the notifications page ([/notifications](/notifications)).
- Administrators can turn each kind off on the notification settings tab. Nothing is created while a kind is off, so make sure not every administrator turns off the same kind.

## Account and sign-in notifications

### {{ui:notifications.kind.account_pending}}

Sent when someone signs in for the first time through external authentication (LDAP/AD, OIDC, SAML) and their account is waiting for approval. Up to 5 new pending accounts a day are notified one by one; beyond that, a single daily summary is updated with the count.

What to do:

1. Click the notification to go to [Users](/admin/users).
2. Check the pending account's name, choose a role and approve it, or reject it.

Until approved, the account is always inactive and has no permissions. See [External authentication overview](guide:external-auth) for the full procedure.

### {{ui:notifications.kind.account_pending_limit}}

Sent once a day when pending and rejected accounts reach the limit ({{limit:auth.pending_max}}) and new account requests are no longer accepted. Meanwhile, people signing in for the first time cannot request an account.

What to do:

1. In Users, approve or reject pending accounts and delete pending or rejected accounts you do not need.
2. If many requests are expected, raise the limit on **{{ui:config_settings.title}} > {{ui:config_settings.group.auth}}**.

Pending and rejected accounts are deleted by the cleanup job after {{limit:auth.pending_retention_days}} days.

### {{ui:notifications.kind.auth_identity_mismatch}}

Sent when a sign-in was refused because the login ID matches but the external identifier differs from the registered one. Someone may be reusing the same login ID. At most one per account per day.

What to do:

1. In the audit log, look at the `auth.login_failed` entries and reasons for that login ID ([Audit log](guide:audit-log)).
2. Ask the directory or IdP administrator whether the ID was reassigned to someone else.
3. Do not link or change the account until you are sure.

### {{ui:notifications.kind.saml_cert_expiring}}

Sent while SAML is on, when a registered IdP signing certificate expires within the warning period ({{limit:notification.cert_expiry_warn}}) or has already expired. The worker checks every hour; one notification per certificate per day.

What to do: get the new certificate from the IdP and put it in "{{ui:config_settings.key.saml.idp_cert_next.label}}" on the **{{ui:config_settings.group.auth_saml}}** tab. Once the certificate expires, SAML sign-in may fail. See [SSO (SAML)](guide:auth-saml).

## Analysis operations notifications

### {{ui:notifications.kind.schedule_failed}}

Sent in these cases:

- A scheduled analysis still failed after retries. Consecutive failures of the same project update one notification with the count and time instead of creating new ones.
- A scheduled run was skipped because of a disk block or a connection problem. Runs skipped because credentials cannot be decrypted (for example after a restore without the key) are notified only once per project.

What to do: read the reason and check the project's status on the [Scheduled analysis](/admin/schedules) tab. For connection problems, run the repository connection test; for disk problems, see [Retention and disk](guide:retention-disk). See [Scheduled analysis](guide:schedule) for details.

### {{ui:notifications.kind.defect_count_warn}}

Sent after an analysis when a project's current (unresolved) defects exceed the warning threshold ({{limit:limits.project_defect_warn}}). 0 means off. Each project has one notification whose count is updated.

What to do: check whether generated files, minified files or third-party library folders are being analyzed. If so, add them to the project's exclude patterns and analyze again. An analysis that would push defects past the maximum ({{limit:limits.project_defect_max}}) fails with `defect_limit_exceeded`. The thresholds are on **{{ui:config_settings.title}} > {{ui:config_settings.group.analysis}}**.

## Backup notifications

- **{{ui:notifications.kind.backup_failed}}**: an automatic backup still failed after a retry. The same reason is notified at most once an hour, and nothing is sent while automatic backup is off. Check the warning and the last failure reason on the [Backup & restore](/admin/backups) screen.
- **{{ui:notifications.kind.backup_done}}**: sent only to the administrator who clicked {{ui:icon.action.backup_now}}, when the backup finishes.
- **{{ui:notifications.kind.restore_rehearsal_due}}**: sent once per due date when {{limit:backup.rehearsal_interval_days}} days have passed since the last restore rehearsal. Run {{ui:icon.action.rehearse}} on the backup screen.

See [Backup and restore](guide:backup-restore).

## Notification retention periods

Notifications are deleted after set periods. Set them in the "{{ui:admin_notifications.title}}" section at the end of **System admin > {{ui:config_settings.title}} > {{ui:config_settings.group.notification}}** ([/admin/config-settings#notification](/admin/config-settings#notification)). They apply to all users.

- **{{ui:admin_notifications.retain_read_days}}**: read notifications are deleted this many days after they were read.
- **{{ui:admin_notifications.retain_unread_days}}**: unread notifications are deleted this many days after they were created.
- **{{ui:admin_notifications.max_per_user}}**: when a user has more than this, the oldest read notifications are deleted first, then the oldest unread ones.

Steps:

1. Change the values. The range and default are shown next to each field.
2. Click {{ui:admin_notifications.save}} in this section. The section is stored in the database, so it saves separately from "Review setting changes" at the bottom.
3. The change applies from the next cleanup. The worker cleans up every hour.

Saves are recorded in the audit log as `setting.update`.

Other items on the same tab also relate to notifications.

- **{{ui:config_settings.key.ui.notification_poll_seconds.label}}** (currently {{limit:ui.notification_poll_seconds}}): how often pages recheck the unread count. Pages already open pick it up when reopened.
- **{{ui:config_settings.key.notification.cert_expiry_warn.label}}** (currently {{limit:notification.cert_expiry_warn}}): used by SAML certificate notifications and by the certificate expiry warning in the allowed hosts table. Allowed host certificate expiry is shown only as a warning in the table, not as a notification.

## Troubleshooting

- **Admin notifications do not arrive**: check on the notification settings tab that the kind is on, and that your account is an active administrator. Notifications created by the worker (backups, scheduled analysis, SAML certificates) appear only while the worker is running.
- **The same notification is updated instead of piling up**: consecutive scheduled analysis failures and defect count warnings update a single notification by design. Its read state is kept.

## Related sections

- [Accounts](guide:accounts)
- [External authentication overview](guide:external-auth)
- [Scheduled analysis](guide:schedule)
- [Backup and restore](guide:backup-restore)
- [Settings](guide:settings)
