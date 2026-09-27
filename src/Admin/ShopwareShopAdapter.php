<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Admin;

use Doctrine\DBAL\Connection;
use Qoliber\Trident\Admin\EntityInvalidator;
use Qoliber\Trident\Admin\ShopAdapter;
use Shopware\Core\Content\Category\SalesChannel\CategoryRoute;
use Shopware\Core\Content\Product\SalesChannel\Listing\ProductListingRoute;
use Shopware\Core\Framework\Adapter\Cache\CacheInvalidator;
use Shopware\Core\Framework\DataAbstractionLayer\Cache\EntityCacheKeyGenerator;

/**
 * The shop, for the shared admin screens (qoliber/trident-php's AdminService):
 * the storefront domains of the sales channels, their canonical SEO URLs, and
 * products/categories purged through Shopware's own CacheInvalidator — exactly
 * as a save would, so Shopware's caches are cleared too and Trident's purge
 * goes through the gateway into the outbox.
 */
final class ShopwareShopAdapter implements ShopAdapter, EntityInvalidator
{
    private const STOREFRONT = '8a243080f92e4c719546314b577cf82b';

    public function __construct(private readonly Connection $connection, private readonly CacheInvalidator $invalidator)
    {
    }

    public function hosts(): array
    {
        $hosts = [];
        foreach ($this->connection->fetchFirstColumn('SELECT url FROM sales_channel_domain') as $url) {
            $parts = parse_url((string) $url);
            if (!isset($parts['host'])) {
                continue;
            }
            $hosts[strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '')] = true;
        }

        return array_map('strval', array_keys($hosts));
    }

    public function scheme(string $host): string
    {
        $url = $this->connection->fetchOne('SELECT url FROM sales_channel_domain WHERE url LIKE ? ORDER BY created_at LIMIT 1', ['%' . $host . '%']);

        return is_string($url) ? (parse_url($url, PHP_URL_SCHEME) ?: 'https') : 'https';
    }

    /**
     * The home page and every canonical SEO URL (products, categories, landing
     * pages) of every storefront domain, the first-created domain first.
     */
    public function catalogUrls(int $limit): array
    {
        $domains = $this->connection->fetchAllAssociative(
            'SELECT d.url, LOWER(HEX(d.sales_channel_id)) AS sc, LOWER(HEX(d.language_id)) AS lang FROM sales_channel_domain d
             INNER JOIN sales_channel s ON s.id = d.sales_channel_id AND s.active = 1 AND s.type_id = UNHEX(:storefront)
             ORDER BY d.created_at',
            ['storefront' => self::STOREFRONT],
        );
        $urls = [];
        foreach ($domains as $domain) {
            $base = rtrim((string) $domain['url'], '/');
            $urls[] = $base . '/';
            $paths = $this->connection->fetchFirstColumn(
                'SELECT seo_path_info FROM seo_url WHERE is_canonical = 1 AND is_deleted = 0
                 AND sales_channel_id = UNHEX(:sc) AND language_id = UNHEX(:lang) ORDER BY route_name, seo_path_info LIMIT ' . max(1, $limit),
                ['sc' => $domain['sc'], 'lang' => $domain['lang']],
            );
            foreach ($paths as $path) {
                $urls[] = $base . '/' . ltrim((string) $path, '/');
            }
        }

        return array_slice(array_values(array_unique($urls)), 0, max(1, $limit));
    }

    public function entityKinds(): array
    {
        return ['product' => 'Products (by id)', 'category' => 'Categories (by id)'];
    }

    public function entityTags(string $kind, array $ids): array
    {
        $tags = [];
        foreach ($ids as $id) {
            $id = strtolower(trim($id));
            if (preg_match('/^[0-9a-f]{32}$/', $id) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not an id', $id));
            }
            array_push($tags, ...match ($kind) {
                'product' => [EntityCacheKeyGenerator::buildProductTag($id)],
                'category' => [CategoryRoute::buildName($id), ProductListingRoute::buildName($id)],
                default => throw new \InvalidArgumentException(sprintf('Unknown kind "%s"', $kind)),
            });
        }

        return $tags;
    }

    public function invalidate(array $tags): void
    {
        $this->invalidator->invalidate($tags, true);
    }
}
