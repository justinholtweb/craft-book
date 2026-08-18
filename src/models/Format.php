<?php

namespace justinholtweb\book\models;

use craft\base\Model;

/**
 * One document format: what it is called, what it is called on disk, and who can show it.
 *
 * The `viewers` list is ordered by preference, which is the whole of the `auto` decision — a
 * PDF prefers the browser's own viewer, a `.docx` prefers Office over Google, and a CSV prefers
 * Book's own table over handing the reader to anybody.
 */
class Format extends Model
{
    public const GROUP_DOCUMENT = 'document';
    public const GROUP_SPREADSHEET = 'spreadsheet';
    public const GROUP_PRESENTATION = 'presentation';
    public const GROUP_TEXT = 'text';
    public const GROUP_IMAGE = 'image';
    public const GROUP_MEDIA = 'media';
    public const GROUP_OTHER = 'other';

    public string $handle = 'other';

    public string $name = 'File';

    public string $group = self::GROUP_OTHER;

    /** Lower-case, no dot. The first one is the canonical spelling. @var string[] */
    public array $extensions = [];

    /** @var string[] */
    public array $mimeTypes = [];

    /**
     * Viewer handles this format can be shown by, best first.
     *
     * `link` is on every one of them, last, because a download card is always possible and is
     * never a failure.
     *
     * @var string[]
     */
    public array $viewers = [Viewer::LINK];

    /**
     * Whether {@see \justinholtweb\book\services\Inliner} can turn this into HTML, and how.
     *
     * One of `text`, `markdown`, `csv`, `json`, `code`, or null for "it cannot".
     */
    public ?string $inlineAs = null;

    /** The media element a native viewer should use, if this is playable: `audio` or `video`. */
    public ?string $mediaTag = null;

    /** Whether a browser can display this in a frame on its own. */
    public bool $nativeFrame = false;

    /** Whether a native viewer should use `<img>` rather than a frame. */
    public bool $nativeImage = false;

    /** A Craft CP icon name, for the index and the download card. */
    public string $icon = 'file';

    public function getExtension(): string
    {
        return $this->extensions[0] ?? '';
    }

    public function getIsOther(): bool
    {
        return $this->handle === 'other';
    }

    /** Whether Book can render this format itself, with nobody else involved. */
    public function getIsInlineable(): bool
    {
        return $this->inlineAs !== null;
    }

    public function supports(string $viewer): bool
    {
        return in_array($viewer, $this->viewers, true);
    }

    /** The label a download card shows: “PDF document”, “Excel spreadsheet”. */
    public function getLabel(): string
    {
        return $this->name;
    }
}
