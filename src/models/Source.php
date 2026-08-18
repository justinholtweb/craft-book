<?php

namespace justinholtweb\book\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\UrlHelper;
use justinholtweb\book\Plugin;

/**
 * What a document points at, resolved.
 *
 * Either a Craft asset or a URL — every question the rest of the plugin asks (what format is
 * this, does it have a public URL, can Book read its bytes) is asked of this object, so nothing
 * downstream has to branch on which kind it is holding.
 */
class Source extends Model
{
    public const KIND_ASSET = 'asset';
    public const KIND_URL = 'url';

    public string $kind = self::KIND_URL;

    public ?int $assetId = null;

    /** For {@see self::KIND_URL}, the URL as the author typed it. */
    public string $url = '';

    public string $filename = '';

    public string $extension = '';

    public ?string $mimeType = null;

    /** Bytes, when known. Craft knows it for assets; a URL tells us nothing. */
    public ?int $size = null;

    private ?Asset $_asset = null;

    private ?Format $_format = null;

    public static function fromAsset(Asset $asset): self
    {
        $source = new self([
            'kind' => self::KIND_ASSET,
            'assetId' => $asset->id,
            'filename' => (string)$asset->getFilename(),
            'extension' => strtolower((string)$asset->getExtension()),
            'size' => $asset->size !== null ? (int)$asset->size : null,
        ]);

        $source->setAsset($asset);

        // Craft's MIME lookup goes through the filename, so a missing extension is the only way
        // this comes back empty — and that is exactly when guessing would be wrong anyway.
        try {
            $source->mimeType = $asset->getMimeType();
        } catch (\Throwable) {
            $source->mimeType = null;
        }

        return $source;
    }

    public static function fromUrl(string $url): self
    {
        $url = trim($url);
        $path = (string)parse_url($url, PHP_URL_PATH);
        $filename = $path !== '' ? basename($path) : '';

        return new self([
            'kind' => self::KIND_URL,
            'url' => $url,
            'filename' => $filename,
            'extension' => strtolower((string)pathinfo($filename, PATHINFO_EXTENSION)),
        ]);
    }

    public function setAsset(?Asset $asset): void
    {
        $this->_asset = $asset;
    }

    public function getAsset(): ?Asset
    {
        if ($this->_asset === null && $this->assetId) {
            $this->_asset = Craft::$app->getAssets()->getAssetById($this->assetId);
        }

        return $this->_asset;
    }

    public function getIsAsset(): bool
    {
        return $this->kind === self::KIND_ASSET;
    }

    /** Whether there is anything here at all. An asset that has since been deleted is not. */
    public function getIsEmpty(): bool
    {
        return $this->getIsAsset() ? $this->getAsset() === null : trim($this->url) === '';
    }

    public function getFormat(): Format
    {
        return $this->_format ??= Plugin::getInstance()->formats->match($this->extension, $this->mimeType);
    }

    /** A human name for the document, when nobody has given it one. */
    public function getLabel(): string
    {
        $asset = $this->getIsAsset() ? $this->getAsset() : null;

        if ($asset) {
            return $asset->title ?: $asset->getFilename();
        }

        return $this->filename !== '' ? $this->filename : $this->url;
    }

    /**
     * The asset's own URL, if it has one.
     *
     * Null for a volume with no public URLs — which is not a problem, it is the case
     * {@see \justinholtweb\book\services\Delivery} exists for.
     */
    public function getAssetUrl(): ?string
    {
        return $this->getAsset()?->getUrl();
    }

    /** The raw URL for a URL source, absolute where it can be made so. */
    public function getSourceUrl(): ?string
    {
        if ($this->getIsAsset()) {
            return $this->getAssetUrl();
        }

        if ($this->url === '') {
            return null;
        }

        return str_starts_with($this->url, '/') ? UrlHelper::siteUrl($this->url) : $this->url;
    }

    /** Whether Book can read the bytes — i.e. whether the `inline` viewer is even possible. */
    public function getIsReadable(): bool
    {
        return $this->getIsAsset() && $this->getAsset() !== null;
    }

    /** The bytes, for the inliner. Assets only, and capped by the caller. */
    public function getContents(): ?string
    {
        $asset = $this->getAsset();

        if (!$asset) {
            return null;
        }

        try {
            return $asset->getContents();
        } catch (\Throwable $e) {
            Craft::warning('Book could not read ' . $this->filename . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    /** A printable size — “4.2 MB” — or an empty string when nobody knows. */
    public function getSizeLabel(): string
    {
        if ($this->size === null || $this->size <= 0) {
            return '';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int)floor(log(max(1, $this->size), 1024)), count($units) - 1);
        $value = $this->size / (1024 ** $power);

        return ($power === 0 ? (string)(int)$value : number_format($value, $value < 10 ? 1 : 0)) . ' ' . $units[$power];
    }

    /** @return array<string, mixed> */
    public function forStorage(): array
    {
        return $this->getIsAsset()
            ? ['kind' => self::KIND_ASSET, 'assetId' => $this->assetId]
            : ['kind' => self::KIND_URL, 'url' => $this->url];
    }
}
