---
title: GraphQL
slug: graphql
order: 35
summary: bookDocuments, bookDocument and bookDocumentCount — the library over Craft's GraphQL API, with signed file URLs, viewer resolution and rendered HTML, behind one schema permission.
---

Book adds its document library to Craft's GraphQL API, so a headless front end gets what a Twig
template gets from `craft.book`: the facts about each file, the viewer Book would pick and why,
the URLs it would hand that viewer, and the rendered markup.

## The permission

Edit a schema under **GraphQL → Schemas** and tick **Book → Query for documents**
(`bookDocuments.all:read`). A schema without it has no Book queries at all, and a **Documents**
relation field is left out of it too, so the field cannot lead there either.

## Queries

| Query | Returns |
| --- | --- |
| `bookDocuments` | A list of documents |
| `bookDocument` | One document |
| `bookDocumentCount` | How many documents match |

They take Craft's usual element arguments (`id`, `uid`, `site`, `search`, `limit`, `offset`,
`orderBy`, …) plus Book's own: `handle`, `format`, `viewer`, `assetId` and `filename`, the same
criteria `craft.book.all()` takes.

**Only enabled documents.** Disabling a document takes a file off every page at once, and a
headless front end counts as one more page. There is no `status` argument.

```graphql
{
  bookDocument(handle: "annual-report") {
    title
    format
    formatName
    sizeLabel
    fileUrl
    downloadUrl
    html(height: 900, loading: "click")
  }
}
```

## Fields

`BookDocument` implements `BookDocumentInterface`, which has Craft's element fields (`id`,
`title`, `dateUpdated`, …) and these:

| Field | What it is |
| --- | --- |
| `handle` | As used in `{book:handle:render}` |
| `sourceKind` | `asset` for a file in this site, `url` for somebody else's |
| `assetId` | The asset, for a document that is one |
| `sourceUrl` | The URL the author gave, for a document that is not an asset |
| `filename`, `extension`, `mimeType` | About the file |
| `size`, `sizeLabel` | Bytes, and `2.4 MB` |
| `format`, `formatName`, `formatGroup` | `pdf`, `PDF document`, `document` |
| `viewer` | The viewer the author chose, which may be `auto` |
| `access` | `public` or `login` |
| `embedCode` | The reference tag to paste into rich text |
| `fileUrl` | The URL to show the file at |
| `downloadUrl` | The URL a download button should point at |
| `resolution(…)` | What Book would do here — see below |
| `html(…)` | The rendered document |

### `resolution`

The answer `craft.book.resolve()` gives a template, as a `BookViewerResolution`:

| Field | What it is |
| --- | --- |
| `viewer` | The viewer that will be used, never `auto` |
| `requested` | The viewer that was asked for |
| `wasDowngraded` | Whether the requested one could not be used |
| `isThirdParty` | Whether the reader's browser loads it from Google or Microsoft |
| `documentUrl` | The file itself, as handed to the viewer |
| `frameUrl` | What an `<iframe src>` should be, or null |
| `downloadUrl` | The download URL |
| `warnings`, `fallbackReason` | The explanations an author sees. **Empty unless dev mode is on**, the same rule as on the page, so a production API doesn't describe your settings and staging hosts to whoever holds a token |

A front end that wants to write its own markup reads `resolution { viewer frameUrl downloadUrl }`
and builds its own frame.

### `html`

The rendered document, exactly as `{book:handle:render}` would write it: your
`templates/_book/document.twig` override applies here too. Null for a disabled document.

The markup expects Book's stylesheet, and its runtime for click-to-load, the toolbar buttons and
the load timeout. A headless front end has no Craft page to register them on, so ship them
yourself: they are `vendor/justinholtweb/craft-book/src/web/assets/runtime/dist/book.css` and
`book.js`, with no dependencies. Without the runtime a document still shows its frame.

## Per-call options

`html` and `resolution` take the options a reference tag takes, typed or as a string:

```graphql
{
  bookDocument(handle: "price-list") {
    typed: html(viewer: "google", height: 900, toolbar: false)
    tag: html(options: "google,height=900,no-toolbar")
    json: html(options: "{\"viewer\":\"google\",\"height\":900}")
  }
}
```

Typed arguments are `viewer`, `fallback`, `height`, `ratio`, `width`, `align`, `loading`,
`toolbar`, `download`, `page` and `title`. `options` takes anything else, and typed arguments
win when both are given.

**How the file is delivered is not an option here.** A template is written by the site, but a
GraphQL query is written by whoever holds the token, so `access` and `serveThroughCraft` are
ignored wherever they appear. A query cannot turn a login-gated document into a public link.

## Signed URLs

Every URL is minted while the query runs, by the same code a template uses, so it follows the
same rules: a private-volume file gets a token, a login-gated document gets a token that still
asks for a login when it is followed, and with **Sign file URLs** on every asset URL is signed
with the configured lifetime.

Craft caches GraphQL responses. When a response carries a URL that expires, Book shortens that
cache entry to half the signature's lifetime, so a cached response never hands out a link that is
about to stop working. The same applies to a `{% cache %}` block in Twig.

## Login-gated documents

A document set to `access: login` is fetched by readers who are logged in to *this* site. A
GraphQL request with only a token has nobody logged in, so:

- `fileUrl` and `downloadUrl` send a guest to the login screen when followed.
- `html` never writes a login-gated file's contents into the markup for a guest. An inline CSV or
  Markdown file shows the download card instead.

## Documents fields

A **Documents** relation field returns `[BookDocumentInterface]` and takes the same arguments as
`bookDocuments`, in any schema that has the permission. The **Document** field (a file configured
on the entry itself) is unchanged: Craft exposes it as a plain `String`.
