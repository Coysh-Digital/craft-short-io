---
layout: home

hero:
  name: Short.io
  text: Short links for Craft entries
  tagline: Publish an entry, get a short link. Change the slug, the link follows. Unpublish, and it stops working. Nobody has to remember to tidy up afterwards.
  actions:
    - theme: brand
      text: Get started
      link: /installation
    - theme: alt
      text: How it works
      link: /how-it-works

features:
  - title: Links that follow your entries
    details: A short link is created when an entry goes live, repointed when its slug changes, expired when it comes down, and restored when it goes back up. All of it off Craft's own save events.
  - title: QR codes anywhere
    details: Short.io serves QR images from a public URL, so one line of Twig puts a QR code on any page, in any email, cached by browsers and CDNs like any other image.
  - title: Human clicks, told apart
    details: Short.io separates bot traffic from real visitors. Both numbers show in the entry sidebar and on the Links screen, read from a local snapshot rather than an API call per row.
  - title: Adopt the links you already have
    details: Installing on a site that already uses Short.io? One command matches every existing link to the entry it points at, without writing anything back to Short.io.
  - title: A doctor built in
    details: One command creates a throwaway link, expands it, fetches its QR code and statistics, and deletes it again - so a wrong API key is something you find, not something an editor reports.
---

## What it is

Short.io for Craft gives every entry a short link, and keeps that link correct without anyone
having to think about it. It is a small plugin by design: no new element type, no new field type,
just a panel in the entry sidebar, a Links screen in the control panel, and a few Twig functions.

The interesting part is not creating a link - that is one API call. It is everything after: what
happens when the slug changes, when the entry is unpublished, when someone deletes the link in
the Short.io dashboard, when two entries want the same path, or when Short.io is briefly down
mid-save. Those are the cases this plugin exists to get right.

## What you get

- **An entry sidebar panel** with the short URL, a copy button, an editable path, click counts
  and a QR code.
- **A Links screen** listing every link with its destination, entry and clicks, with re-sync and
  delete actions, behind its own user permissions.
- **Automatic lifecycle handling** - create, repoint, expire, restore, delete - driven by Craft's
  entry events rather than by anyone remembering.
- **Custom paths with real conflict handling.** Claim a path and the save either succeeds or is
  blocked with a readable message. If the link that owns the path is already yours, it is adopted
  rather than duplicated.
- **QR codes** as ordinary image URLs, usable in the control panel, on the front end and in
  email.
- **Console commands** for adopting existing links, verifying drift, refreshing click snapshots,
  pruning orphans and diagnosing the connection.
- **Craft 4 and Craft 5 support** from one release.
