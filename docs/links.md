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

| Permission | Grants |
|---|---|
| **View short links** | The Links screen, the entry sidebar panel, and QR images. |
| **Create, rename and remove short links** | Editing the path field, and the re-sync and delete actions. |

Without **View short links**, the Short.io nav item is hidden entirely.

## Orphans

Deleting an entry deletes its link row too. If rows are ever left behind - a raw SQL purge, say -
clean them up with:

```bash
php craft short-io/links/prune --dry-run
php craft short-io/links/prune
```
