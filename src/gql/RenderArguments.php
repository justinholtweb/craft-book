<?php

namespace justinholtweb\book\gql;

use GraphQL\Type\Definition\Type;
use justinholtweb\book\models\DocumentOptions;

/**
 * The per-call options `html` and `resolution` take — the GraphQL spelling of
 * `{book:x:render(google,height=900)}` and `document.render({…})`.
 *
 * One difference from Twig, and it is the point of this class: a template is written by the site,
 * a GraphQL query by whoever holds the token. So **how a file is delivered is not an option here.**
 * `access` and `serveThroughCraft` are the author's decision about who may fetch the file; were a
 * query allowed to set `access: public`, it could mint a link to a login-gated document that no
 * longer asks for a login. Both are dropped, from the typed arguments (which do not offer them) and
 * from the free-form `options` string (which could otherwise smuggle them in).
 */
class RenderArguments
{
    /** Options a query may never override. */
    public const LOCKED = ['access', 'serveThroughCraft'];

    /** @return array<string, array<string, mixed>> */
    public static function getArguments(): array
    {
        return [
            'viewer' => [
                'name' => 'viewer',
                'type' => Type::string(),
                'description' => '`auto`, `native`, `office`, `google`, `inline` or `link`.',
            ],
            'fallback' => [
                'name' => 'fallback',
                'type' => Type::boolean(),
                'description' => 'Whether an unusable viewer may quietly become a usable one.',
            ],
            'height' => [
                'name' => 'height',
                'type' => Type::int(),
                'description' => 'Height in pixels, unless `ratio` is set.',
            ],
            'ratio' => [
                'name' => 'ratio',
                'type' => Type::string(),
                'description' => '`8.5:11`, `16:9`, … Beats `height`.',
            ],
            'width' => [
                'name' => 'width',
                'type' => Type::string(),
                'description' => 'Any CSS width.',
            ],
            'align' => [
                'name' => 'align',
                'type' => Type::string(),
                'description' => '`left`, `center`, `right`, `wide` or `full`.',
            ],
            'loading' => [
                'name' => 'loading',
                'type' => Type::string(),
                'description' => '`eager`, `lazy` or `click`.',
            ],
            'toolbar' => [
                'name' => 'toolbar',
                'type' => Type::boolean(),
                'description' => 'Book’s bar above the document.',
            ],
            'download' => [
                'name' => 'download',
                'type' => Type::boolean(),
                'description' => 'A download button.',
            ],
            'page' => [
                'name' => 'page',
                'type' => Type::int(),
                'description' => 'The page a PDF opens at.',
            ],
            'title' => [
                'name' => 'title',
                'type' => Type::string(),
                'description' => 'The displayed name and the frame’s accessible name.',
            ],
            'options' => [
                'name' => 'options',
                'type' => Type::string(),
                'description' => 'Any other option, in reference-tag syntax (`google,height=900,no-toolbar`) or as a JSON object. `access` and `serveThroughCraft` are ignored.',
            ],
        ];
    }

    /**
     * The overrides a query asked for, with the locked ones taken out.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function overrides(array $arguments): array
    {
        $overrides = [];
        $free = $arguments['options'] ?? null;
        unset($arguments['options']);

        if (is_string($free) && trim($free) !== '') {
            $decoded = json_decode($free, true);
            $overrides = is_array($decoded) ? $decoded : DocumentOptions::parseEmbedOptions($free);
        }

        // The typed arguments win over the free-form string, being the more deliberate of the two.
        foreach ($arguments as $key => $value) {
            if ($value !== null) {
                $overrides[$key] = $value;
            }
        }

        foreach (array_keys($overrides) as $key) {
            if (!is_string($key) || in_array($key, self::LOCKED, true)) {
                unset($overrides[$key]);
            }
        }

        return $overrides;
    }
}
