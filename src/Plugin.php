<?php

namespace justinholtweb\book;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\FileHelper;
use craft\services\Elements;
use craft\services\Fields;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use justinholtweb\book\elements\Document;
use justinholtweb\book\fields\DocumentField;
use justinholtweb\book\fields\DocumentsField;
use justinholtweb\book\models\Settings;
use justinholtweb\book\services\Delivery;
use justinholtweb\book\services\Documents;
use justinholtweb\book\services\Formats;
use justinholtweb\book\services\Inliner;
use justinholtweb\book\services\Renderer;
use justinholtweb\book\services\Viewers;
use justinholtweb\book\twig\BookVariable;
use justinholtweb\book\twig\Extension;
use yii\base\Event;

/**
 * Book — embed any document.
 *
 * A PDF in a CMS ends up as a link; a `.docx` cannot be shown by any browser at all; a private
 * asset has no URL to give a viewer; and the formats that are secretly text — CSV, Markdown,
 * JSON — need no viewer, just a server willing to read them. This plugin exists for all four.
 *
 * One hard rule shapes the rest: **Book never makes an outbound HTTP request.** Third-party
 * viewers are URLs the reader's browser loads, and inline rendering reads assets out of your own
 * volume. There is nothing here that fetches a URL server-side, by design.
 *
 * @property-read Documents $documents
 * @property-read Formats $formats
 * @property-read Viewers $viewers
 * @property-read Delivery $delivery
 * @property-read Inliner $inliner
 * @property-read Renderer $renderer
 * @property-read Settings $settings
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const PERMISSION_VIEW = 'book:viewDocuments';
    public const PERMISSION_MANAGE = 'book:manageDocuments';
    public const PERMISSION_DELETE = 'book:deleteDocuments';

    /** Log category used by everything in the plugin. */
    public const LOG_CATEGORY = 'book';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'documents' => Documents::class,
                'formats' => Formats::class,
                'viewers' => Viewers::class,
                'delivery' => Delivery::class,
                'inliner' => Inliner::class,
                'renderer' => Renderer::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementTypes();
        $this->registerFieldTypes();
        $this->registerRoutes();
        $this->registerPermissions();
        $this->registerTwig();
        $this->registerRichTextIntegrations();
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('book', 'Book');

        $item['subnav'] = [
            'documents' => [
                'label' => Craft::t('book', 'Documents'),
                'url' => 'book/documents',
            ],
        ];

        if (Craft::$app->getUser()->getIsAdmin()) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('book', 'Settings'),
                'url' => 'settings/plugins/book',
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('book/settings', [
            'settings' => $this->getSettings(),
            'plugin' => $this,
            'purifierConfigs' => $this->purifierConfigOptions(),
            'viewers' => $this->viewers->getAll(),
            'formats' => $this->formats->getAll(),
        ]);
    }

    /** @return array<string, string> */
    public function purifierConfigOptions(): array
    {
        $options = ['' => Craft::t('book', 'Default')];
        $path = Craft::$app->getPath()->getConfigPath() . DIRECTORY_SEPARATOR . 'htmlpurifier';

        if (is_dir($path)) {
            foreach (FileHelper::findFiles($path, ['only' => ['*.json'], 'recursive' => false]) as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $options[$name] = $name;
            }
        }

        return $options;
    }

    private function registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Document::class;
        });
    }

    private function registerFieldTypes(): void
    {
        Event::on(Fields::class, Fields::EVENT_REGISTER_FIELD_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = DocumentField::class;
            $event->types[] = DocumentsField::class;
        });
    }

    private function registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                'book' => 'book/documents/index',
                'book/documents' => 'book/documents/index',
                'book/documents/new' => 'book/documents/edit',
                'book/documents/<documentId:\d+>' => 'book/documents/edit',
            ];
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules += [
                // The filename is part of the path rather than a parameter so “save as” offers
                // the right name, and so the URL reads properly in a log.
                'book/file/<uid:[\w\-]+>/<filename:.+>' => 'book/file/serve',
                'book/file/<uid:[\w\-]+>' => 'book/file/serve',
            ];
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('book', 'Book'),
                'permissions' => [
                    self::PERMISSION_VIEW => [
                        'label' => Craft::t('book', 'View documents'),
                        'nested' => [
                            self::PERMISSION_MANAGE => [
                                'label' => Craft::t('book', 'Create and edit documents'),
                            ],
                            self::PERMISSION_DELETE => [
                                'label' => Craft::t('book', 'Delete documents'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerTwig(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            $event->sender->set('book', BookVariable::class);
        });

        Craft::$app->getView()->registerTwigExtension(new Extension());
    }

    /**
     * Editing affordances only.
     *
     * Rendering inside rich text is done by Craft's own reference-tag parsing — see
     * {@see Document::getRender()} — so these integrations exist purely to help an author write
     * the tag without typing it, and their absence costs nothing but convenience.
     */
    private function registerRichTextIntegrations(): void
    {
        if (!$this->getSettings()->richTextIntegration) {
            return;
        }

        // Purification happens on save, which can be a console request (a resave, a migration,
        // an Element API write), so this one is not CP-only.
        (new richtext\PurifierSupport())->register();

        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        (new richtext\CkeditorIntegration())->register();
        (new richtext\RedactorIntegration())->register();
    }
}
