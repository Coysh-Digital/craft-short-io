<?php
/**
 * Short.io config
 *
 * Copy this file to your project's config/ directory as `short-io.php` and
 * uncomment any settings you'd like to override. Values set here take
 * precedence over what's configured in the control panel.
 */

use craft\helpers\App;

return [
    // Your Short.io secret API key.
    //
    // Prefer leaving this commented out and entering the literal string
    // $SHORT_IO_API_KEY in the control panel field instead. The plugin resolves
    // it at request time, so the secret never enters project config and the CP
    // never displays it. Setting it here resolves the key at config-load time
    // and puts the plaintext into the settings model, which is exactly what the
    // environment variable support exists to avoid.
    // 'apiKey' => App::env('SHORT_IO_API_KEY'),

    // The Short.io domain links are created on, e.g. 'go.example.com'.
    // 'domain' => App::env('SHORT_IO_DOMAIN'),

    // Which sections get short links: '*' for all, or a list of handles.
    // The SHORT_IO_SECTIONS environment variable overrides this at runtime.
    // 'sections' => '*',

    // 'all' gives every site its own link; 'primary' only links the primary site.
    // 'siteMode' => 'all',

    // Whether a link is created automatically when an entry goes live, with no
    // editor action. Turn this off to make short links opt-in per entry.
    // 'autoPath' => true,

    // A prefix applied to automatically generated paths, e.g. 'blog/'.
    // 'pathPrefix' => '',

    // The HTTP redirect code Short.io serves: 301, 302, 307 or 308.
    // 'redirectType' => 302,

    // Whether the entry title is sent to Short.io as the link title.
    // 'titleFromEntry' => true,

    // Tags applied to every link the plugin creates.
    // 'tags' => ['craft'],

    // An object template used to build the destination URL. The resolved entry
    // URL is available as {url}, so this is where UTM parameters go.
    // 'destinationTemplate' => '{url}?utm_source=short&utm_campaign={slug}',

    // Whether an existing, unclaimed link at a wanted path is adopted and
    // repointed rather than reported as a conflict.
    // 'adoptExistingPaths' => true,

    // What happens when an entry stops being live: 'expire', 'delete' or
    // 'nothing'.
    //
    // 'expire' is the default because archiving alone does NOT stop a Short.io
    // link redirecting - the docs are explicit that an archived link "remains
    // accessible and functions as intended". Expiry actually stops it, keeps
    // the path reserved, and is undone the moment the entry goes live again.
    // 'onUnpublish' => 'expire',

    // The same, for a soft-deleted entry. A hard delete always deletes the link.
    // 'onDelete' => 'expire',

    // Where an expired link sends visitors. Blank means the site's base URL.
    // 'expiredUrl' => '',

    // 'block' stops the entry saving when Short.io can't be reached; 'warn' lets
    // the save through and retries in the queue.
    // 'failureMode' => 'block',

    // Whether console requests - including `php craft resave/entries` - sync
    // links. Off by default: one resave would otherwise be thousands of API
    // calls.
    // 'syncOnResave' => false,

    // How the QR code appears in the entry sidebar: 'none', 'icon' or 'full'.
    // 'qrViewMode' => 'icon',

    // A single row of QR styling. Blank cells fall back to the domain's own
    // settings on Short.io. Note that `size` is a small scale factor (1-99),
    // not a pixel count, and that there is no margin option.
    // 'qrStyle' => [
    //     ['size' => 8, 'color' => '', 'backgroundColor' => '', 'type' => 'png'],
    // ],

    // Whether a signed, anonymous QR endpoint is exposed to the front end.
    // Leave this off unless you have many QR codes on one page: with it off,
    // front-end templates get a data URI instead, which needs no public
    // endpoint at all.
    // 'qrPublic' => false,

    // How long a signed QR URL stays valid, in seconds. 0 means it never
    // expires, which is what statically cached pages need.
    // 'qrSignedUrlTtl' => 0,

    // Whether click counts are shown in the entry sidebar.
    // 'showClicks' => true,

    // The HTTP timeout, in seconds, for Short.io requests.
    // 'httpTimeout' => 10,

    // Cache durations, in seconds.
    // 'statsCacheDuration' => 900,
    // 'domainCacheDuration' => 3600,
    // 'qrCacheDuration' => 2592000,
];
