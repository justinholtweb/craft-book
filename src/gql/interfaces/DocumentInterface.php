<?php

namespace justinholtweb\book\gql\interfaces;

use Craft;
use craft\gql\GqlEntityRegistry;
use craft\gql\interfaces\Element;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\Type;
use justinholtweb\book\gql\RenderArguments;
use justinholtweb\book\gql\types\generators\DocumentGenerator;
use justinholtweb\book\gql\types\ResolutionType;

/**
 * The GraphQL interface a Book document implements.
 *
 * Carries what `craft.book` gives a template: the facts about the file, the viewer Book would
 * pick here and why, the URLs it would hand that viewer — signed afresh for every request that
 * needs one — and the rendered markup itself.
 */
class DocumentInterface extends Element
{
    public static function getTypeGenerator(): string
    {
        return DocumentGenerator::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => static::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'This is the interface implemented by all Book documents.',
            'resolveType' => self::class . '::resolveElementTypeName',
        ]));

        DocumentGenerator::generateTypes();

        return $type;
    }

    public static function getName(): string
    {
        return 'BookDocumentInterface';
    }

    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions(array_merge(parent::getFieldDefinitions(), [
            'handle' => [
                'name' => 'handle',
                'type' => Type::string(),
                'description' => 'The document’s handle, as used in `{book:handle:render}`.',
            ],
            'sourceKind' => [
                'name' => 'sourceKind',
                'type' => Type::string(),
                'description' => '`asset` for a file in this site, `url` for somebody else’s.',
            ],
            'assetId' => [
                'name' => 'assetId',
                'type' => Type::int(),
                'description' => 'The ID of the asset, for a document that is one.',
            ],
            'sourceUrl' => [
                'name' => 'sourceUrl',
                'type' => Type::string(),
                'description' => 'The URL the author gave, for a document that is somebody else’s file.',
            ],
            'filename' => [
                'name' => 'filename',
                'type' => Type::string(),
                'description' => 'The file’s name.',
            ],
            'extension' => [
                'name' => 'extension',
                'type' => Type::string(),
                'description' => 'The file’s extension, lower case.',
            ],
            'mimeType' => [
                'name' => 'mimeType',
                'type' => Type::string(),
                'description' => 'The file’s MIME type, when known.',
            ],
            'size' => [
                'name' => 'size',
                'type' => Type::int(),
                'description' => 'The file’s size in bytes, when known.',
            ],
            'sizeLabel' => [
                'name' => 'sizeLabel',
                'type' => Type::string(),
                'description' => 'The file’s size for people — `2.4 MB`.',
            ],
            'format' => [
                'name' => 'format',
                'type' => Type::string(),
                'description' => 'The format handle — `pdf`, `word`, `csv`, …',
            ],
            'formatName' => [
                'name' => 'formatName',
                'type' => Type::string(),
                'description' => 'The format’s name — `PDF`, `Word document`, …',
            ],
            'formatGroup' => [
                'name' => 'formatGroup',
                'type' => Type::string(),
                'description' => '`document`, `spreadsheet`, `presentation`, `text`, `image`, `media` or `other`.',
            ],
            'viewer' => [
                'name' => 'viewer',
                'type' => Type::string(),
                'description' => 'The viewer the author chose, which may be `auto`. See `resolution` for the one that will be used.',
            ],
            'access' => [
                'name' => 'access',
                'type' => Type::string(),
                'description' => '`public`, or `login` when only a logged-in user may fetch the file.',
            ],
            'embedCode' => [
                'name' => 'embedCode',
                'type' => Type::string(),
                'description' => 'The reference tag to paste into a rich-text field.',
            ],
            'fileUrl' => [
                'name' => 'fileUrl',
                'type' => Type::string(),
                'description' => 'The URL to show the file at. Signed when the file needs it, freshly for each request.',
            ],
            'downloadUrl' => [
                'name' => 'downloadUrl',
                'type' => Type::string(),
                'description' => 'The URL a download button should point at. Signed when the file needs it.',
            ],
            'resolution' => [
                'name' => 'resolution',
                'type' => ResolutionType::getType(),
                'args' => RenderArguments::getArguments(),
                'description' => 'Which viewer Book would use here, with these options, and the URLs it would give it.',
            ],
            'html' => [
                'name' => 'html',
                'type' => Type::string(),
                'args' => RenderArguments::getArguments(),
                'description' => 'The rendered document, exactly as `{book:handle:render}` would write it. Null for a disabled document. Style it with Book’s `book.css`, and include `book.js` for click-to-load, the toolbar buttons and the load timeout.',
            ],
        ]), self::getName());
    }
}
