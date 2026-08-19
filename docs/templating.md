# Templating

Everything is on `craft.shortIo`.

```twig
{{ craft.shortIo.link(entry) }}      {# https://go.example.com/launch, or null #}
{{ craft.shortIo.path(entry) }}      {# launch, or null #}
{{ craft.shortIo.clicks(entry) }}    {# { totalClicks: 1204, humanClicks: 980 }, or null #}
{{ craft.shortIo.qrSrc(entry) }}     {# a data URI, or a signed URL #}
{{ craft.shortIo.qrBytes(entry) }}   {# raw image bytes #}
```

Every method resolves through the entry's **canonical** id, so they return the right link inside a
draft preview rather than nothing.

Every method returns `null` when the entry has no link. Guard accordingly:

```twig
{% set short = craft.shortIo.link(entry) %}
{% if short %}
  <a href="{{ short }}">Share this</a>
{% endif %}
```

## A share block

```twig
{% set short = craft.shortIo.link(entry) %}
{% if short %}
  <div class="share">
    <input type="text" value="{{ short }}" readonly>
    <img src="{{ craft.shortIo.qrSrc(entry) }}" alt="QR code for {{ entry.title }}" width="160">

    {% set clicks = craft.shortIo.clicks(entry) %}
    {% if clicks and clicks.humanClicks > 0 %}
      <p>Shared {{ clicks.humanClicks }} times</p>
    {% endif %}
  </div>
{% endif %}
```

## QR options

`qrSrc()` and `qrBytes()` take an options hash that overrides the configured styling:

```twig
{{ craft.shortIo.qrSrc(entry, { size: 12, type: 'svg', color: '#0EA5E9' }) }}
```

`size` is a scale factor from 1 to 99, not pixels. `type` is `png` or `svg`. Setting a colour
switches off Short.io's domain-level defaults automatically.

## Meta tags

Because `link()` is a plain string, it drops straight into anything:

```twig
{% set short = craft.shortIo.link(entry) %}
{% if short %}
  <link rel="shortlink" href="{{ short }}">
  <meta property="og:url" content="{{ short }}">
{% endif %}
```

## Caching

`link()` and `path()` read the local table, so they are effectively free. `clicks()` hits
Short.io's statistics host, cached for 15 minutes by default. `qrSrc()` and `qrBytes()` read a
30-day cache, and only call Short.io on a miss.

If you are rendering many QR codes on one page, read [QR codes](/qr-codes) - the default data URI
approach inlines each image into the HTML, which is not what you want at volume.
