<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Subscriber;

use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Http\ResponsePolicy;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Applies {@see ResponsePolicy} after Shopware decided the response's caching
 * (its CacheResponseSubscriber runs at -1500).
 */
class ResponsePolicySubscriber implements EventSubscriberInterface
{
    public const DEBUG_HEADER = 'X-Trident-Decision';

    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -2048]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $settings = $this->settings->get();
        if (!$settings->enabled()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $context = $request->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);
        $loggedIn = $context instanceof SalesChannelContext && $context->getCustomer() !== null;

        $names = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $names[] = $cookie->getName();
        }
        $cacheControl = (string) $response->headers->get('Cache-Control', '');
        $decision = ResponsePolicy::decide($request->getMethod(), $cacheControl, $loggedIn, $names);

        if ($decision['private']) {
            $response->headers->remove('Cache-Control');
            $response->headers->set('Cache-Control', ResponsePolicy::PRIVATE_NO_STORE);
        }
        foreach ($decision['strip'] as $name) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getName() === $name) {
                    $response->headers->removeCookie($name, $cookie->getPath(), $cookie->getDomain());
                }
            }
        }

        if ($settings->debugHeaders) {
            $response->headers->set(self::DEBUG_HEADER, match (true) {
                $decision['private'] => 'no-store; logged-in',
                ResponsePolicy::isShared($request->getMethod(), (string) $response->headers->get('Cache-Control', '')) => 'cacheable' . ($request->attributes->get(EsiCapabilitySubscriber::ATTRIBUTE) ? '; esi' : ''),
                default => 'not shared',
            });
        }
    }
}
