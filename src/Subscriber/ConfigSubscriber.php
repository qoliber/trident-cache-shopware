<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Subscriber;

use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\Trident\Security\TokenVault;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigMultipleChangedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigDomainLoadedEvent;
use Shopware\Core\System\SystemConfig\Event\SystemConfigMultipleChangedEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The plugin's system config, on its way in and out.
 *
 *  - In: a new API token is sealed for the API URL saved WITH it (the whole
 *    batch is visible here) or, if the URL is not being changed, the stored
 *    one. The mask placeholder and an already sealed value are left alone.
 *  - Out (the administration's config form): the token is replaced by a
 *    placeholder. The search API returns the stored value — sealed.
 *  - After a change: cached settings and deliveries are dropped, so a
 *    long-running worker uses the new values.
 */
class ConfigSubscriber implements EventSubscriberInterface
{
    public const TOKEN_KEY = SettingsProvider::CONFIG_DOMAIN . 'apiToken';
    public const URL_KEY = SettingsProvider::CONFIG_DOMAIN . 'apiUrl';
    public const MASK = '__trident_token_stored__';

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly DeliveryFactory $deliveries,
        private readonly EsiCapabilitySubscriber $esi,
        private readonly SystemConfigService $systemConfig,
        private readonly Connection $connection,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeSystemConfigMultipleChangedEvent::class => 'seal',
            SystemConfigDomainLoadedEvent::class => 'mask',
            SystemConfigMultipleChangedEvent::class => 'changed',
            'system_config.written' => 'sealEntityWrite',
        ];
    }

    public function seal(BeforeSystemConfigMultipleChangedEvent $event): void
    {
        $config = $event->getConfig();
        if (!array_key_exists(self::TOKEN_KEY, $config)) {
            return;
        }
        $token = $config[self::TOKEN_KEY];
        if ($token === self::MASK) {
            // Unchanged in the form: keep what is stored.
            $event->setValue(self::TOKEN_KEY, $this->systemConfig->get(self::TOKEN_KEY, $event->getSalesChannelId()));

            return;
        }
        if (!is_string($token) || $token === '' || TokenVault::isSealed($token)) {
            return;
        }
        $url = $config[self::URL_KEY] ?? $this->systemConfig->get(self::URL_KEY, $event->getSalesChannelId());
        $event->setValue(self::TOKEN_KEY, $this->settings->vault()->seal($token, (string) $url));
    }

    /**
     * A write through the DAL (`/api/system-config`, sync) skips
     * SystemConfigService and its events: seal whatever plaintext it stored.
     */
    public function sealEntityWrite(EntityWrittenEvent $event): void
    {
        // An update by id carries no configurationKey: look at the row itself
        // (one indexed query, only when system_config rows were written).
        if ($event->getIds() !== []) {
            $this->sealStored();
        }
    }

    /**
     * Seal a plaintext token found in the database (a DAL write, or a value
     * from before sealing existed). Also run on plugin activate/update.
     */
    public function sealStored(): void
    {
        foreach ($this->connection->fetchAllAssociative('SELECT LOWER(HEX(sales_channel_id)) AS sc, configuration_value FROM system_config WHERE configuration_key = :key', ['key' => self::TOKEN_KEY]) as $row) {
            $value = json_decode((string) $row['configuration_value'], true)['_value'] ?? null;
            if (!is_string($value) || $value === '' || $value === self::MASK || TokenVault::isSealed($value)) {
                continue;
            }
            $this->systemConfig->set(self::TOKEN_KEY, $value, $row['sc'] ?: null);
        }
    }

    public function mask(SystemConfigDomainLoadedEvent $event): void
    {
        // By KEY, whatever domain prefix was asked for (`TridentCache`,
        // `TridentCach_` …): the domain is matched with LIKE.
        $config = $event->getConfig();
        if (isset($config[self::TOKEN_KEY]) && $config[self::TOKEN_KEY] !== '' && $config[self::TOKEN_KEY] !== null) {
            $config[self::TOKEN_KEY] = self::MASK;
            $event->setConfig($config);
        }
    }

    public function changed(SystemConfigMultipleChangedEvent $event): void
    {
        foreach (array_keys($event->getConfig()) as $key) {
            if (str_starts_with((string) $key, SettingsProvider::CONFIG_DOMAIN)) {
                // Deliver what this request already recorded (with the settings
                // it was recorded under) before dropping the old delivery.
                $this->deliveries->flushAndReset();
                $this->settings->reset();
                $this->esi->reset();

                return;
            }
        }
    }
}
