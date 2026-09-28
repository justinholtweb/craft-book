<?php

namespace justinholtweb\book\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin settings.
 *
 * Nothing here is `required`. A required rule makes `savePluginSettings()` fail wholesale, so a
 * fresh install could not save *any* setting until that one field was filled in — values are
 * validated for correctness when present instead.
 */
class Settings extends Model
{
    /** Whether Book registers its own stylesheet when a document renders. */
    public bool $registerCss = true;

    /** Whether Book registers the front-end runtime when a document needs one. */
    public bool $registerJs = true;

    /** Defaults handed to every new document, as a {@see DocumentOptions} array. */
    public array $defaultOptions = [];

    // Third-party viewers
    // -------------------------------------------------------------------------

    /**
     * Whether Google's viewer may be used at all.
     *
     * Off is a real answer, not a paranoid one: Google's viewer means Google's servers download
     * every document you embed, and a site under a data-processing agreement may simply not be
     * allowed to do that. With this off, `auto` never picks it and an explicit choice of it is
     * refused with a reason.
     */
    public bool $allowGoogleViewer = true;

    /** The same, for Microsoft's Office Web Viewer. */
    public bool $allowOfficeViewer = true;

    /**
     * Whether Book refuses to hand a URL to a third-party viewer when that URL is obviously not
     * reachable from the public internet.
     *
     * On. The check is syntactic — local domains, private addresses, relative URLs — and it
     * turns the single most common failure (a blank viewer on a staging site) into a sentence.
     */
    public bool $checkPublicUrl = true;

    // Delivery
    // -------------------------------------------------------------------------

    /**
     * Serve every asset through Craft, even when its volume has public URLs.
     *
     * Off by default: a volume URL is usually a CDN URL, and going through PHP to serve a 40 MB
     * PDF that nginx could have sent is a poor trade. Turn it on when the volume path itself is
     * something you would rather not publish.
     */
    public bool $serveAssetsThroughCraft = false;

    /**
     * Sign Book's own file URLs, so one cannot be shared or guessed indefinitely.
     *
     * Off by default, and worth understanding before turning on: a signed URL cannot be used by
     * Google or Microsoft's viewers for longer than its lifetime, so an embed that worked when
     * the page was published goes blank later. Book warns about that combination.
     */
    public bool $signedUrls = false;

    /** How long a signature lasts, in seconds. 0 means it never expires. */
    public int $signedUrlDuration = 86400;

    /**
     * An extra secret mixed into every file token, so they can all be revoked at once.
     *
     * Tokens for private-volume files never expire unless `signedUrls` is on (an expiring one would
     * break every embed of it, later and silently), so a leaked link used to stay valid until the
     * site's security key changed. Changing this invalidates every link Book has handed out without
     * touching the security key. Empty — the default — signs exactly as before, so existing links
     * keep working on upgrade. Accepts an environment variable, which is the way to use it: change
     * `BOOK_LINK_SECRET` and every old link stops working, no deploy needed.
     */
    public string $linkSecret = '';

    // Inline rendering
    // -------------------------------------------------------------------------

    /** The most Book will read into memory to render a file itself. */
    public int $inlineMaxBytes = 1048576;

    /** An HTML Purifier config file in `config/htmlpurifier/`, without the extension. */
    public ?string $purifierConfig = null;

    // Rich text
    // -------------------------------------------------------------------------

    /**
     * Add Book's button to CKEditor and Redactor toolbars.
     *
     * Editing only — rendering is Craft's reference-tag parsing, so turning this off costs an
     * author convenience and costs existing documents nothing.
     */
    public bool $richTextIntegration = true;

    public function defineRules(): array
    {
        return [
            [
                [
                    'registerCss', 'registerJs', 'allowGoogleViewer', 'allowOfficeViewer',
                    'checkPublicUrl', 'serveAssetsThroughCraft', 'signedUrls', 'richTextIntegration',
                ],
                'boolean',
            ],
            [['signedUrlDuration'], 'integer', 'min' => 0],
            [['inlineMaxBytes'], 'integer', 'min' => 1024],
            [['purifierConfig', 'linkSecret'], 'string'],
            [['defaultOptions'], 'safe'],
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'registerCss' => Craft::t('book', 'Register stylesheet'),
            'registerJs' => Craft::t('book', 'Register runtime'),
            'allowGoogleViewer' => Craft::t('book', 'Allow the Google viewer'),
            'allowOfficeViewer' => Craft::t('book', 'Allow the Microsoft Office viewer'),
            'checkPublicUrl' => Craft::t('book', 'Check that URLs are publicly reachable'),
            'serveAssetsThroughCraft' => Craft::t('book', 'Serve assets through Craft'),
            'signedUrls' => Craft::t('book', 'Sign file URLs'),
            'signedUrlDuration' => Craft::t('book', 'Signature lifetime'),
            'inlineMaxBytes' => Craft::t('book', 'Inline size limit'),
            'linkSecret' => Craft::t('book', 'Link secret'),
            'purifierConfig' => Craft::t('book', 'HTML Purifier config'),
            'richTextIntegration' => Craft::t('book', 'Rich-text editor button'),
        ];
    }

    /** {@see $linkSecret}, with any environment variable resolved. */
    public function getLinkSecret(): string
    {
        return (string)(App::parseEnv($this->linkSecret) ?? '');
    }

    public function viewerIsAllowed(string $viewer): bool
    {
        return match ($viewer) {
            Viewer::GOOGLE => $this->allowGoogleViewer,
            Viewer::OFFICE => $this->allowOfficeViewer,
            default => true,
        };
    }

    public function getDefaultDocumentOptions(): DocumentOptions
    {
        return DocumentOptions::fromArray($this->defaultOptions ?: null);
    }

    /** Whether any third-party viewer is available at all — the settings screen says so. */
    public function getAnyThirdPartyViewerAllowed(): bool
    {
        return $this->allowGoogleViewer || $this->allowOfficeViewer;
    }
}
