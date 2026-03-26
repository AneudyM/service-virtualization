<?php

declare(strict_types=1);

namespace App\Aiprise;

use App\Callback\CallbackScheduler;
use App\Core\Database;
use App\Entity\EntityManager;

/**
 * Virtual AiPrise Service — faithful drop-in replacement for the real AiPrise API.
 *
 * Mirrors AiPrise's exact API contract so penny-api can call this service
 * without any code changes. Creates KYC verification sessions, returns
 * verification URLs, auto-completes verifications after a configurable delay,
 * and sends HMAC-SHA256-signed webhook callbacks matching the real format.
 *
 * Endpoints implemented:
 *   - POST /api/v1/verify/get_user_verification_url
 *   - POST /api/v1/verify/run_user_verification
 *   - GET  /api/v1/verify/get_user_verification_result/{id}
 *   - POST /api/v1/verify/_internal/auto-complete/{id}  (self-callback)
 *
 * Default behavior: auto-approves after DEFAULT_AUTO_DELAY seconds.
 * Override via control plane: POST /control/aiprise/{id}/complete
 */
final class AipriseService
{
    private const ENTITY_TYPE = 'aiprise_session';
    private const DEFAULT_NAMESPACE = '__aiprise__';
    private const DEFAULT_AUTO_DELAY = 10; // seconds before auto-completing
    private const DEFAULT_OUTCOME = 'APPROVED';

    /**
     * Create a verification URL session.
     * Mirrors: POST /api/v1/verify/get_user_verification_url
     *
     * Creates an entity, returns verification_session_id + verification_url,
     * and schedules auto-completion callback after a delay.
     */
    public static function createUrlSession(
        string  $templateId,
        string  $clientReferenceId,
        string  $callbackUrl,
        ?string $eventsCallbackUrl,
        array   $userData,
        ?string $namespace = null,
    ): array {
        $ns = $namespace ?? self::DEFAULT_NAMESPACE;
        $sessionId = self::generateUuid();
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';
        $verificationUrl = "{$baseUrl}/verify?verification_session_id={$sessionId}";

        // Rewrite callback URLs from external hosts to Docker-internal addresses
        $callbackUrl = self::rewriteCallbackUrl($callbackUrl);
        $eventsCallbackUrl = self::rewriteCallbackUrl($eventsCallbackUrl);

        $data = [
            'verification_session_id' => $sessionId,
            'verification_url'        => $verificationUrl,
            'template_id'             => $templateId,
            'client_reference_id'     => $clientReferenceId,
            'callback_url'            => $callbackUrl,
            'events_callback_url'     => $eventsCallbackUrl,
            'user_data'               => $userData,
            'verification_result'     => null,
            'auto_outcome'            => self::DEFAULT_OUTCOME,
            'session_type'            => 'url_session',
        ];

        $entityId = EntityManager::create(
            namespace: $ns,
            entityType: self::ENTITY_TYPE,
            entityRef: $sessionId,
            state: 'created',
            data: $data,
        );

        // Schedule auto-completion via internal self-callback.
        // Uses APP_INTERNAL_URL (port 80 inside the container) not APP_BASE_URL (host port).
        $internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';
        $selfUrl = "{$internalUrl}/api/v1/verify/_internal/auto-complete/{$sessionId}";

        $autoDelay = (int)($_ENV['AIPRISE_AUTO_DELAY'] ?? self::DEFAULT_AUTO_DELAY);

        CallbackScheduler::schedule(
            namespace: $ns,
            targetUrl: $selfUrl,
            payload: [
                'verification_session_id' => $sessionId,
                'namespace'               => $ns,
                'outcome'                 => self::DEFAULT_OUTCOME,
            ],
            entityId: $entityId,
            delaySeconds: $autoDelay,
        );

        // Official AiPrise response schema: UserVerificationUrlResponse
        $expiryTime = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d\TH:i:s\Z');

        return [
            'session_expiry_time'     => $expiryTime,
            'verification_session_id' => $sessionId,
            'verification_url'        => $verificationUrl,
        ];
    }

    /**
     * Run full user verification with documents.
     * Mirrors: POST /api/v1/verify/run_user_verification
     *
     * Same as createUrlSession but for API-driven verification (no URL needed).
     */
    public static function runUserVerification(
        string  $templateId,
        string  $clientReferenceId,
        string  $callbackUrl,
        ?string $eventsCallbackUrl,
        array   $userData,
        ?string $namespace = null,
    ): array {
        $ns = $namespace ?? self::DEFAULT_NAMESPACE;
        $sessionId = self::generateUuid();

        // Rewrite callback URLs from external hosts to Docker-internal addresses
        $callbackUrl = self::rewriteCallbackUrl($callbackUrl);
        $eventsCallbackUrl = self::rewriteCallbackUrl($eventsCallbackUrl);

        $data = [
            'verification_session_id' => $sessionId,
            'template_id'             => $templateId,
            'client_reference_id'     => $clientReferenceId,
            'callback_url'            => $callbackUrl,
            'events_callback_url'     => $eventsCallbackUrl,
            'user_data'               => $userData,
            'verification_result'     => null,
            'auto_outcome'            => self::DEFAULT_OUTCOME,
            'session_type'            => 'full_verification',
        ];

        $entityId = EntityManager::create(
            namespace: $ns,
            entityType: self::ENTITY_TYPE,
            entityRef: $sessionId,
            state: 'processing',
            data: $data,
        );

        // Schedule auto-completion
        $internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';
        $selfUrl = "{$internalUrl}/api/v1/verify/_internal/auto-complete/{$sessionId}";

        $autoDelay = (int)($_ENV['AIPRISE_AUTO_DELAY'] ?? self::DEFAULT_AUTO_DELAY);

        CallbackScheduler::schedule(
            namespace: $ns,
            targetUrl: $selfUrl,
            payload: [
                'verification_session_id' => $sessionId,
                'namespace'               => $ns,
                'outcome'                 => self::DEFAULT_OUTCOME,
            ],
            entityId: $entityId,
            delaySeconds: $autoDelay,
        );

        return [
            'verification_session_id' => $sessionId,
        ];
    }

    /**
     * Get verification result for a session.
     * Mirrors: GET /api/v1/verify/get_user_verification_result/{id}
     *
     * Returns the full AiPrise UserVerificationResponseV2 format including:
     * aiprise_summary, status, id_info, face_match_info, face_liveness_info,
     * aml_info, template_id, created_at, environment, etc.
     *
     * @see docs/aiprise-official/response.md for the full schema
     */
    public static function getVerificationResult(string $sessionId, ?string $namespace = null): ?array
    {
        $entity = self::findSession($sessionId, $namespace);
        if ($entity === null) {
            return null;
        }

        $data = $entity['data'];
        $isCompleted = $entity['state'] === 'completed';
        $verificationResult = $data['verification_result']
            ?? ($isCompleted ? self::DEFAULT_OUTCOME : null);

        // Map entity state to AiPrise run status
        $runStatus = match ($entity['state']) {
            'created'    => 'NOT_STARTED',
            'processing' => 'RUNNING',
            'completed'  => 'COMPLETED',
            default      => 'PENDING',
        };

        $userData = $data['user_data'] ?? [];
        $identity = $userData['identity'] ?? [];
        $countryCode = $identity['identity_country_code'] ?? 'MX';
        $createdAtMs = strtotime($entity['created_at'] ?? 'now') * 1000;

        // Build the full response matching UserVerificationResponseV2 schema
        $response = [
            'aiprise_summary' => [
                'verification_result' => $verificationResult,
            ],
            'status'                  => $runStatus,
            'client_reference_id'     => $data['client_reference_id'] ?? null,
            'verification_session_id' => $sessionId,
            'template_id'             => $data['template_id'] ?? null,
            'created_at'              => $createdAtMs,
            'environment'             => 'SANDBOX',
        ];

        // Include check results only when completed
        if ($isCompleted) {
            $response['id_info'] = self::buildIdInfo($verificationResult, $userData, $countryCode);
            $response['face_match_info'] = self::buildFaceMatchInfo($verificationResult);
            $response['face_liveness_info'] = self::buildFaceLivenessInfo($verificationResult);
            $response['aml_info'] = self::buildAmlInfo($verificationResult);
        }

        // Include user input data
        $response['user_input'] = [
            'user_data' => $userData,
        ];

        return $response;
    }

    /**
     * Auto-complete a verification session (called by internal self-callback).
     *
     * Transitions the session to "completed" state and fires the HMAC-signed
     * webhook callback to the callback_url that penny-api-restricted listens on.
     */
    public static function autoComplete(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        // Don't re-complete already completed sessions
        if ($entity['state'] === 'completed') {
            return true;
        }

        $data = $entity['data'];

        // Transition to completed state
        EntityManager::transition(
            entityId: (int)$entity['id'],
            newState: 'completed',
            triggerType: 'callback',
            metadata: ['outcome' => $outcome, 'source' => 'auto_complete'],
            dataUpdates: ['verification_result' => $outcome],
        );

        // Fire webhook to callback_url with HMAC-SHA256 signature
        self::fireWebhook($entity, $sessionId, $outcome, 'callback_url');

        // Also fire to events_callback_url if different from callback_url
        $callbackUrl = $data['callback_url'] ?? null;
        $eventsUrl = $data['events_callback_url'] ?? null;
        if ($eventsUrl && $eventsUrl !== $callbackUrl) {
            self::fireWebhook($entity, $sessionId, $outcome, 'events_callback_url');
        }

        return true;
    }

    /**
     * Control plane: manually complete a session with a specific outcome.
     * Used for testing specific scenarios (DECLINED, REVIEW, UPDATE_REQUIRED).
     */
    public static function setOutcome(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        // Cancel any pending auto-complete callbacks for this entity
        $pdo = Database::connect();
        $pdo->prepare("
            UPDATE pending_callbacks
            SET status = 'cancelled'
            WHERE entity_id = :eid AND status = 'pending'
        ")->execute(['eid' => (int)$entity['id']]);

        // Immediately complete with the specified outcome
        return self::autoComplete($sessionId, $outcome, $entity['namespace']);
    }

    /**
     * List all AiPrise sessions (for control plane inspection).
     */
    public static function listSessions(?string $namespace = null): array
    {
        $ns = $namespace ?? self::DEFAULT_NAMESPACE;
        $entities = EntityManager::findAllByNamespace($ns, self::ENTITY_TYPE);

        return array_map(function (array $entity): array {
            $data = $entity['data'] ?? [];
            return [
                'verification_session_id' => $data['verification_session_id'] ?? $entity['entity_ref'],
                'client_reference_id'     => $data['client_reference_id'] ?? null,
                'state'                   => $entity['state'],
                'verification_result'     => $data['verification_result'] ?? null,
                'session_type'            => $data['session_type'] ?? null,
                'template_id'             => $data['template_id'] ?? null,
                'callback_url'            => $data['callback_url'] ?? null,
                'created_at'              => $entity['created_at'] ?? null,
                'updated_at'              => $entity['updated_at'] ?? null,
            ];
        }, $entities);
    }

    // ── KYB (Business Verification) ─────────────────────────────────────────

    private const KYB_ENTITY_TYPE = 'aiprise_kyb_session';
    private const KYB_NAMESPACE = '__aiprise_kyb__';

    /**
     * Create a business verification URL session.
     * Mirrors: POST /api/v1/verify/get_business_verification_url
     *
     * penny-api calls this with:
     *   - template_id: from aiprise_configuration.templateKyb
     *   - client_reference_id: business_customers.id (NOT customerId)
     *   - callback_url: ${AIPRISE_CALLBACK_URL_BUSINESS}/api/v1/.../webhook-url-session-kyb
     *
     * penny-api extracts "business_onboarding_session_id" from the returned URL.
     */
    public static function createKybSession(
        string  $templateId,
        string  $clientReferenceId,
        string  $callbackUrl,
        ?string $eventsCallbackUrl,
        array   $userData,
        ?string $namespace = null,
    ): array {
        $ns = $namespace ?? self::KYB_NAMESPACE;
        $sessionId = self::generateUuid();
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';
        // penny-api parses "business_onboarding_session_id" from this URL
        $verificationUrl = "{$baseUrl}/verify?business_onboarding_session_id={$sessionId}";

        // Rewrite callback URLs from external hosts to Docker-internal addresses
        $callbackUrl = self::rewriteCallbackUrl($callbackUrl);
        $eventsCallbackUrl = self::rewriteCallbackUrl($eventsCallbackUrl);

        $data = [
            'verification_session_id' => $sessionId,
            'verification_url'        => $verificationUrl,
            'template_id'             => $templateId,
            'client_reference_id'     => $clientReferenceId,
            'callback_url'            => $callbackUrl,
            'events_callback_url'     => $eventsCallbackUrl,
            'user_data'               => $userData,
            'verification_result'     => null,
            'auto_outcome'            => self::DEFAULT_OUTCOME,
            'session_type'            => 'kyb_url_session',
        ];

        $entityId = EntityManager::create(
            namespace: $ns,
            entityType: self::KYB_ENTITY_TYPE,
            entityRef: $sessionId,
            state: 'created',
            data: $data,
        );

        // Schedule auto-completion via internal self-callback
        $internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';
        $selfUrl = "{$internalUrl}/api/v1/verify/_internal/auto-complete-kyb/{$sessionId}";

        $autoDelay = (int)($_ENV['AIPRISE_AUTO_DELAY'] ?? self::DEFAULT_AUTO_DELAY);

        CallbackScheduler::schedule(
            namespace: $ns,
            targetUrl: $selfUrl,
            payload: [
                'verification_session_id' => $sessionId,
                'namespace'               => $ns,
                'outcome'                 => self::DEFAULT_OUTCOME,
            ],
            entityId: $entityId,
            delaySeconds: $autoDelay,
        );

        return [
            'verification_session_id' => $sessionId,
            'verification_url'        => $verificationUrl,
        ];
    }

    /**
     * Get KYB verification result.
     * Mirrors: GET /api/v1/verify/get_business_verification_result/{id}
     */
    public static function getKybVerificationResult(string $sessionId, ?string $namespace = null): ?array
    {
        $entity = self::findKybSession($sessionId, $namespace);
        if ($entity === null) {
            return null;
        }

        $data = $entity['data'];
        $isCompleted = $entity['state'] === 'completed';
        $verificationResult = $data['verification_result']
            ?? ($isCompleted ? self::DEFAULT_OUTCOME : null);

        $runStatus = match ($entity['state']) {
            'created'    => 'NOT_STARTED',
            'processing' => 'RUNNING',
            'completed'  => 'COMPLETED',
            default      => 'PENDING',
        };

        return [
            'business_profile_result' => $verificationResult,
            'status'                  => $runStatus,
            'verification_session_id' => $sessionId,
            'client_reference_id'     => $data['client_reference_id'] ?? null,
            'template_id'             => $data['template_id'] ?? null,
            'created_at'              => strtotime($entity['created_at'] ?? 'now') * 1000,
            'environment'             => 'SANDBOX',
            'aiprise_summary'         => [
                'verification_result' => $verificationResult,
            ],
        ];
    }

    /**
     * Auto-complete a KYB session (called by internal self-callback).
     */
    public static function autoCompleteKyb(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findKybSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        if ($entity['state'] === 'completed') {
            return true;
        }

        EntityManager::transition(
            entityId: (int)$entity['id'],
            newState: 'completed',
            triggerType: 'callback',
            metadata: ['outcome' => $outcome, 'source' => 'auto_complete_kyb'],
            dataUpdates: ['verification_result' => $outcome],
        );

        // Fire KYB webhook
        self::fireKybWebhook($entity, $sessionId, $outcome, 'callback_url');

        $callbackUrl = $entity['data']['callback_url'] ?? null;
        $eventsUrl = $entity['data']['events_callback_url'] ?? null;
        if ($eventsUrl && $eventsUrl !== $callbackUrl) {
            self::fireKybWebhook($entity, $sessionId, $outcome, 'events_callback_url');
        }

        return true;
    }

    /**
     * Control plane: manually complete a KYB session.
     */
    public static function setKybOutcome(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findKybSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        $pdo = Database::connect();
        $pdo->prepare("
            UPDATE pending_callbacks
            SET status = 'cancelled'
            WHERE entity_id = :eid AND status = 'pending'
        ")->execute(['eid' => (int)$entity['id']]);

        return self::autoCompleteKyb($sessionId, $outcome, $entity['namespace']);
    }

    /**
     * List all KYB sessions (control plane).
     */
    public static function listKybSessions(?string $namespace = null): array
    {
        $ns = $namespace ?? self::KYB_NAMESPACE;
        $entities = EntityManager::findAllByNamespace($ns, self::KYB_ENTITY_TYPE);

        return array_map(function (array $entity): array {
            $data = $entity['data'] ?? [];
            return [
                'verification_session_id' => $data['verification_session_id'] ?? $entity['entity_ref'],
                'client_reference_id'     => $data['client_reference_id'] ?? null,
                'state'                   => $entity['state'],
                'verification_result'     => $data['verification_result'] ?? null,
                'session_type'            => $data['session_type'] ?? null,
                'template_id'             => $data['template_id'] ?? null,
                'callback_url'            => $data['callback_url'] ?? null,
                'created_at'              => $entity['created_at'] ?? null,
                'updated_at'              => $entity['updated_at'] ?? null,
            ];
        }, $entities);
    }

    // ── KYB Private helpers ─────────────────────────────────────────────────

    /**
     * Find a KYB session by ID.
     */
    private static function findKybSession(string $sessionId, ?string $namespace): ?array
    {
        if ($namespace !== null) {
            $entity = EntityManager::find($namespace, self::KYB_ENTITY_TYPE, $sessionId);
            if ($entity !== null) {
                return $entity;
            }
        }

        $entity = EntityManager::find(self::KYB_NAMESPACE, self::KYB_ENTITY_TYPE, $sessionId);
        if ($entity !== null) {
            return $entity;
        }

        // Global search
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM entities
            WHERE entity_type = :type AND entity_ref = :ref
            LIMIT 1
        ");
        $stmt->execute(['type' => self::KYB_ENTITY_TYPE, 'ref' => $sessionId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row ?: null;
    }

    /**
     * Fire an HMAC-SHA256-signed KYB webhook.
     *
     * KYB webhooks have a different payload structure than KYC:
     * - business_profile_result instead of event_type
     * - documents, related_people, addresses, business_info
     * - No id_info/face_match_info/face_liveness_info
     */
    private static function fireKybWebhook(array $entity, string $sessionId, string $outcome, string $urlField): void
    {
        $data = $entity['data'];
        $targetUrl = $data[$urlField] ?? null;
        if (!$targetUrl) {
            return;
        }

        $webhookPayload = self::buildKybWebhookPayload($data, $sessionId, $outcome);

        $hmacKey = $_ENV['AIPRISE_HMAC_KEY'] ?? 'virtual-aiprise-key-for-testing';
        $payloadJson = json_encode($webhookPayload);
        $signature = strtolower(hash_hmac('sha256', $payloadJson, $hmacKey));

        CallbackScheduler::schedule(
            namespace: $entity['namespace'],
            targetUrl: $targetUrl,
            payload: $webhookPayload,
            entityId: (int)$entity['id'],
            delaySeconds: 0,
            headers: ["X-HMAC-SIGNATURE: {$signature}"],
        );
    }

    /**
     * Build the KYB webhook payload.
     *
     * penny-api's webhookUrlSessionKyb() extracts:
     *   - business_profile_result → triggers processing (MUST be present)
     *   - verification_session_id / verification_session_ids → lookup business_customer
     *   - client_reference_id → fallback lookup
     *   - name, country_code, tax_identification_number → business info
     *   - business_info.tax_id, business_info.country_code → metadata
     *   - addresses[0] → city, zip, state, street
     *   - documents[].file_type → mapped to DB columns via typeDocuments
     *   - related_people[] → firstName, lastName, email, dateOfBirth
     *   - user_input.user_data.phone_number → headquartersPhone
     *   - aml_info.warnings → warning processing
     *   - aiprise_summary.verification_result → additional status
     */
    private static function buildKybWebhookPayload(array $data, string $sessionId, string $outcome): array
    {
        $userData = $data['user_data'] ?? [];
        $countryCode = $userData['country_code'] ?? $userData['country'] ?? 'MX';
        $businessName = $userData['business_name'] ?? $userData['name'] ?? 'VIRTUAL_BUSINESS_NAME';
        $taxId = $userData['tax_id'] ?? 'VIRTUAL-TAX-001';

        $officerSessionId = self::generateUuid();

        return [
            'business_profile_result'  => $outcome,
            'verification_session_id'  => $sessionId,
            'verification_session_ids' => [$sessionId],
            'client_reference_id'      => $data['client_reference_id'],
            'name'                     => $businessName,
            'country_code'             => $countryCode,
            'tax_identification_number'=> $taxId,
            'business_info'            => [
                'tax_id'       => $taxId,
                'country_code' => $countryCode,
                'warnings'     => [],
            ],
            'addresses'                => [
                [
                    'address_street_1' => $userData['address_street_1'] ?? 'Virtual Business St 456',
                    'address_street_2' => $userData['address_street_2'] ?? null,
                    'address_city'     => $userData['address_city'] ?? 'Virtual City',
                    'address_state'    => $userData['address_state'] ?? 'VC',
                    'address_zip_code' => $userData['address_zip_code'] ?? '00000',
                ],
            ],
            'website'                  => $userData['website'] ?? 'https://virtual-business.example.com',
            'documents'                => [
                [
                    'file_type'   => 'SHAREHOLDERS_REGISTRY',
                    'file_name'   => 'virtual_shareholders_registry.pdf',
                    'file_s3_url' => 'https://virtual-service/docs/shareholders_registry.pdf',
                ],
                [
                    'file_type'   => 'ADDRESS_PROOF_DOCUMENT',
                    'file_name'   => 'virtual_proof_of_address.pdf',
                    'file_s3_url' => 'https://virtual-service/docs/proof_of_address.pdf',
                ],
                [
                    'file_type'   => 'ARTICLES_OF_INCORPORATION',
                    'file_name'   => 'virtual_articles_of_incorporation.pdf',
                    'file_s3_url' => 'https://virtual-service/docs/articles_of_incorporation.pdf',
                ],
            ],
            'related_people'           => [
                [
                    'person_reference_id' => self::generateUuid(),
                    'first_name'          => $userData['officer_first_name'] ?? 'VIRTUAL_OFFICER_FIRST',
                    'last_name'           => $userData['officer_last_name'] ?? 'VIRTUAL_OFFICER_LAST',
                    'email'               => $userData['officer_email'] ?? 'officer@virtual-business.test',
                    'birth_date'          => $userData['officer_dob'] ?? '1985-06-15',
                    'identity'            => [
                        'identity_number'         => 'VIRTUAL-OFFICER-ID-001',
                        'identity_document_front' => 'https://virtual-service/docs/officer_id_front.jpg',
                        'identity_document_back'  => 'https://virtual-service/docs/officer_id_back.jpg',
                    ],
                    'kyc'                 => [
                        'verification_session_id' => $officerSessionId,
                        'verification_result'     => $outcome,
                    ],
                ],
            ],
            'user_input'               => [
                'user_data' => $userData,
            ],
            'aml_info'                 => [
                'warnings' => [],
            ],
            'aiprise_summary'          => [
                'verification_result' => $outcome,
            ],
        ];
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    /**
     * Rewrite a callback URL to use Docker-internal addressing.
     *
     * When penny-api reads callback URLs from the aiprise_configuration DB table,
     * they point to external hosts (e.g. https://penny-api-restricted-dev.alfredpay.io).
     * For local Docker testing, we need to rewrite them to the internal Docker hostname
     * (e.g. http://penny-api-restricted-local:3002).
     *
     * Set AIPRISE_CALLBACK_REWRITE_HOST to enable (e.g. "http://penny-api-restricted-local:3002").
     * Only the scheme+host+port is replaced; the path is preserved.
     */
    private static function rewriteCallbackUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $rewriteHost = $_ENV['AIPRISE_CALLBACK_REWRITE_HOST'] ?? '';
        if ($rewriteHost === '') {
            return $url;
        }

        $parsed = parse_url($url);
        if ($parsed === false || !isset($parsed['host'])) {
            return $url;
        }

        // Strip trailing slash from rewrite host
        $rewriteHost = rtrim($rewriteHost, '/');

        // Reconstruct: rewrite host + original path + query
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        return $rewriteHost . $path . $query . $fragment;
    }

    /**
     * Find a session by ID, trying the given namespace first, then globally.
     */
    private static function findSession(string $sessionId, ?string $namespace): ?array
    {
        // Try specific namespace first
        if ($namespace !== null) {
            $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionId);
            if ($entity !== null) {
                return $entity;
            }
        }

        // Try default namespace
        $entity = EntityManager::find(self::DEFAULT_NAMESPACE, self::ENTITY_TYPE, $sessionId);
        if ($entity !== null) {
            return $entity;
        }

        // Global search (session IDs are UUIDs, globally unique)
        return self::findSessionGlobally($sessionId);
    }

    /**
     * Search all namespaces for a session by entity_ref.
     */
    private static function findSessionGlobally(string $sessionId): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT * FROM entities
            WHERE entity_type = :type AND entity_ref = :ref
            LIMIT 1
        ");
        $stmt->execute(['type' => self::ENTITY_TYPE, 'ref' => $sessionId]);
        $row = $stmt->fetch();
        if ($row) {
            $row['data'] = json_decode($row['data'], true);
        }
        return $row ?: null;
    }

    /**
     * Fire an HMAC-SHA256-signed webhook to a callback URL.
     *
     * Payload matches the AiPrise UserVerificationResponseV2 schema.
     * The HMAC signature is computed the same way AiPrise does it:
     *   HMAC-SHA256(JSON.stringify(payload), AIPRISE_ID_KEY)
     *
     * penny-api-restricted validates this via:
     *   crypto.createHmac('sha256', AIPRISE_ID_KEY)
     *     .update(Buffer.from(JSON.stringify(payload), 'utf8'))
     *     .digest('hex').toLowerCase()
     *
     * Header: X-HMAC-SIGNATURE: hex-encoded-hmac-sha256
     */
    private static function fireWebhook(array $entity, string $sessionId, string $outcome, string $urlField): void
    {
        $data = $entity['data'];
        $targetUrl = $data[$urlField] ?? null;
        if (!$targetUrl) {
            return;
        }

        $webhookPayload = self::buildWebhookPayload($data, $sessionId, $outcome);

        // Compute HMAC-SHA256 on the exact JSON that will be sent.
        // CallbackScheduler stores json_encode($payload), and that's what gets POSTed.
        // Node.js JSON.stringify(JSON.parse(body)) produces identical output for our payloads.
        $hmacKey = $_ENV['AIPRISE_HMAC_KEY'] ?? 'virtual-aiprise-key-for-testing';
        $payloadJson = json_encode($webhookPayload);
        $signature = strtolower(hash_hmac('sha256', $payloadJson, $hmacKey));

        CallbackScheduler::schedule(
            namespace: $entity['namespace'],
            targetUrl: $targetUrl,
            payload: $webhookPayload,
            entityId: (int)$entity['id'],
            delaySeconds: 0,
            headers: ["X-HMAC-SIGNATURE: {$signature}"],
        );
    }

    // ── Response builders (match official AiPrise schema) ──────────────────

    /**
     * Build id_info section matching the official IDInfo schema.
     * Generates realistic mock data based on submitted user_data.
     */
    private static function buildIdInfo(string $result, array $userData, string $countryCode): array
    {
        $identity = $userData['identity'] ?? [];
        $firstName = $userData['first_name'] ?? 'VIRTUAL_FIRST_NAME';
        $lastName = $userData['last_name'] ?? 'VIRTUAL_LAST_NAME';

        return [
            'result'            => $result,
            'status'            => 'COMPLETED',
            'warnings'          => [],
            'id_type'           => $identity['identity_document_type'] ?? 'NATIONAL_ID',
            'id_number'         => $identity['identity_number'] ?? 'VIRTUAL-ID-001',
            'id_expiry_date'    => '2030-12-31',
            'id_issue_date'     => '2020-01-01',
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'full_name'         => "{$firstName} {$lastName}",
            'birth_date'        => $userData['date_of_birth'] ?? '1990-01-01',
            'gender'            => 'M',
            'nationality'       => $countryCode,
            'nationality_code'  => $countryCode,
            'issue_country'     => $countryCode,
            'issue_country_code'=> $countryCode,
            'address'           => [
                'full_address'    => 'VIRTUAL_ADDRESS',
                'parsed_address'  => [
                    'address_street_1' => $userData['address']['address_street_1'] ?? 'Virtual Street 123',
                    'address_city'     => $userData['address']['address_city'] ?? 'Virtual City',
                    'address_state'    => $userData['address']['address_state'] ?? 'VC',
                    'address_country'  => $countryCode,
                    'address_zip_code' => $userData['address']['address_zip_code'] ?? '00000',
                ],
            ],
            'document_details'  => [
                'ocr_data'     => ['Document Country' => $countryCode],
                'mrz_data'     => null,
                'barcode_data' => null,
            ],
            'section_id'        => self::generateUuid(),
        ];
    }

    /**
     * Build face_match_info section matching FaceMatchInfo schema.
     */
    private static function buildFaceMatchInfo(string $result): array
    {
        return [
            'result'           => $result === 'APPROVED' ? 'APPROVED' : 'DECLINED',
            'status'           => 'COMPLETED',
            'warnings'         => [],
            'face_match_score' => $result === 'APPROVED' ? 98.5 : 12.3,
            'section_id'       => self::generateUuid(),
        ];
    }

    /**
     * Build face_liveness_info section matching FaceLivenessInfo schema.
     */
    private static function buildFaceLivenessInfo(string $result): array
    {
        return [
            'result'     => $result === 'APPROVED' ? 'APPROVED' : 'DECLINED',
            'status'     => 'COMPLETED',
            'warnings'   => [],
            'section_id' => self::generateUuid(),
        ];
    }

    /**
     * Build aml_info section matching AMLInfo schema.
     */
    private static function buildAmlInfo(string $result): array
    {
        return [
            'result'      => $result === 'APPROVED' ? 'APPROVED' : 'REVIEW',
            'status'      => 'COMPLETED',
            'warnings'    => [],
            'num_hits'    => 0,
            'entity_hits' => [],
            'section_id'  => self::generateUuid(),
        ];
    }

    /**
     * Build the full webhook callback payload matching UserVerificationResponseV2.
     * This is what gets sent to penny-api-restricted's callback URL.
     */
    private static function buildWebhookPayload(array $data, string $sessionId, string $outcome): array
    {
        $userData = $data['user_data'] ?? [];
        $identity = $userData['identity'] ?? [];
        $countryCode = $identity['identity_country_code'] ?? 'MX';

        return [
            'client_reference_id'     => $data['client_reference_id'],
            'verification_session_id' => $sessionId,
            'event_type'              => 'VERIFICATION_SESSION_COMPLETION',
            'verification_result'     => $outcome,
            'aiprise_summary'         => [
                'verification_result' => $outcome,
            ],
            'status'                  => 'COMPLETED',
            'template_id'             => $data['template_id'] ?? null,
            'created_at'              => (int)(microtime(true) * 1000),
            'environment'             => 'SANDBOX',
            // Include check details — penny-api extracts customer data from these
            'id_info'                 => self::buildIdInfo($outcome, $userData, $countryCode),
            'face_match_info'         => self::buildFaceMatchInfo($outcome),
            'face_liveness_info'      => self::buildFaceLivenessInfo($outcome),
            'aml_info'                => self::buildAmlInfo($outcome),
            'user_input'              => [
                'user_data' => $userData,
            ],
        ];
    }

    /**
     * Generate a v4 UUID.
     */
    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 1
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
