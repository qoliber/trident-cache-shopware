<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Esi;

/**
 * Should this request tell Shopware that the proxy in front assembles ESI?
 *
 * Shopware 6.7 renders the storefront header and footer with `render_esi`
 * (`/_esi/global/header`, `/_esi/global/footer`). With a reverse proxy in
 * front, those are only emitted as `<esi:include>` when the request carries
 * `Surrogate-Capability` — Varnish adds it in VCL; Trident does not add
 * request headers, so the plugin does, for requests that came FROM Trident.
 * Then Trident caches each block once, under its own tags, and a menu change
 * refreshes one fragment instead of every page.
 *
 * Only for the DEFAULT context: Trident fetches a fragment without the
 * visitor's cookies, so a fragment is rendered for a visitor with no
 * `sw-cache-hash`. A visitor who has one (another currency, a logged-in
 * customer, a rule-dependent cart) gets the page assembled by Shopware in the
 * same request — correct for their context — and that page carries the
 * fragments' tags itself ({@see \Qoliber\TridentShopware\Subscriber\FragmentTagRecorder}).
 */
final class EsiCapability
{
    public const HEADER = 'Surrogate-Capability';
    public const VALUE = 'trident="ESI/1.0"';

    public function __construct(private readonly bool $enabled, private readonly PeerMatcher $peers)
    {
    }

    public function announce(string $method, string $remoteAddress, bool $hasContextHash, bool $alreadyAnnounced): bool
    {
        return $this->enabled
            && !$alreadyAnnounced
            && !$hasContextHash
            && in_array(strtoupper($method), ['GET', 'HEAD'], true)
            && $this->peers->matches($remoteAddress);
    }
}
