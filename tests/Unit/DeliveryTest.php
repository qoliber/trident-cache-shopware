<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Delivery\Backoff;
use Qoliber\Trident\Delivery\OutboxEntry;
use Qoliber\Trident\Delivery\OutboxStore;
use Qoliber\Trident\Testing\FakeTransport;
use Qoliber\TridentShopware\Config\SettingsResolver;
use Psr\Log\AbstractLogger;
use Qoliber\TridentShopware\Delivery\Delivery;
use Qoliber\TridentShopware\Delivery\ScheduledRows;
use Qoliber\TridentShopware\Tags\TagPolicy;

final class DeliveryTest extends TestCase
{
    private function delivery(FakeTransport $t, InMemoryStoreBase $store): Delivery
    {
        $settings = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{"edge-1":{"api_url":"http://edge-1:9301"},"edge-2":{"api_url":"http://edge-2:9301"}}', 'TRIDENT_API_TOKEN' => 't'], []);

        return new Delivery($store, $settings, new TagPolicy(), $t, static fn (): int => 1000);
    }

    public function testAPurgeIsRecordedForEveryInstanceAndRemovedOnlyWhenAcknowledged(): void
    {
        $store = new InMemoryScheduledStore();
        $t = (new FakeTransport())->answer('edge-1', 200, '{"purged":1,"mode":"soft"}')->answer('edge-2', 401, '{"error":"Unauthorized"}');
        $d = $this->delivery($t, $store);
        self::assertSame(2, $d->record(['product-a']));
        $report = $d->flush();
        self::assertSame(1, $report->delivered);
        self::assertSame(1, $report->failed);
        $owed = array_values(array_filter($store->rows, static fn (array $r): bool => !$r['backstop']));
        self::assertSame(['edge-2'], array_map(static fn (array $r): string => $r['instance'], $owed), 'only the refused instance keeps its row');
        self::assertSame(1, $owed[0]['attempts']);
        self::assertSame(['product-a', 'sw_tag_overflow'], $owed[0]['tags'], 'product-a is not a product id: general overflow');
        $second = array_values(array_filter($store->rows, static fn (array $r): bool => $r['backstop']));
        self::assertSame(['edge-1', 'edge-2'], array_map(static fn (array $r): string => $r['instance'], $second), 'a second delivery of each purge is scheduled (the editor race)');
        self::assertSame(1000 + Delivery::REDELIVER_AFTER, $second[0]['due']);
    }

    /**
     * A second delivery that has come due (or a failed purge) must not wait for
     * Shopware's scheduled task: the next purging request drains it too.
     */
    public function testFlushAlsoDeliversDueRowsOfEarlierRequests(): void
    {
        $store = new InMemoryScheduledStore();
        $store->record('edge-1', ['product-old'], 990, 1000);
        $t = (new FakeTransport())->answer('edge-1', 200, '{"purged":1,"mode":"soft"}')->answer('edge-2', 200, '{"purged":1,"mode":"soft"}');
        $d = $this->delivery($t, $store);
        $d->record(['product-a']);
        self::assertSame(3, $d->flush()->delivered, 'its own two rows and the due row of an earlier request');
        self::assertSame([], array_values(array_filter($store->rows, static fn (array $r): bool => in_array('product-old', $r['tags'], true))));
    }

    public function testDrainAllDeliversScheduledAndBackstopRowsNow(): void
    {
        $store = new InMemoryScheduledStore();
        $t = (new FakeTransport())->answer('edge-1', 200, '{"purged":1,"mode":"soft"}')->answer('edge-2', 200, '{"purged":1,"mode":"soft"}');
        $d = $this->delivery($t, $store);
        $d->recordDelayed(['product-a'], 1360);
        self::assertSame(0, $d->drain()->delivered, 'the backstop is not due');
        self::assertSame(2, $d->drainAll()->delivered, '--now delivers it');
        self::assertSame([], $store->rows);
    }

    public function testClearIsThePurgeOfTheShopTag(): void
    {
        $store = new InMemoryStore();
        $d = $this->delivery(new FakeTransport(), $store);
        $d->recordAll();
        self::assertSame([['all'], ['all']], array_values(array_map(static fn (array $r): array => $r['tags'], $store->rows)));
    }

    public function testRowsOfARemovedInstanceAreReportedNotDelivered(): void
    {
        $store = new InMemoryStore();
        $store->record('edge-old', ['x'], 1000, 1000);
        $d = $this->delivery(new FakeTransport(), $store);
        self::assertSame(['edge-old' => 1], $d->status()['orphaned']);
        self::assertSame(0, $d->drain()->delivered);
    }

    /**
     * A 30k-product import: one row with every tag would be ~1.3 MB, answered
     * 413 on every retry, forever. Rows are chunked; every chunk is delivered.
     */
    public function testAHugeTagSetIsChunkedAndDelivered(): void
    {
        $store = new InMemoryScheduledStore();
        $t = new FakeTransport();
        $d = $this->delivery($t, $store);
        $tags = [];
        for ($i = 0; $i < 30000; ++$i) {
            $tags[] = sprintf('product-%032x', $i);
        }
        $rows = $d->record($tags);
        self::assertSame(2 * 31, $rows, '30001 tags (with the overflow tag) in chunks of 1000, per instance');
        foreach ($store->rows as $row) {
            self::assertLessThanOrEqual(1000, count($row['tags']));
        }
        $report = $d->flush();
        self::assertSame(62, $report->delivered);
        self::assertSame([], array_filter($store->rows, static fn (array $r): bool => !$r['backstop']), 'every chunk delivered; only the scheduled second deliveries remain');
        foreach ($t->requests as $r) {
            self::assertLessThanOrEqual(1000, count(json_decode((string) $r['body'], true)['tags']));
        }
    }

    public function testAllRowsOfAPurgeAreWrittenInOneTransaction(): void
    {
        $store = new InMemoryStore();
        $settings = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{"edge-1":{"api_url":"http://edge-1:9301"},"edge-2":{"api_url":"http://edge-2:9301"}}'], []);
        $calls = 0;
        $d = new Delivery($store, $settings, new TagPolicy(), new FakeTransport(), static fn (): int => 1000, static function (callable $work) use (&$calls): mixed {
            ++$calls;

            return $work();
        });
        $d->record(['product-a']);
        self::assertSame(1, $calls);
        self::assertCount(2, $store->rows);
    }

    public function testBackstopRowsAreDueAfterShopwaresTaskAndNotDeliveredBefore(): void
    {
        $store = new InMemoryStore();
        $d = $this->delivery(new FakeTransport(), $store);
        self::assertSame(2, $d->recordDelayed(['product-a'], 1360));
        self::assertSame(0, $d->drain()->delivered, 'not due yet');
        self::assertSame([1360, 1360], array_values(array_map(static fn (array $r): int => $r['due'], $store->rows)));
    }

    /**
     * The outbox fails on its second write (chunk 2 of edge-1): nothing of the
     * purge may stay half-recorded. The transaction rolls back, every tag is
     * sent directly at once, and the loss is logged as critical with the tags.
     */
    public function testAFailedChunkRollsTheWholeRecordBackAndSendsDirectly(): void
    {
        $store = new InMemoryStore();
        $store->failOnWrite = 2;
        $settings = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{"edge-1":{"api_url":"http://edge-1:9301"},"edge-2":{"api_url":"http://edge-2:9301"}}', 'TRIDENT_API_TOKEN' => 't'], []);
        $rollbacks = 0;
        $transaction = static function (callable $work) use ($store, &$rollbacks): mixed {
            $snapshot = $store->rows;
            try {
                return $work();
            } catch (\Throwable $e) {
                $store->rows = $snapshot;
                ++$rollbacks;
                throw $e;
            }
        };
        $logger = new RecordingLogger();
        $t = new FakeTransport();
        $d = new Delivery($store, $settings, new TagPolicy(), $t, static fn (): int => 1000, $transaction, $logger);
        $tags = [];
        for ($i = 0; $i < 1500; ++$i) {
            $tags[] = sprintf('product-%032x', $i);
        }

        self::assertSame(0, $d->record($tags));
        self::assertSame(1, $rollbacks);
        self::assertSame([], $store->rows, 'no partial record survives');
        self::assertCount(4, $t->requests, 'two chunks sent directly to each of two instances');
        $sent = [];
        foreach ($t->requests as $r) {
            $sent = array_merge($sent, json_decode((string) $r['body'], true)['tags']);
        }
        self::assertSame(2 * 1501, count($sent), 'every tag, with the overflow tag, reached both instances');
        self::assertSame('critical', $logger->records[0]['level'] ?? null);
        self::assertContains($tags[0], $logger->records[0]['context']['tags']);
        self::assertSame(0, $d->flush()->delivered, 'nothing is delivered twice');
        self::assertCount(4, $t->requests);
    }

    public function testAnIdenticalScheduledTagSetIsRecordedOncePerInstance(): void
    {
        $store = new InMemoryScheduledStore();
        $d = $this->delivery(new FakeTransport(), $store);
        self::assertSame(2, $d->recordDelayed(['product-a'], 1360));
        self::assertSame(0, $d->recordDelayed(['product-a'], 1360), 'the same set again: nothing new');
        self::assertSame(2, $d->recordDelayed(['product-b'], 1360));
        self::assertCount(4, $store->rows);
        self::assertSame(2, $d->record(['product-a']), 'an owed purge is never deduplicated against a backstop');
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

abstract class InMemoryStoreBase implements OutboxStore
{
    /** @var array<int, array{instance: string, tags: list<string>, attempts: int, due: int, created: int, error: ?string, backstop: bool}> */
    public array $rows = [];
    /** The n-th write throws, as a database would (0: never). */
    public int $failOnWrite = 0;
    private int $id = 0;
    private int $writes = 0;

    public function record(string $instance, array $tags, int $now, int $dueAt): int
    {
        if (++$this->writes === $this->failOnWrite) {
            throw new \RuntimeException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
        }
        $this->rows[++$this->id] = ['instance' => $instance, 'tags' => array_values($tags), 'attempts' => 0, 'due' => $dueAt, 'created' => $now, 'error' => null, 'backstop' => false];

        return $this->id;
    }

    public function byIds(array $ids): array
    {
        return $this->entries(array_intersect_key($this->rows, array_flip($ids)));
    }

    public function due(int $limit, int $now, bool $ignoreBackoff, array $instances): array
    {
        return array_slice($this->entries(array_filter($this->rows, static fn (array $r): bool => in_array($r['instance'], $instances, true) && ($r['due'] <= $now || ($ignoreBackoff && $r['attempts'] > 0)))), 0, $limit);
    }

    public function remove(array $ids): void
    {
        foreach ($ids as $id) {
            unset($this->rows[$id]);
        }
    }

    public function fail(array $entries, string $reason, int $now): void
    {
        foreach ($entries as $e) {
            $this->rows[$e->id]['attempts'] = $e->attempts + 1;
            $this->rows[$e->id]['due'] = Backoff::nextAttemptAt($e->attempts + 1, $now);
            $this->rows[$e->id]['error'] = $reason;
        }
    }

    public function forget(string $instance): int
    {
        $before = count($this->rows);
        $this->rows = array_filter($this->rows, static fn (array $r): bool => $r['instance'] !== $instance);

        return $before - count($this->rows);
    }

    public function stats(int $now): array
    {
        $by = [];
        foreach ($this->rows as $r) {
            $by[$r['instance']] = ($by[$r['instance']] ?? 0) + 1;
        }

        return ['pending' => count($this->rows), 'oldest_age' => null, 'last_error' => null, 'last_error_at' => null, 'by_instance' => $by];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return list<OutboxEntry>
     */
    private function entries(array $rows): array
    {
        $out = [];
        foreach ($rows as $id => $r) {
            $out[] = new OutboxEntry($id, $r['instance'], $r['tags'], $r['attempts']);
        }

        return $out;
    }
}

final class InMemoryScheduledStore extends InMemoryStoreBase implements ScheduledRows, \Qoliber\Trident\Delivery\ScheduledStore
{
    public function recordScheduled(string $instance, array $tags, int $now, int $dueAt): int
    {
        foreach ($this->rows as $r) {
            if ($r['backstop'] && $r['instance'] === $instance && $r['attempts'] === 0 && $r['due'] > $now && $r['tags'] === array_values($tags)) {
                return 0;
            }
        }
        $this->record($instance, $tags, $now, $dueAt);
        $this->rows[array_key_last($this->rows)]['backstop'] = true;

        return 1;
    }
}

final class InMemoryStore extends InMemoryStoreBase
{
}
