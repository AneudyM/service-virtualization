# Fireblocks / Stellar Horizon API Contract Reference

Derived from source code analysis of `ExperimentRepos/alfred-payments/fireblock`. No Fireblocks SDK is used; all calls are manual HTTP with RS256 JWT auth.

**Last verified:** 2026-04-12

---

## External APIs Virtualized

| API | Real URL | Virtual URL | Called By |
|-----|----------|-------------|-----------|
| **Fireblocks REST API** | `https://api.fireblocks.io` | `http://service-virtualization` | fireblock container |
| **Stellar Horizon** | `https://horizon.stellar.org` | `http://service-virtualization` | fireblock container (Stellar SDK) |

---

## Authentication

The fireblock service signs every Fireblocks API request with an RS256 JWT:

```
Authorization: Bearer {jwt}
X-API-Key: {API_KEY}
Content-Type: application/json
```

**JWT payload:**
```json
{
  "uri": "/v1/transactions",
  "nonce": "uuid-v4",
  "iat": 1234567890,
  "exp": 1234567920,
  "sub": "{API_KEY}",
  "bodyHash": "sha256-of-request-body"
}
```

The virtual service accepts but does not validate JWT signatures.

---

## Fireblocks API Endpoints

### POST /v1/transactions

Create a transaction. Supports three operation types.

**Operation: TRANSFER (to external address)**
```json
{
  "operation": "TRANSFER",
  "source": { "type": "VAULT_ACCOUNT", "id": "17" },
  "destination": {
    "type": "ONE_TIME_ADDRESS",
    "oneTimeAddress": { "address": "0xabc...", "tag": "" }
  },
  "amount": "100.50",
  "assetId": "USDC"
}
```

**Operation: TRANSFER (vault-to-vault)**
```json
{
  "operation": "TRANSFER",
  "source": { "type": "VAULT_ACCOUNT", "id": "17" },
  "destination": { "type": "VAULT_ACCOUNT", "id": "19" },
  "amount": "50",
  "assetId": "USDC"
}
```

**Operation: TYPED_MESSAGE (EIP712 signing)**
```json
{
  "operation": "TYPED_MESSAGE",
  "source": { "type": "VAULT_ACCOUNT", "id": "17" },
  "assetId": "ETH_TEST6",
  "extraParameters": {
    "rawMessageData": {
      "messages": [{ "content": {}, "type": "EIP712" }]
    }
  }
}
```

**Operation: CONTRACT_CALL (permit2)**
```json
{
  "operation": "CONTRACT_CALL",
  "source": { "type": "VAULT_ACCOUNT", "id": "17" },
  "destination": {
    "type": "ONE_TIME_ADDRESS",
    "oneTimeAddress": { "address": "0x..." }
  },
  "amount": "0",
  "assetId": "ETH_TEST6",
  "extraParameters": { "contractCallData": "0x..." }
}
```

**Response** (fields used by fireblock service):
```json
{
  "id": "vrt_fb_abc123...",
  "status": "SUBMITTED"
}
```

Status values: `SUBMITTED`, `COMPLETED` (for TYPED_MESSAGE).

**Source:** `fireblock.service.ts` lines 115-200, 202-277, 389-439, 441-507

---

### GET /v1/vault/accounts_paged

List all vault accounts with asset balances.

**Response:**
```json
{
  "accounts": [
    {
      "id": "17",
      "name": "AlfredPay Main",
      "hiddenOnUI": false,
      "autoFuel": false,
      "assets": [
        { "id": "USDC", "total": "1000.000000", "available": "1000.000000", "pending": "0", "frozen": "0" }
      ]
    }
  ],
  "paging": { "before": "", "after": "" }
}
```

**Source:** `fireblock.service.ts` line 284

---

### GET /v1/vault/accounts/{vaultAccountId}/{assetId}/addresses_paginated

Get deposit addresses for a vault + asset pair.

**Response:**
```json
{
  "addresses": [
    {
      "assetId": "USDC",
      "address": "0xbc6ecc8c9c218850dc99ae11d2d780a0bb76d7ec",
      "tag": "",
      "description": "",
      "type": "Permanent",
      "legacyAddress": "",
      "bip44AddressIndex": 0
    }
  ],
  "paging": { "before": "", "after": "" }
}
```

**Source:** `fireblock.service.ts` line 319

---

### GET /v1/transactions/{txId}

Get a specific transaction by Fireblocks ID.

**Response:** Full transaction object (same fields as creation response plus `txHash`, `createdAt`, `lastUpdated`, `source`, `destination`, `amount`, `assetId`).

**Source:** `fireblock.service.ts` line 344

---

### GET /v1/transactions?txHash=...&assets=...&status=...&limit=...&sourceId=...

List transactions with filters.

**Query parameters:** `txHash`, `assets`, `limit`, `status`, `after`, `before`, `sourceId`

**Response:** Array of transaction objects.

**Source:** `fireblock.service.ts` line 373

---

## Stellar Horizon

### POST /transactions

Submit a signed Stellar XDR envelope.

**Request:** `{ "tx": "base64-xdr-envelope" }`

**Response:**
```json
{
  "hash": "hex-string-64",
  "ledger": 58963579,
  "envelope_xdr": "...",
  "result_xdr": "AAAAAAAAAGQAAAAAAAAAAQAAAAAAAAABAAAAAAAAAAA=",
  "successful": true
}
```

**Source:** `stellar.service.ts` line 157

---

## Known Vault Accounts

| ID | Name | Usage |
|----|------|-------|
| 17 | AlfredPay Main | Primary vault, USDC + XLM + ETH |
| 19 | AlfredPay Stellar | Stellar operations |
| 20 | AlfredPay Payments | Payment disbursements |

---

## Environment Variables (fireblock container)

```
FIREBLOCKS_BASE_URL=http://service-virtualization    # was hardcoded to https://api.fireblocks.io
STELLAR_HORIZON_URL=http://service-virtualization     # was hardcoded to https://horizon.stellar.org
API_KEY=virtual-fireblocks-api-key
STELLAR_SECRET=                                       # empty for local (no real Stellar signing)
```

## Code Changes Made

Two one-line changes in the fireblock service to make URLs env-configurable:
1. `fireblock.service.ts:43` -- `process.env.FIREBLOCKS_BASE_URL || 'https://api.fireblocks.io'`
2. `stellar.service.ts:157` -- `process.env.STELLAR_HORIZON_URL || 'https://horizon.stellar.org'`
