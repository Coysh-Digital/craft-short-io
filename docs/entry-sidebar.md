# The entry sidebar

Editors see a **Short link** panel on any entry in an enabled section, alongside Status and Notes.

It is laid out like Craft's own sidebar panels - an editable row, then read-only ones:

- the **path** - the bit after your domain, labelled with the domain itself
- the short URL, with a copy button
- the click count, if **Show click counts** is on
- a QR code, depending on **Show in the entry sidebar**

## The path field

Leave it on `auto` and the path is derived from the entry slug, with any configured prefix. Type
something and that path is claimed instead.

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

Users without the manage permission still get short links created for their entries
automatically - they simply cannot choose the path or remove the link.

See [The Links screen](/links#permissions) for the full permission matrix.
