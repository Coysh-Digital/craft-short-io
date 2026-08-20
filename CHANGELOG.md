# Release Notes for Short.io

## 1.0.4 - 2026-08-20

### Added
- **Click counts keep themselves up to date**, so `short-io/links/refresh-stats` no longer needs
  a cron entry. Opening the Links screen queues a background job for any row whose figures have
  aged past *Statistics cache*, and opening an entry writes that row's snapshot from the call its
  sidebar was making anyway. The command is still there for refreshing everything at once.

### Changed
- **The Links screen is now one of Craft's own admin tables**, the same component behind Sections,
  Fields and Filesystems. Sorting by short link, clicks or date, searching, pagination and the
  delete confirmation all come from Craft rather than from markup this plugin maintained itself.
  Re-sync moved with it: tick the links you want and press **Re-sync** once, rather than a button
  per row.
- **The entry sidebar panel is built the way Craft builds its own.** The path field is a real
  sidebar row with your domain as its label, and the link, its click count and its QR code are a
  read-only metadata list underneath - the same structure as the Post Date and Author rows above
  it. The copy button is Craft's, so it reports through Craft's own notification.

### Fixed
- **A blocked save keeps the path you typed.** The message about a path already being in use now
  appears against the path field itself as well as in the error summary, and the field no longer
  snaps back to the stored path while the message talks about the one you typed.

## 1.0.3 - 2026-08-19

### Changed
- **An outage at Short.io no longer stops anyone publishing.** *If Short.io is unreachable* now
  defaults to saving the entry and retrying the link in the queue. A rejected API key or a path
  that's already taken still stops the save, because those are about the entry rather than about
  Short.io being up. Set it back to *Block the save* if you would rather editors found out
  immediately than have a link arrive a minute later. The queue does need to be running.
- **Campaign tracking starts collapsed**, whether or not the entry has overrides of its own. The
  summary line still shows what will be sent, so nothing is hidden - just quieter.
- **The path hint reads "clear this to remove the short link"** once a link exists, rather than
  repeating the domain that is already shown in full immediately above it.

### Fixed
- **An unsaved entry no longer previews a campaign of `__temp_a1b2c3`.** Craft gives a new entry a
  temporary slug, so a `{slug}` default rendered against it looked broken. The template itself is
  shown until there's a real slug to fill it with.

## 1.0.2 - 2026-08-19

### Added
- **Protect existing links**, on by default. Short.io has no field for marking which links belong
  to Craft, so this plugin's own table is the only record of that - which means a domain carrying
  links made by hand needed protecting. While the setting is on, the plugin will only ever modify
  a link recorded against that entry. A path that already exists stops the save and says so; a
  link already pointing at the entry's page is left alone rather than adopted; and a link that
  takes over the path of one the plugin has lost is not hijacked.
- **Automatic paths fall back rather than fail.** A path derived from the entry slug is only a
  suggestion, so a clash tries `-2` through `-6` instead of blocking a save over a name nobody
  chose. A path an editor typed still stops, because that one is their decision to resolve.

### Changed
- **Adopt existing paths now defaults to off**, and is ignored while existing links are protected.
  Taking over somebody else's link and repointing it is not a reasonable default on a domain
  Craft doesn't own outright.

## 1.0.1 - 2026-08-19

### Fixed
- **An ordinary save took the entry's own short link out of service.** Saving in the control panel
  applies a provisional draft and then deletes it, and Craft prunes old revisions as it goes. Both
  fire the same event the plugin used to detect an entry being deleted, so a routine save archived
  the link, repointed it at the site's home page, and reported it in the sidebar as expired - on an
  entry that was live. Archived links are hidden from the Short.io dashboard, so it looked as
  though no link had been created at all.
- **Re-sync, the console commands and the retry job did nothing.** Each one suspends the save
  events before working, and that same flag was being consulted by the check that decides whether
  an entry is eligible - so the work was skipped silently. Suspension and the resave guard now
  belong to the save events alone. The console guard applies only to saves; deleting an entry
  still tidies up its link however it was triggered.
- **Re-sync now means re-sync**, pushing the link again rather than only when something looks
  different, and a link that goes live is brought back out of Short.io's archive.

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
- **Campaign tracking.** Default UTM parameters are set once for the site and can be overridden
  on any entry from its sidebar, inherited field by field, or switched off entirely for a single
  link without losing the values typed against it. Defaults are object templates, so
  `utm_campaign` can be `{slug}` and every entry gets its own campaign name with no per-entry
  work. Short.io holds the parameters natively and folds them into the destination on redirect,
  preserving any query string the destination already had.
- **Craft 4 and Craft 5 support from a single release.**
- **Environment-variable-friendly settings.** The API key and domain both accept a reference such
  as `$SHORT_IO_API_KEY`, so secrets stay out of project config and a multi-domain account can
  point staging and production at different domains from the same deployed code. The domain field
  suggests the domains on your account alongside your environment variables.
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
- **Campaign parameters must be sent as a complete set.** Updating one on its own wipes the other
  four *and* any query string the destination already had, because Short.io rebuilds the
  destination from these fields on every write. The plugin therefore always sends all five.
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
