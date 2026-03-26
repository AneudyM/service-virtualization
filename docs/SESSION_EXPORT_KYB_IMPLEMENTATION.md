# Session Export: KYB Virtual Service Implementation

**Date:** 2026-03-26
**Status:** KYB E2E flow fully verified and working

---

## What Was Built

A complete KYB (Know Your Business) virtual service implementation extending the existing KYC virtual AiPrise service. This enables full end-to-end KYB testing locally via Docker without connecting to the real AiPrise API.

## Verified E2E Flow

```
1. Submit KYB data (POST /:customerId/kyb)              → 201 Created
2. Upload 3 KYB files (POST /:customerId/kyb/:id/files) → 200 Success (x3)
3. Submit KYB (PUT /:customerId/kyb/:id/submit)          → 200 OK → status=IN_REVIEW
4. penny-api → virtual AiPrise (run_business_verification) → Session created
5. Auto-complete fires after 10s delay                    → 200 OK
6. HMAC-signed webhook → penny-api-restricted → penny-api → 201 Created
7. business_customers.status                              → IN_REVIEW → COMPLETED
```

Tested with customer `ebca59de-a325-4451-a251-4889ca4d04ce`, business_customers submission `36115a91-6403-42bf-8d11-905ef196c483`.

---

## Files Modified/Created

### 1. `Test_Automation_Infrastructure/service-virtualization/src/Aiprise/AipriseService.php`

**What changed:** Added ~250 lines of KYB methods alongside existing KYC methods.

**KYB Constants added (around line 33):**
```php
private const KYB_ENTITY_TYPE = 'aiprise_kyb_session';
private const KYB_NAMESPACE = '__aiprise_kyb__';
```

**KYB Methods added:**

| Method | Purpose |
|--------|---------|
| `createKybSession()` | Creates KYB entity, rewrites callback URL, schedules auto-complete, returns `verification_session_id` and URL with `business_onboarding_session_id` param |
| `getKybVerificationResult()` | Returns KYB result with `business_profile_result` field |
| `autoCompleteKyb()` | Transitions entity to completed, fires KYB webhook |
| `setKybOutcome()` | Control plane: cancel pending + complete with specific outcome |
| `listKybSessions()` | List all KYB sessions |
| `findKybSession()` | Namespace search → default → global |
| `fireKybWebhook()` | HMAC-SHA256-signed KYB callback |
| `buildKybWebhookPayload()` | KYB-specific webhook with `business_profile_result`, `documents`, `related_people`, `addresses`, `business_info`, `aml_info`, `aiprise_summary` |

**Critical fix applied:** `createKybSession()` return value was changed from:
```php
return ['verification_url' => ..., 'message' => 'Success'];
```
to:
```php
return ['verification_session_id' => $sessionId, 'verification_url' => ..., 'message' => 'Success'];
```
Without this, `runBusinessVerification` would generate a random UUID instead of returning the session ID that the auto-complete webhook uses — causing a mismatch where penny-api stores one ID but the webhook arrives with a different one.

---

### 2. `Test_Automation_Infrastructure/service-virtualization/src/Controller/AipriseController.php`

**What changed:** Added KYB controller methods.

| Method | Route | Purpose |
|--------|-------|---------|
| `getBusinessVerificationUrl()` | `POST /api/v1/verify/get_business_verification_url` | URL-based KYB session creation |
| `getBusinessVerificationResult()` | `GET /api/v1/verify/get_business_verification_result/{id}` | Get KYB verification result |
| `autoCompleteKyb()` | `POST /api/v1/verify/_internal/auto-complete-kyb/{id}` | Internal self-callback |
| `controlCompleteKyb()` | `POST /control/aiprise-kyb/{id}/complete` | Manual control plane |
| `listKybSessions()` | `GET /control/aiprise-kyb/sessions` | List KYB sessions |
| `runBusinessVerification()` | `POST /api/v1/verify/run_business_verification` | **API-driven KYB** (submit flow) |

**Key distinction:** Two KYB flows exist:
- **URL-based** (`get_business_verification_url`): Creates a verification URL the user opens in a browser. Used by `createVerificationBusinessUrl()` in penny-api.
- **API-driven** (`run_business_verification`): Files are uploaded via API and submitted programmatically. Used by `submitKyb()` in penny-api. This is the one actually tested E2E.

---

### 3. `Test_Automation_Infrastructure/service-virtualization/public/index.php`

**What changed:** Added routes for all KYB endpoints. Key additions:

```php
// Business KYB: Real implementation for URL-based flow
$router->post('/api/v1/verify/get_business_verification_url', ...);
$router->get('/api/v1/verify/get_business_verification_result/{sessionId}', ...);

// Internal self-callback for KYB auto-completion
$router->post('/api/v1/verify/_internal/auto-complete-kyb/{sessionId}', ...);

// Business KYB: API-driven verification (submit flow)
$router->post('/api/v1/verify/run_business_verification', ...);

// KYB Control Plane
$router->post('/control/aiprise-kyb/{sessionId}/complete', ...);
$router->get('/control/aiprise-kyb/sessions', ...);
```

---

### 4. `docker-compose.local.yml`

**What changed:** Added two environment variables to the penny-api service:

```yaml
# Already existed (fixed in previous session):
AIPRISE_CALLBACK_URL_BUSINESS: "http://penny-api-restricted-local:3002"

# Added in this session:
AIPRISE_CALLBACK_URL_KYB: "http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session-kyb"
```

**Why two different callback URLs:**
- `AIPRISE_CALLBACK_URL_BUSINESS` — Base URL only. penny-api **appends** `/api/v1/third-party-service/aiprise/webhook-url-session-kyb` to it (used in URL-based KYB flow).
- `AIPRISE_CALLBACK_URL_KYB` — Full URL. penny-api passes it **as-is** to AiPrise (used in API-driven submit flow via `run_business_verification`).

---

### 5. `local-services.json`

**What changed:** Same two env vars added to match docker-compose.local.yml:
```json
"AIPRISE_CALLBACK_URL_BUSINESS": "http://penny-api-restricted-local:3002",
"AIPRISE_CALLBACK_URL_KYB": "http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session-kyb"
```

---

### 6. `Test_Automation_Infrastructure/service-virtualization/docs/LOCAL_DOCKER_KYC_GUIDE.md`

**Created:** Comprehensive documentation (~700 lines) covering architecture, quick start, E2E flows for both KYC and KYB, virtual AiPrise API reference, HMAC auth, auto-completion, callback URL rewrite, control plane, configuration reference, testing recipes, troubleshooting.

---

## Database Changes Required

**The local PostgreSQL database needs this column added before KYB webhooks will work:**

```sql
ALTER TABLE business_customers ADD COLUMN "headquartersPhone" varchar NULL;
```

**Why:** penny-api's `webhookUrlSessionKyb()` handler at line ~600 sets `headquartersPhone: event?.user_input?.user_data?.phone_number`. The `addKybInfo` object is passed to TypeORM's `update()`, which fails with `EntityPropertyNotFoundError` if the column doesn't exist. The real production DB likely has this column via a migration, but our local dev DB doesn't.

---

## Key Architecture Decisions

### KYB vs KYC Differences in AiPrise

| Aspect | KYC | KYB |
|--------|-----|-----|
| Session creation | `POST get_user_verification_url` | `POST get_business_verification_url` OR `POST run_business_verification` |
| Webhook trigger field | `event_type: VERIFICATION_SESSION_COMPLETION` | `business_profile_result` field presence |
| Customer lookup | `client_reference_id` → `individual_customers` table | `verificationSessionId` → `business_customers` table |
| Verification URL param | `verification_session_id` query param | `business_onboarding_session_id` query param |
| Callback URL env var | `AIPRISE_CALLBACK_URL` | `AIPRISE_CALLBACK_URL_BUSINESS` (URL-flow) / `AIPRISE_CALLBACK_URL_KYB` (submit-flow) |
| Document mapping | N/A | `typeDocuments`: SHAREHOLDERS_REGISTRY → shareholderRegistry, ADDRESS_PROOF_DOCUMENT → proofAddress, ARTICLES_OF_INCORPORATION → articlesIncorporation |

### Two KYB Flows

1. **URL-based flow** (`get_business_verification_url`): penny-api calls this from `createVerificationBusinessUrl()`. Returns a URL the user visits. This is used by the AlfredX frontend.

2. **API-driven flow** (`run_business_verification`): penny-api calls this from `submitKyb()` after files are uploaded via API. Documents are sent as base64 in the request body. This is the one used by the penny-api-restricted REST API that we tested E2E.

Both flows end with the same webhook callback to `webhookUrlSessionKyb()`.

---

## Bugs Found and Fixed

### 1. `headquartersPhone` EntityPropertyNotFoundError
- **Symptom:** `EntityPropertyNotFoundError: Property "headquartersPhone" was not found in "BusinessCustomers"`
- **Root cause:** penny-api's webhook handler sets `headquartersPhone` but the DB column doesn't exist locally
- **Fix:** `ALTER TABLE business_customers ADD COLUMN "headquartersPhone" varchar NULL;`

### 2. Missing `run_business_verification` route
- **Symptom:** 404 → 500 when calling `PUT .../submit`
- **Root cause:** Virtual service only had `get_business_verification_url` but not the API-driven `run_business_verification` endpoint
- **Fix:** Added `POST /api/v1/verify/run_business_verification` route + controller + reused `createKybSession` logic

### 3. Missing `AIPRISE_CALLBACK_URL_KYB` env var
- **Symptom:** KYB submit succeeded but AiPrise received a null/empty callback URL
- **Root cause:** penny-api reads `AIPRISE_CALLBACK_URL_KYB` for the `run_business_verification` callback URL, but it wasn't set in Docker config
- **Fix:** Added env var pointing to `http://penny-api-restricted-local:3002/api/v1/third-party-service/aiprise/webhook-url-session-kyb`

### 4. Session ID mismatch (webhook ID ≠ stored ID)
- **Symptom:** `Kyb dont found verificationSessionIdS: xxx` — webhook arrives with a session ID that doesn't match DB
- **Root cause:** `createKybSession()` didn't return `verification_session_id`, so the controller fell through to `self::generateUuid()` creating a random ID. penny-api stored this random ID, but the auto-complete webhook used the real session ID from the entity.
- **Fix:** Added `'verification_session_id' => $sessionId` to `createKybSession()` return array

### 5. Invented `"message": "Success"` field
- **Symptom:** Virtual service returns `"message": "Success"` in KYC/KYB responses
- **Root cause:** Fabricated field not present in real AiPrise responses
- **Status:** Identified but not yet removed. Should be cleaned up — virtual service should only return fields confirmed from real AiPrise API responses.

---

## Penny-API Source Code Reference

Key files read during implementation (NOT modified — these are in the penny-api and penny-api-restricted repos):

| File | Lines | What It Shows |
|------|-------|--------------|
| `penny-api/src/modules/customer/customer.service.ts` | 5288-5494 | `createVerificationBusinessUrl()` — URL-based KYB flow. Calls `get_business_verification_url`. Extracts `business_onboarding_session_id` from URL. |
| `penny-api/src/modules/customer/customer.service.ts` | 654-748 | `runBusinessVerification()` — API-driven KYB. Builds `business_data` + base64 `additional_info`. Calls `run_business_verification`. |
| `penny-api/src/modules/customer/customer.service.ts` | 863-942 | `submitKyb()` — Orchestrator. Calls `runBusinessVerification()`, updates `business_customers.verificationSessionId`, sets status to `IN_REVIEW`. |
| `penny-api/src/modules/aiprise/aiprise.service.ts` | 511-720 | `webhookUrlSessionKyb()` — KYB webhook handler. Checks `business_profile_result`. Finds by `verificationSessionId`. Maps documents via `typeDocuments`. Sets `headquartersPhone`. Calls `updateKybInfo()` + `updateKybStatusByWebhook()`. |
| `penny-api/src/common/constants/aiprice.ts` | — | `typeDocuments` mapping: SHAREHOLDERS_REGISTRY→shareholderRegistry, ADDRESS_PROOF_DOCUMENT→proofAddress, ARTICLES_OF_INCORPORATION→articlesIncorporation |
| `penny-api/src/common/constants/urls.ts` | — | Full list of AiPrise URL constants including `runBusinessKybVerification: '/api/v1/verify/run_business_verification'` |
| `penny-api-restricted/src/modules/customer/kyb.controller.ts` | — | All KYB REST endpoints: submit data, upload files, submit for review, check status, get details |
| `penny-api-restricted/src/commons/strategy/service.strategy.ts` | — | API key/secret auth: looks up `business.apiKey` in DB, bcrypt-compares `apiSecret` |

---

## Testing Recipe (E2E KYB)

**Prerequisites:**
- All 4 Docker containers running (`docker compose -f docker-compose.local.yml up -d`)
- `headquartersPhone` column added to `business_customers` table

**Auth credentials (business "Decaf", id=2):**
```
api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5
api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2
```

**Step 1: Submit KYB data**
```bash
curl -X POST 'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyb' \
  -H 'Content-Type: application/json' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2' \
  -d '{
    "kybSubmission": {
      "country": "MX",
      "businessName": "Test Corp",
      "taxId": "XAXX010101000",
      "state": "CDMX",
      "city": "Mexico City",
      "address": "Av Test 123",
      "zipCode": "01234",
      "relatedPersons": [{
        "firstName": "Juan",
        "lastName": "Perez",
        "email": "juan@test.com",
        "dateOfBirth": "1985-01-01",
        "nationalities": ["MX"]
      }]
    }
  }'
```
Returns `submissionId` — use in subsequent calls.

**Step 2: Upload 3 document files**
```bash
# Use forward slashes in file paths, even on Windows
curl -X POST 'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyb/{SUBMISSION_ID}/files' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2' \
  -F 'rawBody=@D:/path/to/file.pdf' \
  -F 'fileType=articlesIncorporation'

# Repeat with fileType=proofAddress and fileType=shareholderRegistry
```

**Step 3: Submit KYB for review**
```bash
curl -X PUT 'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyb/{SUBMISSION_ID}/submit' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'
```

**Step 4: Wait ~15 seconds, then check status**
```bash
curl -X GET 'http://localhost:3002/api/v1/third-party-service/penny/customers/{CUSTOMER_ID}/kyb/{SUBMISSION_ID}/status' \
  -H 'api-key: alfredpay.vZpB06yC8crbV-hZTmsuW4tLS.bRFog5' \
  -H 'api-secret: hLacEqbCbIahIovpSY.OnoKidfwj1eR2'
```
Should show `status: COMPLETED`.

---

## Remaining Work / Known Issues

1. **Remove `"message": "Success"` field** from virtual service KYC/KYB responses — not present in real AiPrise
2. **KYB documentation** in LOCAL_DOCKER_KYC_GUIDE.md needs to be updated with the full submit flow (currently mainly covers the URL-based flow)
3. **Test fixtures** at `test-fixtures/` contain real KYB PDF documents — these should probably be replaced with synthetic test files for the repo
4. **`headquartersPhone` column** must be manually added to any fresh local database — consider adding it to a setup/migration script
5. **Virtual service response fidelity** — compare all virtual responses against captured real AiPrise responses to eliminate any other invented fields

---

## How to Rebuild After Code Changes

```bash
# 1. Rebuild the Docker image
docker build -f ./Test_Automation_Infrastructure/service-virtualization/Dockerfile.local \
  -t service-virtualization-local \
  ./Test_Automation_Infrastructure/service-virtualization/

# 2. Restart the container with the new image
docker compose -f docker-compose.local.yml up -d service-virtualization --force-recreate

# 3. If penny-api env vars changed, also restart penny-api
docker compose -f docker-compose.local.yml up -d penny-api --force-recreate
```
