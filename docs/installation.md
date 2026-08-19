# Installation

## Requirements

- Craft CMS 4.0 or later, or Craft CMS 5.0 or later
- PHP 8.2 or later
- A Short.io account with at least one connected domain

## Install the plugin

```bash
composer require coysh-digital/craft-short-io
php craft plugin/install short-io
```

## Create an API key

In your Short.io dashboard, go to **Integrations and API** and click **Create API key**. Leave the
*Public key* toggle off - the plugin needs a secret key. You will be asked to scope the key to a
team or domain before it is created.

Short.io does not let you recover a secret key later, so copy it now. If you lose it, create a
new one.

## Store the key in an environment variable

Put the key and your domain in `.env`:

```bash
SHORT_IO_API_KEY=sk_yourkeyhere
SHORT_IO_DOMAIN=go.example.com
```

Then, on the plugin's settings screen, enter the **variable name** rather than the key itself:

- API key: `$SHORT_IO_API_KEY`
- Domain: `$SHORT_IO_DOMAIN`

The plugin resolves these at request time. That keeps the secret out of project config, out of
version control, and out of the control panel - and lets staging and production use different
keys from the same deployed code.

::: warning
Setting `apiKey` in `config/short-io.php` instead resolves the secret at config-load time and
puts the plaintext into the settings model. That is exactly what the environment variable support
exists to avoid. Prefer the `$SHORT_IO_API_KEY` reference.
:::

## Check it works

```bash
php craft short-io/links/diagnose
```

This creates a throwaway link on your domain, looks it up, updates it, fetches its QR code and
statistics, lists links, and deletes the test link again - printing a tick or a cross for each
step. If anything is wrong with the key, the domain or your plan's permissions, this is where you
find out.

## Choose which sections get links

By default every section that has URLs is eligible. Narrow that on the settings screen under
**Sections**, or per environment with an environment variable:

```bash
SHORT_IO_SECTIONS=blog,news
```

The environment variable wins over the stored setting, and the settings field shows a warning
when it is in effect.

## Existing Short.io links

If the site already uses Short.io, bring those links under Craft's management before you start
publishing:

```bash
php craft short-io/adopt --dry-run
php craft short-io/adopt
```

See [Adopting existing links](/adopting-links).
