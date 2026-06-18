<?php
/**
 * AlfredPay Service Virtualization Platform: Front Controller
 *
 * All requests are routed through this file via .htaccess rewrite.
 * Lightweight: no full Symfony kernel: just Router + Controllers + PDO.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controller\AipriseController;
use App\Controller\BankaoolController;
use App\Controller\BridgeController;
use App\Controller\CircleController;
use App\Controller\CmsBackendController;
use App\Controller\ComplianceController;
use App\Controller\ControlPlaneController;
use App\Controller\EmailController;
use App\Controller\ExchangeCopterController;
use App\Controller\FileStoreController;
use App\Controller\FireblocksController;
use App\Controller\KambiaController;
use App\Controller\SiftController;
use App\Controller\TransferoController;
use App\Controller\WesternUnionController;
use App\Controller\WorldpayController;
use App\Core\Database;
use App\Core\JsonResponse;
use App\Core\RequestLogger;
use App\Core\Router;
use Symfony\Component\Dotenv\Dotenv;

// ── Bootstrap ────────────────────────────────────────────────────────────────

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$startTime = microtime(true);

// ── Parse Request ────────────────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

// Strip the base path if deployed in a subdirectory
$basePath = rtrim(parse_url($_ENV['APP_BASE_URL'] ?? '', PHP_URL_PATH) ?: '', '/');
if ($basePath && str_starts_with($uri, $basePath)) {
    $uri = substr($uri, strlen($basePath)) ?: '/';
}

// Strip query string from URI for routing
$routePath = parse_url($uri, PHP_URL_PATH) ?: '/';

$body = [];
$rawBody = file_get_contents('php://input');
if ($rawBody) {
    $decoded = json_decode($rawBody, true);
    if ($decoded !== null) {
        $body = $decoded;
    } elseif (!empty($_POST)) {
        // Bankaool and Paymax endpoints send application/x-www-form-urlencoded;
        // PHP auto-populates $_POST for that content type.
        $body = $_POST;
    }
}

// Namespace resolution: header > query param > body
$namespace = $_SERVER['HTTP_X_TEST_NAMESPACE']
    ?? $_GET['namespace']
    ?? $body['namespace']
    ?? null;

// ── CORS (for browser-based tools/dashboards) ───────────────────────────────

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Test-Namespace, X-API-KEY');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Virtual S3 File Store ────────────────────────────────────────────────────
// AWS SDK sends PUT /{bucket}/{key} for putObject. Keys can span multiple path
// segments (e.g. profile/uuid.jpg), so we match by bucket prefix before the
// router (which only handles single-segment params).
// penny-api's fetchAndEncode() sends GET to the same URL to download files.

$virtualBucket = $_ENV['VIRTUAL_S3_BUCKET'] ?? 'virtual-kyc-files';
if (str_starts_with($routePath, '/' . $virtualBucket . '/')) {
    $objectKey = substr($routePath, strlen('/' . $virtualBucket . '/'));
    if ($objectKey !== '' && $objectKey !== false) {
        // CORS headers already set above
        if ($method === 'PUT') {
            FileStoreController::upload($virtualBucket, $objectKey, $rawBody);
        } elseif ($method === 'GET') {
            FileStoreController::download($virtualBucket, $objectKey);
        } elseif ($method === 'HEAD') {
            FileStoreController::head($virtualBucket, $objectKey);
        }
    }
}

// ── Helper ───────────────────────────────────────────────────────────────────

function requireNamespace(?string $ns): void
{
    if (!$ns) {
        JsonResponse::error(
            'Namespace required. Pass via X-Test-Namespace header, ?namespace= query param, or namespace field in body.',
            400
        );
    }
}

// ── Routes ───────────────────────────────────────────────────────────────────

$router = new Router();

// Health check
$router->get('/', fn() => JsonResponse::ok([
    'service' => 'AlfredPay Service Virtualization Platform',
    'version' => '0.1.0-poc',
    'status'  => 'healthy',
    'time'    => date('Y-m-d\TH:i:s\Z'),
]));

$router->get('/health', function () {
    $dbStatus = 'unknown';
    try {
        Database::connect()->query('SELECT 1');
        $dbStatus = 'connected';
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }
    JsonResponse::ok(['status' => 'healthy', 'db' => $dbStatus]);
});

// ── Browser-Facing Verification Page (AiPrise) ──────────────────────────────

$router->get('/verify', fn() =>
    AipriseController::verifyPage()
);

// ── Control Plane ────────────────────────────────────────────────────────────

$router->get('/control/scenarios', fn() =>
    ControlPlaneController::listScenarios()
);

$router->post('/control/scenarios', fn() =>
    ControlPlaneController::seedScenario($body)
);

$router->get('/control/scenarios/{namespace}', fn($p) =>
    ControlPlaneController::inspectScenario($p['namespace'])
);

$router->delete('/control/scenarios/{namespace}', fn($p) =>
    ControlPlaneController::resetScenario($p['namespace'])
);

$router->post('/control/fire-callbacks', fn() =>
    ControlPlaneController::fireCallbacks($body)
);

$router->get('/control/history/{namespace}', fn($p) =>
    ControlPlaneController::getHistory($p['namespace'])
);

$router->post('/control/cleanup-expired', fn() =>
    ControlPlaneController::cleanupExpired()
);

// ── Virtual CMS Backend API (cms-front drop-in local target) ────────────────
//
// Goal: provide a backend-faithful enough API surface for cms-front local and
// E2E testing. State is namespaced with the same X-Test-Namespace mechanism as
// the rest of service-virtualization; when the frontend does not send a
// namespace, the controller uses `cms-front-local`.

$router->get('/cms-backend', fn() =>
    CmsBackendController::dashboardPage($namespace)
);
$router->get('/control/cms-backend', fn() =>
    CmsBackendController::dashboardPage($namespace)
);
$router->get('/control/cms-backend/state', fn() =>
    CmsBackendController::controlState($namespace)
);
$router->post('/control/cms-backend/state', fn() =>
    CmsBackendController::controlSaveState($namespace, $body)
);
$router->post('/control/cms-backend/reset', fn() =>
    CmsBackendController::controlReset($namespace)
);
$router->post('/control/cms-backend/presets/{preset}', fn($p) =>
    CmsBackendController::controlPreset($namespace, $p['preset'])
);

$router->post('/auth/passwordless-login', fn() =>
    CmsBackendController::passwordlessLogin($body)
);
$router->post('/auth/password-login', fn() =>
    CmsBackendController::passwordLogin($namespace, $body)
);
$router->post('/auth/passwordless-token', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/login-mfa', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/login-with-recovery-code', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/signup', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/password-signup', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/recovery-password-code', fn() =>
    CmsBackendController::passwordlessLogin($body)
);
$router->post('/auth/change-password', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->post('/auth/change-password-jwt', fn() =>
    CmsBackendController::tokenLogin($namespace)
);
$router->get('/user/me', fn() =>
    CmsBackendController::currentUser($namespace)
);

$router->get('/v2/main-accounts/{id}', fn($p) =>
    CmsBackendController::getMainAccount($namespace, $p['id'])
);
$router->get('/v2/main-accounts/{id}/main-accounts', fn($p) =>
    CmsBackendController::listMainAccounts($namespace, $p['id'])
);
$router->get('/v2/main-accounts/{id}/balance', fn($p) =>
    CmsBackendController::getMainAccountBalance($namespace, $p['id'])
);
// cms-front staging currently calls this compatibility alias.
$router->get('/v2/customers/{id}/{currency}/main-account/balance', fn($p) =>
    CmsBackendController::getMainAccountBalanceByCurrency($namespace, $p['currency'])
);
$router->post('/v2/quotes', fn() =>
    CmsBackendController::createQuote($namespace, $body)
);
$router->get('/v2/quotes/{idOrRef}', fn($p) =>
    CmsBackendController::getQuote($namespace, $p['idOrRef'])
);
$router->post('/v2/transfers/internal', fn() =>
    CmsBackendController::createInternalTransfer($namespace, $body)
);
$router->add('PATCH', '/v2/transfers/update', fn() =>
    CmsBackendController::updateTransfer($namespace, $body)
);
$router->get('/v2/transfers/{idOrRef}', fn($p) =>
    CmsBackendController::getTransfer($namespace, $p['idOrRef'])
);
$router->get('/v2/transfers', fn() =>
    CmsBackendController::listTransfers($namespace)
);
$router->post('/v2/customers/{country}/{customerId}/virtual-accounts', fn($p) =>
    CmsBackendController::emptyList()
);
$router->get('/v2/customers/va/{country}/{vaId}/deposit', fn($p) =>
    CmsBackendController::getVirtualAccountDeposit($namespace, $p['country'], $p['vaId'])
);
$router->get('/v2/customers/main-account/{id}/{country}/customers-vas', fn() =>
    CmsBackendController::emptyList()
);
$router->get('/v2/customers/{customerId}/{country}/accounts-vas', fn() =>
    CmsBackendController::emptyList()
);
$router->post('/v2/payin/create', fn() =>
    CmsBackendController::createPayin($namespace, $body)
);
$router->get('/v2/payin/query/{id}', fn($p) =>
    CmsBackendController::getPayin($namespace, $p['id'])
);
$router->get('/v2/payin/list', fn() =>
    CmsBackendController::listPayins($namespace)
);
$router->post('/v2/payout/create', fn() =>
    CmsBackendController::createPayout($namespace, $body)
);
$router->get('/v2/payout/query/{id}', fn($p) =>
    CmsBackendController::getPayout($namespace, $p['id'])
);
$router->get('/v2/payout/list', fn() =>
    CmsBackendController::listPayouts($namespace)
);

$router->get('/transactions', fn() =>
    CmsBackendController::listTransactions($namespace)
);
$router->get('/transactions/customerId', fn() =>
    CmsBackendController::listTransactions($namespace)
);
$router->get('/transactions/searchByCustomer', fn() =>
    CmsBackendController::listTransactions($namespace)
);
$router->get('/transactions/searchByBankAccount', fn() =>
    CmsBackendController::listTransactions($namespace)
);
$router->get('/transactions/searchByTxId', fn() =>
    CmsBackendController::findTransaction($namespace)
);
$router->get('/transactions/logsByTxId', fn() =>
    CmsBackendController::transactionLogs($namespace)
);
$router->post('/transactions/transfer', fn() =>
    CmsBackendController::createTransaction($namespace, 'transfer', $body)
);
$router->post('/transactions/onramp', fn() =>
    CmsBackendController::createTransaction($namespace, 'onramp', $body)
);
$router->post('/transactions/offramp', fn() =>
    CmsBackendController::createTransaction($namespace, 'offramp', $body)
);
$router->post('/transactions/payment', fn() =>
    CmsBackendController::createTransaction($namespace, 'payment', $body)
);
$router->put('/transactions/payment/cancel/{transactionId}', fn() =>
    CmsBackendController::emptyList()
);
$router->get('/transactions/fiat-accounts/id', fn() =>
    CmsBackendController::listFiatAccountsByCustomerId($namespace)
);
$router->post('/transactions/fiat-accounts/id', fn() =>
    CmsBackendController::createFiatAccount($namespace, $body, $body['data']['customerId'] ?? null)
);
$router->get('/transactions/fiat-accounts', fn() =>
    CmsBackendController::listFiatAccounts($namespace)
);
$router->post('/transactions/fiat-accounts', fn() =>
    CmsBackendController::createFiatAccount($namespace, $body)
);
$router->put('/transactions/fiat-accounts/default', fn() =>
    CmsBackendController::setDefaultFiatAccount($namespace, $body)
);
$router->put('/transactions/fiat-accounts/default/{customerId}', fn($p) =>
    CmsBackendController::setDefaultFiatAccount($namespace, $body, $p['customerId'])
);
$router->delete('/transactions/fiat-accounts/{fiatAccountId}', fn($p) =>
    CmsBackendController::deleteFiatAccount($namespace, $p['fiatAccountId'])
);
$router->delete('/transactions/fiat-accounts/{fiatAccountId}/{customerId}', fn($p) =>
    CmsBackendController::deleteFiatAccount($namespace, $p['fiatAccountId'], $p['customerId'])
);

$router->get('/liquidation-address', fn() =>
    CmsBackendController::listLiquidationAddresses($namespace)
);
$router->post('/liquidation-address', fn() =>
    CmsBackendController::createLiquidationAddress($namespace, $body)
);
$router->put('/liquidation-address/default', fn() =>
    CmsBackendController::setDefaultLiquidationAddress($namespace)
);
$router->delete('/liquidation-address', fn() =>
    CmsBackendController::deleteLiquidationAddress($namespace)
);

$router->get('/client', fn() =>
    CmsBackendController::clients($namespace)
);
$router->get('/roles/allowed', fn() =>
    CmsBackendController::rolesAllowed()
);
$router->get('/profiles', fn() =>
    CmsBackendController::profiles()
);
$router->get('/api-keys/list/dev', fn() =>
    CmsBackendController::apiKeys()
);
$router->get('/api-keys/list/prod', fn() =>
    CmsBackendController::apiKeys()
);
$router->post('/api-keys/create/dev', fn() =>
    CmsBackendController::apiKeys()
);
$router->post('/api-keys/create/prod', fn() =>
    CmsBackendController::apiKeys()
);

// ── KYB Approval (quick action via penny-api) ───────────────────────────────

$router->post('/control/approve-kyb/{businessId}', function ($p) {
    $businessId = $p['businessId'];
    $pennyApiUrl = 'http://penny-api:3003';

    $ch = curl_init("{$pennyApiUrl}/api/v1/third-party-service/business/{$businessId}");
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => json_encode(['KYB' => true]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  KYB APPROVED: Business #{$businessId}");
        error_log("║  Name: " . ($data['name'] ?? 'unknown'));
        error_log("╚══════════════════════════════════════════════════╝");
        JsonResponse::send([
            'approved'   => true,
            'businessId' => (int) $businessId,
            'name'       => $data['name'] ?? null,
            'KYB'        => true,
        ], 200);
    } else {
        JsonResponse::error("Failed to approve business #{$businessId}: HTTP {$httpCode}", 502);
    }
});

$router->get('/control/pending-kyb', function () {
    $pennyApiUrl = 'http://penny-api:3003';

    $ch = curl_init("{$pennyApiUrl}/api/v1/third-party-service/business");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $businesses = json_decode($response, true) ?? [];
    $pending = array_filter($businesses, fn($b) => !($b['KYB'] ?? false));
    $pending = array_values($pending);

    JsonResponse::send([
        'pending_count' => count($pending),
        'businesses'    => array_map(fn($b) => [
            'id'        => $b['id'] ?? null,
            'name'      => $b['name'] ?? null,
            'email'     => $b['email'] ?? null,
            'KYB'       => $b['KYB'] ?? false,
            'createdAt' => $b['createdAt'] ?? null,
        ], $pending),
    ], 200);
});

// ── Virtual Compliance Service (Aiprise replacement) ─────────────────────────

$router->get('/api/compliance/sessions', function () use ($namespace) {
    requireNamespace($namespace);
    ComplianceController::listSessions($namespace);
});

$router->post('/api/compliance/sessions', function () use ($namespace, $body) {
    requireNamespace($namespace);
    ComplianceController::createSession($body, $namespace);
});

$router->get('/api/compliance/sessions/{sessionRef}', function ($p) use ($namespace) {
    requireNamespace($namespace);
    ComplianceController::getSession($p['sessionRef'], $namespace);
});

$router->post('/api/compliance/sessions/{sessionRef}/submit', function ($p) use ($namespace, $body) {
    requireNamespace($namespace);
    ComplianceController::submitDocuments($p['sessionRef'], $body, $namespace);
});

$router->post('/api/compliance/sessions/{sessionRef}/transition', function ($p) use ($namespace, $body) {
    requireNamespace($namespace);
    ComplianceController::transitionSession($p['sessionRef'], $body, $namespace);
});

$router->post('/api/compliance/sessions/{sessionRef}/auto-transition', function ($p) use ($body) {
    ComplianceController::autoTransition($p['sessionRef'], $body);
});

$router->get('/api/compliance/sessions/{sessionRef}/url', function ($p) use ($namespace) {
    requireNamespace($namespace);
    ComplianceController::getVerificationUrl($p['sessionRef'], $namespace);
});

$router->get('/api/compliance/sessions/{sessionRef}/history', function ($p) use ($namespace) {
    requireNamespace($namespace);
    ComplianceController::getSessionHistory($p['sessionRef'], $namespace);
});

// ── AiPrise-Faithful API (drop-in replacement for real AiPrise) ──────────

// Priority 1: KYC Individual Verification
$router->post('/api/v1/verify/get_user_verification_url', function () use ($namespace, $body) {
    AipriseController::getUserVerificationUrl($body, $namespace);
});

$router->post('/api/v1/verify/run_user_verification', function () use ($namespace, $body) {
    AipriseController::runUserVerification($body, $namespace);
});

$router->get('/api/v1/verify/get_user_verification_result/{sessionId}', function ($p) use ($namespace) {
    AipriseController::getUserVerificationResult($p['sessionId'], $namespace);
});

// Internal self-callback for auto-completion (NOT part of real AiPrise API)
$router->post('/api/v1/verify/_internal/auto-complete/{sessionId}', function ($p) use ($body) {
    AipriseController::autoComplete($p['sessionId'], $body);
});

// Business KYB: Real implementation for URL-based flow
$router->post('/api/v1/verify/get_business_verification_url', function () use ($namespace, $body) {
    AipriseController::getBusinessVerificationUrl($body, $namespace);
});

$router->get('/api/v1/verify/get_business_verification_result/{sessionId}', function ($p) use ($namespace) {
    AipriseController::getBusinessVerificationResult($p['sessionId'], $namespace);
});

// Internal self-callback for KYB auto-completion
$router->post('/api/v1/verify/_internal/auto-complete-kyb/{sessionId}', function ($p) use ($body) {
    AipriseController::autoCompleteKyb($p['sessionId'], $body);
});

// Internal: update session data from verify page form (NOT part of real AiPrise API)
$router->post('/api/v1/verify/_internal/update-session/{sessionId}', function ($p) use ($body) {
    AipriseController::updateSession($p['sessionId'], $body);
});

// Business KYB: API-driven verification (submit flow)
$router->post('/api/v1/verify/run_business_verification', function () use ($namespace, $body) {
    AipriseController::runBusinessVerification($body, $namespace);
});

// Business KYB: API-driven stubs (lower priority)
$router->post('/api/v1/verify/create_business_profile', function () use ($body) {
    AipriseController::createBusinessProfile($body);
});

$router->post('/api/v1/verify/add_business_document', function () use ($body) {
    AipriseController::addBusinessDocument($body);
});

$router->post('/api/v1/verify/add_business_officer', function () use ($body) {
    AipriseController::addBusinessOfficer($body);
});

$router->post('/api/v1/verify/run_verification_for_business_officer', function () use ($body) {
    AipriseController::runVerificationForBusinessOfficer($body);
});

$router->post('/api/v1/verify/run_verification_for_business_profile_id', function () use ($body) {
    AipriseController::runVerificationForBusinessProfileId($body);
});

$router->get('/api/v1/verify/get_business_data_from_request/{verificationId}', function ($p) {
    AipriseController::getBusinessDataFromRequest($p['verificationId']);
});

$router->get('/api/v1/verify/get_business_profile/{verificationId}', function ($p) {
    AipriseController::getBusinessProfile($p['verificationId']);
});

// ── AiPrise Control Plane ───────────────────────────────────────────────

// KYC Control Plane
$router->post('/control/aiprise/{sessionId}/complete', function ($p) use ($body) {
    AipriseController::controlComplete($p['sessionId'], $body);
});

$router->get('/control/aiprise/sessions', function () use ($namespace) {
    AipriseController::listSessions($namespace);
});

// KYB Control Plane
$router->post('/control/aiprise-kyb/{sessionId}/complete', function ($p) use ($body) {
    AipriseController::controlCompleteKyb($p['sessionId'], $body);
});

$router->get('/control/aiprise-kyb/sessions', function () use ($namespace) {
    AipriseController::listKybSessions($namespace);
});

// ── Virtual Bridge API ───────────────────────────────────────────────────────

// AlfredPay wrapper surface (called by penny-api when usa-bridge-integration is not present)
$router->post('/v1/auth/token', function () use ($body) {
    BridgeController::authToken($body);
});

$router->post('/v1/bridge/terms-conditions', function () use ($body) {
    BridgeController::termsConditions($body);
});

// Real Bridge API surface (called by usa-bridge-integration)
$router->post('/customers/tos_links', function () use ($body) {
    BridgeController::customersTosLinks($body);
});

$router->post('/kyc_links', function () use ($body) {
    BridgeController::kycLinks($body);
});

// Browser-facing T&C acceptance page (loaded in iframe)
$router->get('/bridge/tos-page', function () {
    BridgeController::tosPage();
});

// ── Virtual Email Service ────────────────────────────────────────────────────

// OTP verification email (registration + login)
$router->post('/api/stub/email/email/verification-otp', function () use ($body) {
    EmailController::sendOtp($body);
});

// Direct-path aliases: cms-backend EmailService uses API_URL_EMAIL_SERVICE as the
// full base URL (no /api/stub/email prefix), so the actual HTTP calls land here.
$router->post('/email/verification-otp', function () use ($body) {
    EmailController::sendOtp($body);
});

// Password recovery email
$router->post('/api/stub/email/cms/reset-password', function () use ($body) {
    EmailController::resetPassword($body);
});

$router->post('/cms/reset-password', function () use ($body) {
    EmailController::resetPassword($body);
});

// Password confirmation email
$router->post('/api/stub/email/cms/confirm-password', function () use ($body) {
    EmailController::confirmPassword($body);
});

$router->post('/cms/confirm-password', function () use ($body) {
    EmailController::confirmPassword($body);
});

// ── Virtual Western Union Modernized API ─────────────────────────────────────
//
// Surface mirrors the real WU API as called by western-union-backend.
// The path prefix matches WU_BASE_URL=http://service-virtualization.
// Token endpoint matches the default WU_TOKEN_URL=/v1/token.

$router->post('/v1/token', function () {
    WesternUnionController::token();
});

// Config (stateless)
$router->get('/v1/pgw/config/origination-currencies', function () {
    WesternUnionController::originationCurrencies();
});
$router->get('/v1/pgw/config/entitled-destinations', function () {
    WesternUnionController::entitledDestinations();
});
$router->get('/v1/pgw/config/currency-info', function () {
    WesternUnionController::currencyInfo();
});
$router->get('/v1/pgw/config/payout-options', function () {
    WesternUnionController::payoutOptions();
});
$router->get('/v1/pgw/config/templates', function () {
    WesternUnionController::fieldTemplate();
});
$router->get('/v1/pgw/config/reasonlist', function () {
    WesternUnionController::reasonList();
});
$router->get('/v1/pgw/config/state-list', function () {
    WesternUnionController::stateList();
});
$router->get('/v1/pgw/config/error-translations', function () {
    WesternUnionController::errorTranslations();
});

// Orders (stateful)
$router->get('/v1/pgw/orders/fees', function () {
    WesternUnionController::feeSurvey();
});
$router->post('/v1/pgw/orders/quotes', function () use ($namespace, $body) {
    WesternUnionController::quote($namespace, $body);
});
$router->post('/v1/pgw/orders', function () use ($namespace, $body) {
    WesternUnionController::createOrder($namespace, $body);
});
$router->post('/v1/pgw/orders/confirm', function () use ($namespace, $body) {
    WesternUnionController::confirmOrder($namespace, $body);
});
$router->post('/v1/pgw/orders/cancel', function () use ($namespace, $body) {
    WesternUnionController::cancelOrder($namespace, $body);
});
$router->post('/v1/pgw/orders/inquiry', function () use ($namespace, $body) {
    WesternUnionController::inquiryOrder($namespace, $body);
});
$router->post('/v1/pgw/orders/release', function () {
    WesternUnionController::notImplementedNoOp('release');
});
$router->put('/v1/pgw/orders/resume', function () {
    WesternUnionController::notImplementedNoOp('resume');
});
$router->put('/v1/pgw/orders/suspend', function () {
    WesternUnionController::notImplementedNoOp('suspend');
});
$router->put('/v1/pgw/orders/modify', function () {
    WesternUnionController::notImplementedNoOp('modify');
});
$router->post('/v1/pgw/orders/receive', function () use ($namespace, $body) {
    WesternUnionController::receiveValidate($namespace, $body);
});
$router->post('/v1/pgw/orders/receive/confirm', function () use ($namespace, $body) {
    WesternUnionController::receiveConfirm($namespace, $body);
});

// ── Virtual WU Agent Locator API ─────────────────────────────────────────────

$router->get('/v1/agent/locations', function () {
    WesternUnionController::agentLocations();
});
$router->post('/v1/agent/locations', function () {
    WesternUnionController::agentLocations();
});
$router->get('/v1/agent/find', function () use ($body) {
    WesternUnionController::agentFindById([]);
});
$router->get('/v1/agent/find/s_phone/{phone}', function ($p) {
    WesternUnionController::agentFindByPhone($p);
});

// WU control plane
$router->get('/control/wu/orders', function () use ($namespace) {
    WesternUnionController::controlListOrders($namespace);
});
$router->post('/control/wu/orders/{mtcn}/force-state', function ($p) use ($namespace, $body) {
    WesternUnionController::controlForceState($namespace, $p['mtcn'], $body);
});

// ── Virtual Circle CPN API (called by cpn-ofi-api) ──────────────────────────
//
// Surface mirrors the real Circle Cross-Chain Payments Network OFI API:
// https://developers.circle.com/openapi/cpn-ofi.yaml
//
// cpn-ofi-api talks to Circle with CIRCLE_API_BASE=http://service-virtualization,
// so these routes must match Circle's real paths and response shapes exactly.
// Stage 1 implements quote endpoints only; payments, RFIs, refunds, and
// support tickets are deferred to Stage 2.

$router->post('/v1/cpn/quotes', function () use ($namespace, $body) {
    CircleController::createQuote($namespace, $body);
});
$router->get('/v1/cpn/quotes/{quoteId}', function ($p) use ($namespace) {
    CircleController::getQuote($namespace, $p['quoteId']);
});

// ── Virtual Mexican Payment Service (paymax account validation) ─────────────
// penny-api calls LOCALBALANCE_MEX_URL/paymax/account/bank/{clabe} to validate
// bank accounts when creating fiat_account records.

$router->post('/auth/token', function () use ($body) {
    if (isset($body['apiKey']) && isset($body['apiSecret'])) {
        JsonResponse::send(['accessToken' => 'vrt_mex_' . bin2hex(random_bytes(16))]);
    }
    JsonResponse::error('Missing apiKey or apiSecret', 401);
});

$router->get('/paymax/account/bank/{clabe}', function ($p) {
    $clabe = $p['clabe'];
    $bank = \App\Bankaool\BankaoolFixtures::lookupBank($clabe);
    // MexService.getBankNameByAccountNumber does `return response.data` after
    // HttpAdapter's interceptor already unwraps the Axios response, so the body
    // must contain a `data` envelope matching the real Paymax API shape.
    JsonResponse::send([
        'data' => [
            'codigo_banco' => $bank['banco'] ?? 'UNKNOWN',
            'banco'        => $bank['codigo_banco'] ?? '000',
        ],
    ]);
});

// ── Virtual Bankaool Banking API ─────────────────────────────────────────────
//
// Simulates Bankaool's banking API (OAuth2, SPEI transfers, accounts) and the
// Paymax/SRC microservice that AlfredPay uses for Mexican payment operations.
// Configure BANCA_URL=http://service-virtualization:8080/banks/bankaool
// Configure URL_MICROSERVICE_MEXICO=http://service-virtualization:8080/banks/bankaool

// Browser UI
$router->get('/banks/bankaool', fn() =>
    BankaoolController::dashboardPage($namespace)
);

// Bankaool Direct API: OAuth2
$router->post('/banks/bankaool/oauth2/token', fn() =>
    BankaoolController::oauthToken($body)
);

// Bankaool Direct API: Accounts
$router->get('/banks/bankaool/v1/cuenta', fn() =>
    BankaoolController::getAccounts($namespace)
);
$router->get('/banks/bankaool/v1/cuenta/{id}/medios-pago', fn($p) =>
    BankaoolController::getPaymentMethods($p['id'], $namespace)
);
$router->post('/banks/bankaool/v1/consulta-banco', fn() =>
    BankaoolController::lookupBank($body)
);

// Bankaool Direct API: Transfers
$router->post('/banks/bankaool/v1/token-otp', fn() =>
    BankaoolController::tokenOtp($namespace)
);
$router->post('/banks/bankaool/v1/transferir', fn() =>
    BankaoolController::transfer($body, $namespace)
);
$router->post('/banks/bankaool/v1/aprobar-transferencia', fn() =>
    BankaoolController::approveTransfer($body, $namespace)
);
$router->post('/banks/bankaool/v1/cobranza', fn() =>
    BankaoolController::collectionDeposit($body, $namespace)
);

// Paymax / SRC Microservice API
$router->post('/banks/bankaool/auth/token', fn() =>
    BankaoolController::authTokenSRC($body)
);
$router->post('/banks/bankaool/paymax/executen-payment', fn() =>
    BankaoolController::executePayment($body, $namespace)
);
$router->post('/banks/bankaool/paymax/customer-is-balance/create', fn() =>
    BankaoolController::createCustomerAccount($body, $namespace)
);
$router->get('/banks/bankaool/customer/customer-is-balance/account/{customer}', fn($p) =>
    BankaoolController::getCustomerAccount($p['customer'], $namespace)
);
$router->get('/banks/bankaool/customer/customer-is-balance/account-clabe/{clabe}', fn($p) =>
    BankaoolController::getCustomerByClabe($p['clabe'], $namespace)
);
$router->post('/banks/bankaool/paymax/customer-is-balance/accredit-payment', fn() =>
    BankaoolController::accreditPayment($body, $namespace)
);
$router->post('/banks/bankaool/paymax/customer-is-balance/debit-payment', fn() =>
    BankaoolController::debitPayment($body, $namespace)
);
$router->post('/banks/bankaool/paymax/generate/deposit', fn() =>
    BankaoolController::generateDeposit($body, $namespace)
);

// Bankaool utilities
$router->get('/banks/bankaool/banks', fn() =>
    BankaoolController::listBanks()
);
$router->post('/banks/bankaool/validate-clabe', fn() =>
    BankaoolController::validateClabe($body)
);

// Bankaool control plane
$router->get('/control/bankaool/transactions', fn() =>
    BankaoolController::controlListTransactions($namespace)
);
$router->post('/control/bankaool/deposit', fn() =>
    BankaoolController::controlDeposit($body, $namespace)
);

// ── Virtual Fireblocks API (https://api.fireblocks.io) ─────────────────────
//
// The fireblock container sets FIREBLOCKS_BASE_URL=http://service-virtualization
// and appends paths like /v1/transactions, /v1/vault/accounts_paged, etc.
// Auth headers (RS256 JWT + X-API-Key) are accepted but not validated.

$router->post('/v1/transactions', function () use ($body, $namespace) {
    FireblocksController::createTransaction($body, $namespace);
});

$router->get('/v1/vault/accounts_paged', function () {
    FireblocksController::listVaultAccounts();
});

$router->get('/v1/vault/accounts/{vaultAccountId}/{assetId}/addresses_paginated', function ($p) {
    FireblocksController::getVaultAddresses($p['vaultAccountId'], $p['assetId']);
});

// Transaction by ID must be registered BEFORE the parameterless list route
// because the router matches top-down and /v1/transactions/{txId} would
// otherwise never match if a bare /v1/transactions GET were registered first.
$router->get('/v1/transactions/{txId}', function ($p) use ($namespace) {
    FireblocksController::getTransaction($p['txId'], $namespace);
});

// Note: GET /v1/transactions (list) conflicts with the {txId} route above.
// The router tries patterns top-down, so {txId} will match first for paths
// like /v1/transactions/abc123. For bare /v1/transactions?query=... the
// {txId} route would match with txId="" which we handle gracefully.
// To avoid ambiguity, we don't register a separate GET /v1/transactions here.
// Instead, the {txId} handler checks for empty txId and delegates to list.

// Fireblocks control plane
$router->post('/control/fireblocks/deposit', function () use ($body) {
    FireblocksController::controlDeposit($body);
});

// ── Virtual Stellar Horizon ────────────────────────────────────────────────
//
// The fireblock service uses the Stellar JS SDK which POSTs to
// {horizonUrl}/transactions to submit signed XDR envelopes.

$router->post('/transactions', function () use ($body) {
    // Accept any XDR submission and return a successful Horizon response
    $hash = bin2hex(random_bytes(32));
    error_log("[Stellar Horizon] Virtual transaction submitted: {$hash}");
    JsonResponse::send([
        'hash'        => $hash,
        'ledger'      => random_int(50000000, 59999999),
        'envelope_xdr' => $body['tx'] ?? '',
        'result_xdr'  => 'AAAAAAAAAGQAAAAAAAAAAQAAAAAAAAABAAAAAAAAAAA=',
        'result_meta_xdr' => '',
        'successful'  => true,
    ]);
});

// ── Virtual KYT / AML Service ──────────────────────────────────────────────
//
// ramps-mexico calls KYT_URL for AML screening before processing transfers.
// This stub always approves. Accepts any path under /virtual/kyt/.

$router->post('/virtual/kyt/screen', function () use ($body) {
    error_log("[KYT] Virtual AML screen: " . json_encode($body));
    JsonResponse::send([
        'status'    => 'APPROVED',
        'riskScore' => 0,
        'alerts'    => [],
    ]);
});

$router->get('/virtual/kyt/status', function () {
    JsonResponse::send([
        'service' => 'Virtual KYT/AML',
        'status'  => 'healthy',
    ]);
});

// ── Virtual CoinMarketCap ──────────────────────────────────────────────────
//
// ramps-mexico calls COIN_MARKET_CAP_URL for price data.

$router->get('/virtual/coinmarketcap/v1/cryptocurrency/quotes/latest', function () {
    JsonResponse::send([
        'data' => [
            'USDC' => [
                'quote' => [
                    'MXN' => ['price' => 19.26, 'last_updated' => date('c')],
                    'USD' => ['price' => 1.00, 'last_updated' => date('c')],
                ],
            ],
        ],
    ]);
});

// ── Worldpay virtual ─────────────────────────────────────────────────────────
$router->post('/worldpay/api/payments', fn() => WorldpayController::createPayment($body));
$router->get('/worldpay/api/payments/{id}', fn($p) => WorldpayController::getPayment($p['id']));
$router->post('/worldpay/api/payments/{id}/3dsDeviceData', fn($p) => WorldpayController::supply3dsDeviceData($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/3dsChallenges', fn($p) => WorldpayController::complete3dsChallenge($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/settlements', fn($p) => WorldpayController::settle($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/partialSettlements', fn($p) => WorldpayController::partialSettle($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/refunds', fn($p) => WorldpayController::refund($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/partialRefunds', fn($p) => WorldpayController::partialRefund($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/cancellations', fn($p) => WorldpayController::cancel($p['id'], $body));
$router->post('/worldpay/api/payments/{id}/reversals', fn($p) => WorldpayController::reverse($p['id'], $body));
$router->post('/worldpay/verifiedTokens/oneTime', fn() => WorldpayController::createVerifiedTokenOneTime($body));
$router->post('/worldpay/verifiedTokens/cardOnFile', fn() => WorldpayController::createVerifiedTokenCardOnFile($body));

// ── Sift virtual ─────────────────────────────────────────────────────────────
$router->post('/sift/v205/events', fn() => SiftController::ingest($body));

// ── Virtual ExchangeCopter (https://api.exchangecopter.com) ────────────────
// Called outbound by microserivces-argentina-payex via env URL_COPTER.
// Configure URL_COPTER=http://service-virtualization/banks/exchangecopter

$router->get('/banks/exchangecopter', fn() =>
    ExchangeCopterController::dashboardPage($namespace ?? 'default')
);
$router->get('/banks/exchangecopter/login', fn() =>
    ExchangeCopterController::login($namespace ?? 'default')
);
$router->post('/banks/exchangecopter/creacionCVUConRegistroWallets', fn() =>
    ExchangeCopterController::creacionCvuConRegistroWallets($namespace ?? 'default', $body)
);
$router->put('/banks/exchangecopter/creacionAlias', fn() =>
    ExchangeCopterController::creacionAlias($namespace ?? 'default', $body)
);
$router->post('/banks/exchangecopter/devolucionCVUonPayexAlfred', fn() =>
    ExchangeCopterController::devolucionCvuOnPayexAlfred($namespace ?? 'default', $body)
);
$router->get('/banks/exchangecopter/checkCBUALIAS', fn() =>
    ExchangeCopterController::checkCbuAlias($_GET['aliasOcvu'] ?? '')
);
$router->post('/control/exchangecopter/scenario', fn() =>
    ExchangeCopterController::setScenario($namespace ?? 'default', $body)
);
// /UAT/ prefix aliases: microserivces-argentina-payex appends /UAT/ to some paths via URL_COPTER.
$router->post('/banks/exchangecopter/UAT/creacionCVUConRegistroWallets', fn() =>
    ExchangeCopterController::creacionCvuConRegistroWallets($namespace ?? 'default', $body)
);
$router->put('/banks/exchangecopter/UAT/creacionAlias', fn() =>
    ExchangeCopterController::creacionAlias($namespace ?? 'default', $body)
);
$router->get('/banks/exchangecopter/UAT/checkCBUALIAS', fn() =>
    ExchangeCopterController::checkCbuAlias($_GET['aliasOcvu'] ?? '')
);
$router->get('/banks/exchangecopter/UAT/balance', fn() =>
    ExchangeCopterController::balance($namespace ?? 'default', $_GET['idCvu'] ?? '')
);
// TotalPay / COELSA endpoints: hardcoded as api.exchangecopter.com in the TS source;
// local patch replaces them with COPTER_TOTALPAY_URL which points here.
$router->post('/banks/exchangecopter/createTransactionTotalPay', fn() =>
    ExchangeCopterController::createTransactionTotalPay($namespace ?? 'default', $body)
);
$router->get('/banks/exchangecopter/consultaCoelsaIdTotalPay', fn() =>
    ExchangeCopterController::consultaCoelsaIdTotalPay($namespace ?? 'default', $_GET['CoelsaId'] ?? '')
);

// ── Virtual Transfero (openbanking.bit.one) ────────────────────────────────
// Called outbound by rampas-brasil via env TRANSF_URL.
// TRANSF_URL=http://service-virtualization/virtual/transfero already set in local-services.json.

$router->get('/virtual/transfero', fn() =>
    TransferoController::dashboardPage($namespace ?? 'default')
);
$router->post('/virtual/transfero/auth/token', fn() =>
    TransferoController::authToken()
);
$router->post('/virtual/transfero/transferoAuth/send-payment', fn() =>
    TransferoController::transferoSendPayment($namespace ?? 'default', $body)
);
$router->get('/virtual/transfero/transferoAuth/get-payment/{id}', fn($p) =>
    TransferoController::transferoGetPayment($namespace ?? 'default', $p['id'])
);
$router->post('/virtual/transfero/copterpay/payout', fn() =>
    TransferoController::copterpayPayout($namespace ?? 'default', $body)
);
$router->get('/virtual/transfero/copterpay/get-status/{id}', fn($p) =>
    TransferoController::copterpayGetStatus($namespace ?? 'default', $p['id'])
);
$router->post('/control/transfero/set-status/{id}', fn($p) =>
    TransferoController::controlSetStatus($namespace ?? 'default', $p['id'], $body)
);

// ── Virtual Kambia / microservice-colombia ─────────────────────────────────
// rampas-colombia env URL_MICROSERVICE_COLOMBIA=http://service-virtualization/virtual/kambia,
// code appends /kambia/*, so routes land at /virtual/kambia/kambia/*.

$router->get('/virtual/kambia', fn() =>
    KambiaController::dashboardPage($namespace ?? 'default')
);
$router->get('/virtual/kambia/kambia/user/login', fn() =>
    KambiaController::userLogin()
);
$router->get('/virtual/kambia/kambia/account/details', fn() =>
    KambiaController::accountDetails()
);
$router->post('/virtual/kambia/kambia/transaction/arch', fn() =>
    KambiaController::createTransferArch($namespace ?? 'default', $body)
);
$router->get('/virtual/kambia/kambia/transaction/arch/details/{id}', fn($p) =>
    KambiaController::getTransferArchDetails($namespace ?? 'default', $p['id'])
);
$router->get('/virtual/kambia/kambia/list/bank', fn() =>
    KambiaController::listBanks()
);
$router->get('/virtual/kambia/kambia/list/typeDocument', fn() =>
    KambiaController::listDocumentTypes()
);
$router->get('/virtual/kambia/kambia/list/account', fn() =>
    KambiaController::listAccountTypes()
);
$router->post('/control/kambia/webhook/{transferId}', fn($p) =>
    KambiaController::controlFireWebhook($namespace ?? 'default', $p['transferId'], $body)
);

// ── Dispatch ─────────────────────────────────────────────────────────────────

$match = $router->dispatch($method, $routePath);

if ($match === null) {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    RequestLogger::log($namespace, $method, $routePath, null, $body, 404, ['error' => 'Not found'], $durationMs);
    JsonResponse::error("No route matches {$method} {$routePath}", 404);
}

try {
    ($match['handler'])($match['params']);
} catch (\Throwable $e) {
    $durationMs = (int)((microtime(true) - $startTime) * 1000);
    $errorPayload = [
        'error'   => true,
        'message' => $e->getMessage(),
        'trace'   => ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? $e->getTraceAsString() : null,
    ];
    RequestLogger::log($namespace, $method, $routePath, null, $body, 500, $errorPayload, $durationMs);
    JsonResponse::error($e->getMessage(), 500);
}
