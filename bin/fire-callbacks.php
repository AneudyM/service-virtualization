#!/usr/bin/env php
<?php
/**
 * Callback firing script — run via cron or manually.
 *
 * Cron entry (every 5 minutes):
 *   */5 * * * * cd /home/in/intelycs.com/service-virtualization; /usr/bin/php bin/fire-callbacks.php
 *
 * Or trigger via HTTP:
 *   POST /control/fire-callbacks
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

$dotenv = new Dotenv();
$dotenv->loadEnv(__DIR__ . '/../.env');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $_ENV['DB_HOST'], $_ENV['DB_PORT'], $_ENV['DB_NAME']),
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

// Fetch all pending callbacks that are due
$stmt = $pdo->prepare("
    SELECT * FROM pending_callbacks
    WHERE status = 'pending' AND fire_at <= :now
    ORDER BY fire_at ASC
    LIMIT 50
");
$stmt->execute(['now' => $now]);
$callbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($callbacks)) {
    echo "No pending callbacks to fire.\n";
    exit(0);
}

echo sprintf("Firing %d callback(s)...\n", count($callbacks));

foreach ($callbacks as $cb) {
    $id = (int)$cb['id'];
    $attempt = (int)$cb['attempt_count'] + 1;
    $maxAttempts = (int)$cb['max_attempts'];
    $startTime = microtime(true);

    echo sprintf("  [%d] %s %s (attempt %d/%d)\n", $id, $cb['http_method'], $cb['target_url'], $attempt, $maxAttempts);

    try {
        $ch = curl_init($cb['target_url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST => $cb['http_method'],
            CURLOPT_POSTFIELDS => $cb['payload'],
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json'],
                json_decode($cb['headers'] ?? '[]', true) ?: []
            ),
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $success = $httpCode >= 200 && $httpCode < 300;

        // Log to callback_history
        $logStmt = $pdo->prepare("
            INSERT INTO callback_history (callback_id, namespace, target_url, payload, response_status, response_body, duration_ms, success, error)
            VALUES (:callback_id, :namespace, :target_url, :payload, :response_status, :response_body, :duration_ms, :success, :error)
        ");
        $logStmt->execute([
            'callback_id' => $id,
            'namespace' => $cb['namespace'],
            'target_url' => $cb['target_url'],
            'payload' => $cb['payload'],
            'response_status' => $httpCode,
            'response_body' => $responseBody ?: null,
            'duration_ms' => $durationMs,
            'success' => $success ? 1 : 0,
            'error' => $error ?: null,
        ]);

        if ($success) {
            // Mark as fired
            $pdo->prepare("UPDATE pending_callbacks SET status = 'fired', attempt_count = :attempt, last_attempt_at = NOW() WHERE id = :id")
                ->execute(['attempt' => $attempt, 'id' => $id]);
            echo sprintf("    -> %d OK (%d ms)\n", $httpCode, $durationMs);
        } else {
            // Retry or fail
            $newStatus = $attempt >= $maxAttempts ? 'failed' : 'pending';
            $nextFireAt = $attempt >= $maxAttempts
                ? null
                : (new DateTimeImmutable())->modify(sprintf('+%d seconds', min(60 * $attempt, 300)))->format('Y-m-d H:i:s');

            $updateSql = $nextFireAt
                ? "UPDATE pending_callbacks SET status = :status, attempt_count = :attempt, last_attempt_at = NOW(), last_error = :error, fire_at = :fire_at WHERE id = :id"
                : "UPDATE pending_callbacks SET status = :status, attempt_count = :attempt, last_attempt_at = NOW(), last_error = :error WHERE id = :id";

            $params = [
                'status' => $newStatus,
                'attempt' => $attempt,
                'error' => "HTTP {$httpCode}: " . substr($responseBody ?: $error, 0, 500),
                'id' => $id,
            ];
            if ($nextFireAt) {
                $params['fire_at'] = $nextFireAt;
            }

            $pdo->prepare($updateSql)->execute($params);
            echo sprintf("    -> %d FAILED (%s)\n", $httpCode, $newStatus === 'failed' ? 'max attempts reached' : 'will retry');
        }
    } catch (\Throwable $e) {
        $durationMs = (int)((microtime(true) - $startTime) * 1000);
        $newStatus = $attempt >= $maxAttempts ? 'failed' : 'pending';

        $pdo->prepare("UPDATE pending_callbacks SET status = :status, attempt_count = :attempt, last_attempt_at = NOW(), last_error = :error WHERE id = :id")
            ->execute([
                'status' => $newStatus,
                'attempt' => $attempt,
                'error' => substr($e->getMessage(), 0, 500),
                'id' => $id,
            ]);

        echo sprintf("    -> EXCEPTION: %s\n", $e->getMessage());
    }
}

echo "Done.\n";
