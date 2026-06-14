# XTransfer Integration Workflow

**Last updated:** 2026-04-13

---

## Architecture

XTransfer is a **client** of AlfredPay, not a provider. AlfredPay exposes a v2 payout API that XTransfer calls to execute cross-border payments. The actual money movement goes through the existing ramp infrastructure.

```
XTransfer (external client)
    │
    ▼ api-key + api-secret auth (ServiceGuard)
penny-api-restricted (v2 API, port 3002)
    │
    ▼ internal calls
penny-api (port 3003, payout entities + DB)
    │
    ▼ payout processing via cron jobs
rampas-penny-api (cron: sendpayOutXTransferMxn, sendpayOutXTransferArs, sendSwapXTransfer)
    │
    ├──▶ ramps-mexico → Bankaool (MXN / SPEI payouts)
    ├──▶ rampas-brasil → Transfero (BRL / PIX payouts)
    └──▶ ... other country ramps as needed
```

---

## Authentication

XTransfer authenticates via headers, not JWT:

```
api-key: <xtransfer-api-key>
api-secret: <xtransfer-api-secret>
Content-Type: application/json
```

Validated by `ServiceGuard` against database credentials. The guard extracts `businessId` from the matched credentials and injects it into the request.

---

## API Endpoints (all on penny-api-restricted, prefix /api/v2)

### Main Account Management

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/customers/:currency/:customerId/main-account` | Create a receiving account (MXN, ARS, USD) |
| GET | `/main-accounts/:accountId` | Get account details (holder, bank info, capabilities) |
| GET | `/main-accounts/:accountId/balance` | Get available, pending, blocked balances |

### Single Payout

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/payout/create` | Create single payout (PENDING) |
| PATCH | `/payout/update` | Update status (PROCESSING, SETTLED, FAILED, CANCELLED) |
| GET | `/payout/query/:requestReferenceId` | Get payout by reference ID |
| GET | `/payout/list` | List payouts with filters |

### Batch Payout

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/payout/batch` | Create batch with items + paymentReason |
| POST | `/payout/batch/:batchId/approve` | Approve batch |
| POST | `/payout/batch/:batchId/execute` | Execute (creates individual Payouts per item) |
| PATCH | `/payout/batch/:batchId` | Update batch status |
| PATCH | `/payout/batch/:batchId/items/:itemId` | Update individual batch item |
| GET | `/payout/batch/:batchId` | Get batch details with items |

### Refund

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/refund/create` | Create refund against a payin systemReference |
| GET | `/refund/detail?refundRequestId=` | Get refund status |

### Internal Transfer

| Method | Path | Purpose |
|--------|------|---------|
| POST | `/transfers/internal` | Transfer between main accounts |
| PATCH | `/transfers/update` | Update transfer status |
| GET | `/transfers` | List transfers |
| GET | `/transfers/:identifier` | Get transfer details |

---

## Core Flows

### 1. Main Account Setup

XTransfer creates a receiving account for a customer:

```
POST /api/v2/customers/MXN/{customerId}/main-account
→ { statusCode: "1000", data: { accountId: "MA-0001" } }

GET /api/v2/main-accounts/MA-0001/balance
→ { data: { available: "1000.00", pending: "0.00", blocked: "0.00" } }
```

Supported currencies: MXN, ARS, USD.

### 2. Single Payout (send money to a beneficiary)

```
POST /api/v2/payout/create
{
  "requestReferenceId": "XT-PAY-001",
  "currency": "MXN",
  "amount": 5000.00,
  "paymentRailType": "local",
  "paymentRailName": "SPEI",
  "payerAccountNumber": "MA-0001",
  "transactionType": "B2B",
  "beneficiaryDetail": {
    "name": "Juan Perez",
    "accountIdentifier": {
      "type": "AccountNumber",
      "value": "072180012345678907"
    },
    "bankDetail": {
      "name": "Banorte",
      "identifier": { "bankCode": "072" },
      "addressCountry": "MX"
    }
  },
  "metadata": { "purpose": "Supplier payment" }
}
```

**State machine:** PENDING → PROCESSING → SETTLED (or FAILED/CANCELLED)

Processing: rampas-penny-api cron (`sendpayOutXTransferMxn`) picks up PENDING payouts → ramps-mexico → Bankaool SPEI transfer → settlement webhook updates status to SETTLED.

### 3. Batch Payout

```
POST /api/v2/payout/batch
{ "paymentReason": "Monthly suppliers", "currency": "MXN", "items": [...] }
→ { data: { batchId: "uuid", status: "CREATED" } }

POST /api/v2/payout/batch/{batchId}/approve
→ { data: { status: "APPROVED" } }

POST /api/v2/payout/batch/{batchId}/execute
→ Creates individual Payout per item
→ status: EXECUTING → COMPLETED | PARTIAL_FAILED | FAILED

GET /api/v2/payout/batch/{batchId}
→ Returns batch with items[] showing individual status, payoutId, errorMessage
```

**Batch state machine:** CREATED → APPROVED → EXECUTING → COMPLETED | PARTIAL_FAILED | FAILED | CANCELLED

### 4. Refund (against a PayIn)

```
POST /api/v2/refund/create
{ "systemReference": "payin-ref-001", "amount": 500.00 }
→ { data: { refundRequestId: "uuid", status: "PENDING" } }

GET /api/v2/refund/detail?refundRequestId={id}
→ { data: { status: "COMPLETED" } }
```

### 5. Internal Transfer

```
POST /api/v2/transfers/internal
{ "fromAccountId": "MA-0001", "toAccountId": "MA-0002", "amount": 1000.00, "currency": "MXN" }
→ { data: { transferId: "uuid", status: "PENDING" } }
```

---

## Webhook Events (AlfredPay → XTransfer)

Webhooks are sent to XTransfer's configured endpoint with HMAC-SHA256 signatures.

| Event | notificationType | When |
|-------|-------------------|------|
| `BATCH_COMPLETED` | PayoutBatch | All batch items reached terminal state |
| `BATCH_UPDATED` | PayoutBatch | Batch status changed |
| `BATCH_ITEM_UPDATED` | Payout | Individual item status changed |

**Webhook headers:**
```
hmac-signature: <HMAC-SHA256 of body with shared secret>
requestId: <uuid>
timestamp: <epoch ms>
```

---

## Response Format

**Success:**
```json
{
  "statusCode": "1000",
  "statusDescription": "Success",
  "data": { ... },
  "meta": { "total": 100, "page": 1, "limit": 20, "totalPages": 5 }
}
```

**Error codes:**
| Code | Meaning |
|------|---------|
| 1000 | Success |
| 2001 | Authentication error (missing/invalid api-key or api-secret) |
| 3001 | Duplicate reference ID (idempotency violation) |
| 3002 | Record not found (or belongs to different business) |
| 4000 | Validation failed (field-level errors) |
| 4001 | Validation error |

---

## Database Tables

### payout
Key columns: `id` (UUID), `business_id`, `request_reference_id` (unique), `currency`, `amount` (DECIMAL 20,6), `payment_rail_type` (local/global), `payment_rail_name` (SPEI/SWIFT/PIX/etc), `payer_account_number`, `transaction_type` (B2B/B2C), `beneficiary_name`, `account_identifier_type` (AccountNumber/IBAN), `account_identifier_value`, `bank_identifier` (JSONB), `status` (PENDING/PROCESSING/SETTLED/FAILED/CANCELLED), `metadata` (JSONB), `batch_id` (FK nullable).

### payout_batch
Key columns: `id` (UUID), `business_id`, `status` (CREATED/APPROVED/EXECUTING/COMPLETED/PARTIAL_FAILED/FAILED/CANCELLED), `total_amount`, `currency`, `payment_reason`, `metadata` (JSONB with items during lifecycle).

---

## Payment Rails Supported

**Local (domestic):**
- SPEI (Mexico)
- PIX (Brazil)
- ARS Bank Transfer (Argentina)

**Global (cross-border):**
- SWIFT, SEPA, FPS, ACH

---

## Local Stack Status

All services XTransfer needs are already deployed locally:
- penny-api-restricted (port 3002) -- v2 endpoints
- penny-api (port 3003) -- payout entities and DB
- rampas-penny-api (port 3007) -- XTransfer cron jobs
- ramps-mexico (port 3008) -- Bankaool for MXN SPEI
- Bankaool virtual service -- form-urlencoded fix applied 2026-04-12

**Not yet done:**
- API credentials seeding for XTransfer client in database
- Main account record for test business
- Cucumber test suite execution (7 feature files ready)

---

## Test Coverage (Cucumber)

Feature files at `Test_Automation_Infrastructure/alfredx-cms-e2e-playwright-cucumber/tests/features/api/`:
- `xtransfer_single_payout.feature`
- `xtransfer_batch_payout.feature`
- `xtransfer_main_account.feature`
- `xtransfer_refund.feature`
- `xtransfer_webhooks.feature`
- `xtransfer_payout_queries.feature`
- `xtransfer_batch_items.feature`

---

## Key Source Files

**Controllers:** `penny-api-restricted/src/modules/v2/payout/`, `main-account/`, `refund/`, `transfer/`
**Entities:** `penny-api/src/modules/v2/payout/entities/payout.entity.ts`, `payout-batch.entity.ts`
**Auth:** `penny-api-restricted/src/commons/guards/jwt-or-service.guard.ts`, `strategy/service.strategy.ts`
**Cron:** `rampas-penny-api/src/taskCronJob/taskCronJob.service.ts` (sendpayOutXTransferMxn, sendpayOutXTransferArs)
**PRD:** `XTransfer/PRD_ XTransfer FIAT Main Accounts.docx`
