# CMS Backend Virtualization

Status: first-pass local CMS backend surface for `cms-front` testing

As of: 2026-06-18

Aligned sources:
- `cms-front` staging: `a6fff28e96c49eb71dffafb51770d4bf04284088`
- `cms-backend` origin/staging: `dfd764644a8dab353c40f50226ef0c122f7d328f`

## Purpose

This virtual surface lets `cms-front` run against service-virtualization without the real `cms-backend`.

It is not a complete CMS backend implementation. It focuses on the Local Balances / Main Account and original-dashboard flows that were validated locally:
- password login and `/user/me`
- feature-flagged user shape
- Send Global dashboard data
- Local Balance accounts, balances, recipients, deposits, payouts, transfers
- legacy transaction and liquidation-address endpoints needed by the current UI

## Implementation

Files:
- `src/CmsBackend/CmsBackendService.php`
- `src/Controller/CmsBackendController.php`
- route registrations in `public/index.php`

Browser GUI:
- `GET /cms-backend?namespace=cms-front-local`
- `GET /control/cms-backend?namespace=cms-front-local`

The GUI reads and writes the same `cms_backend_state` entity used by the API routes. It can apply presets, reset a namespace, inspect accounts/balances/recipients, and save raw JSON state.

State model:
- Uses the existing service-virtualization `EntityManager`.
- Entity type: `cms_backend_state`
- Entity ref: `default`
- Namespace: `X-Test-Namespace`, `?namespace=`, or body `namespace`
- Fallback namespace for browser frontend calls: `cms-front-local`

## Deployed Dev Usage

When deployed to dev, `cms-front` can use service-virtualization as a drop-in replacement for unstable `cms-backend`.

For a local Vite `cms-front`, set:

```env
VITE_API_URL=https://virtual-services-dev.alfredpay.io
```

Then restart `cms-front`.

Request direction:

```text
Local browser / cms-front -> https://virtual-services-dev.alfredpay.io -> response to browser
```

The virtual CMS backend is request/response only. It should not initiate requests to a developer's local machine. If future scenarios need callback-style behavior, simulate those through service-virtualization control routes or callbacks that target service-virtualization itself.

Mock GUI:

```text
https://virtual-services-dev.alfredpay.io/cms-backend?namespace=cms-front-local
```

URL parts:

| Part | Meaning |
|---|---|
| `https://` | Protocol used by the deployed dev environment. |
| `virtual-services-dev.alfredpay.io` | Hostname for the Jenkins-deployed service-virtualization environment. |
| `/cms-backend` | Browser GUI route for the virtual CMS backend control page. |
| `?` | Starts the query string. |
| `namespace` | Service-virtualization state bucket selector. |
| `cms-front-local` | Namespace value. This matches the fallback namespace used when `cms-front` does not send a namespace header, query param, or body field. |

Use `cms-front-local` unless `cms-front` or a proxy is configured to send a different namespace through `X-Test-Namespace`, `?namespace=`, or request body `namespace`. The GUI and frontend must use the same namespace or they will read/write different mock state.

## Local Accounts

| Flow | Email | Password | Client key | Notes |
|---|---|---|---|---|
| Local Balances | `local-balances@alfredpay.test` | `password123` | `xtransfer` | Main-account user with `DASHBOARD_CUSTOMER`; avoids the frontend redirect from `/global/transactions` to `/dashboard`. |
| Original Flow | `original-flow@alfredpay.test` | `password123` | `alfred-cms` | Non-main-account user with `METRICS`; shows the legacy dashboard. |

## Control Routes

Use these from tests or setup scripts:

```http
GET  /cms-backend
GET  /control/cms-backend
GET  /control/cms-backend/state
POST /control/cms-backend/state
POST /control/cms-backend/reset
POST /control/cms-backend/presets/local-balance-happy
POST /control/cms-backend/presets/original-flow
POST /control/cms-backend/presets/duplicate-dashboard
POST /control/cms-backend/presets/no-recipients
POST /control/cms-backend/presets/kyb-pending
```

`POST /control/cms-backend/state` accepts either a full state object or `{ "state": { ... } }`. Use a unique namespace per Jenkins test run to avoid cross-test contamination.

## Frontend Wiring

Start `cms-front` with:

```powershell
$env:VITE_API_URL = "http://127.0.0.1:8080"
npm run dev -- --host 127.0.0.1 --port 5175
```

If service-virtualization is running in Docker, use a URL reachable from the browser and from the frontend container. Do not use `127.0.0.1` inside a container unless the service is inside that same container.

## Smoke Test

From the repo root:

```powershell
$env:CMS_BACKEND_VIRTUAL_URL = "http://127.0.0.1:8080"
$env:X_TEST_NAMESPACE = "cms-front-local"
node "D:\Code\Alfred_Repos\Projects\AlfredX\tools\cms-backend-virtualization-smoke.cjs"
```

Expected result:

```json
{"ok":true,"clientKey":"xtransfer"}
```

The smoke requires service-virtualization and its MySQL schema to be running.

## Supported CMS Routes

Auth and user:
- `POST /auth/passwordless-login`
- `POST /auth/password-login`
- `POST /auth/passwordless-token`
- `POST /auth/login-mfa`
- `POST /auth/login-with-recovery-code`
- `POST /auth/signup`
- `POST /auth/password-signup`
- `POST /auth/recovery-password-code`
- `POST /auth/change-password`
- `POST /auth/change-password-jwt`
- `GET /user/me`

Main Account V2:
- `GET /v2/main-accounts/{id}`
- `GET /v2/main-accounts/{id}/main-accounts`
- `GET /v2/main-accounts/{id}/balance`
- `GET /v2/customers/{id}/{currency}/main-account/balance`
- `POST /v2/quotes`
- `GET /v2/quotes/{idOrRef}`
- `POST /v2/transfers/internal`
- `PATCH /v2/transfers/update`
- `GET /v2/transfers`
- `GET /v2/transfers/{idOrRef}`
- `GET /v2/customers/va/{country}/{vaId}/deposit`
- `POST /v2/payin/create`
- `GET /v2/payin/query/{id}`
- `GET /v2/payin/list`
- `POST /v2/payout/create`
- `GET /v2/payout/query/{id}`
- `GET /v2/payout/list`

Legacy Send Global:
- `GET /transactions`
- `GET /transactions/customerId`
- `GET /transactions/searchByCustomer`
- `GET /transactions/searchByBankAccount`
- `GET /transactions/searchByTxId`
- `GET /transactions/logsByTxId`
- `POST /transactions/transfer`
- `POST /transactions/onramp`
- `POST /transactions/offramp`
- `POST /transactions/payment`
- `PUT /transactions/payment/cancel/{transactionId}`
- `GET /transactions/fiat-accounts`
- `POST /transactions/fiat-accounts`
- `GET /transactions/fiat-accounts/id`
- `POST /transactions/fiat-accounts/id`
- `PUT /transactions/fiat-accounts/default`
- `PUT /transactions/fiat-accounts/default/{customerId}`
- `DELETE /transactions/fiat-accounts/{fiatAccountId}`
- `DELETE /transactions/fiat-accounts/{fiatAccountId}/{customerId}`

Supporting CMS routes:
- `GET /liquidation-address`
- `POST /liquidation-address`
- `PUT /liquidation-address/default`
- `DELETE /liquidation-address`
- `GET /client`
- `GET /roles/allowed`
- `GET /profiles`
- `GET /api-keys/list/dev`
- `GET /api-keys/list/prod`
- `POST /api-keys/create/dev`
- `POST /api-keys/create/prod`

## Known Contract Drift

`cms-front` staging currently calls:

```http
GET /v2/customers/{id}/{currency}/main-account/balance
```

`cms-backend` `origin/staging` documents:

```http
GET /v2/main-accounts/{id}/balance
```

The virtual service supports both. Keep the alias until the frontend/backend route contract is reconciled.

## Gaps

- The implementation is backend-shape-faithful, not database-faithful to `cms-backend` internals.
- MFA, signup, password recovery, API key creation, and several admin surfaces are happy-path placeholders.
- Service-virtualization is now the single source of truth for the CMS mock. Do not maintain a separate Node mock for active testing.
- The virtual service does not yet proxy or compose with lower-level provider mocks such as Bankaool, ExchangeCopter, Fireblocks, or Circle; it returns CMS-level responses directly.
