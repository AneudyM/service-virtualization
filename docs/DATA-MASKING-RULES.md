# Production Data Masking Rules

Rules for building a sanitized production mirror for the local stack. Data is pulled from prod via Grafana (read-only), sensitive fields replaced with synthetic but validation-passing values.

**Principle:** Every masked value must pass the same validation the AlfredPay stack applies. If the stack regex-checks a CURP, the fake CURP must pass that regex. If bcrypt-compare runs on a secret, the fake secret must hash correctly.

---

## Email Domain

All masked emails use `nixstock.com`.

Pattern: `test-{sequence}@nixstock.com` (e.g., `test-001@nixstock.com`)

Corporate domains only. The CMS rejects gmail, yahoo, hotmail, outlook.

---

## Masking Rules by Field Type

### Credentials

| Field | Table(s) | Rule | Example |
|-------|----------|------|---------|
| `apiKey` | business | Synthetic plain text, format: `local.{businessId}.{random8}` | `local.170.a3f8b2c1` |
| `apiSecret` | business | Bcrypt hash of `local-test-secret` (same for all businesses) | `$2b$10$qkf.47Osc...` |
| `webhookSecret` | business | Static: `local-webhook-secret-{businessId}` | `local-webhook-secret-170` |
| JWT secrets | env vars | Already synthetic in local-services.json | N/A |

**Known test password:** `local-test-secret` (all apiSecret hashes resolve to this)

### Mexican Identifiers

| Field | Table(s) | Rule | Example |
|-------|----------|------|---------|
| CURP | individual_customers.dni | 18 chars: `[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}` | `GOMA900115HDFNTA01` |
| RFC (persona fisica) | individual_customers, business_customers.taxId | 13 chars: `[A-Z]{4}[0-9]{6}[A-Z0-9]{3}` | `GOMA9001159A1` |
| RFC (persona moral) | business_customers.taxId | 12 chars: `[A-Z]{3}[0-9]{6}[A-Z0-9]{3}` | `NXS060115AB1` |
| CLABE | fiat_account.accountNumber | 18 digits, first 3 = valid bank code, all numeric | `014180000000000018` |

**Valid Mexican bank codes:** 002 (Banamex), 012 (BBVA), 014 (Santander), 021 (HSBC), 036 (Inbursa), 072 (Banorte), 106 (Bank of America), 127 (Azteca), 130 (STP), 166 (Bankaool)

### Argentine Identifiers

| Field | Rule | Example |
|-------|------|---------|
| CUIT/CUIL | 11 digits: `[20|23|24|27|30|33|34][0-9]{8}[0-9]` | `20345678901` |
| CBU | 22 digits, all numeric | `0140000000000000000012` |
| CVU | 22 digits, all numeric | `0000003100000000000001` |
| DNI | 7-8 digits | `34567890` |

### Brazilian Identifiers

| Field | Rule | Example |
|-------|------|---------|
| CPF | 11 digits: `[0-9]{11}` | `12345678909` |
| CNPJ | 14 digits: `[0-9]{14}` | `12345678000195` |
| PIX key | email, phone, CPF, CNPJ, or random UUID | `test-br@nixstock.com` |

### Colombian Identifiers

| Field | Rule | Example |
|-------|------|---------|
| NIT | 9-10 digits with check digit | `9001234567` |
| Cedula | 6-10 digits | `1234567890` |

### US Identifiers

| Field | Rule | Example |
|-------|------|---------|
| SSN | Never store real. Use `000-00-0000` pattern (invalid range) | `000-12-3456` |
| Routing number | 9 digits, valid ABA format | `021000021` |
| Account number | 8-17 digits | `1234567890` |

### Personal Information

| Field | Table(s) | Rule | Example |
|-------|----------|------|---------|
| firstName | individual_customers | Realistic Spanish/Portuguese names, not real people | `Carlos`, `Maria`, `Pedro` |
| lastName | individual_customers | Common Latin American surnames | `Martinez`, `Silva`, `Gonzalez` |
| legalName | business_customers | Synthetic company names | `NixTest Corp MX`, `Virtual Pagos SA` |
| email | individual_customers, customer | `test-{n}@nixstock.com` | `test-042@nixstock.com` |
| phoneNumber | individual_customers, customer | Valid format, `+52 555 000 {seq:4}` for MX | `+52 555 000 0042` |
| address | individual_customers | Plausible fake addresses | `Av. Reforma 456, Col. Centro, CDMX, 06000` |
| city | individual_customers | Real city names (not sensitive) | Keep original or use `Virtual City` |
| zipCode | individual_customers | Valid format for country | `06000` (CDMX) |
| dateOfBirth | individual_customers | Random dates 1970-2000, not real | `1985-06-15` |

### Bank Account Details

| Field | Table(s) | Rule | Example |
|-------|----------|------|---------|
| accountNumber | fiat_account | Country-valid format (see identifier rules above) | CLABE for MX, CBU for AR |
| accountName | fiat_account | Synthetic bank name | `Test Banorte Account` |
| bankCode | fiat_account | Real bank codes (not sensitive, needed for routing) | `072` |
| bankName | fiat_account | Real bank names (not sensitive) | `Banorte` |

### Transaction Data

| Field | Rule |
|-------|------|
| txHash | Keep or regenerate (not PII) |
| clave_rastreo | Keep format, regenerate values |
| amounts | Keep (not sensitive) |
| status | Keep (not sensitive) |
| metadata | Inspect per-record; mask any embedded PII (names, emails, document numbers) |

---

## Tables That Need Masking

### High Priority (contain credentials or PII)

| Table | Sensitive Columns |
|-------|-------------------|
| `business` | apiKey, apiSecret, webhookSecret, email, phone, managerName |
| `individual_customers` | firstName, lastName, email, phoneNumber, address, dni, dateOfBirth, city, zipCode |
| `business_customers` | legalName, taxId, email |
| `customer` | email, phoneNumber |
| `fiat_account` | accountNumber, metadata (may contain ownerName, documentNumber) |

### Medium Priority (may contain embedded PII in JSON)

| Table | Sensitive Columns |
|-------|-------------------|
| `off_ramps` | metadata (may contain beneficiary details) |
| `on_ramps` | metadata |
| `quote` | metadata |
| `payout` | beneficiary_name, account_identifier_value, metadata |
| `payout_batch` | metadata (contains items with beneficiary details) |

### No Masking Needed (configuration/structural data)

| Table | Safe to copy as-is |
|-------|-------------------|
| `supported_pairs` | Currency pairs, min/max amounts |
| `exchange_factor` | FX rates |
| `configurations` | Feature flags, limits |
| `fee_commission` | Fee structures |
| `kyc_country_condition` | KYC requirements per country |
| `aiprise_configuration` | KYC provider config (no secrets) |
| `bank_code` | Bank registry |

---

## Execution Strategy

1. **Pull schema** from prod via Grafana MCP `grafana_get_table_schema` for each table
2. **Pull safe data** directly (configurations, supported_pairs, exchange_factor, bank_code, etc.)
3. **Pull row counts and sample shapes** for PII tables (don't pull actual PII)
4. **Generate synthetic data** locally using the rules above
5. **Seed locally** via SQL scripts or a bootstrap mechanism
6. **Validate** by running the Cucumber test suite against the seeded data

---

## Notes

- All businesses share the same `apiSecret` hash (resolves to `local-test-secret`) for simplicity
- The masking is one-directional: we never push data back to prod
- Grafana connection is read-only (dba_alfredpay via RDS proxy), no write risk
- When new tables are discovered, classify columns here before pulling data
