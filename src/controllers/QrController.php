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
 * Serves a QR code as a file download.
 *
 * Rendering doesn't need a controller: Short.io's QR images live on a public
 * URL, so an <img> tag points straight at it. A download does, because a
 * cross-origin `download` attribute is ignored by browsers - the file has to be
 * served from this origin to arrive as an attachment.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class QrController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission(Plugin::PERMISSION_VIEW_LINKS);

        return parent::beforeAction($action);
    }

    /**
     * Sends a link's QR code as a download.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionDownload(): Response
    {
        $idString = (string)Craft::$app->getRequest()->getRequiredParam('linkId');

        if (!Qr::isValidId($idString)) {
            throw new NotFoundHttpException();
        }

        /** @var LinkRecord|null $record */
        $record = LinkRecord::findOne(['linkIdString' => $idString]);

        // Only links this site manages. Without this the action would be a
        // download proxy for every link on the Short.io account.
        if ($record === null) {
            throw new NotFoundHttpException();
        }

        $qr = Plugin::getInstance()->qr;
        $options = $qr->normalizeOptions();
        $bytes = $qr->getBytes($idString, $options);

        if ($bytes === null) {
            // A deleted link or a stale bookmark is routine, not a fault.
            Craft::info("No QR code available for {$idString}.", __METHOD__);
            throw new NotFoundHttpException();
        }

        $filename = str_replace('/', '-', sprintf('%s-%s.%s', $record->domain, $record->path, $options['type']));

        return Craft::$app->getResponse()->sendContentAsFile($bytes, $filename, [
            'mimeType' => $qr->contentType($options['type']),
        ]);
    }
}
