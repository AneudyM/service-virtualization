<?php

declare(strict_types=1);

namespace App\Aiprise;

/**
 * Static registry of AiPrise template definitions.
 *
 * Each template defines country-specific behavior: which checks are enabled,
 * what identity number format is expected, which document types are accepted,
 * and how the webhook response should be shaped.
 *
 * Template IDs come from penny-api's aiprise_configuration table / env vars.
 * The virtual service uses these definitions to:
 *   - Validate identity number formats (CPF, CURP, SSN, etc.)
 *   - Conditionally include response sections (id_info, face_match, aml, etc.)
 *   - Render template-specific fields on the verify page
 *   - Generate country-appropriate extracted data in webhooks
 */
final class TemplateRegistry
{
    // ── KYC Templates ────────────────────────────────────────────────────────

    private const TEMPLATES = [
        // Mexico / Argentina / Default
        '8f46470a-7fb3-423f-835d-b3813f92bc39' => [
            'type'                   => 'kyc',
            'country'                => 'MX',
            'label'                  => 'Mexico / Argentina / Default',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'CURP',
            'id_number_placeholder'  => 'ABCD123456HDFRRN01',
            'id_number_pattern'      => '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['CURP', 'NATIONAL_ID'],
            'virtual_id_number'      => 'GARC850101HDFRRN01',
        ],

        // Brazil (CPF)
        'ba469e70-a1f8-4c76-b9fb-0a5188b1d3f7' => [
            'type'                   => 'kyc',
            'country'                => 'BR',
            'label'                  => 'Brazil (CPF)',
            'id_number_type'         => 'TAX_ID',
            'id_number_label'        => 'CPF',
            'id_number_placeholder'  => '12345678909',
            'id_number_pattern'      => '/^\d{11}$/',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['NATIONAL_ID'],
            'virtual_id_number'      => '12345678909',
        ],

        // Colombia
        '015fcb01-b85e-416a-96f6-0d5e50426588' => [
            'type'                   => 'kyc',
            'country'                => 'CO',
            'label'                  => 'Colombia',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'Cédula de Ciudadanía',
            'id_number_placeholder'  => '1234567890',
            'id_number_pattern'      => '/^\d{6,10}$/',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['NATIONAL_ID'],
            'virtual_id_number'      => '1098765432',
        ],

        // USA
        '95fb94d9-48a0-4af8-bb41-56e92a7aeea7' => [
            'type'                   => 'kyc',
            'country'                => 'US',
            'label'                  => 'United States',
            'id_number_type'         => 'SSN9',
            'id_number_label'        => 'SSN',
            'id_number_placeholder'  => '123-45-6789',
            'id_number_pattern'      => '/^\d{3}-?\d{2}-?\d{4}$/',
            'id_type'                => 'DRIVER_LICENSE',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['DRIVER_LICENSE', 'PASSPORT'],
            'field_match_types'      => ['DRIVER_LICENSE', 'PASSPORT'],
            'virtual_id_number'      => '123-45-6789',
        ],

        // China
        'bd93942f-220c-4307-b165-d6f3ecc18b62' => [
            'type'                   => 'kyc',
            'country'                => 'CN',
            'label'                  => 'China',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'National ID',
            'id_number_placeholder'  => '110101199001011234',
            'id_number_pattern'      => '/^\d{17}[\dX]$/i',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['NATIONAL_ID'],
            'virtual_id_number'      => '110101199001011234',
        ],

        // Argentina (DNI)
        'a1b2c3d4-5678-90ab-cdef-argentina001' => [
            'type'                   => 'kyc',
            'country'                => 'AR',
            'label'                  => 'Argentina',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'DNI',
            'id_number_placeholder'  => '12345678',
            'id_number_pattern'      => '/^\d{7,8}$/',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['NATIONAL_ID', 'DNI'],
            'virtual_id_number'      => '30123456',
        ],

        // Bankaool (Mexico partner)
        '95594745-fcbd-44bc-a185-3f0ead3fc011' => [
            'type'                   => 'kyc',
            'country'                => 'MX',
            'label'                  => 'Bankaool (Mexico)',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'CURP',
            'id_number_placeholder'  => 'ABCD123456HDFRRN01',
            'id_number_pattern'      => '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['CURP', 'NATIONAL_ID'],
            'virtual_id_number'      => 'GARC850101HDFRRN01',
        ],

        // Microblink (no AML)
        'f80058dd-313e-4916-9996-50d900bb7173' => [
            'type'                   => 'kyc',
            'country'                => 'MX',
            'label'                  => 'Microblink (Mexico)',
            'id_number_type'         => 'NATIONAL_ID',
            'id_number_label'        => 'CURP',
            'id_number_placeholder'  => 'ABCD123456HDFRRN01',
            'id_number_pattern'      => '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/i',
            'id_type'                => 'NATIONAL_ID',
            'checks'                 => ['id_check', 'face_match', 'liveness'],
            'doc_types'              => ['NATIONAL_ID'],
            'field_match_types'      => ['CURP', 'NATIONAL_ID'],
            'virtual_id_number'      => 'GARC850101HDFRRN01',
        ],

        // ── KYB Templates ────────────────────────────────────────────────────

        // KYB Default
        'd0cd41c6-11ad-4d03-b446-3198d6e940c5' => [
            'type'                   => 'kyb',
            'country'                => 'MX',
            'label'                  => 'KYB Default',
            'id_number_type'         => null,
            'id_number_label'        => null,
            'id_number_placeholder'  => null,
            'id_number_pattern'      => null,
            'id_type'                => null,
            'checks'                 => ['business_info', 'documents', 'officers', 'aml'],
            'doc_types'              => [],
            'field_match_types'      => [],
            'virtual_id_number'      => null,
            'kyb_documents'          => [
                ['file_type' => 'SHAREHOLDERS_REGISTRY',       'file_name' => 'virtual_shareholders_registry.pdf'],
                ['file_type' => 'ADDRESS_PROOF_DOCUMENT',      'file_name' => 'virtual_proof_of_address.pdf'],
                ['file_type' => 'ARTICLES_OF_INCORPORATION',   'file_name' => 'virtual_articles_of_incorporation.pdf'],
            ],
        ],

        // KYB Bankaool
        '38d48055-c6ee-452f-98cf-84085f542cc1' => [
            'type'                   => 'kyb',
            'country'                => 'MX',
            'label'                  => 'KYB Bankaool',
            'id_number_type'         => null,
            'id_number_label'        => null,
            'id_number_placeholder'  => null,
            'id_number_pattern'      => null,
            'id_type'                => null,
            'checks'                 => ['business_info', 'documents', 'officers', 'aml'],
            'doc_types'              => [],
            'field_match_types'      => [],
            'virtual_id_number'      => null,
            'kyb_documents'          => [
                ['file_type' => 'ARTICLES_OF_INCORPORATION',   'file_name' => 'virtual_articles_of_incorporation.pdf'],
                ['file_type' => 'ADDRESS_PROOF_DOCUMENT',      'file_name' => 'virtual_proof_of_address.pdf'],
                ['file_type' => 'TAX_CERTIFICATE',             'file_name' => 'virtual_tax_certificate.pdf'],
            ],
        ],
    ];

    /**
     * Default template used for unknown/missing template IDs.
     * Enables all checks and uses generic labels — backward compatible with
     * the previous behavior where every section was always included.
     */
    private const DEFAULT_TEMPLATE = [
        'type'                   => 'kyc',
        'country'                => 'MX',
        'label'                  => 'Unknown Template',
        'id_number_type'         => 'NATIONAL_ID',
        'id_number_label'        => 'Identity Number',
        'id_number_placeholder'  => '',
        'id_number_pattern'      => null,
        'id_type'                => 'NATIONAL_ID',
        'checks'                 => ['id_check', 'face_match', 'liveness', 'aml'],
        'doc_types'              => ['NATIONAL_ID', 'PASSPORT', 'DRIVER_LICENSE'],
        'field_match_types'      => ['NATIONAL_ID'],
        'virtual_id_number'      => 'VIRTUAL-ID-001',
    ];

    /**
     * Look up a template by its UUID. Returns null if not found.
     */
    public static function get(string $templateId): ?array
    {
        if ($templateId === '' || !isset(self::TEMPLATES[$templateId])) {
            return null;
        }

        $template = self::TEMPLATES[$templateId];
        $template['id'] = $templateId;
        return $template;
    }

    /**
     * Look up a template, falling back to a default that enables all checks.
     * This ensures backward compatibility for unknown or missing template IDs.
     */
    public static function getOrDefault(string $templateId): array
    {
        $template = self::get($templateId);
        if ($template !== null) {
            return $template;
        }

        $default = self::DEFAULT_TEMPLATE;
        $default['id'] = $templateId ?: 'default';
        return $default;
    }

    /**
     * Check whether a template ID is a KYB (business) template.
     */
    public static function isKyb(string $templateId): bool
    {
        $template = self::get($templateId);
        return $template !== null && $template['type'] === 'kyb';
    }

    /**
     * Validate an identity number against a template's format rules.
     *
     * @return array{valid: bool, error: ?string}
     */
    public static function validateIdentityNumber(string $templateId, string $value): array
    {
        $template = self::getOrDefault($templateId);

        if ($template['id_number_pattern'] === null) {
            // No validation rule — accept anything
            return ['valid' => true, 'error' => null];
        }

        if ($value === '') {
            return ['valid' => true, 'error' => null]; // Optional field
        }

        // Regex format check
        if (!preg_match($template['id_number_pattern'], $value)) {
            $label = $template['id_number_label'] ?? 'Identity number';
            return [
                'valid' => false,
                'error' => "{$label} format is invalid. Expected format: {$template['id_number_placeholder']}",
            ];
        }

        // CPF check-digit validation (Brazil)
        if ($template['id_number_type'] === 'TAX_ID' && $template['country'] === 'BR') {
            if (!self::validateCpf($value)) {
                return [
                    'valid' => false,
                    'error' => 'CPF check digits are invalid',
                ];
            }
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Get the JavaScript-compatible regex pattern (without PHP delimiters/flags).
     * Used for the HTML5 pattern attribute on the verify page.
     */
    public static function getJsPattern(string $templateId): string
    {
        $template = self::getOrDefault($templateId);
        $pattern = $template['id_number_pattern'] ?? '';
        if ($pattern === '') {
            return '';
        }

        // Strip PHP regex delimiters and flags: /^pattern$/i → ^pattern$
        if (preg_match('#^/(.+)/[a-z]*$#s', $pattern, $m)) {
            return $m[1];
        }

        return $pattern;
    }

    /**
     * Get all registered template IDs.
     */
    public static function listTemplateIds(): array
    {
        return array_keys(self::TEMPLATES);
    }

    // ── Display Metadata ──────────────────────────────────────────────────

    /** Human-readable labels for document types, keyed by "COUNTRY:DOC_TYPE". */
    private const DOC_TYPE_META = [
        'MX:NATIONAL_ID'   => ['label' => 'CURP',                    'description' => 'Govt.-issued National ID number'],
        'BR:NATIONAL_ID'   => ['label' => 'CPF',                     'description' => 'Govt.-issued Tax ID number'],
        'CO:NATIONAL_ID'   => ['label' => 'Cédula de Ciudadanía',    'description' => 'Govt.-issued National ID'],
        'AR:NATIONAL_ID'   => ['label' => 'DNI',                     'description' => 'Documento Nacional de Identidad'],
        'US:DRIVER_LICENSE' => ['label' => "Driver's License",       'description' => 'State-issued Driver\'s License'],
        'US:PASSPORT'      => ['label' => 'Passport',                'description' => 'U.S. Passport Book'],
        'CN:NATIONAL_ID'   => ['label' => 'National ID',             'description' => 'Govt.-issued Resident Identity Card'],
    ];

    private const COUNTRY_NAMES = [
        'MX' => 'Mexico',
        'BR' => 'Brazil',
        'CO' => 'Colombia',
        'AR' => 'Argentina',
        'US' => 'United States',
        'CN' => 'China',
    ];

    /**
     * Get display metadata (label + description) for a document type in a country.
     *
     * @return array{label: string, description: string}
     */
    public static function getDocTypeMeta(string $country, string $docType): array
    {
        $key = strtoupper($country) . ':' . strtoupper($docType);
        if (isset(self::DOC_TYPE_META[$key])) {
            return self::DOC_TYPE_META[$key];
        }
        // Fallback: humanise the doc_type string
        return [
            'label'       => str_replace('_', ' ', ucwords(strtolower($docType), '_')),
            'description' => 'Identity document',
        ];
    }

    /**
     * Get the full country name for an ISO-2 code.
     */
    public static function getCountryName(string $countryCode): string
    {
        return self::COUNTRY_NAMES[strtoupper($countryCode)] ?? $countryCode;
    }

    // ── Private Validators ──────────────────────────────────────────────────

    /**
     * Validate a Brazilian CPF using the standard check-digit algorithm (mod 11).
     *
     * CPF is 11 digits: 9 base digits + 2 check digits.
     * Digit 10 = 11 - (sum(d[i] * (10-i) for i=0..8) mod 11), mapped: <2 → 0
     * Digit 11 = 11 - (sum(d[i] * (11-i) for i=0..9) mod 11), mapped: <2 → 0
     */
    private static function validateCpf(string $cpf): bool
    {
        // Must be 11 digits
        if (!preg_match('/^\d{11}$/', $cpf)) {
            return false;
        }

        // Reject all-same-digit CPFs (e.g., 00000000000, 11111111111)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        $digits = array_map('intval', str_split($cpf));

        // First check digit (position 9)
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $digits[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $check1 = ($remainder < 2) ? 0 : (11 - $remainder);

        if ($digits[9] !== $check1) {
            return false;
        }

        // Second check digit (position 10)
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $digits[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $check2 = ($remainder < 2) ? 0 : (11 - $remainder);

        return $digits[10] === $check2;
    }
}
