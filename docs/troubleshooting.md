# Troubleshooting

Start here:

```bash
php craft short-io/links/diagnose
```

It creates a throwaway link, looks it up, updates it, fetches its QR code and statistics, lists
links and deletes the test link again - with a tick or a cross at each step. Most problems below
show up as a specific failing line.

## "Short.io rejected the API key"

The key is wrong, expired, or the environment variable it points at is empty in this environment.

Check the variable actually resolves - the settings screen shows `$SHORT_IO_API_KEY`, which is the
*name*, so a typo in `.env` looks identical to a correct setup until you run `diagnose`.

Note that Short.io keys can be scoped to a specific domain or team. A key scoped elsewhere
authenticates fine and then fails on your domain.

## Saving an entry is blocked while Short.io is down

That is the default, so a link is never silently skipped.

If you would rather editors kept working, set **If Short.io is unreachable** to *Save anyway and
retry later*. The save goes through and a queue job retries, so make sure your queue actually
runs.

A rejected key or a taken path always blocks, in either mode - those are real errors about your
data, not outages.

## Nothing happens when I save an entry

The plugin acts only when all of these are true:

- an API key and domain are configured
- the entry is in a section enabled under **Sections**
- that section has URLs for that site
- the entry is live - enabled, past its post date, before its expiry
- **Create links automatically** is on, or an editor typed a path

Console commands are also skipped unless **Sync on console commands** is on.

## A short link still works after unpublishing the entry

Check **When an entry is unpublished** is not set to *Leave it alone*.

If it is set to *Expire the link*, test the link in a browser rather than judging by the Short.io
dashboard. Archiving a link on Short.io does not stop it redirecting - it only hides it from the
dashboard - which is exactly why the plugin expires links instead.

## "That short link path is already in use"

Something else on your domain owns that path.

- If it is another entry's link, pick a different path.
- If it is a link created by hand that points where you want, the plugin adopts it automatically.
- If it points somewhere else and you want to take it over, turn on **Adopt existing paths**.

## Links exist at Short.io but not in Craft

Run `php craft short-io/adopt`. See [Adopting existing links](/adopting-links).

## Click counts look stale on the Links screen

They are snapshots. Schedule:

```bash
php craft short-io/links/refresh-stats
```

The entry sidebar shows live figures, cached for 15 minutes.

## QR codes do not appear on the front end

By default `qrSrc()` returns a data URI, which needs no endpoint but does need the image to be
fetchable at render time. If Short.io was unreachable when the page rendered, you get `null`.

Check the plugin's log entries, and confirm the link exists at Short.io. `diagnose` exercises the
QR endpoint specifically - some Short.io plans restrict it.

## Someone edited a link in the Short.io dashboard

Run:

```bash
php craft short-io/links/verify --fix
```

Links that moved are re-pointed; links that vanished have their rows removed so the next save
recreates them. Re-syncing a single link is also available from the Links screen.

## Rate limits

Short.io publishes per-endpoint limits, and the plugin throttles itself against them. In a web
request it fails fast rather than making an editor wait; in console commands and queue jobs it
waits and continues.

If you are hitting limits during a bulk operation, run it as a console command rather than
through the control panel.
