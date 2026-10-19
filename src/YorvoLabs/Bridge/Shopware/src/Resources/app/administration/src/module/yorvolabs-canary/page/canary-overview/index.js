import template from './canary-overview.html.twig';
import './canary-overview.scss';

const { Component } = Shopware;

// ─── Demo data ──────────────────────────────────────────────────────────────
const DEMO = {
    readiness: {
        status: 'pass',
        checks: [
            { name: 'database', status: 'pass', duration_ms: 4, error: null },
            { name: 'cache_adapter', status: 'pass', duration_ms: 2, error: null },
            { name: 'filesystem', status: 'pass', duration_ms: 1, error: null },
            { name: 'message_queue', status: 'warn', duration_ms: 18, error: 'Queue depth: 120' },
            { name: 'elasticsearch', status: 'pass', duration_ms: 6, error: null },
        ],
    },
    metrics: {
        metrics: {
            shop_orders_count:          42,
            shop_customers_count:       17,
            shop_carts_active:          91,
            shop_queue_depth:          120,
            shop_queue_failed:           0,
            shop_revenue_total:       3847.50,
            shop_newsletter_subscribers: 2341,
            shop_products_count:       891,
            opcache_hit_rate:           98.4,
            opcache_memory_used_bytes:  67108864,
            opcache_memory_free_bytes:  67108864,
            php_memory_usage_bytes:     33554432,
        },
    },
    opcache: {
        enabled: true,
        cache_full: false,
        restart_pending: false,
        memory: { used_bytes: 67108864, free_bytes: 67108864, wasted_bytes: 0, wasted_percent: 0 },
        statistics: { cached_scripts: 1847, hits: 498201, misses: 9124, hit_rate: 98.2 },
    },
    diagConfig: {
        namespace: 'YorvoLabsCanary',
        snapshot: { version: '1.2.0' },
        validation: [
            { name: 'APP_ENV is production', pass: true,  message: 'prod' },
            { name: 'Queue transport configured', pass: true, message: 'async' },
            { name: 'Mailer transport configured', pass: false, message: 'null:// — emails disabled' },
        ],
    },
    diagQueue: {
        queues: [
            { name: 'async',  depth: 120, oldest_age_seconds: 340 },
            { name: 'failed', depth: 0,   oldest_age_seconds: null },
        ],
        failed: { count: 0, oldest_failed_at: null },
        workers: { detected: true, note: '2 workers running' },
    },
    diagPlugins: {
        plugins: [
            { name: 'YorvoLabsCanary', version: '1.2.0', active: true,  installed: true, upgrade_version: null },
            { name: 'SwagPayPal',   version: '9.3.0', active: true,  installed: true, upgrade_version: '9.4.0' },
            { name: 'SwagCms',      version: '4.1.0', active: false, installed: true, upgrade_version: null },
        ],
    },
    diagLogs: {
        file: 'dev.log',
        format: 'json',
        lines_returned: 3,
        entries: [
            { level: 200, level_name: 'INFO',    message: 'Request handled successfully', datetime: '2026-04-26T10:00:00+00:00', context: {} },
            { level: 400, level_name: 'WARNING', message: 'Queue depth exceeds threshold', datetime: '2026-04-26T09:58:00+00:00', context: { depth: 120, threshold: 100 } },
            { level: 500, level_name: 'ERROR',   message: 'Failed to send newsletter batch', datetime: '2026-04-26T09:55:00+00:00', context: { batch_id: 42 } },
        ],
    },
    auditLog: {
        file: 'canary_audit.log',
        lines_returned: 2,
        entries: [
            '{"timestamp":"2026-04-26T10:00:00+00:00","ip":"[IP]","scope":"monitoring","endpoint":"/canary/health/live","method":"GET","status_code":200}',
            '{"timestamp":"2026-04-26T09:45:00+00:00","ip":"[IP]","scope":"admin","endpoint":"/canary/diag/env","method":"GET","status_code":200}',
        ],
    },
    canaryInfo: {
        app_url: 'https://demo.shop.example',
        route_prefix: '/canary',
        tokens: {
            monitoring:  { label: 'Monitoring (read-only health & metrics)', configured: true  },
            cache:       { label: 'Cache management',                         configured: true  },
            diagnostics: { label: 'Diagnostics (env, logs, queue)',           configured: false },
            deploy:      { label: 'Deployment lifecycle',                     configured: false },
            admin:       { label: 'Admin (all scopes)',                       configured: false },
        },
        rate_limits: { health: 60, mutation: 10 },
        allowed_cidrs: [],
    },
    logFiles: {
        files: [
            { name: 'dev.log',          size: 524288, modified: '2026-04-26T10:00:00+00:00' },
            { name: 'canary_audit.log', size: 8192,   modified: '2026-04-26T09:45:00+00:00' },
        ],
    },
    deployHooks: { hooks: [{ name: 'ClearCacheHook', phase: 'post' }, { name: 'WarmCacheHook', phase: 'post' }] },
    rateLimitStats: {
        configured: { health: 60, mutation: 10 },
        active_buckets: 3,
        total_hits_last_minute: 14,
        window_seconds: 60,
    },
};

Component.register('canary-overview', {
    template,

    data() {
        return {
            activeTab: 'overview',
            isDemo: new URLSearchParams(window.location.search).get('demo') === '1',

            readiness:   null,
            metrics:     null,
            diagConfig:  null,
            opcache:     null,
            diagQueue:   null,
            diagPlugins: null,
            diagLogs:    null,
            canaryInfo:  null,
            logFiles:    null,
            selectedLog: '',
            logLines: 25,
            metricsHours: 24,
            deployHooks:    null,
            rateLimitStats: null,
            tokenScope: 'monitoring',
            generatedToken: null,
            cacheResetPending: false,
            cacheShopwarePending: false,

            logFilter: '',
            expandedLogEntries: {},

            loading: {
                readiness:      false,
                metrics:        false,
                config:         false,
                opcache:        false,
                queue:          false,
                plugins:        false,
                logs:           false,
                info:           false,
                logFiles:       false,
                deployHooks:    false,
                rateLimitStats: false,
            },
            errors: {
                readiness:      null,
                metrics:        null,
                config:         null,
                opcache:        null,
                queue:          null,
                plugins:        null,
                logs:           null,
                info:           null,
                deployHooks:    null,
                rateLimitStats: null,
                cache:          null,
            },
        };
    },

    computed: {
        statusVariant() {
            if (!this.readiness) return 'neutral';
            const map = { pass: 'success', warn: 'warning', fail: 'danger' };
            return map[this.readiness.status] || 'neutral';
        },

        heroColors() {
            const s = this.readiness ? this.readiness.status : 'pass';
            if (s === 'warn') return {
                border: '#fde68a', shadow: 'rgba(245,158,11,0.15)',
                bg: 'linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%)',
                circle: '#d97706', circleShadow: 'rgba(217,119,6,0.4)',
                headline: '#78350f', sub: '#92400e', count: '#d97706',
            };
            if (s === 'fail') return {
                border: '#fecaca', shadow: 'rgba(239,68,68,0.15)',
                bg: 'linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%)',
                circle: '#dc2626', circleShadow: 'rgba(220,38,38,0.4)',
                headline: '#7f1d1d', sub: '#991b1b', count: '#dc2626',
            };
            return {
                border: '#bbf7d0', shadow: 'rgba(22,163,74,0.12)',
                bg: 'linear-gradient(135deg,#f0fdf4 0%,#dcfce7 100%)',
                circle: '#16a34a', circleShadow: 'rgba(22,163,74,0.4)',
                headline: '#14532d', sub: '#166534', count: '#16a34a',
            };
        },

        heroSubtitle() {
            if (!this.readiness || !this.readiness.checks) return '';
            const total   = this.readiness.checks.length;
            const passing = this.readiness.checks.filter((c) => c.status === 'pass').length;
            const warns   = this.readiness.checks.filter((c) => c.status === 'warn').length;
            const fails   = this.readiness.checks.filter((c) => c.status !== 'pass' && c.status !== 'warn').length;
            if (passing === total) return total + ' of ' + total + ' checks passing — response times nominal';
            const parts = [];
            if (warns) parts.push(warns + ' warning' + (warns > 1 ? 's' : ''));
            if (fails) parts.push(fails + ' failure' + (fails > 1 ? 's' : ''));
            return passing + ' of ' + total + ' checks passing — ' + parts.join(', ') + ' detected';
        },

        passCount() {
            if (!this.readiness || !this.readiness.checks) return 0;
            return this.readiness.checks.filter((c) => c.status === 'pass').length;
        },

        totalPendingDepth() {
            if (!this.diagQueue || !this.diagQueue.queues) return 0;
            return this.diagQueue.queues
                .filter((q) => q.name !== 'failed')
                .reduce((sum, q) => sum + (q.depth || 0), 0);
        },

        oldestPendingSeconds() {
            if (!this.diagQueue || !this.diagQueue.queues) return null;
            const ages = this.diagQueue.queues
                .filter((q) => q.name !== 'failed' && q.oldest_age_seconds !== null)
                .map((q) => q.oldest_age_seconds);
            return ages.length > 0 ? Math.max(...ages) : null;
        },

        pluginCounts() {
            if (!this.diagPlugins || !this.diagPlugins.plugins) {
                return { total: 0, active: 0, inactive: 0, updates: 0 };
            }
            const p = this.diagPlugins.plugins;
            return {
                total:    p.length,
                active:   p.filter((x) => x.active).length,
                inactive: p.filter((x) => !x.active).length,
                updates:  p.filter((x) => x.upgrade_version).length,
            };
        },

        queueHealthStatus() {
            const failed  = this.metrics ? (this.metrics.metrics.shop_queue_failed || 0) : null;
            const pending = this.metrics ? (this.metrics.metrics.shop_queue_depth  || 0) : null;
            const workers = this.diagQueue ? this.diagQueue.workers.detected : null;
            const oldest  = this.oldestPendingSeconds;

            if (failed === null && pending === null) {
                return { status: 'unknown', label: 'Loading', description: '', dotColor: '#94a3b8', pillBg: 'rgba(148,163,184,0.15)', pillColor: '#94a3b8', pillBorder: 'rgba(148,163,184,0.3)' };
            }
            if (failed > 0) {
                return {
                    status: 'critical', label: 'Messages Failed',
                    description: failed + ' message' + (failed !== 1 ? 's' : '') + ' in the dead-letter queue — manual intervention required.',
                    dotColor: '#f87171', pillBg: 'rgba(248,113,113,0.15)', pillColor: '#f87171', pillBorder: 'rgba(248,113,113,0.3)',
                };
            }
            if (workers === false) {
                return {
                    status: 'warning', label: 'No Workers Detected',
                    description: 'Queue workers appear to be offline. Pending messages will not be processed until workers are restarted.',
                    dotColor: '#fbbf24', pillBg: 'rgba(251,191,36,0.15)', pillColor: '#fbbf24', pillBorder: 'rgba(251,191,36,0.3)',
                };
            }
            if (oldest !== null && oldest > 300) {
                return {
                    status: 'warning', label: 'Processing Slow',
                    description: 'Oldest pending message is ' + oldest + 's old. Workers may be overloaded or stuck.',
                    dotColor: '#fbbf24', pillBg: 'rgba(251,191,36,0.15)', pillColor: '#fbbf24', pillBorder: 'rgba(251,191,36,0.3)',
                };
            }
            if (pending > 100) {
                return {
                    status: 'warning', label: 'Queue Backlog',
                    description: pending + ' messages pending. Workers are processing but may be under load.',
                    dotColor: '#fbbf24', pillBg: 'rgba(251,191,36,0.15)', pillColor: '#fbbf24', pillBorder: 'rgba(251,191,36,0.3)',
                };
            }
            const parts = ['No failed messages'];
            if (workers !== null) parts.push(workers ? 'Workers running' : 'Workers not detected');
            parts.push(pending === 0 ? 'Queue clear' : pending + ' pending');
            return {
                status: 'healthy', label: 'Processing Normally',
                description: parts.join(' · ') + '.',
                dotColor: '#4ade80', pillBg: 'rgba(74,222,128,0.15)', pillColor: '#4ade80', pillBorder: 'rgba(74,222,128,0.3)',
            };
        },

        metricsPeriodLabel() {
            const h = parseInt(this.metricsHours, 10);
            if (h === 1)   return 'last hour';
            if (h === 6)   return 'last 6 hours';
            if (h === 168) return 'last 7 days';
            return 'last 24 hours';
        },

        filteredLogEntries() {
            if (!this.diagLogs || !this.diagLogs.entries) return [];
            if (!this.logFilter) return this.diagLogs.entries;
            const f = this.logFilter.toLowerCase();
            return this.diagLogs.entries.filter((e) => {
                if (typeof e === 'string') return e.toLowerCase().includes(f);
                return (((e.level_name || e.level || '') + ' ' + (e.message || '')).toLowerCase().includes(f));
            });
        },

        tokenStatusList() {
            if (!this.canaryInfo || !this.canaryInfo.tokens) return [];
            return Object.entries(this.canaryInfo.tokens).map(([key, val]) => ({
                key,
                label: val.label,
                configured: val.configured,
            }));
        },

        logLevelCounts() {
            const counts = { error: 0, warning: 0, info: 0, debug: 0 };
            if (!this.diagLogs || !this.diagLogs.entries) return counts;
            for (const entry of this.diagLogs.entries) {
                const lvl = this.logEntryLevel(entry);
                if (['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(lvl)) counts.error++;
                else if (lvl === 'WARNING') counts.warning++;
                else if (lvl === 'DEBUG') counts.debug++;
                else counts.info++;
            }
            return counts;
        },

        logFileOptions() {
            const base = [{ value: '', label: 'Latest file' }];
            if (!this.logFiles || !this.logFiles.files) return base;
            return [...base, ...this.logFiles.files.map((f) => ({ value: f.name, label: f.name }))];
        },

        generatedEnvLine() {
            if (!this.generatedToken || !this.tokenScope) return '';
            const envVar = 'CANARY_TOKEN_' + this.tokenScope.toUpperCase();
            return `${envVar}=${this.generatedToken}`;
        },

        externalEndpoints() {
            if (!this.canaryInfo) return [];
            const base   = (this.canaryInfo.app_url || '').replace(/\/$/, '');
            const prefix = this.canaryInfo.route_prefix || '';
            return [
                { method: 'GET',    label: 'Liveness',                  path: prefix + '/health/live',             scope: 'MONITORING'  },
                { method: 'GET',    label: 'Readiness',                  path: prefix + '/health/ready',            scope: 'MONITORING'  },
                { method: 'GET',    label: 'Metrics (Prometheus)',        path: prefix + '/health/metrics',          scope: 'MONITORING'  },
                { method: 'GET',    label: 'OPcache status',             path: prefix + '/cache/opcache',           scope: 'CACHE'       },
                { method: 'POST',   label: 'OPcache reset',              path: prefix + '/cache/opcache/reset',     scope: 'CACHE'       },
                { method: 'POST',   label: 'Clear all Shopware caches',  path: prefix + '/cache/shopware',          scope: 'CACHE'       },
                { method: 'POST',   label: 'Clear specific cache pool',  path: prefix + '/cache/shopware/{pool}',   scope: 'CACHE'       },
                { method: 'POST',   label: 'Flush cache adapters',       path: prefix + '/cache/adapters',          scope: 'CACHE'       },
                { method: 'GET',    label: 'Diag: Env',                  path: prefix + '/diag/env',                scope: 'DIAGNOSTICS' },
                { method: 'GET',    label: 'Diag: Config',               path: prefix + '/diag/config',             scope: 'DIAGNOSTICS' },
                { method: 'GET',    label: 'Diag: Logs',                 path: prefix + '/diag/logs',               scope: 'DIAGNOSTICS' },
                { method: 'GET',    label: 'Diag: Queue',                path: prefix + '/diag/queue',              scope: 'DIAGNOSTICS' },
                { method: 'GET',    label: 'Diag: Plugins',              path: prefix + '/diag/plugins',            scope: 'DIAGNOSTICS' },
                { method: 'GET',    label: 'Pre-Deploy check',           path: prefix + '/deploy/pre',              scope: 'DEPLOY'      },
                { method: 'POST',   label: 'Post-Deploy',                path: prefix + '/deploy/post',             scope: 'DEPLOY'      },
                { method: 'GET',    label: 'Maintenance mode status',    path: prefix + '/deploy/maintenance',      scope: 'DEPLOY'      },
                { method: 'PUT',    label: 'Enable maintenance mode',    path: prefix + '/deploy/maintenance',      scope: 'DEPLOY'      },
                { method: 'DELETE', label: 'Disable maintenance mode',   path: prefix + '/deploy/maintenance',      scope: 'DEPLOY'      },
            ].map((e) => ({ ...e, url: base + e.path }));
        },
    },

    watch: {
        tokenScope() { this.generatedToken = null; },
    },

    created() {
        if (this.isDemo) {
            this.loadDemoData();
            return;
        }
        const urlTab = new URLSearchParams(window.location.search).get('tab');
        if (urlTab) { this.activeTab = urlTab; }
        this.fetchForTab(this.activeTab);
    },

    methods: {
        _httpClient() {
            return Shopware.Application.getContainer('init').httpClient;
        },

        _headers() {
            return { Authorization: `Bearer ${Shopware.Context.api.authToken.access}` };
        },

        stringify(val) {
            return JSON.stringify(val, null, 2);
        },

        loadDemoData() {
            this.readiness      = DEMO.readiness;
            this.metrics        = DEMO.metrics;
            this.opcache        = DEMO.opcache;
            this.diagConfig     = DEMO.diagConfig;
            this.diagQueue      = DEMO.diagQueue;
            this.diagPlugins    = DEMO.diagPlugins;
            this.diagLogs       = DEMO.diagLogs;
            this.canaryInfo     = DEMO.canaryInfo;
            this.logFiles       = DEMO.logFiles;
            this.deployHooks    = DEMO.deployHooks;
            this.rateLimitStats = DEMO.rateLimitStats;
        },

        switchTab(tabOrItem) {
            // sw-tabs emits the tab-item component object; a direct string call still works.
            const tab = typeof tabOrItem === 'object' && tabOrItem !== null ? tabOrItem.name : tabOrItem;
            this.activeTab = tab;
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', tab);
                window.history.replaceState({}, '', url.toString());
            } catch (_) {}
            if (!this.isDemo) { this.fetchForTab(tab); }
        },

        fetchForTab(tab) {
            if (tab === 'overview') {
                if (!this.readiness)      this.fetchReadiness();
                if (!this.metrics)        this.fetchMetrics();
                if (!this.opcache)        this.fetchOpcache();
                if (!this.diagConfig)     this.fetchDiagConfig();
                if (!this.canaryInfo)     this.fetchCanaryInfo();
                if (!this.deployHooks)    this.fetchDeployHooks();
                if (!this.rateLimitStats) this.fetchRateLimitStats();
                if (!this.diagQueue)      this.fetchDiagQueue();
            } else if (tab === 'queue') {
                if (!this.diagQueue) this.fetchDiagQueue();
            } else if (tab === 'plugins') {
                if (!this.diagPlugins) this.fetchDiagPlugins();
            } else if (tab === 'logs') {
                if (!this.logFiles) this.fetchLogFiles();
                if (!this.diagLogs) this.fetchDiagLogs();
            } else if (tab === 'tools') {
                if (!this.opcache)    this.fetchOpcache();
                if (!this.canaryInfo) this.fetchCanaryInfo();
            }
        },

        refresh() {
            if (this.isDemo) { this.loadDemoData(); return; }
            this.readiness      = null;
            this.metrics        = null;
            this.diagConfig     = null;
            this.opcache        = null;
            this.diagQueue      = null;
            this.diagPlugins    = null;
            this.diagLogs       = null;
            this.canaryInfo     = null;
            this.logFiles       = null;
            this.selectedLog    = '';
            this.generatedToken = null;
            this.deployHooks    = null;
            this.rateLimitStats = null;
            this.expandedLogEntries = {};
            Object.keys(this.errors).forEach((k) => { this.errors[k] = null; });
            this.fetchForTab(this.activeTab);
        },

        fetchReadiness() {
            this.loading.readiness = true;
            this.errors.readiness  = null;
            this._httpClient()
                .get('/_action/canary/diag/readiness', { headers: this._headers() })
                .then((r) => { this.readiness = r.data.data; })
                .catch((e) => { this.errors.readiness = e.message; })
                .finally(() => { this.loading.readiness = false; });
        },

        fetchMetrics() {
            this.loading.metrics = true;
            this.errors.metrics  = null;
            const url = '/_action/canary/diag/metrics?hours=' + this.metricsHours;
            this._httpClient()
                .get(url, { headers: this._headers() })
                .then((r) => { this.metrics = r.data.data; })
                .catch((e) => { this.errors.metrics = e.message; })
                .finally(() => { this.loading.metrics = false; });
        },

        fetchMetricsWithHours() {
            this.metrics = null;
            this.fetchMetrics();
        },

        fetchOpcache() {
            this.loading.opcache = true;
            this.errors.opcache  = null;
            this._httpClient()
                .get('/_action/canary/diag/opcache', { headers: this._headers() })
                .then((r) => { this.opcache = r.data.data; })
                .catch((e) => { this.errors.opcache = e.message; })
                .finally(() => { this.loading.opcache = false; });
        },

        fetchDiagConfig() {
            this.loading.config = true;
            this.errors.config  = null;
            this._httpClient()
                .get('/_action/canary/diag/config', { headers: this._headers() })
                .then((r) => { this.diagConfig = r.data.data; })
                .catch((e) => { this.errors.config = e.message; })
                .finally(() => { this.loading.config = false; });
        },

        fetchDiagQueue() {
            this.loading.queue = true;
            this.errors.queue  = null;
            this._httpClient()
                .get('/_action/canary/diag/queue', { headers: this._headers() })
                .then((r) => { this.diagQueue = r.data.data; })
                .catch((e) => { this.errors.queue = e.message; })
                .finally(() => { this.loading.queue = false; });
        },

        fetchDiagPlugins() {
            this.loading.plugins = true;
            this.errors.plugins  = null;
            this._httpClient()
                .get('/_action/canary/diag/plugins', { headers: this._headers() })
                .then((r) => { this.diagPlugins = r.data.data; })
                .catch((e) => { this.errors.plugins = e.message; })
                .finally(() => { this.loading.plugins = false; });
        },

        fetchDiagLogs() {
            this.loading.logs = true;
            this.errors.logs  = null;
            let url = '/_action/canary/diag/logs?lines=' + this.logLines;
            if (this.selectedLog) { url += '&file=' + encodeURIComponent(this.selectedLog); }
            this._httpClient()
                .get(url, { headers: this._headers() })
                .then((r) => { this.diagLogs = r.data.data; })
                .catch((e) => { this.errors.logs = e.message; })
                .finally(() => { this.loading.logs = false; });
        },

        fetchDiagLogsWithFile() {
            this.diagLogs = null;
            this.expandedLogEntries = {};
            this.fetchDiagLogs();
        },

        fetchDiagLogsWithLines() {
            this.diagLogs = null;
            this.expandedLogEntries = {};
            this.fetchDiagLogs();
        },

        fetchLogFiles() {
            this.loading.logFiles = true;
            this._httpClient()
                .get('/_action/canary/diag/log-files', { headers: this._headers() })
                .then((r) => { this.logFiles = r.data.data; })
                .catch(() => {})
                .finally(() => { this.loading.logFiles = false; });
        },

        fetchCanaryInfo() {
            this.loading.info = true;
            this.errors.info  = null;
            this._httpClient()
                .get('/_action/canary/info', { headers: this._headers() })
                .then((r) => { this.canaryInfo = r.data.data; })
                .catch((e) => { this.errors.info = e.message; })
                .finally(() => { this.loading.info = false; });
        },

        fetchDeployHooks() {
            this.loading.deployHooks = true;
            this.errors.deployHooks  = null;
            this._httpClient()
                .get('/_action/canary/deploy-hooks', { headers: this._headers() })
                .then((r) => { this.deployHooks = r.data.data; })
                .catch((e) => { this.errors.deployHooks = e.message; })
                .finally(() => { this.loading.deployHooks = false; });
        },

        fetchRateLimitStats() {
            this.loading.rateLimitStats = true;
            this.errors.rateLimitStats  = null;
            this._httpClient()
                .get('/_action/canary/rate-limit-stats', { headers: this._headers() })
                .then((r) => { this.rateLimitStats = r.data.data; })
                .catch((e) => { this.errors.rateLimitStats = e.message; })
                .finally(() => { this.loading.rateLimitStats = false; });
        },

        resetOpcache() {
            if (this.cacheResetPending) return;
            this.cacheResetPending = true;
            this.errors.cache = null;
            this._httpClient()
                .post('/_action/canary/cache/opcache/reset', {}, { headers: this._headers() })
                .then(() => {
                    this.opcache = null;
                    this.fetchOpcache();
                })
                .catch((e) => { this.errors.cache = e.message; })
                .finally(() => { this.cacheResetPending = false; });
        },

        clearShopwareCache() {
            if (this.cacheShopwarePending) return;
            this.cacheShopwarePending = true;
            this.errors.cache = null;
            this._httpClient()
                .post('/_action/canary/cache/shopware', {}, { headers: this._headers() })
                .catch((e) => { this.errors.cache = e.message; })
                .finally(() => { this.cacheShopwarePending = false; });
        },

        generateToken() {
            const bytes = new Uint8Array(32);
            crypto.getRandomValues(bytes);
            this.generatedToken = Array.from(bytes).map((b) => b.toString(16).padStart(2, '0')).join('');
        },

        copyEnvLine() {
            if (this.generatedEnvLine) {
                navigator.clipboard.writeText(this.generatedEnvLine).catch(() => {});
            }
        },

        copyUrl(url) {
            navigator.clipboard.writeText(url).catch(() => {});
        },

        checkVariant(status) {
            if (status === 'pass') return 'success';
            if (status === 'warn') return 'warning';
            return 'danger';
        },

        checkColor(status) {
            if (status === 'pass') return '#16a34a';
            if (status === 'warn') return '#d97706';
            return '#dc2626';
        },

        checkTextColor(status) {
            if (status === 'pass') return '#15803d';
            if (status === 'warn') return '#b45309';
            return '#b91c1c';
        },

        formatCheckName(name) {
            return (name || '').replace(/_/g, ' ');
        },

        logLevelVariant(level) {
            const l = (level || '').toLowerCase();
            if (['error', 'critical', 'emergency', 'alert'].some((x) => l.includes(x))) return 'danger';
            if (l.includes('warning')) return 'warning';
            if (l.includes('notice') || l.includes('info')) return 'info';
            return 'neutral';
        },

        isJsonEntry(entry) {
            return typeof entry === 'object' && entry !== null;
        },

        logEntryLevel(entry) {
            if (this.isJsonEntry(entry)) {
                return ((entry.level_name || String(entry.level) || 'INFO')).toUpperCase();
            }
            if (typeof entry === 'string') {
                // Monolog line format: [datetime] channel.LEVEL: message
                const m = entry.match(/\]\s+[\w.-]+\.(DEBUG|INFO|NOTICE|WARNING|ERROR|CRITICAL|ALERT|EMERGENCY)\s*:/i);
                if (m) return m[1].toUpperCase();
            }
            return 'INFO';
        },

        logEntryMessage(entry) {
            if (this.isJsonEntry(entry)) return entry.message || '';
            return typeof entry === 'string' ? entry.substring(0, 140) : '';
        },

        logEntryTimestamp(entry) {
            return this.isJsonEntry(entry) ? (entry.datetime || entry.timestamp || '') : '';
        },

        toggleLogEntry(idx) {
            this.expandedLogEntries = { ...this.expandedLogEntries, [idx]: !this.expandedLogEntries[idx] };
        },

        formatBytes(bytes) {
            if (!bytes) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB'];
            let i = 0;
            let v = bytes;
            while (v >= 1024 && i < 3) { v /= 1024; i++; }
            return v.toFixed(1) + ' ' + units[i];
        },

        formatCurrency(value) {
            if (value === null || value === undefined) return '—';
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'EUR', minimumFractionDigits: 2 }).format(value);
        },

        opcacheUsedPercent() {
            if (!this.opcache || !this.opcache.memory) return 0;
            const { used_bytes, free_bytes } = this.opcache.memory;
            const total = used_bytes + free_bytes;
            if (!total) return 0;
            return Math.round((used_bytes / total) * 100);
        },
    },
});
