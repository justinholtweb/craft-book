# Book — plan

## What it is

A document embedding plugin for Craft CMS 5. Point it at a Craft asset or a URL — PDF, Word,
Excel, PowerPoint, OpenDocument, RTF, CSV, Markdown, JSON, plain text, code, images, audio,
video — and it puts the thing on the page.

Reference point: the WordPress plugin *Embed Any Document*. Same ground, done the Craft way: an
element with a reference tag rather than a shortcode, Craft assets as first-class sources, and a
Twig API that is the primary interface rather than an afterthought.

Free. No editions, no licensing code.

## The problems it exists for

1. **A PDF in a CMS ends up as a link.** "Download our brochure (PDF, 4 MB)" is not an embed.
2. **A `.docx` cannot be shown by any browser.** Somebody has to render it, which means Google
   or Microsoft, which means the URL leaves your site — usually without the reader being asked.
3. **Private documents have no URL at all.** An asset in a non-public volume cannot be handed to
   a viewer, so the usual answer is "make the volume public", which is the wrong answer.
4. **The interesting formats are text.** A CSV, a Markdown file, a JSON export — these need no
   viewer at all, just a server that reads them and writes a table.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\book`
- Package: `justinholtweb/craft-book`
- Handle: `book`

### The load-bearing idea: reference tags

Same as `craft-legs` and `craft-eye`. `craft\htmlfield\HtmlFieldData::__construct()` runs
`Elements::parseRefs()` over every rich-text value and splices the result in **raw**, so a
`Document` element with `refHandle() = 'book'` and a `getRender()` returning `Markup` renders
`{book:handle:render}` in CKEditor, Redactor and any HTML field with no template changes.

The editor integrations are editing affordances only. They write
`<div class="book-document" data-book-handle="x">{book:x:render}</div>`.

### The hard rule: Book never makes an outbound HTTP request

Nothing in this plugin opens a connection to the internet. That is a deliberate architectural
promise and it shapes everything:

- **Third-party viewers are URLs, not fetches.** Google and Office viewers are `<iframe src>`
  values the *reader's browser* loads. Book only ever writes the URL.
- **Inline rendering reads assets, never URLs.** The `inline` viewer reads bytes out of your own
  asset volume via `Asset::getContents()`. An external URL cannot be inlined, and says so.
- Consequence: no SSRF surface, no host allowlist, no fetch timeouts, no proxy settings. The
  whole class of problems `craft-eye` had to fence off does not exist here.

### Sources

A `Source` is what a document points at, resolved: an asset, or a URL. It knows the filename,
the extension, the MIME type, the size, whether it has a public URL, and what format it is.

### Formats

A registry (`formats` service) of ~18 formats, each with extensions, MIME types, a group, an
icon, and the ordered list of viewers that can show it. `other` is last and catches everything.

### Viewers

- `native` — the browser does it. PDF and images in an `<iframe>`/`<img>`, audio and video in a
  media element. No third party, works for private assets.
- `google` — `docs.google.com/gview`. Renders nearly everything, needs a **publicly reachable
  URL**, and sends that URL to Google.
- `office` — `view.officeapps.live.com`. Better fidelity for Office formats, same two caveats.
- `inline` — Book reads the file and writes HTML: CSV to a table, Markdown to prose, JSON/code
  to a highlighted block. No third party, works for private assets.
- `link` — the download card. The end of every fallback chain, and never a failure.
- `auto` — the default: the first viewer the format supports that is actually usable here.

`auto` will not pick a third-party viewer for a URL that is not publicly reachable, because the
result is a viewer box that says "Sorry, we are unable to retrieve this document" — and that is
the single most common way a document embed fails on a staging site.

### Delivery

`Delivery` decides what URL a viewer is handed:

- A public asset with `serveThroughCraft` off gets its own volume URL.
- Everything else gets `book/file/<uid>/<filename>` — a site route that streams the file through
  Craft, so a **private volume works** with `native` and `inline`, and `Content-Disposition`
  becomes a per-document decision rather than a volume-wide one.
- Optionally signed (HMAC over uid + expiry + disposition) with an expiry. The signature travels
  in `bookref`, never `p` or `token`, both of which Craft has already claimed.
- `access: login` requires a logged-in user; `public` does not.

### Services

`documents` (the library and the authority on handles) · `formats` · `viewers` (resolution and
the warnings that come with it) · `delivery` · `inliner` · `renderer`.

## Scope

- `Document` element + CP index and edit screen + settings screen
- `DocumentField` (a document configured where it is used) and `DocumentsField` (a relation)
- CKEditor, Redactor and HTML Purifier integrations
- Twig: `craft.book.*`, plus a `book` filter and function
- Front-end runtime: lazy loading, click-to-load consent for third-party viewers, a load
  timeout that turns a permanently blank frame into a link card, a toolbar, fullscreen
- Integration checks in `tests/integration/checks.php`

## Out of scope

- Server-side conversion (LibreOffice, Gotenberg). Rendering a `.docx` to HTML on the server is
  a different plugin with a different dependency footprint.
- Fetching external URLs. See the hard rule above.
- Full-text extraction and search indexing of document contents.
