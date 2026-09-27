<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Config;

use Qoliber\Trident\Delivery\Instance;

/**
 * What the plugin runs with, resolved once per request from the deployment
 * config (environment) and the administration's plugin settings.
 */
final class Settings
{
    public const MODE_SOFT = 'soft';
    public const MODE_HARD = 'hard';

    /**
     * @param list<Instance> $instances Every Trident that must receive purges.
     * @param list<string>   $errors    Configured instances that were skipped, and why.
     * @param list<string>   $peers     Addresses (IPs, CIDRs or host names) Trident connects from.
     */
    public function __construct(
        public readonly array $instances,
        public readonly array $errors,
        public readonly string $source,
        public readonly string $mode,
        public readonly string $tagPrefix,
        public readonly bool $esiEnabled,
        public readonly array $peers,
        public readonly bool $debugHeaders,
    ) {
    }

    public function enabled(): bool
    {
        return $this->instances !== [];
    }

    /**
     * @return list<string>
     */
    public function instanceNames(): array
    {
        return array_map(static fn (Instance $i): string => $i->name, $this->instances);
    }
}
