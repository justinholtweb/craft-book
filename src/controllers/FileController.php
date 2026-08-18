<?php

namespace justinholtweb\book\controllers;

use Craft;
use craft\base\LocalFsInterface;
use craft\elements\Asset;
use craft\helpers\FileHelper;
use craft\web\Controller;
use justinholtweb\book\models\DocumentOptions;
use justinholtweb\book\Plugin;
use justinholtweb\book\services\Delivery;
use Throwable;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Serves an asset through Craft.
 *
 * This is what makes a document in a private volume showable at all: the browser's PDF viewer,
 * Book's own inline rendering and a download button all need a URL, and a volume with no public
 * URLs has none to give.
 *
 * The rule the whole route rests on: **a file with no public volume URL is never served without
 * a token Book itself minted.** The token carries the access rule, HMAC-signed, so the decision
 * cannot be edited by whoever is asking. An unsigned request may only ever reach a file that is
 * already published at its own volume URL, where serving it changes nothing.
 */
class FileController extends Controller
{
    public array|bool|int $allowAnonymous = true;

    /**
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
     */
    public function actionServe(string $uid, ?string $filename = null): Response
    {
        $asset = Asset::find()->uid($uid)->status(null)->one();

        if (!$asset instanceof Asset) {
            throw new NotFoundHttpException(Craft::t('book', 'File not found.'));
        }

        $request = Craft::$app->getRequest();
        $delivery = Plugin::getInstance()->delivery;
        $token = $request->getQueryParam(Delivery::PARAM);

        if ($token !== null) {
            $result = $delivery->verify((string)$token, $uid);

            if (!$result['valid']) {
                // Every reason is the same 403 to the reader. A response that distinguished
                // “expired” from “forged” would be a signing oracle.
                Craft::info("Book refused $uid: {$result['reason']}", Plugin::LOG_CATEGORY);

                throw new ForbiddenHttpException(Craft::t('book', 'This link is no longer valid.'));
            }

            $access = $result['access'];
            $disposition = $result['disposition'];
        } else {
            // No token: the file has to be one that is already public by its own URL, or Book
            // would be turning an unguessable uid into a way around volume permissions.
            if ($asset->getUrl() === null) {
                throw new ForbiddenHttpException(Craft::t('book', 'This file is not available at this address.'));
            }

            $access = DocumentOptions::ACCESS_PUBLIC;
            $disposition = $request->getQueryParam('dl') ? Delivery::DISPOSITION_ATTACHMENT : Delivery::DISPOSITION_INLINE;
        }

        if ($access === DocumentOptions::ACCESS_LOGIN) {
            // `requireLogin()` sends a redirect to the login screen and ends the request, which
            // is more use than a bare 403 — this URL is followed as a link about as often as it
            // is loaded in a frame, and “log in and come back” works in both.
            $this->requireLogin();
        }

        // The filename in the path is cosmetic — the uid is the identifier — but a mismatch is
        // worth noticing, because it usually means a renamed asset and a stale cached URL.
        if ($filename !== null && $filename !== $asset->getFilename()) {
            Craft::info("Book served $uid under a stale filename ($filename)", Plugin::LOG_CATEGORY);
        }

        return $this->stream($asset, $disposition);
    }

    /**
     * @throws NotFoundHttpException
     */
    private function stream(Asset $asset, string $disposition): Response
    {
        $response = Craft::$app->getResponse();
        $mimeType = $this->mimeType($asset);
        $inline = $disposition === Delivery::DISPOSITION_INLINE;

        // A local volume gives a real path, and `sendFile` on a real path is what lets Yii
        // answer a Range request — which is how a browser's PDF viewer fetches page 40 of a
        // 300-page file without downloading the first 39.
        $path = $this->localPath($asset);

        if ($path !== null) {
            $response->sendFile($path, $asset->getFilename(), [
                'mimeType' => $mimeType,
                'inline' => $inline,
            ]);
        } else {
            // A remote filesystem's stream is not seekable, so ranges are off the table; Yii
            // sends the whole thing, which is correct if not clever.
            try {
                $stream = $asset->getStream();
            } catch (Throwable $e) {
                Craft::warning('Book could not open ' . $asset->getFilename() . ': ' . $e->getMessage(), Plugin::LOG_CATEGORY);

                throw new NotFoundHttpException(Craft::t('book', 'File not found.'));
            }

            $response->sendStreamAsFile($stream, $asset->getFilename(), array_filter([
                'mimeType' => $mimeType,
                'inline' => $inline,
                // Given explicitly so Yii does not seek to the end to measure the file — which
                // a remote filesystem's stream may not be able to do.
                'fileSize' => $asset->size !== null ? (int)$asset->size : null,
            ], fn($value) => $value !== null));
        }

        // Private files are cached by the browser and by nobody else. A shared cache holding a
        // login-gated PDF is the sort of bug that only shows up in production, once.
        $response->getHeaders()
            ->set('X-Content-Type-Options', 'nosniff')
            ->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }

    /**
     * The asset's path on this machine, when it has one.
     *
     * The formula is Craft's own, from `Asset::getImageTransformSourcePath()`: the filesystem's
     * root, the *volume's* subpath, then the asset's path within the volume. Skipping the
     * subpath is the mistake that works on every volume until somebody uses one.
     */
    private function localPath(Asset $asset): ?string
    {
        try {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();

            if (!$fs instanceof LocalFsInterface) {
                return null;
            }

            $path = FileHelper::normalizePath($fs->getRootPath() . DIRECTORY_SEPARATOR . $volume->getSubpath() . $asset->getPath());

            return is_file($path) ? $path : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function mimeType(Asset $asset): string
    {
        try {
            return $asset->getMimeType() ?: 'application/octet-stream';
        } catch (Throwable) {
            return 'application/octet-stream';
        }
    }
}
