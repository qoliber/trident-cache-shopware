<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Subscriber;

use Shopware\Core\Framework\Adapter\Cache\Event\AddCacheTagEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Every cache tag added while one page is rendered — including the blocks
 * Shopware rendered INLINE for it.
 *
 * Shopware collects tags per request URI, and in reverse-proxy mode writes
 * only the main request's set to the response. The header and footer are
 * `render_esi` blocks: when they are not handed to Trident as ESI (a visitor
 * with their own context, or ESI switched off), Shopware renders them inside
 * the same PHP request as sub-requests, their tags (`navigation`,
 * `navigation-route-…`, `currency-route`, …) stay on the sub-request's URI,
 * and a menu change would never reach the stored page. The gateway adds these
 * to the page.
 */
class FragmentTagRecorder implements EventSubscriberInterface, ResetInterface
{
    /** @var array<string, true> */
    private array $tags = [];

    public static function getSubscribedEvents(): array
    {
        return [
            AddCacheTagEvent::class => 'onTags',
            KernelEvents::REQUEST => ['onRequest', 4096],
        ];
    }

    public function onTags(AddCacheTagEvent $event): void
    {
        foreach ($event->tags as $tag) {
            $this->tags[(string) $tag] = true;
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->tags = [];
        }
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return array_map('strval', array_keys($this->tags));
    }

    public function reset(): void
    {
        $this->tags = [];
    }
}
