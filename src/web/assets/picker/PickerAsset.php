<?php

namespace justinholtweb\book\web\assets\picker;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The document picker, shared by every editor integration.
 *
 * One picker rather than one per editor: the choice an author is making — which document — is
 * the same whatever is doing the asking, and a second implementation would drift from the first.
 */
class PickerAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
    ];

    public $js = ['book-picker.js'];

    public $css = ['book-picker.css'];
}
