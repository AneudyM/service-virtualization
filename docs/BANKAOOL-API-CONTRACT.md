# Bankaool / Paymax API Contract Reference

Derived from source code analysis of `ramps-mexico`, `penny-api`, and `liquidity-transaction-service`. No formal API documentation exists; the code is the source of truth.

**Last verified:** 2026-04-12

---

## Two External APIs

AlfredPay integrates with two separate Bankaool-ecosystem services:

| API | Real URL (prod) | Real URL (dev) | Called By | Auth Pattern |
|-----|-----------------|----------------|-----------|-------------|
| **Bankaool Direct Banking** | `https://6ms5ae6ejg.execute-api.us-west-1.amazonaws.com/index.php/api/frontend` | `https://metroapf.ideasoft.mx/index.php/api` | ramps-mexico | OAuth2 (form-urlencoded) |
| **Paymax/SRC Microservice** | `https://7cw230z0i7.execute-api.us-west-1.amazonaws.com/index.php/api/frontend` | Same | penny-api (legacy fiat-account + v2 MexService), liquidity-transaction-service | JSON apiKey/apiSecret or OAuth2 form-urlencoded |

Both are proxied through AWS API Gateway in production. In the local stack, both point at `http://service-virtualization/banks/bankaool`.

---

## API 1: Bankaool Direct Banking API

Called by `ramps-mexico/src/modules/bankaool/bankaool.service.ts`.
All POST bodies use `Content-Type: application/x-www-form-urlencoded` (URLSearchParams).
All authenticated endpoints require `Authorization: Bearer {token}`.

### POST /oauth2/token

Obtain OAuth2 access token.

**Request** (form-urlencoded):
```
username={BANCA_USERNAME}
password={BANCA_PASSWORD}
grant_type={BANCA_GRANT_TYPE}    # "password"
client_id={BANCA_CLIENT_ID}
client_secret={BANCA_CLIENT_SECRET}
```

**Response** (used field: `response.data.access_token`):
```json
{
  "access_token": "string",
  "token_type": "Bearer",
  "expires_in": 3600,
  "scope": "read write transfer"
}
```

**Source:** `bankaool.service.ts:59-87`

---

### GET /v1/cuenta

Get AlfredPay's bank account at Bankaool.

**Headers:** `Authorization: Bearer {token}`

**Response** (used: `response.data` as array):
```json
[
  {
    "id": 1,
    "no_cuenta": "string",
    "alias": "string",
    "saldo": "1000.00",
    "moneda": "MXN",
    "estatus": "ACTIVA",
    "clabe": "string (18 digits)"
  }
]
```

**Source:** `bankaool.service.ts:89-111`

---

### GET /v1/cuenta/{id}/medios-pago

Get payment methods for an account.

**Headers:** `Authorization: Bearer {token}`

**Response** (used: `response.data`):
```json
[
  {
    "id": 1,
    "id_cuenta": 1,
    "tipo": "SPEI",
    "descripcion": "SPEI - Transferencia Interbancaria",
    "activo": true
  }
]
```

**Source:** `bankaool.service.ts:140-162`

---

### POST /v1/consulta-banco

Look up bank by destination account (CLABE).

**Request** (form-urlencoded):
```
cuenta_destino={accountNumber}
```

**Headers:** `Authorization: Bearer {token}`, `Content-Type: application/x-www-form-urlencoded`

**Response** (used: `response.data` as `bankBankaool`):
```json
{
  "codigo_banco": "072",
  "banco": "BANORTE"
}
```

**Interface:**
```typescript
// ramps-mexico/src/common/interfaces/bankaool.interface.ts
export interface bankBankaool {
  codigo_banco: string;
  banco: string;
}
```

**Source:** `bankaool.service.ts:113-138`

---

### POST /v1/token-otp

Generate OTP for transfer approval. Real Bankaool sends SMS.

**Headers:** `Authorization: Bearer {token}`
**Request:** Empty body

**Response** (used: `response.data`):
```json
{
  "error": false,
  "msg": "Token OTP generado exitosamente"
}
```

**Source:** `bankaool.service.ts:164-186`

---

### POST /v1/transferir

Initiate an outbound SPEI transfer.

**Request** (form-urlencoded):
```
id_medio_pago={paymentMethodId}
id_cuenta_origen={sourceAccountId}
cuenta_destino={destinationCLABE}
guardar_cuenta_destino={true|false}
importe={amount}
banco_destino={bankCode}
concepto={description}
referencia={reference}
nombre_beneficiario={beneficiaryName}
token={otpToken}
```

**Headers:** `Authorization: Bearer {token}`, `Content-Type: application/x-www-form-urlencoded`

**Response** (used: `response.data`):
```json
{
  "error": false,
  "msg": "Transferencia registrada",
  "id_tx_pendiente": 123456,
  "clave_rastreo_previa": "BKOL260412XXXXXXXXXX"
}
```

**Source:** `bankaool.service.ts:188-224`

---

### POST /v1/aprobar-transferencia

Approve a pending transfer with OTP.

**Request** (form-urlencoded):
```
id_cuenta={accountId}
token={otpToken}
json_tx={serializedTransaction}
```

**Headers:** `Authorization: Bearer {token}`, `Content-Type: application/x-www-form-urlencoded`

**Response** (used: `response.data`):
```json
{
  "error": false,
  "msg": "Transferencia aprobada exitosamente"
}
```

**Source:** `bankaool.service.ts:226-254`

---

### POST /v1/cobranza

Create a collection deposit reference (for inbound payments).

**Request** (form-urlencoded):
```
id_medio_pago={paymentMethodId}
id_cuenta={accountId}
importe={amount}
nombre_cobranza={description}
```

**Headers:** `Authorization: Bearer {token}`, `Content-Type: application/x-www-form-urlencoded`

**Response** (used: `response.data`):
```json
{
  "error": false,
  "msg": "Cobranza registrada",
  "id_cobro": 12345,
  "clabe": "string (18 digits)",
  "referencia": "string"
}
```

**Source:** `bankaool.service.ts:256-284`

---

### Webhook (inbound from Bankaool to ramps-mexico)

Bankaool sends deposit notifications to ramps-mexico when SPEI funds arrive.

**Payload** (from `webhookBankaool.dto.ts`):
```json
{
  "id_transaccion": "string",
  "nombre_componente": "string",
  "monto": "string",
  "comision": "string",
  "clave_rastreo": "string",
  "tipo": "string",
  "nombre_ordenante": "string",
  "institucion_ordenante": "string",
  "cuenta_ordenante": "string",
  "nombre_beneficiario": "string",
  "institucion_beneficiario": "string",
  "cuenta_beneficiario": "string",
  "concepto": "string",
  "referencia": "string",
  "estatus": "string"
}
```

---

## API 2: Paymax/SRC Microservice

Two auth patterns depending on the caller:

### Pattern A: JSON auth (penny-api v2 MexService, liquidity-transaction-service)

Uses `HttpAdapter` with JSON bodies. Token managed internally.

#### POST /auth/token

**Request** (JSON):
```json
{
  "apiKey": "{LOCALBALANCE_MEX_KEY}",
  "apiSecret": "{LOCALBALANCE_MEX_SECRET}"
}
```

**Response:**
```json
{
  "accessToken": "string"
}
```

**Source:** `penny-api/src/modules/virtual-account/mex/mex.services.ts:30-59`

#### GET /paymax/account/bank/{clabe}

Validate a CLABE and return bank info.

**Headers:** `Authorization: Bearer {token}`

**Response** (note: penny-api v2 MexService does `return response.data`, so body must have a `data` envelope):
```json
{
  "data": {
    "codigo_banco": "BANORTE",
    "banco": "072"
  }
}
```

**Source:** `penny-api/src/modules/virtual-account/mex/mex.services.ts:278-303`
**Consumer:** `penny-api/src/modules/fiat-account/strategies/mexico-validation-strategy.ts:41-58`

### Pattern B: OAuth2 form-urlencoded (penny-api legacy fiat-account service)

Uses raw `axios` with form-urlencoded. Same endpoints as Bankaool Direct for token and bank lookup.

#### POST /oauth2/token

Same as Bankaool Direct API `/oauth2/token` above.
**Source:** `penny-api/src/modules/fiat-account/fiat-account.service.ts:642-679`

#### POST /v1/consulta-banco

Same as Bankaool Direct API `/v1/consulta-banco` above.
Penny-api checks for error strings: `"Banco No Soportado"` in `codigo_banco` and `"Banco NO definido"` in `banco`.
**Source:** `penny-api/src/modules/fiat-account/fiat-account.service.ts:681-725`

### Paymax Payment Endpoints

#### POST /paymax/executen-payment

Execute an off-ramp payment.

**Request** (JSON):
```json
{
  "accountNumber": "string",
  "amount": "string",
  "concepto": "string",
  "referencia": "string",
  "nameBeneficiary": "string"
}
```

**Source:** `ramps-mexico/src/common/dto/webhookBankaool.dto.ts:50-56` (SendPaymentSrcInterface)

---

## Virtual Service Route Mapping

| Real Endpoint | Virtual Route | Notes |
|---------------|--------------|-------|
| `POST /oauth2/token` | `POST /banks/bankaool/oauth2/token` | form-urlencoded |
| `GET /v1/cuenta` | `GET /banks/bankaool/v1/cuenta` | |
| `GET /v1/cuenta/{id}/medios-pago` | `GET /banks/bankaool/v1/cuenta/{id}/medios-pago` | |
| `POST /v1/consulta-banco` | `POST /banks/bankaool/v1/consulta-banco` | form-urlencoded |
| `POST /v1/token-otp` | `POST /banks/bankaool/v1/token-otp` | |
| `POST /v1/transferir` | `POST /banks/bankaool/v1/transferir` | form-urlencoded |
| `POST /v1/aprobar-transferencia` | `POST /banks/bankaool/v1/aprobar-transferencia` | form-urlencoded |
| `POST /v1/cobranza` | `POST /banks/bankaool/v1/cobranza` | form-urlencoded |
| `POST /auth/token` | `POST /auth/token` (root) + `POST /banks/bankaool/auth/token` | JSON |
| `GET /paymax/account/bank/{clabe}` | `GET /paymax/account/bank/{clabe}` (root) | Response wrapped in `{ data: {...} }` |
| `POST /paymax/executen-payment` | `POST /banks/bankaool/paymax/executen-payment` | JSON |

## Environment Variables

### ramps-mexico
```
BANCA_URL=http://service-virtualization/banks/bankaool
BANCA_USERNAME=virtual-bankaool-user
BANCA_PASSWORD=virtual-bankaool-pass
BANCA_GRANT_TYPE=password
BANCA_CLIENT_ID=testclient
BANCA_CLIENT_SECRET=testpass
BANCA_BUSSINES_ID=109
```

### penny-api
```
BANCA_URL=http://service-virtualization/banks/bankaool
BANCA_USERNAME=CELLPAYPROD
BANCA_PASSWORD=7h8j9k0l
BANCA_GRANT_TYPE=password
BANCA_CLIENT_ID=testclient
BANCA_CLIENT_SECRET=testpass
LOCALBALANCE_MEX_URL=http://service-virtualization
LOCALBALANCE_MEX_KEY=pk-ap-lqs-dev.ZBXtDUnV7thGVK39TXo7PotufmRok6cm
LOCALBALANCE_MEX_SECRET=sk-ap-lqs-dev.WbCCyajjdRPUJMYTmEPhdPtOhMhYA4J4
```
