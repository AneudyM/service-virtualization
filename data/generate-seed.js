#!/usr/bin/env node
/**
 * generate-seed.js
 *
 * Reads Grafana snapshot JSON files from ./prod-snapshots/ and generates
 * a comprehensive SQL seed file ./seed-axen-dev.sql for bootstrapping
 * the local axen_dev PostgreSQL database.
 *
 * Usage: node generate-seed.js
 * No external dependencies required.
 */

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SNAPSHOTS_DIR = path.join(__dirname, 'prod-snapshots');
const OUTPUT_FILE = path.join(__dirname, 'seed-axen-dev.sql');

// UUID v5 namespace for deterministic generation
const UUID_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8'; // DNS namespace

// ---------------------------------------------------------------------------
// Grafana JSON parser
// ---------------------------------------------------------------------------

/**
 * Parse a Grafana snapshot file. Handles both Format 1 (direct response body)
 * and Format 2 (wrapped in a JSON array with a "text" property).
 *
 * Returns { fields: [{name, type}], rows: [{col1: val1, ...}] }
 */
function parseGrafanaFile(filepath) {
  const raw = fs.readFileSync(filepath, 'utf8');
  const json = JSON.parse(raw);

  let frameData;
  let fields;

  if (Array.isArray(json)) {
    // Format 2: [{type:"text", text: "..."}]
    const inner = JSON.parse(json[0].text);
    const frame = inner.A.frames[0];
    fields = frame.fields.map(f => ({ name: f.name, type: f.type }));
    frameData = frame.data; // array of column arrays
  } else {
    // Format 1: {results:{A:{status:200, frames:[...]}}}
    const frame = json.results.A.frames[0];
    fields = frame.schema.fields.map(f => ({ name: f.name, type: f.typeInfo ? f.type : f.type }));
    frameData = frame.data.values; // array of column arrays
  }

  // Convert columnar data to row-oriented
  const rowCount = frameData[0] ? frameData[0].length : 0;
  const rows = [];
  for (let r = 0; r < rowCount; r++) {
    const row = {};
    for (let c = 0; c < fields.length; c++) {
      row[fields[c].name] = frameData[c][r];
    }
    rows.push(row);
  }

  return { fields, rows };
}

// ---------------------------------------------------------------------------
// SQL helpers
// ---------------------------------------------------------------------------

/**
 * Escape a string value for SQL (double single quotes).
 */
function escapeSQL(val) {
  if (val === null || val === undefined) return 'NULL';
  return "'" + String(val).replace(/'/g, "''") + "'";
}

/**
 * Format a value for SQL INSERT based on field type and value.
 * Grafana timestamps come as epoch milliseconds.
 */
function formatValue(val, fieldType, columnName) {
  if (val === null || val === undefined) return 'NULL';

  if (fieldType === 'time') {
    // Epoch milliseconds to PostgreSQL timestamp
    return `to_timestamp(${val} / 1000.0)`;
  }

  if (fieldType === 'boolean') {
    return val ? 'true' : 'false';
  }

  if (fieldType === 'number') {
    return String(val);
  }

  // String type
  return escapeSQL(val);
}

/**
 * Quote a column name for PostgreSQL (wrap in double quotes if it contains
 * uppercase letters or is a reserved word).
 */
function quoteCol(name) {
  const reserved = new Set([
    'order', 'type', 'default', 'from', 'to', 'user', 'table', 'column',
    'constraint', 'index', 'check', 'group', 'select', 'where', 'limit',
  ]);
  if (/[A-Z]/.test(name) || reserved.has(name.toLowerCase())) {
    return `"${name}"`;
  }
  return name;
}

/**
 * Generate INSERT ... ON CONFLICT DO NOTHING statements for a parsed
 * Grafana dataset. Returns an array of SQL strings.
 */
function generateInserts(tableName, parsed, opts = {}) {
  const { idColumn = 'id', extraColumns = {}, excludeColumns = [] } = opts;
  const lines = [];

  const fieldNames = parsed.fields
    .map(f => f.name)
    .filter(n => !excludeColumns.includes(n));

  // Add any extra fixed columns
  const allColumns = [...fieldNames, ...Object.keys(extraColumns)];
  const colList = allColumns.map(quoteCol).join(', ');

  for (const row of parsed.rows) {
    const values = [];
    for (const fname of fieldNames) {
      const field = parsed.fields.find(f => f.name === fname);
      values.push(formatValue(row[fname], field.type, fname));
    }
    // Extra fixed columns
    for (const [, val] of Object.entries(extraColumns)) {
      values.push(val);
    }
    lines.push(`INSERT INTO ${tableName} (${colList}) VALUES (${values.join(', ')}) ON CONFLICT DO NOTHING;`);
  }

  return lines;
}

// ---------------------------------------------------------------------------
// UUID v5 generator (deterministic, no external deps)
// ---------------------------------------------------------------------------

function uuidv5(name, namespace) {
  // Parse namespace UUID to bytes
  const nsBytes = Buffer.from(namespace.replace(/-/g, ''), 'hex');
  const nameBytes = Buffer.from(name, 'utf8');
  const hash = crypto.createHash('sha1').update(nsBytes).update(nameBytes).digest();
  // Set version 5
  hash[6] = (hash[6] & 0x0f) | 0x50;
  // Set variant
  hash[8] = (hash[8] & 0x3f) | 0x80;
  const hex = hash.toString('hex');
  return [
    hex.slice(0, 8),
    hex.slice(8, 12),
    hex.slice(12, 16),
    hex.slice(16, 20),
    hex.slice(20, 32),
  ].join('-');
}

// ---------------------------------------------------------------------------
// Business data
// ---------------------------------------------------------------------------

const BUSINESSES = [
  { id: 1, name: 'Axen', country: 'MEX', KYB: false, webhookEnable: false, custodial: false },
  { id: 2, name: 'Decaf', country: 'USA', KYB: true, webhookEnable: true, custodial: false },
  { id: 7, name: 'Hong Kong Peony', country: 'USA', KYB: false, webhookEnable: true, custodial: false },
  { id: 21, name: 'NixTest Corp CO', country: 'COL', KYB: false, webhookEnable: false, custodial: true },
  { id: 88, name: 'Domipago', country: 'USA', KYB: false, webhookEnable: false, custodial: false },
  { id: 170, name: 'NixTest Corp MX', country: 'MEX', KYB: true, webhookEnable: false, custodial: false },
  { id: 187, name: 'Transfi', country: 'USA', KYB: true, webhookEnable: false, custodial: false },
];

function generateBusinessInserts() {
  const lines = [];
  for (const biz of BUSINESSES) {
    const paddedId = String(biz.id).padStart(4, '0');
    const metadata = '{"method":"PUT","url":"http://localhost:3003/api/v1/send-money/transactions/test/status","headers":{"Content-Type":"application/json"}}';
    lines.push(
      `INSERT INTO business (id, name, "managerName", email, phone, country, address, url, "apiKey", "apiSecret", "webhookSecret", metadata, "KYB", "webhookEnable", custodial, "isDeleted") VALUES (` +
      `${biz.id}, ` +
      `${escapeSQL(biz.name)}, ` +
      `${escapeSQL('Test Manager ' + biz.id)}, ` +
      `${escapeSQL('test-biz-' + biz.id + '@nixstock.com')}, ` +
      `${escapeSQL('+1 555 000 ' + paddedId)}, ` +
      `'${biz.country}'::business_country_enum, ` +
      `${escapeSQL('Test Address ' + biz.id)}, ` +
      `NULL, ` +
      `${escapeSQL('local.' + biz.id + '.testkey01')}, ` +
      `${escapeSQL('$2b$10$dJMGzw56tJwa9NSSaRigYuMBPCgsgb0Me5ksQAqefZdZOnkynIQu.')}, ` +
      `${escapeSQL('local-webhook-secret-' + biz.id)}, ` +
      `${escapeSQL(metadata)}, ` +
      `${biz.KYB}, ` +
      `${biz.webhookEnable}, ` +
      `${biz.custodial}, ` +
      `false` +
      `) ON CONFLICT DO NOTHING;`
    );
  }
  return lines;
}

// ---------------------------------------------------------------------------
// Synthetic customers
// ---------------------------------------------------------------------------

const FIRST_NAMES = [
  'Carlos', 'Maria', 'Juan', 'Ana', 'Luis',
  'Gabriela', 'Diego', 'Sofia', 'Andres', 'Valentina',
  'Pedro', 'Isabella', 'Miguel', 'Camila', 'Jorge',
  'Daniela', 'Ricardo', 'Lucia', 'Fernando', 'Paula',
  'Alejandro', 'Mariana', 'Roberto', 'Elena', 'Santiago',
  'Natalia', 'Oscar', 'Catalina', 'Marcos', 'Adriana',
];

const LAST_NAMES = [
  'Garcia', 'Rodriguez', 'Martinez', 'Lopez', 'Hernandez',
  'Gonzalez', 'Perez', 'Sanchez', 'Ramirez', 'Torres',
  'Flores', 'Rivera', 'Gomez', 'Diaz', 'Cruz',
  'Morales', 'Ortiz', 'Gutierrez', 'Chavez', 'Romero',
  'Mendoza', 'Vargas', 'Castro', 'Ruiz', 'Jimenez',
  'Aguilar', 'Medina', 'Reyes', 'Herrera', 'Silva',
];

const MX_BANK_CODES = ['014', '072', '021', '012', '002', '036', '044', '058'];

function generateCURP(seq) {
  // Pattern: [A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}
  const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
  const s = String(seq).padStart(6, '0');
  const p1 = letters[seq % 26] + letters[(seq + 3) % 26] + letters[(seq + 7) % 26] + letters[(seq + 11) % 26];
  const gender = seq % 2 === 0 ? 'H' : 'M';
  const p2 = letters[(seq + 1) % 26] + letters[(seq + 5) % 26] + letters[(seq + 9) % 26] + letters[(seq + 13) % 26] + letters[(seq + 17) % 26];
  const p3 = String(seq % 100).padStart(2, '0');
  return p1 + s + gender + p2 + p3;
}

function generateCLABE(seq) {
  const bankCode = MX_BANK_CODES[seq % MX_BANK_CODES.length];
  const rest = String(seq * 1000000 + 123456).padStart(15, '0').slice(0, 15);
  return bankCode + rest;
}

function generateCBU(seq) {
  return '0000000' + String(seq * 10000000 + 1234567890).padStart(15, '0').slice(0, 15);
}

function generateCustomers() {
  const customerLines = [];
  const individualLines = [];
  const fiatAccountLines = [];
  let globalSeq = 1;

  for (const biz of BUSINESSES) {
    // 3 individual customers: MX, AR, US
    const countries = [
      { code: 'MX', phoneFmt: (s) => `+52 555 000 ${String(s).padStart(4, '0')}` },
      { code: 'AR', phoneFmt: (s) => `+54 11 0000 ${String(s).padStart(4, '0')}` },
      { code: 'US', phoneFmt: (s) => `+1 555 000 ${String(s).padStart(4, '0')}` },
    ];

    for (const ctry of countries) {
      const seq = globalSeq++;
      const custId = uuidv5(`customer-${biz.id}-${ctry.code}-ind-${seq}`, UUID_NAMESPACE);
      const indCustId = uuidv5(`individual-${biz.id}-${ctry.code}-ind-${seq}`, UUID_NAMESPACE);
      const fiatId = uuidv5(`fiat-${biz.id}-${ctry.code}-ind-${seq}`, UUID_NAMESPACE);

      const firstName = FIRST_NAMES[seq % FIRST_NAMES.length];
      const lastName = LAST_NAMES[seq % LAST_NAMES.length];
      const email = `test-${seq}@nixstock.com`;
      const phone = ctry.phoneFmt(seq);

      // customer row
      customerLines.push(
        `INSERT INTO customer (id, email, "phoneNumber", type, country, business) VALUES (` +
        `${escapeSQL(custId)}, ${escapeSQL(email)}, ${escapeSQL(phone)}, 'INDIVIDUAL', ${escapeSQL(ctry.code)}, ${biz.id}` +
        `) ON CONFLICT DO NOTHING;`
      );

      // individual_customers row
      let dni = null;
      if (ctry.code === 'MX') {
        dni = generateCURP(seq);
      } else if (ctry.code === 'AR') {
        dni = String(20000000 + seq * 111);
      } else {
        dni = String(100000000 + seq);
      }

      individualLines.push(
        `INSERT INTO individual_customers (id, "customerId", "firstName", "lastName", "dateOfBirth", country, status, "statusKyc", dni) VALUES (` +
        `${escapeSQL(indCustId)}, ${escapeSQL(custId)}, ${escapeSQL(firstName)}, ${escapeSQL(lastName)}, ` +
        `'1990-01-15'::date, ${escapeSQL(ctry.code)}, 'Active'::individual_customers_status_enum, 'COMPLETED'::individual_customers_statuskyc_enum, ${escapeSQL(dni)}` +
        `) ON CONFLICT DO NOTHING;`
      );

      // fiat_account row
      let accountNumber, accountType, countryCode, fiatType, bankCode;
      if (ctry.code === 'MX') {
        accountNumber = generateCLABE(seq);
        accountType = 'CLABE';
        countryCode = 'MEX';
        fiatType = 'SPEI';
        bankCode = MX_BANK_CODES[seq % MX_BANK_CODES.length];
      } else if (ctry.code === 'AR') {
        accountNumber = generateCBU(seq);
        accountType = 'CBU';
        countryCode = 'ARG';
        fiatType = 'COELSA';
        bankCode = null;
      } else {
        accountNumber = '021000021' + String(seq).padStart(9, '0');
        accountType = 'CHECKING';
        countryCode = null;
        fiatType = 'BANK_USA';
        bankCode = '021000021';
      }

      fiatAccountLines.push(
        `INSERT INTO fiat_account (id, "customerId", name, "accountNumber", "countryCode", "accountType", type, "DNI", "bankCode", "isDeleted", "isExternal", "default") VALUES (` +
        `${escapeSQL(fiatId)}, ${escapeSQL(custId)}, ${escapeSQL(firstName + ' ' + lastName)}, ${escapeSQL(accountNumber)}, ` +
        `${countryCode ? "'" + countryCode + "'::fiat_account_countrycode_enum" : 'NULL'}, ` +
        `'${accountType}'::fiat_account_accounttype_enum, ` +
        `'${fiatType}'::fiat_account_type_enum, ` +
        `${escapeSQL(dni)}, ` +
        `${bankCode ? escapeSQL(bankCode) : 'NULL'}, ` +
        `false, false, true` +
        `) ON CONFLICT DO NOTHING;`
      );
    }

    // 1 business customer
    {
      const seq = globalSeq++;
      const custId = uuidv5(`customer-${biz.id}-biz-${seq}`, UUID_NAMESPACE);
      const fiatId = uuidv5(`fiat-${biz.id}-biz-${seq}`, UUID_NAMESPACE);

      const email = `test-${seq}@nixstock.com`;
      const phone = `+1 555 000 ${String(seq).padStart(4, '0')}`;

      customerLines.push(
        `INSERT INTO customer (id, email, "phoneNumber", type, country, business) VALUES (` +
        `${escapeSQL(custId)}, ${escapeSQL(email)}, ${escapeSQL(phone)}, 'BUSINESS', 'US', ${biz.id}` +
        `) ON CONFLICT DO NOTHING;`
      );

      // Business customers get a CLABE account (MX-based)
      const accountNumber = generateCLABE(seq);
      fiatAccountLines.push(
        `INSERT INTO fiat_account (id, "customerId", name, "accountNumber", "countryCode", "accountType", type, "isDeleted", "isExternal", "default") VALUES (` +
        `${escapeSQL(fiatId)}, ${escapeSQL(custId)}, ${escapeSQL('Business Account ' + biz.name)}, ${escapeSQL(accountNumber)}, ` +
        `'MEX'::fiat_account_countrycode_enum, ` +
        `'CLABE'::fiat_account_accounttype_enum, ` +
        `'SPEI'::fiat_account_type_enum, ` +
        `false, false, true` +
        `) ON CONFLICT DO NOTHING;`
      );
    }
  }

  return { customerLines, individualLines, fiatAccountLines };
}

// ---------------------------------------------------------------------------
// Stored functions parser
// ---------------------------------------------------------------------------

function parseStoredFunctions() {
  const filepath = path.join(SNAPSHOTS_DIR, 'grafana_stored_functions_raw.json');
  const raw = fs.readFileSync(filepath, 'utf8');
  const json = JSON.parse(raw);
  const inner = JSON.parse(json[0].text);
  const frame = inner.A.frames[0];
  // Single column "definition" containing full CREATE OR REPLACE FUNCTION SQL
  return frame.data[0]; // array of function definition strings
}

// ---------------------------------------------------------------------------
// Main: assemble the seed file
// ---------------------------------------------------------------------------

function main() {
  const sql = [];

  // =========================================================================
  // HEADER
  // =========================================================================
  sql.push('-- ==========================================================================');
  sql.push('-- seed-axen-dev.sql');
  sql.push('-- Generated by generate-seed.js from Grafana production snapshots');
  sql.push(`-- Generated at: ${new Date().toISOString()}`);
  sql.push('-- Target: axen_dev database (penny-api)');
  sql.push('--');
  sql.push('-- Re-runnable: all INSERTs use ON CONFLICT DO NOTHING.');
  sql.push('-- Run against a fresh axen_dev DB after penny-api TypeORM sync.');
  sql.push('--');
  sql.push('-- NOTE: Enum creation and ALTER TYPE ADD VALUE must run outside a');
  sql.push('-- transaction (PostgreSQL limitation). These come first, then the');
  sql.push('-- main data inserts run inside BEGIN/COMMIT.');
  sql.push('-- ==========================================================================');
  sql.push('');

  // =========================================================================
  // SECTION 1: Schema fixes (idempotent) -- outside transaction
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 1: Schema fixes (idempotent, outside transaction)');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');
  sql.push("DO $$ BEGIN ALTER TABLE business ALTER COLUMN country DROP NOT NULL; EXCEPTION WHEN others THEN NULL; END $$;");
  sql.push("DO $$ BEGIN ALTER TABLE aiprise_configuration ALTER COLUMN \"businessId\" DROP NOT NULL; EXCEPTION WHEN others THEN NULL; END $$;");
  sql.push("DO $$ BEGIN ALTER TABLE quote_expiration_config ALTER COLUMN business_id DROP NOT NULL; EXCEPTION WHEN others THEN NULL; END $$;");
  sql.push("DO $$ BEGIN ALTER TABLE exchange_factor ALTER COLUMN \"businessId\" DROP NOT NULL; EXCEPTION WHEN others THEN NULL; END $$;");
  sql.push('ALTER TABLE supported_pairs ADD COLUMN IF NOT EXISTS business_id integer;');
  sql.push('ALTER TABLE supported_pairs ADD COLUMN IF NOT EXISTS "typeCustomer" character varying DEFAULT NULL;');
  sql.push('');
  sql.push('-- webhook_circle and temp_log_circle tables (needed by rampas-penny-api)');
  sql.push(`CREATE TABLE IF NOT EXISTS webhook_circle (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  "idempotencyKey" character varying,
  type character varying,
  status character varying,
  payload jsonb,
  "createdAt" timestamptz DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" timestamptz DEFAULT CURRENT_TIMESTAMP
);`);
  sql.push('');
  sql.push(`CREATE TABLE IF NOT EXISTS temp_log_circle (
  id uuid DEFAULT gen_random_uuid() PRIMARY KEY,
  description text,
  method text,
  url character varying,
  status character varying,
  payload jsonb,
  "createdAt" timestamptz DEFAULT CURRENT_TIMESTAMP,
  "updatedAt" timestamptz DEFAULT CURRENT_TIMESTAMP
);`);
  sql.push('');
  sql.push("ALTER TABLE temp_log_circle ALTER COLUMN description TYPE TEXT;");
  sql.push("ALTER TABLE temp_log_circle ALTER COLUMN method TYPE TEXT;");
  sql.push('');

  // =========================================================================
  // SECTION 2: Enum types (must be outside transaction for ADD VALUE)
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 2: Enum types (outside transaction for ADD VALUE support)');
  sql.push('-- TypeORM usually creates these, but we ensure they exist.');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const enumDefs = [
    {
      name: 'fee_feedtype_enum',
      values: ['internal', 'external', 'markupFee', 'Commission', 'commissionFee', 'processingFee', 'networkFee', 'taxFee', 'crossBorderFee'],
    },
    {
      name: 'supported_pairs_fromcurrency_enum',
      values: ['USDC', 'BRL', 'MXN', 'ARS', 'COP', 'DOP', 'GTQ', 'USDT', 'USD', 'CNY', 'HKD', 'REAP', 'HK_USD', 'PEN', 'PYG', 'EUR', 'BOB', 'CLP'],
    },
    {
      name: 'supported_pairs_tocurrency_enum',
      values: ['USDC', 'BRL', 'MXN', 'ARS', 'COP', 'DOP', 'GTQ', 'USDT', 'USD', 'CNY', 'HKD', 'REAP', 'HK_USD', 'PEN', 'PYG', 'EUR', 'BOB', 'CLP'],
    },
    {
      name: 'fee_currency_enum',
      values: ['USDC', 'BRL', 'MXN', 'ARS', 'COP', 'DOP', 'GTQ', 'USDT', 'USD', 'HKD', 'CNY', 'HK_USD', 'BOB', 'CLP', 'PYG'],
    },
    {
      name: 'business_country_enum',
      values: ['USA', 'DOM', 'HTI', 'GTM', 'MEX', 'ARG', 'COL', 'CHL', 'BRA', 'ZAF', 'URY', 'UKR', 'POL', 'CMR', 'CZE'],
    },
    {
      name: 'supported_chains_chain_enum',
      values: ['ETH', 'XLM', 'MATIC', 'OP', 'ARB', 'TRX', 'SOL', 'POL', 'CELO', 'AVAX', 'BNB', 'BASE'],
    },
    {
      name: 'supported_chains_currency_enum',
      values: ['USDC', 'USDT'],
    },
  ];

  for (const e of enumDefs) {
    const valueList = e.values.map(v => `'${v}'`).join(', ');
    sql.push(`DO $$ BEGIN CREATE TYPE ${e.name} AS ENUM (${valueList}); EXCEPTION WHEN duplicate_object THEN NULL; END $$;`);
    // Also add any missing values to existing enums
    for (const v of e.values) {
      sql.push(`DO $$ BEGIN ALTER TYPE ${e.name} ADD VALUE IF NOT EXISTS '${v}'; EXCEPTION WHEN duplicate_object THEN NULL; END $$;`);
    }
    sql.push('');
  }

  // =========================================================================
  // BEGIN TRANSACTION for all data inserts
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Begin transaction for data inserts');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');
  sql.push('BEGIN;');
  sql.push('');
  sql.push('-- Disable FK checks for bulk loading (re-enabled at COMMIT)');
  sql.push("SET session_replication_role = 'replica';");
  sql.push('');

  // =========================================================================
  // SECTION 3: Business records (synthetic PII)
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 3: Business records (synthetic PII, masked credentials)');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const bizInserts = generateBusinessInserts();
  for (const line of bizInserts) {
    sql.push(line);
  }
  sql.push('');
  sql.push("-- Reset business sequence");
  sql.push("SELECT setval('business_id_seq', (SELECT COALESCE(MAX(id), 1) FROM business));");
  sql.push('');

  // =========================================================================
  // SECTION 4: Configurations
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 4: configurations table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const configurations = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_configurations.json'));
  const configInserts = generateInserts('configurations', configurations);
  sql.push(`-- ${configurations.rows.length} rows`);
  for (const line of configInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 5: Supported chains
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 5: supported_chains table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const supportedChains = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_supported_chains.json'));
  const chainInserts = generateInserts('supported_chains', supportedChains);
  sql.push(`-- ${supportedChains.rows.length} rows`);
  for (const line of chainInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 6: Supported fiat types
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 6: supported_fiat_types table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const supportedFiat = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_supported_fiat_types.json'));
  const fiatInserts = generateInserts('supported_fiat_types', supportedFiat);
  sql.push(`-- ${supportedFiat.rows.length} rows`);
  for (const line of fiatInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 7: Bank list
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 7: bank_list table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const bankList = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_bank_list_raw.json'));
  const bankInserts = generateInserts('bank_list', bankList);
  sql.push(`-- ${bankList.rows.length} rows`);
  for (const line of bankInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 8: AiPrise configuration
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 8: aiprise_configuration table');
  sql.push('-- Callback URLs rewritten to localhost for local stack.');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const aipriseConfig = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_aiprise_configuration_raw.json'));
  // Rewrite callbackUrl and eventCallbackUrl to localhost
  for (const row of aipriseConfig.rows) {
    if (row.callbackUrl && typeof row.callbackUrl === 'string') {
      row.callbackUrl = row.callbackUrl.replace(/^https?:\/\/[^/]+/, 'http://localhost:3003');
    }
    if (row.eventCallbackUrl && typeof row.eventCallbackUrl === 'string') {
      row.eventCallbackUrl = row.eventCallbackUrl.replace(/^https?:\/\/[^/]+/, 'http://localhost:3003');
    }
  }
  const aipriseInserts = generateInserts('aiprise_configuration', aipriseConfig);
  sql.push(`-- ${aipriseConfig.rows.length} rows`);
  for (const line of aipriseInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 9: Supported pairs
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 9: supported_pairs table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const supportedPairs = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_supported_pairs_raw.json'));
  const pairsInserts = generateInserts('supported_pairs', supportedPairs);
  sql.push(`-- ${supportedPairs.rows.length} rows`);
  for (const line of pairsInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 10: Exchange factor
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 10: exchange_factor table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const exchangeFactor = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_exchange_factor_raw.json'));
  const exchangeInserts = generateInserts('exchange_factor', exchangeFactor);
  sql.push(`-- ${exchangeFactor.rows.length} rows`);
  for (const line of exchangeInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 11: Quote expiration config
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 11: quote_expiration_config table');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const quoteExpConfig = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_quote_expiration_config_raw.json'));
  const quoteInserts = generateInserts('quote_expiration_config', quoteExpConfig);
  sql.push(`-- ${quoteExpConfig.rows.length} rows`);
  for (const line of quoteInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 12: Fee templates (commission config rows)
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 12: fee table (commission templates, quoteId IS NULL)');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const feeTemplates = parseGrafanaFile(path.join(SNAPSHOTS_DIR, 'grafana_fee_templates_raw.json'));
  // These are fee rows where quoteId IS NULL. Add quoteId = NULL and quoteInputsId = NULL.
  const feeInserts = generateInserts('fee', feeTemplates, {
    extraColumns: {
      quoteId: 'NULL',
      quoteInputsId: 'NULL',
    },
  });
  sql.push(`-- ${feeTemplates.rows.length} rows`);
  for (const line of feeInserts) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 13: Synthetic customers
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 13: Synthetic test customers');
  sql.push('-- 3 individual (MX, AR, US) + 1 business per business entity');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const { customerLines, individualLines, fiatAccountLines } = generateCustomers();

  sql.push('-- customer table rows');
  for (const line of customerLines) {
    sql.push(line);
  }
  sql.push('');

  sql.push('-- individual_customers table rows');
  for (const line of individualLines) {
    sql.push(line);
  }
  sql.push('');

  sql.push('-- fiat_account table rows');
  for (const line of fiatAccountLines) {
    sql.push(line);
  }
  sql.push('');

  // =========================================================================
  // SECTION 14: Stored functions
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Section 14: Stored functions from production');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');

  const storedFunctions = parseStoredFunctions();
  sql.push(`-- ${storedFunctions.length} functions`);
  for (const funcDef of storedFunctions) {
    // Each definition already starts with CREATE OR REPLACE FUNCTION
    // Clean up Windows line endings and add a semicolon if missing
    let cleaned = funcDef.replace(/\r\n/g, '\n').replace(/\r/g, '\n').trim();
    if (!cleaned.endsWith(';')) {
      cleaned += ';';
    }
    sql.push('');
    sql.push(cleaned);
  }
  sql.push('');

  // =========================================================================
  // FOOTER
  // =========================================================================
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('-- Finalize: commit transaction');
  sql.push('-- --------------------------------------------------------------------------');
  sql.push('');
  sql.push('-- Re-enable FK checks');
  sql.push("SET session_replication_role = 'origin';");
  sql.push('');
  sql.push('COMMIT;');
  sql.push('');
  sql.push('-- ==========================================================================');
  sql.push('-- Seed complete.');
  sql.push('-- ==========================================================================');
  sql.push('');

  // Write output
  const output = sql.join('\n');
  fs.writeFileSync(OUTPUT_FILE, output, 'utf8');

  // Summary
  console.log('Generated: ' + OUTPUT_FILE);
  console.log('');
  console.log('Summary:');
  console.log(`  configurations:          ${configurations.rows.length} rows`);
  console.log(`  supported_chains:        ${supportedChains.rows.length} rows`);
  console.log(`  supported_fiat_types:    ${supportedFiat.rows.length} rows`);
  console.log(`  bank_list:               ${bankList.rows.length} rows`);
  console.log(`  aiprise_configuration:   ${aipriseConfig.rows.length} rows`);
  console.log(`  supported_pairs:         ${supportedPairs.rows.length} rows`);
  console.log(`  exchange_factor:         ${exchangeFactor.rows.length} rows`);
  console.log(`  quote_expiration_config: ${quoteExpConfig.rows.length} rows`);
  console.log(`  fee (templates):         ${feeTemplates.rows.length} rows`);
  console.log(`  business:                ${BUSINESSES.length} rows`);
  console.log(`  customer:                ${customerLines.length} rows`);
  console.log(`  individual_customers:    ${individualLines.length} rows`);
  console.log(`  fiat_account:            ${fiatAccountLines.length} rows`);
  console.log(`  stored functions:        ${storedFunctions.length} functions`);
}

main();
