<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Trident Cache for Shopware 6.
 *
 * Activating the plugin switches Shopware into reverse-proxy mode
 * (Resources/config/packages/trident_cache.yaml) with Trident as the proxy.
 */
class TridentCache extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // Resources/config/packages/*.yaml — reverse-proxy mode on. Bundles
        // are built before the project's config/packages, so a project that
        // sets shopware.http_cache.reverse_proxy itself still has the last word.
        $this->buildDefaultConfig($container);
    }

    public function executeComposerCommands(): bool
    {
        // Installed from a zip, the plugin's own dependency (qoliber/trident-php)
        // is resolved by Composer, as for any plugin that ships a composer.json.
        return true;
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
        $this->sealStoredToken();
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
        $this->sealStoredToken();
    }

    /**
     * A plaintext token (stored before sealing, or through the DAL) is sealed
     * for the stored URL.
     */
    private function sealStoredToken(): void
    {
        $config = $this->container?->get(SystemConfigService::class);
        $connection = $this->container?->get(Connection::class);
        if (!$config instanceof SystemConfigService || !$connection instanceof Connection) {
            return;
        }
        $key = 'TridentCache.config.apiToken';
        foreach ($connection->fetchAllAssociative('SELECT LOWER(HEX(sales_channel_id)) AS sc, configuration_value FROM system_config WHERE configuration_key = :key', ['key' => $key]) as $row) {
            $value = json_decode((string) $row['configuration_value'], true)['_value'] ?? null;
            if (is_string($value) && $value !== '' && !str_starts_with($value, 'tc1:')) {
                $config->set($key, $value, $row['sc'] ?: null);
            }
        }
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);
        if ($uninstallContext->keepUserData()) {
            return;
        }
        $connection = $this->container?->get(Connection::class);
        if ($connection instanceof Connection) {
            $connection->executeStatement('DROP TABLE IF EXISTS `trident_purge_outbox`');
        }
    }
}
