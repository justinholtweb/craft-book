---
title: FAQ
slug: faq
order: 50
summary: Cost, privacy, what leaves your server, private assets, Embed Any Document, and what happens to your pages if you remove it.
---

## Is Book really free?

Yes. One edition, everything switched on, no licence key and no licensing code in the plugin. All
five viewers, the element, both fields, the Twig API, the editor integrations and private-asset
delivery are simply the plugin.

## Does Book send my documents anywhere?

**Book itself never makes an outbound HTTP request.** Not at install, not on a page view, not ever.
There is no fetcher in the codebase.

What it can do is write a URL into an `<iframe src>` that the **reader's browser** then loads. For
the `google` and `office` viewers, that browser tells Google or Microsoft to go and download your
file so they can render it. Book is the one writing the URL; it is not the one making the request,
and it never sees the file leave.

That distinction matters for the security model — there is no SSRF surface, no host allowlist and
no proxy settings to maintain — but it does not change the privacy consequence. If a third party
must not have your documents, turn `allowGoogleViewer` and `allowOfficeViewer` off. Then Google and
Microsoft never receive a URL from this site, `auto` skips them, and an explicit request for one is
refused with a reason.

## Can I embed a private asset without making the volume public?

Yes, and this is most of the reason the plugin exists. An asset in a volume with no public URLs is
served through Craft on Book's own route, with an HMAC-signed token Book minted. The token carries
the access rule, so it cannot be edited into permission it was not granted, and an unsigned request
only ever reaches a file that is already published at its own volume URL.

Set `access: 'login'` on the document and a guest is sent to the login screen instead.

The one thing a private document cannot do is use Google's or Microsoft's viewer, because those
render by downloading the file from their own servers and there is no way to let them in without
letting everybody in. Book will not offer them for a login-gated file. Use `native` for PDFs and
images, and `inline` for CSV, Markdown, JSON, text and code — all of which work perfectly on a
private volume.

## What renders a `.docx`?

Microsoft's viewer, or Google's. No browser renders Word natively and Book does no conversion, so
those are the only two options that are not a download link.

Converting on your own server means LibreOffice or Gotenberg — a very different dependency
footprint and deliberately out of scope. If you cannot use a third-party viewer and cannot install
a converter, export to PDF; `native` renders that with nobody else involved.

## What does `auto` actually pick?

The first viewer the format supports that is usable on this site. Each of the eighteen formats
carries its own ordered preference: a PDF tries `native`, then `google`, then `link`; a `.docx`
tries `office`, then `google`, then `link`; a CSV tries `inline` first. "Usable" means allowed by
your settings, publicly reachable if a third party has to fetch it, and readable by Book if Book is
doing the reading.

If it cannot use the one you would expect, it says so — on the edit screen, in
`craft.book.resolve()`, and on the page under `devMode`. Set `fallback: false` if you would rather
an impossible choice became a download card than quietly became a different viewer.

## Do I have to change my templates?

Only if you want to. A document in the library has a handle, and `{book:handle:render}` renders in
CKEditor, in Redactor and in any other HTML field, because Craft parses reference tags in every
rich-text value before it reaches the page. Nothing in a template needs to know.

For documents outside rich text, one Twig call does it: `{{ craft.book.embed(entry.brochure) }}`.

## Does the CKEditor button have to be installed for documents to render?

No, and this is deliberate. The CKEditor toolbar button, the Redactor plugin and the shared picker
are **editing affordances** — they help an author write a reference tag. Rendering is Craft's
reference-tag parsing, which happens with or without them. Set `richTextIntegration` to `false` and
every existing document keeps rendering exactly as before.

## Will it slow my site down?

A document rendered with `native`, `google`, `office` or `link` costs an element lookup and some
markup; the file itself is fetched by the reader's browser, lazily by default.

`inline` is the one to think about: Book reads the whole file into PHP to render it, capped by
`inlineMaxBytes` at 1 MB. That is fine for a price list and wrong for a 400 MB log, which is what
the cap is for.

Serving files through Craft — automatic for private volumes, optional for public ones — puts bytes
through PHP rather than the web server. Range requests are supported, so seeking in a video still
works.

## How is this different from just linking to the PDF?

A link makes the reader leave the page to find out whether the document was worth opening. Four
things go wrong when you put a document on a page, and Book exists for all four: a PDF ends up as
a link; a `.docx` cannot be shown by any browser; a private asset has no URL to give a viewer; and
the interesting formats — CSV, Markdown, JSON — need no viewer at all, just a server willing to
read them and write a table.

## How does it compare to Embed Any Document?

Embed Any Document is the WordPress plugin Book is pitched against, and it covers the same ground.
The differences are the ones you would expect from doing it the Craft way:

- An **element with a reference tag**, not a shortcode — so it renders in any rich-text field, and
  the same document is one thing to keep in step rather than one copy per page.
- **Assets are first-class.** Private volumes work without being made public.
- A **Twig API that is the point**, not an afterthought — including `resolve()`, which tells you
  what will happen before you publish.
- **Inline rendering** for the text formats, with no third party involved at all.

## Does it work on a multi-site install?

Yes. Documents are elements and behave like elements. The delivery route and the token are
site-agnostic; access is governed by the volume and the document's own `access` setting.

## Which versions are supported?

Craft CMS 5.3+, PHP 8.2+. No runtime dependencies beyond Craft's own, and no build step — the
control panel editor, the picker and the front-end runtime are plain scripts, and the CKEditor
plugin is an ES module written against the `ckeditor5` import map.

## What happens to my pages if I remove it?

Uninstalling drops the `book_documents` table and the documents in it. Reference tags left in rich
text render as nothing, and `craft.book` calls in templates will throw. If the content has to keep
serving, replace the embeds first.

## Can I change how a document looks?

Yes, two ways. The stylesheet is small and expressed entirely as custom properties on `.book`, so
four variables usually do it. For the markup itself, put your own template at
`templates/_book/document.twig` — it wins everywhere at once, because every surface renders through
that one template. Both the stylesheet and the runtime can be switched off if you would rather ship
your own.
