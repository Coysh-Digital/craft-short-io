# The Links screen

**Short.io → Links** lists every short link the plugin manages, with its destination, the entry it
belongs to, click counts and creation date.

## Click counts are snapshots

The numbers here come from a local snapshot, not a live API call. That is deliberate: statistics
live on a separate Short.io host, and a fifty-row page would otherwise be fifty HTTP requests
before it could render.

Refresh the snapshots on a schedule:

```bash
php craft short-io/links/refresh-stats
```

The command works oldest-first and throttles itself, so it is safe to run against a large account.
The entry sidebar, which only ever shows one link at a time, does fetch live figures (cached for
15 minutes by default).

## Actions

**Re-sync** rebuilds the link from its entry - useful after changing the destination template, or
when someone has edited a link by hand in the Short.io dashboard.

**Delete** removes the link from Short.io and from Craft. The short URL stops working immediately.

Both need the **Create, rename and remove short links** permission.

## Permissions

There are three gates, and they stack. All of them live under **Settings → Users → (group or
user) → Permissions**.

| Permission | Grants | Provided by |
|---|---|---|
| **Access Short.io** | Reaching the plugin's control panel section at all | Craft |
| **View short links** | The Links screen, the entry sidebar panel, and QR images | This plugin |
| **Create, rename and remove short links** | Editing the path field in the sidebar, and the re-sync and delete actions | This plugin |

*Access Short.io* is Craft's own `accessPlugin-short-io` permission, which every plugin with a
control panel section gets automatically. Without it the section is unreachable even for a user
who has *View short links*.

Without *View short links*, the Short.io nav item is hidden entirely, and the entry sidebar panel
is not rendered.

*Create, rename and remove short links* is nested under *View short links*, so granting it implies
the other.

### What each level can actually do

| | Nobody | Viewer | Manager | Admin |
|---|---|---|---|---|
| Short.io nav item | hidden | visible | visible | visible |
| Links screen | 403 | yes | yes | yes |
| Settings screen | 403 | 403 | 403 | yes |
| Entry sidebar panel | not rendered | read-only | editable | editable |
| Re-sync / delete a link | 403 | 403 | yes | yes |
| QR images | 403 | yes | yes | yes |

Settings are admin-only regardless of the plugin permissions, because they hold the API key.

::: tip Enforcement is server-side
The sidebar's path field is rendered read-only for users without the manage permission, but that
is only markup. The save handler independently ignores a posted `shortIoPath` from anyone who
lacks the permission - so hand-crafting a request cannot rename a link, and cannot delete one by
posting an empty path.

Automatic behaviour is unaffected: an editor without the manage permission still gets a short
link created for their entry, they just cannot steer it.
:::

## Orphans

Deleting an entry deletes its link row too. If rows are ever left behind - a raw SQL purge, say -
clean them up with:

```bash
php craft short-io/links/prune --dry-run
php craft short-io/links/prune
```
