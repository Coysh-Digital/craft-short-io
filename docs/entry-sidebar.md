# The entry sidebar

Editors see a **Short link** panel on any entry in an enabled section, alongside Status and Notes.

It is laid out like Craft's own sidebar panels - an editable row, then read-only ones:

- the **path** - the bit after your domain, labelled with the domain itself
- the short URL, with a copy button
- the click count, if **Show click counts** is on
- a QR code, depending on **Show in the entry sidebar**

## Creating a link

Unless **Create links automatically** is on, an entry has no short link until someone asks for
one. Press **Create short link** under the path field and the link is made there and then - the
panel fills in with the short URL, click count and QR code, and the entry doesn't need saving.

- Leave the path field blank and the path is derived from the entry slug, with any configured
  prefix. Type a path first and that one is claimed instead.
- Campaign values set in the panel before pressing the button are used too.
- The button acts on the entry as it is currently published, not on unsaved changes in the
  editor. An entry that has never been saved, is disabled, or is scheduled for later shows the
  button greyed out until it is live.
- If the path is taken, or Short.io rejects the request, the message appears as a notice and
  nothing is written.
- If Short.io can't be reached and **If Short.io is unreachable** is set to let saves through, the
  request is queued and the link appears once the queue runs.

The button is offered whenever an entry has no link, even with automatic links on - which is
handy for entries that existed before the plugin was installed.

Typing a path and saving the entry still works as well, and creates the link as part of the save.

## The path field

With **Create links automatically** on, leave it on `auto` and the path is derived from the entry
slug, with any configured prefix. Type something and that path is claimed instead. With it off, a
blank field on an entry without a link means no link, and the placeholder says so.

**Clearing the field removes the link.** That is how you take a short link down without
unpublishing the entry.

Behind the scenes this is why the panel is careful about what it renders: a cleared field and a
never-had-one field both post an empty value, so the plugin includes a hidden marker only when a
link already exists. That is the only way to tell "the editor deleted it" from "there was never
one".

## Campaign tracking

A collapsed **Campaign tracking** section holds the five UTM parameters, with the site defaults
shown as placeholders. Type a value to override one for this entry, leave it blank to inherit, or
switch the whole thing off for this link. The summary line shows what will be sent without having
to open it.

See [Campaign tracking](/campaigns) for the full picture.

## Errors

If the path you typed is already in use, the save is blocked and the message appears against the
path field, with the path you typed still in it.

If the path was derived automatically, the message appears against the **slug** field instead -
because that is the field you would actually change to fix it.

## Permissions

The panel is only rendered for users with **View short links**. The path field is editable only
for users who also have **Create, rename and remove short links**; everyone else sees it
read-only.

That read-only state is enforced on the server as well as in the markup: a posted `shortIoPath`
from a user without the manage permission is ignored, so the field cannot be re-enabled in a
browser's dev tools to rename a link, nor emptied to delete one.

Users without the manage permission don't see **Create short link**. With **Create links
automatically** on they still get short links for their entries - they simply cannot choose the
path or remove the link.

See [The Links screen](/links#permissions) for the full permission matrix.
