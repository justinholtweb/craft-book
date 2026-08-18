<?php

namespace justinholtweb\book\models;

use Craft;
use craft\base\Model;
use craft\elements\Asset;
use craft\helpers\Json;
use justinholtweb\book\Plugin;
use Twig\Markup;

/**
 * A document that has no element behind it — one file, configured where it is used.
 *
 * The value of {@see \justinholtweb\book\fields\DocumentField}. Deliberately renders through the
 * same {@see \justinholtweb\book\services\Renderer} as a library document, so a template author
 * never has to know which kind they are holding: both answer to `{{ entry.brochure }}`.
 */
class InlineDocument extends Model
{
    public ?int $assetId = null;

    public string $url = '';

    private ?DocumentOptions $_options = null;

    private ?Source $_source = null;

    public static function fromValue(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value instanceof Asset) {
            $document = new self();
            $document->assetId = $value->id;

            return $document;
        }

        if (is_string($value)) {
            $decoded = Json::decodeIfJson($value);
            $value = is_array($decoded) ? $decoded : ['url' => $value];
        }

        $document = new self();

        if (is_array($value)) {
            $assetId = $value['assetId'] ?? null;

            // An element select posts `['12']`, and a blank one posts `['']`.
            if (is_array($assetId)) {
                $assetId = reset($assetId) ?: null;
            }

            $document->assetId = $assetId ? (int)$assetId : null;
            $document->url = trim((string)($value['url'] ?? ''));
            $document->setOptions($value['options'] ?? null);
        }

        return $document;
    }

    public function setOptions(mixed $value): void
    {
        $this->_options = $value instanceof DocumentOptions
            ? $value
            : DocumentOptions::fromArray(is_array($value) || is_string($value) ? $value : null);
    }

    public function getOptions(): DocumentOptions
    {
        if ($this->_options === null) {
            $this->_options = Plugin::getInstance()->getSettings()->getDefaultDocumentOptions();
        }

        return $this->_options;
    }

    public function getAsset(): ?Asset
    {
        return $this->assetId ? Craft::$app->getAssets()->getAssetById($this->assetId) : null;
    }

    public function getSource(): Source
    {
        if ($this->_source === null) {
            $asset = $this->getAsset();

            if ($asset) {
                $this->_source = Source::fromAsset($asset);
            } elseif ($this->url !== '') {
                $this->_source = Source::fromUrl($this->url);
            } else {
                $this->_source = new Source();
            }
        }

        return $this->_source;
    }

    public function getIsEmpty(): bool
    {
        return !$this->assetId && trim($this->url) === '';
    }

    public function getDocumentFormat(): Format
    {
        return $this->getSource()->getFormat();
    }

    public function resolveViewer(array $overrides = []): ViewerResolution
    {
        return Plugin::getInstance()->viewers->resolve($this->getSource(), $this->getOptions()->merge($overrides));
    }

    public function render(array $overrides = []): Markup
    {
        if ($this->getIsEmpty()) {
            return new Markup('', Craft::$app->charset);
        }

        return Plugin::getInstance()->renderer->renderSource($this->getSource(), $this->getOptions()->merge($overrides));
    }

    /** So `{{ entry.brochure }}` on its own renders it, which is what everyone types first. */
    public function __toString(): string
    {
        return (string)$this->render();
    }

    /** @return array<string, mixed> */
    public function forStorage(): array
    {
        return [
            'assetId' => $this->assetId,
            'url' => $this->url,
            'options' => $this->getOptions()->toStorageArray(),
        ];
    }
}
