<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Esi;

/**
 * Is this connection from a Trident instance?
 *
 * Peers are IP addresses, CIDR ranges (IPv4 and IPv6) or host names; a host
 * name is resolved once per process (a container network's `trident`, a
 * private DNS name). Matching is on the connecting address — never on a
 * forwarded header, which the visitor controls.
 */
final class PeerMatcher
{
    /** @var array<string, list<string>> */
    private array $resolved = [];

    /**
     * @param list<string>                         $peers
     * @param (callable(string): list<string>)|null $resolve Host name → addresses (tests pass a fake).
     */
    public function __construct(private readonly array $peers, private $resolve = null)
    {
    }

    public function matches(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($address === '' || $packed === false) {
            return false;
        }
        foreach ($this->peers as $peer) {
            foreach ($this->expand($peer) as $candidate) {
                if (self::inRange($packed, $candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string> addresses or CIDRs
     */
    private function expand(string $peer): array
    {
        $host = explode('/', $peer, 2)[0];
        if (@inet_pton($host) !== false) {
            return [$peer];
        }

        return $this->resolved[$peer] ??= $this->lookup($peer);
    }

    /**
     * @return list<string>
     */
    private function lookup(string $host): array
    {
        if ($this->resolve !== null) {
            return ($this->resolve)($host);
        }
        $v4 = gethostbynamel($host) ?: [];
        $v6 = [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $v6[] = (string) $record['ipv6'];
            }
        }

        return array_values(array_merge($v4, $v6));
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$network, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $net = @inet_pton((string) $network);
        if ($net === false || strlen($net) !== strlen($packed)) {
            return false;
        }
        $max = strlen($net) * 8;
        $bits = $bits === null ? $max : max(0, min($max, (int) $bits));
        $bytes = intdiv($bits, 8);
        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }
}
