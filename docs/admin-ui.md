# Admin UI

The Shopware bridge includes a full Vue.js admin module accessible at **Settings → YorvoLabs Canary** in the Shopware administration panel.

Access requires the `canary:viewer` ACL privilege. Assign this privilege to any admin role that should be able to view the dashboard.

The admin UI communicates with the Canary API through a proxy controller (`/api/_action/canary/*`) protected by Shopware's own JWT authentication. Canary bearer tokens are **not** required for admin UI access — the proxy calls the underlying action methods directly.

---

## Tabs

### Overview

The main dashboard. Refreshes on demand via the reload button.

**Health Summary** — Hero card with the overall health status (`PASS` / `WARN` / `FAIL`) and a count of passing vs. failing checks.

**Readiness Checks** — Expandable list of every registered health check with status, duration in milliseconds, and any error message.

**Commerce Pulse** — Business KPIs for the selected time window. Use the time selector (top-right of the card) to choose `1h`, `6h`, `24h`, or `7d`.

| Metric | Description |
|---|---|
| Orders | New orders placed in the window |
| Revenue | Sum of order `amountTotal` in the window |
| New Customers | Customer registrations in the window |
| Active Carts | Carts created or updated in the window |
| Newsletter | New confirmed opt-in sign-ups in the window |
| Products | Total main products (no time filter) |
| Queue Depth | Pending async messages (no time filter) |

**OPcache** — Hit rate gauge, memory usage bar, cached script count.

**Configuration Validation** — Results of built-in diagnostic checks (APP_ENV, MESSENGER_TRANSPORT_DSN, MAILER_DSN, etc.).

**Deploy Hooks** — List of registered pre/post-deploy hooks with their phase.

---

### Queue

Real-time queue health.

- **Queue Depth** — Per-queue depth with the age of the oldest message. Green / orange / red thresholds based on `CANARY_DEPLOY_QUEUE_THRESHOLD`.
- **Failed Messages** — Dead-letter count and timestamp of the oldest failure.
- **Worker Detection** — Binary status showing whether a worker process is detected via `/proc` (Linux only).

---

### Plugins

Table of all installed Shopware plugins:

| Column | Description |
|---|---|
| Name | Technical plugin name |
| Label | Human-readable name |
| Version | Currently installed version |
| Status | Active / inactive |
| Installed | Installation timestamp |
| Update available | Shows the available version if an upgrade exists |

---

### Logs

Live log tail with PII masking (email addresses → `[EMAIL]`, IPv4 addresses → `[IP]`).

- **Log file selector** — Dropdown listing all `.log` files in the log directory, sorted by modification time.
- **Level filter** — Show only entries at or above a severity level: `debug`, `info`, `notice`, `warning`, `error`, `critical`.
- **Lines** — Adjustable; defaults to 50, maximum 500.
- **Format detection** — Automatically detects Monolog JSON format (expandable entries with context) vs. plain-text logs.
- **Expandable entries** — Click any JSON entry to expand the full context object.

---

### Tools

Operational actions and token management.

**Cache Actions:**

| Button | Action |
|---|---|
| Reset OPcache | Calls `POST /cache/opcache/reset?confirm=true` |
| Clear Object Cache | Calls `POST /cache/shopware/object` |
| Clear All Caches | Calls `POST /cache/shopware` |

**Token Status** — Table showing which scopes have tokens configured (does not reveal token values).

**Rate Limit Stats** — Active sliding-window buckets and total hit counts in the last minute.

**Token Generator** — Generates random 64-byte tokens client-side (in the browser) for each scope. Copy the generated value and store it in your secret manager or env var.

**External Endpoints** — Copy-ready `curl` commands for every public endpoint with the correct method, URL, and required scope label.

---

## Demo mode

Append `?demo=1` to the admin module URL to load static fixture data. Use this for design reviews or screenshots without a live Shopware instance.

---

## Internationalization

The admin module ships English (`en-GB`) and German (`de-DE`) translations. Additional locales can be added by contributing snippet files under:

```
Resources/app/administration/src/module/yorvolabs-canary/snippet/
```
