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
use Craft;
use craft\elements\Entry;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Links index.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class LinksController extends Controller
{
    // Constants
    // =========================================================================

    private const PER_PAGE = 50;

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
     * @return Response
     */
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $page = max(1, (int)$request->getParam('page', 1));
        $search = trim((string)$request->getParam('search', ''));

        $query = LinkRecord::find()->orderBy(['dateCreated' => SORT_DESC]);

        if ($search !== '') {
            $query->andWhere([
                'or',
                ['like', 'path', $search],
                ['like', 'shortUrl', $search],
                ['like', 'originalUrl', $search],
                ['like', 'title', $search],
            ]);
        }

        $total = (int)$query->count();
        $offset = ($page - 1) * self::PER_PAGE;

        /** @var LinkRecord[] $records */
        $records = $query->offset($offset)->limit(self::PER_PAGE)->all();

        // One query for every entry on the page, rather than one per row.
        $entries = [];

        if ($records !== []) {
            $entryIds = array_values(array_unique(array_map(static fn(LinkRecord $r) => $r->entryId, $records)));

            foreach (Entry::find()->id($entryIds)->status(null)->siteId('*')->all() as $entry) {
                $entries[$entry->id . '_' . $entry->siteId] = $entry;
            }
        }

        return $this->renderTemplate('short-io/links/index.twig', [
            'records' => $records,
            'entries' => $entries,
            'search' => $search,
            'page' => $page,
            'total' => $total,
            'perPage' => self::PER_PAGE,
            'totalPages' => (int)ceil($total / self::PER_PAGE),
            'settings' => Plugin::getInstance()->getSettings(),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_LINKS),
        ]);
    }

    /**
     * Re-syncs one link from its entry.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionResync(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LINKS);

        $record = $this->_record();

        $entry = Entry::find()
            ->id($record->entryId)
            ->siteId($record->siteId)
            ->status(null)
            ->one();

        if (!$entry instanceof Entry) {
            Craft::$app->getSession()->setError(Craft::t('short-io', 'That link’s entry no longer exists.'));

            return $this->redirectToPostedUrl();
        }

        $links = Plugin::getInstance()->links;
        $links->suspend();

        try {
            $error = $links->sync($entry);
        } finally {
            $links->resume();
        }

        if ($error !== null) {
            Craft::$app->getSession()->setError($error);
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('short-io', 'Link re-synced.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes a link, both here and at Short.io.
     *
     * @return Response
     * @throws NotFoundHttpException
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LINKS);

        $record = $this->_record();

        Plugin::getInstance()->client->deleteLink($record->linkIdString);
        $record->delete();

        Craft::$app->getSession()->setNotice(Craft::t('short-io', 'Link deleted.'));

        return $this->redirectToPostedUrl();
    }

    // Private Methods
    // =========================================================================

    /**
     * @return LinkRecord
     * @throws NotFoundHttpException
     */
    private function _record(): LinkRecord
    {
        $id = (int)Craft::$app->getRequest()->getRequiredBodyParam('id');

        /** @var LinkRecord|null $record */
        $record = LinkRecord::findOne(['id' => $id]);

        if ($record === null) {
            throw new NotFoundHttpException();
        }

        return $record;
    }
}
