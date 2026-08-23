# Book

**Embed any document in Craft CMS.** PDFs, Word, Excel, PowerPoint, OpenDocument, RTF, CSV,
Markdown, JSON, text, code, images, audio and video — from a Craft asset or a URL, in a Twig
template or in rich text.

Reference point: the WordPress plugin *Embed Any Document*. Same ground, done the Craft way — an
element with a reference tag rather than a shortcode, assets as first-class sources, and a Twig
API that is the point rather than an afterthought.

Free. No editions, no licence key.

---

## The problem

Four things go wrong when you put a document on a page, and Book exists for all four.

**A PDF ends up as a link.** "Download our brochure (PDF, 4 MB)" is not an embed, and the reader
has to leave the page to find out whether it was worth downloading.

**A `.docx` cannot be shown by any browser.** Somebody has to render it, which in practice means
Google or Microsoft — which means the file's URL leaves your site, usually without the reader
being asked.

**Private documents have no URL at all.** An asset in a volume with no public URLs has nothing to
hand a viewer, so the usual answer is "make the volume public". That is the wrong answer.

**The interesting formats are text.** A CSV, a Markdown file, a JSON export — these need no viewer
at all. They need a server willing to read them and write a table.

---

## Book never makes an outbound HTTP request

That is an architectural promise, not a side effect, and it is worth stating before anything else:

- **Third-party viewers are URLs, not fetches.** Google's and Microsoft's viewers are `<iframe
  src>` values that the *reader's browser* loads. Book only ever writes the URL.
- **Inline rendering reads assets, never URLs.** Book's own renderer reads bytes out of your asset
  volume. An external URL cannot be inlined, and Book says so instead of trying.

So there is no SSRF surface, no host allowlist to maintain, no fetch timeouts and no proxy
settings. The one thing to be aware of is the flip side: a third-party viewer means a third party
downloads your file, and Book is explicit about that everywhere it comes up.

---

## Install

```sh
composer require justinholtweb/craft-book
php craft plugin/install book
```

Craft 5.3+, PHP 8.2+. No runtime dependencies beyond Craft's own.

---

## Twig

The one to reach for takes anything:

```twig
{{ craft.book.embed(entry.brochure) }}          {# an Assets field #}
{{ craft.book.embed('annual-report') }}         {# a library document, by handle #}
{{ craft.book.embed('https://example.com/spec.pdf') }}
```

Or the filter and function, which are the same thing:

```twig
{{ asset|book }}
{{ 'annual-report'|book({ viewer: 'google', height: 900 }) }}
{{ book('annual-report') }}
```

Options are passed as a hash and can override anything the document was configured with:

```twig
{{ craft.book.embed(asset, {
    viewer: 'auto',
    height: 900,
    ratio: '8.5:11',
    loading: 'click',
    toolbar: true,
    download: true,
    caption: 'Annual report, 2026',
}) }}
```

### The rest of `craft.book`

| Call | Gives you |
| --- | --- |
| `craft.book.embed(value, options)` | Markup, from an asset, URL, handle, document or field value |
| `craft.book.document('handle')` | The `Document` element, or `null` |
| `craft.book.render('handle', options)` | Markup for a library document |
| `craft.book.asset(asset, options)` | Markup for an asset with no library entry |
| `craft.book.url('https://…', options)` | Markup for somebody else's file |
| `craft.book.all(criteria)` | A `DocumentQuery` |
| `craft.book.resolve(file, options)` | What *would* happen: viewer, warnings, URLs |
| `craft.book.downloadUrl(file, options)` | The URL a download button would use |
| `craft.book.format(file)` | The `Format` Book recognises |
| `craft.book.formats()` / `.viewers()` | The registries |
| `craft.book.extensions()` | Every extension Book knows, for an Assets field's allowed types |
| `craft.book.embedCode('handle')` | The reference tag, to paste into rich text |
| `craft.book.options({...})` | A fresh options model |

`craft.book.resolve()` is the interesting one. It answers "what will actually happen here", which
is the same answer the control panel shows an author:

```twig
{% set verdict = craft.book.resolve(entry.spec) %}
{% if verdict.isThirdParty %}
    <p>This document is rendered by a third party.</p>
{% endif %}
{% for warning in verdict.warnings %}<p class="warning">{{ warning }}</p>{% endfor %}
```

---

## Rich text

A document in the library has a handle, and its reference tag renders anywhere Craft parses
reference tags — CKEditor, Redactor, or any other HTML field:

```
{book:annual-report:render}
{book:annual-report:render(google,height=900,no-toolbar)}
```

That is the whole integration. The CKEditor toolbar button, the Redactor plugin and the shared
picker are editing affordances: they help an author write the tag. Turn them all off and existing
documents keep rendering, because rendering was never the editor's job.

Pasting a link to a file (`…/report.pdf`) on its own line adds it to the library and inserts its
tag, deduplicating by file so ten pastes make one library entry.

---

## The five viewers

| Viewer | Renders | Third party | Needs a public URL |
| --- | --- | --- | --- |
| `native` | PDF, images, audio, video | No | No |
| `office` | Word, Excel, PowerPoint | Microsoft | Yes |
| `google` | Nearly everything | Google | Yes |
| `inline` | CSV, Markdown, JSON, text, code | No | No |
| `link` | Anything, as a download card | No | No |

`auto` — the default — picks the first viewer the format supports that is actually usable *here*:
allowed by your settings, reachable by whoever has to fetch it, and readable if Book is doing the
reading. It will not hand a `.ddev.site` or `.test` URL to Google, because the result is a viewer
box full of apology, and that is the single most common way a document embed fails on staging.

When `auto` cannot use a richer viewer, or when your explicit choice cannot be honoured, Book says
why — on the edit screen before publishing, in `craft.book.resolve()`, and on the page itself when
`devMode` is on. Set `fallback: false` to make an impossible choice become a download card rather
than quietly becoming a different viewer.

### Inline rendering

The formats that are secretly text get rendered by Craft itself, with nobody else involved:

- **CSV / TSV** → a table. Delimiter sniffed, quoted commas respected, Windows-1252 repaired, BOM
  stripped, row limit honoured and admitted to.
- **Markdown** → prose, run through HTML Purifier — Markdown allows raw HTML by design and these
  files come off an upload volume.
- **JSON** → pretty-printed.
- **Text, logs, code, XML, YAML** → an escaped, scrollable block.

This works for private assets, costs nothing at the network layer, and is usually better than what
a document viewer would have produced.

---

## Private documents

An asset in a volume with no public URLs is served through Craft, on a route Book mints a signed
token for. That is what makes the browser's own PDF viewer and Book's inline rendering work
without making the volume public.

The rule the route rests on: **a file with no public volume URL is never served without a token
Book itself minted.** The token carries the access rule, HMAC-signed, so it cannot be edited into
something it was not signed for. An unsigned request only ever reaches a file that is already
published at its own volume URL, where serving it changes nothing.

Per document:

- `access: 'public'` — anyone with the link (the link is an unguessable UID)
- `access: 'login'` — a logged-in user; guests are sent to the login screen

Site-wide, in the settings:

- **Serve assets through Craft** — force every file onto Book's route, even public ones
- **Sign file URLs** — add an expiring signature to them

One consequence worth knowing before you turn signing on: Google and Microsoft fetch the file from
their own servers, so a signature that expires takes those embeds with it. Book warns about that
combination rather than letting you find out later.

---

## Fields

**Document** — a file configured where it is used. Pick an asset or paste a URL, and choose from
whichever options the field's settings expose. `{{ entry.brochure }}` renders it.

**Documents** — a plain relation field to library documents. Everything you expect from a relation
field works, because it is one.

---

## Customising the markup

Every surface renders through one template. Put your own at `templates/_book/document.twig` and it
wins, with the same variables Book's own gets: `id`, `label`, `source`, `format`, `options`,
`viewer`, `resolution`, `classes`, `stageStyle`, `frameAttributes`, `config`, `consent`, `card`,
`inlineHtml`, `texts` and the `document` element when there is one.

The stylesheet is small and expressed entirely as custom properties on `.book`:

```css
.book {
    --book-border: #d9dee5;
    --book-surface: #f6f7f9;
    --book-accent: #2a6df4;
    --book-radius: 6px;
}
```

Both the stylesheet and the runtime can be switched off in the settings if you would rather ship
your own. A document with no runtime still shows its frame; what you lose is click-to-load, the
load timeout, fullscreen and print.

---

## The front-end runtime

Four jobs, no dependencies, ~5 KB:

1. **Click-to-load.** The frame is parked in a `<template>`, which is the only markup that
   genuinely defers the request — a `hidden` iframe still loads, which is the mistake most consent
   banners make.
2. **A load timeout.** A viewer that cannot reach your file answers `200 OK` with an apology page,
   and a blocked frame says nothing to its parent at all. Past the deadline, Book shows the file
   itself instead of a blank box.
3. **Fullscreen**, on the stage rather than the frame.
4. **Print**, which prints the document for a same-origin frame and the page otherwise.

---

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| `allowGoogleViewer` | `true` | Whether Google's viewer may be used at all |
| `allowOfficeViewer` | `true` | The same, for Microsoft's |
| `checkPublicUrl` | `true` | Refuse to hand an unreachable URL to a viewer that fetches it |
| `serveAssetsThroughCraft` | `false` | Route every asset through Book |
| `signedUrls` | `false` | Sign Book's file URLs |
| `signedUrlDuration` | `86400` | How long a signature lasts, in seconds; 0 never expires |
| `inlineMaxBytes` | `1048576` | The most Book will read to render a file itself |
| `purifierConfig` | `null` | An HTML Purifier config for Markdown rendering |
| `registerCss` / `registerJs` | `true` | Whether Book ships its own front-end assets |
| `richTextIntegration` | `true` | The CKEditor and Redactor buttons |
| `defaultOptions` | `[]` | Defaults for every new document |

---

## Permissions

`book:viewDocuments`, with `book:manageDocuments` and `book:deleteDocuments` nested under it.

---

## Not in scope

- **Server-side conversion.** Rendering a `.docx` to HTML on your own server means LibreOffice or
  Gotenberg, which is a different plugin with a very different dependency footprint.
- **Fetching external URLs.** See the promise at the top.
- **Full-text extraction** of document contents for search.

---

## Licence

The Craft License. See `LICENSE.md`. Book is free: no editions, no licence key, and no licensing
code in the plugin.
