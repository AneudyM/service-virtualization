<?php

declare(strict_types=1);

namespace App\Controller;

use App\Bridge\BridgeService;
use App\Core\JsonResponse;

/**
 * Virtual Bridge API Controller — stubs for the real Bridge API (api.bridge.xyz).
 *
 * Two layers exist:
 *   1. usa-bridge-integration (AlfredPay wrapper) — calls these endpoints
 *   2. These stubs — return minimal valid responses
 *
 * Source of truth for response shapes:
 *   Bridge API Collection.postman_collection.json
 *   Bridge docs: https://apidocs.bridge.xyz
 *
 * Routes (real Bridge API surface, called by usa-bridge-integration):
 *   POST /customers/tos_links             -- T&C URL (returns { url: "..." })
 *   POST /kyc_links                       -- KYC links (returns kyc_link, tos_link, etc.)
 *
 * Routes (AlfredPay wrapper surface, called by penny-api directly):
 *   POST /v1/auth/token                   -- Authentication (returns accessToken)
 *   POST /v1/bridge/terms-conditions      -- T&C URL (wrapped in { data: { url } })
 *
 * Browser-facing:
 *   GET  /bridge/tos-page                 -- HTML page served in iframe
 */
final class BridgeController
{
    /**
     * POST /v1/auth/token
     *
     * penny-api sends: { apiKey, apiSecret }
     * penny-api reads: response.accessToken
     */
    public static function authToken(array $body): never
    {
        // We don't validate credentials — any request gets a token
        JsonResponse::send([
            'accessToken' => BridgeService::generateAccessToken(),
        ], 200);
    }

    /**
     * POST /v1/bridge/terms-conditions
     *
     * penny-api sends: {} (empty body) with Authorization: Bearer header
     * penny-api's httpAdapter strips the axios wrapper (returns response.data)
     * penny-api then reads: response.data (the inner data field)
     * CMS backend reads: data.url from penny-api's wrapped response
     *
     * The real AlfredPay Bridge wrapper returns: { data: { url: "..." } }
     * penny-api's code checks: if (!response || !response.data) throw
     * Then returns: response.data → { url: "..." }
     */
    public static function termsConditions(array $body): never
    {
        JsonResponse::send([
            'data' => [
                'url' => BridgeService::getTermsConditionsUrl(),
            ],
        ], 200);
    }

    // ── Real Bridge API surface (called by usa-bridge-integration) ──────────

    /**
     * POST /customers/tos_links
     *
     * Real Bridge API endpoint.
     * usa-bridge-integration sends: {} with Api-Key + Idempotency-Key headers
     * Response shape from Postman collection: { "url": "<string>" }
     */
    public static function customersTosLinks(array $body): never
    {
        JsonResponse::send([
            'url' => BridgeService::getTermsConditionsUrl(),
        ], 201);
    }

    /**
     * POST /kyc_links
     *
     * Real Bridge API endpoint.
     * usa-bridge-integration sends: { full_name, email, type } with Api-Key header
     * Response shape from Postman collection:
     *   { id, customer_id, full_name, email, kyc_link, kyc_status, tos_link, tos_status, created_at }
     */
    public static function kycLinks(array $body): never
    {
        $id = 'kyc-' . bin2hex(random_bytes(8));
        $customerId = 'cust-' . bin2hex(random_bytes(8));
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';

        JsonResponse::send([
            'id'                => $id,
            'customer_id'       => $customerId,
            'full_name'         => $body['full_name'] ?? 'Virtual User',
            'email'             => $body['email'] ?? 'virtual@test.com',
            'kyc_link'          => $baseUrl . '/bridge/tos-page?virtual=true&type=kyc',
            'kyc_status'        => 'pending',
            'rejection_reasons' => [],
            'tos_link'          => $baseUrl . '/bridge/tos-page?virtual=true&type=tos',
            'tos_status'        => 'approved',
            'created_at'        => date('c'),
        ], 200);
    }

    // ── Browser-facing ───────────────────────────────────────────────────────

    /**
     * GET /bridge/tos-page
     *
     * Browser-facing HTML page loaded in an iframe by the CMS frontend.
     * Shows a simple T&C acceptance UI. When the user clicks Accept,
     * redirects to the redirect_uri with a virtual signed_agreement_id.
     *
     * Query params (appended by CMS backend):
     *   - redirect_uri: where to redirect after acceptance
     *   - virtual: marker (from our URL)
     */
    public static function tosPage(): never
    {
        $redirectUri = $_GET['redirect_uri'] ?? '';
        // Must be a valid UUID — penny-api validates with ParseUUIDPipe
        $signedAgreementId = sprintf(
            '%s-%s-4%s-%s%s-%s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            substr(bin2hex(random_bytes(2)), 1),
            dechex(8 | (ord(random_bytes(1)) & 3)),
            substr(bin2hex(random_bytes(2)), 1),
            bin2hex(random_bytes(6))
        );

        // Build the redirect URL with signed_agreement_id
        $separator = str_contains($redirectUri, '?') ? '&' : '?';
        $redirectUrl = $redirectUri
            ? $redirectUri . $separator . 'signed_agreement_id=' . $signedAgreementId
            : '';

        // Serve HTML directly — this is not a JSON API endpoint
        // Design matches the real Bridge T&C page at compliance.sandbox.bridge.xyz
        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);

        $acceptButton = $redirectUrl
            ? "<button onclick=\"window.location.href='{$redirectUrl}'\">Accept</button>"
            : "<button disabled>Accept</button><p class=\"no-redirect\">No redirect_uri provided. Cannot proceed.</p>";

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Terms of Service | Bridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: #fff;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        main {
            max-width: 24rem;
            margin: 0 auto;
            height: 100vh;
        }
        .container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 1.5rem;
        }
        .card {
            position: relative;
            width: 100%;
            max-width: 28rem;
            border: 1px solid #e2e8f0;
            border-bottom-width: 2px;
            border-radius: 0.5rem;
            background: #fff;
            padding: 2rem;
        }
        @media (min-width: 640px) {
            .card {
                width: 420px;
                padding: 3rem;
            }
        }
        .vs-badge {
            position: fixed; top: 12px; right: 12px; z-index: 100;
            background: rgba(255,243,205,0.95); color: #856404;
            padding: 4px 10px; border-radius: 4px; font-size: 10px;
            font-weight: 700; letter-spacing: 0.5px; border: 1px solid #ffc107;
            backdrop-filter: blur(4px);
        }
        .logo-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 1.5rem;
            text-align: center;
        }
        .bridge-logo {
            width: 80px;
            height: 80px;
            color: #0f172a;
        }
        .content-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }
        .description {
            font-size: 1rem;
            line-height: 1.5;
            letter-spacing: -0.02em;
            margin-bottom: 2rem;
            color: #0f172a;
        }
        .agreement {
            font-size: 0.875rem;
            line-height: 1.25rem;
            letter-spacing: -0.02em;
            margin-bottom: 1.5rem;
            color: #0f172a;
        }
        .agreement a {
            color: currentColor;
            text-decoration: underline;
            cursor: pointer;
        }
        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            height: 3rem;
            background: #0f172a;
            color: #fff;
            border: none;
            border-radius: 0.25rem;
            font-family: inherit;
            font-size: 1rem;
            font-weight: 500;
            letter-spacing: -0.02em;
            cursor: pointer;
            transition: background-color 0.15s;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        button:hover { background: #1e293b; }
        button:disabled {
            background: #e2e8f0;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .no-redirect {
            color: #dc3545;
            font-size: 0.8125rem;
            margin-top: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="vs-badge">VIRTUAL SERVICE</div>
    <main>
        <div class="container">
            <div class="card">
                <div class="logo-section">
                    <svg class="bridge-logo" viewBox="0 0 512 512" aria-label="Bridge" role="img">
                        <path fill="currentColor" d="M256 512c9.1 0 18-.5 26.8-1.4l-8-76c-6.2.6-12.5 1-18.8 1s-12.6-.3-18.7-1l-8.1 76c8.8.9 17.8 1.4 26.8 1.4zM166 411.4c10.8 6.3 22.3 11.4 34.4 15.4l-23.6 72.7c-17.2-5.6-33.6-13-49-21.9L166 411.4zm-55.4-50c7.4 10.2 15.8 19.5 25.1 28L84.5 446.1c-13.3-12-25.3-25.3-35.8-39.8l61.9-44.9zM80.3 293c2.6 12.5 6.5 24.5 11.6 35.8L22 359.9c-7.2-16.2-12.8-33.2-16.5-51L80.3 293zm-3.8-37.1H0c0-.6 0-1.2 0-1.8.1-12.9 1.1-25.5 3.1-37.9C15.9 133.9 68.2 64.5 139.8 27.8 174.7 10 214.2 0 256 0c42 0 81.6 10.1 116.5 28 70.5 36.2 122.1 104.1 135.9 184.8 2.4 14 3.6 28.4 3.6 43.1H435.5c0-99.1-80.4-179.4-179.5-179.4S76.5 156.8 76.5 255.9zm343.7 72.9c5-11.3 8.9-23.3 11.5-35.7l74.8 15.8c-3.7 17.8-9.3 34.8-16.5 51l-69.8-31.2zm-43.9 60.5c9.3-8.4 17.8-17.8 25.2-28l61.8 45c-10.5 14.5-22.6 27.9-35.8 39.9l-51.2-56.8zm-64.7 37.5c12.1-3.9 23.6-9.1 34.4-15.4l38.2 66.2c-15.4 8.9-31.7 16.3-48.9 21.8l-23.7-72.7z"/>
                    </svg>
                </div>
                <div class="content-section">
                    <span class="description">This application uses Bridge to securely connect accounts and move funds.</span>
                    <span class="agreement">By clicking "Accept", you agree to Bridge's <a href="https://www.bridge.xyz/terms" target="_blank">Terms of Service</a> and <a href="https://www.bridge.xyz/privacy" target="_blank">Privacy Policy</a></span>
                    {$acceptButton}
                </div>
            </div>
        </div>
    </main>
</body>
</html>
HTML;
        exit;
    }
}
