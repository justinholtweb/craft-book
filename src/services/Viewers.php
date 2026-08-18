<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\Source;
use justinholtweb\book\models\Viewer;
use justinholtweb\book\models\ViewerResolution;
use justinholtweb\book\Plugin;

/**
 * The viewer registry, and the decision about which one actually runs.
 *
 * Resolution is the interesting half. Three things can rule a viewer out — the format, the
 * environment, and the site's own policy — and an author gets told which, because “the document
 * is blank” is the worst possible way to find out that a staging URL is not reachable from
 * Google's data centre.
 */
class Viewers extends Component
{
    /** @var array<string, Viewer>|null */
    private ?array $viewers = null;

    /** @return array<string, Viewer> */
    public function getAll(): array
    {
        return $this->viewers ??= $this->define();
    }

    public function getByHandle(?string $handle): ?Viewer
    {
        return $handle ? ($this->getAll()[$handle] ?? null) : null;
    }

    /**
     * What will actually happen when this document renders in this environment.
     *
     * @param array<string, mixed> $context Extra values for the resolution, unused today.
     */
    public function resolve(Source $source, DocumentOptions $options, array $context = []): ViewerResolution
    {
        $resolution = new ViewerResolution([
            'requested' => $options->viewer,
            'viewer' => Viewer::LINK,
        ]);

        if ($source->getIsEmpty()) {
            $resolution->addWarning(Craft::t('book', 'There is no file here — the asset may have been deleted.'));

            return $resolution;
        }

        $delivery = Plugin::getInstance()->delivery;
        $format = $source->getFormat();

        $resolution->documentUrl = $delivery->fileUrl($source, $options);
        $resolution->downloadUrl = $delivery->fileUrl($source, $options, Delivery::DISPOSITION_ATTACHMENT);

        $requested = $options->viewer;
        $candidates = $requested === Viewer::AUTO
            ? $format->viewers
            : [$requested, ...($options->fallback ? $format->viewers : [])];

        $firstReason = null;

        foreach ($candidates as $handle) {
            if ($handle === Viewer::LINK) {
                // Always possible, so there is no point testing it — and reaching it means every
                // richer option was ruled out, which the warnings above will already have said.
                $resolution->viewer = Viewer::LINK;
                break;
            }

            $reason = $this->rejectionReason($handle, $source, $options, $format);

            if ($reason === null) {
                $resolution->viewer = $handle;
                break;
            }

            $firstReason ??= $reason;

            // Only the author's own choice is worth explaining. The others were guesses Book
            // made on their behalf, and narrating every one of them is noise.
            if ($handle === $requested) {
                $resolution->addWarning($reason);
                $resolution->fallbackReason = $reason;
            }
        }

        if ($resolution->viewer === Viewer::LINK && $requested === Viewer::AUTO && $firstReason !== null && !$format->getIsOther() && count($format->viewers) > 1) {
            $resolution->addWarning($firstReason);
        }

        $viewer = $this->getByHandle($resolution->viewer);

        if ($viewer && $viewer->builder && $resolution->documentUrl) {
            $resolution->frameUrl = $viewer->buildUrl($resolution->documentUrl);
        } elseif ($resolution->viewer === Viewer::NATIVE && $format->nativeFrame) {
            $resolution->frameUrl = $resolution->documentUrl . $options->getPdfFragment();
        }

        // Worth saying even when everything worked: a signed URL that a third party has cached
        // will stop working the moment it expires, and nothing about that looks like a setting.
        if ($resolution->getIsThirdParty() && $source->getIsAsset()) {
            $settings = Plugin::getInstance()->getSettings();

            if ($settings->signedUrls && $settings->signedUrlDuration > 0) {
                $resolution->addWarning(Craft::t('book', 'Signed URLs are on, so this viewer will stop being able to reach the file once the signature expires.'));
            }

            if ($delivery->getWouldExposePrivateFile($source)) {
                $resolution->addWarning(Craft::t('book', 'This file is in a volume with no public URLs. Book can serve it, so {viewer} will be able to download it — a file nobody could otherwise reach.', [
                    'viewer' => $this->getByHandle($resolution->viewer)?->name ?? $resolution->viewer,
                ]));
            }
        }

        return $resolution;
    }

    /**
     * Why a viewer cannot be used here, or null when it can.
     */
    public function rejectionReason(string $handle, Source $source, DocumentOptions $options, ?Format $format = null): ?string
    {
        $viewer = $this->getByHandle($handle);

        if (!$viewer) {
            return Craft::t('book', 'There is no “{viewer}” viewer.', ['viewer' => $handle]);
        }

        $format ??= $source->getFormat();
        $settings = Plugin::getInstance()->getSettings();

        if (!$format->supports($handle)) {
            return Craft::t('book', 'The {viewer} viewer cannot show a {format}.', [
                'viewer' => $viewer->name,
                'format' => mb_strtolower($format->name),
            ]);
        }

        if ($viewer->thirdParty && !$settings->viewerIsAllowed($handle)) {
            return Craft::t('book', 'The {viewer} viewer is switched off in Book’s settings.', [
                'viewer' => $viewer->name,
            ]);
        }

        if ($viewer->requiresAsset && !$source->getIsReadable()) {
            return Craft::t('book', 'Book can only read a file it holds as an asset, so it cannot render this URL itself.');
        }

        if ($viewer->requiresAsset && $source->size !== null && $source->size > $settings->inlineMaxBytes) {
            return Craft::t('book', 'This file is larger than Book’s inline limit of {limit} bytes.', [
                'limit' => $settings->inlineMaxBytes,
            ]);
        }

        if ($viewer->requiresPublicUrl && $settings->checkPublicUrl) {
            $delivery = Plugin::getInstance()->delivery;

            if ($options->access === DocumentOptions::ACCESS_LOGIN) {
                return Craft::t('book', 'The {viewer} viewer fetches the file itself, as nobody, so it cannot open a document that requires a login.', [
                    'viewer' => $viewer->name,
                ]);
            }

            if (!$delivery->isPubliclyReachable($delivery->fileUrl($source, $options))) {
                return Craft::t('book', 'The {viewer} viewer fetches the file from its own servers, and this URL is not reachable from the public internet.', [
                    'viewer' => $viewer->name,
                ]);
            }
        }

        return null;
    }

    /**
     * The viewers an author may sensibly choose for a format, for the CP's select.
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function choicesFor(?Format $format): array
    {
        $choices = [['label' => Craft::t('book', 'Automatic — the best one that works here'), 'value' => Viewer::AUTO]];

        foreach ($this->getAll() as $handle => $viewer) {
            $supported = $format === null || $format->supports($handle);

            $choices[] = [
                'label' => $supported
                    ? $viewer->name
                    : Craft::t('book', '{viewer} (not for this format)', ['viewer' => $viewer->name]),
                'value' => $handle,
            ];
        }

        return $choices;
    }

    /** @return array<string, Viewer> */
    private function define(): array
    {
        $viewers = [
            new Viewer([
                'handle' => Viewer::NATIVE,
                'name' => Craft::t('book', 'Browser'),
                'note' => Craft::t('book', 'The browser’s own viewer. Nothing leaves this site, and a file served through Craft works even from a private volume.'),
            ]),
            new Viewer([
                'handle' => Viewer::OFFICE,
                'name' => Craft::t('book', 'Microsoft Office'),
                'thirdParty' => true,
                'requiresPublicUrl' => true,
                'host' => 'view.officeapps.live.com',
                'note' => Craft::t('book', 'Microsoft renders the document and the reader’s browser loads the result. The file’s URL is sent to Microsoft, and their servers download it.'),
                'builder' => fn(string $url) => 'https://view.officeapps.live.com/op/embed.aspx?src=' . rawurlencode($url),
            ]),
            new Viewer([
                'handle' => Viewer::GOOGLE,
                'name' => Craft::t('book', 'Google'),
                'thirdParty' => true,
                'requiresPublicUrl' => true,
                'host' => 'docs.google.com',
                'note' => Craft::t('book', 'Google renders the document and the reader’s browser loads the result. The file’s URL is sent to Google, and their servers download it.'),
                'builder' => fn(string $url) => 'https://docs.google.com/gview?embedded=true&url=' . rawurlencode($url),
            ]),
            new Viewer([
                'handle' => Viewer::INLINE,
                'name' => Craft::t('book', 'Book'),
                'requiresAsset' => true,
                'note' => Craft::t('book', 'Book reads the file out of your asset volume and writes the HTML itself. No frame, no third party, and it works for private files.'),
            ]),
            new Viewer([
                'handle' => Viewer::LINK,
                'name' => Craft::t('book', 'Download card'),
                'note' => Craft::t('book', 'A card with the file’s name, type and size, and a link. Always available, and where every other viewer falls back to.'),
            ]),
        ];

        $out = [];

        foreach ($viewers as $viewer) {
            $out[$viewer->handle] = $viewer;
        }

        return $out;
    }
}
