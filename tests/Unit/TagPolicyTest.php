<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\Trident\Tags\TagSet;
use Qoliber\TridentShopware\Tags\TagPolicy;

final class TagPolicyTest extends TestCase
{
    public function testEveryResponseCarriesTheShopTag(): void
    {
        self::assertSame('all,product-abc', (new TagPolicy())->headerValue(['product-abc']));
    }

    public function testThePrefixSeparatesShopsOnASharedTrident(): void
    {
        $p = new TagPolicy('shop1_');
        self::assertSame('shop1_all,shop1_product-abc', $p->headerValue(['product-abc']));
        self::assertSame(['shop1_category-route-abc', 'shop1_sw_tag_overflow'], $p->purgeTags(['category-route-abc']));
        self::assertSame('shop1_all', $p->allTag());
    }

    public function testATooLargeSetKeepsIdentityTagsAndTheOverflowTag(): void
    {
        $tags = ['product-listing-' . str_repeat('c', 32)];
        for ($i = 0; $i < 400; ++$i) {
            $tags[] = sprintf('product-%032x', $i);
        }
        $out = explode(',', (new TagPolicy())->headerValue($tags));
        self::assertLessThanOrEqual(TagSet::DEFAULT_MAX_TAGS, count($out));
        self::assertContains('product-listing-' . str_repeat('c', 32), $out);
        self::assertContains('sw_overflow_product', $out, 'dropped product tags: the product overflow tag');
        self::assertNotContains('sw_tag_overflow', $out, 'no other kind was dropped');
    }

    public function testEveryPurgeAlsoPurgesOverflowedPages(): void
    {
        $p = sprintf('product-%032x', 1);
        self::assertSame([$p, 'sw_overflow_product'], (new TagPolicy())->purgeTags([$p, $p, '']), 'a product purge refreshes pages that dropped product tags');
        self::assertSame(['category-route-x', 'sw_tag_overflow'], (new TagPolicy())->purgeTags(['category-route-x']), 'and not the product overflow');
        self::assertSame([], (new TagPolicy())->purgeTags([]));
    }
}
