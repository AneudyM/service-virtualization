# AlfredPay Service Virtualization Platform — Project Status

**Last updated:** 2026-03-17
**Status:** POC deployed and functional

---

## What We Built

A PHP 8.2 service virtualization platform deployed on TigerTech shared hosting
at `https://service-virtualization.intelycs.com`. It implements two components
from the AlfredPay Service Virtualization Architecture document:

### 1. Virtual Callback Orchestrator (P0 — Wave 0)
The shared platform capability that all virtual services depend on.

**Implemented:**
- Schedule callbacks with configurable delay
- Instant test-driven firing via `POST /control/fire-callbacks` (no cron wait)
- Retry with backoff (configurable max attempts)
- Duplicate callback scheduling (for testing duplicate handling)
- Per-namespace isolation
- Callback history and observability
- Cron-compatible `bin/fire-callbacks.php` for async delivery (5-min intervals)

### 2. Virtual Compliance Service (P0 — Wave 1)
A **generic** L3 workflow emulator for KYC/KYB verification.

**Implemented:**
- KYC session creation (DRAFT state)
- Document submission (DRAFT → PENDING)
- Auto-progression with configurable outcome and delay
- State machine with guarded transitions (PHP 8.2 enum):
  `DRAFT → PENDING → APPROVED | REJECTED | INFO_REQUIRED → PENDING (resubmit)`
- Verification URL generation
- Callback emission on every state transition
- Full state transition history

### 3. Control Plane
Test ergonomics layer for scenario management.

**Implemented:**
- `POST /control/scenarios` — Seed a scenario with entities in known states
- `GET /control/scenarios` — List all active scenarios
- `GET /control/scenarios/{namespace}` — Full inspection (entities, state history, callbacks)
- `DELETE /control/scenarios/{namespace}` — Complete teardown
- `POST /control/fire-callbacks` — Instant callback delivery
- `GET /control/history/{namespace}` — Request + callback history
- `POST /control/cleanup-expired` — Housekeeping for expired namespaces

---

## Architecture

```
/home/in/intelycs.com/service-virtualization/     ← source code (not web-accessible)
├── public/index.php                               ← front controller
├── src/
│   ├── Core/          Database, Router, JsonResponse, RequestLogger
│   ├── Callback/      CallbackScheduler (the orchestrator)
│   ├── Entity/        EntityManager (stateful entity CRUD + state machine)
│   ├── Compliance/    ComplianceService, KycState enum
│   └── Controller/    ControlPlaneController, ComplianceController
└── bin/               install-schema.php, fire-callbacks.php

/var/www/html/in/intelycs.com/service-virtualization → symlink to public/
```

**Subdomain:** `service-virtualization.intelycs.com`
**Database:** MySQL on TigerTech
**Dependencies:** Only `symfony/dotenv` — everything else is PHP built-ins (PDO, curl, json)

---

## Demo Results (2026-03-17)

Full KYC lifecycle completed successfully:

| Step | Endpoint | Result |
|------|----------|--------|
| Seed scenario | `POST /control/scenarios` | Namespace created, KYC entity in DRAFT |
| List sessions | `GET /api/compliance/sessions` | Entity visible in DRAFT state |
| Submit documents | `POST /api/compliance/sessions/{ref}/submit` | Transitioned to PENDING |
| Fire callbacks | `POST /control/fire-callbacks` | Auto-transition callback fired, HTTP 200 |
| Check final state | `GET /api/compliance/sessions/{ref}` | **State: APPROVED** |
| View history | `GET /api/compliance/sessions/{ref}/history` | 3 transitions: → draft → pending → approved |
| Cleanup | `DELETE /control/scenarios/{ns}` | All records deleted |

Demo script: `demo.ps1` (PowerShell)

---

## Critical Gap: Contract Fidelity

### The Problem

The virtual compliance service has **generic** endpoints and field names:
```
POST /api/compliance/sessions
GET  /api/compliance/sessions/{id}
```

But the **real Aiprise API** that Penny API Restricted calls likely has
**different** endpoints, field names, status values, and webhook payloads.
Until the virtual service mirrors the exact Aiprise contract, Penny cannot
point to it as a drop-in replacement.

### What's Needed

To make this a real Aiprise replacement, we need to:

1. **Extract the real Aiprise API contract** from one or more of:
   - `ms-aiprise-main` repo (the dedicated Aiprise integration service)
   - Penny API Restricted source code (the module that calls Aiprise)
   - Aiprise official API documentation or OpenAPI spec
   - Network captures / Postman collections of real Aiprise calls
   - Obsidian vault notes related to KYC/KYB testing

2. **Map the exact surface:**
   - Which HTTP endpoints does Penny/ms-aiprise hit?
   - What request payloads does it send (field names, types, required fields)?
   - What response shapes does it expect (field names, nesting, status values)?
   - What webhook payloads does Aiprise POST back?
   - What headers are required (API keys, signatures)?
   - What error responses does it return (status codes, error shapes)?

3. **Rebuild the virtual compliance routes** to match the real contract:
   - Same URL paths
   - Same request/response JSON structure
   - Same status/state value strings
   - Same webhook payload format
   - Same error response shapes

4. **Validate with a contract test** — run a test suite against both the
   real Aiprise sandbox and the virtual service, compare responses field by field.

---

## Full Gap List (Ordered by Priority)

### P0 — Required for Aiprise replacement to work

- [ ] **Extract real Aiprise API contract** (see above)
- [ ] **Rebuild compliance endpoints to match Aiprise surface exactly**
- [ ] **Match webhook payload format** to what Penny expects
- [ ] **Add Aiprise authentication simulation** (API key headers Penny sends)
- [ ] **Contract test** — validate virtual vs real Aiprise responses

### P1 — Required for production-grade POC

- [ ] **Cron job not yet configured** on TigerTech for async callback firing
  ```
  */5 * * * * cd ~/service-virtualization; /usr/bin/php bin/fire-callbacks.php
  ```
- [ ] **Expired scenario cleanup cron** not yet configured
  ```
  17 * * * * /usr/bin/wget --quiet -O - 'https://service-virtualization.intelycs.com/control/cleanup-expired'
  ```
- [ ] **KYB (business verification)** — currently only KYC is implemented;
  KYB has different document requirements and may have different state flows
- [ ] **Error response simulation** — the virtual service always succeeds;
  need configurable error scenarios (invalid documents, expired session,
  rate limits, 500 errors)
- [ ] **Request logging middleware** — `RequestLogger` is defined but not
  wired into the dispatch loop for successful requests (only 404/500)
- [ ] **Fault injection** — configurable latency, 4xx/5xx errors, malformed
  responses (required by architecture doc)
- [ ] **Time controls** — fast-forward for quote expiry, session expiry
  (required by architecture doc)

### P2 — Next virtual services (Wave 1-2 from architecture doc)

- [ ] **Virtual Rail Network Service** (L4) — replaces STP, SPEI, Rampas,
  country adapters. Needed for onramp/offramp testing.
- [ ] **Virtual Card Payment Service** (L4) — replaces Worldpay Access
  Checkout. 3DS challenge, webhooks, terminal-state protection.
- [ ] **Virtual Custody/Blockchain Service** (L4) — replaces Fireblocks/Vault.
  Balance management, transfer lifecycle, XDR signatures.
- [ ] **Virtual CPN Network Service** (L4) — replaces Circle CPN.
  Quote/payment creation, webhook subscriptions.
- [ ] **Virtual Quote/Rates Service** (L2) — static and time-windowed FX rates,
  bank lists, requirements lookups.

### P3 — Platform hardening

- [ ] **SSL certificate** — verify that `service-virtualization.intelycs.com`
  has valid SSL (it worked in the demo, but confirm cert coverage)
- [ ] **Rate limiting** — protect against accidental DoS from test loops
- [ ] **Authentication on control plane** — currently anyone can seed/reset
  scenarios; add an API key for control plane endpoints
- [ ] **Database cleanup strategy** — auto-expire old scenarios, request logs,
  callback history to prevent DB bloat
- [ ] **Health monitoring** — basic uptime check (can use TigerTech's own
  monitoring or an external service)
- [ ] **Structured logging** — replace console output with file-based logs
- [ ] **PHPUnit tests** — unit tests for KycState transitions, EntityManager,
  CallbackScheduler

---

## Hosting Details

| Item | Value |
|------|-------|
| **Provider** | TigerTech shared hosting |
| **Domain** | intelycs.com |
| **Subdomain** | service-virtualization.intelycs.com |
| **PHP version** | 8.2.30 |
| **Database** | MySQL |
| **SSH access** | Yes |
| **Cron** | Min every 5 minutes, max 60s CPU per run |
| **Web root** | `/var/www/html/in/intelycs.com/` |
| **Home dir** | `/home/in/intelycs.com/` |
| **Source code** | `/home/in/intelycs.com/service-virtualization/` |
| **Symlink** | `/var/www/html/in/intelycs.com/service-virtualization` → `public/` |
| **PHPStorm Server Root** | `/home/in/intelycs.com` |
| **PHPStorm Deployment Path** | `service-virtualization` |
| **PHPStorm Web URL** | `https://service-virtualization.intelycs.com` |

---

## API Reference (22 endpoints)

### Control Plane

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/` | Health check + version |
| GET | `/health` | Health + DB connection check |
| GET | `/control/scenarios` | List all active scenarios |
| POST | `/control/scenarios` | Seed a new scenario |
| GET | `/control/scenarios/{namespace}` | Inspect namespace (entities, callbacks, history) |
| DELETE | `/control/scenarios/{namespace}` | Reset/teardown namespace |
| POST | `/control/fire-callbacks` | Fire pending callbacks instantly |
| GET | `/control/history/{namespace}` | Request + callback history |
| POST | `/control/cleanup-expired` | Remove expired scenarios |

### Virtual Compliance Service

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/compliance/sessions` | List sessions in namespace |
| POST | `/api/compliance/sessions` | Create KYC/KYB session |
| GET | `/api/compliance/sessions/{ref}` | Get session status |
| POST | `/api/compliance/sessions/{ref}/submit` | Submit documents |
| POST | `/api/compliance/sessions/{ref}/transition` | Force state transition |
| POST | `/api/compliance/sessions/{ref}/auto-transition` | Internal: auto-progression |
| GET | `/api/compliance/sessions/{ref}/url` | Get verification URL |
| GET | `/api/compliance/sessions/{ref}/history` | State transition history |

### Namespace Resolution

All virtual service endpoints require a namespace, passed via:
1. `X-Test-Namespace` header (preferred)
2. `?namespace=` query parameter
3. `namespace` field in request body

---

## Relationship to Architecture Documents

### Service Virtualization Architecture Doc
- **Wave 0 (Foundation):** Callback Orchestrator — **DONE**
- **Wave 1 (P0 Externals):** Virtual Compliance — **PARTIAL** (generic, not Aiprise-faithful)
- **Wave 1 (P0 Externals):** Virtual Rail Network — NOT STARTED
- **Wave 1 (P0 Externals):** Virtual Card Payment — NOT STARTED
- **Wave 2 (Funds Movement):** Virtual Custody, CPN — NOT STARTED
- **Wave 3 (Adoption):** Virtual Internal APIs, Reporting — NOT STARTED

### API Automation Architecture Manual (102 pages)
This platform is **separate** from the API automation framework. The manual
prescribes a TypeScript/Jest 5-layer platform for test execution. This PHP
service virtualization platform is infrastructure that the TypeScript tests
would **consume** — they point service-under-test at these virtual endpoints
instead of real partner APIs.

The two tracks are:
1. **Service Virtualization** (this project, PHP) — provides deterministic
   API simulators for external dependencies
2. **API Automation Framework** (future, TypeScript) — runs tests against
   AlfredPay services configured to use virtual dependencies

---

## Files

```
service-virtualization/
├── public/
│   ├── index.php              # Front controller (all routes + dispatch)
│   └── .htaccess              # Apache URL rewriting + RewriteBase /
├── src/
│   ├── Core/
│   │   ├── Database.php       # PDO singleton connection
│   │   ├── Router.php         # Lightweight path router with {param} support
│   │   ├── JsonResponse.php   # JSON response helper (ok/error)
│   │   └── RequestLogger.php  # Inbound request logging to DB
│   ├── Callback/
│   │   └── CallbackScheduler.php   # Callback queue: schedule, fire, retry, history
│   ├── Entity/
│   │   └── EntityManager.php  # Stateful entity CRUD, state transitions, audit log
│   ├── Compliance/
│   │   ├── KycState.php       # PHP 8.2 enum with guarded state transitions
│   │   └── ComplianceService.php  # KYC session lifecycle + callback scheduling
│   └── Controller/
│       ├── ControlPlaneController.php   # Seed, reset, inspect, fire, cleanup
│       └── ComplianceController.php     # Virtual Aiprise API surface
├── bin/
│   ├── install-schema.php     # Creates all 6 MySQL tables
│   └── fire-callbacks.php     # Cron script: fires due callbacks
├── composer.json              # Dependency: symfony/dotenv only
├── .env.example               # Environment template
├── .gitignore
├── .htaccess                  # (not used — .htaccess is in public/)
├── demo.ps1                   # PowerShell demo script (full KYC lifecycle)
├── demo.sh                    # Bash demo script (same, for Linux/Mac)
├── DEPLOY.md                  # Step-by-step TigerTech deployment guide
└── PROJECT_STATUS.md          # This file
```

---

## Next Session Checklist

1. Get access to `ms-aiprise-main` repo or Penny API Restricted Aiprise module
2. Extract exact Aiprise API contract (endpoints, payloads, webhook format)
3. Rebuild virtual compliance routes to match real contract
4. Set up cron jobs on TigerTech
5. Add control plane authentication (API key)
6. Begin next virtual service (Rail Network or Card Payment)
