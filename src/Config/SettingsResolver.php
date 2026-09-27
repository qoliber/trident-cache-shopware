<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Config;

use Qoliber\Trident\Delivery\ApiHostAllowlist;
use Qoliber\Trident\Delivery\Instances;

/**
 * Builds {@see Settings} from the two places an operator configures Trident.
 *
 * The DEPLOYMENT config wins, as `env.php` does for the Magento module and
 * `wp-config.php` for the WooCommerce plugin: the instance list lives in the
 * environment (`TRIDENT_INSTANCES`, a JSON object `{"name": {"api_url": …,
 * "api_token": …}}`), because it describes infrastructure — which Tridents
 * stand in front of this shop — and differs between staging and production
 * while the database is copied between them. The administration's single
 * API URL is the fallback for a one-server shop; its token (or
 * `TRIDENT_API_TOKEN`) is the default for an instance that names none.
 *
 * Pure: no Shopware, no globals — the caller passes the environment and the
 * plugin settings in.
 */
final class SettingsResolver
{
    public const DEFAULT_PEERS = ['127.0.0.1', '::1'];

    /**
     * @param array<string, string|false|null> $env    Environment variables (TRIDENT_*).
     * @param array<string, mixed>             $config The plugin's system config (`TridentCache.config.*` without the prefix).
     */
    public static function resolve(array $env, array $config): Settings
    {
        $envToken = self::str($env['TRIDENT_API_TOKEN'] ?? null) ?? '';
        $adminToken = self::str($config['apiToken'] ?? null) ?? '';
        $source = 'administration';
        $configured = null;
        $errors = [];

        $json = self::str($env['TRIDENT_INSTANCES'] ?? null);
        if ($json !== null) {
            $decoded = json_decode($json, true);
            if (is_array($decoded) && $decoded !== []) {
                $configured = $decoded;
                $source = 'environment (TRIDENT_INSTANCES)';
            } else {
                $errors[] = 'TRIDENT_INSTANCES is not a JSON object of instances; using the administration setting';
            }
        }

        // A token from the environment goes only to URLs from the environment;
        // the administration's URL gets only the administration's token (which
        // is sealed to that URL). Someone who can edit the plugin settings can
        // therefore never make the deployment's token travel to their host.
        $envUrl = self::str($env['TRIDENT_API_URL'] ?? null);
        if ($configured !== null) {
            [$instances, $parseErrors] = Instances::parse($configured, '', $envToken);
        } elseif ($envUrl !== null) {
            $source = 'environment (TRIDENT_API_URL)';
            [$instances, $parseErrors] = Instances::parse(null, $envUrl, $envToken);
        } else {
            [$instances, $parseErrors] = Instances::parse(null, self::str($config['apiUrl'] ?? null) ?? '', $adminToken);
            // Only when the administration's URL is the one used: an
            // environment-configured shop (a staging copy of the database)
            // does not care about the stored token.
            if (is_string($config['tokenError'] ?? null)) {
                $errors[] = $config['tokenError'];
            }
        }
        $errors = array_merge($errors, $parseErrors);

        // Optional allowlist of API hosts (host or host:port).
        [$instances, $allowErrors] = ApiHostAllowlist::filter($instances, self::str($env['TRIDENT_ALLOWED_API_HOSTS'] ?? null) ?? '', 'TRIDENT_ALLOWED_API_HOSTS');
        $errors = array_merge($errors, $allowErrors);

        $mode = self::str($env['TRIDENT_PURGE_MODE'] ?? null) ?? self::str($config['purgeMode'] ?? null) ?? Settings::MODE_SOFT;
        $mode = $mode === Settings::MODE_HARD ? Settings::MODE_HARD : Settings::MODE_SOFT;

        $peers = self::list(self::str($env['TRIDENT_PEERS'] ?? null) ?? self::str($config['tridentPeers'] ?? null));

        return new Settings(
            instances: $instances,
            errors: array_values($errors),
            source: $instances === [] ? 'none' : $source,
            mode: $mode,
            tagPrefix: self::str($env['TRIDENT_TAG_PREFIX'] ?? null) ?? self::str($config['tagPrefix'] ?? null) ?? '',
            esiEnabled: self::bool($env['TRIDENT_ESI'] ?? null, $config['esiEnabled'] ?? true),
            peers: $peers === [] ? self::DEFAULT_PEERS : $peers,
            debugHeaders: self::bool($env['TRIDENT_DEBUG_HEADERS'] ?? null, $config['debugHeaders'] ?? false),
        );
    }

    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function list(?string $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: [])));
    }

    private static function bool(mixed $env, mixed $config): bool
    {
        if (is_string($env) && trim($env) !== '') {
            return in_array(strtolower(trim($env)), ['1', 'true', 'on', 'yes'], true);
        }

        return filter_var($config, FILTER_VALIDATE_BOOLEAN);
    }
}
