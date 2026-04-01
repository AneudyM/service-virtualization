# Fargate Deployment Analysis: Service Virtualization Platform

**Date:** 2026-03-30
**Author:** QA/Infra collaboration
**Status:** Analysis — not yet a deployment plan

---

## 1. Context and Goal

The infra team has asked whether the AlfredPay Service Virtualization Platform can be deployed to AWS Fargate as a **centralized, shared service** that serves:

- **Local development** — developers point their local stack at the central instance instead of running the container locally
- **PR/MR validation** — ephemeral CI environments use it during merge request pipelines
- **CI/CD test automation** — automated test suites hit it as part of build/deploy pipelines

This document analyzes the platform's architecture against these requirements, identifies what works, what breaks, and what changes would be needed.

---

## 2. What the Platform Actually Does

The service virtualization platform replaces external third-party APIs (AiPrise KYC/KYB, Bridge, email providers) so that AlfredPay's own services can run end-to-end without depending on real external services.

It is **not** a simple request-response mock server. It is a **stateful simulation engine** with:

1. **Stateful entities** — KYC/KYB sessions have lifecycle states (created -> processing -> completed) stored in MySQL
2. **Scheduled webhook callbacks** — after creating a verification session, the platform fires HMAC-signed webhooks back to AlfredPay services (e.g., penny-api-restricted) after a configurable delay
3. **Self-callbacks** — the platform schedules internal HTTP calls to itself to trigger auto-completion workflows (e.g., auto-approve a KYC session after 10 seconds)
4. **Browser-facing pages** — verification pages (`/verify`) and Bridge Terms & Conditions pages (`/bridge/tos-page`) that real users/testers interact with in a browser
5. **A control plane** — endpoints for tests to seed scenarios, inspect state, fire callbacks on demand, and tear down test data

### Current Deployment Modes

| Mode | Where it runs | Database | Callback delivery |
|------|--------------|----------|-------------------|
| **Docker Compose (local)** | Developer's machine, alongside penny-api, cms-backend, etc. | MySQL container (`virtual-db`) on same Docker network | Background loop in container (`fire-callbacks.php` every 5s) |
| **TigerTech shared hosting** | Remote shared PHP host | Remote MySQL on same host | Cron job every 5 minutes |

---

## 3. Can It Run on Fargate? (Technical Feasibility)

**Yes.** The container is straightforward to deploy on Fargate:

- **Standard Docker image** — `php:8.2-apache` base, single `EXPOSE 80`, no exotic runtime requirements
- **Stateless process** — all state is in MySQL, no local filesystem writes, no in-memory session state
- **Health check ready** — `GET /health` endpoint already exists and verifies DB connectivity
- **Environment-configurable** — all connection strings and URLs are environment variables (injected via `docker-entrypoint.sh`)

The infrastructure would look like:

```
                     ┌──────────────────────────┐
   Consumers ──────> │   ALB (HTTPS)            │
                     │   virtual-services.alf... │
                     └──────────┬───────────────┘
                                │
                     ┌──────────▼───────────────┐
                     │   Fargate Service         │
                     │   service-virtualization  │
                     │   (PHP 8.2 + Apache)      │
                     └──────────┬───────────────┘
                                │
                     ┌──────────▼───────────────┐
                     │   RDS MySQL               │
                     │   (or Aurora Serverless)   │
                     └───────────────────────────┘
```

The background callback firer (`fire-callbacks.php` loop in `docker-entrypoint.sh`) would continue to work inside the Fargate task. Alternatively, it could be moved to a scheduled ECS task or EventBridge rule for better observability.

**Technical feasibility is not the issue. The issue is whether it makes sense for the intended use cases.**

---

## 4. The Webhook Callback Problem

This is the central architectural concern. The platform doesn't just *receive* requests — it also *initiates* outbound HTTP calls back to the services that called it.

### 4.1 How Callbacks Work Today

When penny-api creates a KYC session via `POST /api/v1/verify/get_user_verification_url`, it passes a `callback_url` — the URL where it wants to receive the webhook when verification completes. In production, this points to a real AiPrise URL. In the local stack, penny-api's config still references the production URL (e.g., `https://penny-api-restricted-dev.alfredpay.io/api/v1/.../webhook-url-session`), but the platform rewrites it using the `AIPRISE_CALLBACK_REWRITE_HOST` environment variable:

```
Original callback_url from penny-api:
  https://penny-api-restricted-dev.alfredpay.io/api/v1/.../webhook-url-session

Rewritten to (in Docker Compose):
  http://penny-api-restricted-local:3002/api/v1/.../webhook-url-session
```

This rewriting works because both containers are on the same Docker network. The platform can resolve `penny-api-restricted-local` to the container's internal IP.

The platform also makes self-callbacks — it schedules an HTTP POST to its own `/api/v1/verify/_internal/auto-complete/{sessionId}` endpoint to trigger auto-completion after a delay. This uses `APP_INTERNAL_URL` (defaulting to `http://localhost` inside the container).

### 4.2 What Breaks with a Centralized Deployment

Here is a concrete example. Developer Alice is running penny-api locally on her laptop:

```
Alice's laptop:
  penny-api:           localhost:3003
  penny-api-restricted: localhost:3002

Fargate (centralized):
  service-virtualization: https://virtual-services.alfredpay.internal
```

1. Alice's penny-api calls `POST https://virtual-services.alfredpay.internal/api/v1/verify/get_user_verification_url` with `callback_url: https://penny-api-restricted-dev.alfredpay.io/api/v1/.../webhook-url-session`

2. The platform creates the session. Now it needs to fire a webhook back to Alice's penny-api-restricted — but what host?

   - The original `callback_url` points to the dev/staging server, not Alice's laptop
   - `AIPRISE_CALLBACK_REWRITE_HOST` is a single global value — it can't point to each developer's machine simultaneously
   - Even if Alice passed `http://localhost:3002` as the callback URL, **Fargate can't reach localhost on Alice's laptop** — that resolves to the Fargate container itself

3. **Result: the webhook never arrives. The KYC flow hangs at "processing" forever.**

This applies to every flow that depends on webhooks:

| Flow | Requires outbound webhook? | Breaks on centralized? |
|------|---------------------------|----------------------|
| KYC individual verification (AiPrise) | Yes — auto-completion fires webhook to penny-api-restricted | Yes |
| KYB business verification (AiPrise) | Yes — same pattern, fires to penny-api-restricted | Yes |
| Compliance session auto-transition | Yes — fires callback to `callback_url` on state change | Yes |
| Bridge T&C page | No — browser-facing, request/response only | No |
| Bridge `POST /customers/tos_links` | No — returns URL, no callback | No |
| Email OTP | No — logs OTP to container output | No |
| Control plane (seed/reset/inspect) | No — request/response only | No |

**Roughly half the platform's value comes from the webhook-based flows.**

### 4.3 What About Self-Callbacks?

The platform also fires HTTP requests to itself for auto-completion:

```php
// AipriseService.php line 82-83
$internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';
$selfUrl = "{$internalUrl}/api/v1/verify/_internal/auto-complete/{$sessionId}";
```

On Fargate, this would need to point to the container's own address (e.g., `http://localhost` or the ALB URL). This is solvable — `http://localhost` would work since Apache listens on port 80 inside the container. But it's worth validating, because Fargate's networking model means `localhost` only reaches the task's own containers.

---

## 5. The Multi-Tenancy Question

Even if the webhook problem didn't exist, a centralized deployment serving multiple consumers simultaneously raises isolation concerns.

### 5.1 What's Already Built: Namespace Isolation

The platform already has namespace-based isolation. Every database table includes a `namespace` column, and all queries filter by it:

```sql
-- entities table
UNIQUE KEY `uq_ns_type_ref` (`namespace`, `entity_type`, `entity_ref`)

-- All queries use:
WHERE namespace = :ns
```

The control plane enforces this:
- `POST /control/scenarios` requires a unique namespace and rejects duplicates (HTTP 409)
- `DELETE /control/scenarios/{namespace}` deletes only that namespace's data
- Scenarios auto-expire after 2 hours

For test automation (CI/CD pipelines), this is effective. Each pipeline run uses a unique namespace (e.g., `ci-pipeline-12345`), seeds its scenario, runs tests, and tears down.

### 5.2 What's Not Built: The "Legacy" AiPrise Endpoints

The AiPrise-faithful endpoints (the ones that mirror the real AiPrise API exactly) have a **different isolation model**. These endpoints are designed so penny-api can call them with zero code changes — meaning penny-api doesn't know about namespaces.

When penny-api calls `POST /api/v1/verify/get_user_verification_url`, the namespace is resolved from a header/query param/body field, but penny-api doesn't send any of those. The platform falls back to a hardcoded default namespace:

```php
// AipriseService.php line 31-32
private const DEFAULT_NAMESPACE = '__aiprise__';
private const KYB_NAMESPACE = '__aiprise_kyb__';
```

This means **all consumers that don't pass a namespace share the same state**. If two developers are both testing KYC flows, their sessions land in the same `__aiprise__` namespace.

In the local Docker stack this is fine — there's only one developer per stack. In a centralized deployment, it's a collision risk:

```
Developer A creates KYC session → session ID abc-123 in __aiprise__
Developer B creates KYC session → session ID def-456 in __aiprise__
Developer A queries sessions → sees both abc-123 AND def-456
Background auto-complete fires → webhooks for BOTH sessions fire simultaneously
```

The data doesn't actually corrupt (session IDs are globally unique UUIDs), but it creates noise and confusion. More critically, the single `AIPRISE_CALLBACK_REWRITE_HOST` value means all callbacks get rewritten to the same target — whichever developer's host was configured.

### 5.3 The Hardcoded penny-api URL

The control plane's KYB approval endpoint has a hardcoded Docker hostname:

```php
// index.php line 143
$pennyApiUrl = 'http://penny-api:3003';
```

This makes `POST /control/approve-kyb/{businessId}` and `GET /control/pending-kyb` completely non-functional outside a Docker Compose environment. On Fargate, `penny-api` doesn't resolve to anything.

---

## 6. Use Case Breakdown

### 6.1 CI/CD Pipelines (AWS-hosted)

**Verdict: Good fit, with minor changes.**

If CI/CD runners (CodeBuild, GitHub Actions self-hosted, GitLab runners on EC2, etc.) are in the same AWS VPC as the Fargate service:

- **Inbound requests** (service-under-test -> virtual service): Work. The runner's service-under-test can reach the Fargate ALB.
- **Outbound webhooks** (virtual service -> service-under-test): Work — if the service-under-test is running on a routable address within the VPC (e.g., an ECS task, an EC2 instance, or a container with a known IP/hostname).
- **Namespace isolation**: Works — each pipeline seeds a unique namespace.
- **Teardown**: Works — `DELETE /control/scenarios/{namespace}` cleans up.

**What's needed:**
- Each pipeline must pass a namespace (via `X-Test-Namespace` header) with every request
- The service-under-test must be reachable from Fargate (VPC networking)
- The `callback_url` passed to the virtual service must be the routable address of the service-under-test in that pipeline run (not a production URL)
- No `AIPRISE_CALLBACK_REWRITE_HOST` needed — each pipeline passes the correct callback URL directly

### 6.2 PR/MR Validation (Ephemeral Environments)

**Verdict: Good fit, same as CI/CD.**

If you use ephemeral environments (e.g., ECS tasks spun up per PR), the same logic applies. The ephemeral penny-api-restricted has a known address within the VPC, and the callback URL is configured to point there.

### 6.3 Local Development

**Verdict: Partially works. Webhook-dependent flows break.**

| What works | What doesn't |
|-----------|-------------|
| Bridge T&C pages (browser-facing) | KYC auto-completion webhooks |
| Bridge `POST /customers/tos_links` | KYB auto-completion webhooks |
| Email OTP stub (logs OTP) | Compliance callback delivery |
| AiPrise session creation (returns URL) | Anything requiring the platform to call back to localhost |
| Control plane: seed/reset/inspect | KYB approval (hardcoded penny-api URL) |
| Verification page (`/verify`) | |

**Workaround options for local dev:**

1. **Tunnel (ngrok, Tailscale, etc.)** — developer exposes their local penny-api-restricted via a tunnel, passes the tunnel URL as the callback. Adds friction, costs money at scale, and requires each developer to manage a tunnel.

2. **Polling instead of webhooks** — instead of relying on the platform to push webhooks, the test/service polls `GET /control/scenarios/{namespace}` or `GET /api/v1/verify/get_user_verification_result/{id}` until the state changes. This requires code changes in the consumer (penny-api-restricted) or a wrapper script.

3. **Keep running locally for dev** — developers continue using Docker Compose for the full stack (including service-virtualization), and the centralized Fargate instance serves only CI/CD. This is the simplest and most pragmatic option.

4. **VPN-based routing** — if all developers are on a corporate VPN with routable IPs, the Fargate service could reach their machines. Fragile and assumes always-on VPN.

---

## 7. Security Concerns for a Shared Deployment

The platform was built for local single-user use. A shared deployment exposes several security gaps:

### 7.1 No Authentication

Every endpoint — including the control plane — is completely unauthenticated. Anyone who can reach the ALB can:

- Seed and inspect any namespace's data
- Fire callbacks to arbitrary URLs (the platform will POST to any `target_url`)
- Reset any namespace (delete all its data)
- View request logs with full headers and bodies

**Required before shared deployment:** API key or IAM-based auth on all endpoints, at minimum on the control plane (`/control/*`).

### 7.2 CORS Wide Open

```php
// index.php line 59
header('Access-Control-Allow-Origin: *');
```

Acceptable for local dev. Not acceptable for a shared service on a corporate network.

### 7.3 Callback URL as Open Relay

The `CallbackScheduler::fireSingle()` method will POST to any URL stored in `target_url`:

```php
// CallbackScheduler.php line 164
$ch = curl_init($cb['target_url']);
```

A malicious actor could seed a scenario with a callback URL pointing to an internal service, using the platform as a proxy to send arbitrary POST requests within the VPC. This is a Server-Side Request Forgery (SSRF) vector.

**Required before shared deployment:** URL allowlisting for callback targets, or at minimum, validation that callback URLs match known service patterns.

### 7.4 Debug Mode Leak

```php
// index.php line 394
'trace' => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? $e->getTraceAsString() : null,
```

If `APP_DEBUG=true` leaks into the Fargate deployment, stack traces (with file paths, database credentials in connection strings, etc.) would be exposed in error responses.

---

## 8. Scalability: Growing the Virtual Service Catalog

The platform currently virtualizes ~4 external services (AiPrise, Bridge, Email, Compliance). AlfredPay integrates with many more third-party services, and the plan is to continue adding virtual implementations over time. This section analyzes how the platform scales as the service catalog grows.

### 8.1 What Scales Well

**Code and image size** — Each new virtual service adds ~2-3 PHP files (~200-300 lines). PHP's autoloader means a request to the AiPrise endpoint never loads Bridge code. Even with 50 virtualized services, the codebase would be ~150 files. The container image stays small and startup time is unaffected.

**Memory per request** — PHP's process-per-request model isolates memory usage. Adding more virtual services doesn't make any individual request heavier. A request to Bridge uses the same memory whether 5 or 50 other services are registered.

**Database schema** — All virtual services share the same generic tables (`entities`, `pending_callbacks`, etc.) via the `entity_type` discriminator. No schema changes are needed per service. Row counts grow linearly with usage, not with the number of registered services.

### 8.2 What Breaks Under Growth

**Callback volume** — This is the primary bottleneck. The background firer processes up to 50 callbacks every 5 seconds, but it fires them **sequentially** — one cURL call at a time, each with a 30-second timeout. If multiple services schedule webhooks simultaneously (e.g., a CI pipeline exercising KYC, KYB, and compliance flows in parallel), the queue backs up. A single slow or unreachable callback target stalls the entire queue for up to 30 seconds per attempt.

```
Current worst case (sequential firing):
  50 callbacks × 30s timeout each = 25 minutes to drain one batch
  Meanwhile, new callbacks accumulate with no processing
```

**Apache concurrency (mod_php + prefork)** — The `php:8.2-apache` image uses `mod_php` with Apache's prefork MPM, which spawns a separate process per concurrent request. Each process consumes ~20-30 MB of memory. On a Fargate task with 512 MB, that's roughly 15-20 concurrent requests before OOM. With more virtual services being exercised simultaneously by multiple pipeline runs, this ceiling gets reached faster.

**Database connections** — Each PHP process opens its own PDO connection to MySQL. There is no connection pooling. Under concurrent load (e.g., 10 pipeline runs each making requests), you could exhaust a small RDS instance's `max_connections` (default 66 on `db.t4g.micro`).

### 8.3 Recommendations for Scalability

| Change | Why | Effort |
|--------|-----|--------|
| **Switch to php-fpm + nginx** | php-fpm uses a worker pool with configurable process management (`pm.max_children`, `pm.max_spare_servers`). Much better memory efficiency than prefork — handles more concurrency in the same memory footprint. | Small — new Dockerfile, nginx config, php-fpm pool config |
| **Parallelize the callback firer** | Use `curl_multi_*` to fire multiple callbacks concurrently instead of sequentially. A batch of 50 callbacks with 5 concurrent workers drains in ~5 minutes worst-case instead of ~25. | Small — refactor `CallbackScheduler::fireAll()` to use `curl_multi_init()` |
| **Fargate task auto-scaling** | Set ECS Service auto-scaling on CPU/memory metrics so additional tasks spin up during heavy pipeline periods. Not needed at launch, but plan the ALB target group for it. | Small — ECS service scaling policy, ALB health checks already exist |
| **RDS connection limits** | If using `db.t4g.micro`, raise `max_connections` or use RDS Proxy for connection pooling. Alternatively, start with `db.t4g.small` (150 max connections). | Trivial — RDS parameter group or instance size |

These are not launch blockers. The current implementation handles single-user and light CI load fine. But they should be planned for before the service catalog exceeds ~10 virtual services or concurrent pipeline count exceeds ~5.

---

## 9. What Would Need to Change

### 9.1 Required Changes (Blockers)

| Change | Why | Effort |
|--------|-----|--------|
| **Add authentication to the control plane** | Prevent unauthorized scenario manipulation and data exposure | Medium — API key middleware on `/control/*` routes |
| **Make `penny-api` URL configurable** | `http://penny-api:3003` is hardcoded in `index.php` lines 143, 175 — won't resolve on Fargate | Trivial — read from `$_ENV['PENNY_API_URL']` |
| **Restrict CORS** | `Access-Control-Allow-Origin: *` is not appropriate for a shared service | Trivial — env-based allowlist |
| **Validate callback URLs** | Prevent SSRF via arbitrary callback targets | Small — allowlist check in `CallbackScheduler::schedule()` |
| **RDS MySQL instead of container MySQL** | Fargate tasks are ephemeral; local MySQL container would lose data on redeploy | Small — just change `DB_HOST` env var to RDS endpoint |

### 9.2 Recommended Changes (Improve Shared Use)

| Change | Why | Effort |
|--------|-----|--------|
| **Move callback firer to scheduled ECS task** | The current background loop (`while true; sleep 5; php fire-callbacks.php`) is not observable and doesn't scale. A scheduled ECS task or EventBridge cron gives you CloudWatch logs and retry semantics. See also Section 8.2 for parallelization needs as the service catalog grows. | Small |
| **Add namespace to AiPrise-faithful endpoints** | Currently defaults to `__aiprise__` when penny-api doesn't send a namespace. For shared use, penny-api would need to pass `X-Test-Namespace` header — but this means a code change in penny-api. Alternatively, namespace could be inferred from API key. | Medium — requires consumer-side changes or namespace-from-auth |
| **Request log retention/rotation** | `request_log` table grows unbounded. Add a TTL or scheduled cleanup beyond the 2-hour scenario expiry. | Small |
| **Health check for ALB** | `GET /health` exists but should also verify the callback firer is running (not just DB connectivity) | Small |

### 9.3 Not Needed (at Launch)

| Thing you might think you need | Why you don't |
|-------------------------------|--------------|
| Multi-container Fargate task (sidecar for MySQL) | Use RDS. Fargate sidecars add complexity for no benefit here. |
| EFS volume for persistence | All state is in MySQL. No filesystem persistence needed. |
| Auto-scaling (at launch) | A single Fargate task is sufficient initially. Plan the ALB target group to support scaling later — see Section 8.3 for when it becomes necessary as the service catalog and concurrent pipeline count grow. |
| Redis/ElastiCache | No caching layer needed. PDO connections are per-request and MySQL handles the concurrency. |

---

## 10. Recommendation

### Deploy to Fargate — but only for CI/CD and ephemeral environments.

The highest-value use case is automated pipelines. These run in AWS, have routable network addresses, and benefit most from a stable, always-on virtual service. The namespace isolation is already built for this.

### Keep Docker Compose for local development.

The webhook callback problem has no clean solution for developers running services on `localhost`. Tunneling adds friction. Polling requires code changes. The local Docker stack already works well and costs nothing. Forcing developers onto a centralized instance would degrade their experience for the flows that matter most (KYC/KYB end-to-end).

### Suggested rollout

```
Phase 1: Deploy to Fargate (CI/CD only)
  - Implement required blockers (auth, configurable URLs, CORS, SSRF protection)
  - Stand up RDS MySQL instance (db.t4g.micro is fine)
  - Deploy Fargate service in the CI/CD VPC
  - Migrate one pipeline to use it as proof of concept
  - Local dev continues using Docker Compose unchanged

Phase 2: Validate and expand
  - Run parallel: existing pipeline mocks + centralized virtual service
  - Measure reliability, callback delivery success rate
  - Expand to more pipelines once stable

Phase 3: Evaluate local dev options (optional, lower priority)
  - If there's demand, explore:
    - Tailscale/VPN-based routing for developer machines
    - A "poll mode" SDK that doesn't require inbound webhooks
    - Namespace-per-developer with callback URL registration
  - These are optimizations, not blockers
```

---

## 11. Fargate Sizing Recommendations

### Service Virtualization Task

| Setting | Recommended | Why |
|---------|-------------|-----|
| **vCPU** | 0.25 | PHP/Apache, low compute. Most work is DB reads/writes and outbound cURL calls. Not CPU-bound. |
| **Memory** | 512 MB | Apache prefork spawns ~20-30 MB per concurrent request. 512 MB handles ~15 concurrent requests. This is the Fargate minimum and is already overprovisioned for initial use. |
| **Desired count** | 1 | Single task is sufficient at launch. Plan the ALB target group so it can scale later. |

### RDS MySQL

| Setting | Recommended | Why |
|---------|-------------|-----|
| **Instance** | db.t4g.micro | 2 vCPU (burstable), 1 GB RAM, 66 max connections. Fine for <5 concurrent pipelines. Move to db.t4g.small (2 GB, 150 connections) if you outgrow it. |
| **Storage** | 20 GB gp3 | State is ephemeral (2-hour auto-expiry on scenarios). This will never fill up. |
| **Multi-AZ** | No | It's a test tool, not production data. |

### Concurrency Analysis

Each CI/CD pipeline run is sequential — test steps execute one at a time, each making an HTTP request that completes in ~50-100ms (PHP reads/writes MySQL, returns JSON). Concurrency comes from multiple pipelines running simultaneously.

| Scenario | Concurrent pipelines | Requests in-flight at any instant | Apache processes needed |
|----------|---------------------|----------------------------------|------------------------|
| **Early adoption** (1-2 pipelines using it) | 1-2 | 2-4 | 2-4 |
| **Steady state** (most pipelines migrated) | 3-5 | 5-10 | 5-10 |
| **Peak spike** (everyone pushes before release) | 5-8 | 8-15 | 8-15 |

The 512 MB ceiling (~15-20 Apache processes) covers even peak-spike scenarios. PHP requests complete so fast that processes recycle quickly — the service will be mostly idle between test requests.

The real bottleneck under load is **not** Apache concurrency — it's the **callback firer**. It fires webhooks sequentially (one cURL at a time, 30s timeout each). A single slow or unreachable callback target stalls the entire queue. See Section 8.2 for the `curl_multi` parallelization recommendation.

### When to Scale Up

| Trigger | Action |
|---------|--------|
| >5 concurrent pipelines or >10 virtual services | Bump to 0.5 vCPU / 1 GB. Switch from mod_php to php-fpm + nginx (smaller memory footprint per request). |
| Callback queue backing up (visible in request logs) | Parallelize the firer (`curl_multi`), or bump to 0.5 vCPU. |
| DB connection exhaustion (>66 concurrent connections) | Move to db.t4g.small or add RDS Proxy. |

**Bottom line: 0.25 vCPU / 512 MB is the Fargate minimum and is already generous for this workload. Start there, scale based on observed CloudWatch metrics, not upfront guessing.**

---

## 12. Summary Table

| Use case | Fargate viable? | Webhook delivery? | Namespace isolation? | Notes |
|----------|:-:|:-:|:-:|-|
| CI/CD (AWS-hosted runners) | Yes | Yes (same VPC) | Yes (built-in) | Best fit |
| PR ephemeral environments | Yes | Yes (same VPC) | Yes | Same as CI/CD |
| Local dev (request/response flows) | Yes | N/A | N/A | Bridge, email, session creation |
| Local dev (webhook flows) | No | No (can't reach localhost) | Shared default namespace | Keep Docker Compose |
| Shared QA/staging | Yes | Yes (if services are routable) | Needs namespace discipline | Good fit if services have stable addresses |

---

## Appendix A: Callback Flow Diagram

```
┌──────────────┐     1. POST /verify/get_user_verification_url      ┌─────────────────────┐
│              │ ──────────────────────────────────────────────────> │                     │
│  penny-api   │     (callback_url = https://penny-restricted/...)   │  service-             │
│  (consumer)  │                                                     │  virtualization       │
│              │ <────────────────────────────────────────────────── │  (Fargate)            │
│              │     2. Response: { verification_session_id, url }   │                     │
│              │                                                     │                     │
│              │                                                     │  3. Schedules auto-  │
│              │                                                     │     complete callback │
│              │                                                     │     (fire_at = +10s)  │
│              │                                                     │                     │
│              │     4. POST /webhook-url-session                    │                     │
│              │ <────────────────────────────────────────────────── │  (fires after delay) │
│              │     (HMAC-signed webhook with verification result)  │                     │
└──────────────┘                                                     └─────────────────────┘

Step 4 is the problem:
  - If penny-api is on Fargate/EC2 in the same VPC → callback arrives ✓
  - If penny-api is on a developer's laptop → callback can't reach it ✗
```

## Appendix B: Key Source Files

| File | What it does |
|------|-------------|
| `public/index.php` | All route definitions, CORS headers, penny-api hardcoded URL (lines 143, 175) |
| `src/Aiprise/AipriseService.php` | KYC/KYB session lifecycle, callback URL rewriting (line 780), self-callback scheduling (lines 82-83), HMAC-signed webhook firing |
| `src/Callback/CallbackScheduler.php` | Webhook queue: schedule, fire, retry, history. The `fireSingle()` method (line 156) makes the outbound HTTP call |
| `src/Controller/ControlPlaneController.php` | Test ergonomics: seed, reset, inspect, fire-callbacks, cleanup-expired |
| `src/Entity/EntityManager.php` | Namespace-scoped CRUD for stateful entities with state transition history |
| `src/Compliance/ComplianceService.php` | Compliance session state machine with callback scheduling on transitions |
| `src/Bridge/BridgeService.php` | Stateless — returns `APP_BASE_URL`-based T&C page URLs |
| `docker-entrypoint.sh` | Container bootstrap: env generation, MySQL wait, schema install, background callback loop |
| `bin/install-schema.php` | Database schema: 6 tables, all with `namespace` column for isolation |
| `Dockerfile.local` | `php:8.2-apache` image, standard web app container |
