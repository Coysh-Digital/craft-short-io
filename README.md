# Short.io for Craft CMS

Short.io for Craft creates a short link for every entry you publish, keeps it pointing at the
right place as slugs change, and retires it when the entry comes down. Editors get a short URL
and a QR code in the entry sidebar without leaving Craft, and without anyone having to remember
to go and tidy up Short.io afterwards.

It is deliberately small. There is no new element type and no new field type - just a panel in
the entry sidebar, a Links screen in the control panel, and a handful of Twig functions. The
plugin hangs off the entry save lifecycle, so a link follows its entry through publishing,
renaming, unpublishing, deleting and restoring on its own.

## What it does

- **Links that follow your entries.** Publish an entry and it gets a short link. Change the
  slug and the link repoints itself. Unpublish and it stops working; publish again and the same
  short URL comes back.
- **Custom paths, safely.** Type a path in the sidebar to claim it. If it is already taken by
  something else, the save is blocked with a readable message rather than a stack trace, and if
  the existing link is one of yours the plugin adopts it instead of creating a duplicate.
- **QR codes.** Short.io serves QR images from a public URL, so one line of Twig puts a QR code
  on any page or in any email, cached by browsers and CDNs like any other image.
- **Campaign tracking.** Set default UTM parameters once and override them on any entry, or
  switch them off for a link that shouldn't be tagged. Defaults can be object templates, so
  `utm_campaign` can be the entry slug without touching a single entry.
- **Human clicks, told apart.** Short.io separates bot traffic from real visitors, so the sidebar
  and the Links screen show both.
- **Adopt what you already have.** `php craft short-io/adopt` matches existing Short.io links to
  the entries they point at, so you can install this on a site that already uses Short.io.
- **A doctor.** `php craft short-io/links/diagnose` creates a throwaway link, expands it, fetches
  its QR code and statistics, and deletes it again - so you find out your API key is wrong before
  an editor does.

## Documentation

Full documentation lives in the [`docs`](docs/) folder, and is published at
[coysh.digital/plugins/short-io/docs](https://coysh.digital/plugins/short-io/docs/). This README
is the short version; the docs go deeper on settings, the entry sidebar, QR codes, clicks,
templating, adopting existing links, and troubleshooting.

## Requirements

- Craft CMS 4.0 or later, or Craft CMS 5.0 or later
- PHP 8.2 or later
- A Short.io account with a connected domain

## Installation

```bash
composer require coysh-digital/craft-short-io
php craft plugin/install short-io
```

Then create a secret API key from **Integrations and API** in your Short.io dashboard, put it in
an environment variable, and reference it from the plugin's settings screen:

```bash
# .env
SHORT_IO_API_KEY=sk_yourkeyhere
SHORT_IO_DOMAIN=go.example.com
```

Enter `$SHORT_IO_API_KEY` in the API key field rather than the key itself. The plugin resolves it
at request time, so the secret stays out of project config and out of the control panel.

Finally, run the doctor to check everything is wired up:

```bash
php craft short-io/links/diagnose
```

## The entry sidebar

Editors see a **Short link** panel on any entry in an enabled section. It shows the short URL with
a copy button, a path field, the click count, and a QR code.

Leaving the path field on `auto` derives it from the entry slug. Typing a path claims that one.
Clearing the field removes the link entirely.

## Templating

```twig
{{ craft.shortIo.link(entry) }}      {# https://go.example.com/launch, or null #}
{{ craft.shortIo.path(entry) }}      {# launch #}
{{ craft.shortIo.clicks(entry) }}    {# { totalClicks: 1204, humanClicks: 980 } - last 30 days #}

<img src="{{ craft.shortIo.qrUrl(entry) }}" alt="QR code">
```

Everything resolves through the canonical entry, so these work inside a draft preview too.

`qrUrl()` returns a public image URL, so it works on the front end and in email with nothing
else to configure. The first call for a link generates the image; after that it is cached.

## Campaign tracking

Set defaults under **Short.io → Settings → Campaign tracking**:

```
utm_source    shortio
utm_medium    short-link
utm_campaign  {slug}
```

Every link then arrives as
`https://example.com/posts/launch-day?utm_source=shortio&utm_medium=short-link&utm_campaign=launch-day`.

Any entry can override any of those in its sidebar, inherit the rest, or switch campaign tracking
off entirely for itself. A blank default is simply not added, so leaving the section empty means
no link is tagged. Full details in [the docs](docs/campaigns.md).

## Adopting existing links

If the site already uses Short.io, bring those links under Craft's management:

```bash
php craft short-io/adopt --dry-run
php craft short-io/adopt
```

It pages through every link on your domain, matches each one to the entry it points at, and
writes the mapping locally. It never writes to Short.io.

One thing worth knowing: Short.io stores nothing that points back at Craft. Unlike some
shorteners there is no external-ID field to stamp, which makes the plugin's own table the only
record of which link belongs to which entry. Back it up, and re-run `short-io/adopt` after
restoring a database.

## Keeping click counts fresh

The Links screen reads click counts from a local snapshot rather than calling Short.io once per
row. Refresh the snapshots on a schedule:

```bash
php craft short-io/links/refresh-stats
```

## Troubleshooting

**Short.io rejected the API key.** The key is wrong, or the environment variable it points at is
empty in this environment. Run `php craft short-io/links/diagnose` to see which.

**Saving an entry is blocked while Short.io is down.** That is the default, so a link is never
silently skipped. If you would rather editors could keep working, set **If Short.io is
unreachable** to *Save anyway and retry later* - the save goes through and a queue job retries.

**A short link still redirects after the entry was unpublished.** Check the **When an entry is
unpublished** setting is not on *Leave it alone*. Note that archiving a link on Short.io does not
stop it working - the plugin expires links instead, which is why the default is *Expire the link*.

**Nothing happens when I save an entry.** The plugin only acts on live entries in sections that
have URLs and are enabled in the settings. Console commands are also skipped by default, so one
`php craft resave/entries` cannot turn into thousands of API calls.

**A path I want is taken.** If the existing link points somewhere else, either pick another path
or turn on **Adopt existing paths** to take it over. `php craft short-io/links/verify` reports
links that have drifted from what Short.io actually has.

## Permissions

Three gates, under **Settings → Users → Permissions**:

- **Access Short.io** - Craft's own per-plugin permission, needed to reach the section at all
- **View short links** - the Links screen, the entry sidebar panel and QR images
- **Create, rename and remove short links** - editing the path, and re-syncing or deleting

Settings are admin-only regardless, since they hold the API key.

The manage permission is enforced server-side, not just by rendering the path field read-only:
a posted path from someone without it is ignored, so a link cannot be renamed or deleted by
hand-crafting a request. Automatic link creation still works for everyone.

Full matrix in [the docs](docs/links.md#permissions).

## License

This is commercial software. See [LICENSE.md](LICENSE.md).
