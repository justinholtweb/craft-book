# Book — Craft CMS 5 Plugin

## Project Overview

Book embeds documents — PDF, Word, Excel, PowerPoint, OpenDocument, RTF, CSV, Markdown, JSON,
text, code, images, audio, video — from a Craft asset or a URL, in Twig or in rich text.
Distributed as `justinholtweb/craft-book`. **Free — no editions, no licensing code.**

Reference point: the WordPress plugin *Embed Any Document*. Same ground, done the Craft way — an
element with a reference tag rather than a shortcode.

Plan in `docs/plan.md`. Family conventions and traps in the shared memory.

`docs/*.md` — everything except `plan.md` — is the source of truth for the marketing site at
`justinholt.com/plugins/craft-book`, pulled by `pluginsite/docs/sync`. Every published page needs
YAML front matter with at least a `title`; `plan.md` has none, which is how it stays off the site.
Plugin Store promo images live in `promos/` (`./build.sh`), not in the website repo.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step anywhere: the CP editor, the picker and the front-end runtime are plain scripts,
  and the CKEditor plugin is an ES module written against the `ckeditor5` import map.
- No runtime dependencies beyond Craft's own. HTML Purifier and Markdown are direct
  `craftcms/cms` requirements — verified, not assumed.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\book`
- Package: `justinholtweb/craft-book`
- Handle: `book`

### The load-bearing idea: reference tags

Same as `[[project_craft_legs]]` and `[[project_craft_eye]]`. `craft\htmlfield\HtmlFieldData`
runs `Elements::parseRefs()` over every rich-text value and splices the result in **raw**, so a
`Document` element with `refHandle() = 'book'` and a `getRender()` returning `Markup` renders
`{book:handle:render}` in CKEditor, Redactor and any HTML field with no template changes.

**The editor integrations are editing affordances only.** They write
`<div class="book-document" data-book-handle="x">{book:x:render}</div>`. Never make rendering
depend on an editor.

### The hard rule: Book never makes an outbound HTTP request

Deliberate, and it shapes everything:

- Third-party viewers are `<iframe src>` values the *reader's browser* loads. Book only writes
  the URL.
- The `inline` viewer reads bytes via `Asset::getContents()`, i.e. the volume's own filesystem.
  An external URL cannot be inlined and says so.

Consequence: no SSRF surface, no host allowlist, no fetch timeouts, no proxy settings — the whole
class of problem `[[project_craft_eye]]` had to fence off does not exist here. Do not add a
server-side fetch to this plugin without revisiting the README, the settings screen and the plan,
all of which state the promise.

### The five viewers

`native` (browser: PDF frame, `<img>`, `<audio>`/`<video>`) · `office`
(view.officeapps.live.com) · `google` (docs.google.com/gview) · `inline` (Book reads the asset and
writes HTML) · `link` (download card, always possible, end of every fallback chain). `auto` is
not a viewer; it is the resolution.

### Services

- `formats` — 18 formats, matched by extension first and MIME type second; `other` is last and
  matched by falling off the end. Each carries an ordered `viewers` list, which *is* the `auto`
  preference order.
- `viewers` — the registry, and `resolve()`, which is the interesting half: format, environment
  and site policy can each rule a viewer out, and the author is told which.
- `delivery` — what URL a viewer is handed, when Book serves the bytes itself, token signing and
  the syntactic public-reachability judgement.
- `inliner` — CSV/Markdown/JSON/text/code → HTML. Purifier on the Markdown path only.
- `documents` — the library and the authority on handles.
- `renderer` — one path for every surface, rendering `book/_render/document` or a site's
  `_book/document.twig`.

### Delivery and access

`Delivery::craftUrl()` mints a token whenever the answer to "may this be served?" is anything but
"yes, to anyone" — a private volume, `access: login`, or `signedUrls` on. The token is
`expires.access.disposition.hmac`, and it is the **only authentic carrier of the access rule**:
`FileController` reads the rule out of the token, never out of a query parameter.

Unsigned requests may only ever reach an asset that already has a public volume URL. That is what
stops Book turning an unguessable UID into a way around volume permissions.

## Traps found while building this

- **A plugin settings template is already namespaced.** `craft\base\Plugin::settingsResponse()`
  runs the template's output through `View::namespaceInputs(…, 'settings')`, so a field written as
  `name="settings[foo]"` posts as `settings[settings][foo]` and saves nothing at all. The screen
  looks perfect, Craft reports "Plugin settings saved", and every edit is silently discarded.
  Field names in `src/templates/settings.twig` carry **no prefix**, and the file says so at the top.
- **`p` is Craft's `pathParam`** and `token` is its preview token, so a signature sent as either
  is read as something else and the route 404s before any controller runs. Book uses `bookref`
  (`Delivery::PARAM`), and a check asserts it is neither.
- **Signing has to force the file onto Book's own route.** `getServesThroughCraft()` originally
  ignored `signedUrls`, so a public asset kept its plain volume URL and the setting did nothing at
  all — the setting was on, the effect was absent, and nothing looked wrong.
- **Every column an element query selects needs a property or a setter.** `book_documents.config`
  has `setConfig()` for exactly this: without it, `UnknownPropertyException` is thrown from
  `ElementQuery::createElement()` — nowhere near the cause, and only when a document is loaded
  *from the database*, so anything re-reading an element it just saved passes happily.
- **`extraAllowedFileExtensions` is a setter, not a property.** Assigning
  `$general->extraAllowedFileExtensions` merges nothing; the list to extend is
  `$general->allowedFileExtensions`. Cost an hour in the checks.
- **Craft's view memoises which templates exist for the life of the request.** The site-override
  check writes `_book/document.twig`, and deleting it again does not un-see it — every later
  render in the same process uses the override. That check runs last, deliberately.
- **Twig 3 has no inline `if` in `{% for %}`.** `{% for x in xs if cond %}` is a syntax error, and
  a syntax error in a settings template is a 500 on the settings screen only, which is easy to
  miss. Use `|filter(x => cond)`.
- **The local path of an asset is `fs->getRootPath() . volume->getSubpath() . asset->getPath()`.**
  Skipping the subpath works on every volume until somebody uses one. `sendFile` on a real path
  is what buys Range support (verified: `206` for `Range: bytes=0-9`); a remote filesystem's
  stream is not seekable, so `fileSize` is passed explicitly to stop Yii seeking to the end.
- **`Controller::requireLogin()` returns void and ends the request** — it cannot be `return`ed
  from an action typed `: Response`.
- **A DOM id derived from a handle is not unique.** The same document twice on one page produced
  the same `id` twice; `Renderer::uniqueId()` suffixes repeats.
- **`fgetcsv`'s default escape character is a backslash**, which RFC 4180 does not have — any cell
  containing a Windows path comes back mangled. Book passes `''`.
- **A `hidden` iframe still loads.** Click-to-load parks the frame in a `<template>`, and a check
  asserts no `<iframe>` appears before it.
- **Handles and the trash** (as in `[[project_craft_legs]]`): a soft-deleted document keeps its
  row and its handle, so `afterDelete()` parks it as `handle--trashed-<id>` and `afterRestore()`
  claims it back; validation asks an element query, which excludes trashed rows.
- **The asset FK is `SET NULL`, not `CASCADE`.** Deleting an asset leaves the document standing
  with a visible "the file is gone" state rather than silently deleting content pages reference.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-book/tests/integration/checks.php   # 98 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-book/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning: every asset they create is prefixed `book-check-`
and swept on the way out, including strays from a run that died half way, and the settings they
change are restored. The prefix is deliberately unmistakable because the sweep runs on a shared
site.

`ddev exec php craft clear-caches/cp-resources` after editing anything under `web/assets/*/dist`,
or Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('book', '…')` for user-facing strings; `src/translations/en/book.php` lists them
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post secondary actions with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- A viewer's own knowledge sits *under* an author's options, never over them
