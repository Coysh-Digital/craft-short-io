<?php
/**
 * Short.io plugin for Craft CMS 4.x & 5.x
 *
 * @link      https://coysh.digital
 * @copyright Copyright (c) Coysh Digital
 */

namespace coyshdigital\shortio\migrations;

use craft\db\Migration;

/**
 * Adds per-link campaign tracking.
 *
 * @author Coysh Digital
 * @since 1.1.0
 */
class m260819_120000_add_utm extends Migration
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
        if (!$this->db->columnExists(self::LINKS, 'utmEnabled')) {
            $this->addColumn(self::LINKS, 'utmEnabled', $this->boolean()->notNull()->defaultValue(true));
        }

        foreach (['utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent'] as $column) {
            if (!$this->db->columnExists(self::LINKS, $column)) {
                $this->addColumn(self::LINKS, $column, $this->string());
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        foreach (['utmEnabled', 'utmSource', 'utmMedium', 'utmCampaign', 'utmTerm', 'utmContent'] as $column) {
            if ($this->db->columnExists(self::LINKS, $column)) {
                $this->dropColumn(self::LINKS, $column);
            }
        }

        return true;
    }
}
