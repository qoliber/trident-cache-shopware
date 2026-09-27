<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Delivery;

use Psr\Log\LoggerInterface;
use Qoliber\Trident\Admin\PurgeOutbox;
use Qoliber\Trident\Delivery\DrainReport;
use Qoliber\Trident\Delivery\Drainer;
use Qoliber\Trident\Delivery\Instance;
use Qoliber\Trident\Delivery\OutboxStore;
use Qoliber\Trident\Delivery\Packer;
use Qoliber\Trident\Delivery\PurgeClient;
use Qoliber\Trident\Delivery\Purger;
use Qoliber\Trident\Delivery\Transport;
use Qoliber\TridentShopware\Config\Settings;
use Qoliber\TridentShopware\Tags\TagPolicy;

/**
 * Durable purge delivery (the X02 contract), over the shared library.
 *
 * A purge is RECORDED — rows per Trident instance, chunked to at most
 * {@see Packer::MAX_TAGS_PER_REQUEST} tags, all in ONE transaction — through
 * the library's {@see Purger}, and a row is removed only when that instance
 * acknowledged it (HTTP 200 with the purge schema, or 202 recorded). The rows
 * this request recorded are delivered by {@see flush()}; anything refused or
 * unreachable is retried with backoff by the scheduled task and
 * `trident:purge:drain`.
 *
 * Shopware's own delayed-invalidation queue does not give this:
 * `CacheInvalidator::invalidateExpired()` deletes the tags before it purges,
 * and the task handler swallows the failure. {@see recordDelayed()} closes that
 * window: the moment Shopware stores tags for later, a backstop row is
 * recorded here too, due after Shopware's task would have run.
 *
 * Shopware-free: the store, the transport, the clock and the transaction
 * wrapper are passed in. One instance per request (DeliveryFactory), so the
 * rows recorded and the rows flushed are the same.
 */
final class Delivery implements PurgeOutbox
{
    public const FLUSH_LIMIT = 200;
    /** Seconds after which a delivered purge is delivered once more (the editor race). */
    public const REDELIVER_AFTER = 10;

    private readonly \Closure $clock;
    private readonly \Closure $transaction;
    private ?Purger $purger = null;

    public function __construct(
        private readonly OutboxStore $store,
        private readonly Settings $settings,
        private readonly TagPolicy $tags,
        private readonly Transport $transport,
        ?\Closure $clock = null,
        ?\Closure $transaction = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->transaction = $transaction ?? static fn (callable $work): mixed => $work();
    }

    /**
     * Record a Shopware invalidation for every instance.
     *
     * @param iterable<string> $shopwareTags
     *
     * @return int rows recorded
     */
    public function record(iterable $shopwareTags): int
    {
        return $this->recordTridentTags($this->tags->purgeTags($shopwareTags));
    }

    /**
     * Record "clear this shop": the one tag every response carries.
     */
    public function purgeTags(iterable $tags): array
    {
        return $this->tags->purgeTags($tags);
    }

    public function allTag(): string
    {
        return $this->tags->allTag();
    }

    public function recordAll(): int
    {
        return $this->recordTridentTags([$this->tags->allTag()]);
    }

    /**
     * @param list<string> $tags already Trident tags
     */
    public function recordTridentTags(array $tags): int
    {
        if ($tags === [] || !$this->settings->enabled()) {
            return 0;
        }
        // All or nothing: every chunk for every instance in ONE transaction.
        // A failed chunk rolls the whole record back, the tags are sent
        // directly at once, and the loss of durability is logged as critical.
        try {
            return (int) ($this->transaction)(function () use ($tags): int {
                $purger = $this->purger();
                $written = $purger->purgeTags($tags);
                if ($purger->recordError() !== null) {
                    throw new \RuntimeException('outbox write failed: ' . $purger->recordError());
                }

                return $written;
            });
        } catch (\Throwable $e) {
            $this->purger = null; // its memory holds rows that were rolled back
            $sent = $this->sendDirectly($tags);
            $this->logger?->critical('Trident: could not record a purge in the outbox; sent directly, without retry', [
                'error' => $e->getMessage(),
                'sent_directly' => $sent,
                'tags' => \array_slice($tags, 0, 50),
            ]);

            return 0;
        }
    }

    /**
     * @param list<string> $tags
     *
     * @return array<string, bool> instance => acknowledged
     */
    private function sendDirectly(array $tags): array
    {
        $result = [];
        foreach ($this->settings->instances as $instance) {
            $ok = true;
            foreach (Packer::chunk($tags) as $chunk) {
                try {
                    $ok = (new PurgeClient($instance, $this->transport))->purgeTags($chunk, $this->settings->mode)->acknowledged() && $ok;
                } catch (\Throwable) {
                    $ok = false;
                }
            }
            $result[$instance->name] = $ok;
        }

        return $result;
    }

    /**
     * Backstop rows for tags Shopware stored for delayed invalidation: due at
     * $dueAt, after Shopware's own task would have purged them — so a soft
     * refresh never re-caches data older than the save. A duplicate purge is
     * harmless; a lost one is not.
     *
     * @param iterable<string> $shopwareTags
     */
    public function recordDelayed(iterable $shopwareTags, int $dueAt): int
    {
        $tags = $this->tags->purgeTags($shopwareTags);
        if ($tags === [] || !$this->settings->enabled()) {
            return 0;
        }
        $now = ($this->clock)();

        return (int) ($this->transaction)(function () use ($tags, $now, $dueAt): int {
            $rows = 0;
            foreach ($this->settings->instances as $instance) {
                foreach (Packer::chunk($tags) as $chunk) {
                    // Deduplicated: an identical scheduled set is recorded once.
                    $rows += $this->store instanceof ScheduledRows
                        ? $this->store->recordScheduled($instance->name, $chunk, $now, $dueAt)
                        : (int) (bool) $this->store->record($instance->name, $chunk, $now, $dueAt);
                }
            }

            return $rows;
        });
    }

    /**
     * This request's own rows, then a few due ones (a failed purge, a second
     * delivery whose time has come) — as the library's Purger::deliverPending()
     * does, so they do not wait for the scheduled task.
     */
    public function flush(): DrainReport
    {
        $own = $this->purger?->deliverOwn() ?? new DrainReport();
        try {
            $due = $this->drain(Purger::REQUEST_DRAIN_LIMIT);
        } catch (\Throwable $e) {
            $this->logger?->warning('Trident: draining due purges after this request failed; the scheduled task retries them', ['error' => $e->getMessage()]);

            return $own;
        }

        return new DrainReport($own->delivered + $due->delivered, $own->failed + $due->failed, $own->instances + $due->instances, $own->purged + $due->purged);
    }

    public function recordError(): ?string
    {
        return $this->purger?->recordError();
    }

    public function drain(int $limit = self::FLUSH_LIMIT, bool $ignoreBackoff = false): DrainReport
    {
        return (new Drainer($this->store, $this->settings->instances, $this->clientFactory(), $this->settings->mode))
            ->drain($limit, ($this->clock)(), $ignoreBackoff);
    }

    /**
     * Every row now, whatever its due time (`trident:purge:drain --now`).
     */
    public function drainAll(int $limit = self::FLUSH_LIMIT): DrainReport
    {
        return (new Drainer($this->store, $this->settings->instances, $this->clientFactory(), $this->settings->mode))
            ->drainAll($limit, ($this->clock)());
    }

    /**
     * @return array{pending: int, oldest_age: ?int, last_error: ?string, last_error_at: ?int, by_instance: array<string, int>, orphaned: array<string, int>}
     */
    public function status(): array
    {
        $stats = $this->store->stats(($this->clock)());
        $configured = array_flip($this->settings->instanceNames());
        $orphaned = [];
        foreach ($stats['by_instance'] ?? [] as $name => $count) {
            if (!isset($configured[$name])) {
                $orphaned[(string) $name] = (int) $count;
            }
        }
        $stats['orphaned'] = $orphaned;

        return $stats;
    }

    public function forget(string $instance): int
    {
        return $this->store->forget($instance);
    }

    private function purger(): Purger
    {
        // The library's record path: chunking, per-request dedupe, a grace
        // period before the scheduled drain may take a row this request is
        // about to deliver itself. `defer` is a no-op: flush() delivers.
        return $this->purger ??= new Purger(
            $this->store,
            $this->settings->instances,
            $this->clientFactory(),
            $this->settings->mode,
            static function (): void {
            },
            $this->clock,
            Purger::DEFAULT_GRACE,
            // The editor race: a render that read the old data before the save
            // committed and finished after the purge re-stores the stale page.
            self::REDELIVER_AFTER,
        );
    }

    private function clientFactory(): \Closure
    {
        return fn (Instance $instance): PurgeClient => new PurgeClient($instance, $this->transport);
    }
}
