<?php
/**
 * Book integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-book/tests/integration/checks.php
 *
 * Covers what unit fixtures cannot: real assets on a real volume, real element saves, reference
 * tags resolved through Craft's own parser, the render pipeline end to end, and — the parts that
 * matter most — that viewer resolution refuses what it is supposed to refuse and that a delivery
 * token cannot be edited into something it was not signed for.
 *
 * Idempotent and self-cleaning: every element it creates is deleted on the way out, including
 * strays from a run that died half way, and the plugin settings it changes are put back.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use justinholtweb\book\elements\Document;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\InlineDocument;
use justinholtweb\book\models\Source;
use justinholtweb\book\models\Viewer;
use justinholtweb\book\Plugin;
use justinholtweb\book\services\Delivery;

$passed = 0;
$failed = 0;

/** @var Asset[] $assets */
$assets = [];
/** @var Document[] $documents */
$documents = [];

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

$suffix = substr(md5((string)microtime(true)), 0, 6);
$originalSettings = $plugin->getSettings()->toArray();

// Craft's upload allowlist is a site's own decision and is usually narrower than the set of
// formats Book recognises — `md` and `json` are not on the default list. Widened for this
// process only; nothing is written to config.
// `extraAllowedFileExtensions` is a *setter* that merges into the real list, so assigning the
// property does nothing; the list itself is the thing to extend.
$general = Craft::$app->getConfig()->getGeneral();
$general->allowedFileExtensions = array_values(array_unique(array_merge(
    $general->allowedFileExtensions,
    ['md', 'markdown', 'json', 'tsv', 'yaml', 'yml', 'log']
)));

/** Applies settings for the duration of one check, without going near project config. */
function withSettings(array $values, callable $body): mixed
{
    $settings = Plugin::getInstance()->getSettings();
    $before = [];

    foreach ($values as $key => $value) {
        $before[$key] = $settings->$key;
        $settings->$key = $value;
    }

    try {
        return $body();
    } finally {
        foreach ($before as $key => $value) {
            $settings->$key = $value;
        }
    }
}

/** A real asset on a real volume, because half of Book's behaviour depends on one. */
function makeAsset(string $filename, string $contents): Asset
{
    global $assets, $suffix;

    $volume = Craft::$app->getVolumes()->getAllVolumes()[0] ?? null;

    if (!$volume) {
        throw new RuntimeException('This site has no asset volumes, so Book cannot be checked against one.');
    }

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

    $assets[] = $asset;

    return $asset;
}

function makeDocument(array $attributes, array $options = []): Document
{
    global $documents;

    $document = new Document();

    foreach ($attributes as $key => $value) {
        $document->$key = $value;
    }

    $document->setOptions(DocumentOptions::fromArray($options));

    if (!Plugin::getInstance()->documents->saveDocument($document)) {
        throw new RuntimeException('Could not save the test document: ' . json_encode($document->getErrors()));
    }

    $documents[] = $document;

    return $document;
}

// -----------------------------------------------------------------------------
section('Formats');

check('a PDF is recognised by extension', function() use ($plugin) {
    $format = $plugin->formats->match('pdf');

    return $format->handle === 'pdf' && $format->nativeFrame ?: "got $format->handle";
});

check('an extension wins over a misleading MIME type', function() use ($plugin) {
    // A .pdf served as application/octet-stream is still a PDF, and this is the common case for
    // anything behind a download script.
    return $plugin->formats->match('pdf', 'application/octet-stream')->handle === 'pdf';
});

check('a MIME type answers when there is no extension', function() use ($plugin) {
    return $plugin->formats->match('', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')->handle === 'word';
});

check('an unknown text/* MIME type still renders as text', function() use ($plugin) {
    return $plugin->formats->match('', 'text/x-something-new')->handle === 'text';
});

check('an unknown file falls off the end to `other`', function() use ($plugin) {
    $format = $plugin->formats->match('qqq');

    return $format->handle === 'other' && $format->getIsOther() ?: "got $format->handle";
});

check('every format ends its viewer list with the download card', function() use ($plugin) {
    foreach ($plugin->formats->getAll() as $handle => $format) {
        if (!in_array(Viewer::LINK, $format->viewers, true)) {
            return "$handle cannot fall back to a download card";
        }
    }

    return true;
});

check('Office formats prefer the Office viewer over Google', function() use ($plugin) {
    foreach (['word', 'excel', 'powerpoint'] as $handle) {
        $viewers = $plugin->formats->getByHandle($handle)->viewers;

        if (array_search(Viewer::OFFICE, $viewers, true) > array_search(Viewer::GOOGLE, $viewers, true)) {
            return "$handle prefers Google";
        }
    }

    return true;
});

check('OpenDocument does not offer the Office viewer, which cannot read it', function() use ($plugin) {
    return !$plugin->formats->getByHandle('opendocument')->supports(Viewer::OFFICE);
});

check('text-shaped formats can be rendered by Book itself', function() use ($plugin) {
    foreach (['csv', 'markdown', 'text', 'data', 'code'] as $handle) {
        if (!$plugin->formats->getByHandle($handle)->getIsInlineable()) {
            return "$handle is not inlineable";
        }
    }

    return true;
});

check('no extension is claimed by two formats', function() use ($plugin) {
    $seen = [];

    foreach ($plugin->formats->getAll() as $handle => $format) {
        foreach ($format->extensions as $extension) {
            if (isset($seen[$extension])) {
                return "$extension is claimed by both {$seen[$extension]} and $handle";
            }

            $seen[$extension] = $handle;
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
section('Options');

check('defaults are an automatic, lazy, toolbar-ed document', function() {
    $options = new DocumentOptions();

    return $options->viewer === Viewer::AUTO && $options->loading === 'lazy' && $options->toolbar
        ?: "got $options->viewer / $options->loading";
});

check('ratios are accepted in every spelling an author might type', function() {
    foreach (['8.5:11' => '8.5 / 11', '16x9' => '16 / 9', '4/3' => '4 / 3', '1.5' => '1.5 / 1'] as $input => $expected) {
        $options = DocumentOptions::fromArray(['ratio' => $input]);

        if ($options->getAspectRatio() !== $expected) {
            return "$input became " . var_export($options->getAspectRatio(), true);
        }
    }

    return true;
});

check('an unknown viewer name is ignored rather than stored', function() {
    return DocumentOptions::fromArray(['viewer' => 'lasercopier'])->viewer === Viewer::AUTO;
});

check('storage keeps only what differs from the defaults', function() {
    $stored = DocumentOptions::fromArray(['viewer' => 'google', 'height' => 720])->toStorageArray();

    // 720 is the default height, so storing it would freeze this document against a later
    // change of mind about the default.
    return $stored === ['viewer' => 'google'] ?: json_encode($stored);
});

check('a reference tag’s bare words mean what they look like', function() {
    $parsed = DocumentOptions::parseEmbedOptions('google,click,full,8.5:11,no-toolbar,height=900');

    return $parsed === [
        'viewer' => 'google',
        'loading' => 'click',
        'align' => 'full',
        'ratio' => '8.5:11',
        'toolbar' => false,
        'height' => '900',
    ] ?: json_encode($parsed);
});

check('a hyphenated option key is the same key as a camel-cased one', function() {
    $parsed = DocumentOptions::parseEmbedOptions('pdf-toolbar=0,csv_header=1');

    return array_keys($parsed) === ['pdfToolbar', 'csvHeader'] ?: json_encode($parsed);
});

check('merge never mutates the original', function() {
    $original = new DocumentOptions();
    $merged = $original->merge(['height' => 300]);

    return $original->height === 720 && $merged->height === 300 ?: "$original->height / $merged->height";
});

check('the PDF fragment is only written when something asked for it', function() {
    $quiet = (new DocumentOptions())->getPdfFragment();
    $loud = DocumentOptions::fromArray(['pdfToolbar' => false, 'page' => 4, 'zoom' => 'page-width'])->getPdfFragment();

    return $quiet === '' && $loud === '#toolbar=0&navpanes=0&page=4&view=FitH' ?: "'$quiet' / '$loud'";
});

// -----------------------------------------------------------------------------
section('Public reachability');

check('a local development host is not reachable from the internet', function() use ($plugin) {
    foreach ([
        'https://plugin-testing.ddev.site/x.pdf',
        'http://localhost/x.pdf',
        'https://example.test/x.pdf',
        'https://thing.local/x.pdf',
        'http://192.168.1.10/x.pdf',
        'http://127.0.0.1/x.pdf',
        'http://10.0.0.4/x.pdf',
        '/uploads/x.pdf',
        '',
    ] as $url) {
        if ($plugin->delivery->isPubliclyReachable($url)) {
            return "$url was judged reachable";
        }
    }

    return true;
});

check('a real host is', function() use ($plugin) {
    return $plugin->delivery->isPubliclyReachable('https://example.com/reports/annual.pdf');
});

check('credentials in a URL make it unusable, because a viewer would be handed them', function() use ($plugin) {
    return !$plugin->delivery->isPubliclyReachable('https://user:pass@example.com/x.pdf');
});

// -----------------------------------------------------------------------------
section('Delivery tokens');

check('a token verifies for the uid, disposition and access it was signed for', function() use ($plugin) {
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_ATTACHMENT, 'login', 0);
    $result = $plugin->delivery->verify($token, 'abc-123');

    return $result['valid'] && $result['access'] === 'login' && $result['disposition'] === 'attachment'
        ?: json_encode($result);
});

check('a token for one file does not verify for another', function() use ($plugin) {
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_INLINE, 'public', 0);

    return !$plugin->delivery->verify($token, 'def-456')['valid'];
});

check('editing the access half of a token invalidates it', function() use ($plugin) {
    // The whole point: the access rule travels in the token because a query parameter would be
    // a suggestion rather than a decision.
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_INLINE, 'login', 0);
    $tampered = str_replace('.login.', '.public.', $token);

    return !$plugin->delivery->verify($tampered, 'abc-123')['valid'];
});

check('editing the disposition half of a token invalidates it', function() use ($plugin) {
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_INLINE, 'public', 0);
    $tampered = str_replace('.inline.', '.attachment.', $token);

    return !$plugin->delivery->verify($tampered, 'abc-123')['valid'];
});

check('an expired token is refused', function() use ($plugin) {
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_INLINE, 'public', time() - 60);
    $result = $plugin->delivery->verify($token, 'abc-123');

    return !$result['valid'] && $result['reason'] === 'expired' ?: json_encode($result);
});

check('extending the expiry of a token invalidates it', function() use ($plugin) {
    $expires = time() - 60;
    $token = $plugin->delivery->sign('abc-123', Delivery::DISPOSITION_INLINE, 'public', $expires);
    $tampered = (time() + 3600) . substr($token, strlen((string)$expires));

    return !$plugin->delivery->verify($tampered, 'abc-123')['valid'];
});

check('a missing or malformed token fails closed, at the strictest access', function() use ($plugin) {
    foreach ([null, '', 'nonsense', '0.public.inline', '0.mystery.inline.abcd'] as $token) {
        $result = $plugin->delivery->verify($token, 'abc-123');

        if ($result['valid'] || $result['access'] !== 'login') {
            return 'accepted ' . var_export($token, true);
        }
    }

    return true;
});

// -----------------------------------------------------------------------------
section('Sources and delivery URLs');

$pdf = makeAsset('report.pdf', "%PDF-1.4\n% a checks fixture\n");
$csv = makeAsset('rows.csv', "Name,Role,Joined\nAda,Engineer,1843\nGrace,Admiral,1944\n\"Comma, in a cell\",Tester,2026\n");
$md = makeAsset('notes.md', "# Notes\n\nSome *prose*.\n\n<script>alert(1)</script>\n");
$json = makeAsset('data.json', '{"b":2,"a":[1,2,3]}');

check('an asset source knows its filename, extension, size and format', function() use ($pdf) {
    $source = Source::fromAsset($pdf);

    return $source->getIsAsset()
        && $source->extension === 'pdf'
        && $source->getFormat()->handle === 'pdf'
        && $source->size > 0
        && $source->getSizeLabel() !== ''
        ?: json_encode([$source->extension, $source->getFormat()->handle, $source->size]);
});

check('a URL source reads its format out of the path', function() {
    $source = Source::fromUrl('https://example.com/files/2026%20budget.xlsx?v=2');

    return $source->getFormat()->handle === 'excel' && $source->extension === 'xlsx'
        ?: $source->getFormat()->handle . ' / ' . $source->extension;
});

check('a URL source cannot be read, so Book will not try to inline it', function() {
    return !Source::fromUrl('https://example.com/x.csv')->getIsReadable();
});

check('a public asset is embedded at its own volume URL by default', function() use ($pdf, $plugin) {
    $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), new DocumentOptions());

    return $url === $pdf->getUrl() ?: "got $url";
});

check('the download URL goes through Craft, because a volume URL cannot say “attachment”', function() use ($pdf, $plugin) {
    $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), new DocumentOptions(), Delivery::DISPOSITION_ATTACHMENT);

    return str_contains((string)$url, 'book/file/' . $pdf->uid) && str_contains((string)$url, 'dl=1') ?: "got $url";
});

check('“serve through Craft” moves the embed URL onto Book’s own route', function() use ($pdf, $plugin) {
    $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), DocumentOptions::fromArray(['serveThroughCraft' => true]));

    return str_contains((string)$url, 'book/file/' . $pdf->uid) ?: "got $url";
});

check('a login-gated document always gets a token', function() use ($pdf, $plugin) {
    $options = DocumentOptions::fromArray(['access' => 'login']);
    $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), $options);

    return str_contains((string)$url, Delivery::PARAM . '=') ?: "got $url";
});

check('the token carries the access rule it was minted for', function() use ($pdf, $plugin) {
    $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), DocumentOptions::fromArray(['access' => 'login']));
    parse_str((string)parse_url((string)$url, PHP_URL_QUERY), $query);
    $result = $plugin->delivery->verify($query[Delivery::PARAM] ?? null, $pdf->uid);

    return $result['valid'] && $result['access'] === 'login' ?: json_encode($result);
});

check('the signature parameter is not one Craft has already claimed', function() {
    // `p` is Craft's pathParam and `token` is its preview token: either would be read as
    // something else entirely, and the route would 404 before any controller ran.
    return !in_array(Delivery::PARAM, ['p', 'token'], true);
});

check('signed URLs expire when the setting says so', function() use ($pdf, $plugin) {
    return withSettings(['signedUrls' => true, 'signedUrlDuration' => 60], function() use ($pdf, $plugin) {
        $url = $plugin->delivery->fileUrl(Source::fromAsset($pdf), new DocumentOptions());
        parse_str((string)parse_url((string)$url, PHP_URL_QUERY), $query);
        [$expires] = explode('.', (string)($query[Delivery::PARAM] ?? '0'));

        return (int)$expires > time() ?: "expiry was $expires";
    });
});

check('a file on a volume with no public URLs is served by Book, with a token that never expires', function() use ($pdf, $plugin) {
    // The private-volume case, without needing a private volume: everything downstream asks
    // the source whether the asset has a URL of its own, and this one says no.
    $private = new class extends Source {
        public function getAssetUrl(): ?string
        {
            return null;
        }
    };

    $private->kind = Source::KIND_ASSET;
    $private->assetId = $pdf->id;
    $private->filename = $pdf->getFilename();
    $private->extension = 'pdf';
    $private->setAsset($pdf);

    $options = new DocumentOptions();
    $url = $plugin->delivery->fileUrl($private, $options);

    if (!str_contains((string)$url, 'book/file/' . $pdf->uid)) {
        return "got $url";
    }

    parse_str((string)parse_url((string)$url, PHP_URL_QUERY), $query);
    $token = $query[Delivery::PARAM] ?? null;
    $result = $plugin->delivery->verify($token, $pdf->uid);

    // No expiry, because a private-volume URL that quietly expired would break every page
    // embedding it, later, for no reason anybody could see.
    return $result['valid'] && str_starts_with((string)$token, '0.') ?: json_encode([$url, $result]);
});

check('…and a third-party viewer is warned about, because it would publish the file', function() use ($pdf, $plugin) {
    $private = new class extends Source {
        public function getAssetUrl(): ?string
        {
            return null;
        }
    };

    $private->kind = Source::KIND_ASSET;
    $private->assetId = $pdf->id;
    $private->filename = $pdf->getFilename();
    $private->extension = 'pdf';
    $private->setAsset($pdf);

    return $plugin->delivery->getWouldExposePrivateFile($private)
        && !$plugin->delivery->getWouldExposePrivateFile(Source::fromAsset($pdf));
});

check('a URL document is passed along untouched', function() use ($plugin) {
    $url = 'https://example.com/annual.pdf';

    return $plugin->delivery->fileUrl(Source::fromUrl($url), new DocumentOptions()) === $url;
});

// -----------------------------------------------------------------------------
section('Viewer resolution');

check('a PDF gets the browser’s own viewer, and nobody else is involved', function() use ($pdf, $plugin) {
    $resolution = $plugin->viewers->resolve(Source::fromAsset($pdf), new DocumentOptions());

    return $resolution->viewer === Viewer::NATIVE && !$resolution->getIsThirdParty() && $resolution->frameUrl
        ?: "got $resolution->viewer";
});

check('a CSV is rendered by Book rather than sent anywhere', function() use ($csv, $plugin) {
    return $plugin->viewers->resolve(Source::fromAsset($csv), new DocumentOptions())->viewer === Viewer::INLINE;
});

check('a Word document on a local URL cannot use Office, and says so', function() use ($plugin) {
    $source = Source::fromUrl('https://plugin-testing.ddev.site/files/spec.docx');
    $resolution = $plugin->viewers->resolve($source, DocumentOptions::fromArray(['viewer' => Viewer::OFFICE]));

    return $resolution->viewer === Viewer::LINK
        && $resolution->getWasDowngraded()
        && str_contains(implode(' ', $resolution->warnings), 'not reachable from the public internet')
        ?: "$resolution->viewer / " . json_encode($resolution->warnings);
});

check('the same document on a public URL does use Office, with a viewer URL', function() use ($plugin) {
    $source = Source::fromUrl('https://example.com/files/spec.docx');
    $resolution = $plugin->viewers->resolve($source, new DocumentOptions());

    return $resolution->viewer === Viewer::OFFICE
        && str_starts_with((string)$resolution->frameUrl, 'https://view.officeapps.live.com/op/embed.aspx?src=')
        && str_contains((string)$resolution->frameUrl, rawurlencode('https://example.com/files/spec.docx'))
        ?: "$resolution->viewer / $resolution->frameUrl";
});

check('turning a third-party viewer off in the settings takes it out of `auto`', function() use ($plugin) {
    return withSettings(['allowOfficeViewer' => false, 'allowGoogleViewer' => false], function() use ($plugin) {
        $resolution = $plugin->viewers->resolve(Source::fromUrl('https://example.com/files/spec.docx'), new DocumentOptions());

        return $resolution->viewer === Viewer::LINK ?: "got $resolution->viewer";
    });
});

check('…and refuses an explicit choice of it, with the reason', function() use ($plugin) {
    return withSettings(['allowGoogleViewer' => false], function() use ($plugin) {
        $resolution = $plugin->viewers->resolve(
            Source::fromUrl('https://example.com/files/spec.odt'),
            DocumentOptions::fromArray(['viewer' => Viewer::GOOGLE])
        );

        return $resolution->viewer === Viewer::LINK
            && str_contains(implode(' ', $resolution->warnings), 'switched off')
            ?: "$resolution->viewer / " . json_encode($resolution->warnings);
    });
});

check('a login-gated document is never handed to a viewer that fetches it as nobody', function() use ($pdf, $plugin) {
    $resolution = $plugin->viewers->resolve(
        Source::fromAsset($pdf),
        DocumentOptions::fromArray(['viewer' => Viewer::GOOGLE, 'access' => 'login'])
    );

    return $resolution->viewer !== Viewer::GOOGLE
        && str_contains(implode(' ', $resolution->warnings), 'as nobody')
        ?: "$resolution->viewer / " . json_encode($resolution->warnings);
});

check('fallback off leaves an impossible choice as a download card, not as another viewer', function() use ($plugin) {
    $source = Source::fromUrl('https://plugin-testing.ddev.site/files/spec.docx');
    $resolution = $plugin->viewers->resolve($source, DocumentOptions::fromArray([
        'viewer' => Viewer::OFFICE,
        'fallback' => false,
    ]));

    return $resolution->viewer === Viewer::LINK ?: "got $resolution->viewer";
});

check('a format nobody can render resolves to a download card without complaining', function() use ($plugin) {
    $resolution = $plugin->viewers->resolve(Source::fromUrl('https://example.com/archive.zip'), new DocumentOptions());

    return $resolution->viewer === Viewer::LINK && $resolution->warnings === []
        ?: "$resolution->viewer / " . json_encode($resolution->warnings);
});

check('an explicitly chosen viewer that suits the format is used as asked', function() use ($plugin) {
    $resolution = withSettings(['allowGoogleViewer' => true], fn() => $plugin->viewers->resolve(
        Source::fromUrl('https://example.com/annual.pdf'),
        DocumentOptions::fromArray(['viewer' => Viewer::GOOGLE])
    ));

    return $resolution->viewer === Viewer::GOOGLE
        && str_starts_with((string)$resolution->frameUrl, 'https://docs.google.com/gview?')
        ?: "$resolution->viewer / $resolution->frameUrl";
});

check('an empty source resolves to nothing, with an explanation', function() use ($plugin) {
    $resolution = $plugin->viewers->resolve(new Source(), new DocumentOptions());

    return $resolution->viewer === Viewer::LINK && $resolution->warnings !== [];
});

check('a file larger than the inline limit is not read', function() use ($csv, $plugin) {
    return withSettings(['inlineMaxBytes' => 8], function() use ($csv, $plugin) {
        $resolution = $plugin->viewers->resolve(Source::fromAsset($csv), new DocumentOptions());

        return $resolution->viewer !== Viewer::INLINE ?: 'inlined anyway';
    });
});

// -----------------------------------------------------------------------------
section('Inline rendering');

check('a CSV becomes a table, with the first row as headers', function() use ($csv, $plugin) {
    [$html, $error] = $plugin->inliner->render(Source::fromAsset($csv), new DocumentOptions());

    return $error === null
        && str_contains((string)$html, '<th scope="col">Name</th>')
        && str_contains((string)$html, '<td>Ada</td>')
        ?: ($error ?? substr((string)$html, 0, 200));
});

check('a quoted comma stays inside its cell', function() use ($csv, $plugin) {
    [$html] = $plugin->inliner->render(Source::fromAsset($csv), new DocumentOptions());

    return str_contains((string)$html, '<td>Comma, in a cell</td>') ?: substr((string)$html, 0, 400);
});

check('cells are escaped, not rendered', function() use ($plugin) {
    $asset = makeAsset('xss.csv', "Header\n<img src=x onerror=alert(1)>\n");
    [$html] = $plugin->inliner->render(Source::fromAsset($asset), new DocumentOptions());

    return !str_contains((string)$html, '<img') && str_contains((string)$html, '&lt;img')
        ?: substr((string)$html, 0, 300);
});

check('the row limit is honoured and admitted to', function() use ($csv, $plugin) {
    [$html] = $plugin->inliner->render(Source::fromAsset($csv), DocumentOptions::fromArray([
        'maxRows' => 2,
        'csvHeader' => true,
    ]));

    return substr_count((string)$html, '<tr>') === 2 && str_contains((string)$html, 'first 2 rows')
        ?: substr((string)$html, 0, 400);
});

check('Markdown becomes prose', function() use ($md, $plugin) {
    [$html, $error] = $plugin->inliner->render(Source::fromAsset($md), new DocumentOptions());

    return $error === null && str_contains((string)$html, '<h1') && str_contains((string)$html, '<em>prose</em>')
        ?: ($error ?? substr((string)$html, 0, 300));
});

check('a script tag in a Markdown file does not survive', function() use ($md, $plugin) {
    // Markdown allows raw HTML by design, and this file came off an asset volume — which is
    // where uploads land.
    [$html] = $plugin->inliner->render(Source::fromAsset($md), new DocumentOptions());

    return !str_contains((string)$html, '<script') ?: substr((string)$html, 0, 300);
});

check('JSON is pretty-printed', function() use ($json, $plugin) {
    [$html] = $plugin->inliner->render(Source::fromAsset($json), new DocumentOptions());

    return str_contains((string)$html, '&quot;a&quot;: [') ?: substr((string)$html, 0, 200);
});

check('a semicolon-separated file is read as one', function() use ($plugin) {
    $asset = makeAsset('euro.csv', "Name;Amount\nAda;1,5\nGrace;2,5\n");
    [$html] = $plugin->inliner->render(Source::fromAsset($asset), new DocumentOptions());

    return str_contains((string)$html, '<td>1,5</td>') ?: substr((string)$html, 0, 300);
});

check('Windows-1252 text is repaired rather than mangled', function() use ($plugin) {
    $asset = makeAsset('latin.csv', "Name\nCaf" . chr(0xE9) . "\n");
    [$html] = $plugin->inliner->render(Source::fromAsset($asset), new DocumentOptions());

    return str_contains((string)$html, 'Café') ?: substr((string)$html, 0, 200);
});

check('a BOM does not end up in the first header', function() use ($plugin) {
    $asset = makeAsset('bom.csv', "\xEF\xBB\xBFName,Role\nAda,Engineer\n");
    [$html] = $plugin->inliner->render(Source::fromAsset($asset), new DocumentOptions());

    return str_contains((string)$html, '<th scope="col">Name</th>') ?: substr((string)$html, 0, 300);
});

check('a URL cannot be inlined, and the refusal explains why', function() use ($plugin) {
    [$html, $error] = $plugin->inliner->render(Source::fromUrl('https://example.com/x.csv'), new DocumentOptions());

    return $html === null && str_contains((string)$error, 'asset') ?: var_export([$html, $error], true);
});

check('a format Book cannot read is refused before anything is opened', function() use ($pdf, $plugin) {
    [$html, $error] = $plugin->inliner->render(Source::fromAsset($pdf), new DocumentOptions());

    return $html === null && $error !== null ?: var_export([$html, $error], true);
});

// -----------------------------------------------------------------------------
section('The Document element');

$document = makeDocument(['assetId' => $pdf->id, 'title' => "Annual report $suffix"]);

check('a document derives its format, filename and handle on save', function() use ($document, $suffix) {
    return $document->format === 'pdf'
        && $document->filename === "book-check-$suffix-report.pdf"
        && $document->handle !== null
        ?: json_encode([$document->format, $document->filename, $document->handle]);
});

check('a handle that starts with a digit is made legal rather than rejected', function() use ($plugin) {
    $handle = $plugin->documents->uniqueHandle('2026 results');

    return preg_match('/^[a-z]/', $handle) === 1 ?: "got $handle";
});

check('a second document cannot take a handle that is in use', function() use ($document) {
    $clash = new Document();
    $clash->url = 'https://example.com/other.pdf';
    $clash->handle = $document->handle;

    return !Craft::$app->getElements()->saveElement($clash) && $clash->hasErrors('handle');
});

check('a document must point at exactly one thing', function() use ($pdf) {
    $neither = new Document();
    $both = new Document();
    $both->assetId = $pdf->id;
    $both->url = 'https://example.com/x.pdf';

    // `both` has its URL cleared in beforeSave, which is the intended resolution — the error
    // that matters is the one for a document pointing at nothing at all.
    return !Craft::$app->getElements()->saveElement($neither) && $neither->hasErrors('assetId');
});

check('a URL that is not one is refused', function() {
    $document = new Document();
    $document->url = 'not a url';

    return !Craft::$app->getElements()->saveElement($document) && $document->hasErrors('url');
});

check('a site-relative URL is allowed, because plenty of files live at one', function() {
    $document = new Document();
    $document->url = '/uploads/handbook.pdf';
    $document->validate();

    return !$document->hasErrors('url') ?: json_encode($document->getErrors('url'));
});

check('the reference tag is the handle', function() use ($document) {
    return $document->getEmbedCode() === '{book:' . $document->handle . ':render}';
});

check('a reference tag can carry options', function() use ($document) {
    return $document->getEmbedCode(['viewer' => 'google', 'toolbar' => false])
        === '{book:' . $document->handle . ':render(viewer=google,no-toolbar)}';
});

check('deleting a document frees its handle immediately', function() use ($plugin, $suffix) {
    $document = makeDocument(['url' => "https://example.com/temp-$suffix.pdf", 'handle' => "temp-$suffix"]);
    Craft::$app->getElements()->deleteElement($document);

    // A soft-deleted row keeps its handle unless something parks it, and “that handle is taken”
    // pointing at a document nobody can see is worse than useless.
    return !$plugin->documents->handleIsTaken("temp-$suffix");
});

check('restoring a document takes its handle back', function() use ($plugin, $suffix) {
    $document = makeDocument(['url' => "https://example.com/restore-$suffix.pdf", 'handle' => "restore-$suffix"]);
    Craft::$app->getElements()->deleteElement($document);
    Craft::$app->getElements()->restoreElement($document);

    $restored = $plugin->documents->getDocumentByHandle("restore-$suffix");

    return $restored !== null && $restored->id === $document->id ?: 'not restored under its handle';
});

check('a document whose asset is deleted is left standing, not deleted with it', function() use ($plugin) {
    $asset = makeAsset('doomed.pdf', "%PDF-1.4\n");
    $document = makeDocument(['assetId' => $asset->id, 'title' => 'Doomed']);
    Craft::$app->getElements()->deleteElement($asset, true);

    $reloaded = $plugin->documents->getDocumentById($document->id);

    return $reloaded !== null && $reloaded->getSource()->getIsEmpty()
        ?: 'the document went with the asset';
});

check('quick-create hands back the existing document rather than making a second', function() use ($plugin, $pdf, $document) {
    $again = $plugin->documents->quickCreate($pdf);

    return $again !== null && $again->id === $document->id ?: 'created a duplicate';
});

check('every column the query selects has somewhere to land', function() use ($plugin, $document) {
    // Craft hands each selected column to the element's constructor, so a column with no
    // property is a fatal error the moment a document is read *from the database* — which is
    // never the moment you just saved it.
    Craft::$app->getElements()->invalidateCachesForElement($document);
    $fresh = Document::find()->id($document->id)->status(null)->one();

    return $fresh instanceof Document && $fresh->getOptions() instanceof DocumentOptions;
});

// -----------------------------------------------------------------------------
section('Rendering');

check('a PDF renders as a frame pointing at the file', function() use ($document) {
    $html = (string)$document->render();

    return str_contains($html, '<iframe') && str_contains($html, 'book--native') ?: substr($html, 0, 300);
});

check('a disabled document renders nothing at all', function() use ($document) {
    $document->enabled = false;
    $html = (string)$document->render();
    $document->enabled = true;

    return $html === '' ?: substr($html, 0, 200);
});

check('click-to-load parks the frame in a template, so nothing is requested', function() use ($plugin) {
    $html = (string)$plugin->renderer->renderSource(
        Source::fromUrl('https://example.com/spec.docx'),
        DocumentOptions::fromArray(['loading' => 'click'])
    );

    $template = strpos($html, '<template');
    $iframe = strpos($html, '<iframe');

    // A `hidden` iframe still loads. Only a template genuinely defers the request, and the
    // frame must not appear anywhere before it.
    return $template !== false && ($iframe === false || $iframe > $template)
        ?: 'a frame appears outside the template';
});

check('a CSV renders as a table with no frame anywhere', function() use ($csv, $plugin) {
    $html = (string)$plugin->renderer->renderSource(Source::fromAsset($csv), new DocumentOptions());

    return str_contains($html, 'book-table') && !str_contains($html, '<iframe') ?: substr($html, 0, 300);
});

check('a format nobody can show renders a download card', function() use ($plugin) {
    $html = (string)$plugin->renderer->renderSource(
        Source::fromUrl('https://example.com/backup.zip'),
        new DocumentOptions()
    );

    return str_contains($html, 'book-card') && str_contains($html, 'ZIP') ?: substr($html, 0, 300);
});

check('an image renders as an image, not as a frame', function() use ($plugin) {
    // A real 1×1 PNG: Craft opens an uploaded image to read its dimensions, so a plausible
    // header is not enough.
    $asset = makeAsset('picture.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $html = (string)$plugin->renderer->renderSource(Source::fromAsset($asset), new DocumentOptions());

    return str_contains($html, '<img') && !str_contains($html, '<iframe') ?: substr($html, 0, 300);
});

check('an aspect ratio beats a height', function() use ($document) {
    $html = (string)$document->render(['ratio' => '8.5:11', 'height' => 400]);

    return str_contains($html, 'aspect-ratio:8.5 / 11') && !str_contains($html, 'height:400px')
        ?: substr($html, 0, 400);
});

check('the toolbar offers a download that actually downloads', function() use ($document, $pdf) {
    $html = (string)$document->render();

    return str_contains($html, 'book-toolbar') && str_contains($html, 'book/file/' . $pdf->uid)
        ?: substr($html, 0, 500);
});

check('the same document twice on a page does not produce the same DOM id twice', function() use ($document) {
    // The id comes from the handle, which is what makes it useful to link to — and embedding
    // one document twice on a page is an ordinary thing to do.
    preg_match_all('/<figure id="([^"]+)"/', (string)$document->render() . (string)$document->render(), $matches);

    return count($matches[1]) === 2 && $matches[1][0] !== $matches[1][1] ?: json_encode($matches[1]);
});

check('an empty source renders nothing rather than an empty box', function() use ($plugin) {
    return (string)$plugin->renderer->renderSource(new Source(), new DocumentOptions()) === '';
});

// -----------------------------------------------------------------------------
section('Reference tags and Twig');

check('Craft’s own parser resolves a Book reference tag', function() use ($document) {
    // This is the whole rich-text integration: whatever editor produced the content, this is
    // what renders it.
    $parsed = Craft::$app->getElements()->parseRefs('<p>{book:' . $document->handle . ':render}</p>');

    return str_contains($parsed, '<figure') && str_contains($parsed, 'book--native') ?: substr($parsed, 0, 300);
});

check('a reference tag carries its options through', function() use ($document) {
    $parsed = Craft::$app->getElements()->parseRefs('{book:' . $document->handle . ':render(no-toolbar,height=333)}');

    return !str_contains($parsed, 'book-toolbar') && str_contains($parsed, 'height:333px')
        ?: substr($parsed, 0, 400);
});

check('an unknown handle costs a document, not the page', function() {
    $parsed = Craft::$app->getElements()->parseRefs('<p>before {book:nothing-here:render} after</p>');

    return str_contains($parsed, 'before') && str_contains($parsed, 'after');
});

check('the `book` filter takes a handle, a URL, an asset or an element', function() use ($document, $pdf) {
    $view = Craft::$app->getView();
    $results = [];

    foreach ([
        "{{ '$document->handle'|book }}",
        "{{ 'https://example.com/x.pdf'|book }}",
        '{{ asset|book }}',
        '{{ document|book }}',
        "{{ book('$document->handle') }}",
    ] as $template) {
        $results[$template] = $view->renderString($template, ['asset' => $pdf, 'document' => $document]);
    }

    foreach ($results as $template => $html) {
        if (!str_contains($html, '<figure')) {
            return "$template rendered " . substr($html, 0, 120);
        }
    }

    return true;
});

check('craft.book resolves, renders and reports', function() use ($document, $pdf) {
    $variable = new justinholtweb\book\twig\BookVariable();

    return $variable->document($document->handle)?->id === $document->id
        && str_contains((string)$variable->render($document->handle), '<figure')
        && $variable->format($pdf)?->handle === 'pdf'
        && $variable->resolve($pdf)?->viewer === Viewer::NATIVE
        && str_contains((string)$variable->downloadUrl($document), 'book/file/')
        && $variable->all()->count() > 0
        && $variable->embedCode($document->handle) === $document->getEmbedCode()
        ?: 'one of the craft.book methods disagreed';
});

check('craft.book.embed handles whatever it is given', function() use ($document, $pdf) {
    $variable = new justinholtweb\book\twig\BookVariable();

    foreach ([$document, $pdf, 'https://example.com/x.pdf', $document->handle] as $value) {
        if (!str_contains((string)$variable->embed($value), '<figure')) {
            return 'embed() did not render ' . get_debug_type($value);
        }
    }

    return true;
});

check('an inline field value renders the same way a library document does', function() use ($pdf) {
    $inline = InlineDocument::fromValue(['assetId' => $pdf->id, 'options' => ['viewer' => 'native']]);

    return str_contains((string)$inline, '<iframe') && !$inline->getIsEmpty();
});

check('an inline field value round-trips through storage', function() use ($pdf) {
    $stored = InlineDocument::fromValue(['assetId' => $pdf->id, 'options' => ['height' => 555]])->forStorage();
    $again = InlineDocument::fromValue(json_encode($stored));

    return $again->assetId === $pdf->id && $again->getOptions()->height === 555
        ?: json_encode($stored);
});

// -----------------------------------------------------------------------------
section('Template overrides');

// Last, deliberately. Craft's view memoises which templates exist for the life of the request,
// so once `_book/document` has been seen it keeps being found — deleting the file again does
// not un-see it, and every render after this one would use the override.
check('a site template of its own wins over Book’s', function() use ($plugin) {
    $path = Craft::$app->getPath()->getSiteTemplatesPath() . '/_book/document.twig';

    FileHelper::writeToFile($path, 'OVERRIDDEN {{ source.filename }}');

    try {
        $html = (string)$plugin->renderer->renderSource(Source::fromUrl('https://example.com/x.pdf'), new DocumentOptions());

        return str_contains($html, 'OVERRIDDEN') ?: substr($html, 0, 200);
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

// -----------------------------------------------------------------------------
section('Cleanup');

$leftovers = 0;

foreach (Document::find()->status(null)->siteId('*')->unique()->all() as $stray) {
    if (str_contains((string)$stray->handle, $suffix)
        || str_contains((string)$stray->title, $suffix)
        || str_contains((string)$stray->filename, 'book-check-')
        || str_contains((string)$stray->url, 'example.com/')) {
        Craft::$app->getElements()->deleteElement($stray, true);
        $leftovers++;
    }
}

foreach ($assets as $asset) {
    try {
        Craft::$app->getElements()->deleteElement($asset, true);
    } catch (Throwable) {
        // Already gone — one of the checks deletes its own.
    }
}

// Any asset a previous run left behind, so the volume does not silt up. The prefix is
// deliberately unmistakable — this sweep runs on a shared site, and a broader pattern would
// eventually delete somebody's real file.
foreach (Asset::find()->filename('book-check-*')->status(null)->all() as $stray) {
    Craft::$app->getElements()->deleteElement($stray, true);
    $leftovers++;
}

check('the settings are as they were found', function() use ($plugin, $originalSettings) {
    $now = $plugin->getSettings()->toArray();

    foreach ($originalSettings as $key => $value) {
        if (($now[$key] ?? null) !== $value) {
            return "$key was left as " . var_export($now[$key] ?? null, true);
        }
    }

    return true;
});

echo "\n$passed passed, $failed failed";
echo $leftovers ? " ($leftovers stray element(s) swept up)\n" : "\n";

exit($failed === 0 ? 0 : 1);
