<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentShopware\Config\SettingsResolver;

final class SettingsResolverTest extends TestCase
{
    public function testTheEnvironmentTakesPrecedenceOverTheAdministration(): void
    {
        $s = SettingsResolver::resolve(
            ['TRIDENT_INSTANCES' => '{"edge-1":{"api_url":"http://a:9301","api_token":"t1"},"edge-2":{"api_url":"http://b:9301"}}', 'TRIDENT_API_TOKEN' => 'shared'],
            ['apiUrl' => 'http://admin:9301', 'apiToken' => 'admin-token'],
        );
        self::assertSame(['edge-1', 'edge-2'], $s->instanceNames());
        self::assertSame('t1', $s->instances[0]->apiToken);
        self::assertSame('shared', $s->instances[1]->apiToken, 'an instance without a token uses the default');
        self::assertStringStartsWith('environment', $s->source);
    }

    public function testTheAdministrationIsTheFallback(): void
    {
        $s = SettingsResolver::resolve([], ['apiUrl' => 'http://admin:9301/', 'apiToken' => 'x']);
        self::assertSame('http://admin:9301', $s->instances[0]->apiUrl);
        self::assertSame('administration', $s->source);
    }

    public function testBrokenJsonIsReportedAndFallsBack(): void
    {
        $s = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{edge-1:'], ['apiUrl' => 'http://admin:9301']);
        self::assertSame(['default'], $s->instanceNames());
        self::assertNotSame([], $s->errors);
    }

    public function testInvalidEntriesAreSkippedWithAReason(): void
    {
        $s = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{"ok":{"api_url":"http://a:1"},"bad name":{"api_url":"http://b:1"},"ftp":{"api_url":"ftp://c"}}'], []);
        self::assertSame(['ok'], $s->instanceNames());
        self::assertCount(2, $s->errors);
    }

    public function testDefaults(): void
    {
        $s = SettingsResolver::resolve([], []);
        self::assertFalse($s->enabled());
        self::assertSame('soft', $s->mode);
        self::assertTrue($s->esiEnabled);
        self::assertSame(['127.0.0.1', '::1'], $s->peers);
        $s = SettingsResolver::resolve(['TRIDENT_PURGE_MODE' => 'hard', 'TRIDENT_ESI' => '0', 'TRIDENT_PEERS' => 'trident, 10.0.0.0/8'], ['purgeMode' => 'soft', 'esiEnabled' => true]);
        self::assertSame('hard', $s->mode);
        self::assertFalse($s->esiEnabled);
        self::assertSame(['trident', '10.0.0.0/8'], $s->peers);
    }

    public function testAStoredTokenThatCannotBeOpenedIsReportedOnlyWhenTheAdministrationUrlIsUsed(): void
    {
        $broken = ['apiUrl' => 'http://admin:9301', 'tokenError' => 'the stored Trident API token cannot be used'];
        self::assertContains($broken['tokenError'], SettingsResolver::resolve([], $broken)->errors);
        $env = SettingsResolver::resolve(['TRIDENT_INSTANCES' => '{"edge-1":{"api_url":"http://a:9301"}}', 'TRIDENT_API_TOKEN' => 't'], $broken);
        self::assertSame([], $env->errors, 'the environment supplies the instances: the stored token is irrelevant');
        self::assertSame([], SettingsResolver::resolve(['TRIDENT_API_URL' => 'http://a:9301', 'TRIDENT_API_TOKEN' => 't'], $broken)->errors);
    }

    public function testAnAdministrationUrlWithQueryFragmentOrCredentialsIsRefused(): void
    {
        foreach (['http://admin:9301/?x=1', 'http://admin:9301/#f', 'http://u:p@admin:9301', 'ftp://admin', 'gopher://admin:9301'] as $url) {
            $s = SettingsResolver::resolve([], ['apiUrl' => $url, 'apiToken' => 'x']);
            self::assertSame([], $s->instances, $url);
            self::assertNotSame([], $s->errors, $url);
        }
        self::assertSame('http://admin:9301/trident', SettingsResolver::resolve([], ['apiUrl' => 'http://admin:9301/trident', 'apiToken' => 'x'])->instances[0]->apiUrl);
    }
}
