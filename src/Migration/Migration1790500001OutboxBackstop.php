<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Marks backstop rows (recorded when Shopware stores a delayed invalidation),
 * so they are deduplicated and reported apart from owed purges.
 */
class Migration1790500001OutboxBackstop extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1790500001;
    }

    public function update(Connection $connection): void
    {
        $columns = $connection->fetchFirstColumn("SHOW COLUMNS FROM `trident_purge_outbox` LIKE 'backstop'");
        if ($columns === []) {
            $connection->executeStatement('ALTER TABLE `trident_purge_outbox` ADD COLUMN `backstop` TINYINT(1) NOT NULL DEFAULT 0, ADD KEY `idx.trident_purge_outbox.backstop` (`backstop`, `instance`)');
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
