<?php
/**
 * Which files a Book editor can reach through the control panel — checked over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-book/tests/integration/trust.php
 *
 * Every Documents action that takes an asset ID renders that file: inline, or behind a freshly
 * signed URL. Until 5.0.1 none of them asked whether the user could see the file, so Book's view
 * permission was a way to read any volume on the site by ID. This signs in as an editor with
 * every Book permission but no access to the asset's volume, tries each action, then grants that
 * access and tries again — so a pass means "refused because of the volume", not "broken".
 *
 * Idempotent and self-cleaning: the user, the asset and any document are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\FileHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\book\elements\Document;
use justinholtweb\book\Plugin;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

$run = substr(bin2hex(random_bytes(3)), 0, 6);
$password = 'book-' . bin2hex(random_bytes(12));
$user = null;
$asset = null;

register_shutdown_function(function() use (&$user, &$asset, $run) {
    foreach (Document::find()->status(null)->siteId('*')->unique()->all() as $document) {
        if (str_contains((string)$document->title, "trust-$run") || ($asset && $document->assetId === (int)$asset->id)) {
            Craft::$app->getElements()->deleteElement($document, true);
        }
    }

    foreach (array_filter([$asset, $user]) as $element) {
        Craft::$app->getElements()->deleteElement($element, true);
    }
});

// A file somewhere the editor cannot see.
$volume = Craft::$app->getVolumes()->getAllVolumes()[0];
$temp = Craft::$app->getPath()->getTempPath() . "/book-trust-$run.csv";
FileHelper::writeToFile($temp, "secret,value\nsalary,123456\n");
$asset = new Asset();
$asset->tempFilePath = $temp;
$asset->setFilename("book-check-trust-$run.csv");
$asset->newFolderId = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id)->id;
$asset->setVolumeId($volume->id);
$asset->setScenario(Asset::SCENARIO_CREATE);
Craft::$app->getElements()->saveElement($asset);

$siteUid = Craft::$app->getSites()->getPrimarySite()->uid;
$bookPermissions = [
    'accesscp', 'accessplugin-book', "editsite:$siteUid",
    strtolower(Plugin::PERMISSION_VIEW), strtolower(Plugin::PERMISSION_MANAGE),
];

$user = new User();
$user->username = "book-trust-$run";
$user->email = "book-trust-$run@example.com";
$user->newPassword = $password;
Craft::$app->getElements()->saveElement($user, false);
Craft::$app->getUsers()->activateUser($user);
Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $bookPermissions);

$http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);

$csrf = static function() use ($http): string {
    $info = json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true);

    return (string)($info['csrfTokenValue'] ?? '');
};

$login = $http->post('index.php?p=actions/users/login', [
    'headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
    'form_params' => ['loginName' => $user->username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()],
]);

if ($login->getStatusCode() !== 200) {
    echo "Could not sign in as the test user: {$login->getStatusCode()}\n";
    exit(1);
}

$post = static function(string $action, array $fields) use ($http, $csrf): array {
    $response = $http->post('index.php?p=admin/actions/book/documents/' . $action, [
        'headers' => ['Accept' => 'application/json'],
        'form_params' => $fields + ['CRAFT_CSRF_TOKEN' => $csrf()],
    ]);

    return [$response->getStatusCode(), json_decode((string)$response->getBody(), true) ?? []];
};

$tryEverything = static function() use ($post, $asset, $run): array {
    [, $preview] = $post('preview', ['assetId' => $asset->id]);
    [, $resolve] = $post('resolve', ['assetId' => $asset->id]);
    [, $quick] = $post('quick-create', ['assetId' => $asset->id]);
    [$saveStatus, $save] = $post('save', ['title' => "trust-$run", 'assetId' => [$asset->id]]);

    return [
        'preview' => ($preview['ok'] ?? false) === true && str_contains((string)($preview['html'] ?? ''), 'salary'),
        'resolve' => ($resolve['ok'] ?? false) === true,
        'quickCreate' => ($quick['success'] ?? false) === true,
        'save' => $saveStatus === 200 && isset($save['id']),
    ];
};

echo "\nWithout access to the file's volume\n";

$denied = $tryEverything();

foreach ($denied as $action => $reached) {
    check("$action refuses the file", fn() => !$reached ?: 'it went through');
}

check('no document was created pointing at the file', function() use ($asset) {
    return !Document::find()->status(null)->siteId('*')->andWhere(['book_documents.assetId' => $asset->id])->exists()
        ?: 'a document exists';
});

echo "\nWith access to it\n";

Craft::$app->getUserPermissions()->saveUserPermissions($user->id, array_merge($bookPermissions, [
    'viewassets:' . $volume->uid,
    'viewpeerassets:' . $volume->uid,
]));

$allowed = $tryEverything();

check('every action works once the editor may view the volume', function() use ($allowed) {
    return !in_array(false, $allowed, true) ?: json_encode($allowed);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
