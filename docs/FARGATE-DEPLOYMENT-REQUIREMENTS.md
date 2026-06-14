# Fargate Deployment Requirements: Service Virtualization Platform

**Date:** 2026-03-31
**For:** Infra Team
**From:** QA Engineering
**Related:** [FARGATE-DEPLOYMENT-ANALYSIS.md](./FARGATE-DEPLOYMENT-ANALYSIS.md) (architectural analysis and trade-offs)

---

## 1. What This Application Is

The Service Virtualization Platform is a PHP web application that **replaces third-party APIs** (AiPrise KYC/KYB verification, Bridge banking, email providers) with deterministic simulations. AlfredPay's own services (penny-api, cms-backend, usa-bridge-integration) call this platform instead of calling real external APIs during testing.

It is **not** a simple HTTP stub/mock. It is stateful: it maintains KYC/KYB session lifecycles in a MySQL database and fires outbound HMAC-signed webhooks back to AlfredPay services when sessions complete. It also serves browser-facing HTML pages (KYC verification forms, Bridge Terms & Conditions).

**Primary consumers (phase 1):** CI/CD pipelines and ephemeral PR validation environments running in AWS.
**Secondary consumers (future):** Shared QA/staging environments.
**Not intended for:** Local developer machines (they continue using Docker Compose — see the analysis doc for why).

---

## 2. Domain Name

Orlando (infra) provisioned per-environment hostnames on Fargate:

| Environment | Hostname |
|---|---|
| Dev | `https://virtual-services-dev.alfredpay.io/` |
| Staging | `https://virtual-services-stg.alfredpay.io/` |

This follows AlfredPay's established `*-dev` / `*-stg` pattern (e.g., `penny-api-restricted-dev.alfredpay.io`, `cms-stg.alfredpay.io`). The original proposal in this doc was a single shared `virtual-services.alfredpay.io` treating the platform as environment-agnostic infrastructure; the team went the per-environment route instead, which keeps dev/stg data isolated and matches how every other service is deployed. Both hostnames are managed in Route 53.

### DNS

- **Zone:** `alfredpay.io` (Route 53)
- **Record type:** CNAME or A (alias) pointing to the ALB for each environment
- **SSL:** ACM certificate covering `virtual-services-dev.alfredpay.io` and `virtual-services-stg.alfredpay.io` (or a wildcard `*.alfredpay.io` if already in use)

---

## 3. Container Image

### 3.1 ECR Repository

Create a private ECR repository:

```
Repository name: alfredpay/service-virtualization
Image tag mutability: MUTABLE (allows :latest, but we also push :sha-<commit>)
Scan on push: Enabled
Lifecycle policy: Keep last 10 images
```

### 3.2 Dockerfile

The application already has a working `Dockerfile.local`. Below is the production-ready version that should be used for Fargate. Key differences from the local version: no debug mode, no dev dependencies, hardened Apache config.

```dockerfile
FROM php:8.2-apache

# Enable Apache mod_rewrite for .htaccess front-controller routing
RUN a2enmod rewrite

# Install system deps
RUN apt-get update && apt-get install -y unzip curl && rm -rf /var/lib/apt/lists/*

# Install MySQL PDO extension
RUN docker-php-ext-install pdo pdo_mysql

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy composer manifest and install deps (no dev packages)
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-interaction --no-cache --optimize-autoloader

# Copy application source
COPY . .

# Point Apache document root at public/
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Permissions
RUN chown -R www-data:www-data /var/www/html

# Entrypoint: generate .env, wait for MySQL, install schema, start Apache
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
```

### 3.3 Container Entrypoint Behavior

The existing `docker-entrypoint.sh` does the following on startup:

1. **Generates `.env` file** from environment variables (Symfony Dotenv expects this file)
2. **Waits for MySQL** — polls the database with a 60-second timeout, retrying every 2 seconds
3. **Runs schema installer** — `php bin/install-schema.php` creates 6 tables with `CREATE TABLE IF NOT EXISTS` (safe to run on every deploy)
4. **Starts background callback firer** — a `while true; sleep 5; php fire-callbacks.php` loop that processes outbound webhook deliveries
5. **Starts Apache** — `apache2-foreground`

No changes are needed to the entrypoint for Fargate. The MySQL wait loop handles RDS cold-start latency, and the `IF NOT EXISTS` in schema creation makes deploys idempotent.

### 3.4 Image Size

The image is small. The only runtime dependency beyond PHP built-ins is `symfony/dotenv` (~50 KB). No Node.js, no frontend build, no large frameworks. Expected image size: ~250-300 MB (mostly the PHP/Apache base image).

---

## 4. Fargate Task Definition

### 4.1 Resource Allocation

```
CPU:    256 (0.25 vCPU)
Memory: 512 MB
```

This is sufficient. The application uses PHP's process-per-request model (Apache prefork MPM), where each concurrent request spawns a ~20-30 MB process. At 512 MB, this comfortably handles 10-15 concurrent requests, which is well above what CI/CD pipelines will generate.

Scale up to `512 CPU / 1024 MB memory` only if you see OOM kills under load (unlikely at launch).

### 4.2 Container Definition

```json
{
  "name": "service-virtualization",
  "image": "<account-id>.dkr.ecr.us-east-1.amazonaws.com/alfredpay/service-virtualization:latest",
  "portMappings": [
    {
      "containerPort": 80,
      "protocol": "tcp"
    }
  ],
  "essential": true,
  "healthCheck": {
    "command": ["CMD-SHELL", "curl -f http://localhost/health || exit 1"],
    "interval": 30,
    "timeout": 5,
    "retries": 3,
    "startPeriod": 60
  },
  "logConfiguration": {
    "logDriver": "awslogs",
    "options": {
      "awslogs-group": "/ecs/service-virtualization",
      "awslogs-region": "us-east-1",
      "awslogs-stream-prefix": "ecs"
    }
  }
}
```

**Notes on `startPeriod: 60`:** The container waits for MySQL on startup (up to 60 seconds). During a fresh deploy or RDS restart, it may take 10-30 seconds before the health check passes. The 60-second start period prevents the task from being killed during this window.

### 4.3 Environment Variables

These must be set on the Fargate task definition (or pulled from Secrets Manager / Parameter Store):

| Variable | Required | Example Value | Description |
|----------|:--------:|---------------|-------------|
| `DB_HOST` | Yes | `service-virt.cluster-xxx.us-east-1.rds.amazonaws.com` | RDS MySQL endpoint |
| `DB_PORT` | Yes | `3306` | MySQL port |
| `DB_NAME` | Yes | `service_virtualization` | Database name |
| `DB_USER` | Yes | `svc_virt_app` | Database user |
| `DB_PASS` | Yes | *(from Secrets Manager)* | Database password |
| `APP_ENV` | Yes | `production` | Environment identifier |
| `APP_DEBUG` | Yes | `false` | **Must be `false`** — `true` exposes stack traces with file paths |
| `APP_SECRET` | Yes | *(random 32-char string)* | Used for internal operations |
| `APP_BASE_URL` | Yes | `https://virtual-services-dev.alfredpay.io` (dev) / `https://virtual-services-stg.alfredpay.io` (stg) | External URL of this service, set per environment (used in verification URLs, T&C page URLs) |
| `APP_INTERNAL_URL` | Yes | `http://localhost` | Internal self-callback URL (localhost works — Apache listens on port 80 inside the container) |
| `AIPRISE_HMAC_KEY` | Yes | *(from Secrets Manager)* | HMAC key for signing AiPrise webhooks (must match what penny-api-restricted expects) |
| `AIPRISE_AUTO_DELAY` | No | `10` | Seconds before auto-completing KYC sessions (default: 10) |
| `AIPRISE_CALLBACK_REWRITE_HOST` | No | *(leave empty)* | Only needed in Docker Compose; on Fargate, consuming services pass correct callback URLs directly |
| `LOG_LEVEL` | No | `info` | Logging verbosity (default: `info`) |

**Secrets (use Secrets Manager or Parameter Store, not plaintext):**
- `DB_PASS`
- `APP_SECRET`
- `AIPRISE_HMAC_KEY`

### 4.4 Task Execution Role

The task execution role needs:
- `ecr:GetAuthorizationToken` + `ecr:BatchGetImage` (pull image from ECR)
- `logs:CreateLogStream` + `logs:PutLogEvents` (CloudWatch Logs)
- `secretsmanager:GetSecretValue` (if using Secrets Manager for DB_PASS, etc.)

### 4.5 Task Role

The application **does not** call any AWS services at runtime. It only speaks HTTP (to consumers and MySQL). The task role can be minimal — no S3, no SQS, no DynamoDB, no other AWS SDK calls.

If you add CloudWatch custom metrics in the future, the task role would need `cloudwatch:PutMetricData`.

---

## 5. Networking

### 5.1 VPC and Subnets

Deploy the Fargate task in **private subnets** with a NAT Gateway for outbound internet access (needed for firing webhooks to services that may be outside the VPC).

The task needs to be reachable by CI/CD pipeline runners. If runners are in the same VPC, use private subnets. If runners are external (e.g., GitLab SaaS, GitHub Actions hosted runners), the ALB must be internet-facing or the runners need VPN/PrivateLink access.

### 5.2 Security Groups

**Fargate Task Security Group (`sg-service-virt-task`):**

| Direction | Port | Source / Destination | Purpose |
|-----------|------|---------------------|---------|
| Inbound | 80 | ALB security group | HTTP from load balancer |
| Outbound | 3306 | RDS security group | MySQL connection |
| Outbound | 443 | 0.0.0.0/0 | Outbound HTTPS for webhook callbacks to AlfredPay services |
| Outbound | 80 | 0.0.0.0/0 | Outbound HTTP for webhook callbacks (some internal services may use HTTP) |

**ALB Security Group (`sg-service-virt-alb`):**

| Direction | Port | Source / Destination | Purpose |
|-----------|------|---------------------|---------|
| Inbound | 443 | VPC CIDR (or specific runner subnets) | HTTPS from consumers |
| Inbound | 80 | VPC CIDR | HTTP (redirect to HTTPS) |
| Outbound | 80 | Fargate task security group | Forward to target |

**RDS Security Group (`sg-service-virt-db`):**

| Direction | Port | Source / Destination | Purpose |
|-----------|------|---------------------|---------|
| Inbound | 3306 | Fargate task security group | MySQL from app |

**Regarding webhook callbacks (outbound):** The platform fires HTTP POST requests to AlfredPay's own services (penny-api-restricted, etc.) as webhook callbacks. In CI/CD usage, these target services will be running inside the VPC (as ECS tasks, EC2 instances, or within the pipeline runner's network). The outbound rules above allow this. If all callback targets are internal, you can restrict outbound to VPC CIDR only instead of `0.0.0.0/0`.

### 5.3 ALB (Application Load Balancer)

| Setting | Value |
|---------|-------|
| Scheme | Internal (if all consumers are in VPC) or Internet-facing (if external CI runners need access) |
| Listener | HTTPS :443, redirect HTTP :80 -> HTTPS :443 |
| Certificate | ACM cert for `virtual-services-dev.alfredpay.io` / `virtual-services-stg.alfredpay.io` (one ALB per env, or a single ALB with SNI) |
| Target group protocol | HTTP (port 80) |
| Health check path | `/health` |
| Health check interval | 30 seconds |
| Healthy threshold | 2 |
| Unhealthy threshold | 3 |
| Health check timeout | 5 seconds |
| Deregistration delay | 30 seconds |
| Stickiness | Not needed (app is stateless, all state is in MySQL) |

### 5.4 Service Discovery (Optional)

If internal services need to reach the virtual service by hostname within the VPC (without going through the ALB), register it in Cloud Map:

```
Namespace: alfredpay.internal  (or whatever internal namespace is in use)
Service:   virtual-services
Record:    virtual-services.alfredpay.internal → task IP (SRV or A record)
```

This is optional. The ALB hostname works for most use cases.

---

## 6. Database (RDS MySQL)

### 6.1 Instance Specification

| Setting | Value |
|---------|-------|
| Engine | MySQL 8.0 |
| Instance class | `db.t4g.micro` (2 vCPU, 1 GB RAM — sufficient for launch) |
| Storage | 20 GB gp3 (the database stores test session data, not production data; rows are cleaned up after 2 hours) |
| Multi-AZ | No (this is test infrastructure, not production data) |
| Backup retention | 1 day (data is ephemeral by design; full loss is acceptable) |
| Encryption | Yes (default KMS key is fine) |
| Public access | No (private subnets only) |
| Parameter group | Default, unless `max_connections` needs increasing (default 66 on t4g.micro; raise to 100 if needed) |

### 6.2 Database and User Setup

The application's schema installer (`bin/install-schema.php`) handles table creation automatically on container startup. It runs `CREATE DATABASE IF NOT EXISTS` and `CREATE TABLE IF NOT EXISTS`, so it's safe across restarts and deploys.

What the infra team needs to provision:

```sql
-- Create the database
CREATE DATABASE service_virtualization
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- Create the application user
CREATE USER 'svc_virt_app'@'%' IDENTIFIED BY '<generated-password>';

-- Grant privileges (the app needs full DDL+DML on its database)
GRANT ALL PRIVILEGES ON service_virtualization.* TO 'svc_virt_app'@'%';
FLUSH PRIVILEGES;
```

The `ALL PRIVILEGES` grant is needed because the entrypoint runs `CREATE TABLE IF NOT EXISTS` on startup. If you want to restrict this, grant `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP` on the `service_virtualization` database only.

### 6.3 Schema (6 Tables)

The application creates these tables automatically. This is for reference:

| Table | Purpose | Grows? |
|-------|---------|--------|
| `scenarios` | Test scenario registry (namespace, config, expiry) | Slow — cleaned up after 2 hours |
| `entities` | Stateful objects (KYC sessions, customers) scoped by namespace | Moderate — cleaned up with scenarios |
| `state_history` | Audit trail of entity state transitions | Moderate — cascade-deleted with entities |
| `pending_callbacks` | Outbound webhook queue (schedule, fire, retry) | Moderate — status changes to `fired`/`failed` |
| `callback_history` | Log of all fired webhooks (response codes, duration) | Grows — needs periodic cleanup |
| `request_log` | Every inbound API request | Grows fastest — needs periodic cleanup |

**Cleanup:** The platform has a built-in cleanup endpoint (`POST /control/cleanup-expired`) that deletes all data for expired scenarios (default 2-hour TTL). This should be called periodically. See section 9 for the scheduled task.

### 6.4 RDS Connectivity from Fargate

The Fargate task connects to RDS via standard MySQL (TCP 3306). No special drivers, IAM auth, or RDS Proxy needed at launch. The PDO connection string is built from environment variables:

```
mysql:host={DB_HOST};port={DB_PORT};dbname={DB_NAME};charset=utf8mb4
```

If you see connection exhaustion under heavy load (unlikely at launch), add RDS Proxy later. The application opens one PDO connection per HTTP request and closes it when the request ends — there is no connection pooling at the app level.

---

## 7. ECS Service Configuration

### 7.1 Service Definition

| Setting | Value |
|---------|-------|
| Launch type | FARGATE |
| Platform version | LATEST |
| Desired count | 1 |
| Min healthy percent | 100 |
| Max percent | 200 |
| Deployment circuit breaker | Enabled (with rollback) |
| Task placement | Spread across AZs |

One task is sufficient. The platform handles negligible traffic (CI/CD pipelines, not user-facing production traffic). If you need scaling later, set up ECS Service Auto Scaling on CPU utilization > 70%.

### 7.2 Deployment Strategy

Rolling update (default). The schema installer is idempotent (`CREATE TABLE IF NOT EXISTS`), so two tasks can run simultaneously during deploys without conflict.

Zero-downtime deploys work naturally because:
- No in-memory state — all state is in MySQL
- Health check at `/health` validates DB connectivity before the ALB sends traffic
- The ALB drains connections from the old task (30-second deregistration delay)

---

## 8. Logging and Monitoring

### 8.1 CloudWatch Logs

```
Log group: /ecs/service-virtualization
Retention: 14 days
```

The application writes structured log output to stderr (Apache error log), which Fargate captures automatically. Key log events to watch for:

- `[entrypoint] MySQL ready. Installing schema...` — healthy startup
- `[entrypoint] ERROR: MySQL not ready after 60s` — RDS unreachable
- `KYB APPROVED: Business #...` — KYB approval actions (control plane)
- PHP errors/warnings — application issues

### 8.2 CloudWatch Alarms (Recommended)

| Alarm | Metric | Threshold | Purpose |
|-------|--------|-----------|---------|
| Unhealthy task | ALB `UnHealthyHostCount` | >= 1 for 5 minutes | Task is down or DB unreachable |
| High error rate | ALB `HTTPCode_Target_5XX_Count` | > 10 in 5 minutes | Application errors |
| Task CPU | ECS `CPUUtilization` | > 80% for 10 minutes | Resource pressure (scale up) |
| DB connections | RDS `DatabaseConnections` | > 50 (on t4g.micro max 66) | Connection exhaustion risk |

### 8.3 Health Check Details

The `/health` endpoint verifies both the application and database:

```json
// GET /health — HTTP 200
{
  "error": false,
  "data": {
    "status": "healthy",
    "db": "connected"
  }
}
```

If MySQL is unreachable, the response still returns HTTP 200 but with `"db": "error: ..."`. You may want to configure the ALB health check to use a stricter endpoint that returns HTTP 503 when the DB is down. This can be added to the application.

---

## 9. Scheduled Task: Expired Scenario Cleanup

Test scenarios auto-expire after 2 hours, but their data stays in the database until explicitly cleaned up. Set up a scheduled ECS task (or EventBridge rule) to call the cleanup endpoint periodically.

### Option A: EventBridge Scheduled Rule (Simpler)

Create an EventBridge rule that invokes a Lambda function every hour, which calls:

```
POST https://virtual-services-dev.alfredpay.io/control/cleanup-expired
POST https://virtual-services-stg.alfredpay.io/control/cleanup-expired
```

### Option B: Scheduled ECS Task (Self-Contained)

Run a lightweight Fargate task on a schedule:

```
Schedule: rate(1 hour)
Command override: ["php", "bin/fire-callbacks.php"]  # Fires due callbacks
```

And a second schedule:

```
Schedule: rate(1 hour)
Command: curl -s -X POST http://localhost/control/cleanup-expired
```

Option A is simpler and recommended. The cleanup endpoint is fast (one query to find expired namespaces, cascading deletes).

---

## 10. CI/CD Pipeline for the Platform Itself

This section covers how to build and deploy the service-virtualization platform itself (not the AlfredPay services that consume it).

### 10.1 Build Pipeline

```
Trigger: Push to main branch (or a deploy branch)
Steps:
  1. Checkout code
  2. Build Docker image: docker build -t service-virtualization:$COMMIT_SHA .
  3. Tag: docker tag service-virtualization:$COMMIT_SHA <ecr-repo>:latest
  4. Tag: docker tag service-virtualization:$COMMIT_SHA <ecr-repo>:$COMMIT_SHA
  5. Push both tags to ECR
  6. Update ECS service to force new deployment:
     aws ecs update-service --cluster <cluster> --service service-virtualization --force-new-deployment
```

### 10.2 Source Repository

A dedicated GitLab repo was provisioned by Orlando (infra):

```
https://gitlab.alfredpay.app/quality-assurance/virtual-services
```

The repo's GitLab display name is `holodeck`; the URL path is still `virtual-services` (renaming the path requires Owner on the `quality-assurance` group).

Local working copy currently lives at:

```
Alfred_Repos/Test_Automation_Infrastructure/service-virtualization/
```

This directory will be migrated into the GitLab repo above. Until then, code changes happen locally and get pushed to the GitLab remote when ready.

---

## 11. Pre-Deployment Code Changes

These changes are needed in the application before the Fargate deployment works correctly. QA Engineering will make these changes:

| Change | File | What | Status |
|--------|------|------|--------|
| Make penny-api URL configurable | `public/index.php` lines 143, 175 | Replace hardcoded `http://penny-api:3003` with `$_ENV['PENNY_API_URL']` | To do |
| Restrict CORS | `public/index.php` line 59 | Replace `Access-Control-Allow-Origin: *` with env-based allowlist | To do |
| Add control plane auth | `public/index.php` (new middleware) | API key validation on all `/control/*` routes | To do |
| Validate callback URLs | `src/Callback/CallbackScheduler.php` | Allowlist check before scheduling callbacks to prevent SSRF | To do |
| Production Dockerfile | `Dockerfile` (new file) | Fargate-ready Dockerfile (based on `Dockerfile.local`) | To do |

These are small changes. The infra work (ECR, Fargate, RDS, ALB, DNS) can proceed in parallel.

---

## 12. Summary Checklist

### Infra Team Provisions

- [ ] **ECR repository:** `alfredpay/service-virtualization`
- [ ] **RDS MySQL instance:** `db.t4g.micro`, MySQL 8.0, private subnets, 20 GB gp3
- [ ] **Database + user:** `service_virtualization` database, `svc_virt_app` user with full privileges
- [ ] **Secrets Manager:** Store `DB_PASS`, `APP_SECRET`, `AIPRISE_HMAC_KEY`
- [ ] **Fargate cluster:** Use existing cluster or create `test-infrastructure` cluster
- [ ] **Task definition:** 256 CPU, 512 MB, container port 80, env vars per section 4.3
- [ ] **ECS service:** 1 desired task, rolling update, deployment circuit breaker
- [ ] **ALB:** Internal (or internet-facing if needed), HTTPS listener, ACM cert
- [ ] **Target group:** HTTP port 80, health check on `/health`
- [ ] **Security groups:** Task, ALB, and RDS groups per section 5.2
- [ ] **DNS:** `virtual-services-dev.alfredpay.io` and `virtual-services-stg.alfredpay.io` CNAMEs -> ALB(s) (done by Orlando)
- [ ] **ACM certificate:** Covering both `virtual-services-dev.alfredpay.io` and `virtual-services-stg.alfredpay.io`
- [ ] **CloudWatch log group:** `/ecs/service-virtualization`, 14-day retention
- [ ] **Scheduled cleanup:** EventBridge rule calling `POST /control/cleanup-expired` hourly
- [ ] **CloudWatch alarms:** Unhealthy host, 5xx rate, CPU, DB connections

### QA Engineering Delivers

- [ ] Production `Dockerfile`
- [ ] Code changes (configurable URLs, auth, CORS, SSRF protection)
- [ ] Updated `docker-entrypoint.sh` if needed
- [ ] Smoke test script for post-deploy validation

### Validation After Deploy

```bash
# 1. Health check
curl https://virtual-services-dev.alfredpay.io/health
# Expected: {"error":false,"data":{"status":"healthy","db":"connected"}}

# 2. Seed a test scenario
curl -X POST https://virtual-services-dev.alfredpay.io/control/scenarios \
  -H "Content-Type: application/json" \
  -H "X-API-KEY: <control-plane-api-key>" \
  -d '{"namespace":"deploy-smoke-test","domain":"compliance","name":"smoke"}'
# Expected: 200, namespace created

# 3. Inspect it
curl https://virtual-services-dev.alfredpay.io/control/scenarios/deploy-smoke-test \
  -H "X-API-KEY: <control-plane-api-key>"
# Expected: 200, scenario details

# 4. Clean up
curl -X DELETE https://virtual-services-dev.alfredpay.io/control/scenarios/deploy-smoke-test \
  -H "X-API-KEY: <control-plane-api-key>"
# Expected: 200, namespace reset

# 5. Verify browser-facing page
# Open in browser: https://virtual-services-dev.alfredpay.io/bridge/tos-page?virtual=true
# Expected: Bridge Terms & Conditions HTML page renders
```
