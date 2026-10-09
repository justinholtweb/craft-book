<?php

namespace justinholtweb\book\gql\types;

use Craft;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use justinholtweb\book\models\ViewerResolution;

/**
 * `BookViewerResolution` — what `craft.book.resolve()` answers, for GraphQL.
 *
 * The explanations (`warnings`, `fallbackReason`) are written for the author, and Book only ever
 * puts them on a page under dev mode; GraphQL keeps the same rule, so a production API does not
 * describe the site's settings and staging hosts to whoever holds a public token.
 */
class ResolutionType
{
    public static function getName(): string
    {
        return 'BookViewerResolution';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'Which viewer Book will use for a document here, and the URLs it will hand it.',
            'fields' => [
                'viewer' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The viewer that will be used — never `auto`.',
                    'resolve' => fn(ViewerResolution $r) => $r->viewer,
                ],
                'requested' => [
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The viewer that was asked for, which may not be the one used.',
                    'resolve' => fn(ViewerResolution $r) => $r->requested,
                ],
                'wasDowngraded' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the requested viewer could not be used.',
                    'resolve' => fn(ViewerResolution $r) => $r->getWasDowngraded(),
                ],
                'isThirdParty' => [
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the reader’s browser will load the document from Google or Microsoft.',
                    'resolve' => fn(ViewerResolution $r) => $r->getIsThirdParty(),
                ],
                'documentUrl' => [
                    'type' => Type::string(),
                    'description' => 'The URL of the file itself, as handed to the viewer.',
                    'resolve' => fn(ViewerResolution $r) => $r->documentUrl,
                ],
                'frameUrl' => [
                    'type' => Type::string(),
                    'description' => 'What an `<iframe src>` should be. Null when the viewer does not use a frame.',
                    'resolve' => fn(ViewerResolution $r) => $r->frameUrl,
                ],
                'downloadUrl' => [
                    'type' => Type::string(),
                    'description' => 'The URL a download button should point at.',
                    'resolve' => fn(ViewerResolution $r) => $r->downloadUrl,
                ],
                'warnings' => [
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'What an author should know about this embed. Empty unless dev mode is on.',
                    'resolve' => fn(ViewerResolution $r) => self::devMode() ? array_values($r->warnings) : [],
                ],
                'fallbackReason' => [
                    'type' => Type::string(),
                    'description' => 'Why the requested viewer was not used. Null unless dev mode is on.',
                    'resolve' => fn(ViewerResolution $r) => self::devMode() ? $r->fallbackReason : null,
                ],
            ],
        ]));
    }

    private static function devMode(): bool
    {
        return Craft::$app->getConfig()->getGeneral()->devMode;
    }
}
