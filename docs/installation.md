---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting your first document onto a page.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

That is the whole list. Book has no runtime dependencies beyond Craft's own, no build step, and
**makes no outbound HTTP request anywhere** — not at install, not on a page view, not ever.

Book is free. There are no editions, no licence key and no licensing code in the plugin.

## Install

```sh
composer require justinholtweb/craft-book
php craft plugin/install book
```

Or find **Book** in the Plugin Store and install it there.

Installing creates the `book_documents` table and adds a **Book** item to the control panel
navigation, with **Documents** and **Settings** under it. No project config is written until you
change a setting.

## Your first document

1. **Book → Documents → New document.**
2. Choose a **file** — pick a Craft asset, or paste a URL to somebody else's file.
3. Give it a **handle**. The handle is what a reference tag and `craft.book.embed()` look the
   document up by, so pick something you will not want to rename later — `annual-report`, not
   `document-1`.
4. Leave the **viewer** on **Auto**. Book picks the best viewer the format supports that is
   actually usable on this site, and tells you underneath what it picked and what it ruled out.
5. Save.

Then put it on a page, either way round:

```twig
{{ craft.book.embed('annual-report') }}
```

```
{book:annual-report:render}
```

The second one is a Craft reference tag. It renders in CKEditor, in Redactor, and in any other
HTML field, with no template change — Craft parses reference tags in every rich-text value before
it reaches the page.

## Skipping the library

A document does not have to be in the library to be embedded. If the file is already on the entry,
embed it where it is:

```twig
{{ craft.book.embed(entry.brochure) }}          {# an Assets field #}
{{ craft.book.embed('https://example.com/spec.pdf') }}
```

The library exists for documents used in more than one place, for documents authors need to reach
from rich text, and for documents whose options you would rather configure once. Nothing requires
it.

## Letting authors upload the file types

Craft's `allowedFileExtensions` governs what may be uploaded at all, and it does not include every
format Book can render. Book knows its own list:

```twig
{# In an Assets field's "Allowed file types" — or just as a reference #}
{{ craft.book.extensions()|join(', ') }}
```

If an author cannot upload a `.csv` or an `.odt`, that is Craft's setting, not Book's. Extend
`allowedFileExtensions` in `config/general.php`.

## What to check before you go live

- **Private volumes work as they are.** An asset in a volume with no public URLs is served through
  Craft on a signed route. You do not need to make the volume public, and you should not.
- **Staging URLs are not public URLs.** Google's and Microsoft's viewers fetch the file from their
  own servers, so a `.ddev.site` or `.test` address gives them nothing to fetch. Book refuses to
  hand those over and falls back rather than rendering an apology page — see
  [Troubleshooting](troubleshooting).
- **Decide about third parties now.** If Google and Microsoft must never receive a document URL
  from this site, turn both viewers off in the settings before authors start choosing them. See
  [Configuration](configuration).

## Uninstalling

```sh
php craft plugin/uninstall book
```

That drops `book_documents` and the documents in it. Reference tags left in rich text render as
nothing, and `craft.book` calls in templates will throw — remove them first if the content has to
keep serving.
