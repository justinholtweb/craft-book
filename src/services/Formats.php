<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\Viewer;

/**
 * The format registry: what Book knows how to recognise, and who can show each one.
 *
 * Order matters only for `other`, which is last and matched by falling off the end. Everything
 * else is looked up by extension first and MIME type second — the extension is what an author
 * can see, and the MIME type is what is true when a URL has no extension at all.
 */
class Formats extends Component
{
    /** @var array<string, Format>|null */
    private ?array $formats = null;

    /** @var array<string, string>|null extension → format handle */
    private ?array $byExtension = null;

    /** @var array<string, string>|null MIME type → format handle */
    private ?array $byMime = null;

    /** @return array<string, Format> */
    public function getAll(): array
    {
        return $this->formats ??= $this->define();
    }

    public function getByHandle(?string $handle): ?Format
    {
        return $handle ? ($this->getAll()[$handle] ?? null) : null;
    }

    /** The `other` format — never null, so callers never have to check. */
    public function getFallback(): Format
    {
        return $this->getAll()['other'];
    }

    /**
     * What Book makes of a file.
     *
     * A `.pdf` served as `application/octet-stream` is still a PDF, so the extension wins; a URL
     * ending in `/download` with a MIME type is the case the MIME lookup exists for.
     */
    public function match(?string $extension, ?string $mimeType = null): Format
    {
        $this->getAll();

        $extension = strtolower(trim((string)$extension, ". \t\n\r\0\x0B"));

        if ($extension !== '' && isset($this->byExtension[$extension])) {
            return $this->getAll()[$this->byExtension[$extension]];
        }

        $mimeType = strtolower(trim(explode(';', (string)$mimeType)[0]));

        if ($mimeType !== '' && isset($this->byMime[$mimeType])) {
            return $this->getAll()[$this->byMime[$mimeType]];
        }

        // A MIME type Book has never seen still says which family it is in, and `text/anything`
        // is safe to show as text — that is what the type means.
        if (str_starts_with($mimeType, 'text/')) {
            return $this->getAll()['text'];
        }

        if (str_starts_with($mimeType, 'image/')) {
            return $this->getAll()['image'];
        }

        if (str_starts_with($mimeType, 'video/')) {
            return $this->getAll()['video'];
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return $this->getAll()['audio'];
        }

        return $this->getFallback();
    }

    /** Every extension Book recognises, for the CP's “what can I upload” hint. */
    public function getAllExtensions(): array
    {
        $this->getAll();

        return array_keys($this->byExtension);
    }

    /** @return array<string, Format> */
    private function define(): array
    {
        $formats = [];

        foreach ($this->definitions() as $definition) {
            $format = new Format($definition);
            $formats[$format->handle] = $format;
        }

        $this->byExtension = [];
        $this->byMime = [];

        foreach ($formats as $handle => $format) {
            foreach ($format->extensions as $extension) {
                $this->byExtension[$extension] ??= $handle;
            }

            foreach ($format->mimeTypes as $mime) {
                $this->byMime[$mime] ??= $handle;
            }
        }

        return $formats;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'handle' => 'pdf',
                'name' => Craft::t('book', 'PDF document'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['pdf'],
                'mimeTypes' => ['application/pdf', 'application/x-pdf'],
                // Native first: every current browser has a PDF viewer, it works for a private
                // file served through Craft, and it involves nobody else.
                'viewers' => [Viewer::NATIVE, Viewer::GOOGLE, Viewer::LINK],
                'nativeFrame' => true,
                'icon' => 'file-pdf',
            ],
            [
                'handle' => 'word',
                'name' => Craft::t('book', 'Word document'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['docx', 'doc', 'docm', 'dot', 'dotx'],
                'mimeTypes' => [
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                // Office before Google: it is Microsoft's own format and the layout survives.
                'viewers' => [Viewer::OFFICE, Viewer::GOOGLE, Viewer::LINK],
                'icon' => 'file-word',
            ],
            [
                'handle' => 'excel',
                'name' => Craft::t('book', 'Excel spreadsheet'),
                'group' => Format::GROUP_SPREADSHEET,
                'extensions' => ['xlsx', 'xls', 'xlsm', 'xlsb', 'xlt', 'xltx'],
                'mimeTypes' => [
                    'application/vnd.ms-excel',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ],
                'viewers' => [Viewer::OFFICE, Viewer::GOOGLE, Viewer::LINK],
                'icon' => 'file-excel',
            ],
            [
                'handle' => 'powerpoint',
                'name' => Craft::t('book', 'PowerPoint presentation'),
                'group' => Format::GROUP_PRESENTATION,
                'extensions' => ['pptx', 'ppt', 'pps', 'ppsx', 'pptm', 'pot', 'potx'],
                'mimeTypes' => [
                    'application/vnd.ms-powerpoint',
                    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                    'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
                ],
                'viewers' => [Viewer::OFFICE, Viewer::GOOGLE, Viewer::LINK],
                'icon' => 'file-powerpoint',
            ],
            [
                'handle' => 'opendocument',
                'name' => Craft::t('book', 'OpenDocument file'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['odt', 'ods', 'odp', 'odg', 'fodt', 'fods'],
                'mimeTypes' => [
                    'application/vnd.oasis.opendocument.text',
                    'application/vnd.oasis.opendocument.spreadsheet',
                    'application/vnd.oasis.opendocument.presentation',
                    'application/vnd.oasis.opendocument.graphics',
                ],
                // Office's viewer does not take OpenDocument. Google's does.
                'viewers' => [Viewer::GOOGLE, Viewer::LINK],
                'icon' => 'file-lines',
            ],
            [
                'handle' => 'rtf',
                'name' => Craft::t('book', 'Rich text document'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['rtf'],
                'mimeTypes' => ['application/rtf', 'text/rtf'],
                'viewers' => [Viewer::GOOGLE, Viewer::LINK],
                'icon' => 'file-lines',
            ],
            [
                'handle' => 'apple',
                'name' => Craft::t('book', 'Apple iWork file'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['pages', 'numbers', 'key'],
                'mimeTypes' => [
                    'application/vnd.apple.pages',
                    'application/vnd.apple.numbers',
                    'application/vnd.apple.keynote',
                ],
                // Nothing renders these in a frame. Saying so beats a viewer full of apology.
                'viewers' => [Viewer::LINK],
                'icon' => 'file',
            ],
            [
                'handle' => 'csv',
                'name' => Craft::t('book', 'Spreadsheet data'),
                'group' => Format::GROUP_SPREADSHEET,
                'extensions' => ['csv', 'tsv'],
                'mimeTypes' => ['text/csv', 'text/tab-separated-values', 'application/csv'],
                'viewers' => [Viewer::INLINE, Viewer::GOOGLE, Viewer::LINK],
                'inlineAs' => 'csv',
                'icon' => 'table',
            ],
            [
                'handle' => 'markdown',
                'name' => Craft::t('book', 'Markdown document'),
                'group' => Format::GROUP_TEXT,
                'extensions' => ['md', 'markdown', 'mdown'],
                'mimeTypes' => ['text/markdown', 'text/x-markdown'],
                'viewers' => [Viewer::INLINE, Viewer::LINK],
                'inlineAs' => 'markdown',
                'icon' => 'file-lines',
            ],
            [
                'handle' => 'text',
                'name' => Craft::t('book', 'Text file'),
                'group' => Format::GROUP_TEXT,
                'extensions' => ['txt', 'text', 'log', 'nfo'],
                'mimeTypes' => ['text/plain'],
                'viewers' => [Viewer::INLINE, Viewer::NATIVE, Viewer::LINK],
                'inlineAs' => 'text',
                'nativeFrame' => true,
                'icon' => 'file-lines',
            ],
            [
                'handle' => 'data',
                'name' => Craft::t('book', 'Data file'),
                'group' => Format::GROUP_TEXT,
                'extensions' => ['json', 'geojson', 'xml', 'yaml', 'yml', 'toml', 'ini'],
                'mimeTypes' => ['application/json', 'application/xml', 'text/xml', 'application/x-yaml'],
                'viewers' => [Viewer::INLINE, Viewer::LINK],
                'inlineAs' => 'json',
                'icon' => 'code',
            ],
            [
                'handle' => 'code',
                'name' => Craft::t('book', 'Source file'),
                'group' => Format::GROUP_TEXT,
                'extensions' => [
                    'php', 'js', 'ts', 'jsx', 'tsx', 'css', 'scss', 'less', 'html', 'htm', 'twig',
                    'py', 'rb', 'go', 'rs', 'java', 'c', 'h', 'cpp', 'cs', 'swift', 'kt', 'sh',
                    'bash', 'zsh', 'sql', 'graphql', 'env', 'conf', 'diff', 'patch',
                ],
                'mimeTypes' => ['text/x-php', 'application/javascript', 'text/javascript', 'text/css'],
                'viewers' => [Viewer::INLINE, Viewer::LINK],
                'inlineAs' => 'code',
                'icon' => 'code',
            ],
            [
                'handle' => 'image',
                'name' => Craft::t('book', 'Image'),
                'group' => Format::GROUP_IMAGE,
                'extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'],
                'mimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml'],
                'viewers' => [Viewer::NATIVE, Viewer::LINK],
                'nativeImage' => true,
                'icon' => 'image',
            ],
            [
                'handle' => 'video',
                'name' => Craft::t('book', 'Video'),
                'group' => Format::GROUP_MEDIA,
                'extensions' => ['mp4', 'm4v', 'webm', 'ogv', 'mov'],
                'mimeTypes' => ['video/mp4', 'video/webm', 'video/ogg', 'video/quicktime'],
                'viewers' => [Viewer::NATIVE, Viewer::LINK],
                'mediaTag' => 'video',
                'icon' => 'video',
            ],
            [
                'handle' => 'audio',
                'name' => Craft::t('book', 'Audio'),
                'group' => Format::GROUP_MEDIA,
                'extensions' => ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac'],
                'mimeTypes' => ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4', 'audio/flac'],
                'viewers' => [Viewer::NATIVE, Viewer::LINK],
                'mediaTag' => 'audio',
                'icon' => 'volume-high',
            ],
            [
                'handle' => 'ebook',
                'name' => Craft::t('book', 'E-book'),
                'group' => Format::GROUP_DOCUMENT,
                'extensions' => ['epub', 'mobi', 'azw3'],
                'mimeTypes' => ['application/epub+zip'],
                'viewers' => [Viewer::LINK],
                'icon' => 'book',
            ],
            [
                'handle' => 'archive',
                'name' => Craft::t('book', 'Archive'),
                'group' => Format::GROUP_OTHER,
                'extensions' => ['zip', 'rar', '7z', 'tar', 'gz', 'tgz', 'bz2', 'xz'],
                'mimeTypes' => ['application/zip', 'application/x-rar-compressed', 'application/gzip'],
                'viewers' => [Viewer::LINK],
                'icon' => 'file-zipper',
            ],
            [
                'handle' => 'other',
                'name' => Craft::t('book', 'File'),
                'group' => Format::GROUP_OTHER,
                'extensions' => [],
                'mimeTypes' => [],
                // Google's viewer will have a go at almost anything, but only when asked for by
                // name — guessing on an unknown format is how you get an apology page.
                'viewers' => [Viewer::LINK, Viewer::GOOGLE],
                'icon' => 'file',
            ],
        ];
    }
}
