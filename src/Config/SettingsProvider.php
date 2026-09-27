<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Config;

use Qoliber\Trident\Security\TokenVault;
use Shopware\Core\DevOps\Environment\EnvironmentHelper;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the environment and the plugin's system config into {@see Settings}.
 * The stored token is opened here, for the stored URL only (TokenVault).
 * Reset between requests/messages (kernel.reset) and when the config changes.
 */
class SettingsProvider implements ResetInterface
{
    /** The HKDF label tokens were sealed with since this plugin shipped (kept: re-labelling would lock existing tokens out). */
    public const TOKEN_INFO = 'qoliber/trident-cache:api-token:v1';

    public const CONFIG_DOMAIN = 'TridentCache.config.';

    private const ENV = [
        'TRIDENT_INSTANCES', 'TRIDENT_API_URL', 'TRIDENT_API_TOKEN', 'TRIDENT_PURGE_MODE',
        'TRIDENT_TAG_PREFIX', 'TRIDENT_ESI', 'TRIDENT_PEERS', 'TRIDENT_DEBUG_HEADERS', 'TRIDENT_ALLOWED_API_HOSTS',
    ];

    private ?Settings $settings = null;
    private ?TokenVault $vault = null;

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function get(): Settings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $env = [];
        foreach (self::ENV as $key) {
            $value = EnvironmentHelper::getVariable($key);
            $env[$key] = is_scalar($value) ? (string) $value : null;
        }
        $config = [];
        foreach (['apiUrl', 'purgeMode', 'tagPrefix', 'esiEnabled', 'tridentPeers', 'debugHeaders'] as $key) {
            $config[$key] = $this->systemConfig->get(self::CONFIG_DOMAIN . $key);
        }
        $stored = $this->systemConfig->get(self::CONFIG_DOMAIN . 'apiToken');
        if (is_string($stored) && $stored !== '') {
            $token = TokenVault::isSealed($stored) ? $this->vault()->open($stored, (string) $config['apiUrl']) : null;
            if ($token === null) {
                $config['tokenError'] = 'the stored Trident API token cannot be used with this API URL (the URL changed, APP_SECRET/TRIDENT_TOKEN_KEY rotated, or it was stored outside the administration): re-enter the token';
            } else {
                $config['apiToken'] = $token;
            }
        }

        return $this->settings = SettingsResolver::resolve($env, $config);
    }

    public function vault(): TokenVault
    {
        if ($this->vault === null) {
            $key = EnvironmentHelper::getVariable('TRIDENT_TOKEN_KEY') ?: EnvironmentHelper::getVariable('APP_SECRET');
            $this->vault = new TokenVault(is_scalar($key) ? (string) $key : '', self::TOKEN_INFO);
        }

        return $this->vault;
    }

    public function reset(): void
    {
        $this->settings = null;
    }
}
