# QR codes

Every short link can have a QR code, and it works anywhere - the control panel, front-end
templates, emails - because Short.io serves the image from a public URL.

## How it works

Short.io's API reference describes `POST /links/qr/{id}` as returning image bytes. It doesn't.
It returns JSON holding a URL on `shortiougc.com`, and that URL serves the PNG to anyone.

The authenticated call is what *generates* the image: fetching the URL before the API has been
asked for it once returns 403. So the plugin calls it the first time a QR is needed for a link,
caches the resulting URL for 30 days, and hands that URL out from then on.

The practical upshot is the nice one: a QR code is an ordinary remote image. No proxying, no
data URIs, no signed URLs, and browsers and CDNs cache it like anything else.

## In the control panel

**Show in the entry sidebar** gives you a small icon, a full image, or nothing. Either visible
mode links through to a download.

The download goes through the plugin rather than linking straight at the image, because a
browser ignores the `download` attribute on a cross-origin link - the file has to come from
this origin to arrive as an attachment. That action needs the **View short links** permission
and only serves links this site manages.

## In templates

```twig
<img src="{{ craft.shortIo.qrUrl(entry) }}" alt="QR code">
```

`qrSrc()` is an alias, if that reads better to you. Both return `null` when the entry has no
link, so guard them:

```twig
{% set qr = craft.shortIo.qrUrl(entry) %}
{% if qr %}
  <img src="{{ qr }}" alt="QR code for {{ entry.title }}" width="160">
{% endif %}
```

The first call for a given link makes one API request; after that it comes from the cache.

## Styling

The **Styling** setting is a single row: size, foreground, background and format.

Things worth knowing, none of which match the API reference:

- **Size is a scale factor from 1 to 99, not a pixel count.**
- **There is no margin option.** Short.io does not offer one.
- **Colours must be plain hex with no `#`.** The control panel's colour picker stores `#0ea5e9`;
  the plugin strips the hash before sending, because Short.io validates against
  `^[0-9A-Fa-f]{6,8}$` and rejects the version with it.
- Setting either colour switches off Short.io's domain-level defaults automatically. Without
  that, custom colours are silently ignored.
- **The image URL does not change when the styling does.** Short.io regenerates the image behind
  the same URL, so a styling change can take a moment to appear and may sit behind browser
  caching.

Leave a cell blank to use whatever that domain is configured to do in Short.io itself.

## Raw bytes

If you want the file rather than a URL - to attach to an email, or write into a volume:

```twig
{% set png = craft.shortIo.qrBytes(entry) %}
```

That fetches the public image server-side. It is a real HTTP request each time, so do not call it
in a loop over a long list.

## Caching

The image *URL* is cached for 30 days (**QR cache**). The image itself is cached by whatever sits
in front of it - the browser, a CDN - exactly like any other remote image.
