# Trident Cache for Shopware 6

Full-page caching for Shopware **6.7** behind the [Trident](https://trident-cache.com)
HTTP cache: Shopware's cache tags on every page, purges delivered durably to
every Trident instance, the header and footer as ESI fragments, and
administration screens over Trident's admin API. Built on
[`qoliber/trident-php`](https://github.com/qoliber/trident-php).

Tested: Shopware 6.7.14.2, PHP 8.3, Trident 1.8.0 (`tests/shopware6-e2e`).

## How it plugs in

Shopware 6.7 has a reverse-proxy mode for Varnish and Fastly: Shopware stops
caching pages itself and hands tags and invalidations to a **reverse-proxy
gateway**. The plugin switches that mode on and registers a Trident gateway:

| Shopware 6.7 extension point | What the plugin does |
|---|---|
| `shopware.http_cache.reverse_proxy.enabled` | turned on by the plugin's `Resources/config/packages/trident_cache.yaml` (a project's own `config/packages` still wins) |
| `AbstractReverseProxyGateway::tag()` | writes the page's tags to `X-Cache-Tags`: bounded to Trident's 200, with an overflow tag, prefixed if you share a Trident between shops, plus the tags of blocks rendered inline |
| `::invalidate()` / `::banAll()` | **records** the purge in the `trident_purge_outbox` table, one row per instance |
| `::flush()` (end of request, after the delayed-invalidation task) | delivers the rows; each is removed only when that instance acknowledged it. It then delivers up to 50 due rows of earlier requests, and schedules each delivered purge **once more 10 s later** (a page rendered from data the purge's transaction had not yet made visible) |
| `::ban()` (media URLs) | URL purge on every instance |
| scheduled task `trident.purge_outbox_drain` (60 s) | retries unacknowledged rows with backoff |
| `kernel.request` | announces ESI (`Surrogate-Capability`) for requests **from Trident** without a `sw-cache-hash` |
| `kernel.response` (after Shopware's cache subscriber) | a render for a **logged-in** customer → `private, no-store`; drops the session cookie from shared responses |

Why an outbox: Shopware's own delayed invalidation deletes the tags from
`invalidation_tags` **before** it purges, and its task handler swallows errors.
A refused or unreachable Trident would silently keep stale pages. With the
outbox, `trident:purge:status` shows every purge still owed.

## Install

```bash
composer require qoliber/trident-cache-shopware
bin/console plugin:refresh
bin/console plugin:install --activate TridentCache
bin/console cache:clear
```

Trident: start from [`../trident.toml`](../trident.toml). It has the
Shopware-specific keys: `vary_cookies = ["sw-cache-hash"]`, the Vary header
allowlist, ESI `propagate_headers`, and the rule that passes logged-in visitors.

## Configure

The connection lives in the **deployment config** (environment, `.env.local`),
which takes precedence over *Settings → Extensions → Trident Cache*:

```dotenv
# One or more Trident instances. JSON: single-quote it in .env files.
TRIDENT_INSTANCES='{"edge-1":{"api_url":"http://10.0.0.11:9301"},"edge-2":{"api_url":"http://10.0.0.12:9301","api_token":"other"}}'
TRIDENT_API_TOKEN=the-admin-auth_token   # default for instances that name none
TRIDENT_PEERS=127.0.0.1, 10.0.0.11, 10.0.0.12   # where Trident connects FROM (ESI); IPs, CIDRs, host names
# Optional: TRIDENT_API_URL (single instance), TRIDENT_PURGE_MODE=soft|hard,
# TRIDENT_TAG_PREFIX=shop1_ (several shops on one Trident), TRIDENT_ESI=0|1
```

- **Instance names:** 1–64 characters of `A-Z a-z 0-9 . _ -`. Invalid entries are skipped and reported by `trident:check` and the administration.
- **Which token goes where:**
  - `TRIDENT_API_TOKEN` (or a per-instance `api_token`) is used **only** with
    instances from the environment (`TRIDENT_INSTANCES`, `TRIDENT_API_URL`),
    never with the URL entered in the administration. Someone who can change
    the plugin settings can never make the deployment's token travel to
    another host.
  - The token entered in the administration is stored **encrypted and bound
    to the API URL saved with it**: XChaCha20-Poly1305 with the URL as
    associated data, the key derived from `APP_SECRET` (or
    `TRIDENT_TOKEN_KEY`).
- **What that means in practice:**
  - The settings form shows a placeholder, and the system-config search API
    only ever sees ciphertext.
  - **Changing the URL makes the stored token unusable until you re-enter
    it.** So does rotating `APP_SECRET`/`TRIDENT_TOKEN_KEY`.
    `trident:check` then says "re-enter the token", and no token is sent.
- **`TRIDENT_ALLOWED_API_HOSTS=trident, 10.0.0.12:9301`** (optional, and
  recommended in production): every instance URL whose host (or `host:port`)
  is not listed is refused. See [Security](#security).

## Security

The API URL is a setting an administrator can change, and the shop's server
then makes requests to it (a server-side request): without limits, anyone with
`system_config` write access could point it at an internal service and read
the answer on the Trident screens. The plugin limits this in four ways:

- **Set `TRIDENT_ALLOWED_API_HOSTS` in production.** It is the only setting
  that confines the API URL to the hosts you run Trident on; everything else
  below only narrows what a wrong URL can do. Or configure the instances in
  the environment (`TRIDENT_INSTANCES`), which the administration cannot change.
- **The URL's shape:** `http(s)://host[:port][/base-path]` only — no query,
  fragment or credentials.
- **Never link-local or cloud metadata** (`169.254.0.0/16`, `fe80::/10`,
  `fd00:ec2::254`, `100.100.100.200`, `metadata.google.internal`), even when
  allowlisted. It is checked on the **resolved** address at request time, and
  the connection is pinned to the address that was checked, so DNS rebinding
  cannot swap it. Private ranges stay allowed: Trident normally is internal.
- **Nothing unverified is displayed.** A screen shows data from an instance
  only after it answered as a Trident admin API (a version and a known cache
  mode); an error body from anything else is clipped to 300 characters,
  without control characters. No redirect is followed, and the token is sent
  only to the URL it was sealed with.

## Cache context

| Visitor | Page |
|---|---|
| anonymous | the shared page (HIT), header/footer as ESI fragments |
| cart, other currency, rule-dependent context | Shopware requires its `sw-cache-hash`: a shared variant **per context**, cached (every shopper with the same context shares it) |
| logged-in (`sw-states=logged-in`) | passed to Shopware; the render is `private, no-store` (it names the customer) |

The cart and account widgets load over AJAX (`/widgets/...`), so shared pages
never carry anyone's cart.

## Commands

| Command | |
|---|---|
| `trident:check` | reach every instance, show version and license |
| `trident:purge:status` | outbox per instance; exit 1 on a skipped instance, rows owed to a removed one, or a purge pending > 15 min |
| `trident:purge:drain [--force] [--now]` | deliver due rows (`--force` ignores the backoff; `--now` delivers every row at once, second deliveries and backstop rows included) |
| `trident:purge <tags…>` / `--all` | purge Shopware tags / the whole shop through the outbox |
| `trident:purge:forget <instance>` | drop the rows of an instance that is no longer configured |
| `trident:warm [--limit]` | queue the home page and canonical SEO URLs on every instance's warmer |

## Administration

*Settings → Extensions → Trident Cache* has these screens:

- Dashboard
- Purge (URL, product, category, tag, pattern with preview, host, whole shop)
- Cached pages with entry detail, Tags, Coverage, Warmer
- Launch mode, Reflect mode, Denoisers (with this shop's WAF export), Bans, Backends, DNS discovery, Live events

Product and category pages get a **Purge from Trident** button.

- **Per-instance:** every screen shows each instance separately; an unreachable one is reported, never fatal.
- **Privileges** (roles: *Trident Cache*):
  - `trident_cache:read` (viewer) for the screens;
  - `trident_cache:update` (editor) for every action.
- **Confirmation:** actions that change what every visitor is served require it, and the server enforces it.
- **Validation:** URLs must be on this shop's domains. Denoiser pins need one of this shop's hosts: the engine keys pins by the request host.

## Known limits (Trident 1.8)

- **Live events poller:** the bounded reader of the event stream
  (`Admin/EventPoller.php`) is plugin code today, as in the WooCommerce and
  Magento integrations. It moves into `qoliber/trident-php` in library 1.7.

- **Warmer sitemap source:** Shopware writes its sitemap only as `.xml.gz`, which the 1.8 warmer does not decompress. Use `trident:warm`.
- **ESI in assemble mode:** Trident's `assemble` mode does not carry fragment tags into the assembled page. Use `hole_punch`, or switch the plugin's ESI off with `assemble`.
- **Duplicate purges:** every stored invalidation also gets a backstop outbox row, due after Shopware's own task. It covers the moment when Shopware has deleted its tags but not yet purged. So each change is purged twice: once by Shopware's task, then about 6 minutes later by the backstop. A duplicate purge is harmless.

## This repository is a mirror

`qoliber/trident-cache-shopware` is developed in the Trident repository together with the
shared library [`qoliber/trident-php`](https://github.com/qoliber/trident-php)
and the live end-to-end test stacks, and published to
[github.com/qoliber/trident-cache-shopware](https://github.com/qoliber/trident-cache-shopware) automatically:
every commit there is a "Sync from trident-cache@…" snapshot. **Please open
issues there**; pull requests against the mirror cannot be merged, because the
next sync would overwrite them. Releases are the tags of that repository.
