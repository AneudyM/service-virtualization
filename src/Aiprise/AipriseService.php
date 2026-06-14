<?php

declare(strict_types=1);

namespace App\Aiprise;

use App\Callback\CallbackScheduler;
use App\Core\Database;
use App\Entity\EntityManager;

/**
 * AiPrise KYC/KYB verification service.
 * Auto-approves after DEFAULT_AUTO_DELAY seconds unless overridden via control plane.
 */
final class AipriseService
{
    private const ENTITY_TYPE = 'aiprise_session';
    private const DEFAULT_NAMESPACE = '__aiprise__';
    private const DEFAULT_AUTO_DELAY = 10;
    private const DEFAULT_OUTCOME = 'APPROVED';

    /** POST /api/v1/verify/get_user_verification_url */
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

        // APP_INTERNAL_URL targets port 80 inside the container, not APP_BASE_URL.
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

        $expiryTime = (new \DateTimeImmutable())->modify('+30 days')->format('Y-m-d\TH:i:s\Z');

        return [
            'session_expiry_time'     => $expiryTime,
            'verification_session_id' => $sessionId,
            'verification_url'        => $verificationUrl,
        ];
    }

    /** POST /api/v1/verify/run_user_verification. API-driven, no URL. */
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
        $outcome = self::DEFAULT_OUTCOME;

        $callbackUrl = self::rewriteCallbackUrl($callbackUrl);
        $eventsCallbackUrl = self::rewriteCallbackUrl($eventsCallbackUrl);

        $data = [
            'verification_session_id' => $sessionId,
            'template_id'             => $templateId,
            'client_reference_id'     => $clientReferenceId,
            'callback_url'            => $callbackUrl,
            'events_callback_url'     => $eventsCallbackUrl,
            'user_data'               => $userData,
            'verification_result'     => $outcome,
            'auto_outcome'            => $outcome,
            'session_type'            => 'full_verification',
        ];

        $entityId = EntityManager::create(
            namespace: $ns,
            entityType: self::ENTITY_TYPE,
            entityRef: $sessionId,
            state: 'processing',
            data: $data,
        );

        $internalUrl = $_ENV['APP_INTERNAL_URL'] ?? 'http://localhost';
        $selfUrl = "{$internalUrl}/api/v1/verify/_internal/auto-complete/{$sessionId}";

        $autoDelay = (int)($_ENV['AIPRISE_AUTO_DELAY'] ?? self::DEFAULT_AUTO_DELAY);

        CallbackScheduler::schedule(
            namespace: $ns,
            targetUrl: $selfUrl,
            payload: [
                'verification_session_id' => $sessionId,
                'namespace'               => $ns,
                'outcome'                 => $outcome,
            ],
            entityId: $entityId,
            delaySeconds: $autoDelay,
        );

        // Synchronous response. Consumed fields: aiprise_summary.verification_result,
        // id_info.id_number, id_info.document_details.ocr_data.cpf
        $template = TemplateRegistry::getOrDefault($templateId);
        $checks = $template['checks'];

        $response = [
            'verification_session_id' => $sessionId,
            'client_reference_id'     => $clientReferenceId,
            'template_id'             => $templateId,
            'status'                  => 'COMPLETED',
            'environment'             => 'SANDBOX',
            'created_at'              => (int)(microtime(true) * 1000),
            'aiprise_summary'         => [
                'verification_result' => $outcome,
            ],
        ];

        if (in_array('id_check', $checks, true)) {
            $response['id_info'] = self::buildIdInfo($outcome, $userData, $template);
        }
        if (in_array('face_match', $checks, true)) {
            $response['face_match_info'] = self::buildFaceMatchInfo($outcome);
        }
        if (in_array('liveness', $checks, true)) {
            $response['face_liveness_info'] = self::buildFaceLivenessInfo($outcome);
        }
        if (in_array('aml', $checks, true)) {
            $response['aml_info'] = self::buildAmlInfo($outcome);
        }

        $response['user_input'] = [
            'user_data' => $userData,
        ];

        return $response;
    }

    /** GET /api/v1/verify/get_user_verification_result/{id} */
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

        if ($isCompleted) {
            $template = TemplateRegistry::getOrDefault($data['template_id'] ?? '');
            $checks = $template['checks'];

            if (in_array('id_check', $checks, true)) {
                $response['id_info'] = self::buildIdInfo($verificationResult, $userData, $template);
            }
            if (in_array('face_match', $checks, true)) {
                $response['face_match_info'] = self::buildFaceMatchInfo($verificationResult);
            }
            if (in_array('liveness', $checks, true)) {
                $response['face_liveness_info'] = self::buildFaceLivenessInfo($verificationResult);
            }
            if (in_array('aml', $checks, true)) {
                $response['aml_info'] = self::buildAmlInfo($verificationResult);
            }
        }

        $response['user_input'] = [
            'user_data' => $userData,
        ];

        return $response;
    }

    /** Fires HMAC-signed webhook to callback_url. */
    public static function autoComplete(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        if ($entity['state'] === 'completed') {
            return true;
        }

        $data = $entity['data'];

        EntityManager::transition(
            entityId: (int)$entity['id'],
            newState: 'completed',
            triggerType: 'callback',
            metadata: ['outcome' => $outcome, 'source' => 'auto_complete'],
            dataUpdates: ['verification_result' => $outcome],
        );

        self::fireWebhook($entity, $sessionId, $outcome, 'callback_url');

        $callbackUrl = $data['callback_url'] ?? null;
        $eventsUrl = $data['events_callback_url'] ?? null;
        if ($eventsUrl && $eventsUrl !== $callbackUrl) {
            self::fireWebhook($entity, $sessionId, $outcome, 'events_callback_url');
        }

        return true;
    }

    /**
     * Control plane: manually complete a session with a specific outcome.
     * Used for testing specific scenarios like DECLINED, REVIEW, UPDATE_REQUIRED.
     */
    public static function setOutcome(string $sessionId, string $outcome, ?string $namespace = null): bool
    {
        $entity = self::findSession($sessionId, $namespace);
        if ($entity === null) {
            return false;
        }

        $pdo = Database::connect();
        $pdo->prepare("
            UPDATE pending_callbacks
            SET status = 'cancelled'
            WHERE entity_id = :eid AND status = 'pending'
        ")->execute(['eid' => (int)$entity['id']]);

        return self::autoComplete($sessionId, $outcome, $entity['namespace']);
    }

    /** Control plane: list all AiPrise sessions. */
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

    private const KYB_ENTITY_TYPE = 'aiprise_kyb_session';
    private const KYB_NAMESPACE = '__aiprise_kyb__';

    /**
     * POST /api/v1/verify/get_business_verification_url
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

    /** GET /api/v1/verify/get_business_verification_result/{id} */
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

    /**
     * Look up any session (KYC or KYB) by its verification_session_id.
     * Returns the full entity row including data, or null if not found.
     *
     * Used by the verify page to retrieve session data like template_id and user_data
     * without exposing the private KYC/KYB finders.
     */
    public static function lookupSession(string $sessionId): ?array
    {
        $entity = self::findSession($sessionId, null);
        if ($entity !== null) {
            return $entity;
        }

        return self::findKybSession($sessionId, null);
    }

    /**
     * Cancel any pending auto-complete callbacks for a session.
     * Called when the verify page loads so the user has time to fill in the form
     * without the auto-complete timer firing first.
     */
    public static function cancelPendingCallbacks(string $sessionId): void
    {
        $entity = self::lookupSession($sessionId);
        if ($entity === null) {
            return;
        }

        $pdo = Database::connect();
        $pdo->prepare("
            UPDATE pending_callbacks
            SET status = 'cancelled'
            WHERE entity_id = :eid AND status = 'pending'
        ")->execute(['eid' => (int)$entity['id']]);
    }

    /**
     * Update the user_data within a session's stored data.
     * Deep-merges $formData into the existing user_data.identity subkey.
     *
     * Used by the verify page to persist form field values like identity_number
     * before triggering auto-complete, so the webhook payload includes them.
     */
    public static function updateSessionUserData(string $sessionId, array $formData): bool
    {
        $entity = self::lookupSession($sessionId);
        if ($entity === null) {
            return false;
        }

        $data = $entity['data'];
        $userData = $data['user_data'] ?? [];

        if (isset($formData['identity_number'])) {
            $userData['identity'] = $userData['identity'] ?? [];
            $userData['identity']['identity_number'] = $formData['identity_number'];
        }
        if (isset($formData['identity_number_type'])) {
            $userData['identity'] = $userData['identity'] ?? [];
            $userData['identity']['identity_number_type'] = $formData['identity_number_type'];
        }

        foreach (['first_name', 'last_name', 'date_of_birth', 'email_address', 'phone_number'] as $field) {
            if (isset($formData[$field]) && $formData[$field] !== '') {
                $userData[$field] = $formData[$field];
            }
        }

        return EntityManager::updateData((int)$entity['id'], ['user_data' => $userData]);
    }

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
     * penny-api webhookUrlSessionKyb() consumes, in order:
     *   business_profile_result                            (required, triggers processing)
     *   verification_session_id / verification_session_ids (business_customer lookup)
     *   client_reference_id                                (fallback lookup)
     *   name, country_code, tax_identification_number     (business info)
     *   business_info.tax_id, business_info.country_code  (metadata)
     *   addresses[0]                                       (city, zip, state, street)
     *   documents[].file_type                              (mapped via typeDocuments)
     *   related_people[]                                   (firstName, lastName, email, dob)
     *   user_input.user_data.phone_number                  (headquartersPhone)
     *   aml_info.warnings                                  (warning processing)
     *   aiprise_summary.verification_result                (additional status)
     */
    private static function buildKybWebhookPayload(array $data, string $sessionId, string $outcome): array
    {
        $userData = $data['user_data'] ?? [];
        $template = TemplateRegistry::getOrDefault($data['template_id'] ?? '');
        $countryCode = $userData['country_code'] ?? $userData['country'] ?? $template['country'];
        $businessName = $userData['business_name'] ?? $userData['name'] ?? 'VIRTUAL_BUSINESS_NAME';
        $taxId = $userData['tax_id'] ?? 'VIRTUAL-TAX-001';

        $officerSessionId = self::generateUuid();

        $kybDocs = $template['kyb_documents'] ?? [
            ['file_type' => 'SHAREHOLDERS_REGISTRY',       'file_name' => 'virtual_shareholders_registry.pdf'],
            ['file_type' => 'ADDRESS_PROOF_DOCUMENT',      'file_name' => 'virtual_proof_of_address.pdf'],
            ['file_type' => 'ARTICLES_OF_INCORPORATION',   'file_name' => 'virtual_articles_of_incorporation.pdf'],
        ];
        $documents = array_map(fn(array $doc) => [
            'file_type'   => $doc['file_type'],
            'file_name'   => $doc['file_name'],
            'file_s3_url' => 'https://virtual-service/docs/' . basename($doc['file_name']),
        ], $kybDocs);

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
            'documents'                => $documents,
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

    /**
     * Callback URLs from the aiprise_configuration table point to external hosts
     * that the container cannot reach. When AIPRISE_CALLBACK_REWRITE_HOST is set,
     * the scheme+host+port are replaced and the path is preserved.
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

        $rewriteHost = rtrim($rewriteHost, '/');

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
        if ($namespace !== null) {
            $entity = EntityManager::find($namespace, self::ENTITY_TYPE, $sessionId);
            if ($entity !== null) {
                return $entity;
            }
        }

        $entity = EntityManager::find(self::DEFAULT_NAMESPACE, self::ENTITY_TYPE, $sessionId);
        if ($entity !== null) {
            return $entity;
        }

        // Session IDs are UUIDs so they are globally unique across namespaces.
        return self::findSessionGlobally($sessionId);
    }

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
     * AiPrise UserVerificationResponseV2 payload.
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

    /** Build id_info section (IDInfo schema). */
    private static function buildIdInfo(string $result, array $userData, array $template): array
    {
        $identity = $userData['identity'] ?? [];
        $countryCode = $identity['identity_country_code'] ?? $template['country'];
        $firstName = $userData['first_name'] ?? 'VIRTUAL_FIRST_NAME';
        $lastName = $userData['last_name'] ?? 'VIRTUAL_LAST_NAME';

        $idType = $identity['identity_document_type'] ?? $template['id_type'] ?? 'NATIONAL_ID';
        $idNumber = $identity['identity_number'] ?? $template['virtual_id_number'] ?? 'VIRTUAL-ID-001';

        $address = $userData['address'] ?? [];
        $parsedAddress = [
            'address_street_1' => $address['address_street_1'] ?? 'Virtual Street 123',
            'address_city'     => $address['address_city'] ?? 'Virtual City',
            'address_state'    => $address['address_state'] ?? 'VC',
            'address_country'  => $countryCode,
            'address_zip_code' => $address['address_zip_code'] ?? '00000',
        ];
        $fullAddress = implode(', ', array_filter([
            $parsedAddress['address_street_1'],
            $parsedAddress['address_city'],
            $parsedAddress['address_state'],
            $parsedAddress['address_zip_code'],
            $parsedAddress['address_country'],
        ]));

        $fieldInfo = [];
        foreach ($template['field_match_types'] as $matchType) {
            $fieldInfo['id_number']['matched'][] = $matchType;
        }
        if (!empty($userData['date_of_birth'])) {
            foreach ($template['field_match_types'] as $matchType) {
                $fieldInfo['birth_date']['matched'][] = $matchType;
            }
        }

        return [
            'result'            => $result,
            'status'            => 'COMPLETED',
            'warnings'          => [],
            'id_type'           => $idType,
            'id_number'         => $idNumber,
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
                'full_address'    => $fullAddress,
                'parsed_address'  => $parsedAddress,
            ],
            'document_details'  => [
                'ocr_data'     => self::buildOcrData($countryCode, $idType, $idNumber, $template),
                'mrz_data'     => null,
                'barcode_data' => null,
            ],
            'field_info'        => $fieldInfo,
            'section_id'        => self::generateUuid(),
        ];
    }

    /** Consumed field for Brazil: ocr_data.cpf */
    private static function buildOcrData(string $countryCode, string $idType, string $idNumber, array $template): array
    {
        $ocrData = [
            'Document Country' => $countryCode,
            'ID Type'          => $idType,
        ];

        if ($countryCode === 'BR' || ($template['id_number_type'] ?? '') === 'TAX_ID') {
            $ocrData['cpf'] = $idNumber;
        }

        return $ocrData;
    }

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

    private static function buildFaceLivenessInfo(string $result): array
    {
        return [
            'result'     => $result === 'APPROVED' ? 'APPROVED' : 'DECLINED',
            'status'     => 'COMPLETED',
            'warnings'   => [],
            'section_id' => self::generateUuid(),
        ];
    }

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
     * Build UserVerificationResponseV2 webhook payload for penny-api-restricted.
     * Sections conditionally included based on template checks.
     */
    private static function buildWebhookPayload(array $data, string $sessionId, string $outcome): array
    {
        $userData = $data['user_data'] ?? [];
        $template = TemplateRegistry::getOrDefault($data['template_id'] ?? '');
        $checks = $template['checks'];

        $payload = [
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
        ];

        if (in_array('id_check', $checks, true)) {
            $payload['id_info'] = self::buildIdInfo($outcome, $userData, $template);
        }
        if (in_array('face_match', $checks, true)) {
            $payload['face_match_info'] = self::buildFaceMatchInfo($outcome);
        }
        if (in_array('liveness', $checks, true)) {
            $payload['face_liveness_info'] = self::buildFaceLivenessInfo($outcome);
        }
        if (in_array('aml', $checks, true)) {
            $payload['aml_info'] = self::buildAmlInfo($outcome);
        }

        $payload['user_input'] = [
            'user_data' => $userData,
        ];

        return $payload;
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant 1
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
