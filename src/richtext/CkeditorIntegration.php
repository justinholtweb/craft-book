<?php

namespace justinholtweb\book\richtext;

use Craft;
use craft\ckeditor\helpers\CkeditorConfig;
use craft\ckeditor\Plugin as CkeditorPlugin;
use justinholtweb\book\web\assets\ckeditor\BookCkeditorAsset;

/**
 * Teaches CKEditor how to insert a Book document.
 *
 * Editing only. Rendering is Craft's reference-tag parsing, which knows nothing about CKEditor —
 * so this integration can be absent, broken or disabled and existing documents keep working.
 */
class CkeditorIntegration
{
    public function register(): void
    {
        // `CkeditorConfig` arrived with the ESM/import-map rewrite of the CKEditor plugin. On the
        // older DLL-based versions the package format differs enough that half-registering would
        // break the editor rather than merely lack a button — so on those, do nothing.
        if (!class_exists(CkeditorPlugin::class) || !class_exists(CkeditorConfig::class)) {
            return;
        }

        CkeditorPlugin::registerCkeditorPackage(BookCkeditorAsset::class, 'index.js');

        CkeditorConfig::registerPackage(BookCkeditorAsset::NAMESPACE, [
            'plugins' => [BookCkeditorAsset::PLUGIN_NAME],
            'toolbarItems' => [BookCkeditorAsset::TOOLBAR_ITEM],
        ]);

        // …and add the import-map entry ourselves.
        //
        // The CKEditor plugin adds one for every registered package, but it does so in its own
        // `init()` — and plugins initialise in handle order, so `book` registers *before*
        // `ckeditor` here and would be fine, while a rename or a reorder would silently break
        // it. Registering the import directly does not depend on the order at all.
        $view = Craft::$app->getView();
        $assetManager = $view->getAssetManager();
        $bundle = $assetManager->getBundle(BookCkeditorAsset::class);

        if ($bundle instanceof BookCkeditorAsset) {
            // With a timestamp, deliberately. A published directory's hash comes from its path
            // and its *directory* mtime, and editing a file inside it does not change that — so
            // without `?v=`, the module URL stays identical while its contents change, and every
            // browser that has already imported it keeps running the old copy.
            $view->registerJsImport(BookCkeditorAsset::NAMESPACE, $assetManager->getAssetUrl($bundle, 'index.js', true));
        }
    }
}
