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

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
