# Local Docker KYC & KYB End-to-End Testing Guide

**Last updated:** 2026-03-25
**Status:** Fully functional — end-to-end KYC and KYB flows verified

---

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Quick Start](#quick-start)
4. [End-to-End KYC Flow](#end-to-end-kyc-flow)
5. [End-to-End KYB Flow](#end-to-end-kyb-flow)
6. [Virtual AiPrise Service](#virtual-aiprise-service)
   - [KYC API Endpoints](#kyc-individual-verification-priority-1)
   - [KYB API Endpoints](#kyb-business-verification)
   - [KYC Webhook Payload](#kyc-webhook-payload)
   - [KYB Webhook Payload](#kyb-webhook-payload)
   - [HMAC Authentication](#hmac-authentication)
   - [Auto-Completion](#auto-completion)
   - [Callback URL Rewrite](#callback-url-rewrite)
7. [Control Plane](#control-plane)
8. [Configuration Reference](#configuration-reference)
9. [Penny-API Integration Details](#penny-api-integration-details)
10. [Testing Recipes](#testing-recipes)
11. [Troubleshooting](#troubleshooting)
12. [File Reference](#file-reference)

---

## Overview

This setup provides a **complete local Docker environment** for testing the AlfredPay KYC
and KYB verification flows without any external dependencies. The virtual AiPrise service acts
as a faithful drop-in replacement for the real AiPrise API, allowing you to:

- Create **KYC** (individual) and **KYB** (business) verification sessions
- Auto-complete verifications with configurable outcomes (APPROVED, DECLINED, REVIEW)
- Receive HMAC-signed webhook callbacks matching the real AiPrise format
- Test the full penny-api → AiPrise → penny-api-restricted → penny-api webhook chain
- Inspect and control session state via the control plane

**Key results:**
- **KYC:** A customer's `statusKyc` transitions from `CREATED` → `COMPLETED` entirely
  within the local Docker network, with no calls to external servers.
- **KYB:** A business customer's `status` transitions from `UPDATE_REQUIRED` → `COMPLETED`
  with all business data (legalName, taxId, documents, relatedPersons) populated from the webhook.

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        Local Docker Network                                 │
│                                                                             │
│  ┌──────────────────┐        ┌──────────────────────────┐                   │
│  │  penny-api-       │        │  service-virtualization   │                  │
│  │  restricted       │        │  (Virtual AiPrise)        │                  │
│  │  :3002            │        │  :8080 (host) / :80 (int) │                  │
│  │                   │        │                            │                 │
│  │  Receives webhook │◄───────│  3. Fires HMAC-signed     │                 │
│  │  from virtual     │  POST  │     webhook callback      │                 │
│  │  AiPrise          │        │                            │                 │
│  │                   │        │  1. Receives session       │                 │
│  │  Validates HMAC   │        │     creation request       │                 │
│  │  Forwards to      │        │                            │                 │
│  │  penny-api        │        │  2. Auto-completes after   │                 │
│  └────────┬─────────┘        │     AIPRISE_AUTO_DELAY     │                 │
│           │                   │     seconds                │                 │
│           │ Forward           └──────────▲─────────────────┘                 │
│           │ webhook                      │                                   │
│           ▼                              │ POST /api/v1/verify/              │
│  ┌──────────────────┐                   │ get_user_verification_url          │
│  │  penny-api        │                   │                                   │
│  │  :3003            │───────────────────┘                                   │
│  │                   │  AIPRISE_URL=http://service-virtualization            │
│  │  Creates customer │                                                       │
│  │  Updates statusKyc│        ┌───────────────────┐                         │
│  │  on webhook       │        │  virtual-db        │                         │
│  │                   │        │  MySQL :3307        │                        │
│  └──────────┬───────┘        │  Sessions, callbacks│                        │
│             │                 └───────────────────┘                          │
│             │ Reads/writes                                                   │
│             ▼                 ┌───────────────────┐                          │
│  ┌──────────────────┐        │  PostgreSQL         │                        │
│  │  host.docker.     │        │  :5432 (host)       │                       │
│  │  internal         │◄──────│  axen_dev DB         │                       │
│  │                   │        │  Customers, config   │                       │
│  └──────────────────┘        └───────────────────┘                          │
└─────────────────────────────────────────────────────────────────────────────┘

KYC Data Flow:
  1. Client calls penny-api-restricted → penny-api → virtual AiPrise (get_user_verification_url)
  2. Virtual AiPrise creates session, schedules auto-complete
  3. After delay, auto-complete fires → HMAC-signed webhook → penny-api-restricted
  4. penny-api-restricted validates HMAC → forwards to penny-api (/webhook-url-session)
  5. penny-api processes webhook → updates individual_customers.statusKyc to COMPLETED

KYB Data Flow:
  1. Client calls penny-api (GET /:customerId/kyb/verification/url)
  2. penny-api → virtual AiPrise (POST get_business_verification_url)
  3. Virtual AiPrise creates KYB session, schedules auto-complete
  4. After delay, auto-complete fires → HMAC-signed webhook → penny-api-restricted
  5. penny-api-restricted forwards to penny-api (/webhook-url-session-kyb)
  6. penny-api processes KYB webhook → updates business_customers.status to COMPLETED
```

### Services

| Service                   | Container Name                 | Host Port | Internal Port | Image                          |
|---------------------------|--------------------------------|-----------|---------------|--------------------------------|
| Virtual AiPrise (PHP)     | `service-virtualization-local` | 8080      | 80            | `service-virtualization-local` |
| Virtual DB (MySQL)        | `virtual-db-local`             | 3307      | 3306          | `mysql:8.0`                    |
| penny-api (Node.js)       | `penny-api-local`              | 3003      | 3003          | `penny-api-local`              |
| penny-api-restricted      | `penny-api-restricted-local`   | 3002      | 3002          | `penny-api-restricted-local`   |
| PostgreSQL (host)         | —                              | 5432      | —             | —                              |

---

## Quick Start

### Prerequisites

- Docker Desktop with WSL 2 backend
- PostgreSQL running locally with `axen_dev` database (dev dump imported)
- Repo cloned with penny-api and penny-api-restricted present

### 1. Build Images

```powershell
# From Alfred_Repos root
cd D:\Code\Alfred_Repos

# Build service-virtualization
cd Test_Automation_Infrastructure\service-virtualization
docker build -f Dockerfile.local -t service-virtualization-local .

# Build penny-api and penny-api-restricted (via local-up.ps1 or manually)
cd ..\..\Penny_API_and_Restricted\penny-api
docker build -t penny-api-local .

cd ..\penny-api-restricted
docker build -t penny-api-restricted-local .
```

### 2. Start Services

```powershell
cd D:\Code\Alfred_Repos
docker compose -f docker-compose.local.yml up -d
```

### 3. Verify Health

```bash
# Virtual AiPrise service
curl http://localhost:8080/health

# penny-api
curl http://localhost:3003/api/v1/ping

# penny-api-restricted (via Docker healthcheck)
docker ps --filter name=penny-api-restricted-local
```

### 4. Test E2E KYC Flow

```bash
# Step 1: Request verification URL (creates AiPrise session)
curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/MX/url' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'

# Step 2: Wait 15-20 seconds for auto-completion + webhook delivery

# Step 3: Check KYC status (should be COMPLETED)
curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/{SUBMISSION_ID}/status' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'
```

### 5. Test E2E KYB Flow

```bash
# Step 1: Request KYB verification URL (creates AiPrise KYB session)
curl -s -X GET \
  'http://localhost:3003/api/v1/third-party-service/customers/{CUSTOMER_ID}/kyb/verification/url'

# Step 2: Wait 15-20 seconds for auto-completion + webhook delivery

# Step 3: Check business customer status in PostgreSQL
docker exec penny-api-local node -e "
const{Client}=require('pg');
const c=new Client({host:'host.docker.internal',port:5432,database:'axen_dev',user:'postgres',password:'YoUnGhAcK@32'});
c.connect().then(()=>c.query('SELECT status,\"verificationSessionId\",\"legalName\" FROM business_customers WHERE \"customerId\"=\'{CUSTOMER_ID}\'')).then(r=>{console.log(JSON.stringify(r.rows[0],null,2));c.end()}).catch(e=>{console.error(e.message);c.end()})
"
# Expected: status = "COMPLETED"
```

---

## End-to-End KYC Flow

### Flow Walkthrough

Here is exactly what happens when a KYC verification is triggered:

#### Step 1: Client Requests Verification URL
```
Client → penny-api-restricted (GET /kyc/{country}/url)
       → penny-api (GET /customers/{id}/kyc/{country}/url)
```

penny-api's `getUrlKycSession()` method:
1. Reads template config from `aiprise_configuration` table (by businessId + country)
2. Extracts `templateId`, `callbackUrl`, `eventCallbackUrl`
3. POSTs to virtual AiPrise: `POST /api/v1/verify/get_user_verification_url`
4. Stores the returned `verificationUrl` on `individual_customers`
5. Returns `verification_url` + `verification_session_id` + `submissionId`

#### Step 2: Virtual AiPrise Creates Session
```
penny-api → service-virtualization (POST /api/v1/verify/get_user_verification_url)
```

The virtual AiPrise service:
1. **Rewrites callback URLs** from external hosts to Docker-internal addresses
   - `https://penny-api-restricted-dev.alfredpay.io/...` → `http://penny-api-restricted-local:3002/...`
2. Creates an entity in MySQL with state `created`
3. Schedules a self-callback (auto-complete) after `AIPRISE_AUTO_DELAY` seconds
4. Returns `verification_session_id` + `verification_url` + `session_expiry_time`

#### Step 3: Auto-Completion (Background)
```
(after 10s) service-virtualization → self-callback → autoComplete()
```

The background callback firer (every 5 seconds):
1. Fires the pending self-callback to `POST /api/v1/verify/_internal/auto-complete/{sessionId}`
2. `autoComplete()` transitions the entity to `completed` state
3. Builds the full `UserVerificationResponseV2` webhook payload
4. Computes HMAC-SHA256 signature with `AIPRISE_HMAC_KEY`
5. Schedules the webhook callback to the (rewritten) `callback_url`

#### Step 4: Webhook Delivery
```
service-virtualization → penny-api-restricted (POST /aiprise/webhook-url-session)
```

The callback firer sends the webhook with:
- Body: Full AiPrise response (event_type, verification_result, id_info, etc.)
- Header: `X-HMAC-SIGNATURE: {hex-encoded HMAC-SHA256}`

#### Step 5: Webhook Processing
```
penny-api-restricted → validates HMAC → forwards to penny-api
penny-api → processes webhook → updates individual_customers
```

penny-api-restricted:
1. `AipriseGuard` extracts `X-HMAC-SIGNATURE` header
2. Computes expected signature with its `AIPRISE_ID_KEY` (same as virtual service's `AIPRISE_HMAC_KEY`)
3. Forwards the webhook body to penny-api

penny-api's `webhookUrlSession()`:
1. Checks `event_type` is `VERIFICATION_SESSION_COMPLETION` or `CASE_STATUS_UPDATE`
2. Finds individual_customer by `client_reference_id` (= customerId)
3. Extracts `aiprise_summary.verification_result` → maps via `StatusKycAiprise`
4. Updates `individual_customers`: statusKyc, verificationSessionId, aiPriseKycStatus, firstName, lastName, etc.

#### Final State
```sql
individual_customers SET
  statusKyc = 'COMPLETED',           -- was 'CREATED'
  aiPriseKycStatus = 'APPROVED',     -- from aiprise_summary.verification_result
  verificationSessionId = '...',      -- from webhook
  firstName = 'VIRTUAL_FIRST_NAME',   -- from id_info
  lastName = 'VIRTUAL_LAST_NAME',     -- from id_info
  country = 'MX'                      -- from id_info.issue_country_code
```

### Status Mapping

| AiPrise Result    | penny-api statusKyc | Description                   |
|-------------------|----------------------|-------------------------------|
| `APPROVED`        | `COMPLETED`          | KYC verification passed       |
| `DECLINED`        | `FAILED`             | KYC verification rejected     |
| `REVIEW`          | `IN_REVIEW`          | Manual review needed          |
| `UNKNOWN`         | `IN_REVIEW`          | Status not yet determined     |
| `UPDATE_REQUIRED` | `UPDATE_REQUIRED`    | Additional documents needed   |

---

## End-to-End KYB Flow

### Flow Walkthrough

Here is exactly what happens when a KYB verification is triggered:

#### Step 1: Client Requests KYB Verification URL
```
Client → penny-api (GET /third-party-service/customers/{customerId}/kyb/verification/url)
```

penny-api's `getVerificationBusinessUrl()` method:
1. Finds the `business_customers` record by `customerId`
2. Reads AiPrise config from `aiprise_configuration` table (by businessId + country, type `KYB`)
3. Builds callback URL: `${AIPRISE_CALLBACK_URL_BUSINESS}/api/v1/third-party-service/aiprise/webhook-url-session-kyb`
4. POSTs to virtual AiPrise: `POST /api/v1/verify/get_business_verification_url`
5. Extracts `business_onboarding_session_id` from returned URL
6. Stores `verificationUrl` and `verificationSessionId` on `business_customers`
7. Returns `verification_url` + `submissionId`

#### Step 2: Virtual AiPrise Creates KYB Session
```
penny-api → service-virtualization (POST /api/v1/verify/get_business_verification_url)
```

The virtual AiPrise service:
1. **Rewrites callback URL** from external hosts to Docker-internal addresses
2. Creates a KYB entity in MySQL with state `created`, entity_type `aiprise_kyb_session`
3. Schedules a self-callback (auto-complete-kyb) after `AIPRISE_AUTO_DELAY` seconds
4. Returns URL with `business_onboarding_session_id` query parameter

#### Step 3: Auto-Completion (Background)
```
(after 10s) service-virtualization → self-callback → autoCompleteKyb()
```

The background callback firer:
1. Fires the pending self-callback to `POST /api/v1/verify/_internal/auto-complete-kyb/{sessionId}`
2. `autoCompleteKyb()` transitions the entity to `completed` state
3. Builds the KYB-specific webhook payload (business_profile_result, documents, related_people, etc.)
4. Computes HMAC-SHA256 signature with `AIPRISE_HMAC_KEY`
5. Schedules the webhook callback to the (rewritten) `callback_url`

#### Step 4: Webhook Delivery
```
service-virtualization → penny-api-restricted (POST /aiprise/webhook-url-session-kyb)
```

The callback firer sends the webhook with:
- Body: Full KYB response (business_profile_result, documents, addresses, etc.)
- Header: `X-HMAC-SIGNATURE: {hex-encoded HMAC-SHA256}`

#### Step 5: Webhook Processing
```
penny-api-restricted → validates HMAC → forwards to penny-api
penny-api → processes KYB webhook → updates business_customers
```

penny-api-restricted:
1. `AipriseGuard` validates HMAC signature
2. Forwards the webhook body to penny-api at `/api/v1/third-party-service/customers/kyb/webhook-url-session`

penny-api's `webhookUrlSessionKyb()`:
1. Checks `business_profile_result` is present (the KYB equivalent of `event_type`)
2. Finds `business_customers` by `verificationSessionId` (from `verification_session_ids` array)
3. Maps `business_profile_result` → status via `StatusKybWebhook` mapping
4. Processes documents: maps `file_type` → entity fields via `typeDocuments` constant
5. Processes `related_people` → `relatedPersons` JSON array
6. Updates `business_customers` with legalName, taxId, countryCode, addresses, documents
7. Updates KYB status via `updateKybStatusByWebhook()`

#### Final State
```sql
business_customers SET
  status = 'COMPLETED',                    -- was 'UPDATE_REQUIRED'
  verificationSessionId = '...',            -- from webhook
  legalName = 'VIRTUAL_BUSINESS_NAME',      -- from webhook.name
  taxId = 'VIRTUAL-TAX-001',               -- from webhook.tax_identification_number
  countryCode = 'MX',                       -- from webhook.country_code
  addressCity = 'Virtual City',             -- from webhook.addresses[0]
  stateCode = 'VC',                         -- from webhook.addresses[0]
  proofAddress = 'virtual_proof_of_address.pdf',         -- from documents
  articlesIncorporation = 'virtual_articles_of_inc...',  -- from documents
  shareholderRegistry = 'virtual_shareholders_reg...',   -- from documents
  relatedPersons = '[{...}]'                -- from related_people
```

### KYB Status Mapping

| AiPrise `business_profile_result` | penny-api `business_customers.status` | Description                  |
|-----------------------------------|---------------------------------------|------------------------------|
| `APPROVED`                        | `COMPLETED`                           | KYB verification passed      |
| `DECLINED`                        | `FAILED`                              | KYB verification rejected    |
| `REVIEW`                          | `IN_REVIEW`                           | Manual review needed         |
| `UNKNOWN`                         | `IN_REVIEW`                           | Status not yet determined    |
| `UPDATE_REQUIRED`                 | `UPDATE_REQUIRED`                     | Additional documents needed  |

### KYB vs KYC — Key Differences

| Aspect                | KYC (Individual)                              | KYB (Business)                                |
|-----------------------|-----------------------------------------------|-----------------------------------------------|
| **Create endpoint**   | `POST get_user_verification_url`              | `POST get_business_verification_url`          |
| **Result endpoint**   | `GET get_user_verification_result/{id}`        | `GET get_business_verification_result/{id}`    |
| **Webhook URL**       | `.../webhook-url-session`                     | `.../webhook-url-session-kyb`                 |
| **Webhook trigger**   | `event_type` field                            | `business_profile_result` field               |
| **Customer lookup**   | `client_reference_id` → `individual_customers.id` | `verificationSessionId` → `business_customers.verificationSessionId` |
| **URL param**         | `verification_session_id`                     | `business_onboarding_session_id`              |
| **Callback URL**      | From `aiprise_configuration.callbackUrl` DB   | From `AIPRISE_CALLBACK_URL_BUSINESS` env var  |
| **DB table**          | `individual_customers`                        | `business_customers`                          |
| **Document mapping**  | N/A                                           | `typeDocuments` constant (SHAREHOLDERS_REGISTRY, etc.) |

---

## Virtual AiPrise Service

### API Endpoints

#### KYC Individual Verification (Priority 1)

##### POST /api/v1/verify/get_user_verification_url
Creates a verification URL session. This is the primary endpoint penny-api calls.

**Request:**
```json
{
  "template_id": "f17b5f61-aede-4c73-903d-2ac18a2a6ba1",
  "client_reference_id": "e755013c-a886-4a35-a271-1592c95d0faf",
  "callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session",
  "events_callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session",
  "redirect_uri": "popup_close",
  "user_data": {
    "first_name": "John",
    "last_name": "Doe",
    "identity": {
      "identity_country_code": "MX"
    }
  }
}
```

**Response:**
```json
{
  "message": "Success",
  "verification_session_id": "04a1d754-29ac-4225-947a-aa08a8c2b029",
  "verification_url": "http://localhost:8080/verify?verification_session_id=04a1d754-...",
  "session_expiry_time": "2026-04-24T20:51:12Z"
}
```

**Headers:**
- `X-API-KEY: <any value>` (accepted but not validated in virtual mode)
- `Content-Type: application/json`

**Behavior:**
1. Rewrites `callback_url` and `events_callback_url` to Docker-internal addresses
2. Creates entity with state `created` in MySQL
3. Schedules auto-completion after `AIPRISE_AUTO_DELAY` seconds (default: 10)
4. Returns verification URL that can be opened in a browser (stub page)

---

##### POST /api/v1/verify/run_user_verification
Runs a full API-driven verification (no URL needed). Same flow as above but for
server-to-server document submission.

**Request:** Same as `get_user_verification_url`
**Response:**
```json
{
  "verification_session_id": "session-uuid"
}
```

---

##### GET /api/v1/verify/get_user_verification_result/{sessionId}
Returns the full verification result in `UserVerificationResponseV2` format.

**Response (completed session):**
```json
{
  "aiprise_summary": {
    "verification_result": "APPROVED"
  },
  "status": "COMPLETED",
  "client_reference_id": "customer-uuid",
  "verification_session_id": "session-uuid",
  "template_id": "template-uuid",
  "created_at": 1774471602897,
  "environment": "SANDBOX",
  "id_info": {
    "result": "APPROVED",
    "status": "COMPLETED",
    "warnings": [],
    "id_type": "NATIONAL_ID",
    "id_number": "VIRTUAL-ID-001",
    "first_name": "VIRTUAL_FIRST_NAME",
    "last_name": "VIRTUAL_LAST_NAME",
    "full_name": "VIRTUAL_FIRST_NAME VIRTUAL_LAST_NAME",
    "birth_date": "1990-01-01",
    "gender": "M",
    "nationality": "MX",
    "nationality_code": "MX",
    "issue_country": "MX",
    "issue_country_code": "MX",
    "id_expiry_date": "2030-12-31",
    "id_issue_date": "2020-01-01",
    "address": {
      "full_address": "VIRTUAL_ADDRESS",
      "parsed_address": {
        "address_street_1": "Virtual Street 123",
        "address_city": "Virtual City",
        "address_state": "VC",
        "address_country": "MX",
        "address_zip_code": "00000"
      }
    },
    "section_id": "uuid"
  },
  "face_match_info": {
    "result": "APPROVED",
    "status": "COMPLETED",
    "warnings": [],
    "face_match_score": 98.5,
    "section_id": "uuid"
  },
  "face_liveness_info": {
    "result": "APPROVED",
    "status": "COMPLETED",
    "warnings": [],
    "section_id": "uuid"
  },
  "aml_info": {
    "result": "APPROVED",
    "status": "COMPLETED",
    "warnings": [],
    "num_hits": 0,
    "entity_hits": [],
    "section_id": "uuid"
  },
  "user_input": {
    "user_data": {}
  }
}
```

**Status Mapping:**

| Entity State  | AiPrise Run Status |
|---------------|---------------------|
| `created`     | `NOT_STARTED`       |
| `processing`  | `RUNNING`           |
| `completed`   | `COMPLETED`         |

---

#### KYB Business Verification

##### POST /api/v1/verify/get_business_verification_url
Creates a KYB verification URL session. This is the primary endpoint penny-api calls for business verification.

**Request:**
```json
{
  "template_id": "d0cd41c6-11ad-4d03-b446-3198d6e940c5",
  "client_reference_id": "2ed731f0-e0ea-487d-ba37-c5bdbfe8a006",
  "callback_url": "http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session-kyb",
  "events_callback_url": "http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session-kyb",
  "user_data": {
    "business_name": "Test Corp",
    "country_code": "MX",
    "tax_id": "TAX-12345"
  }
}
```

**Response:**
```json
{
  "message": "Success",
  "verification_session_id": "18085c8d-bf99-4315-b5b9-bd5f0351a949",
  "verification_url": "http://localhost:8080/verify?business_onboarding_session_id=18085c8d-...",
  "session_expiry_time": "2026-04-24T22:37:18Z"
}
```

**Behavior:**
1. Rewrites `callback_url` to Docker-internal addresses (same as KYC)
2. Creates a KYB entity with `entity_type = aiprise_kyb_session` in MySQL
3. Schedules auto-completion after `AIPRISE_AUTO_DELAY` seconds
4. Returns URL with **`business_onboarding_session_id`** param (NOT `verification_session_id`)

> **Important:** penny-api extracts the session ID from the URL's `business_onboarding_session_id`
> query parameter. The virtual service ensures this parameter matches `verification_session_id`.

---

##### GET /api/v1/verify/get_business_verification_result/{sessionId}
Returns the KYB verification result including `business_profile_result`.

**Response (completed session):**
```json
{
  "business_profile_result": "APPROVED",
  "verification_session_id": "18085c8d-bf99-4315-b5b9-bd5f0351a949",
  "client_reference_id": "2ed731f0-e0ea-487d-ba37-c5bdbfe8a006",
  "name": "VIRTUAL_BUSINESS_NAME",
  "country_code": "MX",
  "tax_identification_number": "VIRTUAL-TAX-001",
  "business_info": { "tax_id": "VIRTUAL-TAX-001", "country_code": "MX", "warnings": [] },
  "addresses": [{
    "address_street_1": "Virtual Business St 456",
    "address_city": "Virtual City",
    "address_state": "VC",
    "address_zip_code": "00000"
  }],
  "documents": [
    { "file_type": "SHAREHOLDERS_REGISTRY", "file_name": "virtual_shareholders_registry.pdf", "file_s3_url": "https://..." },
    { "file_type": "ADDRESS_PROOF_DOCUMENT", "file_name": "virtual_proof_of_address.pdf", "file_s3_url": "https://..." },
    { "file_type": "ARTICLES_OF_INCORPORATION", "file_name": "virtual_articles_of_incorporation.pdf", "file_s3_url": "https://..." }
  ],
  "related_people": [{
    "person_reference_id": "uuid",
    "first_name": "VIRTUAL_OFFICER_FIRST",
    "last_name": "VIRTUAL_OFFICER_LAST",
    "email": "officer@virtual-business.test",
    "birth_date": "1985-06-15",
    "identity": { "identity_number": "...", "identity_document_front": "...", "identity_document_back": "..." },
    "kyc": { "verification_session_id": "officer-session-uuid", "verification_result": "APPROVED" }
  }],
  "aml_info": { "warnings": [] },
  "aiprise_summary": { "verification_result": "APPROVED" }
}
```

---

##### POST /api/v1/verify/_internal/auto-complete-kyb/{sessionId}
**Internal endpoint** — not part of real AiPrise API. Auto-completes KYB sessions and fires webhooks.

---

#### Business KYB API Stubs (Lower Priority)

These return stub responses for API-driven KYB flows (not used by penny-api's URL-based flow):

| Method | Endpoint                                              | Description                      |
|--------|-------------------------------------------------------|----------------------------------|
| POST   | `/api/v1/verify/create_business_profile`              | Create business profile          |
| POST   | `/api/v1/verify/add_business_document`                | Add business document            |
| POST   | `/api/v1/verify/add_business_officer`                 | Add business officer             |
| POST   | `/api/v1/verify/run_verification_for_business_officer`| Verify business officer          |
| POST   | `/api/v1/verify/run_verification_for_business_profile_id` | Verify business profile      |
| GET    | `/api/v1/verify/get_business_data_from_request/{id}`  | Get business data                |
| GET    | `/api/v1/verify/get_business_profile/{id}`            | Get business profile             |

---

### KYC Webhook Payload

When a session auto-completes (or is manually completed via control plane), the virtual
service fires a webhook to the stored `callback_url`. The payload matches the real AiPrise
`UserVerificationResponseV2` schema:

```json
{
  "client_reference_id": "e755013c-a886-4a35-a271-1592c95d0faf",
  "verification_session_id": "04a1d754-29ac-4225-947a-aa08a8c2b029",
  "event_type": "VERIFICATION_SESSION_COMPLETION",
  "verification_result": "APPROVED",
  "aiprise_summary": {
    "verification_result": "APPROVED"
  },
  "status": "COMPLETED",
  "template_id": "03e6e0d1-91a3-49e7-9cf7-d0dd1fa85a49",
  "created_at": 1774471885117,
  "environment": "SANDBOX",
  "id_info": {
    "result": "APPROVED",
    "status": "COMPLETED",
    "first_name": "VIRTUAL_FIRST_NAME",
    "last_name": "VIRTUAL_LAST_NAME",
    "birth_date": "1990-01-01",
    "id_type": "NATIONAL_ID",
    "id_number": "VIRTUAL-ID-001",
    "issue_country_code": "MX",
    "nationality_code": "MX",
    "...": "..."
  },
  "face_match_info": { "result": "APPROVED", "face_match_score": 98.5, "..." : "..." },
  "face_liveness_info": { "result": "APPROVED", "..." : "..." },
  "aml_info": { "result": "APPROVED", "num_hits": 0, "..." : "..." },
  "user_input": { "user_data": {} }
}
```

**Critical fields for penny-api processing:**

| Field                                 | Used By                          | Purpose                                      |
|---------------------------------------|----------------------------------|----------------------------------------------|
| `event_type`                          | `webhookUrlSession()` line 222   | **Must be present** or handler returns early  |
| `aiprise_summary.verification_result` | `webhookUrlSession()` line 221   | Maps to `statusKyc` via `StatusKycAiprise`    |
| `verification_result`                 | CASE_STATUS_UPDATE handler       | Top-level result for status updates           |
| `client_reference_id`                 | `webhookUrlSession()` line 240   | Finds individual_customer (= customerId)      |
| `verification_session_id`             | `webhookUrlSession()` line 220   | Stored on individual_customer                 |
| `id_info.*`                           | `webhookUrlSession()` lines 327+ | Extracts firstName, lastName, DOB, country    |

---

### KYB Webhook Payload

When a KYB session auto-completes, the webhook payload uses a different structure than KYC:

```json
{
  "business_profile_result": "APPROVED",
  "verification_session_id": "18085c8d-bf99-4315-b5b9-bd5f0351a949",
  "verification_session_ids": ["18085c8d-bf99-4315-b5b9-bd5f0351a949"],
  "client_reference_id": "2ed731f0-e0ea-487d-ba37-c5bdbfe8a006",
  "name": "VIRTUAL_BUSINESS_NAME",
  "country_code": "MX",
  "tax_identification_number": "VIRTUAL-TAX-001",
  "business_info": {
    "tax_id": "VIRTUAL-TAX-001",
    "country_code": "MX",
    "warnings": []
  },
  "addresses": [{
    "address_street_1": "Virtual Business St 456",
    "address_city": "Virtual City",
    "address_state": "VC",
    "address_zip_code": "00000"
  }],
  "website": "https://virtual-business.example.com",
  "documents": [
    { "file_type": "SHAREHOLDERS_REGISTRY", "file_name": "virtual_shareholders_registry.pdf", "file_s3_url": "https://..." },
    { "file_type": "ADDRESS_PROOF_DOCUMENT", "file_name": "virtual_proof_of_address.pdf", "file_s3_url": "https://..." },
    { "file_type": "ARTICLES_OF_INCORPORATION", "file_name": "virtual_articles_of_incorporation.pdf", "file_s3_url": "https://..." }
  ],
  "related_people": [{
    "person_reference_id": "uuid",
    "first_name": "VIRTUAL_OFFICER_FIRST",
    "last_name": "VIRTUAL_OFFICER_LAST",
    "email": "officer@virtual-business.test",
    "birth_date": "1985-06-15",
    "identity": {
      "identity_number": "VIRTUAL-OFFICER-ID-001",
      "identity_document_front": "https://virtual-service/docs/officer_id_front.jpg",
      "identity_document_back": "https://virtual-service/docs/officer_id_back.jpg"
    },
    "kyc": {
      "verification_session_id": "officer-session-uuid",
      "verification_result": "APPROVED"
    }
  }],
  "user_input": { "user_data": {} },
  "aml_info": { "warnings": [] },
  "aiprise_summary": { "verification_result": "APPROVED" }
}
```

**Critical fields for penny-api KYB processing:**

| Field                               | Used By                               | Purpose                                                |
|-------------------------------------|---------------------------------------|--------------------------------------------------------|
| `business_profile_result`           | `webhookUrlSessionKyb()` line 530     | **Must be present** — KYB trigger (like `event_type` for KYC) |
| `verification_session_ids`          | `webhookUrlSessionKyb()` line 540     | Array — finds `business_customers.verificationSessionId` |
| `name`                              | `addKybInfo`                          | Sets `business_customers.legalName`                    |
| `tax_identification_number`         | `addKybInfo`                          | Sets `business_customers.taxId`                        |
| `addresses[0].*`                    | `addKybInfo`                          | Sets addressCity, stateCode, addressStreetOne, etc.    |
| `country_code`                      | `addKybInfo`                          | Sets `business_customers.countryCode`                  |
| `documents[].file_type`             | `typeDocuments` mapping               | Maps to entity fields (see Document Mapping below)     |
| `related_people[]`                  | `addKybInfo`                          | Sets `business_customers.relatedPersons` (JSONB)       |
| `aiprise_summary.verification_result` | Status mapping                      | Maps to business_customers.status                      |

**Document Type Mapping (`typeDocuments` constant in penny-api):**

| `file_type` Value          | Entity Field (name)        | Entity Field (URI)           |
|----------------------------|----------------------------|------------------------------|
| `SHAREHOLDERS_REGISTRY`    | `shareholderRegistry`      | `shareholderRegistryUri`     |
| `ADDRESS_PROOF_DOCUMENT`   | `proofAddress`             | `proofAddressUri`            |
| `ARTICLES_OF_INCORPORATION`| `articlesIncorporation`    | `articlesIncorporationUri`   |

**Important: `user_input.user_data` must NOT contain `phone_number`** — penny-api maps this to
a `headquartersPhone` field that doesn't exist in the `BusinessCustomers` TypeORM entity.
The virtual service sends `user_data: {}` to avoid this issue.

---

### HMAC Authentication

The webhook uses HMAC-SHA256 to authenticate callbacks, matching AiPrise's real implementation.

**Signature computation:**
```
HMAC-SHA256(JSON.stringify(webhookPayload), HMAC_KEY) → hex → lowercase
```

**Header:** `X-HMAC-SIGNATURE: <hex-encoded signature>`

**Key chain:**

```
service-virtualization                     penny-api-restricted
  AIPRISE_HMAC_KEY ─────────────────────→  AIPRISE_ID_KEY
  "virtual-aiprise-test-key"               "virtual-aiprise-test-key"
         │                                         │
         │  Signs webhook payload                   │  Validates X-HMAC-SIGNATURE
         │  with HMAC-SHA256                        │  header against payload
         ▼                                         ▼
  X-HMAC-SIGNATURE: abc123...             crypto.createHmac('sha256', key)
                                            .update(Buffer.from(body, 'utf8'))
                                            .digest('hex').toLowerCase()
```

Both services must share the same key. This is configured via:
- `local-services.json` → `service-virtualization.env.AIPRISE_HMAC_KEY`
- `local-services.json` → `penny-api-restricted.env.AIPRISE_ID_KEY`

> **Note:** penny-api-restricted's HMAC validation (in `aiprise.strategy.ts`) has a bug
> where it returns `true` before actually comparing signatures. This means HMAC validation
> is effectively bypassed in the current codebase — but we still sign correctly for
> forward-compatibility when the bug is fixed.

---

### Auto-Completion

When a session is created, the virtual service automatically schedules its completion:

1. **Self-callback scheduled:** `POST /api/v1/verify/_internal/auto-complete/{sessionId}`
   - Sent to `APP_INTERNAL_URL` (port 80, inside the container)
   - Delay: `AIPRISE_AUTO_DELAY` seconds (default: 10)

2. **Background callback firer** runs every 5 seconds inside the container:
   ```bash
   (while true; do
       sleep 5
       php /var/www/html/bin/fire-callbacks.php 2>/dev/null || true
   done) &
   ```

3. **On auto-complete:** entity transitions to `completed` → webhook fires to `callback_url`

**Timeline for a typical request:**
```
T+0s    Session created, auto-complete callback scheduled for T+10s
T+5s    Callback firer runs — callback not yet due
T+10s   Callback becomes due (fire_at reached)
T+10s   Callback firer runs — fires self-callback → auto-complete
T+10s   Webhook callback scheduled (immediate, delay=0)
T+15s   Callback firer runs — fires webhook to penny-api-restricted
T+15s   penny-api-restricted receives webhook → forwards to penny-api
T+15s   penny-api processes webhook → statusKyc = COMPLETED
```

Actual delay is typically **10–20 seconds** depending on the 5-second poll cycle alignment.

---

### Callback URL Rewrite

**Problem:** penny-api reads `callback_url` from the `aiprise_configuration` table in
PostgreSQL, which contains URLs like:
```
https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session
```

These point to the real DEV server, not the local Docker container.

**Solution:** The virtual AiPrise service rewrites callback URLs at session creation time,
replacing the external host with the Docker-internal address.

**How it works:**
1. penny-api sends the callback URL from the DB to the virtual service
2. The virtual service checks `AIPRISE_CALLBACK_REWRITE_HOST` env var
3. If set, replaces the scheme+host+port of the callback URL, preserving the path
4. Stores the rewritten URL on the session entity

**Example:**
```
Input:   https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session
Rewrite: http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session
                                                └─── AIPRISE_CALLBACK_REWRITE_HOST ───┘
```

**Configuration:**
```json
{
  "AIPRISE_CALLBACK_REWRITE_HOST": "http://penny-api-restricted-local:3002"
}
```

When `AIPRISE_CALLBACK_REWRITE_HOST` is empty or unset, no rewriting occurs (passthrough mode).

---

## Control Plane

The control plane allows inspection and manual control of AiPrise sessions.

### KYC Control Plane

#### List All KYC Sessions
```bash
curl -s http://localhost:8080/control/aiprise/sessions | python -m json.tool
```

**Response:**
```json
{
  "error": false,
  "message": "ok",
  "data": [
    {
      "verification_session_id": "04a1d754-29ac-4225-947a-aa08a8c2b029",
      "client_reference_id": "e755013c-a886-4a35-a271-1592c95d0faf",
      "state": "completed",
      "verification_result": "APPROVED",
      "session_type": "url_session",
      "template_id": "03e6e0d1-91a3-49e7-9cf7-d0dd1fa85a49",
      "callback_url": "http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session",
      "created_at": "2026-03-25 20:51:12",
      "updated_at": "2026-03-25 20:51:25"
    }
  ]
}
```

#### Manually Complete a KYC Session
Force a session to complete with a specific outcome — useful for testing DECLINED, REVIEW, etc.

```bash
# Complete with DECLINED outcome
curl -s -X POST 'http://localhost:8080/control/aiprise/{SESSION_ID}/complete' \
  -H 'Content-Type: application/json' \
  -d '{"outcome": "DECLINED"}'
```

Supported outcomes: `APPROVED`, `DECLINED`, `REVIEW`, `UNKNOWN`, `UPDATE_REQUIRED`

This cancels any pending auto-complete callbacks and immediately fires the webhook.

### KYB Control Plane

#### List All KYB Sessions
```bash
curl -s http://localhost:8080/control/aiprise-kyb/sessions | python -m json.tool
```

**Response:**
```json
{
  "error": false,
  "message": "ok",
  "data": [
    {
      "verification_session_id": "18085c8d-bf99-4315-b5b9-bd5f0351a949",
      "client_reference_id": "2ed731f0-e0ea-487d-ba37-c5bdbfe8a006",
      "state": "completed",
      "verification_result": "APPROVED",
      "session_type": "kyb_url_session",
      "template_id": "d0cd41c6-11ad-4d03-b446-3198d6e940c5",
      "callback_url": "http://penny-api-restricted-local:3002/.../webhook-url-session-kyb",
      "created_at": "2026-03-25 22:37:18",
      "updated_at": "2026-03-25 22:37:30"
    }
  ]
}
```

#### Manually Complete a KYB Session
```bash
curl -s -X POST 'http://localhost:8080/control/aiprise-kyb/{SESSION_ID}/complete' \
  -H 'Content-Type: application/json' \
  -d '{"outcome": "APPROVED"}'
```

### Shared Control Plane

#### Fire Pending Callbacks
Trigger all due callbacks without waiting for the background loop:

```bash
curl -s -X POST http://localhost:8080/control/fire-callbacks
```

### Inspect Scenario (Full Entity + Callback History)
```bash
curl -s http://localhost:8080/control/scenarios/__aiprise__ | python -m json.tool
```

### View Request History
```bash
curl -s http://localhost:8080/control/history/__aiprise__ | python -m json.tool
```

### Reset All AiPrise Sessions
```bash
curl -s -X DELETE http://localhost:8080/control/scenarios/__aiprise__
```

---

## Configuration Reference

### Environment Variables

#### service-virtualization

| Variable                        | Default                            | Description                                        |
|---------------------------------|------------------------------------|----------------------------------------------------|
| `APP_BASE_URL`                  | `http://localhost:8080`            | External URL for verification links                |
| `APP_INTERNAL_URL`              | `http://localhost`                 | Internal URL for self-callbacks (port 80 in container) |
| `AIPRISE_HMAC_KEY`             | `virtual-aiprise-key-for-testing`  | HMAC secret for signing webhook callbacks          |
| `AIPRISE_AUTO_DELAY`           | `10`                               | Seconds before auto-completing a session           |
| `AIPRISE_CALLBACK_REWRITE_HOST`| *(empty — no rewrite)*             | Replace callback URL host with this value          |
| `DB_HOST`                       | `virtual-db`                       | MySQL host                                         |
| `DB_PORT`                       | `3306`                             | MySQL port                                         |
| `DB_NAME`                       | `service_virtualization`           | MySQL database name                                |
| `DB_USER`                       | `virtual`                          | MySQL username                                     |
| `DB_PASS`                       | `virtual`                          | MySQL password                                     |
| `APP_ENV`                       | `local`                            | Environment name                                   |
| `APP_DEBUG`                     | `true`                             | Enable debug output                                |

#### penny-api (AiPrise-related)

| Variable                           | Value                                    | Description                                |
|------------------------------------|------------------------------------------|--------------------------------------------|
| `AIPRISE_URL`                      | `http://service-virtualization`          | Points to virtual AiPrise (no port — 80)   |
| `AIPRISE_CALLBACK_URL`            | `http://penny-api-restricted:3002/...`   | KYC env var (NOT used — DB overrides this) |
| `AIPRISE_CALLBACK_URL_BUSINESS`   | `http://penny-api-restricted-local:3002` | **KYB base URL** — penny-api appends the path |
| `AIPRISE_ID_KEY`                  | `bdb602e5ea6649a893faf01d0f6e7e8a`       | API key header sent to AiPrise             |
| `AIPRISE_BUSINESS_ID_TEMPLATE`    | `d0cd41c6-11ad-4d03-b446-3198d6e940c5`  | Default KYB template ID                    |

> **Important:** `AIPRISE_CALLBACK_URL` in penny-api's env is NOT the callback URL
> that gets sent to AiPrise for KYC. penny-api reads the KYC callback URL from the
> `aiprise_configuration` table in PostgreSQL. The `AIPRISE_CALLBACK_REWRITE_HOST`
> on the virtual service handles this mismatch.
>
> **KYB Callback URL:** For KYB, penny-api builds the callback URL as:
> `${AIPRISE_CALLBACK_URL_BUSINESS}/api/v1/third-party-service/aiprise/webhook-url-session-kyb`
> So `AIPRISE_CALLBACK_URL_BUSINESS` must be the **base URL only** (no path), e.g.,
> `http://penny-api-restricted-local:3002`. If the full path is included, it gets doubled.

#### penny-api-restricted (AiPrise-related)

| Variable          | Value                        | Description                                   |
|-------------------|------------------------------|-----------------------------------------------|
| `AIPRISE_ID_KEY`  | `virtual-aiprise-test-key`   | HMAC key for validating webhook signatures    |
| `ALFRED_URL`      | `http://penny-api:3003`      | Where to forward validated webhooks           |

### local-services.json

This is the source of truth for service configuration. The `local-up.ps1` script reads
this file and generates `docker-compose.local.yml`.

**Key configuration block:**
```json
{
  "service-virtualization": {
    "env": {
      "AIPRISE_HMAC_KEY": "virtual-aiprise-test-key",
      "AIPRISE_AUTO_DELAY": "10",
      "AIPRISE_CALLBACK_REWRITE_HOST": "http://penny-api-restricted-local:3002"
    }
  },
  "penny-api": {
    "env": {
      "AIPRISE_URL": "http://service-virtualization"
    }
  },
  "penny-api-restricted": {
    "env": {
      "AIPRISE_ID_KEY": "virtual-aiprise-test-key"
    }
  }
}
```

### Database Tables

#### PostgreSQL (axen_dev) — penny-api tables

| Table                    | Key Columns                                                        | Description                          |
|--------------------------|--------------------------------------------------------------------|--------------------------------------|
| `customer`               | `id`, `email`, `country`, `business`                              | Customer records                     |
| `individual_customers`   | `id`, `customerId`, `statusKyc`, `verificationSessionId`, `verificationUrl`, `aiPriseKycStatus` | KYC submission records |
| `business_customers`     | `id`, `customerId`, `status`, `verificationSessionId`, `verificationUrl`, `legalName`, `taxId`, `relatedPersons` | KYB business records |
| `aiprise_configuration`  | `businessId`, `country`, `template`, `callbackUrl`, `eventCallbackUrl` | AiPrise template + callback config |

#### MySQL (service_virtualization) — virtual service tables

| Table                | Key Columns                                                          | Description                       |
|----------------------|----------------------------------------------------------------------|-----------------------------------|
| `entities`           | `id`, `namespace`, `entity_type`, `entity_ref`, `state`, `data`      | AiPrise session state             |
| `pending_callbacks`  | `id`, `namespace`, `target_url`, `payload`, `headers`, `fire_at`     | Scheduled webhook callbacks       |
| `callback_history`   | `id`, `callback_id`, `response_status`, `success`                    | Delivery audit log                |
| `state_history`      | `id`, `entity_id`, `old_state`, `new_state`                         | Session state transitions         |
| `request_log`        | `id`, `method`, `path`, `body`, `response_status`                   | Inbound request log               |
| `scenarios`          | `namespace`, `created_at`, `expires_at`                             | Test scenario metadata            |

---

## Penny-API Integration Details

### How penny-api Creates a Session

**File:** `penny-api/src/modules/customer/customer.service.ts`
**Method:** `getUrlKycSession()` (~line 3119)

1. Reads config from `aiprise_configuration` table:
   ```sql
   SELECT * FROM aiprise_configuration
   WHERE "businessId" = ? AND country = ?
   ```

2. Sends POST to AiPrise (virtual service):
   ```json
   POST http://service-virtualization/api/v1/verify/get_user_verification_url
   Headers: { "X-API-KEY": "...", "Content-Type": "application/json" }
   Body: {
     "redirect_uri": "popup_close",
     "template_id": "<from aiprise_configuration.template>",
     "callback_url": "<from aiprise_configuration.callbackUrl>",
     "events_callback_url": "<from aiprise_configuration.eventCallbackUrl>",
     "client_reference_id": "<customerId>",
     "ui_options": {
       "id_verification_module": {
         "allowed_country_code": "<country>"
       }
     }
   }
   ```

3. Stores response on `individual_customers`:
   ```sql
   UPDATE individual_customers
   SET "verificationUrl" = '<response.verification_url>',
       "statusKyc" = 'CREATED'
   WHERE id = '<submissionId>'
   ```

### How penny-api Processes the Webhook

**File:** `penny-api/src/modules/aiprise/aiprise.service.ts`
**Method:** `webhookUrlSession()` (~line 211)

1. **Guard:** Checks `event_type` is present and valid
2. **Lookup:** Finds `individual_customers` by `client_reference_id`:
   - Tries `individual_customers.id = client_reference_id`
   - Falls back: `individual_customers.customerId.id = client_reference_id`
3. **For `VERIFICATION_SESSION_COMPLETION`:** Updates with full ID info
   ```sql
   UPDATE individual_customers SET
     "statusKyc" = 'COMPLETED',
     "aiPriseKycStatus" = 'APPROVED',
     "verificationSessionId" = '<session_id>',
     "firstName" = '<id_info.first_name>',
     "lastName" = '<id_info.last_name>',
     "dateOfBirth" = '<id_info.birth_date>',
     "country" = '<id_info.issue_country_code>',
     "dni" = '<extracted_dni>',
     ...
   WHERE id = '<individual_customer_id>'
   ```
4. **For `CASE_STATUS_UPDATE`:** Updates status only
   ```sql
   UPDATE individual_customers SET
     "aiPriseKycStatus" = '<verification_result>',
     "statusKyc" = '<mapped_status>',
     "verificationSessionId" = '<session_id>'
   WHERE id = '<individual_customer_id>'
   ```

### How penny-api Creates a KYB Session

**File:** `penny-api/src/modules/customer/customer.service.ts`
**Method:** `getVerificationBusinessUrl()` (~line 5402)

1. Finds `business_customers` record by `customerId`
2. Reads KYB config from `aiprise_configuration` table:
   ```sql
   SELECT * FROM aiprise_configuration
   WHERE "businessId" = ? AND country = ? AND type = 'KYB'
   ```
3. Builds callback URL from env var:
   ```javascript
   const callbackUrl = `${configService.get('aiprise.aipriseCallbackUrlBusiness')}/api/v1/third-party-service/aiprise/webhook-url-session-kyb`
   ```
4. Sends POST to AiPrise (virtual service):
   ```json
   POST http://service-virtualization/api/v1/verify/get_business_verification_url
   Headers: { "X-API-KEY": "...", "Content-Type": "application/json" }
   Body: {
     "template_id": "<from config or AIPRISE_BUSINESS_ID_TEMPLATE>",
     "callback_url": "http://penny-api-restricted-local:3002/.../webhook-url-session-kyb",
     "client_reference_id": "<business_customers.id>",
     "user_data": {}
   }
   ```
5. Extracts `business_onboarding_session_id` from returned URL:
   ```javascript
   const url = new URL(response.verification_url);
   const sessionId = url.searchParams.get('business_onboarding_session_id');
   ```
6. Stores on `business_customers`:
   ```sql
   UPDATE business_customers
   SET "verificationUrl" = '<response.verification_url>',
       "verificationSessionId" = '<extracted session_id>'
   WHERE id = '<business_customers.id>'
   ```

### How penny-api Processes the KYB Webhook

**File:** `penny-api/src/modules/aiprise/aiprise.service.ts`
**Method:** `webhookUrlSessionKyb()` (~line 511)

1. **Guard:** Checks `business_profile_result` is present
2. **Lookup:** Finds `business_customers` by `verificationSessionId` from `verification_session_ids` array
3. **Document Processing:** Maps document `file_type` via `typeDocuments` constant:
   ```javascript
   // SHAREHOLDERS_REGISTRY → { uriKey: 'shareholderRegistryUri', nameKey: 'shareholderRegistry' }
   // ADDRESS_PROOF_DOCUMENT → { uriKey: 'proofAddressUri', nameKey: 'proofAddress' }
   // ARTICLES_OF_INCORPORATION → { uriKey: 'articlesIncorporationUri', nameKey: 'articlesIncorporation' }
   ```
4. **Related People Processing:** Maps `related_people` array to `relatedPersons` JSONB
5. **Updates `business_customers`:**
   ```sql
   UPDATE business_customers SET
     "legalName" = '<name>',
     "taxId" = '<tax_identification_number>',
     "countryCode" = '<country_code>',
     "addressCity" = '<addresses[0].address_city>',
     "stateCode" = '<addresses[0].address_state>',
     "addressStreetOne" = '<addresses[0].address_street_1>',
     "shareholderRegistry" = '<file_name>',
     "shareholderRegistryUri" = '<file_s3_url>',
     "proofAddress" = '<file_name>',
     "proofAddressUri" = '<file_s3_url>',
     "articlesIncorporation" = '<file_name>',
     "articlesIncorporationUri" = '<file_s3_url>',
     "relatedPersons" = '<json array>'
   WHERE id = '<business_customer.id>'
   ```
6. **Updates KYB status** via `updateKybStatusByWebhook()`

### KYC Session Reuse Behavior

penny-api caches `verificationUrl` on the `individual_customers` record. Subsequent calls
to `GET /verification/url` return the cached URL without creating a new AiPrise session.
A new session is only created when:
- `verificationUrl` is null
- The existing session is older than 30 days
- The previous status was `DECLINED`

**To force a new KYC session** (e.g., for re-testing):
```sql
UPDATE individual_customers
SET "verificationUrl" = NULL, "verificationSessionId" = NULL
WHERE id = '<submissionId>';
```

### KYB Session Reuse Behavior

Similarly, penny-api caches `verificationUrl` on `business_customers`. A new session is created
when `verificationUrl` is null.

**To force a new KYB session:**
```sql
UPDATE business_customers
SET "verificationUrl" = NULL,
    "verificationSessionId" = NULL,
    status = 'UPDATE_REQUIRED'
WHERE id = '<business_customer_id>';
```

---

## Testing Recipes

### Recipe 1: Full E2E KYC — Happy Path (APPROVED)

```bash
# 1. Get verification URL (triggers session creation)
RESPONSE=$(curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/MX/url' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2')
echo "$RESPONSE"

# 2. Wait for auto-completion (10s delay + 5s poll cycle + webhook processing)
sleep 20

# 3. Check status
curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/{SUBMISSION_ID}/status' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'
# Expected: {"status":"COMPLETED","updatedAt":"..."}
```

### Recipe 2: Test DECLINED Outcome

```bash
# 1. Get verification URL
RESPONSE=$(curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/MX/url' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2')

# 2. Extract session ID from the response
SESSION_ID=$(echo "$RESPONSE" | python -c "import sys,json; print(json.load(sys.stdin)['verification_session_id'])")

# 3. Override the auto-complete outcome to DECLINED (before it fires)
curl -s -X POST "http://localhost:8080/control/aiprise/${SESSION_ID}/complete" \
  -H 'Content-Type: application/json' \
  -d '{"outcome": "DECLINED"}'

# 4. Fire callbacks immediately (don't wait for poll cycle)
curl -s -X POST http://localhost:8080/control/fire-callbacks

# 5. Wait a moment for webhook processing
sleep 3

# 6. Check status
curl -s -X GET \
  'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyc/{SUBMISSION_ID}/status' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'
# Expected: {"status":"FAILED","updatedAt":"..."}
```

### Recipe 3: Direct Virtual AiPrise API Test (No penny-api)

```bash
# Create session directly
curl -s -X POST http://localhost:8080/api/v1/verify/get_user_verification_url \
  -H 'Content-Type: application/json' \
  -H 'X-API-KEY: test-key' \
  -d '{
    "template_id": "test-template",
    "client_reference_id": "test-customer-001",
    "callback_url": "https://httpbin.org/post",
    "user_data": {"first_name": "Test", "last_name": "User"}
  }'

# Check session state
curl -s http://localhost:8080/control/aiprise/sessions | python -m json.tool

# Get verification result
curl -s http://localhost:8080/api/v1/verify/get_user_verification_result/{SESSION_ID} | python -m json.tool
```

### Recipe 4: Full E2E KYB — Happy Path (APPROVED)

```bash
# 1. Find a business customer to test with
docker exec penny-api-local node -e "
const{Client}=require('pg');
const c=new Client({host:'host.docker.internal',port:5432,database:'axen_dev',user:'postgres',password:'YoUnGhAcK@32'});
c.connect().then(()=>c.query(\`
  SELECT bc.id, bc.status, bc.\\\"customerId\\\", c.country
  FROM business_customers bc
  JOIN customer c ON bc.\\\"customerId\\\" = c.id
  WHERE bc.status = 'UPDATE_REQUIRED' AND c.country = 'MX'
  LIMIT 3
\`)).then(r=>{console.log(JSON.stringify(r.rows,null,2));c.end()}).catch(e=>{console.error(e.message);c.end()})
"

# 2. Request KYB verification URL (use customerId from step 1)
curl -s 'http://localhost:3003/api/v1/third-party-service/customers/{CUSTOMER_ID}/kyb/verification/url'
# Response: {"verification_url":"http://localhost:8080/verify?business_onboarding_session_id=...","submissionId":"..."}

# 3. Wait for auto-completion (10s delay + 5s poll cycle + webhook processing)
sleep 20

# 4. Check business customer status
docker exec penny-api-local node -e "
const{Client}=require('pg');
const c=new Client({host:'host.docker.internal',port:5432,database:'axen_dev',user:'postgres',password:'YoUnGhAcK@32'});
c.connect().then(()=>c.query('SELECT status,\\\"verificationSessionId\\\",\\\"legalName\\\",\\\"taxId\\\" FROM business_customers WHERE \\\"customerId\\\"=\\\'{CUSTOMER_ID}\\\'')).then(r=>{console.log(JSON.stringify(r.rows[0],null,2));c.end()}).catch(e=>{console.error(e.message);c.end()})
"
# Expected: {"status":"COMPLETED","verificationSessionId":"...","legalName":"VIRTUAL_BUSINESS_NAME","taxId":"VIRTUAL-TAX-001"}
```

### Recipe 5: Test KYB DECLINED Outcome

```bash
# 1. Request KYB verification URL
RESPONSE=$(curl -s 'http://localhost:3003/api/v1/third-party-service/customers/{CUSTOMER_ID}/kyb/verification/url')
echo "$RESPONSE"

# 2. Check KYB sessions in virtual service to get the session ID
curl -s http://localhost:8080/control/aiprise-kyb/sessions | python -m json.tool

# 3. Override auto-complete outcome to DECLINED (before it fires — act within 10s)
curl -s -X POST 'http://localhost:8080/control/aiprise-kyb/{SESSION_ID}/complete' \
  -H 'Content-Type: application/json' \
  -d '{"outcome": "DECLINED"}'

# 4. Fire callbacks immediately
curl -s -X POST http://localhost:8080/control/fire-callbacks

# 5. Wait a moment for webhook processing
sleep 3

# 6. Check business customer status
# Expected: status = "FAILED"
```

### Recipe 6: Reset Customer for Re-testing (KYC)

```bash
# Clear cached session so penny-api creates a fresh one
docker exec penny-api-local node -e "
const { Client } = require('pg');
const c = new Client({ host: 'host.docker.internal', port: 5432, user: 'postgres', password: 'YoUnGhAcK@32', database: 'axen_dev' });
c.connect().then(async () => {
  await c.query(\`
    UPDATE individual_customers
    SET \"verificationUrl\" = NULL,
        \"verificationSessionId\" = NULL,
        \"verificationSessions\" = NULL,
        \"statusKyc\" = 'CREATED',
        \"aiPriseKycStatus\" = NULL
    WHERE \"customerId\" = '{CUSTOMER_ID}'
  \`);
  console.log('Customer reset');
  c.end();
});
"
```

### Recipe 7: Reset Business Customer for Re-testing (KYB)

```bash
docker exec penny-api-local node -e "
const { Client } = require('pg');
const c = new Client({ host: 'host.docker.internal', port: 5432, user: 'postgres', password: 'YoUnGhAcK@32', database: 'axen_dev' });
c.connect().then(async () => {
  await c.query(\`
    UPDATE business_customers
    SET \"verificationUrl\" = NULL,
        \"verificationSessionId\" = NULL,
        status = 'UPDATE_REQUIRED',
        \"legalName\" = 'test',
        \"taxId\" = NULL,
        \"countryCode\" = NULL,
        \"relatedPersons\" = NULL
    WHERE \"customerId\" = '{CUSTOMER_ID}'
  \`);
  console.log('Business customer reset');
  c.end();
});
"
```

### Recipe 8: View Logs Across All Services

```bash
# Virtual AiPrise logs (session creation, auto-complete, webhook)
docker logs service-virtualization-local --tail 50

# penny-api-restricted logs (webhook receipt + forward)
docker logs penny-api-restricted-local --tail 20

# penny-api logs (webhook processing + status update)
docker logs penny-api-local --tail 20
```

---

## Troubleshooting

### statusKyc stays CREATED after 30+ seconds

**Symptom:** KYC status check returns `{"status":"CREATED"}` indefinitely.

**Diagnosis checklist:**

1. **Check if session was created:**
   ```bash
   curl -s http://localhost:8080/control/aiprise/sessions | python -m json.tool
   ```
   If no sessions → penny-api didn't call the virtual service. Check penny-api logs.

2. **Check callback_url on the session:**
   Look at the `callback_url` field in the sessions response.
   - If it shows `https://penny-api-restricted-dev.alfredpay.io/...` → the rewrite isn't working
   - Fix: Ensure `AIPRISE_CALLBACK_REWRITE_HOST` is set on the container

3. **Check if session auto-completed:**
   Look at the `state` field. If still `created` after 15+ seconds:
   - Check `docker logs service-virtualization-local` for errors
   - Try manual fire: `curl -s -X POST http://localhost:8080/control/fire-callbacks`

4. **Check if webhook was delivered:**
   ```bash
   docker logs penny-api-restricted-local --tail 10
   ```
   Look for `POST /api/v1/third-party-service/aiprise/webhook-url-session`

5. **Check if penny-api processed it:**
   ```bash
   docker logs penny-api-local --tail 10
   ```
   Look for the same POST route. If 201 but no status change, check next item.

6. **Missing `event_type` in webhook:**
   penny-api silently returns `{ message: 'sucess' }` if `event_type` is null.
   Check the webhook payload in penny-api-restricted logs for `event_type` field.

### Webhook fires to wrong URL

**Cause:** `AIPRISE_CALLBACK_REWRITE_HOST` not set or empty.

**Fix:**
```bash
# Check current env
docker exec service-virtualization-local bash -c "grep AIPRISE_CALLBACK_REWRITE_HOST /var/www/html/.env"

# Should show: AIPRISE_CALLBACK_REWRITE_HOST=http://penny-api-restricted-local:3002
```

If missing, update `local-services.json`, `docker-compose.local.yml`, rebuild and recreate.

### HMAC signature mismatch

**Cause:** `AIPRISE_HMAC_KEY` (virtual service) doesn't match `AIPRISE_ID_KEY` (penny-api-restricted).

**Fix:** Both must be the same value. Check:
```bash
docker exec service-virtualization-local bash -c "grep AIPRISE_HMAC_KEY /var/www/html/.env"
docker exec penny-api-restricted-local bash -c "printenv AIPRISE_ID_KEY"
```

> **Note:** Currently penny-api-restricted has a bug where HMAC validation is bypassed
> (returns true before comparing). But fix this for correctness.

### KYB webhook returns 500 — "Property headquartersPhone not found"

**Symptom:** penny-api logs show:
```
EntityPropertyNotFoundError: Property "headquartersPhone" was not found in "BusinessCustomers"
```

**Cause:** penny-api's `webhookUrlSessionKyb()` sets `headquartersPhone: event?.user_input?.user_data?.phone_number ?? null`.
If the webhook payload includes a non-null `phone_number` in `user_input.user_data`, TypeORM tries to set it
on the entity, but `BusinessCustomers` doesn't have a `headquartersPhone` column.

**Fix (already applied):** The virtual service sends `user_input.user_data: {}` (empty) instead of including
`phone_number`. This causes `headquartersPhone` to be `null`, which gets filtered out by the null filter in
`updateKybInfo()`.

**Alternative fix** (if you need phone_number for other testing):
```sql
ALTER TABLE business_customers ADD COLUMN "headquartersPhone" varchar NULL;
```

### KYB business_customers.status stays UPDATE_REQUIRED

**Symptom:** Business customer status doesn't change after 30+ seconds.

**Diagnosis checklist:**

1. **Check if KYB session was created:**
   ```bash
   curl -s http://localhost:8080/control/aiprise-kyb/sessions | python -m json.tool
   ```

2. **Check callback_url:** Ensure it points to `penny-api-restricted-local:3002`, not an external host.

3. **Check AIPRISE_CALLBACK_URL_BUSINESS env var:**
   Must be base URL only: `http://penny-api-restricted-local:3002` (no path appended).
   penny-api appends `/api/v1/third-party-service/aiprise/webhook-url-session-kyb` to this value.
   If the env var includes the path, it gets doubled.

4. **Check webhook delivery:**
   ```bash
   docker logs penny-api-restricted-local --tail 10
   docker logs penny-api-local --tail 10
   ```
   Look for `POST /webhook-url-session-kyb`. If 201 but status unchanged, check next items.

5. **Check if business customer was found:**
   The webhook finds `business_customers` by `verificationSessionId`. Verify it was stored:
   ```sql
   SELECT "verificationSessionId" FROM business_customers WHERE "customerId" = '...';
   ```

### penny-api returns cached verification URL

**Symptom:** Calling `GET /kyc/{country}/url` returns old session ID.

**Cause:** `individual_customers.verificationUrl` is cached from a previous session.

**Fix:** Clear it in PostgreSQL (see Recipe 4 above).

### fire-callbacks.php parse error

**Symptom:** `Parse error: syntax error, unexpected token "*" in fire-callbacks.php`

**Cause:** A PHP docblock containing `*/5 * * * *` (cron syntax) — the `*/` closes the
comment block prematurely.

**Fix:** Already applied. Don't use `*/5` inside PHP docblock comments.

### Container can't reach penny-api-restricted

**Test connectivity:**
```bash
docker exec service-virtualization-local curl -s -o /dev/null -w "%{http_code}" http://penny-api-restricted-local:3002/docs
# Should return: 200
```

If it fails, ensure both containers are on the same Docker network:
```bash
docker network inspect alfred_repos_default
```

---

## File Reference

### Source Files

| File                                | Description                                      |
|-------------------------------------|--------------------------------------------------|
| `src/Aiprise/AipriseService.php`    | Core service: session CRUD, auto-complete, HMAC, webhook, URL rewrite |
| `src/Controller/AipriseController.php` | Route handlers for all AiPrise endpoints      |
| `public/index.php`                  | Route registration (all 15+ AiPrise routes)      |
| `src/Callback/CallbackScheduler.php`| Callback queue: schedule, fire, retry, history   |
| `src/Entity/EntityManager.php`      | Stateful CRUD with state machine + audit log     |
| `src/Core/Router.php`              | Lightweight regex router with `{param}` support  |
| `src/Core/JsonResponse.php`         | JSON response helpers                            |
| `bin/fire-callbacks.php`            | CLI script to fire pending callbacks             |
| `bin/install-schema.php`            | Creates 6 MySQL tables on first run              |
| `docker-entrypoint.sh`             | Container startup: .env generation, schema install, background firer |
| `Dockerfile.local`                  | Docker build configuration (PHP 8.2 + Apache)    |

### Configuration Files

| File                                 | Description                                    |
|--------------------------------------|------------------------------------------------|
| `local-services.json`               | Service definitions + env vars (source of truth) |
| `docker-compose.local.yml`          | Auto-generated Docker Compose (4 services)     |

### Documentation Files

| File                                              | Description                              |
|---------------------------------------------------|------------------------------------------|
| `docs/LOCAL_DOCKER_KYC_GUIDE.md`                  | This document                            |
| `docs/aiprise-official/response.md`               | Official AiPrise response schema         |
| `docs/aiprise-official/api-endpoints.md`          | Official AiPrise endpoint listing        |
| `docs/aiprise-official/openapi-get-user-verification-url.md` | OpenAPI spec for verification URL |
| `AIPRISE_CONTRACT.md`                             | AiPrise contract extracted from penny-api|
| `PROJECT_STATUS.md`                               | Project status, gaps, next steps         |
| `DEPLOY.md`                                       | TigerTech deployment guide               |
