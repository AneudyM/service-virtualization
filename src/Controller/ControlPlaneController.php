<?php

declare(strict_types=1);

namespace App\Controller;

use App\Callback\CallbackScheduler;
use App\Compliance\ComplianceService;
use App\Core\Database;
use App\Core\JsonResponse;
use App\Entity\EntityManager;

/**
 * Control Plane — seed, reset, inspect, and fire callbacks.
 *
 * These endpoints are called by tests (not by AlfredPay services).
 * They provide the test ergonomics layer described in the architecture doc:
 *   - Seed: set up a scenario with entities in known states
 *   - Reset: tear down a namespace completely
 *   - Inspect: view entity state, history, and callback log
 *   - Fire: trigger pending callbacks immediately (no cron wait)
 */
final class ControlPlaneController
{
    /**
     * POST /control/scenarios
     * Seed a new test scenario.
     */
    public static function seedScenario(array $body): never
    {
        $namespace = $body['namespace'] ?? null;
        $domain    = $body['domain'] ?? 'compliance';
        $name      = $body['name'] ?? 'unnamed';
        $config    = $body['config'] ?? [];
        $seed      = $body['seed'] ?? [];

        if (!$namespace) {
            JsonResponse::error('namespace is required', 400);
        }

        $pdo = Database::connect();

        // Check if namespace already exists
        $existing = $pdo->prepare("SELECT id FROM scenarios WHERE namespace = :ns");
        $existing->execute(['ns' => $namespace]);
        if ($existing->fetch()) {
            JsonResponse::error("Namespace '{$namespace}' already exists. Reset it first or use a different namespace.", 409);
        }

        // Create scenario record
        $stmt = $pdo->prepare("
            INSERT INTO scenarios (namespace, domain, name, config, expires_at)
            VALUES (:ns, :domain, :name, :config, :expires)
        ");
        $expiresAt = (new \DateTimeImmutable())->modify('+2 hours')->format('Y-m-d H:i:s');
        $stmt->execute([
            'ns'      => $namespace,
            'domain'  => $domain,
            'name'    => $name,
            'config'  => json_encode(array_merge($config, ['seed' => $seed])),
            'expires' => $expiresAt,
        ]);

        $created = [];

        // Auto-seed compliance entities if requested
        if ($domain === 'compliance' && !empty($seed)) {
            foreach ($seed as $item) {
                $session = ComplianceService::createSession(
                    namespace: $namespace,
                    customerId: $item['customer_id'] ?? 'CUST-' . bin2hex(random_bytes(4)),
                    verificationType: $item['verification_type'] ?? 'kyc',
                    country: $item['country'] ?? 'MX',
                    customerData: $item['customer_data'] ?? [],
                    callbackUrl: $item['callback_url'] ?? null,
                );
                $created[] = $session;

                // If initial_state is specified, auto-transition
                $initialState = $item['initial_state'] ?? null;
                if ($initialState && $initialState !== 'draft') {
                    // First submit documents to get to PENDING
                    ComplianceService::submitDocuments(
                        namespace: $namespace,
                        sessionRef: $session['session_ref'],
                        documents: [['type' => 'id_front', 'filename' => 'virtual_doc.pdf']],
                    );
                    // Then transition to target if not PENDING
                    if ($initialState !== 'pending') {
                        ComplianceService::transitionSession(
                            namespace: $namespace,
                            sessionRef: $session['session_ref'],
                            targetState: $initialState,
                        );
                    }
                }
            }
        }

        JsonResponse::ok([
            'namespace'  => $namespace,
            'domain'     => $domain,
            'name'       => $name,
            'expires_at' => $expiresAt,
            'seeded'     => $created,
        ], 'Scenario seeded');
    }

    /**
     * DELETE /control/scenarios/{namespace}
     * Reset/teardown a namespace — delete all entities, callbacks, and history.
     */
    public static function resetScenario(string $namespace): never
    {
        $pdo = Database::connect();

        // Delete in dependency order
        $counts = [];

        $stmt = $pdo->prepare("DELETE FROM callback_history WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        $counts['callback_history'] = $stmt->rowCount();

        $stmt = $pdo->prepare("DELETE FROM pending_callbacks WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        $counts['pending_callbacks'] = $stmt->rowCount();

        $counts['entities'] = EntityManager::deleteByNamespace($namespace);

        $stmt = $pdo->prepare("DELETE FROM request_log WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        $counts['request_log'] = $stmt->rowCount();

        $stmt = $pdo->prepare("DELETE FROM scenarios WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        $counts['scenarios'] = $stmt->rowCount();

        JsonResponse::ok(['deleted' => $counts], "Namespace '{$namespace}' reset");
    }

    /**
     * GET /control/scenarios/{namespace}
     * Inspect a namespace — view all entities, their states, and pending callbacks.
     */
    public static function inspectScenario(string $namespace): never
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare("SELECT * FROM scenarios WHERE namespace = :ns");
        $stmt->execute(['ns' => $namespace]);
        $scenario = $stmt->fetch();

        if (!$scenario) {
            JsonResponse::error("Namespace '{$namespace}' not found", 404);
        }

        $scenario['config'] = json_decode($scenario['config'], true);

        $entities = EntityManager::findAllByNamespace($namespace);
        $pendingCallbacks = CallbackScheduler::getPending($namespace);
        $callbackHistory = CallbackScheduler::getHistory($namespace);

        // Add state history to each entity
        foreach ($entities as &$entity) {
            $entity['state_history'] = EntityManager::getHistory((int)$entity['id']);
        }

        JsonResponse::ok([
            'scenario'          => $scenario,
            'entities'          => $entities,
            'pending_callbacks' => $pendingCallbacks,
            'callback_history'  => $callbackHistory,
        ]);
    }

    /**
     * GET /control/scenarios
     * List all active scenarios.
     */
    public static function listScenarios(): never
    {
        $pdo = Database::connect();
        $rows = $pdo->query("SELECT namespace, domain, name, created_at, expires_at FROM scenarios ORDER BY created_at DESC LIMIT 50")->fetchAll();
        JsonResponse::ok($rows);
    }

    /**
     * POST /control/fire-callbacks
     * Fire all pending callbacks NOW (test-driven instant delivery).
     * Optionally filter by namespace.
     */
    public static function fireCallbacks(array $body): never
    {
        $namespace = $body['namespace'] ?? null;

        if ($namespace) {
            $results = CallbackScheduler::forceFireAll($namespace);
        } else {
            $results = CallbackScheduler::fireNow();
        }

        JsonResponse::ok([
            'fired'   => count($results),
            'results' => $results,
        ], 'Callbacks fired');
    }

    /**
     * GET /control/history/{namespace}
     * View request + callback history for a namespace.
     */
    public static function getHistory(string $namespace): never
    {
        $pdo = Database::connect();

        $reqStmt = $pdo->prepare("SELECT * FROM request_log WHERE namespace = :ns ORDER BY created_at DESC LIMIT 100");
        $reqStmt->execute(['ns' => $namespace]);
        $requests = $reqStmt->fetchAll();

        $callbackHistory = CallbackScheduler::getHistory($namespace);

        JsonResponse::ok([
            'requests'  => $requests,
            'callbacks' => $callbackHistory,
        ]);
    }

    /**
     * POST /control/cleanup-expired
     * Remove expired scenarios (housekeeping, can also be a cron job).
     */
    public static function cleanupExpired(): never
    {
        $pdo = Database::connect();
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("SELECT namespace FROM scenarios WHERE expires_at IS NOT NULL AND expires_at < :now");
        $stmt->execute(['now' => $now]);
        $expired = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $cleaned = [];
        foreach ($expired as $ns) {
            // Reuse the reset logic but capture results directly
            $pdo->prepare("DELETE FROM callback_history WHERE namespace = :ns")->execute(['ns' => $ns]);
            $pdo->prepare("DELETE FROM pending_callbacks WHERE namespace = :ns")->execute(['ns' => $ns]);
            EntityManager::deleteByNamespace($ns);
            $pdo->prepare("DELETE FROM request_log WHERE namespace = :ns")->execute(['ns' => $ns]);
            $pdo->prepare("DELETE FROM scenarios WHERE namespace = :ns")->execute(['ns' => $ns]);
            $cleaned[] = $ns;
        }

        JsonResponse::ok(['cleaned' => $cleaned], sprintf('Cleaned %d expired namespace(s)', count($cleaned)));
    }
}
