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
use craft\helpers\AdminTable;
use craft\helpers\Html;
use craft\i18n\Locale;
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
        return $this->renderTemplate('short-io/links/index.twig', [
            'settings' => Plugin::getInstance()->getSettings(),
            'perPage' => self::PER_PAGE,
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_LINKS),
        ]);
    }

    /**
     * Feeds Craft.VueAdminTable, in the shape it insists on: a `pagination`
     * object built by AdminTable::paginationLinks() and a flat `data` array
     * whose keys are the column names.
     *
     * @return Response
     */
    public function actionTableData(): Response
    {
        $this->requireAcceptsJson();

        $page = max(1, (int)$this->request->getParam('page', 1));
        $limit = max(1, (int)$this->request->getParam('per_page', self::PER_PAGE));
        $search = trim((string)$this->request->getParam('search', ''));

        // The table posts the column name it was given, so these are the
        // `name` keys from the template's column definitions.
        $orderBy = match ($this->request->getParam('sort.0.field')) {
            '__slot:title' => 'shortUrl',
            'clicks' => 'clicks',
            default => 'dateCreated',
        };
        $sortDir = match ($this->request->getParam('sort.0.direction')) {
            'asc' => SORT_ASC,
            default => SORT_DESC,
        };

        $query = LinkRecord::find()->orderBy([$orderBy => $sortDir]);

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

        /** @var LinkRecord[] $records */
        $records = $query->offset(($page - 1) * $limit)->limit($limit)->all();

        // Anything on this page whose figures have aged out gets refreshed in
        // the background, so click counts stay current without a scheduled
        // command. The rows render from the snapshot either way.
        Plugin::getInstance()->stats->queueRefresh($records);

        return $this->asSuccess(data: [
            'pagination' => AdminTable::paginationLinks($page, $total, $limit),
            'data' => $this->_rows($records),
        ]);
    }

    /**
     * Re-syncs links from their entries.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     */
    public function actionResync(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LINKS);

        $links = Plugin::getInstance()->links;
        $errors = [];
        $synced = 0;

        foreach ($this->_records() as $record) {
            $entry = Entry::find()
                ->id($record->entryId)
                ->siteId($record->siteId)
                ->status(null)
                ->one();

            if (!$entry instanceof Entry) {
                $errors[] = Craft::t('short-io', 'That link’s entry no longer exists.');
                continue;
            }

            $links->suspend();

            try {
                $error = $links->sync($entry, null, true);
            } finally {
                $links->resume();
            }

            if ($error !== null) {
                $errors[] = $error;
            } else {
                $synced++;
            }
        }

        if ($errors !== []) {
            return $this->asFailure($errors[0]);
        }

        return $this->asSuccess(Craft::t('short-io', '{n, plural, =1{Link} other{# links}} re-synced.', ['n' => $synced]));
    }

    /**
     * Deletes a link, both here and at Short.io.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE_LINKS);

        foreach ($this->_records() as $record) {
            Plugin::getInstance()->client->deleteLink($record->linkIdString);
            $record->delete();
        }

        return $this->asSuccess(Craft::t('short-io', 'Link deleted.'));
    }

    // Private Methods
    // =========================================================================

    /**
     * The records one of the actions was asked to act on.
     *
     * Craft.VueAdminTable posts `id` for its delete button and `ids[]` for a
     * toolbar action, and the same actions are still postable from a plain
     * form, so both shapes are accepted.
     *
     * @return LinkRecord[]
     * @throws NotFoundHttpException
     */
    private function _records(): array
    {
        $ids = $this->request->getBodyParam('ids');

        if (!is_array($ids) || $ids === []) {
            $ids = [$this->request->getRequiredBodyParam('id')];
        }

        $ids = array_values(array_filter(array_map('intval', $ids)));

        /** @var LinkRecord[] $records */
        $records = $ids !== [] ? LinkRecord::findAll(['id' => $ids]) : [];

        if ($records === []) {
            throw new NotFoundHttpException();
        }

        return $records;
    }

    /**
     * Shapes records as admin table rows. Cell values are rendered as HTML, so
     * anything interpolated into one has to be encoded here.
     *
     * @param LinkRecord[] $records
     * @return array
     */
    private function _rows(array $records): array
    {
        $entries = $this->_entries($records);
        $formatter = Craft::$app->getFormatter();
        $rows = [];

        foreach ($records as $record) {
            $entry = $entries[$record->entryId . '_' . $record->siteId] ?? null;
            $destination = $record->originalUrl;

            $rows[] = [
                'id' => $record->id,
                // The title slot renders `title` as a link to `url`, with a
                // status dot when `status` is set.
                'title' => preg_replace('#^https?://#', '', $record->shortUrl),
                'name' => $record->path,
                'url' => $record->shortUrl,
                'status' => !$record->suspended,
                'entry' => $entry !== null
                    ? Html::a(
                        // An entry type with its Title field turned off has no
                        // title, so fall back to something recognisable.
                        Html::encode($entry->title ?: $entry->slug ?: Craft::t('short-io', 'Untitled')),
                        $entry->getCpEditUrl() ?? '#'
                    )
                    : Html::tag('span', Craft::t('short-io', 'Entry missing'), ['class' => 'light']),
                'destination' => Html::tag('span', Html::encode(
                    mb_strlen($destination) > 60 ? mb_substr($destination, 0, 60) . '…' : $destination
                ), ['title' => Html::encode($destination), 'class' => 'light']),
                'clicks' => $record->clicks !== $record->humanClicks
                    ? sprintf(
                        '%s %s',
                        $formatter->asDecimal($record->clicks, 0),
                        Html::tag('span', Craft::t('short-io', '({n} human)', [
                            'n' => $formatter->asDecimal($record->humanClicks, 0),
                        ]), ['class' => 'light'])
                    )
                    : $formatter->asDecimal($record->clicks, 0),
                'dateCreated' => Html::encode($formatter->asDate($record->dateCreated, Locale::LENGTH_SHORT)),
            ];
        }

        return $rows;
    }

    /**
     * One query for every entry on the page, rather than one per row.
     *
     * @param LinkRecord[] $records
     * @return Entry[]
     */
    private function _entries(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $entryIds = array_values(array_unique(array_map(static fn(LinkRecord $r) => $r->entryId, $records)));
        $entries = [];

        foreach (Entry::find()->id($entryIds)->status(null)->siteId('*')->all() as $entry) {
            $entries[$entry->id . '_' . $entry->siteId] = $entry;
        }

        return $entries;
    }
}
