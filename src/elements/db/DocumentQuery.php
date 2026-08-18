<?php

namespace justinholtweb\book\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @method \justinholtweb\book\elements\Document[] all($db = null)
 * @method \justinholtweb\book\elements\Document|null one($db = null)
 * @method \justinholtweb\book\elements\Document|null nth(int $n, $db = null)
 */
class DocumentQuery extends ElementQuery
{
    public mixed $handle = null;

    public mixed $assetId = null;

    public mixed $url = null;

    public mixed $format = null;

    public mixed $viewer = null;

    public mixed $filename = null;

    public function handle(mixed $value): self
    {
        $this->handle = $value;

        return $this;
    }

    public function assetId(mixed $value): self
    {
        $this->assetId = $value;

        return $this;
    }

    public function url(mixed $value): self
    {
        $this->url = $value;

        return $this;
    }

    public function format(mixed $value): self
    {
        $this->format = $value;

        return $this;
    }

    public function viewer(mixed $value): self
    {
        $this->viewer = $value;

        return $this;
    }

    public function filename(mixed $value): self
    {
        $this->filename = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->handle === []) {
            return false;
        }

        $this->joinElementTable('book_documents');

        $this->query->select([
            'book_documents.handle',
            'book_documents.assetId',
            'book_documents.url',
            'book_documents.format',
            'book_documents.viewer',
            'book_documents.filename',
            'book_documents.config',
        ]);

        foreach (['handle', 'assetId', 'url', 'format', 'viewer', 'filename'] as $attribute) {
            if ($this->$attribute !== null) {
                $this->subQuery->andWhere(Db::parseParam("book_documents.$attribute", $this->$attribute));
            }
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            'enabled' => ['elements.enabled' => true],
            'disabled' => ['elements.enabled' => false],
            default => parent::statusCondition($status),
        };
    }
}
