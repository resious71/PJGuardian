---
key: notifications
title: In-app notifications
order: 12
summary: Get notified about analysis results and defects assigned to you, and turn each kind on or off
keywords: notification, badge, notification panel, read, delete, notification settings, assignee, my defects, analysis finished, analysis failed, retention
related: defects, analysis-status, history, account
audience: user
---

## What it does

PJGuardian sends in-app notifications when an analysis finishes or a defect is assigned to you. Only you can see your notifications. Admins do not see other people's notifications either.

![Notification panel opened from the bell icon at the top. It shows recent notifications with Mark all read and View all.](guide-img:notification-panel)

## Kinds of notifications

Regular users receive five kinds of notifications.

- **{{ui:notifications.kind.analysis_done}}**: an analysis you requested succeeded. Includes the Run number and the new, resolved, and reopened counts.
- **{{ui:notifications.kind.analysis_failed}}**: an analysis you requested ended as failed, cancelled, or timed out
- **{{ui:notifications.kind.defect_assigned}}**: a defect was assigned to you (including when you assigned it to yourself)
- **{{ui:notifications.kind.defect_changed}}**: someone (including you) changed the status or severity of a defect assigned to you
- **{{ui:notifications.kind.analysis_summary}}**: an analysis finished in a project where you have assigned defects. Shows the project's new, resolved, and reopened counts and how many of your assigned defects were resolved or reopened.

Good to know:

- Defects the analysis resolves or reopens automatically are not notified one by one; they only appear as counts in the summary notification.
- If nothing changed in the project, no summary notification is sent. The person who requested the analysis gets the finished notification instead of the summary.
- When many defects are changed at once, each person gets one combined notification.
- Admins have additional admin-only kinds, such as backups, scheduled analysis, and account approval.

## Header badge and notification panel

The {{icon:nav.notifications}} item in the top menu shows the number of unread notifications. With 0 no number is shown, and 100 or more shows as "99+". While the page is open, the count is checked again every {{limit:ui.notification_poll_seconds}} seconds. Checking pauses while the browser tab is hidden.

1. Click {{icon:nav.notifications}} to open the "{{ui:notif.panel_title}}" panel with your latest notifications.
2. Click a notification to mark it as read and go to the related screen (analysis result, defect, my defects list, and so on).
3. Click {{ui:notif.read_all}} to mark all unread notifications as read.
4. Click {{ui:notif.view_all}} to go to the notifications screen.

The panel closes with Esc, a click outside, or when focus leaves it. Inside the panel, move between notifications with the up and down arrows, Home, and End.

## Managing the notification list

Manage notifications in the "{{ui:notifications.tab_list}}" tab of the notifications screen.

1. Set conditions with the "{{ui:notifications.filter_kind}}" box and "{{ui:notifications.unread_only}}", then click the search button.
2. For each notification you can click {{ui:notif.mark_read}} or {{ui:notif.mark_unread}}, and {{ui:notif.delete}}.
3. {{ui:notifications.read_all}} marks all your notifications as read, regardless of the conditions.
4. {{ui:notifications.delete_read}} deletes all read notifications after a confirmation. Deleted notifications cannot be restored.

## Turning notifications on and off

In the "{{ui:notifications.tab_settings}}" tab, use the switch for each kind to choose whether to receive it.

- Changing a switch saves immediately.
- Notifications of a kind that is turned off are not created at all. Turning it back on does not bring back notifications from the meantime.
- Click {{ui:notifications.settings_reset}} to turn every kind back on.

## Defects assigned to me

When you are made the assignee of a defect, you get a {{ui:notifications.kind.defect_assigned}} notification. You can see all defects assigned to you, grouped by status, under {{icon:nav.my_defects}} in the top menu. A notification about several defects assigned at once opens this list. For working with defects, see [Defects](guide:defects).

## Retention

Read notifications and old unread notifications are cleaned up automatically after the period set by the admin. There is also a limit on how many notifications one person can keep; above it, the oldest read notifications are deleted first. If you need to keep something, record it elsewhere, for example in a defect note.

## Cautions

- If you lose access to a project, its notifications disappear from your list and count.
- Analyses with no requester, such as scheduled analyses, do not send finished or failed notifications.
- Notifications appear only inside the app. An open page checking the notification count does not count as sign-in activity, so you can be logged out if you are away for a long time ([Sign-in problems](guide:login-errors)).

## Related

- [Defects](guide:defects)
- [Analysis status](guide:analysis-status)
- [History](guide:history)
- [My account](guide:account)
