<?php

declare(strict_types=1);

namespace App\Controller;

use App\Compliance\ComplianceService;
use App\Core\JsonResponse;

/**
 * Compliance API: AiPrise-compatible endpoints.
 * Namespace via X-Test-Namespace header or namespace query param.
 */
final class ComplianceController
{
    /** POST /api/compliance/sessions */
    public static function createSession(array $body, string $namespace): never
    {
        $customerId       = $body['customer_id'] ?? null;
        $verificationType = $body['verification_type'] ?? 'kyc';
        $country          = $body['country'] ?? 'MX';
        $customerData     = $body['customer_data'] ?? [];
        $callbackUrl      = $body['callback_url'] ?? null;

        if (!$customerId) {
            JsonResponse::error('customer_id is required', 400);
        }

        $session = ComplianceService::createSession(
            namespace: $namespace,
            customerId: $customerId,
            verificationType: $verificationType,
            country: $country,
            customerData: $customerData,
            callbackUrl: $callbackUrl,
        );

        JsonResponse::send($session, 201);
    }

    /** GET /api/compliance/sessions/{sessionRef} */
    public static function getSession(string $sessionRef, string $namespace): never
    {
        $session = ComplianceService::getSession($namespace, $sessionRef);
        if ($session === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }
        JsonResponse::ok($session);
    }

    /** POST /api/compliance/sessions/{sessionRef}/submit */
    public static function submitDocuments(string $sessionRef, array $body, string $namespace): never
    {
        $documents    = $body['documents'] ?? [];
        $autoOutcome  = $body['auto_outcome'] ?? null;
        $autoDelay    = (int)($body['auto_outcome_delay_seconds'] ?? 30);

        if (empty($documents)) {
            JsonResponse::error('documents array is required', 400);
        }

        $result = ComplianceService::submitDocuments(
            namespace: $namespace,
            sessionRef: $sessionRef,
            documents: $documents,
            autoOutcome: $autoOutcome,
            autoOutcomeDelaySeconds: $autoDelay,
        );

        if ($result === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }
        if (isset($result['error'])) {
            JsonResponse::error($result['error'], 409);
        }

        JsonResponse::ok($result, 'Documents submitted');
    }

    /** POST /api/compliance/sessions/{sessionRef}/transition (also used by auto-progression). */
    public static function transitionSession(string $sessionRef, array $body, string $namespace): never
    {
        $targetState     = $body['target_state'] ?? null;
        $rejectionReason = $body['rejection_reason'] ?? null;
        $infoRequest     = $body['info_request'] ?? null;

        if (!$targetState) {
            JsonResponse::error('target_state is required', 400);
        }

        $result = ComplianceService::transitionSession(
            namespace: $namespace,
            sessionRef: $sessionRef,
            targetState: $targetState,
            rejectionReason: $rejectionReason,
            infoRequest: $infoRequest,
        );

        if ($result === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }
        if (isset($result['error'])) {
            JsonResponse::error($result['error'], 409);
        }

        JsonResponse::ok($result, 'State transitioned');
    }

    /** POST /api/compliance/sessions/{sessionRef}/auto-transition: internal, NOT part of real AiPrise API. */
    public static function autoTransition(string $sessionRef, array $body): never
    {
        $namespace       = $body['namespace'] ?? null;
        $targetState     = $body['target_state'] ?? null;
        $rejectionReason = $body['rejection_reason'] ?? null;
        $infoRequest     = $body['info_request'] ?? null;

        if (!$namespace || !$targetState) {
            JsonResponse::error('namespace and target_state are required', 400);
        }

        $result = ComplianceService::transitionSession(
            namespace: $namespace,
            sessionRef: $sessionRef,
            targetState: $targetState,
            rejectionReason: $rejectionReason,
            infoRequest: $infoRequest,
        );

        if ($result === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }
        if (isset($result['error'])) {
            JsonResponse::error($result['error'], 409);
        }

        JsonResponse::ok($result, 'Auto-transition applied');
    }

    /** GET /api/compliance/sessions/{sessionRef}/url */
    public static function getVerificationUrl(string $sessionRef, string $namespace): never
    {
        $session = ComplianceService::getSession($namespace, $sessionRef);
        if ($session === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }

        JsonResponse::ok([
            'session_ref'      => $sessionRef,
            'verification_url' => $session['verification_url'],
            'state'            => $session['state'],
        ]);
    }

    /** GET /api/compliance/sessions/{sessionRef}/history */
    public static function getSessionHistory(string $sessionRef, string $namespace): never
    {
        $history = ComplianceService::getSessionHistory($namespace, $sessionRef);
        if ($history === null) {
            JsonResponse::error("Session '{$sessionRef}' not found", 404);
        }
        JsonResponse::ok($history);
    }

    /** GET /api/compliance/sessions */
    public static function listSessions(string $namespace): never
    {
        $sessions = ComplianceService::listSessions($namespace);
        JsonResponse::ok($sessions);
    }
}
