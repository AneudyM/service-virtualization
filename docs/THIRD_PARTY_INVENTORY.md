# Third-Party API Inventory — AlfredPay (Corrected v2)

**Generated:** 2026-04-23
**Scope:** TRUE external HTTP(S) APIs. Excludes AlfredPay-owned wrappers.
**Status:** v2 human-verified (grepped all key wrappers).

This doc catalogs TRUE third-party externals that prod code paths touch, categorizes them by virtualization status, and ranks the unvirtualized ones by impact.

---

## Core rule: AlfredPay-owned wrapper exclusion

**Any service repo under `ExperimentRepos/alfred-payments/` or `send-global/` is AlfredPay-owned**, even if its dev URL looks external (`.alfredpay.app`, `.alfredpay.io`, or `*.virtual-needed.app`). These wrappers ARE the product and run locally from source. The **TRUE third parties** are whatever these wrappers call outbound.

**Example:** `microserivces-argentina-payex` is an AlfredPay wrapper. Its true externals are `api.exchangecopter.com` (ExchangeCopter) and `apisrv.itconsultoramutual.com.ar` (Consultoramutual) — those are what get virtualized.

---

## AlfredPay-owned wrappers and real externals they front

| Wrapper | Dev URL | Externals | Notes |
|---|---|---|---|
| microserivces-argentina-payex | account-balancer-ramp-dev.virtual-needed.app | api.exchangecopter.com, apisrv.itconsultoramutual.com.ar | Payex (AR) |
| nest-backend-service-brasil | brazil-pay-svc.alfredpay.io | openbanking.bit.one | Transfero (BR) |
| micro-service-colombia | ramps-co.alfredpay.io | xc223xnsz1.execute-api.us-east-1.amazonaws.com/dev/v1 | Kambia (CO) |
| micro-service-mexico-stp | stp-ramp-mx.alfredpay.io | demo.stpmex.com | STP (MX) |
| cpn-ofi-api | cpn-core.alfredpay.app | api.circle.com | Circle |
| card-payment | card-pay-svc-stg.alfredpay.app | try.access.worldpay.com | Worldpay |
| fireblock | fireblock-api.alfredpay.app | api.fireblocks.io | Fireblocks |
| cpn-core | stellar-anchor.alfredpay.io | horizon.stellar.org | Stellar |

---

## Summary: Externals found

**Wrappers identified:** 8 AlfredPay-owned (serving as proxies to true externals)

**TRUE third-party externals:**
1. Transfero: openbanking.bit.one
2. ExchangeCopter: api.exchangecopter.com
3. Consultoramutual: apisrv.itconsultoramutual.com.ar
4. Kambia: xc223xnsz1.execute-api.us-east-1.amazonaws.com/dev/v1
5. STP: demo.stpmex.com
6. Worldpay: try.access.worldpay.com
7. Stellar Horizon: horizon.stellar.org
8. Circle: api.circle.com
9. Sumsub: api.sumsub.com
10. PMI Americas: api.pmi-americas.com
11. Geoapify: api.geoapify.com
12. Freshdesk: alfredpay.freshdesk.com/api/v2
13. SendGrid: api.sendgrid.com
14. Twilio: api.twilio.com
15. AiPrise: api-sandbox.aiprise.com (already virtualized)
16. Fireblocks: api.fireblocks.io (already virtualized)
17. (+ 3 more ambiguous: Plaid, Onfido, Persona)

**Top 5 externals to virtualize next (by blast radius):**
1. ExchangeCopter + Consultoramutual — Blocks AP-1084 AR CVU today. ASAP.
2. Transfero — AP-561 Brazil virtual-account. Medium effort.
3. Kambia — AP-561 Colombia PSE/ACH. AES encryption raises complexity.
4. Worldpay — AP-254/424 3DS auth. High effort (webhooks, challenge).
5. STP — AP-792 Mexico SPEI. mTLS setup.
