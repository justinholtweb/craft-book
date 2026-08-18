<?php

namespace justinholtweb\book\models;

use Craft;
use craft\base\Model;

/**
 * One way of showing a document.
 *
 * A viewer is a strategy, not a renderer: it says what kind of markup the template should write
 * and — for the two hosted ones — how to turn a document URL into a viewer URL. The template
 * does the writing, so a site that overrides `_book/document.twig` keeps all five.
 */
class Viewer extends Model
{
    /** The browser's own PDF viewer, `<img>`, `<audio>` or `<video>`. No third party. */
    public const NATIVE = 'native';

    /** Google Docs Viewer. Renders almost anything; needs a public URL; sees the URL. */
    public const GOOGLE = 'google';

    /** Microsoft Office Web Viewer. Better with Office formats; same two caveats. */
    public const OFFICE = 'office';

    /** Book reads the file and writes HTML. Assets only, text-shaped formats only. */
    public const INLINE = 'inline';

    /** A download card. The end of every fallback chain. */
    public const LINK = 'link';

    /** Not a viewer: “pick the best one that works here”. */
    public const AUTO = 'auto';

    public const ALL = [self::NATIVE, self::GOOGLE, self::OFFICE, self::INLINE, self::LINK];

    /** Every value {@see \justinholtweb\book\models\DocumentOptions::$viewer} accepts. */
    public const CHOICES = [self::AUTO, ...self::ALL];

    /** The two that are somebody else's servers. */
    public const THIRD_PARTY = [self::GOOGLE, self::OFFICE];

    public string $handle = self::LINK;

    public string $name = '';

    /** Whether the reader's browser talks to somebody other than this site. */
    public bool $thirdParty = false;

    /** Whether the document URL has to be reachable from the public internet. */
    public bool $requiresPublicUrl = false;

    /** Whether the file has to be a Craft asset, because Book reads its bytes. */
    public bool $requiresAsset = false;

    /** Who the reader is handed to, for the consent card. */
    public string $host = '';

    /** A sentence explaining the trade-off, shown in the CP. */
    public string $note = '';

    /**
     * The viewer URL for a document URL.
     *
     * @var callable(string): string|null
     */
    public $builder = null;

    public function buildUrl(string $documentUrl): string
    {
        return $this->builder ? ($this->builder)($documentUrl) : $documentUrl;
    }

    /** The name to put in “Load content from …?”. */
    public function getConsentName(): string
    {
        return $this->name ?: $this->host ?: Craft::t('book', 'the viewer');
    }
}
