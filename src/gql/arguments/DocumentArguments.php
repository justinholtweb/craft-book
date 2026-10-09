<?php

namespace justinholtweb\book\gql\arguments;

use craft\gql\base\ElementArguments;
use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;

/**
 * Arguments for `bookDocuments`, `bookDocument`, `bookDocumentCount` and Documents fields — the
 * same criteria `craft.book.all()` takes in Twig.
 */
class DocumentArguments extends ElementArguments
{
    public static function getArguments(): array
    {
        return array_merge(parent::getArguments(), [
            'handle' => [
                'name' => 'handle',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results by document handle.',
            ],
            'format' => [
                'name' => 'format',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results by format handle — `pdf`, `word`, `csv`, …',
            ],
            'viewer' => [
                'name' => 'viewer',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results by the viewer the author chose (`auto` included).',
            ],
            'assetId' => [
                'name' => 'assetId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results by the asset the document points at.',
            ],
            'filename' => [
                'name' => 'filename',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results by filename.',
            ],
        ]);
    }
}
