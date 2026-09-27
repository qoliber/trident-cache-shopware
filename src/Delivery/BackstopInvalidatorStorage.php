<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Delivery;

use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Adapter\Cache\InvalidatorStorage\AbstractInvalidatorStorage;

/**
 * Decorates Shopware's delayed-invalidation storage so a stored invalidation
 * is ALSO recorded in the purge outbox, due after Shopware's own task would
 * have delivered it.
 *
 * `CacheInvalidator::invalidateExpired()` deletes the stored tags
 * (`loadAndDelete()`) before it purges; a crash or an error in between loses
 * them. The backstop row survives that — contract item 2, durable delivery.
 */
class BackstopInvalidatorStorage extends AbstractInvalidatorStorage
{
    /** Shopware's shopware.invalidate_cache task runs every 300 s. */
    public const DEFAULT_DELAY = 360;

    public function __construct(
        private readonly AbstractInvalidatorStorage $inner,
        private readonly DeliveryFactory $deliveries,
        private readonly LoggerInterface $logger,
        private readonly int $delay = self::DEFAULT_DELAY,
    ) {
    }

    public function store(array $tags): void
    {
        $this->inner->store($tags);
        try {
            $this->deliveries->delivery()->recordDelayed($tags, time() + $this->delay);
        } catch (\Throwable $e) {
            // Shopware's own path still has the tags; only the backstop is missing.
            $this->logger->error('Trident: could not record the backstop purge', ['error' => $e->getMessage()]);
        }
    }

    public function loadAndDelete(): array
    {
        return $this->inner->loadAndDelete();
    }
}
