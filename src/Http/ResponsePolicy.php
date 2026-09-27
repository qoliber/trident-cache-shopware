<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Http;

/**
 * The last word on what a storefront response may do in a shared cache.
 *
 * Shopware 6.7 decides cacheability (`public, s-maxage=…` for cacheable
 * routes) and keys shared variants by the `sw-cache-hash` cookie. Two things
 * it leaves to the proxy's VCL, which Trident has no VCL for:
 *
 *  - A page rendered for a LOGGED-IN customer may name them (the account
 *    menu). The hash tells "logged in" from "guest" but not WHICH customer, so
 *    such a render must never be stored: it goes out `private, no-store`.
 *  - Every storefront response starts a session and sends its cookie. A
 *    response with `Set-Cookie` is never stored, so the session cookie is
 *    dropped from responses that are going to be shared — the visitor gets it
 *    from the first uncached request (the cart widget, any POST). Other
 *    cookies (`sw-cache-hash`, `sw-states`, `sw-currency`) change the
 *    visitor's context and stay: that rare response is simply not stored.
 */
final class ResponsePolicy
{
    public const PRIVATE_NO_STORE = 'private, no-store';

    /**
     * @param list<string> $setCookieNames names of the cookies the response sets
     *
     * @return array{private: bool, strip: list<string>}
     */
    public static function decide(string $method, string $cacheControl, bool $customerLoggedIn, array $setCookieNames, string $sessionCookiePrefix = 'session-'): array
    {
        $shared = self::isShared($method, $cacheControl);
        if (!$shared) {
            return ['private' => false, 'strip' => []];
        }
        if ($customerLoggedIn) {
            return ['private' => true, 'strip' => []];
        }
        $strip = [];
        foreach ($setCookieNames as $name) {
            if ($sessionCookiePrefix !== '' && str_starts_with($name, $sessionCookiePrefix)) {
                $strip[] = $name;
            }
        }

        return ['private' => false, 'strip' => $strip];
    }

    public static function isShared(string $method, string $cacheControl): bool
    {
        if (!in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            return false;
        }
        $cc = strtolower($cacheControl);
        if (str_contains($cc, 'private') || str_contains($cc, 'no-store')) {
            return false;
        }

        return str_contains($cc, 'public') && preg_match('/s-maxage=([1-9]\d*)/', $cc) === 1;
    }
}
