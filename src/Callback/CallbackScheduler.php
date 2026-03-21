<?php

declare(strict_types=1);

namespace App\Callback;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Virtual Callback Orchestrator — the P0 shared platform capability.
 *
 * Schedules, manages, and fires callbacks (webhooks) to consuming services.
 * Supports: delayed delivery, retry with backoff, duplicate delivery, and per-namespace isolation.
 */
final class CallbackScheduler
{
    /**
     * Schedule a callback to be fired at a specific time.
     *
     * @return int The pending_callback ID
     */
    public static function schedule(
        string  $namespace,
        string  $targetUrl,
        array   $payload,
        ?int    $entityId = null,
        int     $delaySeconds = 0,
        string  $httpMethod = 'POST',
        array   $headers = [],
        int     $maxAttempts = 3,
    ): int {
        $pdo = Database::connect();
        $fireAt = (new DateTimeImmutable())
            ->modify("+{$delaySeconds} seconds")
            ->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO pending_callbacks (namespace, entity_id, target_url, http_method, headers, payload, fire_at, max_attempts)
            VALUES (:namespace, :entity_id, :target_url, :http_method, :headers, :payload, :fire_at, :max_attempts)
        ");
        $stmt->execute([
            'namespace'    => $namespace,
            'entity_id'    => $entityId,
            'target_url'   => $targetUrl,
            'http_method'  => $httpMethod,
            'headers'      => json_encode($headers),
            'payload'      => json_encode($payload),
            'fire_at'      => $fireAt,
            'max_attempts' => $maxAttempts,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Schedule a duplicate callback (for testing duplicate handling).
     */
    public static function scheduleDuplicate(
        string $namespace,
        string $targetUrl,
        array  $payload,
        ?int   $entityId = null,
        int    $delaySeconds = 0,
        int    $duplicateDelaySeconds = 5,
    ): array {
        $first = self::schedule($namespace, $targetUrl, $payload, $entityId, $delaySeconds);
        $second = self::schedule($namespace, $targetUrl, $payload, $entityId, $delaySeconds + $duplicateDelaySeconds);
        return [$first, $second];
    }

    /**
     * Get all pending callbacks for a namespace.
     */
    public static function getPending(string $namespace): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM pending_callbacks WHERE namespace = :ns AND status = 'pending' ORDER BY fire_at ASC");
        $stmt->execute(['ns' => $namespace]);
        return $stmt->fetchAll();
    }

    /**
     * Get callback history for a namespace.
     */
    public static function getHistory(string $namespace): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM callback_history WHERE namespace = :ns ORDER BY fired_at DESC LIMIT 100");
        $stmt->execute(['ns' => $namespace]);
        return $stmt->fetchAll();
    }

    /**
     * Cancel all pending callbacks for a namespace.
     */
    public static function cancelAll(string $namespace): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("UPDATE pending_callbacks SET status = 'cancelled' WHERE namespace = :ns AND status = 'pending'");
        $stmt->execute(['ns' => $namespace]);
        return $stmt->rowCount();
    }

    /**
     * Fire all due callbacks NOW (used by the /control/fire-callbacks endpoint
     * for instant test-driven triggering without waiting for cron).
     *
     * @return array Summary of fired callbacks
     */
    public static function fireNow(?string $namespace = null): array
    {
        $pdo = Database::connect();
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $sql = "SELECT * FROM pending_callbacks WHERE status = 'pending' AND fire_at <= :now";
        $params = ['now' => $now];

        if ($namespace !== null) {
            $sql .= " AND namespace = :ns";
            $params['ns'] = $namespace;
        }
        $sql .= " ORDER BY fire_at ASC LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $callbacks = $stmt->fetchAll();

        $results = [];
        foreach ($callbacks as $cb) {
            $results[] = self::fireSingle($pdo, $cb);
        }

        return $results;
    }

    /**
     * Force-fire ALL pending callbacks for a namespace, ignoring fire_at time.
     * This is the key enabler for test-driven instant callback delivery.
     */
    public static function forceFireAll(string $namespace): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("SELECT * FROM pending_callbacks WHERE namespace = :ns AND status = 'pending' ORDER BY fire_at ASC");
        $stmt->execute(['ns' => $namespace]);
        $callbacks = $stmt->fetchAll();

        $results = [];
        foreach ($callbacks as $cb) {
            $results[] = self::fireSingle($pdo, $cb);
        }

        return $results;
    }

    private static function fireSingle(\PDO $pdo, array $cb): array
    {
        $id = (int)$cb['id'];
        $attempt = (int)$cb['attempt_count'] + 1;
        $maxAttempts = (int)$cb['max_attempts'];
        $startTime = microtime(true);

        try {
            $ch = curl_init($cb['target_url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_CUSTOMREQUEST  => $cb['http_method'],
                CURLOPT_POSTFIELDS     => $cb['payload'],
                CURLOPT_HTTPHEADER     => array_merge(
                    ['Content-Type: application/json'],
                    json_decode($cb['headers'] ?? '[]', true) ?: []
                ),
            ]);

            $responseBody = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $durationMs = (int)((microtime(true) - $startTime) * 1000);
            $success = $httpCode >= 200 && $httpCode < 300;

            // Log history
            $pdo->prepare("
                INSERT INTO callback_history (callback_id, namespace, target_url, payload, response_status, response_body, duration_ms, success, error)
                VALUES (:cid, :ns, :url, :payload, :status, :body, :dur, :success, :error)
            ")->execute([
                'cid'     => $id,
                'ns'      => $cb['namespace'],
                'url'     => $cb['target_url'],
                'payload' => $cb['payload'],
                'status'  => $httpCode,
                'body'    => $responseBody ?: null,
                'dur'     => $durationMs,
                'success' => $success ? 1 : 0,
                'error'   => $error ?: null,
            ]);

            // Update status
            if ($success) {
                $pdo->prepare("UPDATE pending_callbacks SET status = 'fired', attempt_count = :a, last_attempt_at = NOW() WHERE id = :id")
                    ->execute(['a' => $attempt, 'id' => $id]);
            } else {
                $newStatus = $attempt >= $maxAttempts ? 'failed' : 'pending';
                $pdo->prepare("UPDATE pending_callbacks SET status = :s, attempt_count = :a, last_attempt_at = NOW(), last_error = :e WHERE id = :id")
                    ->execute(['s' => $newStatus, 'a' => $attempt, 'e' => "HTTP {$httpCode}", 'id' => $id]);
            }

            return [
                'callback_id' => $id,
                'target_url'  => $cb['target_url'],
                'http_code'   => $httpCode,
                'success'     => $success,
                'duration_ms' => $durationMs,
            ];
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE pending_callbacks SET attempt_count = :a, last_attempt_at = NOW(), last_error = :e WHERE id = :id")
                ->execute(['a' => $attempt, 'e' => $e->getMessage(), 'id' => $id]);

            return [
                'callback_id' => $id,
                'target_url'  => $cb['target_url'],
                'success'     => false,
                'error'       => $e->getMessage(),
            ];
        }
    }
}
