<?php

declare(strict_types=1);

namespace App\Bridge;

/**
 * Bridge API: token generation and T&C URLs.
 */
final class BridgeService
{
    /**
     * POST /v1/auth/token -> accessToken
     */
    public static function generateAccessToken(): string
    {
        return 'virtual-bridge-' . bin2hex(random_bytes(16));
    }

    /**
     * POST /v1/bridge/terms-conditions -> data.url
     *
     * URL must be browser-accessible, loaded in an iframe, and must already
     * contain a '?' so CMS backend can append '&redirect_uri=...'
     */
    public static function getTermsConditionsUrl(): string
    {
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';
        return $baseUrl . '/bridge/tos-page?virtual=true';
    }
}
