<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentShopware\Config\SettingsResolver;
use Qoliber\Trident\Security\TokenVault;

final class TokenSecurityTest extends TestCase
{
    public function testASealedTokenOpensOnlyForItsUrl(): void
    {
        $v = new TokenVault('app-secret');
        $sealed = $v->seal('s3cret', 'http://Trident:9301/');
        self::assertStringStartsWith('tc1:', $sealed);
        self::assertStringNotContainsString('s3cret', $sealed);
        self::assertSame('s3cret', $v->open($sealed, 'http://trident:9301'), 'normalised: case and trailing slash');
        self::assertNull($v->open($sealed, 'http://attacker.example:9301'), 'another URL: the token is not released');
        self::assertNull((new TokenVault('rotated'))->open($sealed, 'http://trident:9301'), 'another key');
        self::assertNull($v->open('s3cret', 'http://trident:9301'), 'plain text is not a sealed token');
    }

    public function testTheEnvironmentTokenNeverGoesToTheAdministrationsUrl(): void
    {
        $s = SettingsResolver::resolve(['TRIDENT_API_TOKEN' => 'deploy-token'], ['apiUrl' => 'http://attacker.example:9301']);
        self::assertSame('', $s->instances[0]->apiToken);
        $s = SettingsResolver::resolve(['TRIDENT_API_TOKEN' => 'deploy-token', 'TRIDENT_API_URL' => 'http://trident:9301'], ['apiUrl' => 'http://attacker.example:9301', 'apiToken' => 'admin']);
        self::assertSame('http://trident:9301', $s->instances[0]->apiUrl);
        self::assertSame('deploy-token', $s->instances[0]->apiToken);
    }

    public function testAnUnusableStoredTokenIsReportedAndNotSent(): void
    {
        $s = SettingsResolver::resolve([], ['apiUrl' => 'http://attacker.example:9301', 'tokenError' => 'the stored Trident API token cannot be used with this API URL: re-enter the token']);
        self::assertSame('', $s->instances[0]->apiToken);
        self::assertStringContainsString('re-enter the token', $s->errors[0]);
    }

    public function testTheHostAllowlistRefusesOtherHosts(): void
    {
        $s = SettingsResolver::resolve(['TRIDENT_ALLOWED_API_HOSTS' => 'trident, 10.0.0.12:9301', 'TRIDENT_INSTANCES' => '{"a":{"api_url":"http://trident:9301"},"b":{"api_url":"http://10.0.0.12:9301"},"c":{"api_url":"http://evil.example:9301"}}'], []);
        self::assertSame(['a', 'b'], $s->instanceNames());
        self::assertStringContainsString('not in TRIDENT_ALLOWED_API_HOSTS', implode(' ', $s->errors));
    }
}
