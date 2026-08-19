# Templating

Everything is on `craft.shortIo`.

```twig
{{ craft.shortIo.link(entry) }}      {# https://go.example.com/launch, or null #}
{{ craft.shortIo.path(entry) }}      {# launch, or null #}
{{ craft.shortIo.clicks(entry) }}    {# { totalClicks: 1204, humanClicks: 980 }, or null #}
{{ craft.shortIo.qrUrl(entry) }}     {# a public image URL - qrSrc() is an alias #}
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
    <img src="{{ craft.shortIo.qrUrl(entry) }}" alt="QR code for {{ entry.title }}" width="160">

    {% set clicks = craft.shortIo.clicks(entry) %}
    {% if clicks and clicks.humanClicks > 0 %}
      <p>Shared {{ clicks.humanClicks }} times</p>
    {% endif %}
  </div>
{% endif %}
```

## QR options

`qrUrl()` and `qrBytes()` take an options hash that overrides the configured styling:

```twig
{{ craft.shortIo.qrUrl(entry, { size: 12, color: '0EA5E9' }) }}
```

`size` is a scale factor from 1 to 99, not pixels. Colours are plain hex - a leading `#` is
stripped for you, since Short.io rejects it. Setting a colour switches off Short.io's
domain-level defaults automatically.

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
Short.io's statistics host, cached for 15 minutes by default. `qrUrl()` reads a 30-day cache and
only calls Short.io on a miss - and what it returns is an ordinary remote image URL, so the
browser caches the image itself.

`qrBytes()` is the exception: it fetches the image server-side on every call, so do not use it in
a loop over a long list.
