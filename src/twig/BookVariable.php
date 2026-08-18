<?php

namespace justinholtweb\book\twig;

use Craft;
use craft\elements\Asset;
use justinholtweb\book\elements\db\DocumentQuery;
use justinholtweb\book\elements\Document;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\Source;
use justinholtweb\book\models\Viewer;
use justinholtweb\book\models\ViewerResolution;
use justinholtweb\book\Plugin;
use justinholtweb\book\services\Delivery;
use Twig\Markup;

/**
 * `craft.book` — the template API.
 *
 * Every method is spelled one way only. A bare `documents()` beside a `getDocuments()` getter is
 * a trap in Twig, which resolves the bare method first and re-enters it until the C stack gives
 * out.
 */
class BookVariable
{
    /**
     * `craft.book.embed(entry.brochure)` — the one to reach for.
     *
     * Takes an asset, a URL, a library handle, a document, or an inline field value, and renders
     * whichever it turns out to be.
     */
    public function embed(mixed $value, array $options = []): Markup
    {
        return (new Extension())->render($value, $options);
    }

    /** `craft.book.document('annual-report')` — by handle or by id. */
    public function document(string|int|null $reference): ?Document
    {
        return Plugin::getInstance()->documents->resolve($reference);
    }

    /**
     * `{{ craft.book.render('annual-report') }}` — the short way to put a library document on a
     * page. An unknown handle costs you a document, not the page.
     */
    public function render(string|int|null $reference, array $options = []): Markup
    {
        $document = $this->document($reference);

        return $document ? $document->render($options) : $this->nothing();
    }

    /** `{{ craft.book.asset(entry.spec.one()) }}` — an asset, with no library entry needed. */
    public function asset(?Asset $asset, array $options = []): Markup
    {
        if (!$asset) {
            return $this->nothing();
        }

        return Plugin::getInstance()->renderer->renderSource(Source::fromAsset($asset), $this->options($options));
    }

    /** `{{ craft.book.url('https://example.com/report.pdf') }}` — somebody else's file. */
    public function url(?string $url, array $options = []): Markup
    {
        if (!$url) {
            return $this->nothing();
        }

        return Plugin::getInstance()->renderer->renderSource(Source::fromUrl($url), $this->options($options));
    }

    /** `craft.book.all()` — a normal element query, for listing or filtering. */
    public function all(array $criteria = []): DocumentQuery
    {
        return Plugin::getInstance()->documents->find($criteria);
    }

    /** The reference tag to paste into a rich-text field. */
    public function embedCode(string|int|null $reference, array $overrides = []): string
    {
        return $this->document($reference)?->getEmbedCode($overrides) ?? '';
    }

    // Asking rather than rendering
    // -------------------------------------------------------------------------

    /** What Book makes of a file: `craft.book.format(asset)` or `craft.book.format('x.docx')`. */
    public function format(Asset|string|null $file): ?Format
    {
        if ($file instanceof Asset) {
            return Source::fromAsset($file)->getFormat();
        }

        if (!$file) {
            return null;
        }

        return Source::fromUrl($file)->getFormat();
    }

    /**
     * What would actually happen if this were rendered here — which viewer, why, and what would
     * be lost. The same answer the CP shows an author.
     */
    public function resolve(Asset|string|Document|null $file, array $options = []): ?ViewerResolution
    {
        if ($file instanceof Document) {
            return $file->resolveViewer($options);
        }

        $source = $file instanceof Asset ? Source::fromAsset($file) : ($file ? Source::fromUrl($file) : null);

        return $source ? Plugin::getInstance()->viewers->resolve($source, $this->options($options)) : null;
    }

    /** The URL a download button would point at, for a template writing its own chrome. */
    public function downloadUrl(Asset|string|Document|null $file, array $options = []): ?string
    {
        if ($file instanceof Document) {
            return $file->getDownloadUrl();
        }

        $source = $file instanceof Asset ? Source::fromAsset($file) : ($file ? Source::fromUrl($file) : null);

        if (!$source) {
            return null;
        }

        return Plugin::getInstance()->delivery->fileUrl(
            $source,
            $this->options($options),
            Delivery::DISPOSITION_ATTACHMENT
        );
    }

    /** @return array<string, Format> */
    public function formats(): array
    {
        return Plugin::getInstance()->formats->getAll();
    }

    /** @return array<string, Viewer> */
    public function viewers(): array
    {
        return Plugin::getInstance()->viewers->getAll();
    }

    /** Every extension Book recognises — handy for an asset field's allowed-file-types hint. */
    public function extensions(): array
    {
        return Plugin::getInstance()->formats->getAllExtensions();
    }

    /** A fresh options model, for a template that wants to build one up. */
    public function options(array $values = []): DocumentOptions
    {
        return Plugin::getInstance()->getSettings()->getDefaultDocumentOptions()->merge($values);
    }

    private function nothing(): Markup
    {
        return new Markup('', Craft::$app->charset);
    }
}
