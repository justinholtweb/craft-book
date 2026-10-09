<?php

namespace justinholtweb\book\fields;

use Craft;
use craft\fields\BaseRelationField;
use craft\helpers\Gql as GqlHelper;
use craft\models\GqlSchema;
use craft\services\Gql as GqlService;
use GraphQL\Type\Definition\Type;
use justinholtweb\book\elements\Document;
use justinholtweb\book\gql\arguments\DocumentArguments;
use justinholtweb\book\gql\DocumentQueries;
use justinholtweb\book\gql\interfaces\DocumentInterface;
use justinholtweb\book\gql\resolvers\DocumentResolver;

/**
 * A relation to one or more documents in the library.
 *
 * Deliberately a thin `BaseRelationField`: everything an author expects from a relation field —
 * the element selector, eager loading, the index, revisions — already works, and reimplementing
 * any of it would only make it work differently. GraphQL is the exception, because Craft cannot
 * know which schema permission covers somebody else's element type: the three methods below say
 * it is Book's own `bookDocuments.all`.
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

    /** Only in a schema that may read documents — the field would otherwise lead to them. */
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return GqlHelper::canSchema(DocumentQueries::SCOPE, 'read', $schema);
    }

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => Type::nonNull(Type::listOf(DocumentInterface::getType())),
            'args' => DocumentArguments::getArguments(),
            'resolve' => DocumentResolver::class . '::resolve',
            'complexity' => GqlHelper::relatedArgumentComplexity(GqlService::GRAPHQL_COMPLEXITY_EAGER_LOAD),
        ];
    }

    /**
     * `null` is Craft's "load nothing"; an empty array would mean "no conditions", which is the
     * opposite and would let an eager-loaded field reach documents the schema cannot.
     */
    public function getEagerLoadingGqlConditions(): ?array
    {
        if (!DocumentQueries::canQuery()) {
            return null;
        }

        return ['status' => [Document::STATUS_ENABLED]];
    }
}
