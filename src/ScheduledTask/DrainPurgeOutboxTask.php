<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

/**
 * Retries purges an instance did not acknowledge (backoff per row).
 */
class DrainPurgeOutboxTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'trident.purge_outbox_drain';
    }

    public static function getDefaultInterval(): int
    {
        return 60;
    }
}
