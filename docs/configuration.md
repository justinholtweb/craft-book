---
title: Configuration
slug: configuration
order: 20
summary: The plugin settings, what they default to, and the three decisions worth making deliberately.
---

Settings live at **Book → Settings**, or in `config/book.php` if you would rather they were in the
repo. A config file wins over the control panel, and Craft grey-boxes any setting it overrides.

```php
<?php
return [
    'allowGoogleViewer' => true,
    'allowOfficeViewer' => true,
    'checkPublicUrl' => true,
    'serveAssetsThroughCraft' => false,
    'signedUrls' => false,
    'signedUrlDuration' => 86400,
    'inlineMaxBytes' => 1048576,
    'purifierConfig' => null,
    'registerCss' => true,
    'registerJs' => true,
    'richTextIntegration' => true,
    'defaultOptions' => [],
];
```

## Viewers

| Setting | Default | What it does |
| --- | --- | --- |
| `allowGoogleViewer` | `true` | Whether Google's viewer may be used at all |
| `allowOfficeViewer` | `true` | The same, for Microsoft's |
| `checkPublicUrl` | `true` | Refuse to hand an unreachable URL to a viewer that fetches it |

**`allowGoogleViewer` and `allowOfficeViewer` are the privacy switches.** Turning one off means
that service never receives a document URL from this site — not automatically, and not because an
author picked it from a menu. `auto` skips it, an explicit choice of it is refused, and the author
is told why on the edit screen rather than finding out from a blank frame.

This is a decision to make before authors start using the plugin, not after. Both viewers are
third-party services that download the file from your server to render it. If your documents are
not the sort of thing you would email to a stranger, turn them off and rely on `native`, `inline`
and `link`, none of which involve anybody else.

**`checkPublicUrl` is the staging guard.** Google and Microsoft fetch from their own servers, so a
URL on `.ddev.site`, `.test`, `.local`, `localhost` or a private-network address gives them
nothing to fetch — the reader gets a viewer box full of apology, and it works perfectly again the
moment you deploy. The check is syntactic: Book looks at the host and decides, and never makes a
request to find out. Leave it on. Turning it off does not make staging work; it makes staging fail
silently.

## Delivery

| Setting | Default | What it does |
| --- | --- | --- |
| `serveAssetsThroughCraft` | `false` | Route every asset through Book, even public ones |
| `signedUrls` | `false` | Add an expiring signature to the URLs Book serves |
| `signedUrlDuration` | `86400` | How long a signature lasts, in seconds; `0` never expires |
| `linkSecret` | `''` | Mixed into every token; change it to revoke every link Book has handed out |

An asset in a volume with **no** public URLs is always served through Book's own route, with a
token Book minted, whatever these are set to. That is not optional and it is what makes private
documents work at all.

`serveAssetsThroughCraft` extends the same treatment to assets that *do* have a public volume URL.
It is slower — every byte goes through PHP instead of the web server or the CDN — and it is worth
it when the volume path is something you would rather not publish.

`signedUrls` adds an expiry. **One consequence to know before you turn it on:** Google's and
Microsoft's viewers fetch the file from their own servers when the reader opens the page, so a
signature that has expired takes those embeds down with it. Book warns about that combination on
the edit screen rather than letting you discover it in six months. `native`, `inline` and `link`
are unaffected, because the reader's own browser is doing the fetching inside the signature's
lifetime.

Turning `signedUrls` on also forces public assets onto Book's route, because there is nowhere else
to put a signature. That is deliberate: the setting used to be ignored for public assets, which
made it look switched on and do nothing.

### Revoking links

A link to a private-volume file does not expire unless `signedUrls` is on, so a forwarded one keeps
working. To revoke **every** link Book has handed out at once, change `linkSecret`. Set it from an
environment variable so it can be changed without a deploy:

```php
// config/book.php
return [
    'linkSecret' => '$BOOK_LINK_SECRET',
];
```

Then put a new random value in `BOOK_LINK_SECRET` whenever you need to. Pages rendered after the
change get fresh links; cached pages and anything already shared stop working. Leaving it empty —
the default — signs links exactly as Book always has, so upgrading does not break existing ones.

## Inline rendering

| Setting | Default | What it does |
| --- | --- | --- |
| `inlineMaxBytes` | `1048576` | The most Book will read to render a file itself |
| `purifierConfig` | `null` | An HTML Purifier config for Markdown rendering |

`inlineMaxBytes` is a memory guard. Inline rendering reads the whole file into PHP, so a 400 MB
log is not a thing to do on a page view. Past the limit Book falls back to a download card and
says so.

`purifierConfig` names an HTML Purifier config file — `config/htmlpurifier/<name>.json` — the same way Craft's
own rich-text fields do. It applies to the **Markdown** path only — Markdown allows raw HTML by
design and these files come off an upload volume, so it is purified before it reaches the page.
CSV, JSON, text and code are escaped, not purified, because none of them can contain markup that
matters.

## Front end

| Setting | Default | What it does |
| --- | --- | --- |
| `registerCss` | `true` | Whether Book ships its own stylesheet |
| `registerJs` | `true` | Whether Book ships its own runtime |
| `richTextIntegration` | `true` | The CKEditor and Redactor buttons |

Turn `registerCss` off to style documents entirely yourself. The stylesheet is small and expressed
as custom properties on `.book`, so overriding four variables is usually easier than replacing it:

```css
.book {
    --book-border: #d9dee5;
    --book-surface: #f6f7f9;
    --book-accent: #2a6df4;
    --book-radius: 6px;
}
```

Turn `registerJs` off and documents still render — what you lose is click-to-load, the load
timeout, fullscreen and print. A document with `loading: 'click'` and no runtime shows its frame
immediately, which is not what you asked for, so change the option too.

`richTextIntegration` is the CKEditor toolbar button, the Redactor plugin and the shared picker.
**Turning it off does not stop documents rendering.** The editors write a Craft reference tag and
Craft parses reference tags with or without them — the integration is an editing affordance, never
part of the rendering path.

## Defaults for every document

`defaultOptions` seeds every new document and every embed that does not say otherwise:

```php
'defaultOptions' => [
    'height' => 900,
    'loading' => 'click',
    'download' => false,
    'consentText' => 'This document is rendered by Google. Loading it sends the file to their servers.',
],
```

The precedence, lowest to highest: Book's own defaults, then `defaultOptions`, then the document's
stored options, then the options passed at the call site. **An author's explicit choice always
wins over a viewer's own knowledge** — if someone asks for the Google viewer on a file Book would
have rendered natively, they get Google.

Every key in [Usage](usage) can go in here.
