<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Subscriber;

use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Esi\EsiCapability;
use Qoliber\TridentShopware\Esi\PeerMatcher;
use Shopware\Core\Framework\Adapter\Cache\Http\HttpCacheKeyGenerator;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Announces Trident's ESI capability to Shopware for requests Trident sent
 * (see {@see EsiCapability} for when, and why only then).
 */
class EsiCapabilitySubscriber implements EventSubscriberInterface, ResetInterface
{
    public const ATTRIBUTE = 'trident_esi';

    private ?EsiCapability $capability = null;

    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 2048]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        // A Surrogate-Capability from the client is never trusted: a visitor
        // with their own context (another currency, a cart rule) sending it
        // would get default-context fragments cached under their variant.
        // Only this plugin announces it — for Trident, default context only.
        $request->headers->remove(EsiCapability::HEADER);
        $settings = $this->settings->get();
        if (!$settings->enabled()) {
            return;
        }
        $this->capability ??= new EsiCapability($settings->esiEnabled, new PeerMatcher($settings->peers));
        $announce = $this->capability->announce(
            $request->getMethod(),
            (string) $request->server->get('REMOTE_ADDR', ''),
            $request->cookies->has(HttpCacheKeyGenerator::CONTEXT_CACHE_COOKIE),
            false,
        );
        if ($announce) {
            $request->headers->set(EsiCapability::HEADER, EsiCapability::VALUE);
            $request->attributes->set(self::ATTRIBUTE, true);
        }
    }

    public function reset(): void
    {
        $this->capability = null;
    }
}
