# Changelog

## 5.0.0 — 2026-08-23

Initial release.

### Added

- **Document element** with reference tags — `{book:annual-report:render}` renders a document
  inside CKEditor, Redactor or any other HTML field, with no template changes. Options travel
  in the tag: `{book:annual-report:render(google,height=900)}`.
- **Five viewers.** The browser's own (PDF, images, audio, video), Google Docs Viewer, the
  Microsoft Office Web Viewer, Book's own server-side rendering for text-shaped formats, and a
  download card that is always available and is where everything else falls back to.
- **Viewer resolution with reasons.** `auto` picks the best viewer that actually works for the
  format, in this environment, under this site's settings — and says what it ruled out and why,
  in the control panel before publishing rather than on the live page after.
- **Inline rendering** of CSV and TSV (as a table, with delimiter sniffing and encoding
  repair), Markdown (purified), JSON (pretty-printed), and text, code and data files.
- **Private assets.** A file on a volume with no public URLs is served through Craft on a
  signed route, so the browser's PDF viewer and Book's own rendering work without making the
  volume public. Optional expiring signatures and a logged-in-users-only access rule.
- **Two field types** — `Document` for a file configured on the entry, `Documents` for a
  relation to the library.
- **Twig API** — `craft.book.embed()`, `.document()`, `.render()`, `.asset()`, `.url()`,
  `.all()`, `.resolve()`, `.downloadUrl()`, `.format()`, `.formats()`, `.viewers()`,
  `.extensions()`, `.embedCode()`, `.options()`, plus a `book` filter and function.
- **Front-end runtime** — lazy loading, click-to-load consent for third-party viewers, a load
  timeout that turns a permanently blank frame into the file itself, fullscreen and print.
- **Editor integrations** — a CKEditor toolbar button and plugin, a Redactor plugin, a shared
  picker, and paste-a-file-URL-to-embed. HTML Purifier is taught about Book's data attributes.
- 18 formats recognised by extension and MIME type, with per-format viewer preferences.
