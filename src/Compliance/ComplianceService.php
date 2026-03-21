<?php

declare(strict_types=1);

namespace App\Compliance;

use App\Callback\CallbackScheduler;
use App\Entity\EntityManager;

/**
 * Virtual Compliance Service — L3 Workflow Emulator.
 *
 * Replaces Aiprise SaaS for KYC/KYB verification in development and testing.
 * Implements stateful KYC sessions with async callback delivery.
 *
 * Capabilities:
 *   - Create KYC/KYB sessions (draft state)
 *   - Submit documents (transition to pending)
 *   - Auto-approve/reject based on scenario config
 *   - Generate verification URLs
 *   - Emit callbacks on state transitions
 *   - Support resubmission (info_required -> pending)
 */
final class ComplianceService
{
    private const ENTITY_TYPE = 'kyc_session';

    /**
     * Create a new KYC/KYB session.
     */
    public static function createSession(
        string $namespace,
        string $customerId,
        string $verificationType,  // 'kyc' or 'kyb'
        string $country,
        array  $customerData = [],
        ?string $callbackUrl = null,
    ): array {
        $sessionRef = 'vkyc_' . bin2hex(random_bytes(12));

        $data = [
            'session_ref'       => $sessionRef,
            'customer_id'       => $customerId,
            'verification_type' => $verificationType,
            'country'           => $country,
            'customer_data'     => $customerData,
            'callback_url'      => $callbackUrl,
            'documents'         => [],
            'verification_url'  => self::generateVerificationUrl($sessionRef),
            'rejection_reason'  => null,
            'info_request'      => null,
        ];

        $entityId = EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_TYPE,
            entityRef: $sessionRef,
            state: KycState::DRAFT->value,
            data: $data,
        );

        return [
            'id'               => $entityId,
            'session_ref'      => $sessionRef,
            'state'            => KycState::DRAFT->value,
            'verification_url' => $data['verification_url'],
            'verification_type'=> $verificationType,
            'country'          => $country,
            'created_at'       => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    /**
     * Get session by reference.
     */
    public static function getSession(string $namespace, string $sessionRef): ?array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        if ($entity === null) {
            return null;
        }

        return self::formatSession($entity);
    }

    /**
     * Submit documents — transitions from DRAFT to PENDING.
     * Optionally auto-progresses to a terminal state after a delay (simulating review).
     */
    public static function submitDocuments(
        string $namespace,
        string $sessionRef,
        array  $documents,
        ?string $autoOutcome = null,       // 'approved', 'rejected', 'info_required', or null (stay pending)
        int    $autoOutcomeDelaySeconds = 0,
    ): ?array {
        $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        if ($entity === null) {
            return null;
        }

        $currentState = KycState::from($entity['state']);

        // Can submit from DRAFT or INFO_REQUIRED
        if (!in_array($currentState, [KycState::DRAFT, KycState::INFO_REQUIRED], true)) {
            return ['error' => "Cannot submit documents in state '{$currentState->value}'"];
        }

        // Transition to PENDING
        EntityManager::transition(
            entityId: (int)$entity['id'],
            newState: KycState::PENDING->value,
            triggerType: 'api_call',
            metadata: ['action' => 'document_submission', 'document_count' => count($documents)],
            dataUpdates: ['documents' => array_merge($entity['data']['documents'] ?? [], $documents)],
        );

        // Schedule callback for the PENDING transition
        $callbackUrl = $entity['data']['callback_url'] ?? null;
        if ($callbackUrl) {
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $callbackUrl,
                payload: self::buildCallbackPayload($sessionRef, KycState::PENDING->value, $entity['data']),
                entityId: (int)$entity['id'],
                delaySeconds: 1,
            );
        }

        // If auto-outcome is configured, schedule the terminal state transition
        if ($autoOutcome !== null && $callbackUrl) {
            $targetState = KycState::from($autoOutcome);
            $outcomePayload = self::buildCallbackPayload($sessionRef, $targetState->value, $entity['data']);

            if ($autoOutcome === 'rejected') {
                $outcomePayload['rejection_reason'] = 'Document quality insufficient (virtual)';
            }
            if ($autoOutcome === 'info_required') {
                $outcomePayload['info_request'] = 'Please provide a clearer copy of your ID (virtual)';
            }

            // Schedule a deferred state transition via a self-callback
            // The callback will hit our own transition endpoint
            $selfUrl = ($_ENV['APP_BASE_URL'] ?? '') . "/api/compliance/sessions/{$sessionRef}/auto-transition";
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $selfUrl,
                payload: [
                    'target_state'     => $autoOutcome,
                    'namespace'        => $namespace,
                    'rejection_reason' => $autoOutcome === 'rejected' ? 'Document quality insufficient (virtual)' : null,
                    'info_request'     => $autoOutcome === 'info_required' ? 'Please provide a clearer copy of your ID (virtual)' : null,
                ],
                entityId: (int)$entity['id'],
                delaySeconds: $autoOutcomeDelaySeconds,
            );
        }

        $updated = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        return self::formatSession($updated);
    }

    /**
     * Manually transition a session to a new state (control plane operation).
     */
    public static function transitionSession(
        string  $namespace,
        string  $sessionRef,
        string  $targetState,
        ?string $rejectionReason = null,
        ?string $infoRequest = null,
    ): ?array {
        $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        if ($entity === null) {
            return null;
        }

        $current = KycState::from($entity['state']);
        $target  = KycState::from($targetState);

        if (!$current->canTransitionTo($target)) {
            return ['error' => "Cannot transition from '{$current->value}' to '{$target->value}'"];
        }

        $dataUpdates = [];
        if ($rejectionReason !== null) {
            $dataUpdates['rejection_reason'] = $rejectionReason;
        }
        if ($infoRequest !== null) {
            $dataUpdates['info_request'] = $infoRequest;
        }

        EntityManager::transition(
            entityId: (int)$entity['id'],
            newState: $target->value,
            triggerType: 'callback',
            metadata: ['rejection_reason' => $rejectionReason, 'info_request' => $infoRequest],
            dataUpdates: $dataUpdates,
        );

        // Fire callback to the consuming service
        $callbackUrl = $entity['data']['callback_url'] ?? null;
        if ($callbackUrl) {
            $payload = self::buildCallbackPayload($sessionRef, $target->value, array_merge($entity['data'], $dataUpdates));
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $callbackUrl,
                payload: $payload,
                entityId: (int)$entity['id'],
                delaySeconds: 0,
            );
        }

        $updated = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        return self::formatSession($updated);
    }

    /**
     * List all KYC sessions for a namespace.
     */
    public static function listSessions(string $namespace): array
    {
        $entities = EntityManager::findAllByNamespace($namespace, self::ENTITY_TYPE);
        return array_map([self::class, 'formatSession'], $entities);
    }

    /**
     * Get state transition history for a session.
     */
    public static function getSessionHistory(string $namespace, string $sessionRef): ?array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionRef);
        if ($entity === null) {
            return null;
        }
        return EntityManager::getHistory((int)$entity['id']);
    }

    private static function generateVerificationUrl(string $sessionRef): string
    {
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'https://virtual-compliance.local';
        return "{$baseUrl}/verify/{$sessionRef}";
    }

    private static function buildCallbackPayload(string $sessionRef, string $state, array $data): array
    {
        return [
            'event'             => 'verification.status_changed',
            'session_id'        => $sessionRef,
            'status'            => $state,
            'verification_type' => $data['verification_type'] ?? 'kyc',
            'customer_id'       => $data['customer_id'] ?? null,
            'country'           => $data['country'] ?? null,
            'rejection_reason'  => $data['rejection_reason'] ?? null,
            'info_request'      => $data['info_request'] ?? null,
            'timestamp'         => date('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function formatSession(?array $entity): ?array
    {
        if ($entity === null) {
            return null;
        }

        $data = $entity['data'] ?? [];
        return [
            'id'                => (int) $entity['id'],
            'session_ref'       => $data['session_ref'] ?? $entity['entity_ref'],
            'state'             => $entity['state'],
            'verification_type' => $data['verification_type'] ?? null,
            'customer_id'       => $data['customer_id'] ?? null,
            'country'           => $data['country'] ?? null,
            'verification_url'  => $data['verification_url'] ?? null,
            'documents'         => $data['documents'] ?? [],
            'rejection_reason'  => $data['rejection_reason'] ?? null,
            'info_request'      => $data['info_request'] ?? null,
            'callback_url'      => $data['callback_url'] ?? null,
            'created_at'        => $entity['created_at'] ?? null,
            'updated_at'        => $entity['updated_at'] ?? null,
        ];
    }
}
