<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Admin;

use Qoliber\Trident\Admin\AdminContext;
use Qoliber\Trident\Admin\PurgeOutbox;
use Qoliber\TridentShopware\Config\Settings;
use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;

/**
 * This request's configuration for the shared admin screens.
 */
final class AdminContextFactory
{
    public function __construct(private readonly SettingsProvider $settings, private readonly DeliveryFactory $deliveries)
    {
    }

    public function __invoke(): AdminContext
    {
        return new class ($this->settings->get(), $this->deliveries) implements AdminContext {
            public function __construct(private readonly Settings $s, private readonly DeliveryFactory $d)
            {
            }

            public function instances(): array
            {
                return $this->s->instances;
            }

            public function mode(): string
            {
                return $this->s->mode;
            }

            public function view(): array
            {
                return [
                    'source' => $this->s->source,
                    'instances' => array_map(static fn ($i) => ['name' => $i->name, 'api_url' => $i->apiUrl, 'has_token' => $i->apiToken !== ''], $this->s->instances),
                    'errors' => $this->s->errors,
                    'mode' => $this->s->mode,
                    'tag_prefix' => $this->s->tagPrefix,
                    'esi' => $this->s->esiEnabled,
                    'peers' => $this->s->peers,
                ];
            }

            public function outbox(): PurgeOutbox
            {
                return $this->d->delivery();
            }
        };
    }
}
