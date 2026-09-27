<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Gateway;

use Psr\Log\LoggerInterface;
use Qoliber\Trident\Admin\Fleet;
use Qoliber\Trident\Client\TridentClient;
use Qoliber\TridentShopware\Config\SettingsProvider;
use Qoliber\TridentShopware\Delivery\DeliveryFactory;
use Qoliber\Trident\Http\TransportFactory;
use Qoliber\TridentShopware\Subscriber\FragmentTagRecorder;
use Qoliber\TridentShopware\Tags\TagPolicy;
use Shopware\Core\Framework\Adapter\Cache\ReverseProxy\AbstractReverseProxyGateway;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shopware 6.7's reverse-proxy gateway for Trident.
 *
 * With `shopware.http_cache.reverse_proxy.enabled` (the plugin turns it on),
 * Shopware stops caching pages itself and talks to the proxy in front through
 * this gateway — in place of its Varnish (`xkey` + BAN) or Fastly gateways:
 *
 *  - {@see tag()}: the page's tags go out in `X-Cache-Tags`, bounded and
 *    prefixed by {@see TagPolicy}, including the tags of blocks Shopware
 *    rendered inline ({@see FragmentTagRecorder}).
 *  - {@see invalidate()} / {@see banAll()}: RECORDED in the durable outbox
 *    for every instance; {@see flush()} (end of request, after the delayed
 *    invalidation task) delivers them.
 *  - {@see ban()}: URL purges, used by Shopware only for media files whose
 *    URL changed — best effort, like the built-in gateways.
 */
class TridentReverseProxyGateway extends AbstractReverseProxyGateway
{
    public const TAG_HEADER = 'X-Cache-Tags';

    private bool $pending = false;

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly DeliveryFactory $deliveries,
        private readonly FragmentTagRecorder $fragments,
        private readonly TransportFactory $transports,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDecorated(): AbstractReverseProxyGateway
    {
        throw new \LogicException(self::class . ' is not a decorator');
    }

    /**
     * @param list<string> $tags
     */
    public function tag(array $tags, string $url, Response $response): void
    {
        $policy = new TagPolicy($this->settings->get()->tagPrefix);
        $response->headers->set(self::TAG_HEADER, $policy->headerValue(array_merge($tags, $this->fragments->all())));
        // Varnish's tag header is ours now; Shopware's VCL states mean nothing to Trident.
        $response->headers->remove('xkey');
    }

    /**
     * @param list<string> $tags
     */
    public function invalidate(array $tags): void
    {
        $delivery = $this->deliveries->delivery();
        try {
            if ($delivery->record($tags) > 0) {
                $this->pending = true;
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Trident: could not record a purge in the outbox; it is lost', [
                'error' => $e->getMessage(),
                'tags' => \array_slice($tags, 0, 50),
            ]);

            return;
        }

    }

    /**
     * @param list<string> $urls
     */
    public function ban(array $urls): void
    {
        if ($urls === []) {
            return;
        }
        $settings = $this->settings->get();
        $soft = $settings->mode === 'soft';
        $fleet = new Fleet($settings->instances, $this->transports->transport(), $this->logger);
        foreach ($fleet->each(static fn (TridentClient $client) => $client->purgeUrls(array_values($urls), $soft)) as $result) {
            if (!$result->isOk()) {
                $this->logger->error('Trident: URL purge failed', ['instance' => $result->name(), 'reason' => $result->reason(), 'urls' => \array_slice($urls, 0, 20)]);
            } elseif (!$result->value->isAcknowledged()) {
                // Some hosts purged, some not (or no acknowledgement): the URLs
                // that failed may still be served stale.
                $this->logger->error('Trident: URL purge not acknowledged', ['instance' => $result->name(), 'failure' => $result->value->failure, 'urls' => \array_slice($urls, 0, 20)]);
            }
        }
    }

    public function banAll(): void
    {
        try {
            if ($this->deliveries->delivery()->recordAll() > 0) {
                $this->pending = true;
            }
        } catch (\Throwable $e) {
            $this->logger->critical('Trident: could not record a full purge in the outbox; it is lost', ['error' => $e->getMessage()]);
        }
        $this->flush();
    }

    public function flush(): void
    {
        if (!$this->pending) {
            return;
        }
        $this->pending = false;
        try {
            $report = $this->deliveries->delivery()->flush();
            if ($report->failed > 0) {
                $this->logger->warning('Trident: purges kept for retry', ['failed' => $report->failed, 'instances' => $report->instances]);
            }
        } catch (\Throwable $e) {
            // The rows stay in the outbox; the scheduled task retries them.
            $this->logger->error('Trident: purge delivery failed; kept for retry', ['error' => $e->getMessage()]);
        }
    }
}
