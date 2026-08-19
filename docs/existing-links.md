# Sharing a domain with links you already have

Short.io has **no field for marking which links belong to Craft**. Unlike some shorteners there is
no external id to stamp, so the plugin's own table is the only record of which link belongs to
which entry.

That matters if your domain already carries links made by hand, by another system, or by people
who left years ago. Without care, an entry could rename or repoint one of them.

So by default it doesn't. **Protect existing links** is on, and while it is:

| Situation | What happens |
|---|---|
| An editor types a path that already exists | The save is stopped and says so. The existing link is not touched. |
| A link already points at the entry's page | Left alone. The entry gets its own separate link rather than adopting it. |
| The plugin's own link is deleted at Short.io and something else takes its path | The save is stopped. The other link is not taken over. |
| An automatic path clashes with an existing link | The plugin tries `-2`, `-3` and so on, up to `-6`, rather than blocking a save over a name nobody chose. |

The rule is simple: **the plugin only ever modifies links recorded in its own table for that
entry.** Everything else on the domain is read-only to it.

## Bringing existing links under Craft's management

That protection means adoption has to be deliberate. When you do want the plugin to manage links
that already exist:

```bash
php craft short-io/adopt --dry-run
php craft short-io/adopt
```

It matches links to the entries they point at and records the mapping locally. It never writes to
Short.io. See [Adopting existing links](/adopting-links).

Once a link is adopted, the entry owns it: saving that entry will update its destination, title
and campaign parameters. Run the dry run first and read the summary.

## Turning the protection off

**Protect existing links** can be turned off if Craft is the only thing creating links on the
domain. With it off:

- Creating a link re-uses an existing one that points at the same page, rather than making a
  second - which means a lost row self-heals into re-adoption instead of leaving a duplicate.
- **Adopt existing paths** becomes available, letting an entry take over an unclaimed link at a
  path it wants and repoint it.

Both are useful on a domain dedicated to Craft. Neither is safe on a domain with links you care
about, which is why the default is the cautious one.

## A note on the trade-off

Protection costs you one thing: automatic self-healing. If the plugin's table loses a row - a
database restore from before the link was made, say - it will create a *new* link rather than
finding the old one, leaving the original orphaned.

`php craft short-io/links/verify` reports links that have drifted, and `short-io/adopt` reconciles
the table against what Short.io actually has. On a domain that only Craft touches, turning
protection off avoids the situation entirely.
