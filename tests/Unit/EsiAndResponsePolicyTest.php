<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentShopware\Esi\EsiCapability;
use Qoliber\TridentShopware\Esi\PeerMatcher;
use Qoliber\TridentShopware\Http\ResponsePolicy;

final class EsiAndResponsePolicyTest extends TestCase
{
    public function testPeersMatchAddressesCidrsAndHostNames(): void
    {
        $m = new PeerMatcher(['127.0.0.1', '10.1.0.0/16', 'fd00::/8', 'trident'], static fn (string $h): array => $h === 'trident' ? ['172.22.0.4'] : []);
        self::assertTrue($m->matches('127.0.0.1'));
        self::assertTrue($m->matches('10.1.200.3'));
        self::assertFalse($m->matches('10.2.0.1'));
        self::assertTrue($m->matches('fd12::1'));
        self::assertTrue($m->matches('172.22.0.4'));
        self::assertFalse($m->matches('172.22.0.5'));
        self::assertFalse($m->matches('not-an-ip'));
        self::assertFalse($m->matches(''));
    }

    public function testEsiIsAnnouncedOnlyToTridentForTheDefaultContext(): void
    {
        $esi = new EsiCapability(true, new PeerMatcher(['10.0.0.1']));
        self::assertTrue($esi->announce('GET', '10.0.0.1', false, false));
        self::assertFalse($esi->announce('GET', '203.0.113.9', false, false), 'a visitor must never receive ESI tags');
        self::assertFalse($esi->announce('GET', '10.0.0.1', true, false), 'a visitor with their own context gets the page assembled by Shopware');
        self::assertFalse($esi->announce('POST', '10.0.0.1', false, false));
        self::assertFalse($esi->announce('GET', '10.0.0.1', false, true));
        self::assertFalse((new EsiCapability(false, new PeerMatcher(['10.0.0.1'])))->announce('GET', '10.0.0.1', false, false));
    }

    public function testALoggedInRenderIsNeverShared(): void
    {
        self::assertSame(['private' => true, 'strip' => []], ResponsePolicy::decide('GET', 'public, s-maxage=7200', true, ['session-']));
    }

    public function testTheSessionCookieIsDroppedFromSharedResponsesOnly(): void
    {
        self::assertSame(['private' => false, 'strip' => ['session-']], ResponsePolicy::decide('GET', 'public, s-maxage=7200', false, ['session-', 'sw-cache-hash']));
        self::assertSame(['private' => false, 'strip' => []], ResponsePolicy::decide('GET', 'no-cache, private', false, ['session-']));
        self::assertSame(['private' => false, 'strip' => []], ResponsePolicy::decide('POST', 'public, s-maxage=7200', false, ['session-']));
        self::assertFalse(ResponsePolicy::isShared('GET', 'public, s-maxage=0'));
    }
}
