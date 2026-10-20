---
key: shortcuts-and-icons
title: Reading the screen
order: 17
summary: How to read the icon legend, open help for each screen, and use the screens with the keyboard
keywords: icon, icon legend, help, help for this screen, keyboard, shortcut, Tab, Esc, arrow keys, accessibility, screen reader
related: getting-started, account, errors-help, dashboard
audience: user
---

## What it does

This section explains what the icons on PJGuardian's screens mean, how to open help for each screen, and how to use the screens with the keyboard instead of a mouse.

## Reading icons

- Icons on screen always come with text or an accessible name. You never have to guess the meaning from the icon alone.
- The same icon always means the same thing on every screen. For example, {{icon:ui.changed}} always means that the tool version or settings changed.
- Severity and status are never shown by color alone. The icon shape and text come together, so you can read them even if colors are hard to tell apart.

Some icons you will see often:

- {{icon:severity.critical}}, {{icon:severity.high}}, {{icon:severity.medium}}, {{icon:severity.low}}, {{icon:severity.not_defined}}: defect severity
- {{icon:analysis_status.succeeded}}, {{icon:analysis_status.failed}}, {{icon:analysis_status.unchanged}}: analysis status
- {{icon:ui.read_only}}: viewing a past Run read-only
- {{icon:action.export}}: export

## Icon legend

The table below is the icon legend. Icons are grouped by kind, and each icon is shown with its on-screen name and its accessible name. The legend is built from the app's icon list, so new icons are added to it automatically. From other sections, the "{{ui:guide.legend_link}}" link in the one-line footer at the bottom of the screen brings you here. The same line also shows the PJGuardian version, the ruff and ESLint versions currently in use, and the third-party license notices.

{{legend:icons}}

## Help links

Main screens have a {{icon:nav.guide}} "{{ui:help.link}}" link at the right of the screen header. It opens the guide section that explains that screen.

- It opens in the same tab, so the browser's Back button returns you to the screen.
- Screens with the link: project list, project screen, upload, analysis details, defect list and defect details, my defects, history, dashboard, rule descriptions, notifications, my account, change password

For the "{{ui:guide.detail_link}}" link on error displays, see [Error messages](guide:errors-help).

## Using the keyboard

### Moving around

- Tab moves to the next item and Shift+Tab to the previous one.
- The first time you press Tab on a page, a "{{ui:layout.skip_to_content}}" link appears. Press Enter to skip the top menu and go to the main content.

### Dialogs

- When a confirmation dialog opens, focus stays inside the dialog.
- Esc cancels and closes it.
- Confirmation dialogs put focus on the {{ui:component.cancel}} button first, so pressing Enter by mistake does not run the action.
- When the dialog closes, focus returns to the button that opened it.

### Tabs

The tabs on a project screen, the tabs on the notifications screen, and the language tabs in rule descriptions move with the left and right arrow keys; Home and End go to the first and last tab.

### Lists and menus

- **Theme menu**: choose with the up and down arrows and confirm with Enter or Space. Esc closes it.
- **Notification panel**: move between notifications with the up and down arrows, Home, and End, and close it with Esc.
- **Dashboard selection boxes**: type to narrow the list, choose with the up and down arrows, then press Enter. Esc closes the list.
- **Run selector**: choose a Run with the arrow keys and press Enter to go to that Run.

### Search and tooltips

- In the defect and rule search areas, pressing Enter in the search box runs the search.
- The "{{ui:guide.search_label}}" box on the guide screen searches once you type at least two characters; Esc clears it.
- Shortened values, such as long file paths, show the full value as a tooltip when focused with the keyboard too. Esc closes the tooltip.

### Charts

Instead of clicking a chart, use the {{ui:dashboard.show_table}} button to see the same values as a table. Move through the links in the table with Tab and open them with Enter ([Dashboard](guide:dashboard)).

## Screen language and theme

Change the screen language (Korean or English) and the theme (light, dark, or follow system) with the buttons at the right of the top bar. For details, see [My account](guide:account).

## Related

- [Getting started](guide:getting-started)
- [My account](guide:account)
- [Error messages](guide:errors-help)
- [Dashboard](guide:dashboard)
