<?php

namespace justinholtweb\book\twig;

use Craft;
use craft\elements\Asset;
use justinholtweb\book\elements\Document;
use justinholtweb\book\models\InlineDocument;
use justinholtweb\book\models\Source;
use justinholtweb\book\Plugin;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The `book` filter and function.
 *
 * `{{ asset|book }}`, `{{ 'https://example.com/report.pdf'|book }}`, `{{ 'brochure'|book }}`,
 * `{{ entry.specSheet|book({ viewer: 'google' }) }}` and `{{ book('brochure') }}` all land on the
 * same renderer, so a template author never has to know whether they are holding an asset, a
 * URL, a handle, an element or a field value.
 */
class Extension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('book', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('book', [$this, 'render'], ['is_safe' => ['html']]),
        ];
    }

    public function render(mixed $value, array $options = []): Markup
    {
        $plugin = Plugin::getInstance();

        if ($value instanceof Document || $value instanceof InlineDocument) {
            return $value->render($options);
        }

        if ($value instanceof Asset) {
            return $plugin->renderer->renderSource(
                Source::fromAsset($value),
                $plugin->getSettings()->getDefaultDocumentOptions()->merge($options)
            );
        }

        // An asset relation, unresolved: `{{ entry.brochure|book }}` where `brochure` is an
        // Assets field is the most natural thing to type, and it arrives here as a query.
        if ($value instanceof \craft\elements\db\AssetQuery) {
            $asset = $value->one();

            return $asset ? $this->render($asset, $options) : $this->nothing();
        }

        if (is_string($value) && (preg_match('~^https?://~i', trim($value)) || str_starts_with(trim($value), '/'))) {
            return $plugin->renderer->renderSource(
                Source::fromUrl(trim($value)),
                $plugin->getSettings()->getDefaultDocumentOptions()->merge($options)
            );
        }

        if (is_string($value) || is_int($value)) {
            $document = $plugin->documents->resolve($value);

            if ($document) {
                return $document->render($options);
            }
        }

        return $this->nothing();
    }

    private function nothing(): Markup
    {
        return new Markup('', Craft::$app->charset);
    }
}
