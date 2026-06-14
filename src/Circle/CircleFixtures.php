<?php

declare(strict_types=1);

namespace App\Circle;

use App\Core\Database;

/**
 * Static and seed data for the Circle CPN API.
 *
 * Authoritative source: https://developers.circle.com/openapi/cpn-ofi.yaml
 *
 * Fields and enum values must match the Circle API.
 */
final class CircleFixtures
{
    /**
     * Fixed P-256 test JWK used for the certificate on every quote.
     *
     * This is the same dev-fallback key that cpn-ofi-api's CryptoService uses
     * internally (see cpn-ofi-api/src/modules/crypto/application/crypto.service.ts),
     * so a downstream payment flow that encrypts against the stored quote JWK
     * will produce a payload decryptable by the same fallback key.
     *
     * Single fixed key; generate dynamic pairs if payment flow needs it.
     */
    public static function testJwk(): array
    {
        return [
            'kty' => 'EC',
            'crv' => 'P-256',
            'kid' => '263521881931753643998528753619816524468853605762',
            'x'   => 'YdjOeAmlNfWV0xIryFAivcp9of21s0c-JhyGEOINV2Y',
            'y'   => 'n621ve_OV_p3jdocxtNkAk4uaKcYR2XWYUu1NMzBei8',
        ];
    }

    /**
     * Returns a certificate object in the shape Circle CPN returns.
     */
    public static function testCertificate(): array
    {
        return [
            'id'      => '00000000-0000-4000-8000-000000000001',
            'certPem' => self::testCertPemBase64(),
            'domain'  => 'circle.local.virtual',
            'jwk'     => self::testJwk(),
        ];
    }

    /**
     * Placeholder PEM (not a real certificate; cpn-ofi-api stores it but does
     * not parse it for payment encryption). May need a real one later.
     */
    private static function testCertPemBase64(): string
    {
        // Base64-encoded placeholder string. cpn-ofi-api persists this on the
        // quote entity without parsing it in the quote-only flow.
        return base64_encode(
            "-----BEGIN CERTIFICATE-----\n" .
            "VIRTUAL CIRCLE CPN TEST CERTIFICATE\n" .
            "DO NOT USE IN PRODUCTION\n" .
            "-----END CERTIFICATE-----\n"
        );
    }

    /**
     * Hardcoded fallback rate table. Used when virtual_rates has no row for a
     * given (source, destination) pair. Format: [source][destination] = rate.
     *
     * Values match approximate real-world mid-market rates as of 2026-04-10.
     */
    private const FALLBACK_RATES = [
        'USDC' => [
            'USD' => '0.9950',
            'CNY' => '7.1000',
            'MXN' => '18.0000',
            'BRL' => '5.1200',
            'EUR' => '0.9200',
            'ARS' => '1025.5000',
            'COP' => '4050.0000',
            'HKD' => '7.8200',
        ],
        'USDT' => [
            'USD' => '0.9950',
            'CNY' => '7.1000',
            'MXN' => '18.0000',
        ],
    ];

    /**
     * Look up an exchange rate.
     * Tries virtual_rates table first, falls back to the constant above.
     *
     * @return array{rate: string, pair: string, rawRate: string}
     */
    public static function lookupRate(string $sourceCurrency, string $destCurrency): array
    {
        $source = strtoupper($sourceCurrency);
        $dest   = strtoupper($destCurrency);
        $pair   = "{$source}/{$dest}";

        // Try virtual_rates table first.
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare("
                SELECT rate FROM virtual_rates
                WHERE source_currency = :src AND destination_currency = :dst
                LIMIT 1
            ");
            $stmt->execute(['src' => $source, 'dst' => $dest]);
            $row = $stmt->fetch();
            if ($row && isset($row['rate'])) {
                $rate = (string) $row['rate'];
                return ['rate' => $rate, 'pair' => $pair, 'rawRate' => self::rawRate($rate)];
            }
        } catch (\Throwable $e) {
            // Fall through to constants (table may not exist yet).
        }

        $rate = self::FALLBACK_RATES[$source][$dest] ?? null;
        if ($rate === null) {
            throw new \RuntimeException(
                "No rate configured for {$source} -> {$dest}. " .
                'Add a row to virtual_rates or extend CircleFixtures::FALLBACK_RATES.'
            );
        }

        return ['rate' => $rate, 'pair' => $pair, 'rawRate' => self::rawRate($rate)];
    }

    /**
     * Compute the "raw" (pre-spread) rate. Circle returns both a post-spread
     * effective rate and a raw mid-market rate; we synthesize the raw one by
     * applying a 0.3% markup to the stored rate. Uses plain float math to
     * avoid the bcmath PHP extension dependency.
     */
    private static function rawRate(string $rate): string
    {
        $value = (float) $rate;
        if ($value <= 0) {
            return $rate;
        }
        return number_format($value * 1.003, 8, '.', '');
    }

    /**
     * Compute a fees object in Circle's shape: total + breakdown of
     * BFI_TRANSACTION_FEE (80%) and TAX_FEE (20%), both in the source currency.
     *
     * Fee is 0.5% of the source amount, minimum 0.50 USDC equivalent.
     *
     * @return array
     */
    public static function buildFees(string $sourceAmount, string $sourceCurrency): array
    {
        $amount = (float) $sourceAmount;
        $total  = max($amount * 0.005, 0.50);
        $bfi    = round($total * 0.80, 2);
        $tax    = round($total - $bfi, 2);

        return [
            'totalAmount' => [
                'amount'   => number_format($total, 2, '.', ''),
                'currency' => $sourceCurrency,
            ],
            'breakdown' => [
                [
                    'type'   => 'BFI_TRANSACTION_FEE',
                    'amount' => [
                        'amount'   => number_format($bfi, 2, '.', ''),
                        'currency' => $sourceCurrency,
                    ],
                ],
                [
                    'type'   => 'TAX_FEE',
                    'amount' => [
                        'amount'   => number_format($tax, 2, '.', ''),
                        'currency' => $sourceCurrency,
                    ],
                ],
            ],
        ];
    }

    /**
     * Fiat settlement time window by payment method.
     * WIRE/FEDWIRE: 1-2 hours, SPEI/PIX: minutes.
     */
    public static function fiatSettlementTime(string $paymentMethodType): array
    {
        return match (strtoupper($paymentMethodType)) {
            'SPEI', 'PIX', 'FAST', 'FPS', 'INSTA-PAY', 'IMPS', 'NEQUI', 'PESONET'
                => ['min' => '1', 'max' => '5', 'unit' => 'MINUTES'],
            'WIRE', 'FEDWIRE', 'CHATS', 'CIPS', 'FTS', 'RTGS'
                => ['min' => '1', 'max' => '2', 'unit' => 'HOURS'],
            'SEPA', 'BANK-TRANSFER', 'NEFT', 'AANI'
                => ['min' => '1', 'max' => '2', 'unit' => 'DAYS'],
            default
                => ['min' => '1', 'max' => '2', 'unit' => 'HOURS'],
        };
    }

    /**
     * Valid Circle CPN paymentMethodType enum values (from the OpenAPI spec).
     * FEDWIRE is not in Circle's enum but gets sent for US corridors.
     */
    public const VALID_PAYMENT_METHODS = [
        'AANI', 'BANK-TRANSFER', 'CHATS', 'CIPS', 'FAST', 'FPS', 'FTS',
        'IMPS', 'INSTA-PAY', 'NEFT', 'NEQUI', 'PESONET', 'PIX', 'RTGS',
        'SEPA', 'SPEI', 'WIRE',
        // Non-standard:
        'FEDWIRE',
    ];

    public const VALID_BLOCKCHAINS = [
        'SOL', 'MATIC', 'ETH', 'SOL-DEVNET', 'MATIC-AMOY', 'ETH-SEPOLIA',
    ];
}
