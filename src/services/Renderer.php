<?php

namespace justinholtweb\book\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\View;
use justinholtweb\book\elements\Document;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Format;
use justinholtweb\book\models\Source;
use justinholtweb\book\models\Viewer;
use justinholtweb\book\models\ViewerResolution;
use justinholtweb\book\Plugin;
use justinholtweb\book\web\assets\runtime\RuntimeAsset;
use Throwable;
use Twig\Markup;

/**
 * Turns a document into markup.
 *
 * One path for every surface — the element, a reference tag, an inline field value, a Twig call
 * — so there is a single answer to “what does a Book document look like on the page”, and a site
 * that wants a different one overrides a single template.
 */
class Renderer extends Component
{
    /** A site template of this name wins over Book's own. */
    public const SITE_TEMPLATE = '_book/document';

    private int $counter = 0;

    /** @var array<string, int> Ids already written this request, so a repeat gets a suffix. */
    private array $usedIds = [];

    /** Registered once per request, however many documents there are. */
    private bool $assetsRegistered = false;

    /**
     * @param array<string, mixed> $overrides
     */
    public function renderDocument(Document $document, array $overrides = []): Markup
    {
        // A disabled document renders nothing — the point of the switch is to take a file off
        // every page at once without hunting down the references.
        if (!$document->enabled) {
            return $this->nothing();
        }

        return $this->renderSource($document->getSource(), $document->getOptions()->merge($overrides), [
            'document' => $document,
            'handle' => $document->handle,
            'label' => $document->title,
            'uid' => $document->uid,
        ]);
    }

    /**
     * Render a file that has no element behind it — an inline field value, an asset straight
     * out of a template, or `craft.book.url()`.
     *
     * @param array<string, mixed> $context
     */
    public function renderSource(Source $source, DocumentOptions $options, array $context = []): Markup
    {
        if ($source->getIsEmpty()) {
            return $this->nothing();
        }

        $plugin = Plugin::getInstance();
        $format = $source->getFormat();
        $resolution = $plugin->viewers->resolve($source, $options);
        $viewer = $plugin->viewers->getByHandle($resolution->viewer);

        $id = $this->uniqueId((string)($options->id ?: ($context['id'] ?? sprintf('book-%s', $context['handle'] ?? ++$this->counter))));
        $label = (string)($options->title ?: ($context['label'] ?? '') ?: $source->getLabel());

        $variables = array_merge([
            'document' => null,
            'handle' => null,
            'uid' => null,
        ], $context, [
            'id' => $id,
            'label' => $label,
            'source' => $source,
            'format' => $format,
            'options' => $options,
            'viewer' => $viewer,
            'resolution' => $resolution,
            'classes' => $this->classes($options, $resolution->viewer, $format->handle),
            'stageStyle' => $this->stageStyle($options, $resolution->viewer, $format),
            'frameAttributes' => $this->frameAttributes($options, $label),
            'config' => $this->runtimeConfig($options, $resolution),
            'consent' => $this->consent($options, $resolution),
            'card' => $this->card($source, $options, $resolution, $label),
            'inlineHtml' => null,
            'inlineError' => null,
            'texts' => $this->texts(),
            'devMode' => Craft::$app->getConfig()->getGeneral()->devMode,
        ]);

        if ($resolution->viewer === Viewer::INLINE) {
            [$variables['inlineHtml'], $variables['inlineError']] = $plugin->inliner->render($source, $options);
        }

        $this->registerAssets($options);

        return new Markup($this->renderTemplate($variables), Craft::$app->charset);
    }

    // -------------------------------------------------------------------------

    /**
     * A DOM id nothing else on this page is using.
     *
     * The id is derived from the handle, which is what makes it useful — and the same document
     * embedded twice on one page is a normal thing to do, so the second one has to give way.
     */
    private function uniqueId(string $id): string
    {
        $id = Html::id($id);
        $count = $this->usedIds[$id] = ($this->usedIds[$id] ?? 0) + 1;

        return $count === 1 ? $id : "$id-$count";
    }

    /** @return string[] */
    private function classes(DocumentOptions $options, string $viewer, string $format): array
    {
        $classes = [
            'book',
            'book--' . $viewer,
            'book--format-' . preg_replace('/[^a-z0-9]+/', '', $format),
            'book--' . $options->loading,
            'book--align-' . $options->align,
        ];

        if ($options->className !== '') {
            foreach (preg_split('/\s+/', $options->className, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The stage's inline style.
     *
     * A ratio beats a height when one is set, because a ratio is the only thing that keeps a
     * page of A4 looking like a page of A4 on a phone. Inline and media viewers are sized by
     * their own content and get neither.
     */
    private function stageStyle(DocumentOptions $options, string $viewer, Format $format): string
    {
        $rules = [];

        if ($options->width !== '' && $options->width !== '100%') {
            $rules[] = 'width:' . $options->width;
        }

        // Inline HTML, a download card, an image and a media element are all sized by what is
        // inside them — a video already knows its own aspect ratio. Forcing 720px onto a two-row
        // CSV is the sort of thing that makes an embed plugin feel like it is fighting the page.
        if (in_array($viewer, [Viewer::INLINE, Viewer::LINK], true) || $format->nativeImage || $format->mediaTag !== null) {
            return implode(';', $rules);
        }

        $ratio = $options->getAspectRatio();

        if ($ratio !== null) {
            $rules[] = 'aspect-ratio:' . $ratio;
        } elseif ($options->height > 0) {
            $rules[] = 'height:' . $options->height . 'px';
        }

        return implode(';', $rules);
    }

    /**
     * @return array<string, mixed>
     */
    private function frameAttributes(DocumentOptions $options, string $label): array
    {
        return array_filter([
            'class' => 'book-frame',
            'title' => $label ?: Craft::t('book', 'Embedded document'),
            // Native lazy loading costs nothing and works with JavaScript off. The runtime's
            // IntersectionObserver is for click-to-load, which native loading cannot express.
            'loading' => $options->loading === DocumentOptions::LOADING_EAGER ? 'eager' : 'lazy',
            'referrerpolicy' => 'strict-origin-when-cross-origin',
            'allowfullscreen' => $options->fullscreen ?: null,
            'frameborder' => '0',
        ], fn($value) => $value !== null && $value !== false);
    }

    /**
     * What the front-end runtime needs, as JSON on the wrapper.
     *
     * Only what the runtime actually reads. Everything presentational has already become CSS by
     * the time it gets here.
     */
    private function runtimeConfig(DocumentOptions $options, ViewerResolution $resolution): string
    {
        $config = [
            'viewer' => $resolution->viewer,
            'loading' => $options->loading,
            'timeout' => $options->timeout,
        ];

        if ($options->loading === DocumentOptions::LOADING_CLICK) {
            $host = $resolution->frameUrl ? parse_url($resolution->frameUrl, PHP_URL_HOST) : null;

            $config['consent'] = [
                'remember' => $options->rememberConsent,
                'key' => $options->rememberConsent && $host ? 'book:consent:' . $host : null,
            ];
        }

        if ($options->rootMargin !== '') {
            $config['rootMargin'] = $options->rootMargin;
        }

        return Json::encode($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function consent(DocumentOptions $options, ViewerResolution $resolution): array
    {
        $viewer = Plugin::getInstance()->viewers->getByHandle($resolution->viewer);
        $name = $viewer?->getConsentName() ?? Craft::t('book', 'the viewer');

        return [
            'title' => $options->consentTitle ?: Craft::t('book', 'Show this document?'),
            'text' => $options->consentText ?: ($resolution->getIsThirdParty()
                ? Craft::t('book', 'This document is rendered by {name}. Loading it will share your IP address with them.', ['name' => $name])
                : Craft::t('book', 'The document will load when you are ready.')),
            'button' => $options->consentButtonLabel ?: Craft::t('book', 'Show the document'),
            'poster' => $options->posterUrl,
            'name' => $name,
        ];
    }

    /**
     * The download card — the `link` viewer's whole output, and the fallback every other viewer
     * falls back to when the frame does not load.
     *
     * @return array<string, mixed>
     */
    private function card(Source $source, DocumentOptions $options, ViewerResolution $resolution, string $label): array
    {
        $format = $source->getFormat();

        return [
            'title' => $label,
            'formatName' => $format->name,
            'extension' => strtoupper($source->extension ?: $format->getExtension()),
            'size' => $source->getSizeLabel(),
            'url' => $resolution->documentUrl,
            'downloadUrl' => $resolution->downloadUrl,
            'icon' => $format->icon,
            'showMeta' => $options->showMeta,
        ];
    }

    /** @return array<string, string> */
    private function texts(): array
    {
        return [
            'loading' => Craft::t('book', 'Loading…'),
            'download' => Craft::t('book', 'Download'),
            'open' => Craft::t('book', 'Open in a new tab'),
            'print' => Craft::t('book', 'Print'),
            'fullscreen' => Craft::t('book', 'Fullscreen'),
            'exitFullscreen' => Craft::t('book', 'Exit fullscreen'),
            'failed' => Craft::t('book', 'This document could not be displayed.'),
        ];
    }

    private function nothing(): Markup
    {
        return new Markup('', Craft::$app->charset);
    }

    private function registerAssets(DocumentOptions $options): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $view = Craft::$app->getView();

        if ($this->assetsRegistered || !$view instanceof View) {
            return;
        }

        // A console request or an Element API response has no head to register into, and a
        // rendered document can legitimately end up in both.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        if (!$settings->registerCss && !$settings->registerJs) {
            return;
        }

        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);
            $view->registerAssetBundle(RuntimeAsset::class);
            $this->assetsRegistered = true;
        } catch (Throwable $e) {
            Craft::warning('Book could not register its assets: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }

    /**
     * A site override wins, and is looked for in site template mode so it can include the site's
     * own partials.
     */
    private function renderTemplate(array $variables): string
    {
        $view = Craft::$app->getView();
        $oldMode = $view->getTemplateMode();

        try {
            $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

            if ($view->doesTemplateExist(self::SITE_TEMPLATE)) {
                return $view->renderTemplate(self::SITE_TEMPLATE, $variables);
            }

            $view->setTemplateMode(View::TEMPLATE_MODE_CP);

            return $view->renderTemplate('book/_render/document', $variables);
        } finally {
            $view->setTemplateMode($oldMode);
        }
    }
}
