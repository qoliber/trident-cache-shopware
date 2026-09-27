<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Delivery;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\OutboxStore;

/**
 * The purge outbox on Shopware's database (`trident_purge_outbox`, created by
 * the plugin's migration).
 *
 * `instance` is compared in binary collation: "Edge-1" and "edge-1" are two
 * instances, so rows owed to one never go to the other. Rows are written on
 * Shopware's connection, so inside a DAL write (the backstop rows of
 * BackstopInvalidatorStorage) they commit or roll back with that write. The
 * rows of the gateway are written when Shopware invalidates — after the write
 * committed (delayed invalidation) — each purge's rows in their own
 * transaction (Delivery).
 */
final class DbalOutboxStore implements OutboxStore, ScheduledRows, \Qoliber\Trident\Delivery\ScheduledStore
{
    public const TABLE = 'trident_purge_outbox';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        $this->connection->insert(self::TABLE, [
            'instance' => $instance,
            'tags' => (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    public function recordScheduled(string $instance, array $tags, int $now, int $dueAt): int
    {
        $json = (string) json_encode(array_values($tags), \JSON_THROW_ON_ERROR);
        $exists = $this->connection->fetchOne(
            'SELECT id FROM ' . self::TABLE . ' WHERE instance = :instance AND backstop = 1 AND attempts = 0 AND next_attempt_at > :now AND tags = :tags LIMIT 1',
            ['instance' => $instance, 'now' => $now, 'tags' => $json],
        );
        if ($exists !== false) {
            return 0;
        }
        $this->connection->insert(self::TABLE, [
            'instance' => $instance,
            'tags' => $json,
            'attempts' => 0,
            'created_at' => $now,
            'next_attempt_at' => $dueAt,
            'backstop' => 1,
        ]);

        return 1;
    }

    public function byIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        return $this->entries($this->connection->fetchAllAssociative(
            'SELECT id, instance, tags, attempts FROM ' . self::TABLE . ' WHERE id IN (:ids) ORDER BY id ASC',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ));
    }

    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        if ($instances === []) {
            return [];
        }
        $where = $ignoreBackoff ? '(next_attempt_at <= :now OR attempts > 0)' : 'next_attempt_at <= :now';

        return $this->entries($this->connection->fetchAllAssociative(
            'SELECT id, instance, tags, attempts FROM ' . self::TABLE
            . ' WHERE instance IN (:instances) AND ' . $where
            . ' ORDER BY id ASC LIMIT ' . max(1, $limit),
            ['instances' => array_values($instances), 'now' => $now],
            ['instances' => ArrayParameterType::STRING],
        ));
    }

    public function remove(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return;
        }
        $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    public function fail(array $entries, string $reason, int $now): void
    {
        // One UPDATE per attempt count: each entry keeps its own schedule.
        $byAttempts = [];
        foreach ($entries as $entry) {
            $byAttempts[$entry->attempts][] = $entry->id;
        }
        foreach ($byAttempts as $attempts => $ids) {
            $failures = $attempts + 1;
            $this->connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET attempts = :attempts, next_attempt_at = :next, last_error = :error, last_error_at = :now WHERE id IN (:ids)',
                [
                    'attempts' => $failures,
                    'next' => Backoff::nextAttemptAt($failures, $now),
                    'error' => mb_substr($reason, 0, 1000),
                    'now' => $now,
                    'ids' => $ids,
                ],
                ['ids' => ArrayParameterType::INTEGER],
            );
        }
    }

    public function forget(string $instance): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM ' . self::TABLE . ' WHERE instance = :instance',
            ['instance' => $instance],
        );
    }

    public function stats(int $now): array
    {
        // `scheduled`: backstop rows not due yet (never tried) — not owed yet,
        // so not counted as pending. Rows in their normal grace period are owed.
        $scheduled = 'backstop = 1 AND attempts = 0 AND next_attempt_at > :now';
        $row = $this->connection->fetchAssociative(
            'SELECT COALESCE(SUM(CASE WHEN ' . $scheduled . ' THEN 0 ELSE 1 END), 0) AS pending,
                    COALESCE(SUM(CASE WHEN ' . $scheduled . ' THEN 1 ELSE 0 END), 0) AS scheduled,
                    MIN(CASE WHEN ' . $scheduled . ' THEN NULL ELSE created_at END) AS oldest FROM ' . self::TABLE,
            ['now' => $now],
        ) ?: [];
        $failure = $this->connection->fetchAssociative(
            'SELECT last_error, last_error_at FROM ' . self::TABLE . ' WHERE last_error IS NOT NULL ORDER BY last_error_at DESC, id DESC LIMIT 1'
        ) ?: [];
        $byInstance = [];
        foreach ($this->connection->fetchAllAssociative('SELECT instance, COUNT(*) AS n FROM ' . self::TABLE . ' WHERE NOT (' . $scheduled . ') GROUP BY instance', ['now' => $now]) as $group) {
            $byInstance[(string) $group['instance']] = (int) $group['n'];
        }
        ksort($byInstance);

        return [
            'pending' => (int) ($row['pending'] ?? 0),
            'scheduled' => (int) ($row['scheduled'] ?? 0),
            'oldest_age' => isset($row['oldest']) ? max(0, $now - (int) $row['oldest']) : null,
            'last_error' => isset($failure['last_error']) ? (string) $failure['last_error'] : null,
            'last_error_at' => isset($failure['last_error_at']) ? (int) $failure['last_error_at'] : null,
            'by_instance' => $byInstance,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<OutboxEntry>
     */
    private function entries(array $rows): array
    {
        $entries = [];
        foreach ($rows as $row) {
            $tags = json_decode((string) $row['tags'], true);
            $entries[] = new OutboxEntry(
                (int) $row['id'],
                (string) $row['instance'],
                is_array($tags) ? array_values(array_map('strval', $tags)) : [],
                (int) $row['attempts'],
            );
        }

        return $entries;
    }
}
