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

## An entry saved but no link appeared

By default an outage at Short.io doesn't stop anyone publishing: the save goes through and a
queue job retries the link afterwards. So if Short.io was unreachable, the entry is saved and the
link arrives when the queue next runs.

That means **the queue has to be running**. If it isn't, the job sits there and the link never
appears. Check Utilities → Queue Manager.

Set **If Short.io is unreachable** to *Block the save* if you would rather an editor found out
immediately than have a link arrive late.

## Saving an entry is blocked

Two things stop a save whatever the failure mode is set to, because both are about the entry
rather than about Short.io being up:

- **the API key was rejected** - see above
- **the path is already taken** - see below

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

## Click counts show zero

If you are passing `'total'` as the period, that is why: Short.io's `total` returns 0 regardless
of a link's real traffic. Use the default (last 30 days) or another named period. See
[Clicks](/clicks#the-period).

If `humanClicks` is 0 but `totalClicks` is not, that is usually correct - link previews, crawlers
and anything testing with `curl` count as clicks but not as humans.

## Click counts look stale on the Links screen

They are snapshots, refreshed by a queue job that the screen queues for itself. If they never
change, the queue is not running: check **Utilities → Queue Manager** for failed jobs, and that
`runQueueAutomatically` has not been turned off without a queue runner in its place.

The entry sidebar shows live figures, cached for 15 minutes, so a difference between the two
screens of a few minutes is normal.

To force the issue:

```bash
php craft short-io/links/refresh-stats
```

## QR codes do not appear

`qrUrl()` returns `null` when the entry has no link, or when Short.io could not be reached the
first time a QR was needed for it. The first call generates the image; after that it is cached.

Run `php craft short-io/links/diagnose`, which generates a QR and then fetches the image, so it
tells you which half is failing.

If a styling change has not shown up, note that Short.io reuses the same image URL - so it is
usually browser caching rather than the plugin.

## An unpublished entry's link still works

Check **When an entry is unpublished** is not set to *Leave it alone*, then test the link in a
browser rather than judging by the Short.io dashboard: an archived link still redirects, which is
why the plugin expires or repoints instead.

Link expiry is a paid Short.io feature. On a plan without it the plugin repoints the link at the
fallback destination instead, which you will see in the logs as an info message.

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
