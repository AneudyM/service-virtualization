<?php

declare(strict_types=1);

namespace App\Bridge;

/**
 * Virtual Bridge Service — handles token generation and T&C URL creation.
 *
 * Only implements the fields that penny-api actually reads from Bridge responses.
 * Source of truth: penny-api/src/modules/customer/bridge/usa.services.ts
 */
final class BridgeService
{
    /**
     * Generate a virtual access token.
     *
     * Real Bridge: POST /v1/auth/token
     * penny-api reads: response.accessToken
     */
    public static function generateAccessToken(): string
    {
        return 'virtual-bridge-' . bin2hex(random_bytes(16));
    }

    /**
     * Build the Terms & Conditions page URL.
     *
     * Real Bridge: POST /v1/bridge/terms-conditions
     * penny-api reads: response.data (returned as-is)
     * CMS backend reads: data.url
     * CMS backend appends: &redirect_uri={FRONT_BASE_URL}bridge-admin/{customerId}
     *
     * The URL must be browser-accessible (loaded in an iframe) and must already
     * contain a '?' so the CMS backend can append '&redirect_uri=...'
     */
    public static function getTermsConditionsUrl(): string
    {
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';
        return $baseUrl . '/bridge/tos-page?virtual=true';
    }
}
