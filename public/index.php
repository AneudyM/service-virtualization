<?php
/**
 * AlfredPay Service Virtualization Platform — Front Controller
 *
 * All requests are routed through this file via .htaccess rewrite.
 * Lightweight: no full Symfony kernel — just Router + Controllers + PDO.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controller\AipriseController;
use App\Controller\ComplianceController;
use App\Controller\ControlPlaneController;
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
    $body = json_decode($rawBody, true) ?? [];
}

// Namespace resolution: header > query param > body
$namespace = $_SERVER['HTTP_X_TEST_NAMESPACE']
    ?? $_GET['namespace']
    ?? $body['namespace']
    ?? null;

// ── CORS (for browser-based tools/dashboards) ───────────────────────────────

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Test-Namespace, X-API-KEY');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
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
