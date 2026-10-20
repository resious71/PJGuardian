---
key: groups-access
title: Groups and project access
order: 6
summary: Groups and group managers, project access mappings (groups and individual users), assignees and notifications after access is lost, and 404 for projects a user cannot see
keywords: groups, group manager, members, access, mapping, project access, 404, assignee, no access
related: accounts, projects-admin, notifications-admin, security
audience: admin
---

## What this feature does

Only three kinds of people can see a project in PJGuardian:

- Admins (all projects)
- Users **mapped directly** to the project
- **Members of a group mapped** to the project (including group managers)

A project with no mappings is visible to admins only. Anyone mapped to a project can view it, upload to it, and manage its defects. Only admins can edit or delete a project and change its mappings.

A group is a set of users. Each group can have **group managers**, who manage the members of their own group. Groups are flat: a group cannot contain another group.

## Creating and managing groups

Use **Groups** in the admin menu ([/groups](/groups)).

1. Click **{{ui:groups.new_button}}**, enter a group name (1 to 100 characters) and a description, and create it. Names that differ only in letter case are not allowed.
2. On the group page, use **{{ui:icon.action.add_member}}** to find existing active users and add them. Search by login ID or name.
3. Use **{{ui:groups.action_set_manager}}** on a member row to make someone a group manager. To undo it, use **{{ui:groups.action_unset_manager}}**.
4. For a new person, **{{ui:icon.action.create_account}}** creates an account and adds it to the group in one step. The temporary password is shown once and is valid for {{limit:auth.temp_password_expiry}}.

- Admins are treated as managers of every group, so they are not added as members (the request is refused).
- Removing a member only removes the membership. The account stays.
- Deleting a group requires typing the exact group name. The confirmation page shows the number of members and the number of projects mapped to this group. Deleting removes the memberships and the group's project mappings and cannot be undone.
- The "Groups" column on the Users screen shows each user's groups (read-only).
- Audit action names: `group.create`, `group.rename`, `group.update`, `group.delete`, `group.member_add`, `group.member_remove`, `group.manager_set`, `group.manager_unset`.

## What group managers can do

A user who is a group manager gets **Groups** in the main menu and a "Group manager" badge next to their role at the top of the screen.

- Add and remove members of their own groups
- Make or remove group managers in their own groups
- Create accounts in their own groups. These are always regular users with app (local) accounts and become members of that group. Any other role or group in the request is ignored.

What they cannot do:

- Remove themselves from a group or remove their own manager status (they ask another group manager or an admin)
- Create groups, rename them, edit descriptions, or delete them
- See other groups. Other groups return 404 as if they did not exist.
- Use the Users screen, change roles, status, or passwords, or change project access mappings

Making someone a group manager, or removing that status, takes effect from their next request.

## Project access mappings

Mappings are set on the project edit screen.

1. Open the project from **Projects** in the admin menu ([/projects](/projects)). On the project page, click **{{ui:access.manage_link}}** below the **{{ui:access.title}}** summary, or click **{{ui:projects.edit_button}}**.
2. In the **{{ui:access.title}}** area, choose a group and click **{{ui:access.add_group}}**.
3. To give access to someone outside the groups, use **{{ui:access.add_user}}** to find and map the user directly.
4. Remove mappings you no longer need with **{{ui:access.remove}}** on the row.

- The top of the area shows how many active users can actually see the project besides admins, without duplicates. With no mappings, a warning says only admins can see it.
- The same group or user cannot be mapped twice, and admins are never mapped.
- A project visible through both a group and a direct mapping still appears once in the list.
- The access column in the project list (admins only) shows the mapping count of each project at a glance.
- Audit action names: `project.access_add`, `project.access_remove`.

## What happens when access changes

Access is not cached; every request reads the database. Adding or removing mappings, adding or removing members, deleting a group, and deactivating an account all take effect **from the next request**.

### Projects a user cannot see return 404

A project the user cannot see returns **exactly the same 404 as a project that does not exist** (not 403). The status code, error code (`project_not_found`), and message are the same, so the user cannot even tell that the project exists. The same applies to data that belongs to a project, such as uploads, analyses, defects, and snapshots. Only an action without permission on a visible project (for example, a regular user editing the project) returns 403.

So when someone says "I opened the link and it says not found," check that person's mappings and group memberships first.

### Assignees who lose access

- If an assignee loses access, the assignment stays. The defect screen shows a **{{ui:defects.no_access}}** tag next to the assignee. A deactivated assignee gets an "Inactive" tag.
- Only active users who can access the project can be chosen as a new assignee.
- To clean up, filter the defect list by assignee and reassign the defects. Editing other fields of a defect does not re-check the assignee.

### Notifications for users who lose access

Notifications about a project the user can no longer access disappear from their notification list and count, and opening one returns 404. The notifications are not deleted, so they reappear if access is granted again. New notifications are created only for people who can access the project at that time.

## Operating tips

- Create groups per team and map groups to projects. When people change, you only edit group membership. Use direct mappings only for exceptions.
- Letting group managers handle membership saves admin work. Group managers cannot change project mappings, though.
- Deactivating a departed user's account cuts off all of their project access at once. Removing them from groups is optional.
- Deleting a project also deletes its mappings (the delete confirmation shows the mapping count).

## Related sections

- [Managing accounts](guide:accounts)
- [Managing projects](guide:projects-admin)
- [Managing notifications](guide:notifications-admin)
- [Security](guide:security)
