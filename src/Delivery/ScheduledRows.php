<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Delivery;

/**
 * An outbox that keeps backstop rows apart from owed ones: recorded with a
 * marker, deduplicated (an identical scheduled tag set per instance is
 * recorded once), and reported as `scheduled`.
 */
interface ScheduledRows
{
    /**
     * @param list<string> $tags
     *
     * @return int 1 when recorded, 0 when an identical scheduled row exists
     */
    public function recordScheduled(string $instance, array $tags, int $now, int $dueAt): int;
}
