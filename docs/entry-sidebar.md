# The entry sidebar

Editors see a **Short link** panel on any entry in an enabled section, alongside Status and Notes.

It shows:

- the short URL, with a copy button
- the **path** - the bit after your domain
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

## Errors

If the path you typed is already in use, the save is blocked and the message appears against the
path field.

If the path was derived automatically, the message appears against the **slug** field instead -
because that is the field you would actually change to fix it.

## Permissions

The panel is only rendered for users with **View short links**. The path field is editable only
for users who also have **Create, rename and remove short links**; everyone else sees it
read-only.
