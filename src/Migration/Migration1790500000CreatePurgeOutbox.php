<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * The durable purge outbox: one row per (purge, Trident instance), removed
 * only when that instance acknowledged it.
 */
class Migration1790500000CreatePurgeOutbox extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790500000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS `trident_purge_outbox` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `instance` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                `tags` LONGTEXT NOT NULL,
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` BIGINT UNSIGNED NOT NULL,
                `next_attempt_at` BIGINT UNSIGNED NOT NULL,
                `last_error` VARCHAR(1000) NULL,
                `last_error_at` BIGINT UNSIGNED NULL,
                PRIMARY KEY (`id`),
                KEY `idx.trident_purge_outbox.due` (`next_attempt_at`, `id`),
                KEY `idx.trident_purge_outbox.instance` (`instance`)
            ) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
