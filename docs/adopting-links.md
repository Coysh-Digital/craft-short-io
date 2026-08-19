# Adopting existing links

If your site already uses Short.io, `short-io/adopt` brings those links under Craft's management
so the plugin can keep them up to date from then on.

```bash
php craft short-io/adopt --dry-run
php craft short-io/adopt
```

## What it does

It pages through every link on your configured domain, builds a map of destination URL to link,
then walks your entries and matches them up. Matching happens in memory, so adopting a large
account costs no per-entry API calls.

Each entry ends up as one of:

- **adopted** - exactly one link points at this entry's URL, so the mapping is recorded
- **skipped** - a mapping already exists (use `--overwrite` to replace it)
- **ambiguous** - more than one link points here, so nothing is assumed
- **unmatched** - no link points at this entry

Ambiguous matches are reported rather than guessed. Two sites on a multi-site install can
legitimately share a destination path, and silently picking one would attach the wrong link to
the wrong entry.

## Options

| Option | Effect |
|---|---|
| `--dry-run`, `-d` | Report what would happen, change nothing |
| `--overwrite`, `-o` | Replace mappings that already exist |
| `--by-path` | For entries with no URL match, also try matching the link path against the entry slug |
| `--section`, `-s` | Limit to one section handle |
| `--site` | Limit to one site id |

## It never writes to Short.io

This is the one place where Short.io differs from shorteners that let you stamp your own
identifier onto a link. There is no such field, so adoption is purely local: the plugin records
which link belongs to which entry in its own table, and writes nothing back.

The consequence is worth stating plainly:

::: warning
**The `shortio_links` table is the only record of which link belongs to which entry.** It cannot
be rebuilt from the Short.io API alone, because Short.io holds nothing that points back at Craft.

Include it in your backups. After restoring a database from an older backup, re-run
`php craft short-io/adopt` to pick up anything created since.
:::

In practice the plugin recovers from a good deal on its own - a lost row for an entry whose path
has not changed will be re-adopted automatically on the next save, because creates are told not
to duplicate. What cannot be recovered automatically is a link whose path has since changed.

## Checking for drift

Once adopted, `verify` reports links that no longer match what Short.io has:

```bash
php craft short-io/links/verify
php craft short-io/links/verify --fix
```

Without `--fix` it only reports. With it, links that have moved are re-pointed and links that have
vanished have their rows removed, so the next entry save recreates them.
