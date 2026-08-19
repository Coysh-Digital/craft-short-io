<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\controllers;

use coyshdigital\shortio\Plugin;
use coyshdigital\shortio\records\LinkRecord;
use coyshdigital\shortio\services\Qr;
use Craft;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Streams QR codes.
 *
 * Short.io's QR endpoint needs the secret API key, so there is no URL a browser
 * can fetch directly. This action is the stand-in: it fetches once, caches the
 * bytes, and serves them from Craft.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class QrController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Anonymous access is gated by a signed token *and* the qrPublic setting,
     * both checked in actionRender().
     */
    protected int|bool|array $allowAnonymous = ['render'];

    // Public Methods
    // =========================================================================

    /**
     * Renders a QR code as an image.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionRender(): Response
    {
        $request = Craft::$app->getRequest();
        $qr = Plugin::getInstance()->qr;

        $token = $request->getParam('q');

        if ($token !== null && $token !== '') {
            $payload = $qr->readToken((string)$token);

            if ($payload === null || !Plugin::getInstance()->getSettings()->qrPublic) {
                throw new NotFoundHttpException();
            }

            $idString = $payload['idString'];
            $options = $payload['options'];
        } else {
            // Control panel mode: a logged-in user with the view permission.
            $this->requireCpRequest();
            $this->requireLogin();
            $this->requirePermission(Plugin::PERMISSION_VIEW_LINKS);

            $idString = (string)$request->getRequiredParam('linkId');
            $options = $qr->normalizeOptions([
                'size' => $request->getParam('size', ''),
                'type' => $request->getParam('type', ''),
            ]);
        }

        if (!Qr::isValidId($idString)) {
            throw new NotFoundHttpException();
        }

        // Only links this site actually owns. Without this the action would be
        // a general-purpose proxy to every link on the Short.io account.
        if (!LinkRecord::find()->where(['linkIdString' => $idString])->exists()) {
            throw new NotFoundHttpException();
        }

        $bytes = $qr->getBytes($idString, $options);

        if ($bytes === null) {
            // A deleted link or a stale bookmark is routine, not a fault.
            Craft::info("No QR code available for {$idString}.", __METHOD__);
            throw new NotFoundHttpException();
        }

        return $this->_image($bytes, $qr->contentType($options['type']), $token !== null);
    }

    /**
     * Sends a QR code as a download.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionDownload(): Response
    {
        $this->requireCpRequest();
        $this->requireLogin();
        $this->requirePermission(Plugin::PERMISSION_VIEW_LINKS);

        $request = Craft::$app->getRequest();
        $qr = Plugin::getInstance()->qr;
        $idString = (string)$request->getRequiredParam('linkId');

        if (!Qr::isValidId($idString)) {
            throw new NotFoundHttpException();
        }

        /** @var LinkRecord|null $record */
        $record = LinkRecord::findOne(['linkIdString' => $idString]);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $options = $qr->normalizeOptions([
            'size' => $request->getParam('size', ''),
            'type' => $request->getParam('type', ''),
        ]);

        $bytes = $qr->getBytes($idString, $options);

        if ($bytes === null) {
            throw new NotFoundHttpException();
        }

        $filename = sprintf('%s-%s.%s', $record->domain, $record->path, $options['type']);

        return Craft::$app->getResponse()->sendContentAsFile(
            $bytes,
            str_replace('/', '-', $filename),
            ['mimeType' => $qr->contentType($options['type'])]
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $bytes
     * @param string $contentType
     * @param bool $public
     * @return Response
     */
    private function _image(string $bytes, string $contentType, bool $public): Response
    {
        $response = Craft::$app->getResponse();
        $etag = '"' . sha1($bytes) . '"';
        $maxAge = Plugin::getInstance()->getSettings()->qrCacheDuration;

        if (Craft::$app->getRequest()->getHeaders()->get('If-None-Match') === $etag) {
            $response->setStatusCode(304);
            $response->format = Response::FORMAT_RAW;
            $response->content = '';

            return $response;
        }

        $response->format = Response::FORMAT_RAW;
        $response->content = $bytes;
        $response->getHeaders()
            ->set('Content-Type', $contentType)
            ->set('Content-Length', (string)strlen($bytes))
            ->set('ETag', $etag)
            ->set('Cache-Control', sprintf('%s, max-age=%d', $public ? 'public' : 'private', $maxAge));

        return $response;
    }
}
