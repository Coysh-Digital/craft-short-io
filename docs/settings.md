# Settings

Settings live at **Short.io → Settings**. Every one of them can be overridden in
`config/short-io.php`; a setting that is overridden shows as read-only in the control panel.

Copy `vendor/coysh-digital/craft-short-io/src/config.php` to `config/short-io.php` to start.

## Connection

| Setting | Notes |
|---|---|
| **API key** | Your Short.io secret key. Enter `$SHORT_IO_API_KEY` rather than the key itself. |
| **Domain** | The Short.io domain links are created on. Once a valid key is saved this becomes a picker; until then it is a text field. |

The domain is validated against your account, but only when the account's domain list can
actually be fetched. If there is no key yet, or Short.io is unreachable, validation stands aside
rather than blocking you from saving the screen at all.

## What gets a link

| Setting | Default | Notes |
|---|---|---|
| **Sections** | All | Only sections with URLs are listed. Overridable per environment with `SHORT_IO_SECTIONS`. |
| **Sites** | Every site | Or only the primary site, on a multi-site install. |
| **Create links automatically** | On | Off makes short links opt-in: nothing happens until an editor types a path. |
| **Path prefix** | – | Prepended to automatic paths, e.g. `blog/`. |
| **Redirect type** | 302 | 301 is cached hard by browsers, which makes a link effectively permanent. |
| **Use the entry title** | On | Sends the title to Short.io so links are recognisable in its dashboard. |
| **Tags** | – | Applied to every link the plugin creates. |
| **Destination template** | – | An object template; the resolved entry URL is `{url}`. This is where UTM parameters go. |

## Lifecycle

| Setting | Default | Notes |
|---|---|---|
| **When an entry is unpublished** | Expire the link | Or delete it, or leave it alone. |
| **When an entry is deleted** | Expire the link | Applies to soft deletes. A permanent delete always deletes the link. |
| **Expired link destination** | Site home page | Where an expired link sends visitors. |
| **If Short.io is unreachable** | Block the save | Or save anyway and retry in the queue. |
| **Adopt existing paths** | On | Take over an unclaimed link sitting at a path you want. |
| **Sync on console commands** | Off | Leave off unless you want `resave/entries` to sync links. |

::: warning Why expire rather than archive
Archiving a link on Short.io does **not** stop it working. Their documentation is explicit that an
archived link "remains accessible and functions as intended" - archiving only hides it from the
dashboard.

So archiving the link for an unpublished entry would leave it redirecting cheerfully to a 404.
The plugin sets an expiry and a fallback destination instead. That actually stops the link, keeps
its path reserved, and is undone the moment the entry goes live again.
:::

## QR codes

| Setting | Default | Notes |
|---|---|---|
| **Show in the entry sidebar** | Small icon | Or a full image, or nothing. |
| **Styling** | – | Size, foreground, background, format. Blank cells use the domain's own settings. |
| **Public QR endpoint** | Off | See [QR codes](/qr-codes). |
| **Signed URL lifetime** | 0 (never expires) | Only relevant with the public endpoint on. |

**Size is a scale factor from 1 to 99, not a pixel count.** There is no margin option - Short.io
does not have one.

## Clicks and advanced

| Setting | Default |
|---|---|
| **Show click counts** | On |
| **HTTP timeout** | 10 seconds |
| **Statistics cache** | 900 seconds |
| **Domain cache** | 3600 seconds |
| **QR cache** | 2592000 seconds (30 days) |

QR images only change when you change the styling, which is why they are cached for so long.
