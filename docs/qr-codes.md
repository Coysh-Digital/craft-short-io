# QR codes

Short.io's QR endpoint is an authenticated `POST` that returns raw image bytes. There is no URL a
browser can fetch on its own, which shapes everything here: **every QR code is fetched by the
plugin, cached, and served by Craft.**

## In the control panel

The **Show in the entry sidebar** setting gives you a small icon, a full image, or nothing. Both
visible modes link through to a download.

Images are served by a control panel action that requires the **View short links** permission and
checks that the link is one this site actually manages - so the endpoint cannot be used as a
general-purpose proxy to every link on your Short.io account.

## On the front end

```twig
<img src="{{ craft.shortIo.qrSrc(entry) }}" alt="QR code">
```

By default, on a front-end request this returns a **data URI**: the image bytes come straight out
of the cache and are inlined into the HTML. No public endpoint is exposed, and there is no second
request.

The cost is roughly 1-3 KB of markup per QR code. That is fine for one on a page, and less fine
for twenty.

### The public endpoint

If you do have many QR codes on one page, turn on **Public QR endpoint**. `qrSrc()` then returns
a signed URL that anyone can fetch, so the browser caches the images normally.

The signature covers the link id and the styling, so the URL cannot be edited into a request for
a different link. **Signed URL lifetime** defaults to `0`, meaning never expires - which is what
statically cached pages need, since a URL baked into cached HTML outlives any short expiry.

## Styling

The **Styling** setting is a single row: size, foreground, background and format.

Two things worth knowing:

- **Size is a scale factor from 1 to 99, not a pixel count.**
- **There is no margin option.** Short.io does not offer one.

Leave a cell blank to fall back to whatever that domain is configured to do in Short.io itself.
Setting either colour switches off the "use domain settings" flag automatically - without that,
Short.io ignores custom colours entirely.

## Caching

Generated images are cached for 30 days by default, keyed on the link and the exact styling. A
QR code only changes if you change the styling, so this is deliberately long. Changing a setting
invalidates the affected images on its own, because the styling is part of the cache key.

## Raw bytes

If you want to write the file yourself - into an asset volume, or an email attachment:

```twig
{% set png = craft.shortIo.qrBytes(entry, { size: 12, type: 'png' }) %}
```
