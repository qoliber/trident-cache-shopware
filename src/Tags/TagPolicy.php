<?php

declare(strict_types=1);

namespace Qoliber\TridentShopware\Tags;

use Qoliber\Trident\Tags\TagSet;

/**
 * How Shopware's cache tags become Trident tags — on the response, and in a
 * purge — so the two always agree.
 *
 * Shopware tags a product page with a few dozen tags (`product-<id>`,
 * `category-route-<id>`, `cms-page-<id>`, `system.config-…`), a listing with
 * hundreds. Trident keeps at most {@see TagSet::DEFAULT_MAX_TAGS} per entry,
 * so the set is bounded by the library: what does not fit is replaced by an
 * overflow tag — {@see OVERFLOW_PRODUCT} for dropped product-<id> tags, the
 * general {@see OVERFLOW} for anything else — and a purge carries the
 * overflow tag of each kind it contains. A page whose tag was dropped is still
 * invalidated, by the purges of that kind only.
 *
 * Every response also carries {@see self::ALL}: "clear the whole shop" is a
 * purge of that one tag, so it travels the same durable path as any other
 * purge and, with a tag prefix, clears only this shop on a shared Trident.
 */
final class TagPolicy
{
    public const ALL = 'all';
    public const OVERFLOW = 'sw_tag_overflow';
    public const OVERFLOW_PRODUCT = 'sw_overflow_product';

    /**
     * A listing drops product-<id> tags first: those get their own overflow
     * tag, so a category or CMS save does not refresh every big listing, and
     * a product save refreshes only listings that dropped product tags.
     */
    public const FAMILIES = ['/^product-[0-9a-f]{32}$/' => self::OVERFLOW_PRODUCT];

    public function __construct(private readonly string $prefix = '')
    {
    }

    /**
     * The `X-Cache-Tags` value for a response tagged with $tags.
     *
     * @param iterable<string> $tags
     */
    public function headerValue(iterable $tags): string
    {
        $set = new TagSet($this->prefix, self::OVERFLOW, TagSet::DEFAULT_MAX_TAGS, TagSet::DEFAULT_MAX_BYTES, self::FAMILIES);
        $set->add(self::ALL, TagSet::IDENTITY);
        foreach ($tags as $tag) {
            $tag = (string) $tag;
            $set->add($tag, self::isIdentity($tag) ? TagSet::IDENTITY : TagSet::REFERENCE);
        }

        return $set->headerValue(',');
    }

    /**
     * The Trident tags a Shopware invalidation of $tags must purge.
     *
     * @param iterable<string> $tags
     *
     * @return list<string>
     */
    public function purgeTags(iterable $tags): array
    {
        $out = [];
        $raw = [];
        foreach ($tags as $tag) {
            $normalised = TagSet::normalise($this->prefix, (string) $tag);
            if ($normalised !== '') {
                $out[$normalised] = true;
                $raw[] = (string) $tag;
            }
        }
        if ($out === []) {
            return [];
        }
        foreach (TagSet::overflowTagsFor($raw, $this->prefix, self::OVERFLOW, self::FAMILIES) as $overflow) {
            $out[$overflow] = true;
        }

        return array_map('strval', array_keys($out));
    }

    public function allTag(): string
    {
        return TagSet::normalise($this->prefix, self::ALL);
    }

    /**
     * Tags naming what the page IS (kept first when the set must be cut),
     * as opposed to shared references every page carries.
     */
    private static function isIdentity(string $tag): bool
    {
        return str_starts_with($tag, 'product-')
            || str_starts_with($tag, 'category-')
            || str_starts_with($tag, 'cms-page-')
            || str_starts_with($tag, 'landing-page-')
            || str_contains($tag, '-route-');
    }
}
