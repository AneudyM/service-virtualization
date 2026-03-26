# AiPrise API Contract - Extracted from AlfredPay Codebase

**Extracted:** 2026-03-25
**Sources:** penny-api (staging), penny-api-restricted (staging), ms-aiprise (staging)

---

## Architecture Overview

```
                         ┌──────────────┐
                         │   AiPrise    │
                         │  Sandbox API │
                         └──┬───────┬───┘
           Creates sessions │       │ Sends webhooks
           Gets results     │       │ (X-HMAC-SIGNATURE)
                            ▼       ▼
                      ┌──────────┐  ┌──────────────────────┐
                      │penny-api │  │penny-api-restricted   │
                      │ (port    │◄─│ (port 3002)           │
                      │  3003)   │  │ validates HMAC,       │
                      │ calls    │  │ proxies to penny-api  │
                      │ AiPrise  │  └──────────────────────┘
                      │ directly │
                      └──────────┘
```

- **penny-api** calls AiPrise directly (creates sessions, gets results)
- **penny-api-restricted** receives AiPrise webhooks, validates HMAC, proxies to penny-api
- **ms-aiprise** is a separate microservice for third-party payment customers (different flow)

---

## 1. AiPrise API Endpoints Called by penny-api

Base URL: `AIPRISE_URL` env var (e.g., `https://api-sandbox.aiprise.com`)

All requests include:
```
X-API-KEY: {AIPRISE_ID_KEY}
Content-Type: application/json
Accept: application/json
```

### 1.1 Create KYC Verification URL Session

```
POST {AIPRISE_URL}/api/v1/verify/get_user_verification_url
```

**Request:**
```json
{
  "template_id": "8f46470a-7fb3-423f-835d-b3813f92bc39",
  "client_reference_id": "customer-uuid-here",
  "callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session",
  "events_callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session",
  "user_data": {
    "identity": {
      "identity_country_code": "MX",
      "identity_number_type": "NATIONAL_ID",
      "identity_number": "DNI-value-here"
    }
  }
}
```

**Response:**
```json
{
  "verification_session_id": "29a46668-a2bd-4e9f-a212-e707ae539e4e",
  "verification_url": "https://verify-sandbox.aiprise.com/?verification_session_id=29a46668-a2bd-4e9f-a212-e707ae539e4e"
}
```

**Notes:**
- `template_id` is per-country, loaded from `aiprise_configuration` DB table or env vars
- `client_reference_id` is the penny-api customer UUID
- `callback_url` points to penny-api-restricted's webhook endpoint
- penny-api stores `verification_session_id` in `individual_customers.verificationSessionId`
- penny-api stores `verification_url` in `individual_customers.verificationUrl`
- Sessions are reused within 30 days if not expired

### 1.2 Run Full User Verification (with documents)

```
POST {AIPRISE_URL}/api/v1/verify/run_user_verification
```

**Request:**
```json
{
  "template_id": "template-uuid",
  "client_reference_id": "customer-uuid",
  "callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhook-url-session",
  "events_callback_url": "https://penny-api-restricted-dev.alfredpay.io/api/v1/third-party-service/aiprise/webhooks",
  "user_data": {
    "first_name": "John",
    "last_name": "Doe",
    "date_of_birth": "1990-01-15",
    "email_address": "john@example.com",
    "phone_number": "+5212345678",
    "selfie": "base64-encoded-image",
    "identity": {
      "identity_country_code": "MX",
      "identity_document_front": "base64-encoded-image",
      "identity_document_back": "base64-encoded-image"
    },
    "address": {
      "address_street_1": "123 Main St",
      "address_city": "Mexico City",
      "address_state": "CDMX",
      "address_zip_code": "06600",
      "address_country": "MX"
    }
  }
}
```

**Response:**
```json
{
  "verification_session_id": "uuid-here"
}
```

### 1.3 Get User Verification Result

```
GET {AIPRISE_URL}/api/v1/verify/get_user_verification_result/{verification_session_id}
```

**Response:**
```json
{
  "verification_session_id": "uuid",
  "verification_result": "APPROVED",
  "aiprise_summary": {
    "verification_result": "APPROVED"
  },
  "client_reference_id": "customer-uuid",
  "user_data": { ... }
}
```

### 1.4 Business KYB Endpoints

```
POST {AIPRISE_URL}/api/v1/verify/create_business_profile
POST {AIPRISE_URL}/api/v1/verify/add_business_document
POST {AIPRISE_URL}/api/v1/verify/add_business_officer
POST {AIPRISE_URL}/api/v1/verify/run_verification_for_business_officer
POST {AIPRISE_URL}/api/v1/verify/run_verification_for_business_profile_id
GET  {AIPRISE_URL}/api/v1/verify/get_business_verification_result/{verification_session_id}
GET  {AIPRISE_URL}/api/v1/verify/get_business_verification_url
GET  {AIPRISE_URL}/api/v1/verify/get_business_data_from_request/{verification_id}
GET  {AIPRISE_URL}/api/v1/verify/get_business_profile/{verification_id}
```

---

## 2. Webhook Callbacks (AiPrise -> penny-api-restricted)

### 2.1 Authentication

All AiPrise webhook callbacks include:
```
X-HMAC-SIGNATURE: {hex-encoded-hmac-sha256}
```

**Signature generation:**
```javascript
const signature = crypto
  .createHmac('sha256', AIPRISE_ID_KEY)
  .update(Buffer.from(JSON.stringify(payload), 'utf8'))
  .digest('hex')
  .toLowerCase();
```

Secret key: `AIPRISE_ID_KEY` env var (e.g., `bdb602e5ea6649a893faf01d0f6e7e8a`)

### 2.2 KYC Session Webhook

```
POST /api/v1/third-party-service/aiprise/webhook-url-session
```

**Payload from AiPrise:**
```json
{
  "client_reference_id": "customer-uuid",
  "verification_session_id": "session-uuid",
  "aiprise_summary": {
    "verification_result": "APPROVED"
  }
}
```

**Processing:**
1. penny-api-restricted validates X-HMAC-SIGNATURE
2. Forwards entire payload to penny-api at `{ALFRED_URL}/api/v1/third-party-service/aiprise/webhook-url-session`
3. penny-api looks up customer by `client_reference_id` or `verification_session_id`
4. Updates `individual_customers.aiPriseKycStatus` with `aiprise_summary.verification_result`
5. Maps to internal status and updates `individual_customers.statusKyc`

### 2.3 KYC Events Webhook

```
POST /api/v1/third-party-service/aiprise/webhooks
```

**Payload:** Same structure as 2.2, used for status update events during verification.

### 2.4 KYB Session Webhook

```
POST /api/v1/third-party-service/aiprise/webhook-url-session-kyb
```

**Payload:** Similar structure for business verification results.

---

## 3. Status Values and Mapping

### AiPrise Verification Results
| AiPrise Status     | penny-api StatusKyc | Description                  |
|--------------------|--------------------|------------------------------|
| `APPROVED`         | `COMPLETED`        | KYC passed                   |
| `DECLINED`         | `FAILED`           | KYC rejected                 |
| `REVIEW`           | `IN_REVIEW`        | Manual review needed         |
| `UNKNOWN`          | `IN_REVIEW`        | Status not determined        |
| `UPDATE_REQUIRED`  | `UPDATE_REQUIRED`  | Additional documents needed  |

### Internal StatusKyc Enum (penny-api)
```typescript
enum StatusKyc {
  CREATED = 'CREATED',           // Initial state, no session yet
  COMPLETED = 'COMPLETED',       // KYC approved
  FAILED = 'FAILED',             // KYC rejected
  IN_REVIEW = 'IN_REVIEW',       // Under review
  UPDATE_REQUIRED = 'UPDATE_REQUIRED',  // Needs more docs
}
```

### Internal StatusKyb Enum (penny-api)
```typescript
enum StatuskybEnum {
  COMPLETED = 'COMPLETED',
  FAILED = 'FAILED',
  IN_REVIEW = 'IN_REVIEW',
  UPDATE_REQUIRED = 'UPDATE_REQUIRED',
}
```

---

## 4. Database Tables

### aiprise_configuration
Per-business, per-country AiPrise template config.
```sql
CREATE TABLE aiprise_configuration (
  id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
  country VARCHAR NOT NULL,
  template VARCHAR,            -- AiPrise template_id
  "callbackUrl" VARCHAR,       -- Webhook URL for KYC
  "eventCallbackUrl" VARCHAR,  -- Webhook URL for events
  "templateFileRequirement" VARCHAR,
  "templateKyb" VARCHAR,       -- AiPrise template_id for KYB
  -- businessId column (relation to business table)
);
```

### individual_customers (AiPrise-relevant columns)
```sql
"verificationSessionId" VARCHAR,       -- Current AiPrise session ID
"verificationUrl" VARCHAR,             -- Current verification URL
"verificationSessions" TEXT[],         -- History of all session IDs
"aiPriseKycStatus" VARCHAR,            -- Raw AiPrise status (APPROVED, DECLINED, etc.)
"statusKyc" statuskyc_enum DEFAULT 'CREATED',  -- Internal mapped status
"sessionKycId" VARCHAR,                -- Legacy session reference
```

### business_customers (AiPrise-relevant columns)
```sql
"verificationSessionId" VARCHAR,
"verificationSessions" TEXT[],
"verificationUrl" VARCHAR,
status statuskybEnum DEFAULT 'UPDATE_REQUIRED',
```

---

## 5. Template IDs by Country

From `env/.local.env`:
```
AIPRISE_ID_TEMPLATE_PENNY=8f46470a-7fb3-423f-835d-b3813f92bc39     # Default
AIPRISE_ID_TEMPLATE_MX=8f46470a-7fb3-423f-835d-b3813f92bc39        # Mexico
AIPRISE_ID_TEMPLATE_AR=8f46470a-7fb3-423f-835d-b3813f92bc39        # Argentina
AIPRISE_ID_TEMPLATE_BR=ba469e70-a1f8-4c76-b9fb-0a5188b1d3f7        # Brazil (CPF)
AIPRISE_ID_TEMPLATE_CO=015fcb01-b85e-416a-96f6-0d5e50426588        # Colombia
AIPRISE_ID_TEMPLATE_US=95fb94d9-48a0-4af8-bb41-56e92a7aeea7        # USA (from DB)
AIPRISE_ID_TEMPLATE_CN=bd93942f-220c-4307-b165-d6f3ecc18b62        # China
AIPRISE_ID_TEMPLATE_BANKAOOL=95594745-fcbd-44bc-a185-3f0ead3fc011   # Bankaool partner
AIPRISE_ID_TEMPLATE_CPF=ba469e70-a1f8-4c76-b9fb-0a5188b1d3f7       # CPF (Brazil)
AIPRISE_ID_TEMPLATE_DEFAULT=8f46470a-7fb3-423f-835d-b3813f92bc39    # Fallback
AIPRISE_ID_TEMPLATE_MICROBLINK=f80058dd-313e-4916-9996-50d900bb7173 # Microblink
AIPRISE_BUSINESS_ID_TEMPLATE=d0cd41c6-11ad-4d03-b446-3198d6e940c5   # KYB default
AIPRISE_BUSINESS_ID_TEMPLATE_BANKAOOL=38d48055-c6ee-452f-98cf-84085f542cc1
```

Templates can also be overridden per-business via the `aiprise_configuration` table.

---

## 6. Document Types

```typescript
enum FileTypeKyc {
  'National ID Back' = 'National ID Back',
  'National ID Front' = 'National ID Front',
  'Driver Licence Back' = 'Driver Licence Back',
  'Driver Licence Front' = 'Driver Licence Front',
  'Selfie' = 'Selfie',
}
```

---

## 7. What the Virtual Service Must Implement

### Priority 1: KYC URL Session Flow (most common path)

**Stub endpoint:**
```
POST /api/v1/verify/get_user_verification_url
```
- Validate `X-API-KEY` header
- Accept request with `template_id`, `client_reference_id`, `callback_url`, `user_data`
- Return `{ verification_session_id, verification_url }`
- Schedule callback to `callback_url` with configurable delay and outcome

**Stub endpoint:**
```
GET /api/v1/verify/get_user_verification_result/{verification_session_id}
```
- Return stored session state with `aiprise_summary.verification_result`

**Callback emission:**
```
POST {callback_url}
Headers: X-HMAC-SIGNATURE: {computed-hmac}
Body: {
  client_reference_id, verification_session_id,
  aiprise_summary: { verification_result: "APPROVED" }
}
```

### Priority 2: Full Document Verification

**Stub endpoint:**
```
POST /api/v1/verify/run_user_verification
```
- Accept full user_data with base64 images
- Return `{ verification_session_id }`
- Schedule callback

### Priority 3: Business KYB Flow

All `/api/v1/verify/*business*` endpoints with appropriate state machine.

---

## 8. ms-aiprise Contract (Separate Service)

ms-aiprise is a standalone microservice for third-party payment customer compliance:

**Endpoint:**
```
POST /api/v1/third_party_payment_customer/create
Body: { accountNumber, dni, businessId, app, country }
Response: { statusCode: 202, data: { id, status: "CREATED" } }
```

**Outbound to AiPrise:** Same POST to compliance endpoint with:
```json
{
  "template_id": "from-compliance-config",
  "client_reference_id": "tppc-{country}-{customer_id}",
  "callback_url": "from-config",
  "user_data": {
    "identity": {
      "identity_country_code": "MX",
      "identity_number_type": "NATIONAL_ID",
      "identity_number": "dni-value"
    }
  }
}
```

**Webhook:** `POST /api/v1/webhook/kyc` with same AiPrise payload structure.
Matches via `response.clientRefenceId` and `response.verificationSessionId` JSON fields.
