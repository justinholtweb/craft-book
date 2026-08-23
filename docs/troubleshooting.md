---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Blank viewers, documents that work locally and not on staging, private files that 403, and the reference tag that renders as text.
---

## Start here: ask Book what it is doing

Almost every question on this page is answered faster by `resolve()` than by guessing:

```twig
{{ dump(craft.book.resolve(entry.spec)) }}
```

It returns the viewer Book chose, the viewers it ruled out and why, the URL it would hand over,
and any warnings. The control panel shows the same verdict under the file on a document's edit
screen. **If a document is not doing what you expect, the reason is almost always already written
down there.**

With `devMode` on, the verdict is also printed on the page itself, under the document.

## A grey box, or a viewer full of apology, on staging

**Cause:** Google and Microsoft render your file by downloading it *from their own servers*. A URL
on `.ddev.site`, `.test`, `.local`, `localhost` or a private-network address is not reachable from
theirs, so there is nothing for them to fetch.

**What Book does:** with `checkPublicUrl` on — the default — it will not hand those hosts over. It
falls back to a viewer that works, and says on the edit screen that it did.

**If you are seeing the apology page anyway,** `checkPublicUrl` has been turned off, or the viewer
was chosen explicitly with `fallback` left on. Turn the check back on. It is the single most
common way a document embed fails, and it fails only in the place where nobody is watching.

**This is not a bug to fix before launch.** The same document works the moment the site is on a
public domain.

## The document renders locally but not in production

Work through these in order:

1. **Is the viewer allowed?** `allowGoogleViewer` or `allowOfficeViewer` may be off in production's
   `config/book.php` and on locally.
2. **Is the asset's volume the same?** A local volume with public URLs and a production volume
   without will resolve to different viewers, correctly.
3. **Is `signedUrls` on with a short `signedUrlDuration`?** See the next section.
4. **Has the file moved?** The asset foreign key is `SET NULL`, not `CASCADE` — deleting an asset
   leaves the document standing and showing "the file is gone" rather than silently deleting
   content your pages reference. That state is visible on the document's index row.

## Embeds that worked yesterday and are blank today

**Cause:** `signedUrls` is on, and the viewer is `google` or `office`.

Those two fetch the file when the *reader* opens the page, which can be any time after the page
was rendered — and a signature that has expired by then takes the embed with it. Book warns about
this combination on the edit screen; this is what the warning meant.

**Fixes, in order of preference:**

- Use `native` or `inline` for those documents. The reader's own browser fetches inside the
  signature's lifetime, so signing is harmless there.
- Raise `signedUrlDuration`, or set it to `0` for no expiry.
- Turn `signedUrls` off and rely on the volume's own privacy, which is what actually governs
  access.

## A private document 403s

Book's file route refuses anything it did not sign. Some causes:

- **The signature was stripped.** A CDN, a cache or a redirect that drops query strings takes the
  `bookref` parameter with it, and an unsigned request to a private file is refused by design.
- **The token expired.** See above.
- **The document is `access: 'login'` and the reader is a guest.** They are redirected to the login
  screen, not 403'd — if you are seeing a 403 instead, the request was made by something that does
  not follow redirects, such as a third-party viewer. Those cannot see a login-gated file at all,
  and Book will not offer them for one.

The parameter is `bookref` on purpose and must not be renamed: `p` is Craft's own `pathParam` and
`token` is its preview token, so a signature sent as either is read as something else entirely and
the route 404s before Book's controller ever runs.

## `{book:handle:render}` renders as literal text

**Cause:** the field is not one Craft parses reference tags in.

Craft parses reference tags in rich-text values — CKEditor, Redactor, any HTML field. It does
**not** parse them in a plain text field, a table cell, or a string you built yourself in Twig. For
those, call `craft.book.embed('handle')` instead.

If it is a rich-text field and the tag still shows: check the handle actually exists
(`craft.book.document('handle')` returns `null` if not), and check the document is not in the
trash. A soft-deleted document keeps its row but its handle is parked as
`handle--trashed-<id>`, so the tag no longer matches. Restore it and the handle comes back.

## Two copies of the same document on one page fight each other

Fixed, but worth knowing why: a DOM id derived from a handle is not unique, so the same document
twice produced the same `id` twice and the runtime wired both toolbars to the first frame. Book
now suffixes repeats. If you are setting `id` yourself on both copies, set two different ones.

## A CSV renders with mangled cells

**Windows paths in cells** — `C:\Users\…` — used to come back broken because PHP's `fgetcsv`
defaults to a backslash escape character that RFC 4180 does not have. Book passes an empty escape
character, so this is handled. If cells still look wrong:

- **The delimiter was sniffed wrong.** A file with more semicolons than commas in its first rows
  is read as semicolon-delimited. Save it as a true CSV or a TSV.
- **The encoding is not UTF-8.** Windows-1252 is repaired and a BOM is stripped; other encodings
  are not converted.
- **The file was truncated.** `maxRows` defaults to 500 and Book says so at the bottom of the
  table rather than pretending that was the whole file. Raise it per document.

## "This file is too large to render inline"

`inlineMaxBytes` defaults to 1 MB, because inline rendering reads the whole file into PHP. Raise it
in the settings if you mean it, and remember it is per page view.

## A Markdown file lost its HTML

Markdown allows raw HTML by design, and these files come off an upload volume, so Book runs the
Markdown path through HTML Purifier before it reaches the page. If you need something it strips,
add a Purifier config: `config/htmlpurifier/<name>.json`, then set `purifierConfig` to `<name>`.

CSV, JSON, text and code are escaped rather than purified, so nothing is stripped from those —
they simply cannot contain markup that matters.

## Click-to-load loads anyway

Check `registerJs`. With the runtime switched off, `loading: 'click'` has nothing to defer with and
the frame renders directly. Either turn the runtime back on or change the option — a document
should not silently be sending your file to Google when the author asked for consent first.

## The document has no toolbar, no fullscreen and no click-to-load

Same cause: `registerJs` is off, or the page never got Book's assets. The frame itself still
renders — the runtime adds behaviour, not markup.

## After editing the control panel assets

Craft serves a published copy of anything under `web/assets/*/dist`:

```sh
php craft clear-caches/cp-resources
```

## Still stuck

Turn `devMode` on and load the page. The verdict panel prints the chosen viewer, every rejected
one and the reason for each, directly under the document.
