<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Simple JSON response helper.
 */
final class JsonResponse
{
    public static function send(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        foreach ($headers as $key => $value) {
            header("{$key}: {$value}");
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    public static function error(string $message, int $status = 400, array $details = []): never
    {
        self::send([
            'error'   => true,
            'message' => $message,
            'details' => $details ?: null,
        ], $status);
    }

    public static function ok(mixed $data = null, string $message = 'ok'): never
    {
        self::send([
            'error'   => false,
            'message' => $message,
            'data'    => $data,
        ]);
    }
}
