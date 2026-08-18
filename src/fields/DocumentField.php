<?php

namespace justinholtweb\book\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Html;
use craft\helpers\Json;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\InlineDocument;
use justinholtweb\book\Plugin;
use justinholtweb\book\web\assets\editor\EditorAsset;
use yii\db\Schema;

/**
 * A document configured where it is used: pick a file, or paste a URL, and choose how it shows.
 *
 * The counterpart to {@see DocumentsField}, which references the library. Use this one when the
 * document belongs to the entry — a spec sheet that appears on one product and nowhere else —
 * and the library one when the same file appears in several places and should change once.
 */
class DocumentField extends Field
{
    /** Which options an author may set on the entry. Everything else uses the defaults. */
    public array $enabledOptions = ['viewer', 'height', 'loading', 'toolbar', 'caption'];

    /**
     * Defaults for a new value in this field.
     *
     * Private with a setter because the field settings screen posts it as editable-table rows —
     * `[['key' => 'viewer', 'value' => 'google']]` — and everything that reads it wants a plain
     * `['viewer' => 'google']`. Normalising on the way in means exactly one shape exists past
     * this point.
     *
     * @var array<string, mixed>
     */
    private array $_defaultOptions = [];

    /** Asset volume UIDs the file picker is limited to. Empty means all of them. */
    public array $sources = [];

    public static function displayName(): string
    {
        return Craft::t('book', 'Document');
    }

    /**
     * @param array<string, mixed>|array<int, array{key?: string, value?: mixed}> $value
     */
    public function setDefaultOptions(mixed $value): void
    {
        $options = [];

        foreach (is_array($value) ? $value : [] as $key => $entry) {
            if (is_array($entry)) {
                $rowKey = trim((string)($entry['key'] ?? ''));

                if ($rowKey !== '') {
                    $options[$rowKey] = $entry['value'] ?? '';
                }

                continue;
            }

            $options[(string)$key] = $entry;
        }

        $this->_defaultOptions = $options;
    }

    /** @return array<string, mixed> */
    public function getDefaultOptions(): array
    {
        return $this->_defaultOptions;
    }

    /** @return array<int, array{key: string, value: mixed}> */
    public function getDefaultOptionRows(): array
    {
        $rows = [];

        foreach ($this->_defaultOptions as $key => $value) {
            $rows[] = ['key' => (string)$key, 'value' => is_bool($value) ? ($value ? '1' : '0') : (string)$value];
        }

        return $rows;
    }

    /**
     * `defaultOptions` lives behind a getter, and reflection over public properties — which is
     * how Craft finds a field's settings — cannot see it.
     */
    public function settingsAttributes(): array
    {
        return array_merge(parent::settingsAttributes(), ['defaultOptions']);
    }

    public static function icon(): string
    {
        return 'book';
    }

    public static function phpType(): string
    {
        return InlineDocument::class;
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof InlineDocument) {
            return $value;
        }

        $document = InlineDocument::fromValue($value);

        // A brand-new value inherits the field's defaults; a stored one does not, or editing the
        // field settings would silently rewrite every entry that already has a value.
        if ($value === null && $this->_defaultOptions) {
            $document->setOptions(DocumentOptions::fromArray($this->_defaultOptions));
        }

        return $document;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        $document = $value instanceof InlineDocument ? $value : InlineDocument::fromValue($value);

        return $document->getIsEmpty() ? null : Json::encode($document->forStorage());
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(EditorAsset::class);

        /** @var InlineDocument $value */
        $value = $value instanceof InlineDocument ? $value : InlineDocument::fromValue($value);

        return $view->renderTemplate('book/_field/input', [
            'field' => $this,
            'id' => $view->namespaceInputId($this->handle),
            'name' => $this->handle,
            'value' => $value,
            'options' => $value->getOptions(),
            'source' => $value->getSource(),
            'resolution' => $value->getIsEmpty() ? null : $value->resolveViewer(),
            'enabledOptions' => $this->enabledOptions,
            'elementSources' => $this->getInputSources(),
            'viewerChoices' => Plugin::getInstance()->viewers->choicesFor($value->getIsEmpty() ? null : $value->getDocumentFormat()),
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('book/_field/settings', [
            'field' => $this,
            'optionChoices' => $this->optionChoices(),
            'volumeOptions' => $this->volumeOptions(),
        ]);
    }

    public function getSearchKeywords(mixed $value, ElementInterface $element): string
    {
        $document = $value instanceof InlineDocument ? $value : InlineDocument::fromValue($value);
        $source = $document->getSource();

        return implode(' ', array_filter([
            $source->filename,
            $document->url,
            $document->getOptions()->title,
            $document->getOptions()->caption,
        ]));
    }

    public function isValueEmpty(mixed $value, ElementInterface $element): bool
    {
        $document = $value instanceof InlineDocument ? $value : InlineDocument::fromValue($value);

        return $document->getIsEmpty();
    }

    /** What the element index shows in this field's column: the file and its format. */
    protected function previewHtml(mixed $value, ElementInterface $element): string
    {
        $document = $value instanceof InlineDocument ? $value : InlineDocument::fromValue($value);

        if ($document->getIsEmpty()) {
            return '';
        }

        $source = $document->getSource();

        return Html::tag('span', Html::encode($source->getLabel()), [
            'title' => $source->getFormat()->name,
        ]);
    }

    /** @return array<int, array{label: string, value: string}> */
    private function optionChoices(): array
    {
        return [
            ['label' => Craft::t('book', 'Viewer'), 'value' => 'viewer'],
            ['label' => Craft::t('book', 'Height'), 'value' => 'height'],
            ['label' => Craft::t('book', 'Aspect ratio'), 'value' => 'ratio'],
            ['label' => Craft::t('book', 'Loading'), 'value' => 'loading'],
            ['label' => Craft::t('book', 'Toolbar'), 'value' => 'toolbar'],
            ['label' => Craft::t('book', 'Title'), 'value' => 'title'],
            ['label' => Craft::t('book', 'Caption'), 'value' => 'caption'],
            ['label' => Craft::t('book', 'Access'), 'value' => 'access'],
        ];
    }

    /** @return array<int, array{label: string, value: string}> */
    private function volumeOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => $volume->name, 'value' => $volume->uid];
        }

        return $options;
    }

    /** The asset sources the picker is limited to, in the form the element select wants. */
    public function getInputSources(): array|string
    {
        if (!$this->sources) {
            return '*';
        }

        return array_map(fn(string $uid) => "volume:$uid", $this->sources);
    }
}
