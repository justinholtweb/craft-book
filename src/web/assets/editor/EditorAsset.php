<?php

namespace justinholtweb\book\web\assets\editor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use justinholtweb\book\web\assets\picker\PickerAsset;

/**
 * The control panel editor: the document edit screen and the {@see
 * \justinholtweb\book\fields\DocumentField} input.
 *
 * Both are the same job — pick a file, choose how it shows, be told what will actually happen —
 * so they share one script and one stylesheet.
 */
class EditorAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [
        CpAsset::class,
        PickerAsset::class,
    ];

    public $js = ['book-editor.js'];

    public $css = ['book-editor.css'];
}
