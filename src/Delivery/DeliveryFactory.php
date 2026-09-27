<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Delivery;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\TridentShopware\Tags\TagPolicy;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The {@see Delivery} of this request (or of this message in a worker):
 * recording and flushing must see the same rows. Reset between requests and
 * messages (kernel.reset) and when the settings change.
 */
class DeliveryFactory implements ResetInterface
{
    private ?Delivery $delivery = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly SettingsProvider $settings,
        private readonly TransportFactory $transports,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function delivery(): Delivery
    {
        if ($this->delivery !== null) {
            return $this->delivery;
        }
        $settings = $this->settings->get();
        $connection = $this->connection;

        return $this->delivery = new Delivery(
            new DbalOutboxStore($connection),
            $settings,
            new TagPolicy($settings->tagPrefix),
            $this->transports->transport(),
            null,
            static fn (callable $work): mixed => $connection->transactional(static fn (): mixed => $work()),
            $this->logger,
        );
    }

    public function reset(): void
    {
        $this->delivery = null;
    }

    public function flushAndReset(): void
    {
        try {
            $this->delivery?->flush();
        } catch (\Throwable) {
            // Rows that were not acknowledged stay in the outbox.
        }
        $this->delivery = null;
    }
}
