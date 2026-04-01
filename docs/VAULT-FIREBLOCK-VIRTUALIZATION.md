# Vault / Fireblock Provider Virtualization

**Date:** 2026-03-30
**Status:** Analysis and implementation plan

---

## 1. What is the Fireblock Service?

The `fireblock` repo is a NestJS wrapper around [Fireblocks](https://www.fireblocks.com/), a cryptocurrency custody and transaction infrastructure platform. It is the bridge between AlfredPay's internal services and two external systems:

| External System | What it does | Protocol |
|---|---|---|
| **Fireblocks API** (`api.fireblocks.io`) | Vault management, transaction creation, address lookup, transaction queries | REST, RS256 JWT auth |
| **Stellar Horizon** (`horizon.stellar.org`) | Broadcast signed Stellar transactions to the network | REST |

**Not in scope:** The `GET /fireblock/vault/allowance` endpoint makes JSON-RPC calls to public blockchain nodes (Ethereum, Polygon, etc.) to read ERC-20 allowances. This does not need virtualization — it's a standalone read-only query, no other flow depends on its result, and it works against public RPCs without credentials.

### What the service exposes

| Method | Endpoint | Auth | Calls externally? |
|---|---|---|---|
| POST | `/fireblock/signIn` | None | No (DB lookup only) |
| POST | `/fireblock/transfer-tokens` | None | Fireblocks API |
| POST | `/fireblock/send-tokens/pay` | JwtAppGuard | Fireblocks API |
| GET | `/fireblock/list-wallet` | JwtAppGuard | Fireblocks API |
| GET | `/fireblock/list-whitelist-refund/:country` | None | No (DB lookup only) |
| GET | `/fireblock/addresses/:vaultAccountId/:assetId` | None | Fireblocks API |
| GET | `/fireblock/get-transaction/:txId` | JwtAppGuard | Fireblocks API |
| POST | `/fireblock/list-transactions` | JwtAppGuard | Fireblocks API |
| POST | `/fireblock/send-tokens/sign` | JwtAppGuard | Fireblocks API |
| POST | `/fireblock/permit2` | JwtAppGuard | Fireblocks API |
| GET | `/fireblock/vault/allowance` | None | Blockchain RPC (public, not virtualized) |
| POST | `/stellar/sign-xdr` | JwtAppGuard | Stellar Horizon (optional) |

### Key characteristics for virtualization

- **No webhooks.** All flows are synchronous request/response. No callback delivery problem (unlike AiPrise).
- **Stateful transactions.** The service records every transaction in its `transaction_fireblocks` PostgreSQL table.
- **Two-tier auth.** Callers authenticate with the fireblock service via JWT (`/signIn`). The fireblock service then authenticates with Fireblocks API using an RS256-signed JWT with a private key file.

---

## 2. Deployment Strategy

### The problem

The team does not want to run the fireblock service as a local container. The service is already deployed (staging/production). The question is how to make that deployed service talk to the virtual service instead of the real Fireblocks API, without touching production.

### The approach: Deploy a virtual-backed instance

Deploy a second instance of the fireblock service under a different domain, configured to point at the service-virtualization platform instead of the real external APIs. Production remains untouched.

```
                        ┌──────────────────────────────────────────────┐
                        │           Fireblock Service                  │
                        │           (TWO INSTANCES)                    │
                        │                                              │
  AlfredPay services    │  fireblock.alfredpay.internal (production)   │
  (penny-api, etc.) ──> │    └── FIREBLOCKS_BASE_URL = api.fireblocks.io
                        │    └── STELLAR_HORIZON_URL = horizon.stellar.org
                        │    └── (uses real blockchain RPCs)           │
                        │                                              │
  Local dev / CI / QA   │  fireblock-virtual.alfredpay.internal        │
  environments ───────> │    └── FIREBLOCKS_BASE_URL = virtual-services.alfredpay.internal
                        │    └── STELLAR_HORIZON_URL = virtual-services.alfredpay.internal
                        │    └── (allowance endpoint uses public RPCs, unchanged) │
                        └───────────────────┬──────────────────────────┘
                                            │
                                            ▼
                        ┌──────────────────────────────────────────────┐
                        │  Service Virtualization (Fargate or local)    │
                        │  Implements:                                  │
                        │    /v1/transactions                           │
                        │    /v1/vault/accounts_paged                   │
                        │    /v1/vault/accounts/:id/:asset/addresses_*  │
                        │    /stellar/submit                            │
                        └──────────────────────────────────────────────┘
```

### Why this is the right approach

- **Production untouched** — The existing deployment doesn't change at all. No env var is added, no risk.
- **Real code paths exercised** — The fireblock NestJS service runs its actual code: JWT generation, DTO validation, transaction DB logging, error handling. Only the external boundary is fake.
- **Consistent with architecture principle** — We never bypass AlfredPay-owned services. The fireblock wrapper is AlfredPay-owned; the Fireblocks API is the external boundary we virtualize.
- **No local container needed** — Developers and CI point at `fireblock-virtual.alfredpay.internal` instead of running the service locally.

### Why not other approaches

| Approach | Problem |
|---|---|
| Modify the existing deployment's env vars | Affects everyone using staging. One misconfiguration and real crypto flows break. |
| DNS interception / proxy rewrite | Fragile. Breaks if the Fireblocks SDK validates certs or uses SNI. Hard to scope. |
| Virtual service impersonates the fireblock wrapper directly | Violates architecture principle — bypasses the real NestJS code paths. Bugs in JWT generation, vault ID validation, or DB logging would never surface. |

---

## 3. Required Code Changes in the Fireblock Repo

The external API URLs are currently hardcoded. Two small changes make them env-configurable with the current values as defaults (zero impact on existing deployments):

### 3.1 Fireblocks API base URL

**File:** `src/modules/fireblock/fireblock.service.ts` line 43

```typescript
// Before:
private readonly baseURL = 'https://api.fireblocks.io';

// After:
private readonly baseURL = process.env.FIREBLOCKS_BASE_URL || 'https://api.fireblocks.io';
```

### 3.2 Stellar Horizon URL

**File:** `src/modules/stellar/stellar.service.ts` line 157

```typescript
// Before:
const server = new Horizon.Server('https://horizon.stellar.org');

// After:
const server = new Horizon.Server(
  process.env.STELLAR_HORIZON_URL || 'https://horizon.stellar.org',
);
```

### 3.3 Fireblocks secret key

The service reads an RSA private key from `./public/fireblock/fireblocks_secret.key` to sign Fireblocks API JWTs. For the virtual-backed instance:

- **Option A (simple):** Use a dummy key pair. The virtual service ignores JWT signatures anyway. Generate a throwaway RSA key for the virtual instance.
- **Option B (high-fidelity):** The virtual service validates the RS256 JWT using the corresponding public key. This verifies that the fireblock service's `generateJwt()` logic is correct. More work, but catches auth bugs.

Recommendation: **Option A for initial rollout**, Option B as a future hardening step.

---

## 4. Virtual Endpoints to Implement

These are the Fireblocks API endpoints that the service-virtualization platform must implement to support the fireblock wrapper.

### 4.1 Fireblocks API endpoints

#### `POST /v1/transactions`

**Called by:** `sendTokens`, `transferVaultTokens`, `signTransaction`, `permit2`

This is the most important endpoint. It handles four different `operation` types:

| Operation | Flow | Request shape |
|---|---|---|
| `TRANSFER` (ONE_TIME_ADDRESS) | Send tokens to external address | `{ operation, source: { type: "VAULT_ACCOUNT", id }, destination: { type: "ONE_TIME_ADDRESS", oneTimeAddress: { address, tag } }, amount, assetId }` |
| `TRANSFER` (VAULT_ACCOUNT) | Vault-to-vault transfer | `{ operation, source: { type: "VAULT_ACCOUNT", id }, destination: { type: "VAULT_ACCOUNT", id }, amount, assetId }` |
| `TYPED_MESSAGE` | Sign EIP-712 message | `{ operation, source: { type: "VAULT_ACCOUNT", id }, assetId, extraParameters: { rawMessageData: { messages: [{ content, type: "EIP712" }] } } }` |
| `CONTRACT_CALL` | Permit2 ERC-20 approval | `{ operation, source: { type: "VAULT_ACCOUNT", id }, destination: { type: "ONE_TIME_ADDRESS", oneTimeAddress: { address } }, amount: "0", assetId, extraParameters: { contractCallData } }` |

**Virtual response:**

```json
{
  "id": "<generated-uuid>",
  "status": "SUBMITTED"
}
```

The virtual service should:
1. Accept any valid-shaped request body
2. Return a generated transaction ID and `SUBMITTED` status
3. Store the transaction in the `entities` table (entity_type: `fireblocks_transaction`) so it can be queried later via `GET /v1/transactions/:txId`

#### `GET /v1/vault/accounts_paged`

**Called by:** `listWallets`

**Virtual response:**

```json
{
  "accounts": [
    {
      "id": "0",
      "name": "Default",
      "hiddenOnUI": false,
      "assets": [
        {
          "id": "ETH",
          "total": "1.5",
          "available": "1.5",
          "pending": "0",
          "frozen": "0",
          "lockedAmount": "0",
          "blockHeight": "19000000"
        }
      ]
    }
  ],
  "paging": { "before": null, "after": null }
}
```

The virtual service should return a configurable set of vault accounts. Seed data can define which vaults and assets exist.

#### `GET /v1/vault/accounts/:vaultAccountId/:assetId/addresses_paginated`

**Called by:** `getAddress`

**Virtual response:**

```json
{
  "addresses": [
    {
      "assetId": "ETH",
      "address": "0x1234567890abcdef1234567890abcdef12345678",
      "description": "",
      "tag": "",
      "type": "Permanent",
      "customerRefId": null,
      "addressFormat": "EIP55"
    }
  ],
  "paging": { "before": null, "after": null }
}
```

#### `GET /v1/transactions/:txId`

**Called by:** `getTransaction`

**Virtual response:** Return the transaction entity stored when `POST /v1/transactions` was called, with a status progression:

```json
{
  "id": "<txId>",
  "status": "COMPLETED",
  "subStatus": "CONFIRMED",
  "operation": "TRANSFER",
  "source": { "type": "VAULT_ACCOUNT", "id": "17" },
  "destination": { "type": "ONE_TIME_ADDRESS", "oneTimeAddress": { "address": "0x..." } },
  "amount": 100,
  "assetId": "USDC",
  "txHash": "0xfake...",
  "createdAt": 1711800000000,
  "lastUpdated": 1711800010000
}
```

#### `GET /v1/transactions` (with query params)

**Called by:** `getTransactionByHash`

Query params may include: `txHash`, `status`, `sourceType`, `destType`, `assets`, `after`, `before`, `limit`, `orderBy`, `sort`.

**Virtual response:** Return an array of matching transaction entities. Filter by query params against stored entities.

### 4.2 Stellar Horizon endpoint

#### `POST /stellar/submit` (or the Horizon transaction submission path)

The Stellar SDK posts to `{horizonUrl}/transactions` with `tx` as a form-encoded body field.

**Virtual response:**

```json
{
  "hash": "<generated-hash>",
  "ledger": 12345678,
  "envelope_xdr": "<echo-back-input>",
  "result_xdr": "AAAAAAAAAGQAAAAAAAAAAQAAAAAAAAABAAAAAAAAAAA=",
  "result_meta_xdr": "...",
  "paging_token": "12345678"
}
```

The virtual service should accept any XDR and return a success response. It does not need to validate the Stellar transaction — the fireblock service already validates source account matching before submission.

---

## 5. Authentication Handling

### 5.1 Fireblocks RS256 JWT (fireblock service -> virtual service)

Every request the fireblock service makes to the Fireblocks API includes:
- `Authorization: Bearer <RS256-signed-JWT>`
- `X-API-Key: <api-key>`

The JWT payload contains: `uri`, `nonce`, `iat`, `exp` (30s), `sub` (api key), `bodyHash` (SHA-256 of request body).

**For the virtual service:** Ignore both headers. Accept all requests regardless of auth. The virtual service is not a security boundary — it's a test double.

**Future hardening (optional):** Validate the JWT signature using the public key counterpart. This would verify that the fireblock service's `generateJwt()` correctly signs requests — catching bugs like wrong algorithm, missing fields, or body hash mismatches.

### 5.2 App JWT (callers -> fireblock service)

This auth layer is between AlfredPay services and the fireblock wrapper. It uses the `apps_fireblock` database table. The virtual-backed fireblock instance needs seed data in its PostgreSQL:

```sql
INSERT INTO apps_fireblock (id, name, key) VALUES
  (1, 'penny-api', 'test-api-key-for-virtual-instance');
```

This is a fireblock database concern, not a service-virtualization concern. The virtual service never sees this auth — it happens upstream.

---

## 6. State Management

### 6.1 Transaction lifecycle

The real Fireblocks API tracks transaction states: `SUBMITTED -> QUEUED -> PENDING_SIGNATURE -> BROADCASTING -> CONFIRMING -> COMPLETED` (or `FAILED`, `CANCELLED`, etc.).

For the virtual service, a simplified model is sufficient:

```
POST /v1/transactions → creates entity with status SUBMITTED
                        (optionally auto-transitions to COMPLETED after a delay)

GET /v1/transactions/:txId → returns current status
```

The virtual service can use its existing `entities` table:
- `entity_type`: `fireblocks_transaction`
- `entity_ref`: the transaction ID
- `state`: `SUBMITTED`, `COMPLETED`, `FAILED`
- `data`: JSON blob with the full transaction details

**Auto-completion:** Optionally schedule a self-callback (like AiPrise does) to transition the transaction from `SUBMITTED` to `COMPLETED` after a configurable delay. This simulates real Fireblocks processing time.

### 6.2 Vault and address data

Vault accounts and addresses are read-only lookups. The virtual service can return static/canned data or allow seeding via the control plane:

```
POST /control/seed-vaults (with vault definitions)
GET /v1/vault/accounts_paged → returns seeded vaults
```

---

## 7. What the Virtual Service Does NOT Need to Simulate

| Fireblocks capability | Why we skip it |
|---|---|
| Multi-sig / approval policies | The fireblock service doesn't use approval flows — it submits and gets back a tx ID |
| Gas station / fee estimation | Not called by the fireblock service |
| Webhooks / event notifications | The fireblock service doesn't register any Fireblocks webhook listeners |
| Staking, NFTs, DeFi | Not used |
| Sandbox environment (`sandbox.fireblocks.io`) | We're replacing the API entirely, not using Fireblocks' own sandbox |

---

## 8. Rollout Plan

### Phase 1: Code changes (fireblock repo)

- Make `FIREBLOCKS_BASE_URL` env-configurable (1 line, `fireblock.service.ts`)
- Make `STELLAR_HORIZON_URL` env-configurable (1 line, `stellar.service.ts`)
- Generate a dummy RSA key pair for the virtual instance
- Zero impact on production (defaults remain unchanged)

### Phase 2: Virtual endpoints (service-virtualization repo)

Implement in order of usage frequency:

1. `POST /v1/transactions` — covers `sendTokens`, `transferVaultTokens`, `signTransaction`, `permit2`
2. `GET /v1/transactions/:txId` — covers `getTransaction`
3. `GET /v1/transactions` (with query params) — covers `getTransactionByHash`
4. `GET /v1/vault/accounts_paged` — covers `listWallets`
5. `GET /v1/vault/accounts/:id/:asset/addresses_paginated` — covers `getAddress`
6. `POST /transactions` (Stellar Horizon format) — covers `signAndOptionallyBroadcast`

### Phase 3: Deploy virtual-backed instance

- Deploy second fireblock instance pointing at virtual service
- Seed `apps_fireblock` table with test app credentials
- Seed vault/address data via control plane
- Route local dev and CI to `fireblock-virtual.alfredpay.internal`

### Phase 4: Validate

- Run the same operations against both instances, compare response shapes
- Verify transaction records are created in the virtual-backed instance's DB
- Confirm no regressions on the production instance

---

## 9. Impact on Service Virtualization Platform

### New route group

The virtual service would gain a new route prefix: `/v1/` for Fireblocks-faithful endpoints (mirroring the real API path structure). This is the same pattern used for AiPrise (`/api/v1/verify/*`).

### Namespace isolation

Like AiPrise, the Fireblocks-faithful endpoints would use a default namespace (`__fireblocks__`) when the caller (fireblock service) doesn't pass `X-Test-Namespace`. For CI/CD pipelines, the fireblock service could be configured to forward a namespace header.

### No webhook complexity

Unlike AiPrise, there is no callback delivery problem. All Fireblocks API calls are synchronous. This makes Fireblocks virtualization significantly simpler — no `pending_callbacks`, no `fire-callbacks.php`, no callback URL rewriting.

### Estimated scope

| Component | Files | Effort |
|---|---|---|
| `FireblocksController.php` | 1 new controller | Medium — 6 route handlers |
| `FireblocksService.php` | 1 new service | Medium — transaction CRUD, vault data |
| Seed data / control plane | Extend existing control plane | Small — add vault/address seeding endpoints |
| Fireblock repo changes | 2 lines changed | Trivial |

---

## Appendix A: Fireblocks API Auth — JWT Structure

For reference, the RS256 JWT the fireblock service sends to the Fireblocks API:

```
Header: { "alg": "RS256", "typ": "JWT" }
Payload: {
  "uri": "/v1/transactions",           // API path
  "nonce": "550e8400-e29b-...",         // UUID v4
  "iat": 1711800000,                    // issued at (epoch seconds)
  "exp": 1711800030,                    // expires in 30 seconds
  "sub": "569f1h81-090b-...",           // API_KEY env var
  "bodyHash": "a1b2c3d4..."            // SHA-256 hex of JSON.stringify(body)
}
Signed with: RSA private key from ./public/fireblock/fireblocks_secret.key
Sent as: Authorization: Bearer <token> + X-API-Key: <api-key>
```

## Appendix B: Key Source Files

| File | What it does |
|---|---|
| `src/modules/fireblock/fireblock.service.ts` | All Fireblocks API calls, JWT generation, transaction DB writes. Hardcoded `baseURL` at line 43. |
| `src/modules/fireblock/fireblock.controller.ts` | Route definitions, guard assignments, DTO binding |
| `src/modules/stellar/stellar.service.ts` | Stellar XDR parsing, signing, Horizon submission. Hardcoded Horizon URL at line 157. |
| `src/common/auth/app-auth.service.ts` | App JWT verification against `apps_fireblock` table |
| `src/common/auth/jwt-app.guard.ts` | NestJS guard that enforces `token` + `app_name` headers |
| `src/modules/fireblock/helper/network.ts` | `resolveChain()` — maps network names to viem chain objects |
| `src/modules/fireblock/entitites/transactions-fireblocks-refund.entity.ts` | `transaction_fireblocks` TypeORM entity |
| `src/modules/fireblock/entitites/apps_fireblock.entity.ts` | `apps_fireblock` TypeORM entity (app credentials) |
| `public/fireblock/fireblocks_secret.key` | RSA private key for Fireblocks API JWT signing |
