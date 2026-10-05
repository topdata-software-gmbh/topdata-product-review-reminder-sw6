<?php declare(strict_types=1);

namespace Topdata\TopdataProductReviewReminderSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class V1790944253_CreateReviewReminderConsentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790944253;
    }

    public function update(Connection $connection): void
    {
        // created_at / updated_at are not optional: EntityDefinition::defaultFields()
        // adds a CreatedAtField and an UpdatedAtField to EVERY definition, so the
        // DAL selects both columns on any read.
        //
        // UNIQUE on customer_id is what makes "one consent row per customer"
        // enforceable rather than merely intended: without it two concurrent
        // requests could both insert a grant and the "latest" row would be
        // arbitrary. Same reason the log table carries a unique index.
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `topdata_product_review_reminder_consent` (
                `id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `granted_at` DATETIME(3) NOT NULL,
                `revoked_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `topdata_product_review_reminder_consent_uniq_customer` (`customer_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        SQL;

        $connection->executeStatement($sql);
    }

    public function updateDestructive(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE IF EXISTS `topdata_product_review_reminder_consent`');
    }
}