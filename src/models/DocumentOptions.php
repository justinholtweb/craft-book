<?php

namespace justinholtweb\book\models;

use craft\base\Model;

/**
 * Everything presentational about a document embed.
 *
 * One format, shared verbatim by {@see \justinholtweb\book\elements\Document}, by inline field
 * values, by the editor JavaScript and by the renderer — so there is exactly one place that
 * knows what “click” or “8.5:11” means, and a value written by any of them can be read by all of
 * them.
 */
class DocumentOptions extends Model
{
    public const LOADING_EAGER = 'eager';
    public const LOADING_LAZY = 'lazy';

    /** Nothing is requested until the reader asks for it. The one that matters for Google. */
    public const LOADING_CLICK = 'click';

    public const LOADINGS = [self::LOADING_EAGER, self::LOADING_LAZY, self::LOADING_CLICK];

    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';
    public const ALIGN_WIDE = 'wide';
    public const ALIGN_FULL = 'full';

    public const ALIGNS = [
        self::ALIGN_LEFT,
        self::ALIGN_CENTER,
        self::ALIGN_RIGHT,
        self::ALIGN_WIDE,
        self::ALIGN_FULL,
    ];

    /** Anyone with the link. */
    public const ACCESS_PUBLIC = 'public';

    /** A logged-in user. Only meaningful for files Book serves itself. */
    public const ACCESS_LOGIN = 'login';

    public const ACCESSES = [self::ACCESS_PUBLIC, self::ACCESS_LOGIN];

    // What shows the document
    // -------------------------------------------------------------------------

    /** One of {@see Viewer::CHOICES}. `auto` is resolved per document, per environment. */
    public string $viewer = Viewer::AUTO;

    /**
     * Whether an unusable viewer may quietly become a usable one.
     *
     * On, because the alternative is a blank box on the staging site. Off when an author needs
     * to know that their choice did not happen — {@see ViewerResolution::$warnings} says so
     * either way, and dev mode puts it on the page.
     */
    public bool $fallback = true;

    // Size and placement
    // -------------------------------------------------------------------------

    /** Height in pixels. Used unless {@see self::$ratio} is set. */
    public int $height = 720;

    /** `8.5:11`, `16:9`, `1:1` — anything `w:h`. Beats `height` when set. */
    public string $ratio = '';

    /** Any CSS width. */
    public string $width = '100%';

    public string $align = self::ALIGN_CENTER;

    /** Extra classes on the wrapper, for the site's own stylesheet to hook. */
    public string $className = '';

    /** A DOM id for the wrapper. Generated when blank. */
    public string $id = '';

    // Chrome
    // -------------------------------------------------------------------------

    /** Book's own bar above the document: the title, and whichever buttons are on. */
    public bool $toolbar = true;

    /** A download button. Points at the file with `Content-Disposition: attachment`. */
    public bool $download = true;

    /** An “open in a new tab” button. */
    public bool $open = true;

    /** A print button. Only offered where the browser can actually do it. */
    public bool $print = false;

    /** A fullscreen button, using the Fullscreen API on the wrapper. */
    public bool $fullscreen = true;

    /** The document's displayed name and the frame's accessible name. */
    public string $title = '';

    /** Shown under the document. */
    public string $caption = '';

    /** Show the file's size and type next to the title. */
    public bool $showMeta = true;

    // Loading
    // -------------------------------------------------------------------------

    public string $loading = self::LOADING_LAZY;

    /** How far ahead of the viewport a lazy or click document is prepared. */
    public string $rootMargin = '200px';

    public bool $showLoader = true;

    /**
     * How long to wait for the frame's `load` event before showing the download card, in
     * milliseconds. 0 waits forever.
     *
     * The Google viewer answers `200 OK` with an apology page when it cannot reach a URL, and a
     * frame that never loads at all says nothing to the parent. A timeout is the only thing that
     * turns “permanently blank” into “here is the file”.
     */
    public int $timeout = 12000;

    // Consent, for `loading: click`
    // -------------------------------------------------------------------------

    public string $consentTitle = '';

    public string $consentText = '';

    public string $consentButtonLabel = '';

    /** An image behind the consent card. */
    public string $posterUrl = '';

    /** Remember the reader's choice for this viewer in `localStorage`. */
    public bool $rememberConsent = false;

    // PDF, for the native viewer
    // -------------------------------------------------------------------------

    /** The page a PDF opens at. */
    public int $page = 0;

    /** `page-width`, `page-fit`, `page-height`, or a percentage like `120`. Blank leaves it. */
    public string $zoom = '';

    /** Whether the browser's own PDF chrome is shown. Chrome and Firefox honour this. */
    public bool $pdfToolbar = true;

    // Inline rendering
    // -------------------------------------------------------------------------

    /** Treat a CSV's first row as headers. */
    public bool $csvHeader = true;

    /** Stop after this many rows of a CSV. 0 means all of it, up to the byte cap. */
    public int $maxRows = 500;

    /** Wrap long lines in an inlined text or code block instead of scrolling. */
    public bool $wrap = false;

    // Delivery
    // -------------------------------------------------------------------------

    /** {@see self::ACCESS_PUBLIC} or {@see self::ACCESS_LOGIN}. */
    public string $access = self::ACCESS_PUBLIC;

    /** Force the file through Craft even when the volume has public URLs. `null` = the setting. */
    public ?bool $serveThroughCraft = null;

    // -------------------------------------------------------------------------

    public function defineRules(): array
    {
        return [
            [['viewer'], 'in', 'range' => Viewer::CHOICES],
            [['loading'], 'in', 'range' => self::LOADINGS],
            [['align'], 'in', 'range' => self::ALIGNS],
            [['access'], 'in', 'range' => self::ACCESSES],
            [['height', 'timeout', 'page', 'maxRows'], 'integer', 'min' => 0],
            [['ratio'], 'match', 'pattern' => '/^\d+(\.\d+)?\s*[:\/]\s*\d+(\.\d+)?$/', 'skipOnEmpty' => true],
            [
                [
                    'width', 'className', 'id', 'title', 'caption', 'rootMargin', 'consentTitle',
                    'consentText', 'consentButtonLabel', 'posterUrl', 'zoom',
                ],
                'string',
            ],
            [
                [
                    'fallback', 'toolbar', 'download', 'open', 'print', 'fullscreen', 'showMeta',
                    'showLoader', 'rememberConsent', 'pdfToolbar', 'csvHeader', 'wrap',
                ],
                'boolean',
            ],
            [['serveThroughCraft'], 'safe'],
        ];
    }

    // Construction
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed>|string|null $value An options array, a JSON string, or null.
     */
    public static function fromArray(array|string|null $value): self
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        $options = new self();

        if ($value) {
            $options->apply($value);
        }

        return $options;
    }

    /**
     * Assign only the keys that exist, coercing each to the property's type.
     *
     * Everything that reaches this — a JSON column, a reference tag, a posted form, an editor's
     * data attribute — is untrusted and partial, so `setAttributes()` is not enough on its own.
     *
     * @param array<string, mixed> $values
     */
    public function apply(array $values): void
    {
        foreach ($values as $key => $value) {
            $key = (string)$key;

            if (!property_exists($this, $key)) {
                continue;
            }

            if ($value === null && $key !== 'serveThroughCraft') {
                continue;
            }

            match ($key) {
                'height', 'timeout', 'page', 'maxRows' => $this->$key = max(0, (int)$value),
                'fallback', 'toolbar', 'download', 'open', 'print', 'fullscreen', 'showMeta',
                'showLoader', 'rememberConsent', 'pdfToolbar', 'csvHeader', 'wrap' => $this->$key = self::toBool($value),
                'serveThroughCraft' => $this->serveThroughCraft = $value === '' || $value === null ? null : self::toBool($value),
                'viewer' => $this->viewer = in_array($value, Viewer::CHOICES, true) ? (string)$value : $this->viewer,
                'loading' => $this->loading = self::normalizeLoading($value) ?? $this->loading,
                'align' => $this->align = in_array($value, self::ALIGNS, true) ? (string)$value : $this->align,
                'access' => $this->access = in_array($value, self::ACCESSES, true) ? (string)$value : $this->access,
                'ratio' => $this->ratio = self::normalizeRatio((string)$value) ?? $this->ratio,
                default => $this->$key = is_scalar($value) ? (string)$value : $this->$key,
            };
        }
    }

    /** A copy with `$overrides` applied — the original is never mutated. */
    public function merge(array|string|null $overrides): self
    {
        if (!$overrides) {
            return $this;
        }

        $clone = clone $this;

        if (is_string($overrides)) {
            $decoded = json_decode($overrides, true);
            $overrides = is_array($decoded) ? $decoded : self::parseEmbedOptions($overrides);
        }

        $clone->apply($overrides);

        return $clone;
    }

    /** @return array<string, mixed> */
    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return get_object_vars($this);
    }

    /**
     * Only what differs from the defaults.
     *
     * Stored configs are diffed so that a default Book later changes its mind about — a longer
     * timeout, a different fallback rule — reaches documents that never expressed an opinion.
     *
     * @return array<string, mixed>
     */
    public function toStorageArray(): array
    {
        $defaults = new self();
        $out = [];

        foreach (get_object_vars($this) as $key => $value) {
            if ($value !== $defaults->$key) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // Reference-tag options: `{book:brochure:render(google,height=900)}`
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function parseEmbedOptions(string $string): array
    {
        $out = [];

        foreach (preg_split('/\s*,\s*/', trim($string), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = array_map('trim', explode('=', $part, 2));

                if ($key !== '') {
                    $out[self::normalizeKey($key)] = $value;
                }

                continue;
            }

            // A bare word is a viewer, a loading strategy, an alignment or a flag —
            // `{book:brochure:render(click)}` should mean what it obviously means.
            if (in_array($part, Viewer::CHOICES, true)) {
                $out['viewer'] = $part;
            } elseif (($loading = self::normalizeLoading($part)) !== null) {
                $out['loading'] = $loading;
            } elseif (in_array($part, self::ALIGNS, true)) {
                $out['align'] = $part;
            } elseif (self::normalizeRatio($part) !== null) {
                $out['ratio'] = self::normalizeRatio($part);
            } elseif (str_starts_with($part, 'no-')) {
                $out[self::normalizeKey(substr($part, 3))] = false;
            } else {
                $out[self::normalizeKey($part)] = true;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $options */
    public static function toEmbedOptions(array $options): string
    {
        $parts = [];

        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $parts[] = $value ? $key : "no-$key";
            } elseif (is_array($value)) {
                $parts[] = $key . '=' . implode(' ', $value);
            } else {
                $parts[] = $key . '=' . $value;
            }
        }

        return implode(',', $parts);
    }

    // Derived values the renderer and the templates ask for
    // -------------------------------------------------------------------------

    /** The CSS `aspect-ratio` value, or null when this document is sized by height. */
    public function getAspectRatio(): ?string
    {
        $ratio = self::normalizeRatio($this->ratio);

        return $ratio === null ? null : str_replace(':', ' / ', $ratio);
    }

    /** Whether the front-end runtime has anything to do for this document. */
    public function getNeedsRuntime(): bool
    {
        return $this->loading === self::LOADING_CLICK
            || $this->timeout > 0
            || $this->fullscreen
            || $this->print;
    }

    /** Whether any button is on, which is what decides if a toolbar is worth drawing. */
    public function getHasToolbarButtons(): bool
    {
        return $this->download || $this->open || $this->print || $this->fullscreen;
    }

    /**
     * The `#…` a native PDF frame carries.
     *
     * These are PDF Open Parameters, honoured by Chrome, Edge and Firefox's built-in viewers and
     * ignored by everything else, which is the correct failure mode for a preference.
     */
    public function getPdfFragment(): string
    {
        $parts = [];

        if (!$this->pdfToolbar) {
            $parts[] = 'toolbar=0';
            $parts[] = 'navpanes=0';
        }

        if ($this->page > 1) {
            $parts[] = 'page=' . $this->page;
        }

        if ($this->zoom !== '') {
            $parts[] = match ($this->zoom) {
                'page-width' => 'view=FitH',
                'page-fit' => 'view=Fit',
                'page-height' => 'view=FitV',
                default => 'zoom=' . (int)$this->zoom,
            };
        }

        return $parts ? '#' . implode('&', $parts) : '';
    }

    // Coercion helpers
    // -------------------------------------------------------------------------

    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool)$value;
    }

    /** `16x9`, `4/3` and a bare `1.5` all mean something an author would recognise. */
    public static function normalizeRatio(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace(['x', '/', ' '], [':', ':', ''], strtolower($value));

        if (preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $value, $matches) && (float)$matches[2] > 0) {
            return $matches[1] . ':' . $matches[2];
        }

        if (is_numeric($value) && (float)$value > 0) {
            return $value . ':1';
        }

        return null;
    }

    /** `true`, `defer` and `lazy` all mean lazy; `false` and `eager` mean eager. */
    public static function normalizeLoading(mixed $value): ?string
    {
        $value = strtolower(trim((string)$value));

        return match ($value) {
            'eager', 'false', '0', 'now' => self::LOADING_EAGER,
            'lazy', 'true', '1', 'defer' => self::LOADING_LAZY,
            'click', 'consent', 'ask' => self::LOADING_CLICK,
            default => null,
        };
    }

    /** `pdf-toolbar` and `pdfToolbar` are the same key; a ref tag should not care. */
    private static function normalizeKey(string $key): string
    {
        $key = preg_replace_callback('/[-_\s]+(.)/', fn($m) => strtoupper($m[1]), trim($key)) ?? $key;

        return lcfirst($key);
    }
}
