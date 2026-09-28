/*
 * Trident Cache — administration screens (Shopware 6.7).
 *
 * Hand-written ES module, loaded by the administration through
 * .vite/entrypoints.json like any built bundle; templates are runtime strings,
 * as in Shopware's own bundles. Every screen reads /api/_action/trident/*
 * (AdminController): each configured Trident instance is shown separately, an
 * instance that is down or has a feature switched off is shown as such, and
 * actions that change what every visitor is served ask for confirmation here
 * AND are refused by the server without it.
 */
const { Module, Component, Mixin } = Shopware;

Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: null,
    key: 'trident_cache',
    roles: {
        viewer: { privileges: ['trident_cache:read'], dependencies: [] },
        editor: { privileges: ['trident_cache:read', 'trident_cache:update'], dependencies: ['trident_cache.viewer'] },
    },
});

function api() {
    const client = Shopware.Application.getContainer('init').httpClient;
    const headers = () => ({
        Authorization: `Bearer ${Shopware.Service('loginService').getToken()}`,
        Accept: 'application/json',
        'Content-Type': 'application/json',
    });
    return {
        get: (path, params) => client.get(`_action/trident/${path}`, { headers: headers(), params }).then((r) => r.data),
        post: (path, body) => client.post(`_action/trident/${path}`, body, { headers: headers() }).then((r) => r.data),
    };
}

function errorText(e) {
    return (e && e.response && e.response.data && (e.response.data.error
        || (e.response.data.errors && e.response.data.errors[0] && e.response.data.errors[0].detail)))
        || (e && e.message) || String(e);
}

const MODE = { key: 'mode', label: 'Mode', type: 'select', options: ['', 'soft', 'hard'], hint: 'empty = the plugin setting' };

/*
 * Screen definitions: what to load, which query filters to offer, which actions.
 * `confirm` marks an action that changes what every visitor is served.
 */
const SCREENS = [
    { key: 'dashboard', title: 'Dashboard', actions: [
        { action: 'outbox_drain', label: 'Deliver pending purges now', fields: [] },
        { action: 'outbox_drain', label: 'Retry failed purges now (ignore backoff)', fields: [], extra: { force: true } },
    ] },
    { key: 'purge', title: 'Purge', actions: [
        { action: 'purge_urls', label: 'Purge URLs', fields: [{ key: 'urls', label: 'URLs or paths on this shop, one per line', type: 'textarea' }, MODE] },
        { action: 'purge_products', label: 'Purge products', fields: [{ key: 'ids', label: 'Product ids, comma separated', type: 'list' }] },
        { action: 'purge_categories', label: 'Purge categories', fields: [{ key: 'ids', label: 'Category ids, comma separated', type: 'list' }] },
        { action: 'purge_tags', label: 'Purge cache tags (durable, every instance)', confirmIf: (b) => (b.tags || []).some((t) => String(t).toLowerCase() === 'all'), confirm: 'This tag is on every page of the shop. Purge it everywhere?', fields: [{ key: 'tags', label: 'Shopware cache tags, comma separated', type: 'list' }] },
        { action: 'purge_preview', label: 'Preview a pattern', fields: [{ key: 'pattern', label: 'Regular expression on the cache key (METHOD:scheme:host:/path)' }] },
        { action: 'purge_pattern', label: 'Purge pattern', confirm: 'Purge every entry matching this pattern — on EVERY site on the instance?', fields: [{ key: 'pattern', label: 'Regular expression on the cache key' }, MODE] },
        { action: 'purge_host', label: 'Purge host', confirm: 'Purge every entry of this host?', fields: [{ key: 'host', label: "Host (host[:port]) — one of this shop's domains" }, MODE] },
        { action: 'purge_all', label: 'Purge this whole shop', confirm: 'Purge EVERY page of this shop from every Trident instance?', fields: [] },
    ] },
    { key: 'entries', title: 'Cached pages', filters: [{ key: 'sort', label: 'Sort', type: 'select', options: ['age', 'hits', 'size'] }, { key: 'tag', label: 'Only with tag' }, { key: 'limit', label: 'Limit' }],
        lookup: { key: 'url', label: 'Look up one URL (path or URL on this shop)', screen: 'entry' },
        actions: [{ action: 'purge_urls', label: 'Purge a URL', fields: [{ key: 'urls', label: 'URL or path', type: 'textarea' }, MODE] }] },
    { key: 'tags', title: 'Tags', filters: [{ key: 'prefix', label: 'Tag prefix' }, { key: 'sort', label: 'Sort', type: 'select', options: ['count', 'name'] }],
        actions: [{ action: 'purge_tags', label: 'Purge tags', fields: [{ key: 'tags', label: 'Tags, comma separated', type: 'list' }, MODE] }] },
    { key: 'coverage', title: 'Coverage', actions: [] },
    { key: 'warmer', title: 'Warmer', actions: [
        { action: 'warmer_queue_shop', label: "Warm this shop's pages (home + canonical SEO URLs)", fields: [] },
        { action: 'warmer_run', label: 'Run the warmer now', fields: [{ key: 'sitemaps', label: 'Sitemap URL(s) on this shop, one per line (.xml or .xml.gz). Empty: the configured sources', type: 'textarea' }] },
        { action: 'warmer_cancel', label: 'Cancel the running warm-up', fields: [] },
        { action: 'warmer_queue', label: 'Warm these URLs', fields: [{ key: 'urls', label: 'URLs or paths on this shop, one per line', type: 'textarea' }] },
    ] },
    { key: 'launch', title: 'Launch mode', actions: [
        { action: 'launch_start', label: 'Start launch mode', confirm: 'Start launch mode? Visitors get the maintenance page while the cache warms.', fields: [{ key: 'reason', label: 'Reason' }] },
        { action: 'launch_complete', label: 'Complete (go live)', confirm: 'Go live now?', fields: [] },
        { action: 'launch_abort', label: 'Abort', confirm: 'Abort the launch?', fields: [{ key: 'reason', label: 'Reason' }] },
    ] },
    { key: 'reflect', title: 'Reflect mode', actions: [
        { action: 'reflect_enable', label: 'Enable reflect mode', confirm: 'Enable reflect mode? Trident serves cached pages and defers purges.', fields: [{ key: 'level', label: 'Level', type: 'select', options: ['', 'full', 'selective', 'ttl_extension'] }, { key: 'duration', label: 'Duration (e.g. 30m)' }, { key: 'reason', label: 'Reason' }] },
        { action: 'reflect_disable', label: 'Disable reflect mode', confirm: 'Disable reflect mode?', fields: [{ key: 'mode', label: 'Deferred purges', type: 'select', options: ['', 'replay', 'hard'] }] },
    ] },
    { key: 'denoisers', title: 'Denoisers', actions: [
        { action: 'denoiser_query_pin', label: 'Pin a query parameter', fields: [{ key: 'param', label: 'Parameter' }, { key: 'class', label: 'Class', type: 'select', options: ['noise', 'signal'] }, { key: 'host', label: 'Host (empty = this shop, if it has one domain)' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_query_unpin', label: 'Unpin a query parameter', fields: [{ key: 'param', label: 'Parameter' }, { key: 'host', label: 'Host (empty = this shop if it has one domain; * to clean up)' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_path_pin', label: 'Pin a path zone', fields: [{ key: 'status', label: 'Status', type: 'select', options: ['dead', 'alive'] }, { key: 'host', label: 'Host (empty = this shop, if it has one domain)' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_path_unpin', label: 'Unpin a path zone', fields: [{ key: 'host', label: 'Host' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_zone_delete', label: 'Forget a path zone', confirm: 'Forget this learned path zone?', fields: [{ key: 'host', label: 'Host' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_scope_delete', label: 'Forget a query scope', confirm: 'Forget this learned query scope?', fields: [{ key: 'host', label: 'Host' }, { key: 'path_prefix', label: 'Path prefix' }] },
        { action: 'denoiser_reset', label: 'Reset denoisers', confirm: 'Reset what the denoisers learned?', fields: [{ key: 'which', label: 'Which', type: 'select', options: ['query', 'path', 'all'] }] },
    ] },
    { key: 'bans', title: 'Bans', actions: [
        { action: 'ban_create', label: 'Add a ban', confirm: 'Ban these entries?', fields: [{ key: 'pattern', label: 'Pattern' }, { key: 'type', label: 'Type', type: 'select', options: ['url', 'tag', 'pattern'] }] },
        { action: 'ban_delete', label: 'Remove a ban', confirm: 'Remove this ban?', fields: [{ key: 'id', label: 'Ban id' }] },
    ] },
    { key: 'backends', title: 'Backends', actions: [
        { action: 'backend_drain', label: 'Drain a backend', confirm: 'Take this backend out of rotation?', fields: [{ key: 'name', label: 'Backend name' }] },
        { action: 'backend_restore', label: 'Restore a backend', fields: [{ key: 'name', label: 'Backend name' }] },
    ] },
    { key: 'discovery', title: 'DNS discovery', actions: [
        { action: 'discovery_refresh', label: 'Refresh discovery', fields: [{ key: 'name', label: 'Backend name' }] },
    ] },
    { key: 'events', title: 'Live events', actions: [] },
];

Component.register('trident-cache-data', {
    name: 'trident-cache-data',
    props: { value: { required: false, default: null }, depth: { type: Number, default: 0 } },
    computed: {
        kind() {
            const v = this.value;
            if (v === null || v === undefined || v === '') return 'empty';
            if (Array.isArray(v)) {
                if (v.length === 0) return 'emptylist';
                return v.every((x) => x && typeof x === 'object' && !Array.isArray(x)) ? 'table' : 'list';
            }
            if (typeof v === 'object') return 'object';
            if (typeof v === 'boolean') return 'bool';
            return 'scalar';
        },
        columns() {
            const keys = [];
            (this.value || []).slice(0, 50).forEach((row) => Object.keys(row).forEach((k) => { if (!keys.includes(k)) keys.push(k); }));
            return keys.slice(0, 12);
        },
        rows() { return (this.value || []).slice(0, 200); },
        entries() { return Object.entries(this.value || {}); },
    },
    methods: {
        cell(v) {
            if (v === null || v === undefined) return '—';
            if (typeof v === 'object') return JSON.stringify(v);
            return String(v);
        },
    },
    template: `
<span v-if="kind === 'empty'" class="trident-muted">—</span>
<span v-else-if="kind === 'emptylist'" class="trident-muted">(none)</span>
<span v-else-if="kind === 'bool'">{{ value ? 'yes' : 'no' }}</span>
<span v-else-if="kind === 'scalar'">{{ value }}</span>
<span v-else-if="kind === 'list'">{{ value.map(cell).join(', ') }}</span>
<table v-else-if="kind === 'table'" class="trident-table">
  <thead><tr><th v-for="c in columns" :key="c">{{ c }}</th></tr></thead>
  <tbody><tr v-for="(row, i) in rows" :key="i"><td v-for="c in columns" :key="c">{{ cell(row[c]) }}</td></tr></tbody>
</table>
<table v-else class="trident-kv">
  <tr v-for="[k, v] in entries" :key="k"><th>{{ k }}</th><td><trident-cache-data :value="v" :depth="depth + 1" /></td></tr>
</table>`,
});

Component.register('trident-cache-screen', {
    name: 'trident-cache-screen',
    mixins: [Mixin.getByName('notification')],
    inject: ['acl'],
    data() {
        return { overview: null, result: null, loading: false, error: null, instance: '*', filters: {}, lookup: '', forms: {}, last: null, busy: false };
    },
    computed: {
        screenKey() { return this.$route.meta.tridentScreen; },
        screen() { return SCREENS.find((s) => s.key === this.screenKey); },
        screens() { return SCREENS; },
        instances() { return (this.overview && this.overview.settings && this.overview.settings.instances) || []; },
        canEdit() { return this.acl.can('trident_cache.editor'); },
    },
    watch: { $route() { this.reset(); this.load(); } },
    created() { this.reset(); this.loadOverview(); this.load(); },
    methods: {
        reset() {
            this.result = null; this.error = null; this.last = null; this.lookup = '';
            const forms = {};
            (this.screen.actions || []).forEach((a, i) => {
                forms[i] = {};
                a.fields.forEach((f) => { forms[i][f.key] = f.type === 'select' ? (f.options[0] || '') : ''; });
            });
            this.forms = forms;
            const filters = {};
            (this.screen.filters || []).forEach((f) => { filters[f.key] = f.type === 'select' ? f.options[0] : ''; });
            this.filters = filters;
        },
        loadOverview() {
            api().get('overview').then((o) => { this.overview = o; }).catch((e) => { this.error = errorText(e); });
        },
        load(screen) {
            this.loading = true; this.error = null;
            const params = Object.assign({ instance: this.instance }, this.filters);
            if (screen === 'entry') params.url = this.lookup;
            return api().get(`screen/${screen || this.screenKey}`, params)
                .then((r) => { this.result = r; })
                .catch((e) => { this.error = errorText(e); })
                .finally(() => { this.loading = false; });
        },
        body(i) {
            const a = this.screen.actions[i];
            const body = Object.assign({ instance: this.instance }, a.extra || {});
            a.fields.forEach((f) => {
                const v = this.forms[i][f.key];
                if (f.type === 'list') body[f.key] = String(v || '').split(/[\s,]+/).filter(Boolean);
                else if (v !== '') body[f.key] = v;
            });
            return body;
        },
        run(i) {
            const a = this.screen.actions[i];
            const body = this.body(i);
            if (a.confirm && (!a.confirmIf || a.confirmIf(body))) {
                // eslint-disable-next-line no-alert
                if (!window.confirm(a.confirm)) return;
                body.confirm = true;
            }
            this.busy = true;
            api().post(`action/${a.action}`, body)
                .then((r) => {
                    this.last = { label: a.label, data: r };
                    const failed = (r.results || []).filter((x) => !x.ok).length + (r.failed || 0);
                    if (failed > 0) this.createNotificationWarning({ message: `${a.label}: ${failed} instance(s) or purge(s) failed — see the result below` });
                    else this.createNotificationSuccess({ message: `${a.label}: done` });
                    this.load();
                })
                .catch((e) => { this.createNotificationError({ message: `${a.label}: ${errorText(e)}` }); })
                .finally(() => { this.busy = false; });
        },
    },
    template: `
<sw-page class="trident-cache">
  <template #smart-bar-header><h2>Trident Cache <span class="trident-muted">› {{ screen.title }}</span></h2></template>
  <template #content>
    <sw-card-view>
      <div class="trident-nav">
        <router-link v-for="s in screens" :key="s.key" :to="{ name: 'trident.cache.' + s.key }" class="trident-nav__item" :class="{ 'is--active': s.key === screenKey }">{{ s.title }}</router-link>
      </div>
      <mt-card title="Instances" position-identifier="trident-cache-instances">
        <div v-if="overview">
          <p>Configured from <strong>{{ overview.settings.source }}</strong>, purge mode <strong>{{ overview.settings.mode }}</strong><span v-if="overview.settings.tag_prefix">, tag prefix <code>{{ overview.settings.tag_prefix }}</code></span>, ESI <strong>{{ overview.settings.esi ? 'on' : 'off' }}</strong>.</p>
          <p v-for="e in overview.settings.errors" :key="e" class="trident-error">Skipped: {{ e }}</p>
          <p v-if="!overview.settings.instances.length" class="trident-error">No Trident instance configured — set TRIDENT_INSTANCES in the environment or the API URL in the plugin settings.</p>
          <label>Show <select v-model="instance" @change="load()" class="trident-select"><option value="*">every instance</option><option v-for="i in instances" :key="i.name" :value="i.name">{{ i.name }} ({{ i.api_url }})</option></select></label>
          <p v-if="overview.outbox">Purge outbox: <strong>{{ overview.outbox.pending }}</strong> pending<span v-if="overview.outbox.last_error"> — last failure: {{ overview.outbox.last_error }}</span></p>
        </div>
        <p v-else-if="error" class="trident-error">{{ error }}</p>
      </mt-card>
      <mt-card v-if="screen.filters || screen.lookup" title="Filter" position-identifier="trident-cache-filters">
        <div class="trident-form">
          <label v-for="f in screen.filters || []" :key="f.key">{{ f.label }}
            <select v-if="f.type === 'select'" v-model="filters[f.key]" class="trident-select"><option v-for="o in f.options" :key="o" :value="o">{{ o }}</option></select>
            <input v-else v-model="filters[f.key]" class="trident-input" />
          </label>
          <button class="trident-button" @click="load()">Apply</button>
        </div>
        <div v-if="screen.lookup" class="trident-form">
          <label>{{ screen.lookup.label }}<input v-model="lookup" class="trident-input trident-lookup" /></label>
          <button class="trident-button" :disabled="!lookup" @click="load(screen.lookup.screen)">Look up</button>
        </div>
      </mt-card>
      <mt-card :title="screen.title" position-identifier="trident-cache-result" :is-loading="loading">
        <p v-if="error" class="trident-error">{{ error }}</p>
        <div v-else-if="result">
          <template v-if="result.results">
            <div v-for="r in result.results" :key="r.instance" class="trident-instance" :data-instance="r.instance">
              <h3>{{ r.instance }} <span v-if="r.ok" class="trident-ok">ok</span><span v-else-if="r.disabled" class="trident-muted">not enabled on this instance</span><span v-else class="trident-error">{{ r.unreachable ? 'unreachable' : 'error' }}</span></h3>
              <p v-if="!r.ok" class="trident-error trident-reason">{{ r.error }}</p>
              <trident-cache-data v-else :value="r.data" />
            </div>
          </template>
          <trident-cache-data v-else :value="result" />
        </div>
      </mt-card>
      <mt-card v-if="screen.actions.length" title="Actions" position-identifier="trident-cache-actions">
        <p v-if="!canEdit" class="trident-muted">Your role can view these screens but not change anything (Trident Cache: editor).</p>
        <form v-for="(a, i) in screen.actions" v-else :key="i" class="trident-form" :data-action="a.action" @submit.prevent="run(i)">
          <label v-for="f in a.fields" :key="f.key">{{ f.label }}
            <textarea v-if="f.type === 'textarea'" v-model="forms[i][f.key]" rows="3" class="trident-input"></textarea>
            <select v-else-if="f.type === 'select'" v-model="forms[i][f.key]" class="trident-select"><option v-for="o in f.options" :key="o" :value="o">{{ o || '(default)' }}</option></select>
            <input v-else v-model="forms[i][f.key]" class="trident-input" />
          </label>
          <button type="submit" class="trident-button" :class="{ 'is--danger': a.confirm }" :disabled="busy">{{ a.label }}</button>
        </form>
      </mt-card>
      <mt-card v-if="last" :title="'Result: ' + last.label" position-identifier="trident-cache-last">
        <trident-cache-data :value="last.data" />
      </mt-card>
    </sw-card-view>
  </template>
</sw-page>`,
});

const routes = {};
SCREENS.forEach((s) => {
    routes[s.key] = {
        component: 'trident-cache-screen',
        path: s.key,
        meta: { parentPath: 'sw.settings.index.plugins', privilege: 'trident_cache.viewer', tridentScreen: s.key },
    };
});

Module.register('trident-cache', {
    type: 'plugin',
    name: 'trident-cache',
    title: 'Trident Cache',
    description: 'Trident HTTP cache: status, purges, warmer and operator tools',
    color: '#189EFF',
    icon: 'regular-rocket',
    routes,
    settingsItem: {
        group: 'plugins',
        to: 'trident.cache.dashboard',
        icon: 'regular-rocket',
        privilege: 'trident_cache.viewer',
        label: 'Trident Cache',
    },
});

/* "Purge from Trident" on the product and category pages: the same
 * invalidation Shopware runs on save, through the durable outbox. */
function purgeButton(action, idGetter) {
    return {
        methods: {
            onTridentPurge() {
                const id = idGetter(this);
                if (!id) return;
                api().post(`action/${action}`, { ids: [id] })
                    .then((r) => {
                        if (r.failed > 0) this.createNotificationWarning({ message: `Trident: ${r.failed} purge(s) kept for retry — ${JSON.stringify(r.instances)}` });
                        else this.createNotificationSuccess({ message: `Trident: purged (${r.delivered} delivered)` });
                    })
                    .catch((e) => this.createNotificationError({ message: `Trident: ${errorText(e)}` }));
            },
        },
    };
}

Component.override('sw-product-detail', Object.assign(purgeButton('purge_products', (vm) => vm.productId || (vm.product && vm.product.id)), {
    template: `{% block sw_product_detail_actions_abort %}
<button v-if="acl.can('trident_cache.editor') && (productId || (product && product.id))" class="trident-button trident-purge-page" @click="onTridentPurge">Purge from Trident</button>
{% parent %}
{% endblock %}`,
}));

Component.override('sw-category-detail', Object.assign(purgeButton('purge_categories', (vm) => vm.categoryId || (vm.category && vm.category.id)), {
    template: `{% block sw_category_smart_bar_abort %}
<button v-if="acl.can('trident_cache.editor') && (categoryId || (category && category.id))" class="trident-button trident-purge-page" @click="onTridentPurge">Purge from Trident</button>
{% parent %}
{% endblock %}`,
}));

const style = document.createElement('style');
style.textContent = `
.trident-nav { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
.trident-nav__item { padding: 6px 12px; border-radius: 4px; background: #f0f2f5; color: #52667a; text-decoration: none; font-size: 13px; }
.trident-nav__item.is--active { background: #189eff; color: #fff; }
.trident-table, .trident-kv { border-collapse: collapse; font-size: 12px; width: 100%; margin: 6px 0; }
.trident-table th, .trident-table td, .trident-kv th, .trident-kv td { border-bottom: 1px solid #e0e6ed; padding: 4px 8px; text-align: left; vertical-align: top; word-break: break-word; }
.trident-kv th { width: 220px; color: #52667a; font-weight: 600; }
.trident-muted { color: #8a9bab; }
.trident-ok { color: #37d046; font-size: 12px; }
.trident-error { color: #de294c; }
.trident-instance { margin-bottom: 18px; }
.trident-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; padding: 10px 0; border-bottom: 1px solid #f0f2f5; }
.trident-form label { display: flex; flex-direction: column; font-size: 12px; color: #52667a; gap: 4px; }
.trident-input, .trident-select { border: 1px solid #d1d9e0; border-radius: 4px; padding: 6px 8px; min-width: 220px; font-size: 13px; }
.trident-lookup { min-width: 420px; }
.trident-button { background: #189eff; color: #fff; border: 0; border-radius: 4px; padding: 8px 14px; cursor: pointer; font-size: 13px; }
.trident-button.is--danger { background: #de294c; }
.trident-button[disabled] { opacity: .5; cursor: default; }
.trident-purge-page { margin-right: 8px; }
`;
document.head.appendChild(style);
