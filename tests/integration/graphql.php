<?php
/**
 * Book integration checks — GraphQL.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-book/tests/integration/graphql.php
 *
 * Every check executes a real query through `Craft::$app->getGql()->executeQuery()` against an
 * in-memory schema, so the permissions are Craft's own reading of the scope, not ours.
 *
 * **One PHP process per schema.** Craft builds its GraphQL type registry once per process, and
 * flushing it and executing again fails ("multiple types named QueryArgument"). So this script
 * makes the fixtures, runs itself once per scenario as a child process, totals what the children
 * report, and cleans up. Self-cleaning like `checks.php`: the same `book-check-` prefix, the same
 * sweep, settings changed only in memory and only in a child.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\models\GqlSchema;
use justinholtweb\book\elements\Document;
use justinholtweb\book\fields\DocumentsField;
use justinholtweb\book\gql\DocumentQueries;
use justinholtweb\book\gql\resolvers\DocumentResolver;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\Plugin;
use justinholtweb\book\services\Delivery;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";

            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

$plugin = Plugin::getInstance();

if (!$plugin) {
    echo "Book is not installed in this site.\n";
    exit(1);
}

$scenario = null;
$fixtures = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--scenario=')) {
        $scenario = substr($arg, 11);
    } elseif (str_starts_with($arg, '--fixtures=')) {
        $fixtures = json_decode(base64_decode(substr($arg, 11)), true);
    }
}

// =============================================================================
// Child: one schema, one process.
// =============================================================================

if ($scenario !== null) {
    $general = Craft::$app->getConfig()->getGeneral();
    // A cached result would answer the second query with the first one's settings.
    $general->enableGraphqlCaching = false;
    $general->devMode = $scenario === 'dev';

    if ($scenario === 'signed') {
        $plugin->getSettings()->signedUrls = true;
        $plugin->getSettings()->signedUrlDuration = 3600;
    }

    if (in_array('--as-admin', $argv, true)) {
        $admin = User::find()->admin(true)->status(null)->one() ?? throw new RuntimeException('No admin user.');
        Craft::$app->getUser()->setIdentity($admin);
    }

    $scope = $scenario === 'none' ? [] : [DocumentQueries::SCOPE . ':read'];
    $schema = new GqlSchema(['name' => "Book check ($scenario)", 'scope' => $scope, 'uid' => StringHelper::UUID()]);
    $gql = Craft::$app->getGql();
    $gql->setActiveSchema($schema);

    $run = fn(string $query, array $variables = []): array => $gql->executeQuery($schema, $query, $variables);

    $one = function(string $handle, string $fields) use ($run): array {
        $result = $run("query(\$h: [String]) { bookDocument(handle: \$h) { $fields } }", ['h' => [$handle]]);

        if (!empty($result['errors'])) {
            throw new RuntimeException(json_encode($result['errors']));
        }

        return $result['data']['bookDocument'] ?? throw new RuntimeException('no document: ' . json_encode($result));
    };

    $f = $fixtures;
    $allHandles = array_values($f['handles']);

    section("Scenario: $scenario");

    if ($scenario === 'none') {
        check('a schema without the permission has no document queries', function() use ($run, $allHandles) {
            // Craft drops an unknown root field rather than erroring, so this asserts on absence.
            $result = $run('query($h: [String]) { bookDocuments(handle: $h) { id title } bookDocumentCount(handle: $h) }', ['h' => $allHandles]);

            return !isset($result['data']['bookDocuments']) && !isset($result['data']['bookDocumentCount']) ?: json_encode($result);
        });

        check('…and the resolver finds nothing even when called directly', function() {
            $value = DocumentResolver::prepareQuery(null, []);

            return $value instanceof craft\elements\ElementCollection && $value->isEmpty() ?: get_debug_type($value);
        });

        check('…and a Documents field is left out of it, and eager-loads nothing', function() use ($schema) {
            $field = new DocumentsField(['handle' => 'bookCheckDocs']);

            return !$field->includeInGqlSchema($schema) && $field->getEagerLoadingGqlConditions() === null;
        });
    }

    if ($scenario === 'full' || $scenario === 'signed' || $scenario === 'login') {
        check('the document queries exist and resolve to the BookDocument type', function() use ($run, $f) {
            $result = $run('query($h: [String]) { bookDocument(handle: $h) { __typename id handle } }', ['h' => [$f['handles']['pdf']]]);
            $doc = $result['data']['bookDocument'] ?? null;

            return ($doc['__typename'] ?? null) === 'BookDocument' && (int)$doc['id'] === $f['ids']['pdf'] ?: json_encode($result);
        });
    }

    if ($scenario === 'full') {
        check('a Documents field is in a schema that may read documents', function() use ($schema) {
            $field = new DocumentsField(['handle' => 'bookCheckDocs']);
            $type = $field->getContentGqlType();

            return $field->includeInGqlSchema($schema)
                && $field->getEagerLoadingGqlConditions() === ['status' => ['enabled']]
                && str_contains((string)$type['type'], 'BookDocumentInterface') ?: json_encode([(string)$type['type'], $field->getEagerLoadingGqlConditions()]);
        });

        check('bookDocuments lists the enabled documents, and only those', function() use ($run, $f, $allHandles) {
            $result = $run('query($h: [String]) { bookDocuments(handle: $h, orderBy: "title") { handle } }', ['h' => $allHandles]);
            $got = array_column($result['data']['bookDocuments'] ?? [], 'handle');
            sort($got);
            $want = array_values(array_diff($allHandles, [$f['handles']['disabled']]));
            sort($want);

            return $got === $want ?: json_encode($result);
        });

        check('a disabled document cannot be reached by id, by handle or by asking for its status', function() use ($run, $f) {
            $byId = $run('query($id: [QueryArgument]) { bookDocument(id: $id) { id } }', ['id' => [$f['ids']['disabled']]]);
            $byHandle = $run('query($h: [String]) { bookDocuments(handle: $h) { id } bookDocumentCount(handle: $h) }', ['h' => [$f['handles']['disabled']]]);
            $byStatus = $run('query($h: [String]) { bookDocuments(handle: $h, status: "disabled") { id } }', ['h' => [$f['handles']['disabled']]]);

            return array_key_exists('bookDocument', $byId['data'] ?? []) && $byId['data']['bookDocument'] === null
                && ($byHandle['data']['bookDocuments'] ?? null) === [] && ($byHandle['data']['bookDocumentCount'] ?? null) === 0
                && !empty($byStatus['errors']) && empty($byStatus['data']['bookDocuments'])
                ?: json_encode([$byId, $byHandle, $byStatus]);
        });

        check('…and the resolver pins enabled even when a caller passes status itself', function() {
            $query = DocumentResolver::prepareQuery(null, ['status' => 'disabled']);

            return $query instanceof craft\elements\db\ElementQuery && $query->status === 'enabled' ?: var_export($query->status ?? null, true);
        });

        check('bookDocumentCount and the format argument agree with the fixtures', function() use ($run, $allHandles) {
            $result = $run('query($h: [String]) { all: bookDocumentCount(handle: $h) csv: bookDocumentCount(handle: $h, format: "csv") }', ['h' => $allHandles]);

            return ($result['data']['all'] ?? null) === 4 && ($result['data']['csv'] ?? null) === 2 ?: json_encode($result);
        });

        check('the facts about the file come through', function() use ($one, $f) {
            $doc = $one($f['handles']['pdf'], 'sourceKind assetId sourceUrl filename extension format formatName formatGroup viewer access embedCode size sizeLabel');

            $ok = $doc['sourceKind'] === 'asset'
                && $doc['assetId'] === $f['assets']['pdf']
                && $doc['sourceUrl'] === null
                && str_ends_with((string)$doc['filename'], 'report.pdf')
                && $doc['extension'] === 'pdf'
                && $doc['format'] === 'pdf'
                && $doc['formatName'] === 'PDF document'
                && $doc['formatGroup'] === 'document'
                && $doc['viewer'] === 'native'
                && $doc['access'] === 'public'
                && $doc['embedCode'] === '{book:' . $f['handles']['pdf'] . ':render}'
                && $doc['size'] > 0
                && $doc['sizeLabel'] !== null;

            return $ok ?: json_encode($doc);
        });

        check('a URL document says where it is and is not an asset', function() use ($one, $f) {
            $doc = $one($f['handles']['url'], 'sourceKind assetId sourceUrl format fileUrl');

            return $doc['sourceKind'] === 'url' && $doc['assetId'] === null
                && $doc['sourceUrl'] === 'https://example.com/book-check/brochure.docx'
                && $doc['fileUrl'] === $doc['sourceUrl'] && $doc['format'] === 'word' ?: json_encode($doc);
        });

        check('fileUrl and downloadUrl are the URLs a template would get', function() use ($one, $f, $plugin) {
            $doc = $one($f['handles']['pdf'], 'fileUrl downloadUrl');
            $element = Document::find()->id($f['ids']['pdf'])->one();

            return $doc['fileUrl'] === $plugin->delivery->fileUrl($element->getSource(), $element->getOptions())
                && $doc['downloadUrl'] === $element->getDownloadUrl()
                && $doc['downloadUrl'] !== null ?: json_encode($doc);
        });

        check('html is the rendered document, as a reference tag would write it', function() use ($one, $f) {
            $doc = $one($f['handles']['pdf'], 'html');

            return str_contains((string)$doc['html'], 'class="book book--native')
                && str_contains((string)$doc['html'], '<figure') ?: substr((string)$doc['html'], 0, 300);
        });

        check('html takes per-call options, typed and in reference-tag syntax', function() use ($one, $f) {
            $typed = $one($f['handles']['pdf'], 'html(viewer: "link", toolbar: false)');
            $tag = $one($f['handles']['pdf'], 'html(options: "link,no-toolbar")');
            $json = $one($f['handles']['pdf'], 'html(options: "{\"viewer\":\"link\"}")');

            foreach (['typed' => $typed, 'tag' => $tag, 'json' => $json] as $name => $doc) {
                if (!str_contains((string)$doc['html'], 'book--link') || !str_contains((string)$doc['html'], 'book-card')) {
                    return "$name: " . substr((string)$doc['html'], 0, 300);
                }
            }

            return !str_contains((string)$typed['html'], 'book-toolbar') ?: 'the toolbar survived toolbar: false';
        });

        check('a CSV is rendered inline into html', function() use ($one, $f) {
            $doc = $one($f['handles']['csv'], 'html resolution { viewer }');

            return $doc['resolution']['viewer'] === 'inline'
                && str_contains((string)$doc['html'], '<table')
                && str_contains((string)$doc['html'], 'Charlotte') ?: json_encode($doc);
        });

        check('resolution reports the viewer and the URLs it would use', function() use ($one, $f) {
            $doc = $one($f['handles']['pdf'], 'resolution { viewer requested wasDowngraded isThirdParty documentUrl frameUrl downloadUrl warnings fallbackReason }');
            $r = $doc['resolution'];

            return $r['viewer'] === 'native' && $r['requested'] === 'native' && $r['wasDowngraded'] === false
                && $r['isThirdParty'] === false && $r['documentUrl'] !== null
                && str_starts_with((string)$r['frameUrl'], (string)$r['documentUrl']) ?: json_encode($r);
        });

        check('outside dev mode the author-facing explanations are withheld', function() use ($one, $f) {
            $r = $one($f['handles']['pdf'], 'resolution(viewer: "inline", fallback: true) { viewer wasDowngraded warnings fallbackReason }')['resolution'];

            return $r['wasDowngraded'] === true && $r['warnings'] === [] && $r['fallbackReason'] === null ?: json_encode($r);
        });

        section('Security: the access rule');

        check('a login-gated document’s URLs carry a login token', function() use ($one, $f, $plugin) {
            $doc = $one($f['handles']['login'], 'fileUrl downloadUrl access');

            foreach (['fileUrl', 'downloadUrl'] as $key) {
                parse_str((string)parse_url((string)$doc[$key], PHP_URL_QUERY), $query);
                $result = $plugin->delivery->verify($query[Delivery::PARAM] ?? null, $f['uids']['login']);

                if (!$result['valid'] || $result['access'] !== 'login') {
                    return "$key: " . json_encode([$doc[$key], $result]);
                }
            }

            return $doc['access'] === 'login' ?: json_encode($doc);
        });

        check('a query cannot override access or delivery, typed, as a tag or as JSON', function() use ($one, $f, $plugin) {
            $attempts = [
                'resolution(options: "access=public,serve-through-craft=false") { documentUrl downloadUrl }',
                'resolution(options: "{\"access\":\"public\",\"serveThroughCraft\":false}") { documentUrl downloadUrl }',
            ];

            foreach ($attempts as $fields) {
                $r = $one($f['handles']['login'], $fields)['resolution'];

                foreach (['documentUrl', 'downloadUrl'] as $key) {
                    parse_str((string)parse_url((string)$r[$key], PHP_URL_QUERY), $query);
                    $result = $plugin->delivery->verify($query[Delivery::PARAM] ?? null, $f['uids']['login']);

                    if (!$result['valid'] || $result['access'] !== 'login') {
                        return "$fields → $key " . json_encode([$r[$key], $result]);
                    }
                }
            }

            return true;
        });

        check('…and `access` is not an argument at all', function() use ($run, $f) {
            $result = $run('query($h: [String]) { bookDocument(handle: $h) { html(access: "public") } }', ['h' => [$f['handles']['login']]]);

            return !empty($result['errors']) && !isset($result['data']['bookDocument']['html']) ?: json_encode($result);
        });

        check('a guest never gets a login-gated file’s contents written into html', function() use ($one, $f) {
            $doc = $one($f['handles']['login'], 'html resolution { viewer }');

            return !str_contains((string)$doc['html'], 'TOPSECRET')
                && str_contains((string)$doc['html'], 'book-card')
                && $doc['resolution']['viewer'] === 'link' ?: json_encode($doc);
        });

        check('…not even when the query asks for the inline viewer', function() use ($one, $f) {
            $doc = $one($f['handles']['login'], 'html(viewer: "inline", fallback: false)');

            return !str_contains((string)$doc['html'], 'TOPSECRET') ?: substr((string)$doc['html'], 0, 300);
        });

        check('a guest gets the same answer from Twig as from GraphQL', function() use ($f) {
            $html = (string)Craft::$app->getView()->renderString('{{ craft.book.render(h) }}', ['h' => $f['handles']['login']]);

            return !str_contains($html, 'TOPSECRET') && str_contains($html, 'book-card') ?: substr($html, 0, 300);
        });
    }

    if ($scenario === 'login') {
        check('a logged-in user does get the login-gated CSV inline', function() use ($one, $f) {
            $doc = $one($f['handles']['login'], 'html resolution { viewer }');

            return $doc['resolution']['viewer'] === 'inline' && str_contains((string)$doc['html'], 'TOPSECRET') ?: json_encode($doc);
        });
    }

    if ($scenario === 'dev') {
        check('in dev mode the explanations come through, as they do on the page', function() use ($run, $f) {
            $result = $run('query($h: [String]) { bookDocument(handle: $h) { resolution(viewer: "inline") { viewer wasDowngraded warnings fallbackReason } } }', ['h' => [$f['handles']['pdf']]]);
            $r = $result['data']['bookDocument']['resolution'] ?? null;

            return $r && $r['wasDowngraded'] === true && $r['warnings'] !== [] && $r['fallbackReason'] !== null ?: json_encode($result);
        });
    }

    if ($scenario === 'signed') {
        check('with signed URLs on, every URL is signed afresh and expires', function() use ($one, $f, $plugin) {
            $doc = $one($f['handles']['pdf'], 'fileUrl downloadUrl');

            foreach (['fileUrl', 'downloadUrl'] as $key) {
                parse_str((string)parse_url((string)$doc[$key], PHP_URL_QUERY), $query);
                $token = $query[Delivery::PARAM] ?? '';
                $expires = (int)explode('.', $token)[0];
                $result = $plugin->delivery->verify($token, $f['uids']['pdf']);

                if (!$result['valid'] || $expires < time() + 3500 || $expires > time() + 3700) {
                    return "$key: " . json_encode([$doc[$key], $result]);
                }
            }

            return true;
        });

        check('an expiring URL shortens the cache that holds it to half its lifetime', function() use ($f, $plugin) {
            $elements = Craft::$app->getElements();
            $element = Document::find()->id($f['ids']['pdf'])->one();
            $elements->startCollectingCacheInfo();
            $plugin->delivery->fileUrl($element->getSource(), $element->getOptions());
            [, $duration] = $elements->stopCollectingCacheInfo();

            return $duration !== null && $duration > 1700 && $duration <= 1800 ?: var_export($duration, true);
        });
    }

    echo "\n@@RESULT $passed $failed\n";
    exit($failed === 0 ? 0 : 1);
}

// =============================================================================
// Parent: fixtures, children, cleanup.
// =============================================================================

$suffix = substr(md5((string)microtime(true)), 0, 6);
$general = Craft::$app->getConfig()->getGeneral();
$general->allowedFileExtensions = array_values(array_unique(array_merge($general->allowedFileExtensions, ['csv'])));

/** @var Asset[] $assets */
$assets = [];

$makeAsset = function(string $filename, string $contents) use (&$assets, $suffix): Asset {
    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? throw new RuntimeException('No volume.');
    $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);
    $temp = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . AssetsHelper::prepareAssetName($filename);
    FileHelper::writeToFile($temp, $contents);

    $asset = new Asset();
    $asset->tempFilePath = $temp;
    $asset->setFilename("book-check-$suffix-$filename");
    $asset->newFolderId = $folder->id;
    $asset->setVolumeId($volume->id);
    $asset->setScenario(Asset::SCENARIO_CREATE);

    if (!Craft::$app->getElements()->saveElement($asset)) {
        throw new RuntimeException('Could not save the test asset: ' . json_encode($asset->getErrors()));
    }

    return $assets[] = $asset;
};

$makeDocument = function(string $handle, array $attributes, array $options = []) use ($plugin): Document {
    $document = new Document();
    $document->handle = $handle;
    $document->title = "Book check $handle";

    foreach ($attributes as $key => $value) {
        $document->$key = $value;
    }

    $document->setOptions(DocumentOptions::fromArray($options));

    if (!$plugin->documents->saveDocument($document)) {
        throw new RuntimeException('Could not save the test document: ' . json_encode($document->getErrors()));
    }

    return $document;
};

section('Fixtures');

$exit = 0;

try {
    $pdf = $makeAsset('report.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
    $csv = $makeAsset('cities.csv', "city,state\nCharlotte,NC\nRaleigh,NC\n");
    $secret = $makeAsset('secret.csv', "code,value\nTOPSECRET,42\n");

    $docs = [
        'pdf' => $makeDocument("gqlPdf$suffix", ['assetId' => $pdf->id], ['viewer' => 'native']),
        'csv' => $makeDocument("gqlCsv$suffix", ['assetId' => $csv->id], ['viewer' => 'inline']),
        'login' => $makeDocument("gqlLogin$suffix", ['assetId' => $secret->id], ['viewer' => 'inline', 'access' => 'login']),
        'url' => $makeDocument("gqlUrl$suffix", ['url' => 'https://example.com/book-check/brochure.docx']),
        'disabled' => $makeDocument("gqlOff$suffix", ['assetId' => $pdf->id, 'enabled' => false]),
    ];

    echo "  made " . count($assets) . " assets and " . count($docs) . " documents\n";

    $fixtures = [
        'handles' => array_map(fn(Document $d) => $d->handle, $docs),
        'ids' => array_map(fn(Document $d) => $d->id, $docs),
        'assets' => ['pdf' => $pdf->id],
        'uids' => ['pdf' => $pdf->uid, 'login' => $secret->uid],
    ];

    // The no-access schema first, as the family's GraphQL notes advise; each in its own process.
    foreach (['none', 'full', 'dev', 'signed', 'login'] as $name) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
            . ' --scenario=' . $name
            . ' --fixtures=' . base64_encode(json_encode($fixtures));

        if ($name === 'login') {
            $command .= ' --as-admin';
        }

        $output = [];
        exec($command . ' 2>&1', $output);
        $text = implode("\n", $output);

        if (preg_match('/@@RESULT (\d+) (\d+)/', $text, $m)) {
            $passed += (int)$m[1];
            $failed += (int)$m[2];
            echo preg_replace('/\n?@@RESULT.*$/s', '', $text) . "\n";
        } else {
            $failed++;
            echo "\n  ✗ scenario $name did not finish\n" . $text . "\n";
        }
    }
} catch (Throwable $e) {
    $failed++;
    echo "  ✗ fixtures: " . $e->getMessage() . "\n";
} finally {
    section('Cleanup');

    foreach (Document::find()->status(null)->siteId('*')->unique()->all() as $stray) {
        if (str_starts_with((string)$stray->handle, 'gql') && str_contains((string)$stray->title, 'Book check')) {
            Craft::$app->getElements()->deleteElement($stray, true);
        }
    }

    foreach (Asset::find()->filename('book-check-*')->status(null)->all() as $stray) {
        Craft::$app->getElements()->deleteElement($stray, true);
    }

    echo "  swept\n";
}

echo "\n$passed passed, $failed failed\n";

exit($failed === 0 ? 0 : 1);
