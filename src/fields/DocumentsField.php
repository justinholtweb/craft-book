<?php

namespace justinholtweb\book\fields;

use Craft;
use craft\fields\BaseRelationField;
use justinholtweb\book\elements\Document;

/**
 * A relation to one or more documents in the library.
 *
 * Deliberately a thin `BaseRelationField`: everything an author expects from a relation field —
 * the element selector, eager loading, the index, GraphQL, revisions — already works, and
 * reimplementing any of it would only make it work differently.
 */
class DocumentsField extends BaseRelationField
{
    public static function displayName(): string
    {
        return Craft::t('book', 'Documents');
    }

    public static function icon(): string
    {
        return 'book';
    }

    public static function elementType(): string
    {
        return Document::class;
    }

    public static function defaultSelectionLabel(): string
    {
        return Craft::t('book', 'Add a document');
    }
}
