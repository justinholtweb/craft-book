---
title: Usage
slug: usage
order: 30
summary: Twig, reference tags, the five viewers, every option, the two fields, private documents and your own template.
---

## Twig

One call takes anything:

```twig
{{ craft.book.embed(entry.brochure) }}          {# an Assets field #}
{{ craft.book.embed('annual-report') }}         {# a library document, by handle #}
{{ craft.book.embed('https://example.com/spec.pdf') }}
```

The filter and the function are the same thing in different clothes:

```twig
{{ asset|book }}
{{ 'annual-report'|book({ viewer: 'google', height: 900 }) }}
{{ book('annual-report') }}
```

Options are a hash, and they override whatever the document was configured with:

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

`craft.book.all()` is an element query like any other:

```twig
{% for document in craft.book.all({ limit: 10, orderBy: 'dateCreated desc' }) %}
    <li>{{ document.title }} — {{ document.getFormat().name }}</li>
{% endfor %}
```

### Asking what will happen

`craft.book.resolve()` answers "what will this actually do here", which is the same answer the
control panel shows an author on the edit screen:

```twig
{% set verdict = craft.book.resolve(entry.spec) %}

{% if verdict.isThirdParty %}
    <p>This document is rendered by {{ verdict.viewer.name }}, who will download it.</p>
{% endif %}

{% for warning in verdict.warnings %}
    <p class="warning">{{ warning }}</p>
{% endfor %}
```

This is the plugin's distinguishing idea and it is worth using. The usual way a document embed
fails is a blank grey box on staging with nothing explaining it; `resolve()` is that explanation,
available before you publish.

## Rich text

A library document has a handle, and its reference tag renders anywhere Craft parses reference
tags — CKEditor, Redactor, or any other HTML field:

```
{book:annual-report:render}
{book:annual-report:render(google,height=900,no-toolbar)}
```

That is the whole integration. The CKEditor toolbar button, the Redactor plugin and the shared
picker exist to help an author write that tag. Switch all three off and existing documents keep
rendering, because rendering was never the editor's job — Craft splices the parsed result into
every rich-text value on its way out.

The parenthesised part is options, comma-separated. `key=value` sets an option; `no-something`
turns a boolean off; and a bare word means what it obviously means — `google` is a viewer, `click`
is a loading strategy, `wide` is an alignment, `16:9` is a ratio, and anything else is a flag
turned on. `{book:brochure:render(click,wide,no-download)}` needs no keys at all.

Pasting a link to a file on its own line — `https://example.com/report.pdf` — adds it to the
library and inserts its tag. It deduplicates by file, so ten pastes of the same URL make one
library entry, not ten.

## The five viewers

| Viewer | Renders | Third party | Needs a public URL |
| --- | --- | --- | --- |
| `native` | PDF, images, audio, video | No | No |
| `office` | Word, Excel, PowerPoint | Microsoft | Yes |
| `google` | Nearly everything | Google | Yes |
| `inline` | CSV, Markdown, JSON, text, code | No | No |
| `link` | Anything, as a download card | No | No |

`auto` is not a sixth viewer. It is the resolution: the first viewer the format supports that is
actually usable *here* — allowed by your settings, reachable by whoever has to fetch it, and
readable if Book is the one reading. Each format carries its own ordered preference, so a PDF
tries `native` before `google` and a `.docx` tries `office` before `google`.

When `auto` cannot use a richer viewer, or when your explicit choice cannot be honoured, Book says
why: on the edit screen before publishing, in `craft.book.resolve()`, and on the page itself when
`devMode` is on. Set `fallback: false` to make an impossible choice become a download card instead
of quietly becoming a different viewer.

### Inline rendering

The formats that are secretly text get rendered by Craft itself, with nobody else involved:

- **CSV / TSV** → a table. Delimiter sniffed, quoted commas respected, Windows-1252 repaired, BOM
  stripped, row limit honoured and admitted to.
- **Markdown** → prose, run through HTML Purifier.
- **JSON, XML, YAML, TOML, INI** → pretty-printed.
- **Text, logs, code** → an escaped, scrollable block.

This works for private assets, costs nothing at the network layer, and is usually better than what
a document viewer would have produced. It reads bytes through the volume's own filesystem, so it
cannot be used on an external URL — Book says so rather than trying.

### Formats Book knows

Eighteen of them, matched by extension first and MIME type second: PDF, Word, Excel, PowerPoint,
OpenDocument, RTF, Apple iWork, CSV/TSV, Markdown, text, data (JSON, XML, YAML…), source code,
images, video, audio, e-books, archives, and everything else. The last one is matched by falling
off the end of the list, and gets a download card.

## Options

Every option can be set on the document, in `defaultOptions`, in a reference tag, or at the Twig
call site.

| Option | Default | What it does |
| --- | --- | --- |
| `viewer` | `auto` | `auto`, `native`, `office`, `google`, `inline`, `link` |
| `fallback` | `true` | Whether an impossible viewer may become a different one |
| `height` | `720` | Frame height in pixels |
| `ratio` | — | An aspect ratio instead of a height — `16:9`, `8.5:11` |
| `width` | `100%` | Frame width |
| `align` | `center` | `left`, `center`, `right`, `wide`, `full` |
| `className` | — | Extra classes on the wrapper |
| `id` | — | A DOM id; Book suffixes repeats so two copies never collide |
| `toolbar` | `true` | The document toolbar |
| `download` | `true` | A download button |
| `open` | `true` | An open-in-new-tab button |
| `print` | `false` | A print button |
| `fullscreen` | `true` | A fullscreen button |
| `title` | — | Overrides the label |
| `caption` | — | A caption under the frame |
| `showMeta` | `true` | Format and file size next to the label |
| `loading` | `lazy` | `eager`, `lazy` or `click` |
| `rootMargin` | `200px` | How early lazy loading starts |
| `showLoader` | `true` | The loading indicator |
| `timeout` | `12000` | Milliseconds before Book gives up on a frame |
| `consentTitle` / `consentText` / `consentButtonLabel` | — | The click-to-load card's wording |
| `posterUrl` | — | A poster behind the click-to-load card |
| `rememberConsent` | `false` | Remember a click-to-load decision |
| `page` | `0` | Open a PDF at a page |
| `zoom` | — | A PDF zoom level |
| `pdfToolbar` | `true` | The browser's own PDF toolbar |
| `csvHeader` | `true` | Treat the first CSV row as headings |
| `maxRows` | `500` | Rows to render from a CSV |
| `wrap` | `false` | Wrap long lines in a text or code block |
| `access` | `public` | `public` or `login` |
| `serveThroughCraft` | — | Override the site setting for this document |

`loading: 'click'` is worth singling out. The frame is parked in a `<template>` and only written
into the page when the reader clicks — which is the only markup that genuinely defers the request.
A `hidden` iframe still loads, which is the mistake most consent banners make. If a third party is
going to download your file, this is how you ask first.

## Fields

**Document** — a file configured where it is used. Pick an asset or paste a URL, and choose from
whichever options the field's settings expose. `{{ entry.brochure }}` renders it; no `craft.book`
call needed.

**Documents** — a plain relation field to library documents. Everything you expect from a relation
field works, because it is one.

## Private documents

An asset in a volume with no public URLs is served through Craft on a route Book mints a signed
token for. That is what makes the browser's own PDF viewer and Book's inline rendering work
without making the volume public.

The rule the route rests on: **a file with no public volume URL is never served without a token
Book itself minted.** The token carries the access rule, HMAC-signed, so it cannot be edited into
something it was not signed for — the controller reads the rule out of the token, never out of a
query parameter. An unsigned request only ever reaches a file already published at its own volume
URL, where serving it changes nothing.

Per document:

- `access: 'public'` — anyone with the link, and the link is an unguessable UID
- `access: 'login'` — a logged-in user; guests are sent to the login screen

Site-wide, see [Configuration](configuration).

Note the interaction with third-party viewers: `google` and `office` fetch from their own servers,
so they cannot see a login-gated file at all, and Book will not offer them for one.

## Your own template

Every surface — Twig, reference tag, field, control panel preview — renders through one template.
Put your own at `templates/_book/document.twig` and it wins, with the same variables Book's own
gets:

`id`, `label`, `source`, `format`, `options`, `viewer`, `resolution`, `classes`, `stageStyle`,
`frameAttributes`, `config`, `consent`, `card`, `inlineHtml`, `texts`, and `document` when there
is one.

Because it is one template, an override applies everywhere at once — there is no second place a
document renders from.

## The front-end runtime

Four jobs, no dependencies, about 5 KB:

1. **Click-to-load**, as described above.
2. **A load timeout.** A viewer that cannot reach your file answers `200 OK` with an apology page,
   and a cross-origin frame says nothing to its parent either way. Past the deadline Book stops
   waiting and shows the file itself instead of a blank box.
3. **Fullscreen**, on the stage rather than the frame, so the toolbar comes too.
4. **Print**, which prints the document for a same-origin frame and the page otherwise.

## Permissions

`book:viewDocuments`, with `book:manageDocuments` and `book:deleteDocuments` nested under it.
