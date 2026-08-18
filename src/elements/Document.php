<?php

namespace justinholtweb\book\elements;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use justinholtweb\book\elements\db\DocumentQuery;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\Source;
use justinholtweb\book\models\Viewer;
use justinholtweb\book\models\ViewerResolution;
use justinholtweb\book\Plugin;
use justinholtweb\book\records\DocumentRecord;
use justinholtweb\book\services\Delivery;
use Twig\Markup;

/**
 * One document in the library: a file, plus every decision about how it should be shown.
 *
 * Being a real element buys the index, search, permissions, relations and restore-from-trash —
 * and, the reason it is an element rather than a row in a settings screen, **reference tags**.
 * `craft\htmlfield\HtmlFieldData` parses ref tags over every rich-text value, so
 * `{book:annual-report:render}` renders this document inside CKEditor content, Redactor content,
 * or any other HTML field, with no template changes and no per-editor rendering code.
 *
 * **Localized, but not translatable.** A document exists on every site — one referenced from the
 * Spanish page has to be *findable* from the Spanish page — while its file and options live in
 * one row, shared by all of them.
 *
 * @property-read DocumentOptions $options
 * @property-read Source $source
 * @property-read Format $documentFormat
 */
class Document extends Element
{
    public ?string $handle = null;

    /** Set when this document is a Craft asset. Mutually exclusive with {@see self::$url}. */
    public ?int $assetId = null;

    /** Set when this document is somebody else's file. */
    public ?string $url = null;

    /** The format handle, derived from the file on save. Never author-supplied. */
    public string $format = 'other';

    /** Mirrors `options.viewer`, as a column, so the index can sort and filter on it. */
    public string $viewer = Viewer::AUTO;

    /** Denormalised so the index can name the file without loading the asset. */
    public ?string $filename = null;

    private ?DocumentOptions $_options = null;

    private ?Source $_source = null;

    // Identity
    // -------------------------------------------------------------------------

    public static function displayName(): string
    {
        return Craft::t('book', 'Document');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('book', 'document');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('book', 'Documents');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('book', 'documents');
    }

    public static function refHandle(): ?string
    {
        return 'book';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasUris(): bool
    {
        return false;
    }

    /**
     * A disabled document renders nothing at all, which is how a file comes off every page at
     * once without hunting down the references.
     */
    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return true;
    }

    public function getSupportedSites(): array
    {
        return Craft::$app->getSites()->getAllSiteIds();
    }

    public static function trackChanges(): bool
    {
        return true;
    }

    public static function find(): ElementQueryInterface
    {
        return new DocumentQuery(static::class);
    }

    // Options
    // -------------------------------------------------------------------------

    /**
     * The `config` column, straight off the query.
     *
     * Craft hands every selected column to the element's constructor, so a stored column with no
     * matching property is a fatal `UnknownPropertyException` the moment anything loads a
     * document from the database — not at save time, and nowhere near the line that caused it.
     */
    public function setConfig(mixed $value): void
    {
        $this->setOptions($value);
    }

    public function setOptions(mixed $value): void
    {
        $this->_options = $value instanceof DocumentOptions ? $value : DocumentOptions::fromArray(
            is_array($value) || is_string($value) ? $value : null
        );

        $this->viewer = $this->_options->viewer;
    }

    public function getOptions(): DocumentOptions
    {
        if ($this->_options === null) {
            $this->_options = Plugin::getInstance()->getSettings()->getDefaultDocumentOptions();
        }

        return $this->_options;
    }

    // The file
    // -------------------------------------------------------------------------

    public function setAsset(?Asset $asset): void
    {
        $this->assetId = $asset?->id;
        $this->_source = null;

        if ($asset) {
            $this->url = null;
        }
    }

    public function getAsset(): ?Asset
    {
        return $this->assetId ? Craft::$app->getAssets()->getAssetById($this->assetId) : null;
    }

    /** What this document points at, resolved. Never null; possibly empty. */
    public function getSource(): Source
    {
        if ($this->_source === null) {
            $asset = $this->getAsset();

            if ($asset) {
                $this->_source = Source::fromAsset($asset);
            } elseif ($this->url) {
                $this->_source = Source::fromUrl($this->url);
            } else {
                $this->_source = new Source();
            }
        }

        return $this->_source;
    }

    /** Named `documentFormat` because `format` is the column, and Twig would find that first. */
    public function getDocumentFormat(): Format
    {
        return $this->getSource()->getFormat();
    }

    /**
     * What will actually happen when this renders here.
     *
     * @param array<string, mixed> $overrides
     */
    public function resolveViewer(array $overrides = []): ViewerResolution
    {
        return Plugin::getInstance()->viewers->resolve($this->getSource(), $this->getOptions()->merge($overrides));
    }

    // Rendering
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $overrides Per-call overrides, for a template that wants one
     * document to behave differently in one place.
     */
    public function render(array $overrides = []): Markup
    {
        return Plugin::getInstance()->renderer->renderDocument($this, $overrides);
    }

    /**
     * The property `{book:annual-report:render}` resolves.
     *
     * Craft splices the result in raw, so this is the entire rich-text integration: whatever
     * editor produced the content, the tag renders the same document the same way.
     */
    public function getRender(): Markup
    {
        return $this->render();
    }

    /**
     * Lets a reference tag carry options: `{book:report:render(google,height=900)}`.
     *
     * Craft resolves a reference tag by reading the named property off the element, and its
     * pattern is loose enough to allow parentheses — so one document can behave differently in
     * one place without a second mechanism, and without the editors storing anything beyond the
     * tag they already write.
     *
     * `render` on its own is not handled here: Yii finds {@see self::getRender()} first.
     */
    public function __get($name)
    {
        $overrides = $this->parseRenderCall((string)$name);

        return $overrides !== null ? $this->render($overrides) : parent::__get($name);
    }

    public function __isset($name): bool
    {
        return $this->parseRenderCall((string)$name) !== null || parent::__isset($name);
    }

    /** @return array<string, mixed>|null */
    private function parseRenderCall(string $name): ?array
    {
        if (!preg_match('/^render\((.*)\)$/s', $name, $matches)) {
            return null;
        }

        return DocumentOptions::parseEmbedOptions($matches[1]);
    }

    /** The tag an author copies out of the CP. */
    public function getEmbedCode(array $overrides = []): string
    {
        $ref = $this->handle ?: $this->id;

        if (!$overrides) {
            return sprintf('{book:%s:render}', $ref);
        }

        return sprintf('{book:%s:render(%s)}', $ref, DocumentOptions::toEmbedOptions($overrides));
    }

    /** The URL a download button points at. */
    public function getDownloadUrl(): ?string
    {
        return Plugin::getInstance()->delivery->fileUrl(
            $this->getSource(),
            $this->getOptions(),
            Delivery::DISPOSITION_ATTACHMENT
        );
    }

    // Element plumbing
    // -------------------------------------------------------------------------

    public function getRef(): ?string
    {
        return $this->handle;
    }

    public function getUiLabel(): string
    {
        return $this->title ?: ($this->filename ?: Craft::t('book', 'Untitled document'));
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl("book/documents/$this->id");
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('book/documents');
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // Dashes allowed on purpose: `{book:annual-report:render}` parses fine — Craft's ref
        // pattern is far looser than its handle pattern — and reads better than `annualReport`.
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/', 'message' => Craft::t('book', 'Handles must start with a letter and contain only letters, numbers, dashes and underscores.')];
        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'validateHandleIsFree'];
        $rules[] = [['assetId'], 'validateHasAFile', 'skipOnEmpty' => false];
        $rules[] = [['url'], 'validateUrl'];
        $rules[] = [['format'], 'string', 'max' => 32];
        $rules[] = [['viewer'], 'in', 'range' => Viewer::CHOICES];

        return $rules;
    }

    /**
     * Handles have to be unique among *live* documents, not among rows.
     *
     * A `UniqueValidator` over `book_documents` would be the obvious rule and is the wrong one: a
     * deleted document keeps its row until garbage collection, so its handle would stay taken
     * forever and an author would be told “already taken” by something they cannot see anywhere.
     * Element queries exclude trashed elements, which is the same question the author is asking.
     */
    public function validateHandleIsFree(string $attribute): void
    {
        if (!$this->handle) {
            return;
        }

        if (Plugin::getInstance()->documents->handleIsTaken($this->handle, $this->id)) {
            $this->addError($attribute, Craft::t('book', 'Another document is already using the handle “{handle}”.', [
                'handle' => $this->handle,
            ]));
        }
    }

    /**
     * One file, and exactly one.
     *
     * `skipOnEmpty => false`, because the case worth catching — neither a file nor a URL — is by
     * definition the empty one, and Yii would skip the rule that exists to see it.
     */
    public function validateHasAFile(string $attribute): void
    {
        $url = trim((string)$this->url);

        if (!$this->assetId && $url === '') {
            $this->addError($attribute, Craft::t('book', 'Choose a file, or paste the URL of one.'));

            return;
        }

        if ($this->assetId && $url !== '') {
            $this->addError($attribute, Craft::t('book', 'A document is either a file in this site or a URL somewhere else, not both.'));
        }

        if ($this->assetId && !$this->getAsset()) {
            $this->addError($attribute, Craft::t('book', 'That asset no longer exists.'));
        }
    }

    public function validateUrl(string $attribute): void
    {
        $url = trim((string)$this->url);

        if ($url === '' || str_starts_with($url, '/')) {
            return;
        }

        if (!preg_match('~^https?://~i', $url)) {
            $this->addError($attribute, Craft::t('book', 'The URL must start with http:// or https://, or with / for a path on this site.'));

            return;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->addError($attribute, Craft::t('book', 'That does not look like a URL.'));
        }
    }

    public function attributeLabels(): array
    {
        return array_merge(parent::attributeLabels(), [
            'handle' => Craft::t('book', 'Handle'),
            'assetId' => Craft::t('book', 'File'),
            'url' => Craft::t('book', 'URL'),
            'viewer' => Craft::t('book', 'Viewer'),
        ]);
    }

    public function beforeSave(bool $isNew): bool
    {
        $this->url = trim((string)$this->url) ?: null;

        if ($this->assetId) {
            $this->url = null;
        }

        $this->_source = null;
        $source = $this->getSource();

        // Derived, never author-supplied: both are facts about the file, and an author who
        // swaps the file should not have to remember to re-pick them.
        $this->format = $source->getFormat()->handle;
        $this->filename = $source->filename ?: null;
        $this->viewer = $this->getOptions()->viewer;

        if (!$this->title) {
            $this->title = $source->getLabel() ?: Craft::t('book', 'Untitled document');
        }

        if (!$this->handle && $this->title) {
            $this->handle = Plugin::getInstance()->documents->uniqueHandle($this->title, $this->id);
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        // One row, whichever site the save came from — a document is localized so it can be
        // found everywhere, not so it can differ.
        if (!$this->propagating) {
            $record = $isNew ? new DocumentRecord() : DocumentRecord::findOne($this->id);

            if (!$record) {
                // A restored element, or one whose row went missing. Write it back rather than
                // failing the save and leaving an element with no document behind it.
                $record = new DocumentRecord();
                $isNew = true;
            }

            if ($isNew) {
                $record->id = $this->id;
            }

            $record->handle = (string)$this->handle;
            $record->assetId = $this->assetId;
            $record->url = $this->url;
            $record->format = $this->format;
            $record->viewer = $this->viewer;
            $record->filename = $this->filename;
            $record->config = json_encode($this->getOptions()->toStorageArray());
            $record->save(false);
        }

        parent::afterSave($isNew);
    }

    /**
     * Frees the handle when a document goes to the trash.
     *
     * Craft's delete is a soft delete, so the row — and its handle — outlive the document an
     * author can see. Without this, deleting “brochure” and immediately recreating it fails on a
     * unique index pointing at something invisible.
     */
    public function afterDelete(): void
    {
        if ($this->handle && $this->id) {
            Craft::$app->getDb()->createCommand()
                ->update(DocumentRecord::TABLE, ['handle' => substr((string)$this->handle, 0, 40) . '--trashed-' . $this->id], ['id' => $this->id])
                ->execute();
        }

        parent::afterDelete();
    }

    /**
     * Takes the handle back, or a variation of it if the name has been reused meanwhile — a
     * document that cannot come back out of the trash is worse than one that comes back as
     * `brochure-2`.
     */
    public function afterRestore(): void
    {
        if ($this->id) {
            $handle = preg_replace('/--trashed-\d+$/', '', (string)$this->handle) ?: 'document';
            $documents = Plugin::getInstance()->documents;

            if ($documents->handleIsTaken($handle, $this->id)) {
                $handle = $documents->uniqueHandle($handle, $this->id);
            }

            $this->handle = $handle;
            Craft::$app->getDb()->createCommand()
                ->update(DocumentRecord::TABLE, ['handle' => $handle], ['id' => $this->id])
                ->execute();
        }

        parent::afterRestore();
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle', 'filename', 'url', 'format'];
    }

    // Index
    // -------------------------------------------------------------------------

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('book', 'All documents'),
                'defaultSort' => ['title', 'asc'],
            ],
            ['heading' => Craft::t('book', 'Format')],
        ];

        $counts = Plugin::getInstance()->documents->countByFormat();

        foreach (Plugin::getInstance()->formats->getAll() as $handle => $format) {
            // A source per format Book *could* recognise would be a wall of empty lists; only
            // the ones somebody has actually used are worth a row.
            if (empty($counts[$handle])) {
                continue;
            }

            $sources[] = [
                'key' => "format:$handle",
                'label' => $format->name,
                'criteria' => ['format' => $handle],
            ];
        }

        $sources[] = ['heading' => Craft::t('book', 'Source')];
        $sources[] = [
            'key' => 'source:asset',
            'label' => Craft::t('book', 'Files in this site'),
            'criteria' => ['assetId' => ':notempty:'],
        ];
        $sources[] = [
            'key' => 'source:url',
            'label' => Craft::t('book', 'Files elsewhere'),
            'criteria' => ['assetId' => ':empty:'],
        ];

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('book', 'Handle')],
            'file' => ['label' => Craft::t('book', 'File')],
            'documentFormat' => ['label' => Craft::t('book', 'Format')],
            'viewer' => ['label' => Craft::t('book', 'Viewer')],
            'size' => ['label' => Craft::t('book', 'Size')],
            'embedCode' => ['label' => Craft::t('book', 'Embed')],
            'dateUpdated' => ['label' => Craft::t('app', 'Last Updated')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
        ];
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['file', 'documentFormat', 'viewer', 'embedCode', 'dateUpdated'];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('app', 'Title'),
            'book_documents.handle' => Craft::t('book', 'Handle'),
            'book_documents.filename' => Craft::t('book', 'File'),
            'book_documents.format' => Craft::t('book', 'Format'),
            'book_documents.viewer' => Craft::t('book', 'Viewer'),
            'dateUpdated' => Craft::t('app', 'Last Updated'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'handle' => Html::tag('code', Html::encode((string)$this->handle)),
            'file' => $this->fileHtml(),
            'documentFormat' => Html::encode($this->getDocumentFormat()->name),
            'viewer' => Html::encode($this->viewerLabel()),
            'size' => Html::encode($this->getSource()->getSizeLabel()),
            'embedCode' => Cp::renderTemplate('_includes/forms/copytextbtn.twig', [
                'class' => ['code', 'small', 'light'],
                'value' => $this->getEmbedCode(),
            ]),
            default => parent::attributeHtml($attribute),
        };
    }

    private function fileHtml(): string
    {
        $source = $this->getSource();

        if ($source->getIsEmpty()) {
            return Html::tag('span', Craft::t('book', 'Missing'), ['class' => 'error']);
        }

        if ($source->getIsAsset()) {
            return Html::tag('span', Html::encode((string)$this->filename), ['title' => (string)$this->filename]);
        }

        return Html::a(Html::encode($this->shortUrl()), (string)$this->url, [
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
            'title' => (string)$this->url,
        ]);
    }

    private function viewerLabel(): string
    {
        if ($this->viewer !== Viewer::AUTO) {
            return Plugin::getInstance()->viewers->getByHandle($this->viewer)?->name ?? $this->viewer;
        }

        $resolved = Plugin::getInstance()->viewers->getByHandle($this->resolveViewer()->viewer)?->name ?? '';

        return Craft::t('book', 'Automatic ({viewer})', ['viewer' => $resolved]);
    }

    private function shortUrl(): string
    {
        $url = preg_replace('~^https?://(www\.)?~', '', (string)$this->url) ?? (string)$this->url;

        return strlen($url) > 48 ? substr($url, 0, 45) . '…' : $url;
    }

    // Metadata
    // -------------------------------------------------------------------------

    protected function metadata(): array
    {
        $source = $this->getSource();
        $resolution = $this->resolveViewer();

        return [
            Craft::t('book', 'Format') => $source->getFormat()->name,
            Craft::t('book', 'Size') => $source->getSizeLabel() ?: Craft::t('book', 'Unknown'),
            Craft::t('book', 'Viewer') => $this->viewerLabel(),
            Craft::t('book', 'Served by') => $resolution->getIsThirdParty()
                ? Plugin::getInstance()->viewers->getByHandle($resolution->viewer)?->host ?? ''
                : Craft::t('book', 'This site'),
            Craft::t('book', 'Reference') => fn() => Html::tag('code', Html::encode($this->getEmbedCode())),
        ];
    }

    // Permissions
    // -------------------------------------------------------------------------

    public function canView(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_MANAGE);
    }

    public function canDelete(User $user): bool
    {
        return $user->can(Plugin::PERMISSION_DELETE);
    }

    public function canCreateDrafts(User $user): bool
    {
        return false;
    }
}
