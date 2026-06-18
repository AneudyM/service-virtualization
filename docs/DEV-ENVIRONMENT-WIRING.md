# Wiring the DEV Environment to the Service Virtualization Platform

**Audience:** DevOps (task definition changes) and QA. Working session reference for the Aneudy and Orlando meet.
**Scope:** DEV environment only. Staging gets the same treatment later, swapping the hostname for `virtual-services-stg.alfredpay.io`.
**Platform:** `https://virtual-services-dev.alfredpay.io` (ECS service `virtual-services-dev`, cluster `virtual-services-dev-qa`). Health check: `GET /health` must report `db: connected`.

## What this achieves

Today the dev microservices point at real third-party providers (or at nothing). After this change, dev points at the virtualization platform: deterministic responses, controllable scenarios, no real money movement, no external sandbox dependencies. Each service can be switched independently; nothing requires a big-bang cutover.

## Part 1: Consumer services

For each service deployed in dev, replace these environment variables in its task definition and force a new deployment. Apply only to services that exist in dev; the list covers the full catalog.

### penny-api

| Variable | Value |
|---|---|
| `AIPRISE_URL` | `https://virtual-services-dev.alfredpay.io` |
| `BANCA_URL` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `LOCALBALANCE_MEX_URL` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `S3_ENDPOINT` | `https://virtual-services-dev.alfredpay.io` |

### penny-api-restricted

No changes. It only receives webhook callbacks from the platform.

### wu-backend

| Variable | Value |
|---|---|
| `WU_BASE_URL` | `https://virtual-services-dev.alfredpay.io` |
| `WU_LOCATOR_BASE_URL` | `https://virtual-services-dev.alfredpay.io` |
| `WU_LOCATOR_HEADERLESS_WEB_URL` | `https://virtual-services-dev.alfredpay.io/v1/agent/locations` |

### card-payment

| Variable | Value |
|---|---|
| `WORLDPAY_URL` | `https://virtual-services-dev.alfredpay.io/worldpay/` |
| `SIFT_API_URL` | `https://virtual-services-dev.alfredpay.io/sift/v205/events` |

### fireblock

| Variable | Value |
|---|---|
| `FIREBLOCKS_BASE_URL` | `https://virtual-services-dev.alfredpay.io` |
| `STELLAR_HORIZON_URL` | `https://virtual-services-dev.alfredpay.io` |

### rampas-penny-api

| Variable | Value |
|---|---|
| `TRANSF_URL_BASE_TOKEN` | `https://virtual-services-dev.alfredpay.io/virtual/transfero/token` |
| `TRANSF_URL_BASE` | `https://virtual-services-dev.alfredpay.io/virtual/transfero/` |
| `CBPAY_HOST_URL` | `https://virtual-services-dev.alfredpay.io` |
| `EMAIL_SERVICE` | `https://virtual-services-dev.alfredpay.io` |
| `MICRO_SERVICE_AIRPRISE` | `https://virtual-services-dev.alfredpay.io` |
| `MICROSERVICE_PARAGUAY_URL` | `https://virtual-services-dev.alfredpay.io` |

### ramps-mexico

| Variable | Value |
|---|---|
| `BANCA_URL` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `URL_MICROSERVICE_MEXICO` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `URL_SERVICE_LIQUIDITY` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `URL_GET_BALANCE` | `https://virtual-services-dev.alfredpay.io/banks/bankaool` |
| `URL_SEND_MAIL_BALANCE` | `https://virtual-services-dev.alfredpay.io/api/stub/email` |
| `KYT_URL` | `https://virtual-services-dev.alfredpay.io/virtual/kyt` |
| `COIN_MARKET_CAP_URL` | `https://virtual-services-dev.alfredpay.io/virtual/coinmarketcap` |

### rampas-brasil

| Variable | Value |
|---|---|
| `TRANSF_URL` | `https://virtual-services-dev.alfredpay.io/virtual/transfero` |
| `URL_STARSPAY` | `https://virtual-services-dev.alfredpay.io/virtual/starspay` |
| `KYT_URL` | `https://virtual-services-dev.alfredpay.io/virtual/kyt` |
| `COIN_MARKET_CAP_URL` | `https://virtual-services-dev.alfredpay.io/virtual/coinmarketcap` |

### rampas-colombia

| Variable | Value |
|---|---|
| `URL_MICROSERVICE_COLOMBIA` | `https://virtual-services-dev.alfredpay.io/virtual/kambia` |
| `EMAIL_SERVICE_URL` | `https://virtual-services-dev.alfredpay.io/api/stub/email` |
| `KYT_URL` | `https://virtual-services-dev.alfredpay.io/virtual/kyt` |
| `COIN_MARKET_CAP_URL` | `https://virtual-services-dev.alfredpay.io/virtual/coinmarketcap` |

### rampas-republica-dominicana

| Variable | Value |
|---|---|
| `URL_B89` | `https://virtual-services-dev.alfredpay.io/virtual/b89` |
| `URL_LOAD_FILE` | `https://virtual-services-dev.alfredpay.io/virtual/fileupload` |
| `EMAIL_SERVICE_URL` | `https://virtual-services-dev.alfredpay.io/api/stub/email` |
| `KYT_URL` | `https://virtual-services-dev.alfredpay.io/virtual/kyt` |
| `COIN_MARKET_CAP_URL` | `https://virtual-services-dev.alfredpay.io/virtual/coinmarketcap` |

### cpn-ofi-api

| Variable | Value |
|---|---|
| `CIRCLE_API_BASE` | `https://virtual-services-dev.alfredpay.io` |
| `THIRDPARTY_API_BASE` | `https://virtual-services-dev.alfredpay.io` |

### usa-bridge-integration

| Variable | Value |
|---|---|
| `URL_BRIDGE` | `https://virtual-services-dev.alfredpay.io` |

### cms-backend

| Variable | Value |
|---|---|
| `API_URL_EMAIL_SERVICE` | `https://virtual-services-dev.alfredpay.io/api/stub/email` |

### exchange-rate-service

| Variable | Value |
|---|---|
| `RATE_URL` | `https://virtual-services-dev.alfredpay.io/virtual/coinmarketcap` |

### microserivces-argentina-payex

| Variable | Value |
|---|---|
| `URL_COPTER` | `https://virtual-services-dev.alfredpay.io/banks/exchangecopter` |

Note: `URL_GENERAL` and `URL_VALIDATE` (Consultora Mutual) stay pointed at their current values. That provider is not virtualized yet.

## Part 2: The platform's own task definition

The platform fires webhooks back into dev services. Add these variables to the `virtual-services-dev` task definition (revision 8) and force a new deployment.

| Variable | Value | Notes |
|---|---|---|
| `AIPRISE_HMAC_KEY` | matches penny-api-restricted dev | The secret penny-api-restricted in dev uses to verify AiPrise webhook signatures. A mismatch fails silently: callbacks arrive but signature verification rejects them. |
| `AIPRISE_AUTO_DELAY` | `10` | Seconds before KYC sessions auto-complete. |
| `BANKAOOL_WEBHOOK_URL` | `<rampas-penny-api dev base>/v1/bankaool/webhook` | |
| `BANKAOOL_DEPOSIT_WEBHOOK_URL` | `<rampas-penny-api dev base>/v1/bankaool/webhook` | |
| `BANKAOOL_MXN_WEBHOOK_URL` | `<rampas-penny-api dev base>/v1/bankaool/webhook` | |
| `BANKAOOL_SETTLE_DELAY` | `3` | |
| `BANKAOOL_DEPOSIT_DELAY` | `2` | |
| `KAMBIA_WEBHOOK_URL` | `<rampas-colombia dev base>/v1/webhook/webhookKambia` | Supported by the platform as of commit `630a152`. |
| `FIREBLOCKS_WEBHOOK_URL` | `<rampas-penny-api dev base>/v1/webhook/alfredPay/fireblocks` | Supported by the platform as of commit `630a152`. |

Do NOT set `AIPRISE_CALLBACK_REWRITE_HOST` in dev. Deployed consumers send their real callback URLs; the rewrite is only needed in local Docker.

The internal dev base URLs for rampas-penny-api and rampas-colombia are DevOps-known values; fill them in during the session. If the platform task runs in a different VPC or security group than those services, the webhook POSTs also need a network path (the platform's outbound rules already allow 80/443).

## Suggested rollout order

1. `penny-api` plus the Part 2 platform variables (KYC end to end is the highest-value flow).
2. `wu-backend`, `card-payment`, `fireblock`.
3. The rampas family plus `ramps-mexico`.
4. The rest (`cpn-ofi-api`, `usa-bridge-integration`, `cms-backend`, `exchange-rate-service`, `microserivces-argentina-payex`).

After each service flips, a smoke check of its main flow in dev confirms the wiring before moving to the next.

## Verification

The QA automation suite runs against the deployed platform directly and currently passes 42 scenarios covering every provider listed here. After Part 1 lands for a service, the same flows exercised through the real dev microservice validate the integration end to end.
