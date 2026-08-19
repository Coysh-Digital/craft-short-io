<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Install migration.
 *
 * @author Coysh Digital
 * @since 1.0.0
 */
class Install extends Migration
{
    // Constants
    // =========================================================================

    public const LINKS = '{{%shortio_links}}';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::LINKS)) {
            return true;
        }

        $this->_createTable();
        $this->_createIndexes();
        $this->_addForeignKeys();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Unlike craft-dub's, this really does drop the table. Rows are
        // worthless without the plugin - Short.io stores nothing that points
        // back at them - and leaving them behind breaks a clean reinstall.
        $this->dropTableIfExists(self::LINKS);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * @return void
     */
    private function _createTable(): void
    {
        $this->createTable(self::LINKS, [
            'id' => $this->primaryKey(),
            'entryId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            // The only identifier DELETE /links/{id} accepts. The numeric id
            // can't delete, so this column is not optional.
            'linkIdString' => $this->string()->notNull(),
            // Short.io's numeric id, kept as the statistics fallback identifier.
            'linkId' => $this->string(),
            // Denormalised from the create response so archive and delete still
            // work when the domains cache is cold.
            'domainId' => $this->integer(),
            'domain' => $this->string()->notNull(),
            'path' => $this->string()->notNull(),
            'shortUrl' => $this->string(500)->notNull(),
            'originalUrl' => $this->text()->notNull(),
            'title' => $this->string(),
            'suspended' => $this->boolean()->notNull()->defaultValue(false),
            // Campaign tracking. Short.io folds these into the destination URL
            // itself, so they are stored here as the source of truth.
            'utmEnabled' => $this->boolean()->notNull()->defaultValue(true),
            'utmSource' => $this->string(),
            'utmMedium' => $this->string(),
            'utmCampaign' => $this->string(),
            'utmTerm' => $this->string(),
            'utmContent' => $this->string(),
            'clicks' => $this->integer()->notNull()->defaultValue(0),
            'humanClicks' => $this->integer()->notNull()->defaultValue(0),
            'clicksUpdated' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
    }

    /**
     * @return void
     */
    private function _createIndexes(): void
    {
        // One link per entry per site. This is the de-facto key.
        $this->createIndex(null, self::LINKS, ['entryId', 'siteId'], true);

        // Two rows must never claim the same remote link.
        $this->createIndex(null, self::LINKS, ['linkIdString'], true);

        // Deliberately NOT unique: Short.io's per-domain caseSensitive flag
        // means "Foo" and "foo" can be two legitimate links, which MySQL's
        // default collation would reject. Path uniqueness is Short.io's job.
        $this->createIndex(null, self::LINKS, ['domain', 'path']);

        $this->createIndex(null, self::LINKS, ['siteId']);

        // Oldest-first ordering for the stats refresh command.
        $this->createIndex(null, self::LINKS, ['clicksUpdated']);
    }

    /**
     * @return void
     */
    private function _addForeignKeys(): void
    {
        $this->addForeignKey(null, self::LINKS, ['entryId'], Table::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, self::LINKS, ['siteId'], Table::SITES, ['id'], 'CASCADE', null);
    }
}
