# How it works

The plugin has no element type and no field type. It is five event handlers, five services and a
single database table.

## The entry lifecycle

Everything is driven by Craft's own entry events:

| Event | What happens |
|---|---|
| Before save | Work out what the link should be, and talk to Short.io. A failure here can block the save. |
| After save | Write the result to the database. Nothing here can fail. |
| After delete | Expire or delete the link, depending on whether the delete was soft or permanent. |
| After restore | Un-expire the link, so a restored entry gets its short URL back. |
| Sidebar HTML | Render the **Short link** panel into the entry editor. |

The split between before-save and after-save matters. The API call happens *before* the entry is
written, so if Short.io rejects the path you asked for, the save is stopped and you see why. Only
once Craft has committed the entry does the plugin write its own row - by which point there is
nothing left that can fail.

## Which entries are eligible

An entry gets a link when all of these hold:

- The plugin has an API key and a domain.
- The entry is in a section that is enabled in the settings.
- That section has URLs for the entry's site.
- The entry is live: enabled, past its post date, before its expiry date.

And it is skipped when:

- It is a draft or revision. (Autosaves therefore cost nothing.)
- It has no section at all - on Craft 5 an entry nested in a Matrix field is still an entry, and
  without this guard every block save would be an API call.
- The request is a console command and **Sync on console commands** is off. Without that,
  one `php craft resave/entries` would be thousands of API calls.

## Working out the destination

At before-save an entry's URI is not settled. On a new entry it is null; if the slug changed in
this same save it is still the old one, because Craft regenerates the URI during validation -
which runs *after* before-save.

So the plugin computes the URI Craft is about to assign, on a throwaway clone of the entry, and
uses that. Getting this wrong is subtle: the link would work, but point at the entry's previous
URL.

If you have set a **Destination template**, it is rendered at this point with the resolved URL
available as `{url}` - which is how UTM parameters get attached.

## Keeping Craft and Short.io in step

Some shorteners let you stamp your own identifier onto a link, so the mapping between their
records and yours is stored on their side and can always be rebuilt. **Short.io has no such
field.** Links are identified only by their own id, or by domain plus path.

That makes the plugin's `shortio_links` table the source of truth, and means it needs to be able
to recover when the two sides drift apart. Three mechanisms do that:

**Creates never duplicate.** Every create tells Short.io not to allow duplicates, so if a link
already exists for the same destination on the same domain, Short.io hands that one back instead
of minting a second. A lost local row therefore re-adopts the original link rather than
scattering copies. (One wrinkle: on a duplicate hit Short.io ignores the path you asked for, so
the plugin follows up with a rename.)

**A taken path is investigated, not just reported.** When Short.io says a path is in use, the
plugin looks that link up. If our table already claims it for a different entry, the save is
blocked with a message naming the clash. If nothing claims it and it already points where we
want, it is adopted - this is the usual case after a database restore. If nothing claims it and
it points elsewhere, it is either taken over or reported, depending on the **Adopt existing
paths** setting.

**A missing link is re-found or recreated.** If the stored link id no longer exists at Short.io,
the plugin looks up the domain and path. If a different link now sits there, the row is
re-pointed at it. If nothing is there, the row is dropped and a fresh link created. A
contradictory answer is treated as a temporary glitch and left alone - the plugin does not delete
local data on an ambiguous response.

`php craft short-io/links/verify` runs that same check across every row without saving anything.

## Nothing changed, nothing sent

Before updating, the plugin compares the destination, path, title and suspended state against
what it last sent. If nothing relevant changed, no request is made at all. Re-saving an entry you
did not really edit costs nothing.

## When Short.io is unreachable

A bad path or a rejected key is a real error and always blocks the save. A timeout, a 429 or a
5xx is different - it says nothing about whether your entry is valid.

By default those block the save too, so a link is never silently skipped. Setting **If Short.io
is unreachable** to *Save anyway and retry later* lets the entry through and queues a retry job
instead, using Short.io's own `Retry-After` value as the delay.

The plugin also throttles itself against Short.io's published rate limits, per endpoint. In a web
request it fails fast rather than sleeping; in console commands and queue jobs it waits.
