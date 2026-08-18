<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use justinholtweb\book\elements\db\DocumentQuery;
use justinholtweb\book\elements\Document;
use justinholtweb\book\Plugin;
use justinholtweb\book\records\DocumentRecord;
use Throwable;

/**
 * The library, and the authority on handles.
 */
class Documents extends Component
{
    /** @var array<string, Document|false> */
    private array $byHandle = [];

    public function find(array $criteria = []): DocumentQuery
    {
        $query = Document::find();
        Craft::configure($query, $criteria);

        return $query;
    }

    public function getDocumentById(int $id, ?int $siteId = null): ?Document
    {
        $document = Craft::$app->getElements()->getElementById($id, Document::class, $siteId);

        return $document instanceof Document ? $document : null;
    }

    public function getDocumentByHandle(string $handle, ?int $siteId = null): ?Document
    {
        $key = $handle . '|' . ($siteId ?? '*');

        if (!array_key_exists($key, $this->byHandle)) {
            $this->byHandle[$key] = Document::find()
                ->handle($handle)
                ->siteId($siteId)
                ->status(null)
                ->one() ?? false;
        }

        return $this->byHandle[$key] ?: null;
    }

    /**
     * A handle, an id, or an element — one method, so a template author never has to know which
     * they are holding.
     */
    public function resolve(mixed $reference, ?int $siteId = null): ?Document
    {
        if ($reference instanceof Document) {
            return $reference;
        }

        if (is_int($reference) || (is_string($reference) && ctype_digit($reference))) {
            return $this->getDocumentById((int)$reference, $siteId);
        }

        return is_string($reference) && $reference !== '' ? $this->getDocumentByHandle($reference, $siteId) : null;
    }

    public function saveDocument(Document $document, bool $runValidation = true): bool
    {
        $saved = Craft::$app->getElements()->saveElement($document, $runValidation);

        if ($saved) {
            $this->byHandle = [];
        }

        return $saved;
    }

    public function deleteDocument(Document $document): bool
    {
        $deleted = Craft::$app->getElements()->deleteElement($document);

        if ($deleted) {
            $this->byHandle = [];
        }

        return $deleted;
    }

    /**
     * A document built from an asset, ready to save.
     *
     * Used by the CP's “add from a file” path and by the editor integrations, so that pasting a
     * file into CKEditor produces the same thing as creating one by hand.
     */
    public function createFromAsset(Asset $asset): Document
    {
        $document = new Document();
        $document->setOptions(Plugin::getInstance()->getSettings()->getDefaultDocumentOptions());
        $document->setAsset($asset);
        $document->title = $asset->title ?: $asset->getFilename();

        return $document;
    }

    public function createFromUrl(string $url): Document
    {
        $document = new Document();
        $document->setOptions(Plugin::getInstance()->getSettings()->getDefaultDocumentOptions());
        $document->url = trim($url);
        $document->title = $document->getSource()->getLabel();

        return $document;
    }

    /**
     * The existing document for an asset, if there is one.
     *
     * Quick-create dedupes on this: ten authors dropping the same brochure into ten entries
     * should produce one library document, not ten.
     */
    public function findByAsset(Asset|int $asset): ?Document
    {
        return Document::find()
            ->assetId($asset instanceof Asset ? $asset->id : $asset)
            ->status(null)
            ->one();
    }

    public function findByUrl(string $url): ?Document
    {
        return Document::find()
            ->url(trim($url))
            ->status(null)
            ->one();
    }

    /**
     * The document for a file, creating it if it does not exist yet.
     *
     * @param array<string, mixed> $options
     */
    public function quickCreate(Asset|string $file, array $options = []): ?Document
    {
        $existing = $file instanceof Asset ? $this->findByAsset($file) : $this->findByUrl($file);

        if ($existing) {
            return $existing;
        }

        $document = $file instanceof Asset ? $this->createFromAsset($file) : $this->createFromUrl($file);

        if ($options) {
            $document->setOptions($document->getOptions()->merge($options));
        }

        try {
            return $this->saveDocument($document) ? $document : null;
        } catch (Throwable $e) {
            Craft::warning('Book could not quick-create a document: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return null;
        }
    }

    // Handles
    // -------------------------------------------------------------------------

    /**
     * Whether a handle is spoken for by a document that has not been deleted.
     *
     * An element query, not a row lookup: a trashed document keeps its row, and telling an
     * author their handle is taken by something they cannot see anywhere is worse than useless.
     */
    public function handleIsTaken(string $handle, ?int $exceptId = null): bool
    {
        $query = Document::find()->handle($handle)->status(null)->siteId('*')->unique();

        if ($exceptId) {
            $query->id("not $exceptId");
        }

        return $query->exists();
    }

    /** A free handle based on a name — `brochure`, then `brochure-2`, and so on. */
    public function uniqueHandle(string $name, ?int $exceptId = null): string
    {
        $base = StringHelper::toKebabCase(StringHelper::toAscii($name)) ?: 'document';

        // A handle has to start with a letter, and a filename like `2024-report.pdf` does not.
        if (!preg_match('/^[a-z]/', $base)) {
            $base = 'doc-' . $base;
        }

        $base = substr($base, 0, 50);
        $handle = $base;
        $suffix = 1;

        while ($this->handleIsTaken($handle, $exceptId)) {
            $handle = $base . '-' . ++$suffix;
        }

        return $handle;
    }

    // Index support
    // -------------------------------------------------------------------------

    /**
     * How many live documents there are of each format.
     *
     * Straight SQL against the row table rather than a count per format through the element
     * query: the index draws its sources on every request, and eighteen counting queries to
     * decide which headings to show is eighteen too many.
     *
     * @return array<string, int>
     */
    public function countByFormat(): array
    {
        try {
            $rows = (new Query())
                ->select(['documents.format', 'count' => 'COUNT(*)'])
                ->from(['documents' => DocumentRecord::TABLE])
                ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[documents.id]]')
                ->where(['elements.dateDeleted' => null])
                ->groupBy(['documents.format'])
                ->pairs();
        } catch (Throwable) {
            // Before the install migration has run, there is no table and no documents.
            return [];
        }

        return array_map('intval', $rows);
    }

    /** Every document that points at an asset which has since gone. */
    public function findOrphans(): array
    {
        $orphans = [];

        foreach (Document::find()->status(null)->all() as $document) {
            if ($document->getSource()->getIsEmpty()) {
                $orphans[] = $document;
            }
        }

        return $orphans;
    }

    /** Options as posted by a CP form, where every checkbox is a string. */
    public function normalizePostedOptions(array $posted): array
    {
        $out = [];

        foreach ($posted as $key => $value) {
            if (is_array($value)) {
                // Craft's `lightswitchField` posts a hidden `0` before the real value, and its
                // checkbox groups post arrays; take the last meaningful entry either way.
                $value = end($value);
            }

            $out[$key] = $value;
        }

        return $out;
    }

}
