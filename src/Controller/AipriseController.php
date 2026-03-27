<?php

declare(strict_types=1);

namespace App\Controller;

use App\Aiprise\AipriseService;
use App\Core\JsonResponse;

/**
 * AiPrise-Faithful API Controller — exact route/response matching.
 *
 * These endpoints mirror the real AiPrise API surface so that penny-api,
 * penny-api-restricted, and ms-aiprise can call this virtual service
 * without any code changes.
 *
 * Routes:
 *   POST /api/v1/verify/get_user_verification_url     -- Create KYC URL session
 *   POST /api/v1/verify/run_user_verification          -- Full document verification
 *   GET  /api/v1/verify/get_user_verification_result/{id} -- Get verification result
 *   POST /api/v1/verify/_internal/auto-complete/{id}   -- Internal self-callback
 *
 * Control Plane:
 *   POST /control/aiprise/{id}/complete                -- Manually trigger outcome
 *   GET  /control/aiprise/sessions                     -- List all sessions
 *
 * Business KYB (stubs):
 *   POST /api/v1/verify/create_business_profile
 *   POST /api/v1/verify/add_business_document
 *   POST /api/v1/verify/add_business_officer
 *   POST /api/v1/verify/run_verification_for_business_officer
 *   POST /api/v1/verify/run_verification_for_business_profile_id
 *   GET  /api/v1/verify/get_business_verification_result/{id}
 *   GET  /api/v1/verify/get_business_verification_url
 *   GET  /api/v1/verify/get_business_data_from_request/{id}
 *   GET  /api/v1/verify/get_business_profile/{id}
 */
final class AipriseController
{
    // ── Priority 1: KYC Individual Verification ─────────────────────────────

    /**
     * POST /api/v1/verify/get_user_verification_url
     *
     * Creates a KYC verification URL session. Returns the same shape as real AiPrise:
     * { "verification_session_id": "uuid", "verification_url": "https://..." }
     *
     * Auto-schedules webhook callback to callback_url after configurable delay.
     */
    public static function getUserVerificationUrl(array $body, ?string $namespace): never
    {
        $templateId        = $body['template_id'] ?? '';
        $clientRefId       = $body['client_reference_id'] ?? '';
        $callbackUrl       = $body['callback_url'] ?? '';
        $eventsCallbackUrl = $body['events_callback_url'] ?? $callbackUrl;
        $userData          = $body['user_data'] ?? [];

        if (!$clientRefId) {
            // AiPrise would return an error; match that behavior
            JsonResponse::send([
                'error'   => true,
                'message' => 'client_reference_id is required',
            ], 400);
        }

        $result = AipriseService::createUrlSession(
            templateId: $templateId,
            clientReferenceId: $clientRefId,
            callbackUrl: $callbackUrl,
            eventsCallbackUrl: $eventsCallbackUrl,
            userData: $userData,
            namespace: $namespace,
        );

        // AiPrise returns a flat JSON response (not wrapped in an envelope)
        JsonResponse::send($result, 200);
    }

    /**
     * POST /api/v1/verify/run_user_verification
     *
     * Runs a full verification with documents submitted via API.
     * Returns: { "verification_session_id": "uuid" }
     */
    public static function runUserVerification(array $body, ?string $namespace): never
    {
        $templateId        = $body['template_id'] ?? '';
        $clientRefId       = $body['client_reference_id'] ?? '';
        $callbackUrl       = $body['callback_url'] ?? '';
        $eventsCallbackUrl = $body['events_callback_url'] ?? $callbackUrl;
        $userData          = $body['user_data'] ?? [];

        $result = AipriseService::runUserVerification(
            templateId: $templateId,
            clientReferenceId: $clientRefId,
            callbackUrl: $callbackUrl,
            eventsCallbackUrl: $eventsCallbackUrl,
            userData: $userData,
            namespace: $namespace,
        );

        JsonResponse::send($result, 200);
    }

    /**
     * GET /api/v1/verify/get_user_verification_result/{verification_session_id}
     *
     * Returns the verification result in AiPrise format:
     * {
     *   "verification_session_id": "uuid",
     *   "verification_result": "APPROVED",
     *   "aiprise_summary": { "verification_result": "APPROVED" },
     *   "client_reference_id": "customer-uuid",
     *   "user_data": { ... }
     * }
     */
    public static function getUserVerificationResult(string $sessionId, ?string $namespace): never
    {
        $result = AipriseService::getVerificationResult($sessionId, $namespace);

        if ($result === null) {
            JsonResponse::send([
                'error'   => true,
                'message' => 'Verification session not found',
            ], 404);
        }

        JsonResponse::send($result, 200);
    }

    // ── Internal Self-Callback ──────────────────────────────────────────────

    /**
     * POST /api/v1/verify/_internal/auto-complete/{verification_session_id}
     *
     * Internal endpoint called by the callback scheduler's self-callback mechanism.
     * Transitions the session to completed state and fires the HMAC-signed webhook
     * to penny-api-restricted's callback URL.
     *
     * NOT part of the real AiPrise API -- this is the virtual service's auto-progression.
     */
    public static function autoComplete(string $sessionId, array $body): never
    {
        $outcome   = $body['outcome'] ?? 'APPROVED';
        $namespace = $body['namespace'] ?? null;

        $success = AipriseService::autoComplete($sessionId, $outcome, $namespace);

        if (!$success) {
            JsonResponse::send([
                'error'   => true,
                'message' => "Session '{$sessionId}' not found",
            ], 404);
        }

        JsonResponse::send([
            'status'                  => 'completed',
            'outcome'                 => $outcome,
            'verification_session_id' => $sessionId,
        ]);
    }

    // ── Control Plane ───────────────────────────────────────────────────────

    /**
     * POST /control/aiprise/{verification_session_id}/complete
     *
     * Manually trigger a verification outcome. Useful for testing specific scenarios:
     *   { "outcome": "DECLINED" }     -- Test KYC failure
     *   { "outcome": "REVIEW" }       -- Test manual review
     *   { "outcome": "UPDATE_REQUIRED" } -- Test resubmission
     *   { "outcome": "APPROVED" }     -- Test approval (default)
     */
    public static function controlComplete(string $sessionId, array $body): never
    {
        $outcome = $body['outcome'] ?? 'APPROVED';

        $success = AipriseService::setOutcome($sessionId, $outcome);

        if (!$success) {
            JsonResponse::send([
                'error'   => true,
                'message' => "Session '{$sessionId}' not found",
            ], 404);
        }

        JsonResponse::send([
            'status'                  => 'completed',
            'outcome'                 => $outcome,
            'verification_session_id' => $sessionId,
            'message'                 => "Webhook callback scheduled with outcome: {$outcome}",
        ]);
    }

    /**
     * GET /control/aiprise/sessions
     *
     * List all AiPrise virtual sessions for inspection.
     */
    public static function listSessions(?string $namespace): never
    {
        $sessions = AipriseService::listSessions($namespace);
        JsonResponse::ok($sessions);
    }

    // ── KYB Business Verification (Real Implementation) ─────────────────────

    /**
     * POST /api/v1/verify/get_business_verification_url
     *
     * Creates a business KYB verification URL session. penny-api calls this with:
     *   template_id, client_reference_id (business_customers.id), callback_url
     *
     * penny-api parses "business_onboarding_session_id" from the returned URL.
     * Auto-schedules webhook callback after AIPRISE_AUTO_DELAY seconds.
     */
    public static function getBusinessVerificationUrl(array $body, ?string $namespace): never
    {
        $templateId        = $body['template_id'] ?? '';
        $clientRefId       = $body['client_reference_id'] ?? '';
        $callbackUrl       = $body['callback_url'] ?? '';
        $eventsCallbackUrl = $body['events_callback_url'] ?? $callbackUrl;
        $userData          = $body['user_data'] ?? [];

        $result = AipriseService::createKybSession(
            templateId: $templateId,
            clientReferenceId: $clientRefId,
            callbackUrl: $callbackUrl,
            eventsCallbackUrl: $eventsCallbackUrl,
            userData: $userData,
            namespace: $namespace,
        );

        // AiPrise returns flat JSON (no envelope)
        JsonResponse::send($result, 200);
    }

    /**
     * GET /api/v1/verify/get_business_verification_result/{verification_session_id}
     *
     * Returns the KYB verification result including business_profile_result.
     */
    public static function getBusinessVerificationResult(string $sessionId, ?string $namespace): never
    {
        $result = AipriseService::getKybVerificationResult($sessionId, $namespace);

        if ($result === null) {
            JsonResponse::send([
                'error'   => true,
                'message' => 'Business verification session not found',
            ], 404);
        }

        JsonResponse::send($result, 200);
    }

    /**
     * POST /api/v1/verify/_internal/auto-complete-kyb/{sessionId}
     *
     * Internal self-callback for KYB auto-completion.
     */
    public static function autoCompleteKyb(string $sessionId, array $body): never
    {
        $outcome   = $body['outcome'] ?? 'APPROVED';
        $namespace = $body['namespace'] ?? null;

        $success = AipriseService::autoCompleteKyb($sessionId, $outcome, $namespace);

        if (!$success) {
            JsonResponse::send([
                'error'   => true,
                'message' => "KYB session '{$sessionId}' not found",
            ], 404);
        }

        JsonResponse::send([
            'status'                  => 'completed',
            'outcome'                 => $outcome,
            'verification_session_id' => $sessionId,
        ]);
    }

    // ── KYB Control Plane ─────────────────────────────────────────────────

    /**
     * POST /control/aiprise-kyb/{sessionId}/complete
     *
     * Manually complete a KYB session with a specific outcome.
     */
    public static function controlCompleteKyb(string $sessionId, array $body): never
    {
        $outcome = $body['outcome'] ?? 'APPROVED';

        $success = AipriseService::setKybOutcome($sessionId, $outcome);

        if (!$success) {
            JsonResponse::send([
                'error'   => true,
                'message' => "KYB session '{$sessionId}' not found",
            ], 404);
        }

        JsonResponse::send([
            'status'                  => 'completed',
            'outcome'                 => $outcome,
            'verification_session_id' => $sessionId,
            'message'                 => "KYB webhook callback scheduled with outcome: {$outcome}",
        ]);
    }

    /**
     * GET /control/aiprise-kyb/sessions
     *
     * List all KYB virtual sessions for inspection.
     */
    public static function listKybSessions(?string $namespace): never
    {
        $sessions = AipriseService::listKybSessions($namespace);
        JsonResponse::ok($sessions);
    }

    // ── Business KYB: API-driven verification (submit flow) ─────────────────

    /**
     * POST /api/v1/verify/run_business_verification
     *
     * API-driven KYB verification. penny-api calls this during submitKyb()
     * after all files have been uploaded. Sends business_data + documents as base64.
     *
     * Returns: { verification_session_id, template_id, client_reference_id }
     * Then auto-completes and fires the KYB webhook.
     */
    public static function runBusinessVerification(array $body, ?string $namespace): never
    {
        $templateId        = $body['template_id'] ?? '';
        $clientRefId       = $body['client_reference_id'] ?? '';
        $callbackUrl       = $body['callback_url'] ?? '';
        $eventsCallbackUrl = $body['events_callback_url'] ?? $callbackUrl;
        $userData          = $body['user_data'] ?? [];

        // Reuse the KYB session creation logic — same auto-completion + webhook flow
        $result = AipriseService::createKybSession(
            templateId: $templateId,
            clientReferenceId: $clientRefId,
            callbackUrl: $callbackUrl,
            eventsCallbackUrl: $eventsCallbackUrl,
            userData: $userData,
            namespace: $namespace,
        );

        // penny-api expects { verification_session_id, template_id, client_reference_id }
        JsonResponse::send([
            'verification_session_id' => $result['verification_session_id'] ?? $result['business_onboarding_session_id'] ?? self::generateUuid(),
            'template_id'             => $templateId,
            'client_reference_id'     => $clientRefId,
        ], 200);
    }

    // ── Business KYB API Stubs (lower priority) ──────────────────────────────

    /**
     * POST /api/v1/verify/create_business_profile
     */
    public static function createBusinessProfile(array $body): never
    {
        $profileId = self::generateUuid();
        JsonResponse::send([
            'business_profile_id'   => $profileId,
            'status'                => 'CREATED',
            'client_reference_id'   => $body['client_reference_id'] ?? null,
        ], 200);
    }

    /**
     * POST /api/v1/verify/add_business_document
     */
    public static function addBusinessDocument(array $body): never
    {
        JsonResponse::send([
            'status'  => 'DOCUMENT_ADDED',
            'message' => 'Document added to business profile (virtual)',
        ], 200);
    }

    /**
     * POST /api/v1/verify/add_business_officer
     */
    public static function addBusinessOfficer(array $body): never
    {
        $officerId = self::generateUuid();
        JsonResponse::send([
            'officer_id' => $officerId,
            'status'     => 'OFFICER_ADDED',
        ], 200);
    }

    /**
     * POST /api/v1/verify/run_verification_for_business_officer
     */
    public static function runVerificationForBusinessOfficer(array $body): never
    {
        JsonResponse::send([
            'verification_session_id' => self::generateUuid(),
            'status'                  => 'PROCESSING',
        ], 200);
    }

    /**
     * POST /api/v1/verify/run_verification_for_business_profile_id
     */
    public static function runVerificationForBusinessProfileId(array $body): never
    {
        JsonResponse::send([
            'verification_session_id' => self::generateUuid(),
            'status'                  => 'PROCESSING',
        ], 200);
    }

    /**
     * GET /api/v1/verify/get_business_data_from_request/{verification_id}
     */
    public static function getBusinessDataFromRequest(string $verificationId): never
    {
        JsonResponse::send([
            'verification_id' => $verificationId,
            'business_data'   => [],
            'status'          => 'APPROVED',
        ], 200);
    }

    /**
     * GET /api/v1/verify/get_business_profile/{verification_id}
     */
    public static function getBusinessProfile(string $verificationId): never
    {
        JsonResponse::send([
            'verification_id'       => $verificationId,
            'business_profile_id'   => $verificationId,
            'status'                => 'APPROVED',
        ], 200);
    }

    // ── Browser-Facing Verification Page ─────────────────────────────────────

    /**
     * GET /verify
     *
     * Browser-facing verification page loaded by the CMS frontend after Bridge
     * T&C acceptance. Supports both KYC and KYB session types via query params:
     *   ?verification_session_id=...         (KYC individual)
     *   ?business_onboarding_session_id=...  (KYB business)
     *
     * When the user clicks "Complete Verification", the page triggers the
     * auto-complete endpoint which fires the webhook back to penny-api-restricted.
     */
    public static function verifyPage(): never
    {
        $sessionId = $_GET['verification_session_id']
            ?? $_GET['business_onboarding_session_id']
            ?? 'unknown';
        $isKyb = isset($_GET['business_onboarding_session_id']);
        $sessionType = $isKyb ? 'KYB (Business)' : 'KYC (Individual)';
        $autoCompleteEndpoint = $isKyb
            ? "/api/v1/verify/_internal/auto-complete-kyb/{$sessionId}"
            : "/api/v1/verify/_internal/auto-complete/{$sessionId}";
        $internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';

        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Identity Verification | AiPrise</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: #fff;
            color: #0f172a;
            -webkit-font-smoothing: antialiased;
        }
        main { max-width: 24rem; margin: 0 auto; height: 100vh; }
        .container {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; min-height: 100vh; padding: 1.5rem;
        }
        .card {
            position: relative; width: 100%; max-width: 28rem;
            border: 1px solid #e2e8f0; border-bottom-width: 2px;
            border-radius: 0.5rem; background: #fff; padding: 2rem;
        }
        @media (min-width: 640px) { .card { width: 420px; padding: 3rem; } }
        .badge {
            position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            display: inline-block; background: #fff3cd; color: #856404;
            padding: 4px 12px; border-radius: 4px; font-size: 11px;
            font-weight: 700; letter-spacing: 0.5px; border: 1px solid #ffc107;
            white-space: nowrap;
        }
        .icon {
            width: 80px; height: 80px; margin: 0 auto 1.5rem;
            background: #f0f9ff; border-radius: 50%; display: flex;
            align-items: center; justify-content: center; font-size: 36px;
        }
        .content { text-align: center; }
        h1 { font-size: 1.25rem; margin-bottom: 0.5rem; }
        .type-badge {
            display: inline-block; background: #e0f2fe; color: #0369a1;
            padding: 3px 10px; border-radius: 12px; font-size: 0.75rem;
            font-weight: 600; margin-bottom: 1rem;
        }
        .description { font-size: 0.9375rem; line-height: 1.5; margin-bottom: 1.5rem; color: #475569; }
        .session-id {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 0.75rem; font-size: 0.75rem; color: #64748b;
            font-family: monospace; word-break: break-all; margin-bottom: 1.5rem;
        }
        button {
            display: inline-flex; align-items: center; justify-content: center;
            width: 100%; height: 3rem; background: #059669; color: #fff;
            border: none; border-radius: 0.375rem; font-family: inherit;
            font-size: 1rem; font-weight: 500; cursor: pointer;
            transition: background-color 0.15s;
        }
        button:hover { background: #047857; }
        button:disabled { background: #94a3b8; cursor: not-allowed; }
        .status { text-align: center; margin-top: 1rem; font-size: 0.875rem; color: #059669; }
        .status.error { color: #dc2626; }
    </style>
</head>
<body>
    <main>
        <div class="container">
            <div class="card">
                <div class="badge">VIRTUAL SERVICE</div>
                <div class="icon">🛡️</div>
                <div class="content">
                    <h1>Identity Verification</h1>
                    <div class="type-badge">{$sessionType}</div>
                    <p class="description">
                        This is a virtual AiPrise verification page. Clicking "Complete Verification"
                        will approve the session and fire the webhook callback to penny-api-restricted.
                    </p>
                    <div class="session-id">Session: {$sessionId}</div>
                    <button id="completeBtn" onclick="completeVerification()">Complete Verification</button>
                    <div id="status" class="status"></div>
                </div>
            </div>
        </div>
    </main>
    <script>
        async function completeVerification() {
            const btn = document.getElementById('completeBtn');
            const status = document.getElementById('status');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            status.textContent = '';
            try {
                const res = await fetch('{$internalUrl}{$autoCompleteEndpoint}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ outcome: 'APPROVED' }),
                });
                const data = await res.json();
                if (res.ok) {
                    btn.textContent = 'Verified ✓';
                    btn.style.background = '#047857';
                    status.textContent = 'Verification approved. Webhook callback sent. You can close this page.';
                } else {
                    throw new Error(data.message || 'Verification failed');
                }
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Complete Verification';
                status.textContent = 'Error: ' + e.message;
                status.className = 'status error';
            }
        }
    </script>
</body>
</html>
HTML;
        exit;
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
