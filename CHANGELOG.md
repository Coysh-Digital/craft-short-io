# Release Notes for Short.io

## 1.0.0 - 2026-08-19

### Added
- **Short links for entries, managed automatically.** Publishing an entry creates a Short.io
  link; changing its slug repoints the link; unpublishing expires it and re-publishing brings the
  same short URL back. The whole lifecycle hangs off Craft's entry save events, so nothing has to
  be kept in step by hand.
- **A Short link panel in the entry sidebar**, with the short URL and a copy button, an editable
  path, the click count, and a QR code. Leaving the path on `auto` derives it from the entry
  slug; clearing it removes the link.
- **A Links screen in the control panel**, listing every short link with its destination, entry,
  clicks and creation date, with re-sync and delete actions. It reads click counts from a local
  snapshot rather than calling the API once per row, so a fifty-row page is one query rather than
  fifty HTTP requests to a second host.
- **QR codes.** Short.io's QR endpoint is an authenticated POST returning image bytes, so there is
  no URL a browser can fetch directly. The plugin fetches and caches the image itself, serving it
  through a permission-gated control panel action. Front-end templates get a data URI by default,
  which means QR codes work with no public endpoint at all; a signed, cacheable URL is available
  behind a setting for pages carrying many of them.
- **Human clicks alongside total clicks**, which Short.io separates for you.
- **`php craft short-io/adopt`**, which matches short links you already have to the entries they
  point at. It pages the whole domain once and matches in memory, so adopting a large account
  costs no per-entry API calls. It never writes to Short.io.
- **`php craft short-io/links/diagnose`**, a doctor that creates a throwaway link, expands it,
  updates it, fetches its QR code and statistics, lists links and deletes the link again -
  printing a tick or a cross for each step. It is the fastest way to find out that an API key is
  wrong or a domain is misconfigured.
- **`php craft short-io/links/verify`**, which reports links that have drifted from what Short.io
  actually has, and repairs them with `--fix`. Plus `sync`, `refresh-stats` and `prune`.
- **Twig access** through `craft.shortIo.link()`, `.path()`, `.clicks()`, `.qrSrc()` and
  `.qrBytes()`, all resolving via the canonical entry so they work inside draft previews.
- **Craft 4 and Craft 5 support from a single release.**
- **User permissions**, as *View short links* with *Create, rename and remove short links* nested
  beneath it, alongside Craft's own *Access Short.io*. The nav item and the entry sidebar panel
  disappear for users without view access, and settings stay admin-only because they hold the API
  key. The manage permission is enforced in the save handler rather than only by rendering the
  path field read-only, so a hand-crafted request cannot rename a link or delete one by posting
  an empty path.

### Notes
- **Unpublishing expires a link rather than archiving it.** This is deliberate, and worth knowing
  if you are coming from another shortener. Short.io's documentation is explicit that an archived
  link "remains accessible and functions as intended" - archiving only hides it from the
  dashboard. Archiving an unpublished entry's link would therefore leave it redirecting happily
  to a 404. The plugin sets an expiry and a fallback destination instead, which actually stops
  the link, keeps its path reserved, and is undone the moment the entry goes live again.
- **The plugin's own table is the only record of which link belongs to which entry.** Short.io
  has no external-ID field to stamp, unlike some other shorteners, so the mapping cannot be
  rebuilt from the API alone. Back the table up, and re-run `short-io/adopt` after restoring a
  database.
- **An API failure blocks the entry save by default**, so a link is never silently skipped. Sites
  that would rather keep editors working during an outage can switch to *Save anyway and retry
  later*, which queues a retry job.
- **Console commands do not sync links by default.** Without that guard a single
  `php craft resave/entries` would become thousands of API calls. Turn on **Sync on console
  commands** if you want it.
