# Clicks

Short.io reports two numbers for every link: **total clicks**, and **human clicks** with bot
traffic filtered out. The plugin shows both wherever it shows either.

## In the entry sidebar

Live figures, cached for 15 minutes by default (**Statistics cache**). Today's numbers are cached
more briefly, since they are the ones actually moving.

Turn the display off entirely with **Show click counts**.

## On the Links screen

Snapshots from the local table, not live calls. See [The Links screen](/links) for why, and for
the refresh command.

## In templates

```twig
{% set clicks = craft.shortIo.clicks(entry) %}
{% if clicks %}
  {{ clicks.totalClicks }} clicks, {{ clicks.humanClicks }} of them human
{% endif %}
```

A period can be passed as the second argument: `today`, `yesterday`, `total`, `week`, `month`,
`lastmonth`, `last7` or `last30`. It defaults to `total`.

```twig
{{ craft.shortIo.clicks(entry, 'last7').humanClicks }}
```

The method returns `null` when the entry has no link, or when Short.io could not be reached -
so always guard it.

## A note on cost

Statistics come from a different Short.io host from the links themselves, and are the slowest
part of the integration. That is why the Links screen uses snapshots, and why the sidebar asks
Short.io to skip the country/browser/referrer breakdowns it does not display.
