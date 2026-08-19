<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\records;

use craft\db\ActiveRecord;

/**
 * One Short.io link, tied to one entry on one site.
 *
 * Short.io has no equivalent of Dub's externalId - it stores nothing that points
 * back at Craft - so this table is the only record of which link belongs to
 * which entry.
 *
 * @property int $id
 * @property int $entryId
 * @property int $siteId
 * @property string $linkIdString
 * @property string|null $linkId
 * @property int|null $domainId
 * @property string $domain
 * @property string $path
 * @property string $shortUrl
 * @property string $originalUrl
 * @property string|null $title
 * @property bool $suspended
 * @property int $clicks
 * @property int $humanClicks
 * @property string|null $clicksUpdated
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class LinkRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return '{{%shortio_links}}';
    }
}
