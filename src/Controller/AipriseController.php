<?php

declare(strict_types=1);

namespace App\Controller;

use App\Aiprise\AipriseService;
use App\Aiprise\TemplateRegistry;
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

    // ── Internal Session Update (verify page form submission) ──────────────

    /**
     * POST /api/v1/verify/_internal/update-session/{verification_session_id}
     *
     * Called by the verify page form to persist identity number and other
     * fields into the session data before triggering auto-complete.
     * Validates the identity number format against the template's rules.
     *
     * NOT part of the real AiPrise API -- this is the virtual service's form handler.
     */
    public static function updateSession(string $sessionId, array $body): never
    {
        // Look up the session to get its template_id
        $entity = AipriseService::lookupSession($sessionId);
        if ($entity === null) {
            JsonResponse::send([
                'error'   => true,
                'message' => "Session '{$sessionId}' not found",
            ], 404);
        }

        $templateId = $entity['data']['template_id'] ?? '';
        $identityNumber = $body['identity_number'] ?? '';

        // Validate identity number format if provided
        if ($identityNumber !== '') {
            $validation = TemplateRegistry::validateIdentityNumber($templateId, $identityNumber);
            if (!$validation['valid']) {
                JsonResponse::send([
                    'error'   => true,
                    'message' => $validation['error'],
                    'field'   => 'identity_number',
                ], 422);
            }
        }

        // Persist the form data into the session
        $success = AipriseService::updateSessionUserData($sessionId, $body);

        if (!$success) {
            JsonResponse::send([
                'error'   => true,
                'message' => 'Failed to update session data',
            ], 500);
        }

        JsonResponse::send([
            'status'                  => 'updated',
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
     * Looks up the session from the DB to determine the template, then renders
     * template-specific form fields (identity number, document placeholders).
     * When the user clicks "Complete Verification", the page:
     *   1. Validates the form fields (client-side + server-side)
     *   2. Persists form data into the session via _internal/update-session
     *   3. Triggers auto-complete to fire the webhook to penny-api-restricted
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
        $updateSessionEndpoint = "/api/v1/verify/_internal/update-session/{$sessionId}";
        $baseUrl = $_ENV['APP_BASE_URL'] ?? 'http://localhost:8080';

        // Look up session to get template and pre-populated data
        $entity = AipriseService::lookupSession($sessionId);
        $sessionData = $entity['data'] ?? [];
        $templateId = $sessionData['template_id'] ?? '';
        $template = TemplateRegistry::getOrDefault($templateId);

        // Cancel pending auto-complete so the user has time to fill in the form
        if ($entity !== null) {
            AipriseService::cancelPendingCallbacks($sessionId);
        }
        $userData = $sessionData['user_data'] ?? [];
        $identity = $userData['identity'] ?? [];

        // Template-specific values for the form (escaped for safe HTML interpolation)
        $templateLabel = htmlspecialchars($template['label'] ?? 'Unknown', ENT_QUOTES);
        $country = htmlspecialchars($template['country'] ?? 'MX', ENT_QUOTES);
        $countryName = htmlspecialchars(TemplateRegistry::getCountryName($template['country'] ?? 'MX'), ENT_QUOTES);
        $idNumberLabel = htmlspecialchars($template['id_number_label'] ?? 'Identity Number', ENT_QUOTES);
        $idNumberPlaceholder = htmlspecialchars($template['id_number_placeholder'] ?? '', ENT_QUOTES);
        $jsPattern = htmlspecialchars(TemplateRegistry::getJsPattern($templateId), ENT_QUOTES);
        $idNumberType = htmlspecialchars($template['id_number_type'] ?? 'NATIONAL_ID', ENT_QUOTES);

        // Pre-populated values from penny-api's request
        $prefilledIdNumber = htmlspecialchars($identity['identity_number'] ?? '', ENT_QUOTES);
        $prefilledFirstName = htmlspecialchars($userData['first_name'] ?? '', ENT_QUOTES);
        $prefilledLastName = htmlspecialchars($userData['last_name'] ?? '', ENT_QUOTES);
        $prefilledDob = htmlspecialchars($userData['date_of_birth'] ?? '', ENT_QUOTES);

        // Build document type cards for Step 1 (KYC only)
        $docTypes = $template['doc_types'] ?? ['NATIONAL_ID'];
        $docTypeCardsHtml = '';
        $singleDocType = count($docTypes) === 1;
        foreach ($docTypes as $docType) {
            $meta = TemplateRegistry::getDocTypeMeta($template['country'] ?? 'MX', $docType);
            $metaLabel = htmlspecialchars($meta['label'], ENT_QUOTES);
            $metaDesc = htmlspecialchars($meta['description'], ENT_QUOTES);
            $dtEsc = htmlspecialchars($docType, ENT_QUOTES);
            $selectedClass = $singleDocType ? ' selected' : '';
            $docTypeCardsHtml .= <<<CARD
                    <button type="button" class="doc-card{$selectedClass}" data-doctype="{$dtEsc}" onclick="selectDocType(this, '{$dtEsc}')">
                        <svg class="doc-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <div class="doc-card-text">
                            <span class="doc-card-label">{$metaLabel}</span>
                            <span class="doc-card-desc">{$metaDesc}</span>
                        </div>
                        <div class="doc-radio"><div class="doc-radio-dot"></div></div>
                    </button>
CARD;
        }

        // Build KYB summary if business template
        $kybSummaryHtml = '';
        if ($isKyb) {
            $bizName = htmlspecialchars($userData['business_name'] ?? $userData['name'] ?? '—', ENT_QUOTES);
            $bizTaxId = htmlspecialchars($userData['tax_id'] ?? '—', ENT_QUOTES);
            $bizCountry = htmlspecialchars($userData['country_code'] ?? $userData['country'] ?? $country, ENT_QUOTES);
            $kybSummaryHtml = <<<KYBHTML
                        <div class="kyb-summary">
                            <div class="kyb-row"><span class="kyb-label">Business Name</span><span class="kyb-value">{$bizName}</span></div>
                            <div class="kyb-row"><span class="kyb-label">Tax ID</span><span class="kyb-value">{$bizTaxId}</span></div>
                            <div class="kyb-row"><span class="kyb-label">Country</span><span class="kyb-value">{$bizCountry}</span></div>
                        </div>
KYBHTML;
        }

        // Build form fields HTML (KYC Step 2)
        $formFieldsHtml = '';
        if (!$isKyb && $template['id_number_label'] !== null) {
            $patternAttr = $jsPattern !== '' ? "pattern=\"{$jsPattern}\"" : '';
            $formFieldsHtml = <<<FORMHTML
                        <div class="form-group">
                            <label for="identityNumber">{$idNumberLabel}</label>
                            <input type="text" id="identityNumber" name="identity_number"
                                   placeholder="{$idNumberPlaceholder}" value="{$prefilledIdNumber}"
                                   {$patternAttr} autocomplete="off" spellcheck="false" />
                            <div class="field-error" id="identityError"></div>
                        </div>
                        <div class="form-row">
                            <div class="form-group half">
                                <label for="firstName">First Name</label>
                                <input type="text" id="firstName" name="first_name"
                                       value="{$prefilledFirstName}" />
                            </div>
                            <div class="form-group half">
                                <label for="lastName">Last Name</label>
                                <input type="text" id="lastName" name="last_name"
                                       value="{$prefilledLastName}" />
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="dateOfBirth">Date of Birth</label>
                            <input type="date" id="dateOfBirth" name="date_of_birth"
                                   value="{$prefilledDob}" />
                        </div>
FORMHTML;
        }

        // Document upload placeholders for Step 2
        $docUploadHtml = '';
        foreach ($docTypes as $docType) {
            $docLabel = htmlspecialchars(str_replace('_', ' ', ucwords(strtolower($docType), '_')), ENT_QUOTES);
            $docUploadHtml .= <<<DOCHTML
                            <div class="upload-zone">
                                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                </svg>
                                <span class="upload-label">{$docLabel} (Front &amp; Back)</span>
                                <span class="upload-hint">Document upload simulated</span>
                            </div>
DOCHTML;
        }
        $docUploadHtml .= <<<DOCHTML
                            <div class="upload-zone">
                                <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                                    <path d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z"/>
                                    <path d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
                                </svg>
                                <span class="upload-label">Selfie / Liveness Check</span>
                                <span class="upload-hint">Biometric capture simulated</span>
                            </div>
DOCHTML;

        // CPF check-digit validation JS (only for Brazil template)
        $cpfValidatorJs = '';
        if ($template['id_number_type'] === 'TAX_ID' && $template['country'] === 'BR') {
            $cpfValidatorJs = <<<'CPFJS'
            function validateCpf(cpf) {
                if (!/^\d{11}$/.test(cpf)) return false;
                if (/^(\d)\1{10}$/.test(cpf)) return false;
                const d = cpf.split('').map(Number);
                let sum = 0;
                for (let i = 0; i < 9; i++) sum += d[i] * (10 - i);
                let r = sum % 11;
                let c1 = r < 2 ? 0 : 11 - r;
                if (d[9] !== c1) return false;
                sum = 0;
                for (let i = 0; i < 10; i++) sum += d[i] * (11 - i);
                r = sum % 11;
                let c2 = r < 2 ? 0 : 11 - r;
                return d[10] === c2;
            }
CPFJS;
        }

        $isCpf = ($template['id_number_type'] === 'TAX_ID' && $template['country'] === 'BR') ? 'true' : 'false';
        $isKybJs = $isKyb ? 'true' : 'false';
        $autoSelect = $singleDocType ? 'true' : 'false';

        header('Content-Type: text/html; charset=UTF-8');
        http_response_code(200);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0">
    <title>AiPrise - User &amp; Business Verification</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            background: #f0f0f5;
            color: #1a1a2e;
            -webkit-font-smoothing: antialiased;
        }

        /* ── Layout ── */
        .page {
            display: flex; align-items: center; justify-content: center;
            min-height: 100vh; padding: 1rem;
        }
        .container {
            width: 100%; max-width: 480px; min-height: 70vh;
            background: #fff; border-radius: 1rem;
            box-shadow: 0 4px 24px rgba(0,0,0,0.08);
            overflow: hidden; position: relative;
        }
        @media (min-width: 768px) {
            .container { max-width: 560px; min-height: 600px; max-height: 90vh; }
        }

        /* ── Virtual Service Badge ── */
        .vs-badge {
            position: fixed; top: 12px; right: 12px; z-index: 100;
            background: rgba(255,243,205,0.95); color: #856404;
            padding: 4px 10px; border-radius: 4px; font-size: 10px;
            font-weight: 700; letter-spacing: 0.5px; border: 1px solid #ffc107;
            backdrop-filter: blur(4px);
        }

        /* ── Header Bar ── */
        .header {
            display: flex; align-items: center; padding: 1rem 1.25rem;
            border-bottom: 1px solid #f0f0f5;
        }
        .back-btn {
            width: 28px; height: 28px; border-radius: 50%;
            border: none; background: transparent; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: #1a1a2e; transition: background 0.15s;
        }
        .back-btn:hover { background: rgba(82,81,253,0.08); }
        .back-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .back-btn svg { width: 18px; height: 18px; }
        .header-title {
            flex: 1; text-align: center; font-size: 0.8125rem;
            font-weight: 600; color: #5251fd; letter-spacing: -0.01em;
        }
        .header-spacer { width: 28px; }

        /* ── Content Area ── */
        .content { padding: 1.5rem 1.25rem 2rem; }
        .step { display: none; }
        .step.active { display: block; }
        .step-fade { animation: fadeIn 0.2s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }

        /* ── Step 1: Country + Doc Type ── */
        .section-label {
            font-size: 0.8125rem; font-weight: 600; color: #1a1a2e;
            margin-bottom: 0.75rem;
        }
        .country-select {
            display: flex; align-items: center; gap: 0.625rem;
            width: 100%; padding: 0.75rem 1rem;
            background: rgba(82,81,253,0.05); border: 1px solid rgba(82,81,253,0.2);
            border-radius: 0.5rem; font-family: inherit; font-size: 0.875rem;
            color: #1a1a2e; cursor: default; margin-bottom: 1.5rem;
        }
        .country-flag { font-size: 1.25rem; line-height: 1; }
        .country-name { flex: 1; font-weight: 500; }
        .divider {
            height: 1px; background: rgba(0,0,0,0.06); margin-bottom: 1.5rem;
        }

        /* ── Doc Type Cards ── */
        .doc-card {
            display: flex; align-items: center; gap: 0.75rem;
            width: 100%; padding: 0.875rem 1rem; margin-bottom: 0.75rem;
            background: rgba(82,81,253,0.04); border: 1.5px solid transparent;
            border-radius: 0.625rem; cursor: pointer; font-family: inherit;
            text-align: left; transition: all 0.2s;
        }
        .doc-card:hover { background: rgba(82,81,253,0.08); }
        .doc-card:active { background: rgba(82,81,253,0.12); }
        .doc-card.selected {
            border-color: #5251fd; background: rgba(82,81,253,0.06);
        }
        .doc-icon {
            width: 28px; height: 28px; flex-shrink: 0;
            color: #5251fd;
        }
        .doc-card-text { flex: 1; min-width: 0; }
        .doc-card-label {
            display: block; font-size: 0.875rem; font-weight: 600;
            color: #1a1a2e; margin-bottom: 2px;
        }
        .doc-card-desc {
            display: block; font-size: 0.75rem; font-weight: 300;
            color: #64748b;
        }
        .doc-radio {
            width: 20px; height: 20px; border-radius: 50%;
            border: 2px solid #cbd5e1; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s;
        }
        .doc-radio-dot {
            width: 10px; height: 10px; border-radius: 50%;
            background: transparent; transition: background 0.15s;
        }
        .doc-card.selected .doc-radio {
            border-color: #5251fd;
        }
        .doc-card.selected .doc-radio-dot {
            background: #5251fd;
        }

        /* ── Continue / Complete Buttons ── */
        .btn-primary {
            display: flex; align-items: center; justify-content: center;
            width: 100%; height: 3rem; margin-top: 1.5rem;
            background: #5251fd; color: #fff; border: none;
            border-radius: 0.5rem; font-family: inherit;
            font-size: 0.9375rem; font-weight: 600; cursor: pointer;
            letter-spacing: -0.02em; transition: background 0.15s;
        }
        .btn-primary:hover { background: #4140e0; }
        .btn-primary:disabled { background: #c7c7e0; cursor: not-allowed; }

        /* ── Step 2: Form Fields ── */
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block; font-size: 0.8125rem; font-weight: 600;
            color: #1a1a2e; margin-bottom: 0.375rem;
        }
        .form-group input[type="text"],
        .form-group input[type="date"] {
            width: 100%; height: 2.75rem; padding: 0 0.875rem;
            border: 1.5px solid #e2e8f0; border-radius: 0.5rem;
            font-family: inherit; font-size: 0.875rem; color: #1a1a2e;
            background: #fff; transition: all 0.15s;
        }
        .form-group input:focus {
            outline: none; border-color: #5251fd;
            box-shadow: 0 0 0 3px rgba(82,81,253,0.15);
        }
        .form-group input.invalid {
            border-color: #ef4444;
            box-shadow: 0 0 0 3px rgba(239,68,68,0.1);
        }
        .field-error {
            font-size: 0.75rem; color: #ef4444; margin-top: 0.25rem;
            min-height: 1rem;
        }
        .form-row { display: flex; gap: 0.75rem; }
        .form-group.half { flex: 1; }

        /* ── Document Upload Zones ── */
        .upload-section {
            margin-top: 1.25rem; padding-top: 1.25rem;
            border-top: 1px solid #f0f0f5;
        }
        .upload-section-label {
            font-size: 0.8125rem; font-weight: 600; color: #1a1a2e;
            margin-bottom: 0.75rem;
        }
        .upload-zone {
            display: flex; flex-direction: column; align-items: center;
            justify-content: center; gap: 0.5rem;
            padding: 1.25rem; margin-bottom: 0.75rem;
            border: 2px dashed #e2e8f0; border-radius: 0.625rem;
            background: #fafafe; transition: border-color 0.15s;
        }
        .upload-zone:hover { border-color: #c7c7e0; }
        .upload-icon {
            width: 24px; height: 24px; color: #5251fd; opacity: 0.7;
        }
        .upload-label {
            font-size: 0.8125rem; font-weight: 500; color: #1a1a2e;
        }
        .upload-hint {
            font-size: 0.6875rem; color: #94a3b8; font-style: italic;
        }

        /* ── KYB Summary ── */
        .kyb-summary {
            background: #fafafe; border: 1.5px solid #e2e8f0;
            border-radius: 0.625rem; padding: 1rem; margin-bottom: 1rem;
        }
        .kyb-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 0.5rem 0; font-size: 0.875rem;
            border-bottom: 1px solid #f0f0f5;
        }
        .kyb-row:last-child { border-bottom: none; }
        .kyb-label { color: #64748b; font-weight: 500; }
        .kyb-value { color: #1a1a2e; font-weight: 600; }

        /* ── Status ── */
        .status {
            text-align: center; margin-top: 1rem; font-size: 0.875rem;
            color: #059669; font-weight: 500;
        }
        .status.error { color: #ef4444; }

        /* ── Session ID ── */
        .session-id {
            background: #f8f9fc; border: 1px solid #e2e8f0; border-radius: 0.375rem;
            padding: 0.5rem 0.75rem; font-size: 0.6875rem; color: #94a3b8;
            font-family: ui-monospace, 'SF Mono', monospace;
            word-break: break-all; margin-top: 1.5rem; text-align: center;
        }
    </style>
</head>
<body>
    <div class="vs-badge">VIRTUAL SERVICE</div>
    <div class="page">
        <div class="container">
            <!-- Header -->
            <div class="header">
                <button class="back-btn" id="backBtn" disabled onclick="goToStep(1)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <div class="header-title">aiprise</div>
                <div class="header-spacer"></div>
            </div>

            <div class="content">
HTML;

        // ── KYC: Step 1 (Document Selection) ──
        if (!$isKyb) {
            $continueDisabled = $singleDocType ? '' : 'disabled';
            echo <<<HTML
                <!-- Step 1: Country + Document Type Selection -->
                <div class="step active step-fade" id="step1">
                    <div class="section-label">Country where your ID document was issued</div>
                    <div class="country-select">
                        <span class="country-flag">{$countryName}</span>
                    </div>

                    <div class="section-label">Choose a document type</div>
{$docTypeCardsHtml}

                    <button type="button" class="btn-primary" id="continueBtn" onclick="goToStep(2)" {$continueDisabled}>Continue</button>
                    <div class="session-id">Session: {$sessionId}</div>
                </div>

                <!-- Step 2: Identity Details -->
                <div class="step" id="step2">
{$formFieldsHtml}

                    <div class="upload-section">
                        <div class="upload-section-label">Documents</div>
{$docUploadHtml}
                    </div>

                    <button type="button" class="btn-primary" id="completeBtn" onclick="completeVerification()">Complete Verification</button>
                    <div id="status" class="status"></div>
                    <div class="session-id">Session: {$sessionId}</div>
                </div>
HTML;
        }

        // ── KYB: Single Step ──
        if ($isKyb) {
            echo <<<HTML
                <div class="step active step-fade" id="step1">
                    <div class="section-label">Business Verification</div>
{$kybSummaryHtml}

                    <button type="button" class="btn-primary" id="completeBtn" onclick="completeVerification()">Complete Verification</button>
                    <div id="status" class="status"></div>
                    <div class="session-id">Session: {$sessionId}</div>
                </div>
HTML;
        }

        echo <<<HTML
            </div>
        </div>
    </div>
    <script>
        const BASE_URL = '{$baseUrl}';
        const UPDATE_ENDPOINT = '{$updateSessionEndpoint}';
        const COMPLETE_ENDPOINT = '{$autoCompleteEndpoint}';
        const JS_PATTERN = '{$jsPattern}';
        const IS_CPF = {$isCpf};
        const IS_KYB = {$isKybJs};
        let selectedDocType = {$autoSelect} ? document.querySelector('.doc-card.selected')?.dataset.doctype || '' : '';

        {$cpfValidatorJs}

        function selectDocType(el, docType) {
            document.querySelectorAll('.doc-card').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            selectedDocType = docType;
            const btn = document.getElementById('continueBtn');
            if (btn) btn.disabled = false;
        }

        function goToStep(n) {
            document.querySelectorAll('.step').forEach(s => { s.classList.remove('active', 'step-fade'); });
            const target = n === 1 ? document.getElementById('step1') : document.getElementById('step2');
            if (target) {
                target.classList.add('active', 'step-fade');
            }
            const backBtn = document.getElementById('backBtn');
            if (backBtn) {
                backBtn.disabled = (n === 1);
            }
        }

        function validateForm() {
            const idInput = document.getElementById('identityNumber');
            const idError = document.getElementById('identityError');
            if (!idInput || !idError) return true;

            const value = idInput.value.trim();
            idError.textContent = '';
            idInput.classList.remove('invalid');

            if (value === '') return true;

            if (JS_PATTERN) {
                const re = new RegExp(JS_PATTERN, 'i');
                if (!re.test(value)) {
                    idError.textContent = '{$idNumberLabel} format is invalid. Expected: {$idNumberPlaceholder}';
                    idInput.classList.add('invalid');
                    return false;
                }
            }

            if (IS_CPF && typeof validateCpf === 'function') {
                if (!validateCpf(value)) {
                    idError.textContent = 'CPF check digits are invalid';
                    idInput.classList.add('invalid');
                    return false;
                }
            }

            return true;
        }

        function collectFormData() {
            const data = {};
            const idInput = document.getElementById('identityNumber');
            const fnInput = document.getElementById('firstName');
            const lnInput = document.getElementById('lastName');
            const dobInput = document.getElementById('dateOfBirth');

            if (idInput && idInput.value.trim()) {
                data.identity_number = idInput.value.trim();
                data.identity_number_type = '{$idNumberType}';
            }
            if (fnInput && fnInput.value.trim()) data.first_name = fnInput.value.trim();
            if (lnInput && lnInput.value.trim()) data.last_name = lnInput.value.trim();
            if (dobInput && dobInput.value) data.date_of_birth = dobInput.value;
            if (selectedDocType) data.identity_document_type = selectedDocType;

            return data;
        }

        async function completeVerification() {
            const btn = document.getElementById('completeBtn');
            const status = document.getElementById('status');
            btn.disabled = true;
            btn.textContent = 'Processing...';
            status.textContent = '';
            status.className = 'status';

            try {
                if (!validateForm()) {
                    btn.disabled = false;
                    btn.textContent = 'Complete Verification';
                    return;
                }

                const formData = collectFormData();
                if (Object.keys(formData).length > 0) {
                    const updateRes = await fetch(BASE_URL + UPDATE_ENDPOINT, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(formData),
                    });
                    const updateResult = await updateRes.json();
                    if (!updateRes.ok) {
                        const idError = document.getElementById('identityError');
                        if (idError && updateResult.field === 'identity_number') {
                            idError.textContent = updateResult.message;
                            const idInput = document.getElementById('identityNumber');
                            if (idInput) idInput.classList.add('invalid');
                        }
                        throw new Error(updateResult.message || 'Validation failed');
                    }
                }

                const res = await fetch(BASE_URL + COMPLETE_ENDPOINT, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ outcome: 'APPROVED' }),
                });
                const data = await res.json();
                if (res.ok) {
                    btn.textContent = 'Verified';
                    btn.style.background = '#059669';
                    status.textContent = 'Verification approved. Webhook callback sent. You can close this page.';
                } else {
                    throw new Error(data.message || 'Verification failed');
                }
            } catch (e) {
                btn.disabled = false;
                btn.textContent = 'Complete Verification';
                if (!status.textContent) {
                    status.textContent = 'Error: ' + e.message;
                    status.className = 'status error';
                }
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
