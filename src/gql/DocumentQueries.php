<?php

namespace justinholtweb\book\gql;

use craft\helpers\Gql as GqlHelper;
use GraphQL\Type\Definition\Type;
use justinholtweb\book\gql\arguments\DocumentArguments;
use justinholtweb\book\gql\interfaces\DocumentInterface;
use justinholtweb\book\gql\resolvers\DocumentResolver;

/**
 * `bookDocuments`, `bookDocument` and `bookDocumentCount` — the library, for a headless front end.
 *
 * Prefixed `book` so they cannot collide with anybody else's `documents`. Registered only for a
 * schema that has been given `bookDocuments.all:read`; for any other schema they do not exist.
 */
class DocumentQueries
{
    /** The schema component, as it is stored in a schema's scope. */
    public const SCOPE = 'bookDocuments.all';

    /** @return array<string, array<string, mixed>> */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !self::canQuery()) {
            return [];
        }

        return [
            'bookDocuments' => [
                'type' => Type::listOf(DocumentInterface::getType()),
                'args' => DocumentArguments::getArguments(),
                'resolve' => DocumentResolver::class . '::resolve',
                'description' => 'This query is used to query for Book documents.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'bookDocumentCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => DocumentArguments::getArguments(),
                'resolve' => DocumentResolver::class . '::resolveCount',
                'description' => 'This query is used to return the number of Book documents.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
            'bookDocument' => [
                'type' => DocumentInterface::getType(),
                'args' => DocumentArguments::getArguments(),
                'resolve' => DocumentResolver::class . '::resolveOne',
                'description' => 'This query is used to query for a single Book document.',
                'complexity' => GqlHelper::relatedArgumentComplexity(),
            ],
        ];
    }

    /** Whether the active schema may read documents. */
    public static function canQuery(): bool
    {
        return GqlHelper::canSchema(self::SCOPE, 'read');
    }
}
