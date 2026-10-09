<?php

namespace justinholtweb\book\gql\types;

use craft\gql\types\elements\Element;
use GraphQL\Type\Definition\ResolveInfo;
use justinholtweb\book\elements\Document;
use justinholtweb\book\gql\interfaces\DocumentInterface;
use justinholtweb\book\gql\RenderArguments;
use justinholtweb\book\gql\resolvers\DocumentResolver;
use justinholtweb\book\Plugin;

/**
 * The GraphQL object type for a Book document.
 *
 * Every URL here is minted while the query runs, by the same {@see \justinholtweb\book\services\Delivery}
 * a template uses — so a signed URL carries the document's own access rule and the site's own
 * lifetime, and a login-gated file is still a login-gated file when the link is followed.
 */
class DocumentType extends Element
{
    public function __construct(array $config)
    {
        $config['interfaces'] = [
            DocumentInterface::getType(),
        ];

        parent::__construct($config);
    }

    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Document $source */
        $file = $source->getSource();

        return match ($resolveInfo->fieldName) {
            'sourceKind' => $file->getIsEmpty() ? null : $file->kind,
            'sourceUrl' => $source->url,
            'filename' => $file->filename ?: null,
            'extension' => $file->extension ?: null,
            'mimeType' => $file->mimeType,
            'size' => $file->size,
            'sizeLabel' => $file->getSizeLabel() ?: null,
            'format' => $source->getDocumentFormat()->handle,
            'formatName' => $source->getDocumentFormat()->name,
            'formatGroup' => $source->getDocumentFormat()->group,
            'access' => $source->getOptions()->access,
            'embedCode' => $source->getEmbedCode(),
            'fileUrl' => Plugin::getInstance()->delivery->fileUrl($file, $source->getOptions()),
            'downloadUrl' => $source->getDownloadUrl(),
            'resolution' => $source->resolveViewer(RenderArguments::overrides($arguments)),
            'html' => $source->enabled ? (string)$source->render(RenderArguments::overrides($arguments)) : null,
            default => parent::resolve($source, $arguments, $context, $resolveInfo),
        };
    }

    protected static function elementResolverClass(): ?string
    {
        return DocumentResolver::class;
    }
}
