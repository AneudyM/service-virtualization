<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Logs every inbound API request to the request_log table.
 */
final class RequestLogger
{
    public static function log(
        ?string $namespace,
        string  $method,
        string  $path,
        ?array  $headers,
        mixed   $body,
        int     $responseStatus,
        mixed   $responseBody,
        int     $durationMs,
    ): void {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            INSERT INTO request_log (namespace, method, path, headers, body, response_status, response_body, duration_ms)
            VALUES (:namespace, :method, :path, :headers, :body, :response_status, :response_body, :duration_ms)
        ");
        $stmt->execute([
            'namespace'       => $namespace,
            'method'          => $method,
            'path'            => $path,
            'headers'         => $headers ? json_encode($headers) : null,
            'body'            => is_array($body) ? json_encode($body) : $body,
            'response_status' => $responseStatus,
            'response_body'   => is_array($responseBody) ? json_encode($responseBody) : $responseBody,
            'duration_ms'     => $durationMs,
        ]);
    }
}
