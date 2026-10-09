<?php

namespace justinholtweb\book\gql\resolvers;

use craft\elements\db\ElementQuery;
use craft\elements\ElementCollection;
use craft\gql\base\ElementResolver;
use justinholtweb\book\elements\Document;
use justinholtweb\book\gql\DocumentQueries;
use yii\base\UnknownMethodException;

/**
 * Resolves document queries for a schema that may read documents, and nothing for one that may not.
 *
 * **Only enabled documents.** Disabling a document is how an author takes a file off every page
 * at once, and a headless front end is one more page. There is no `status` argument to ask
 * with, and the query is pinned to enabled anyway, for whatever else calls this resolver.
 */
class DocumentResolver extends ElementResolver
{
    public static function prepareQuery(mixed $source, array $arguments, ?string $fieldName = null): mixed
    {
        $query = $source === null ? Document::find() : $source->$fieldName;

        // Eager-loaded already: nothing left to narrow.
        if (!$query instanceof ElementQuery) {
            return $query;
        }

        if (!DocumentQueries::canQuery()) {
            return ElementCollection::empty();
        }

        unset($arguments['status']);

        foreach ($arguments as $key => $value) {
            try {
                $query->$key($value);
            } catch (UnknownMethodException $e) {
                if ($value !== null) {
                    throw $e;
                }
            }
        }

        $query->status(Document::STATUS_ENABLED);

        return $query;
    }
}
