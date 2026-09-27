<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Doctrine\DBAL\Connection;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\TridentShopware\Admin\AdminContextFactory;
use Qoliber\TridentShopware\Admin\ShopwareShopAdapter;
use Qoliber\TridentShopware\Command\CheckCommand;
use Qoliber\TridentShopware\Command\PurgeCommand;
use Qoliber\TridentShopware\Command\PurgeDrainCommand;
use Qoliber\TridentShopware\Command\PurgeForgetCommand;
use Qoliber\TridentShopware\Command\PurgeStatusCommand;
use Qoliber\TridentShopware\Command\WarmCommand;
use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Controller\AdminController;
use Qoliber\TridentShopware\Delivery\BackstopInvalidatorStorage;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Qoliber\TridentShopware\Gateway\TridentReverseProxyGateway;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\TridentShopware\ScheduledTask\DrainPurgeOutboxTask;
use Qoliber\TridentShopware\ScheduledTask\DrainPurgeOutboxTaskHandler;
use Qoliber\TridentShopware\Subscriber\ConfigSubscriber;
use Qoliber\TridentShopware\Subscriber\EsiCapabilitySubscriber;
use Qoliber\TridentShopware\Subscriber\FragmentTagRecorder;
use Qoliber\TridentShopware\Subscriber\ResponsePolicySubscriber;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\Adapter\Cache\InvalidatorStorage\AbstractInvalidatorStorage;
use Shopware\Core\Framework\Adapter\Cache\ReverseProxy\AbstractReverseProxyGateway;
use Shopware\Core\System\SystemConfig\SystemConfigService;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SettingsProvider::class)
        ->args([service(SystemConfigService::class)])
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(TransportFactory::class);

    $services->set(DeliveryFactory::class)
        ->args([service(Connection::class), service(SettingsProvider::class), service(TransportFactory::class), service('logger')])
        ->tag('kernel.reset', ['method' => 'reset']);

    // Backstop: an invalidation Shopware stores for later is also recorded in
    // the outbox, so losing Shopware's own queue cannot lose the purge.
    $services->set(BackstopInvalidatorStorage::class)
        ->decorate(AbstractInvalidatorStorage::class)
        ->args([service('.inner'), service(DeliveryFactory::class), service('logger')]);

    $services->set(FragmentTagRecorder::class)
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset']);

    // Trident replaces Shopware's Varnish gateway (same service id). Shopware
    // only builds the reverse-proxy services when reverse_proxy.enabled is on,
    // which the plugin's packages/trident_cache.yaml does.
    $services->set(AbstractReverseProxyGateway::class, TridentReverseProxyGateway::class)
        ->args([
            service(SettingsProvider::class),
            service(DeliveryFactory::class),
            service(FragmentTagRecorder::class),
            service(TransportFactory::class),
            service('logger'),
        ]);

    $services->set(EsiCapabilitySubscriber::class)
        ->args([service(SettingsProvider::class)])
        ->tag('kernel.event_subscriber')
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(ConfigSubscriber::class)
        ->args([service(SettingsProvider::class), service(DeliveryFactory::class), service(EsiCapabilitySubscriber::class), service(SystemConfigService::class), service(Connection::class)])
        ->tag('kernel.event_subscriber');

    $services->set(ResponsePolicySubscriber::class)
        ->args([service(SettingsProvider::class)])
        ->tag('kernel.event_subscriber');

    $services->set(DrainPurgeOutboxTask::class)
        ->tag('shopware.scheduled.task');

    $services->set(DrainPurgeOutboxTaskHandler::class)
        ->args([service('scheduled_task.repository'), service('logger'), service(DeliveryFactory::class)])
        ->tag('messenger.message_handler');

    foreach ([PurgeDrainCommand::class, PurgeCommand::class] as $command) {
        $services->set($command)->args([service(DeliveryFactory::class)])->tag('console.command');
    }
    foreach ([PurgeStatusCommand::class, PurgeForgetCommand::class] as $command) {
        $services->set($command)->args([service(DeliveryFactory::class), service(SettingsProvider::class)])->tag('console.command');
    }
    $services->set(CheckCommand::class)
        ->args([service(SettingsProvider::class), service(TransportFactory::class)])
        ->tag('console.command');

    $services->set(ShopwareShopAdapter::class)->args([service(Connection::class), service(CacheInvalidator::class)]);
    $services->set(AdminContextFactory::class)->args([service(SettingsProvider::class), service(DeliveryFactory::class)]);
    // The shared admin screens (qoliber/trident-php).
    $services->set(AdminService::class)
        ->args([
            service(AdminContextFactory::class),
            service(ShopwareShopAdapter::class),
            service(TransportFactory::class),
            service('logger'),
        ]);

    $services->set(WarmCommand::class)
        ->args([service(AdminService::class)])
        ->tag('console.command');

    $services->set(AdminController::class)
        ->args([service(AdminService::class)])
        ->public()
        ->call('setContainer', [service('service_container')]);
};
