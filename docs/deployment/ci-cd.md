# CI/CD Integration

This page shows how to integrate Canary's deploy endpoints into common CI/CD pipelines. The key pattern is always the same:

1. **Before deploy:** `GET /deploy/pre` — abort if `"pass": false`
2. **Deploy your application** (rsync, Docker image push, etc.)
3. **After deploy:** `POST /deploy/post` — runs OPcache reset, cache clear, readiness check, and any custom POST hooks

---

## Environment variables to set as CI secrets

| Secret | Value |
|---|---|
| `CANARY_DEPLOY_TOKEN` | Your `DEPLOY` scope token |
| `CANARY_BASE_URL` | `https://your-shop.com` |

---

## GitHub Actions

```yaml
# .github/workflows/deploy.yml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: production

    steps:
      - name: Check out code
        uses: actions/checkout@v4

      - name: Pre-deploy check
        run: |
          result=$(curl -sf \
            -H "Authorization: Bearer ${{ secrets.CANARY_DEPLOY_TOKEN }}" \
            "${{ secrets.CANARY_BASE_URL }}/canary/deploy/pre")
          echo "$result" | jq .
          if [ "$(echo "$result" | jq -r '.data.pass')" != "true" ]; then
            echo "Pre-deploy check failed — aborting"
            exit 1
          fi

      - name: Deploy application
        run: |
          # your deployment steps here
          # rsync, docker push, etc.

      - name: Post-deploy verification
        run: |
          result=$(curl -sf \
            -X POST \
            -H "Authorization: Bearer ${{ secrets.CANARY_DEPLOY_TOKEN }}" \
            "${{ secrets.CANARY_BASE_URL }}/canary/deploy/post")
          echo "$result" | jq .
          if [ "$(echo "$result" | jq -r '.data.pass')" != "true" ]; then
            echo "Post-deploy verification failed"
            exit 1
          fi
```

---

## GitLab CI

```yaml
# .gitlab-ci.yml
stages:
  - pre-deploy
  - deploy
  - post-deploy

variables:
  CANARY_URL: "${CANARY_BASE_URL}/canary"

pre_deploy_check:
  stage: pre-deploy
  script:
    - |
      result=$(curl -sf \
        -H "Authorization: Bearer $CANARY_DEPLOY_TOKEN" \
        "${CANARY_URL}/deploy/pre")
      echo "$result" | python3 -m json.tool
      pass=$(echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['pass'])")
      if [ "$pass" != "True" ]; then
        echo "Pre-deploy check failed"
        exit 1
      fi
  environment:
    name: production

deploy:
  stage: deploy
  script:
    - echo "Deploy your application here"
  environment:
    name: production
  needs: [pre_deploy_check]

post_deploy_check:
  stage: post-deploy
  script:
    - |
      result=$(curl -sf \
        -X POST \
        -H "Authorization: Bearer $CANARY_DEPLOY_TOKEN" \
        "${CANARY_URL}/deploy/post")
      echo "$result" | python3 -m json.tool
      pass=$(echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['pass'])")
      if [ "$pass" != "True" ]; then
        echo "Post-deploy verification failed"
        exit 1
      fi
  environment:
    name: production
  needs: [deploy]
```

---

## Bitbucket Pipelines

```yaml
# bitbucket-pipelines.yml
pipelines:
  branches:
    main:
      - step:
          name: Pre-deploy check
          script:
            - |
              result=$(curl -sf \
                -H "Authorization: Bearer $CANARY_DEPLOY_TOKEN" \
                "$CANARY_BASE_URL/canary/deploy/pre")
              pass=$(echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['pass'])")
              [ "$pass" = "True" ] || (echo "Pre-deploy failed"; exit 1)

      - step:
          name: Deploy
          script:
            - echo "Your deploy commands"

      - step:
          name: Post-deploy
          script:
            - |
              result=$(curl -sf -X POST \
                -H "Authorization: Bearer $CANARY_DEPLOY_TOKEN" \
                "$CANARY_BASE_URL/canary/deploy/post")
              pass=$(echo "$result" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['pass'])")
              [ "$pass" = "True" ] || (echo "Post-deploy failed"; exit 1)
```

---

## Maintenance window pattern

For deploys that require a maintenance window:

```bash
#!/usr/bin/env bash
set -euo pipefail

BASE="$CANARY_BASE_URL/canary"
AUTH="Authorization: Bearer $CANARY_DEPLOY_TOKEN"

# 1. Check readiness
pre=$(curl -sf -H "$AUTH" "$BASE/deploy/pre")
[ "$(echo "$pre" | jq -r '.data.pass')" = "true" ] || { echo "Pre-deploy failed"; exit 1; }

# 2. Enable maintenance mode
curl -sf -X PUT -H "$AUTH" -H "Content-Type: application/json" \
  -d '{"reason":"Scheduled release"}' \
  "$BASE/deploy/maintenance"

# 3. Deploy
# ... your deploy commands ...

# 4. Post-deploy verification
post=$(curl -sf -X POST -H "$AUTH" "$BASE/deploy/post")
echo "$post" | jq .

# 5. Disable maintenance (runs whether post-deploy passed or not)
curl -sf -X DELETE -H "$AUTH" "$BASE/deploy/maintenance"

# 6. Fail the pipeline if post-deploy had issues
[ "$(echo "$post" | jq -r '.data.pass')" = "true" ] || exit 1
```

---

## Kubernetes rolling deploy with health probes

When using Kubernetes, configure the Canary health endpoints directly as probes. Kubernetes will automatically delay routing traffic to a new pod until the readiness probe passes.

```yaml
# deployment.yaml (relevant excerpt)
spec:
  template:
    spec:
      containers:
        - name: shopware
          livenessProbe:
            httpGet:
              path: /canary/health/live
              port: 8080
              httpHeaders:
                - name: Authorization
                  value: Bearer $(CANARY_TOKEN_MONITORING)
            initialDelaySeconds: 10
            periodSeconds: 15
            failureThreshold: 3

          readinessProbe:
            httpGet:
              path: /canary/health/ready
              port: 8080
              httpHeaders:
                - name: Authorization
                  value: Bearer $(CANARY_TOKEN_MONITORING)
            initialDelaySeconds: 5
            periodSeconds: 10
            failureThreshold: 3
```

With this setup:
- `livenessProbe` restarts pods that are stuck (PHP-FPM deadlock, OOM kill, etc.)
- `readinessProbe` prevents new pods from receiving traffic until all health checks pass
- Rolling deploys drain the old pod only after the new pod is ready

The deploy token calls (`/deploy/pre`, `/deploy/post`) should still be made from your CI pipeline, not from the pod itself.
