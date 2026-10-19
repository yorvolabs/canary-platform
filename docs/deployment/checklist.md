# Deployment Checklist

Use this checklist before deploying YorvoLabs Canary to a production environment.

---

## Prerequisites

- [ ] PHP 8.2+ is installed on the target server
- [ ] Shopware 6.6+ is installed and configured
- [ ] `var/canary/rate-limit/` is writable by the PHP process
- [ ] `var/log/` is writable by the PHP process (for audit log)
- [ ] `APP_ENV=prod` is set in your environment

---

## Token generation

Generate a unique token for each scope you intend to use:

```bash
bin/console canary:token:generate monitoring
bin/console canary:token:generate cache
bin/console canary:token:generate diagnostics
bin/console canary:token:generate deploy
bin/console canary:token:generate admin    # optional — only if you need a supertoken
```

- [ ] All required tokens generated
- [ ] Tokens stored in your secret manager (AWS Secrets Manager, Vault, Doppler, etc.)
- [ ] Tokens **not** committed to version control

---

## Environment configuration

Set the following in your production environment (or via Shopware admin):

```dotenv
# Required: at minimum the scopes you will use
CANARY_TOKEN_MONITORING=<64-char hex>
CANARY_TOKEN_CACHE=<64-char hex>
CANARY_TOKEN_DIAGNOSTICS=<64-char hex>
CANARY_TOKEN_DEPLOY=<64-char hex>

# Recommended: restrict by IP
CANARY_ALLOWED_CIDRS=10.0.0.0/8,<monitoring-egress-cidr>

# Optional: change URL prefix
CANARY_ROUTE_PREFIX=canary

# Optional: tune rate limits
CANARY_RATE_LIMIT_HEALTH=60
CANARY_RATE_LIMIT_MUTATION=10

# Optional: tune deploy thresholds
CANARY_DEPLOY_QUEUE_THRESHOLD=100
CANARY_DEPLOY_FAILED_THRESHOLD=0
```

- [ ] Env vars set and verified
- [ ] `bin/console cache:clear` run after setting env vars

---

## Smoke tests

Run these manually or in your deploy pipeline after the first deployment:

```bash
export BASE=https://your-shop.com
export MONITORING_TOKEN=<your monitoring token>
export DEPLOY_TOKEN=<your deploy token>

# Liveness
curl -sf -H "Authorization: Bearer $MONITORING_TOKEN" $BASE/canary/health/live

# Readiness
curl -sf -H "Authorization: Bearer $MONITORING_TOKEN" $BASE/canary/health/ready

# Metrics (JSON)
curl -sf -H "Authorization: Bearer $MONITORING_TOKEN" "$BASE/canary/health/metrics?hours=1"

# Metrics (Prometheus)
curl -sf -H "Authorization: Bearer $MONITORING_TOKEN" \
         -H "Accept: text/plain" \
         "$BASE/canary/health/metrics"

# Pre-deploy check
curl -sf -H "Authorization: Bearer $DEPLOY_TOKEN" $BASE/canary/deploy/pre

# Verify a wrong token returns 403
curl -o /dev/null -w "%{http_code}" \
     -H "Authorization: Bearer wrongtoken00000000000000000000000000000000000000000000000000000000" \
     $BASE/canary/health/live
# Expected: 403
```

- [ ] All smoke tests pass
- [ ] Wrong token returns 403 (not 200 or 500)

---

## Monitoring integration

- [ ] Liveness probe configured in Kubernetes / Docker / load balancer health check
- [ ] Readiness probe configured
- [ ] Prometheus scrape job added (or metrics polling set up)
- [ ] Alerts defined: `health_check_*` metrics, `shop_queue_depth`, `shop_queue_failed`

---

## CI/CD integration

- [ ] Pre-deploy step calls `GET /deploy/pre` and aborts pipeline if `"pass": false`
- [ ] Post-deploy step calls `POST /deploy/post`
- [ ] Deploy token stored as a CI secret (not in repo)

See [CI/CD Integration](ci-cd.md) for pipeline examples.

---

## Security review

- [ ] `CANARY_ALLOWED_CIDRS` is set (or documented as intentionally open)
- [ ] Tokens are at least 64 characters
- [ ] `APP_ENV=prod` is set (enforces HTTPS)
- [ ] Canary endpoints are **not** publicly listed in robots.txt or sitemaps
- [ ] Audit log location (`var/log/canary_audit-*.log`) is not publicly accessible via the webserver

---

## Admin UI access

- [ ] The `canary:viewer` ACL privilege is assigned to the appropriate admin role(s)
- [ ] Admin UI reachable at **Settings → YorvoLabs Canary**
- [ ] Token status card shows all configured scopes as green

---

## Ongoing maintenance

- [ ] Token rotation procedure documented (generate → deploy → cache:clear → verify)
- [ ] Log retention policy confirmed (Monolog default: 30-day rotating files)
- [ ] Rate limit directory `var/canary/rate-limit/` included in deployment cleanup if files accumulate
