<?php

namespace justinholtweb\book\models;

use craft\base\Model;

/**
 * The answer to “what is actually going to happen when this document renders here”.
 *
 * Produced by {@see \justinholtweb\book\services\Viewers::resolve()} and used in three places:
 * the renderer, the CP edit screen (where it is the whole point — an author should find out that
 * Google cannot reach their staging URL *before* a reader does) and the checks.
 */
class ViewerResolution extends Model
{
    /** The viewer that will be used, never `auto`. */
    public string $viewer = Viewer::LINK;

    /** What the author asked for, which may not be what they got. */
    public string $requested = Viewer::AUTO;

    /** The URL the document itself lives at, as handed to a viewer. */
    public ?string $documentUrl = null;

    /** What an `<iframe src>` should be. Null when this viewer does not use one. */
    public ?string $frameUrl = null;

    /** The URL a download button points at. */
    public ?string $downloadUrl = null;

    /** Sentences worth showing an author. Never shown to a reader. @var string[] */
    public array $warnings = [];

    /** Why the requested viewer was not used, when it was not. */
    public ?string $fallbackReason = null;

    public function getWasDowngraded(): bool
    {
        return $this->requested !== Viewer::AUTO && $this->requested !== $this->viewer;
    }

    public function getIsThirdParty(): bool
    {
        return in_array($this->viewer, Viewer::THIRD_PARTY, true);
    }

    public function addWarning(string $message): void
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }
}
