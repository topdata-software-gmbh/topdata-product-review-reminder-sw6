<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class V1767214800_CreateReviewReminderLogTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1767214800;
    }

    public function update(Connection $connection): void
    {
        // created_at / updated_at are not optional: EntityDefinition::defaultFields()
        // adds a CreatedAtField and an UpdatedAtField to EVERY definition, so the
        // DAL selects both columns on any read. Omitting them fails at query time
        // with "Unknown column ... created_at", not at migration time.
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `topdata_product_review_reminder_log` (
                `id` BINARY(16) NOT NULL,
                `order_id` BINARY(16) NOT NULL,
                `order_version_id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `sales_channel_id` BINARY(16) NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `review_product_ids` TEXT NULL,
                `sent_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `topdata_product_review_reminder_uniq_order` (`order_id`),
                KEY `topdata_product_review_reminder_idx_customer` (`customer_id`),
                KEY `topdata_product_review_reminder_idx_sent_at` (`sent_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_review_reminder_log`');
    }
}