# Campaign tracking

Every short link can carry UTM parameters, so traffic arriving through it shows up properly in
your analytics rather than as direct or referral.

Set a default for the site, and override it on any entry that needs something different.

## Defaults

**Short.io → Settings → Campaign tracking** holds a default for each of the five parameters:

| Parameter | Typical value |
|---|---|
| `utm_source` | `shortio`, or the name of the channel |
| `utm_medium` | `short-link` |
| `utm_campaign` | `{slug}` |
| `utm_term` | usually blank |
| `utm_content` | usually blank |

**A blank default is not added at all.** Leave the whole section empty and no link gets campaign
parameters.

Each value can be an **object template**, so a default can still vary per entry:

```
{slug}                    → launch-day-2025
{section.handle}          → blog
{postDate|date('Y-m')}    → 2026-08
```

That is how one default gives every entry its own campaign name.

## Per-entry overrides

The entry sidebar has a **Campaign tracking** section, collapsed unless the entry has overrides
of its own. The summary line shows what will actually be sent, so you can see it without opening
the section.

- **Type a value** to override the default for that entry.
- **Leave a field blank** to inherit. The default appears as the field's placeholder, so it is
  clear what a blank field will send.
- **Switch off "Add campaign tracking"** to give that one entry no parameters at all, without
  losing the values you have typed - switching it back on restores them.

Overrides and defaults mix freely: overriding `utm_campaign` alone keeps the default source and
medium.

Editing these needs the **Create, rename and remove short links** permission. Without it the
fields are read-only, and posted values are ignored server-side rather than only being disabled
in the markup.

## What actually happens

Short.io holds the parameters as fields on the link and folds them into the destination when
someone follows it:

```
https://go.example.com/launch
  → https://example.com/posts/launch-day-2025?utm_source=shortio&utm_medium=short-link&utm_campaign=launch-day-2025
```

Two consequences worth knowing:

- **An existing query string is preserved.** A destination that already has `?ref=x` keeps it,
  and the campaign parameters are appended.
- **Short.io rewrites the link's stored destination** to include them. The plugin keeps the clean
  URL in its own table, which is what lets it tell that nothing has actually changed and skip the
  API call on a routine re-save.

## Changing the defaults later

Changing a default does **not** rewrite existing links on its own - links are only updated when
their entry is saved. To roll a change out across a section:

```bash
php craft short-io/links/sync --section=blog
```

## Interaction with the destination template

**Destination template** shapes the URL before it reaches Short.io; campaign parameters are added
by Short.io on top. Use one or the other for UTM, not both, or you will get the parameters twice.
