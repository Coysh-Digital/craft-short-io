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
- **QR codes as ordinary image URLs.** Short.io generates a QR on first request and then serves it
  from a public URL, so `craft.shortIo.qrUrl(entry)` drops straight into an `<img>` tag on the
  front end or in an email, and is cached by browsers and CDNs like any other image. The plugin
  caches the URL for 30 days.
- **Human clicks alongside total clicks**, which Short.io separates for you. Figures cover the
  last 30 days.
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

Several of these were found by testing against a live Short.io account, and contradict the
published API reference. They are recorded here because they shaped the design.

- **QR codes are not image bytes.** `POST /links/qr/{id}` is documented as returning an image; it
  returns JSON containing a public URL, and that URL 403s until the authenticated call has been
  made once. So the call generates the image and the URL serves it - which is a better outcome
  than the documentation suggests, and is why there is no image proxy here.
- **Link ids are `link_…`, not `lnk_…`.** The reference documents the latter throughout.
- **A `Content-Type` header breaks DELETE.** Short.io answers a body-less DELETE carrying
  `Content-Type: application/json` with 400 Bad Request. Sending no such header deletes the link
  as documented.
- **`archived: false` on the update endpoint does nothing.** It answers 200 and leaves the link
  archived. The dedicated `/links/unarchive` endpoint is the only way to reverse it.
- **Link expiry is a paid feature**, answering 402 on plans without it. See below.
- **Statistics `period=total` reports 0** regardless of a link's real traffic, including on
  long-established links. Every other period works, so figures default to the last 30 days -
  which is Short.io's own default too.
- **QR colours must be plain hex**, without the `#` that Craft's colour field stores.
- **Unpublishing expires a link rather than archiving it.** Short.io's documentation is explicit
  that an archived link "remains accessible and functions as intended" - archiving only hides it
  from the dashboard, so archiving an unpublished entry's link would leave it redirecting happily
  to a 404. The plugin sets an expiry and a fallback destination instead, which actually stops
  the link, keeps its path reserved, and is undone the moment the entry goes live again.

  Expiry is a paid Short.io feature. On a plan without it the API answers 402 and the plugin
  repoints the link at the fallback destination instead - same visible outcome, equally
  reversible, since the entry's own URL stays on the plugin's record.
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
