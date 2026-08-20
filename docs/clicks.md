# Clicks

Short.io reports two numbers for a link: **total clicks**, and **human clicks** with bot traffic
filtered out. The plugin shows both wherever it shows either.

## The period

Figures cover **the last 30 days** by default.

That is Short.io's own default, and here it is also the only sensible one:

::: warning
Short.io's `total` period reports **0 regardless of a link's real traffic**. It returns zero for
established links with plenty of clicks, so it cannot be used as a lifetime figure.
:::

You can pass any of `today`, `yesterday`, `week`, `month`, `lastmonth`, `last7`, `last30` or
`total` - but bear the above in mind before reaching for the last one.

## In the entry sidebar

Live figures, cached for 15 minutes by default (**Statistics cache**). Today's numbers are cached
more briefly, since they are the ones actually moving.

Turn the display off entirely with **Show click counts**.

## On the Links screen

Snapshots from the local table, not live calls - a fifty-row page would otherwise be fifty HTTP
requests to a second host before it could render.

Nothing needs scheduling to keep them fresh. Viewing the screen queues a background refresh for
any row that has aged past **Statistics cache**, and Craft's queue does the work; viewing an entry
updates that entry's row from the call the sidebar makes anyway. A count on the Links screen can
therefore be a few minutes behind the one in the sidebar.

To refresh every link at once - after `short-io/adopt`, or from cron if you prefer:

```bash
php craft short-io/links/refresh-stats
```

The command works oldest-first and throttles itself, so it is safe against a large account.

## In templates

```twig
{% set clicks = craft.shortIo.clicks(entry) %}
{% if clicks %}
  {{ clicks.totalClicks }} clicks in the last 30 days,
  {{ clicks.humanClicks }} of them human
{% endif %}
```

A period can be passed as the second argument:

```twig
{{ craft.shortIo.clicks(entry, 'last7').humanClicks }}
```

The method returns `null` when the entry has no link, or when Short.io could not be reached, so
always guard it.

## Bots

`humanClicks` can be a lot lower than `totalClicks`, and that is usually correct rather than
alarming - link previews in chat apps, crawlers and monitoring all count as clicks. Testing a
link with `curl` will show up as a click but not a human one.

## A note on cost

Statistics come from a different Short.io host from the links themselves, and are the slowest
part of the integration. That is why the Links screen uses snapshots, and why the sidebar asks
Short.io to skip the country, browser and referrer breakdowns it does not display.
