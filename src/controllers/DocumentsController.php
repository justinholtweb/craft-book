<?php

namespace justinholtweb\book\controllers;

use Craft;
use craft\elements\Asset;
use craft\web\Controller;
use justinholtweb\book\elements\Document;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\models\Source;
use justinholtweb\book\Plugin;
use Throwable;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel side of the document library.
 */
class DocumentsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('book/documents/_index', [
            'title' => Craft::t('book', 'Documents'),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionEdit(?int $documentId = null, ?Document $document = null): Response
    {
        $plugin = Plugin::getInstance();

        if ($document === null) {
            if ($documentId !== null) {
                $document = $plugin->documents->getDocumentById($documentId);

                if (!$document) {
                    throw new NotFoundHttpException(Craft::t('book', 'Document not found.'));
                }
            } else {
                $request = Craft::$app->getRequest();
                $assetId = $request->getQueryParam('assetId');
                $url = $request->getQueryParam('url');

                // “New from a file”: the index posts here, so the form opens already filled in.
                if ($assetId && ($asset = Craft::$app->getAssets()->getAssetById((int)$assetId))) {
                    $document = $plugin->documents->createFromAsset($asset);
                } elseif ($url) {
                    $document = $plugin->documents->createFromUrl((string)$url);
                } else {
                    $document = new Document();
                    $document->setOptions($plugin->getSettings()->getDefaultDocumentOptions());
                }
            }
        }

        $source = $document->getSource();

        return $this->renderTemplate('book/documents/_edit', [
            'document' => $document,
            'options' => $document->getOptions(),
            'source' => $source,
            'format' => $source->getFormat(),
            'resolution' => $source->getIsEmpty() ? null : $document->resolveViewer(),
            'viewerChoices' => $plugin->viewers->choicesFor($source->getIsEmpty() ? null : $source->getFormat()),
            'viewers' => $plugin->viewers->getAll(),
            'isNew' => !$document->id,
            'title' => $document->id ? $document->getUiLabel() : Craft::t('book', 'New document'),
            'settings' => $plugin->getSettings(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $documentId = $request->getBodyParam('documentId');

        if ($documentId) {
            $document = $plugin->documents->getDocumentById((int)$documentId);

            if (!$document) {
                throw new NotFoundHttpException(Craft::t('book', 'Document not found.'));
            }
        } else {
            $document = new Document();
        }

        $document->title = $request->getBodyParam('title', $document->title);
        $document->handle = $request->getBodyParam('handle', $document->handle) ?: null;
        $document->enabled = (bool)$request->getBodyParam('enabled', $document->enabled);

        // An element select posts an array of ids, and an empty one posts `['']`.
        $assetIds = $request->getBodyParam('assetId');
        $assetId = is_array($assetIds) ? (reset($assetIds) ?: null) : $assetIds;

        $document->assetId = $assetId ? (int)$assetId : null;
        $document->url = $document->assetId ? null : $request->getBodyParam('url');

        $posted = $request->getBodyParam('options', []);
        $document->setOptions($document->getOptions()->merge(
            is_array($posted) ? $plugin->documents->normalizePostedOptions($posted) : []
        ));

        if (!$plugin->documents->saveDocument($document)) {
            return $this->asModelFailure($document, Craft::t('book', 'Couldn’t save document.'), 'document');
        }

        return $this->asModelSuccess($document, Craft::t('book', 'Document saved.'), 'document', [
            'id' => $document->id,
            'handle' => $document->handle,
            'embedCode' => $document->getEmbedCode(),
        ]);
    }

    /**
     * @throws NotFoundHttpException
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $documentId = (int)Craft::$app->getRequest()->getRequiredBodyParam('documentId');
        $document = Plugin::getInstance()->documents->getDocumentById($documentId);

        if (!$document) {
            throw new NotFoundHttpException(Craft::t('book', 'Document not found.'));
        }

        if (!Plugin::getInstance()->documents->deleteDocument($document)) {
            return $this->asFailure(Craft::t('book', 'Couldn’t delete document.'));
        }

        return $this->asSuccess(Craft::t('book', 'Document deleted.'));
    }

    /**
     * What Book makes of a file, without saving anything.
     *
     * The edit screen calls this every time the file or the viewer changes, so an author finds
     * out that Google cannot reach their staging URL while they are still looking at the form.
     */
    public function actionResolve(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $assetId = $request->getBodyParam('assetId');
        $assetId = is_array($assetId) ? (reset($assetId) ?: null) : $assetId;
        $url = trim((string)$request->getBodyParam('url', ''));

        if ($assetId && ($asset = Craft::$app->getAssets()->getAssetById((int)$assetId))) {
            $source = Source::fromAsset($asset);
        } elseif ($url !== '') {
            $source = Source::fromUrl($url);
        } else {
            return $this->asJson(['ok' => false]);
        }

        $posted = $request->getBodyParam('options', []);
        $options = $plugin->getSettings()->getDefaultDocumentOptions()->merge(
            is_array($posted) ? $plugin->documents->normalizePostedOptions($posted) : []
        );

        $format = $source->getFormat();
        $resolution = $plugin->viewers->resolve($source, $options);
        $viewer = $plugin->viewers->getByHandle($resolution->viewer);

        return $this->asJson([
            'ok' => true,
            'format' => [
                'handle' => $format->handle,
                'name' => $format->name,
                'viewers' => $format->viewers,
            ],
            'viewer' => [
                'handle' => $resolution->viewer,
                'name' => $viewer?->name,
                'note' => $viewer?->note,
                'thirdParty' => $resolution->getIsThirdParty(),
                'host' => $viewer?->host,
            ],
            'choices' => $plugin->viewers->choicesFor($format),
            'warnings' => $resolution->warnings,
            'downgraded' => $resolution->getWasDowngraded(),
            'size' => $source->getSizeLabel(),
            'filename' => $source->filename,
        ]);
    }

    /**
     * Make a library document out of a file, or hand back the one that already exists.
     *
     * The editor integrations call this: pasting the same brochure into ten entries should
     * produce one library document, not ten, and the dedupe has to be the server's job because
     * only the server can see the library.
     */
    public function actionQuickCreate(): Response
    {
        $this->requireAcceptsJson();
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $assetId = $request->getBodyParam('assetId');
        $assetId = is_array($assetId) ? (reset($assetId) ?: null) : $assetId;
        $url = trim((string)$request->getBodyParam('url', ''));

        if ($assetId) {
            $asset = Craft::$app->getAssets()->getAssetById((int)$assetId);

            if (!$asset) {
                return $this->asFailure(Craft::t('book', 'That asset no longer exists.'));
            }

            $file = $asset;
        } elseif ($url !== '') {
            if (!preg_match('~^https?://~i', $url) && !str_starts_with($url, '/')) {
                return $this->asFailure(Craft::t('book', 'That is not a URL Book can embed.'));
            }

            $file = $url;
        } else {
            return $this->asFailure(Craft::t('book', 'Choose a file, or paste the URL of one.'));
        }

        $existing = $file instanceof Asset
            ? $plugin->documents->findByAsset($file)
            : $plugin->documents->findByUrl($file);

        if ($existing) {
            return $this->asJson(['success' => true, 'reused' => true] + $this->documentPayload($existing));
        }

        $overrides = $request->getBodyParam('options', []);
        $document = $plugin->documents->quickCreate($file, is_array($overrides) ? $overrides : []);

        if (!$document) {
            return $this->asFailure(Craft::t('book', 'Couldn’t create document.'));
        }

        return $this->asJson(['success' => true, 'reused' => false] + $this->documentPayload($document));
    }

    /** The editor picker's list. */
    public function actionList(): Response
    {
        $this->requireAcceptsJson();

        $search = trim((string)Craft::$app->getRequest()->getParam('search', ''));
        $query = Document::find()->status(null)->orderBy(['elements_sites.title' => SORT_ASC])->limit(100);

        if ($search !== '') {
            $query->search($search);
        }

        $documents = [];

        foreach ($query->all() as $document) {
            $documents[] = $this->documentPayload($document) + [
                'enabled' => (bool)$document->enabled,
                'format' => $document->getDocumentFormat()->name,
            ];
        }

        return $this->asJson(['documents' => $documents]);
    }

    /** @return array<string, mixed> */
    private function documentPayload(Document $document): array
    {
        return [
            'id' => $document->id,
            'handle' => $document->handle,
            'title' => $document->getUiLabel(),
            'embedCode' => $document->getEmbedCode(),
            'cpEditUrl' => $document->getCpEditUrl(),
        ];
    }

    /**
     * The document as it would render on the site, for the edit screen's preview pane.
     *
     * Rendered in site template mode, so a site override of `_book/document` is what an author
     * sees — a preview of Book's own template would be a preview of the wrong thing.
     */
    public function actionPreview(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        $assetId = $request->getBodyParam('assetId');
        $assetId = is_array($assetId) ? (reset($assetId) ?: null) : $assetId;
        $url = trim((string)$request->getBodyParam('url', ''));

        if ($assetId && ($asset = Craft::$app->getAssets()->getAssetById((int)$assetId))) {
            $source = Source::fromAsset($asset);
        } elseif ($url !== '') {
            $source = Source::fromUrl($url);
        } else {
            return $this->asJson(['ok' => false, 'html' => '']);
        }

        $posted = $request->getBodyParam('options', []);
        $options = $plugin->getSettings()->getDefaultDocumentOptions()->merge(
            is_array($posted) ? $plugin->documents->normalizePostedOptions($posted) : []
        );

        // A preview that asked the reader for consent first would preview the consent card.
        if ($options->loading === DocumentOptions::LOADING_CLICK) {
            $options = $options->merge(['loading' => DocumentOptions::LOADING_EAGER]);
        }

        try {
            $html = (string)$plugin->renderer->renderSource($source, $options, [
                'label' => (string)$request->getBodyParam('title', ''),
            ]);
        } catch (Throwable $e) {
            Craft::warning('Book could not preview a document: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->asJson(['ok' => false, 'html' => '', 'error' => $e->getMessage()]);
        }

        return $this->asJson(['ok' => true, 'html' => $html]);
    }
}
