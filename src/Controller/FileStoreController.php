<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\RequestLogger;

/**
 * S3 File Store: S3-compatible file upload/download.
 *
 * The AWS SDK v2 sends PUT /{bucket}/{key} for putObject operations.
 * penny-api's fetchAndEncode() uses axios.get(url) to download files.
 *
 * Files are stored on the container filesystem at /tmp/virtual-files/{bucket}/{key}.
 * This is ephemeral storage: files survive container restarts but not rebuilds.
 *
 * Routes (handled pre-routing in index.php via bucket prefix matching):
 *   PUT  /{bucket}/{key...} : store file, return 200 + ETag header
 *   GET  /{bucket}/{key...} : serve file with correct Content-Type
 *   HEAD /{bucket}/{key...} : return file metadata (size, type, ETag)
 */
final class FileStoreController
{
    private const STORAGE_DIR = '/tmp/virtual-files';

    /**
     * Handle S3 putObject: store raw binary body to disk.
     *
     * AWS SDK expects: HTTP 200 with ETag header, empty body.
     * The SDK constructs the Location URL from endpoint + bucket + key.
     */
    public static function upload(string $bucket, string $key, string $rawBody): never
    {
        $filePath = self::STORAGE_DIR . '/' . $bucket . '/' . $key;
        $dir = dirname($filePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $rawBody);

        $etag = md5($rawBody);
        $size = strlen($rawBody);

        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  VIRTUAL S3: File Uploaded                      ║");
        error_log("║  Bucket: {$bucket}");
        error_log("║  Key:    {$key}");
        error_log("║  Size:   {$size} bytes");
        error_log("╚══════════════════════════════════════════════════╝");

        $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        RequestLogger::log(
            null,
            'PUT',
            "/{$bucket}/{$key}",
            null,
            ['size' => $size, 'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'unknown'],
            200,
            ['stored' => true, 'etag' => $etag, 'size' => $size],
            $durationMs
        );

        // S3 putObject response: 200 with ETag header, empty body
        header('ETag: "' . $etag . '"');
        header('Content-Length: 0');
        http_response_code(200);
        exit;
    }

    /**
     * Handle file download: serve stored file with correct Content-Type.
     *
     * penny-api's fetchAndEncode() calls axios.get(url, { responseType: 'arraybuffer' })
     * and base64-encodes the response for AiPrise submission.
     */
    public static function download(string $bucket, string $key): never
    {
        $filePath = self::STORAGE_DIR . '/' . $bucket . '/' . $key;

        if (!file_exists($filePath)) {
            error_log("VIRTUAL S3: 404: {$bucket}/{$key} not found");

            $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
            $durationMs = (int)((microtime(true) - $startTime) * 1000);

            RequestLogger::log(
                null,
                'GET',
                "/{$bucket}/{$key}",
                null,
                [],
                404,
                ['error' => 'NoSuchKey'],
                $durationMs
            );

            // S3-compatible XML error response
            header('Content-Type: application/xml');
            http_response_code(404);
            echo '<?xml version="1.0" encoding="UTF-8"?>'
                . '<Error>'
                . '<Code>NoSuchKey</Code>'
                . '<Message>The specified key does not exist.</Message>'
                . '<Key>' . htmlspecialchars($key, ENT_XML1) . '</Key>'
                . '<BucketName>' . htmlspecialchars($bucket, ENT_XML1) . '</BucketName>'
                . '</Error>';
            exit;
        }

        $contentType = self::guessContentType($key);
        $fileSize = filesize($filePath);
        $etag = md5_file($filePath);

        $startTime = defined('REQUEST_START_TIME') ? REQUEST_START_TIME : microtime(true);
        $durationMs = (int)((microtime(true) - $startTime) * 1000);

        RequestLogger::log(
            null,
            'GET',
            "/{$bucket}/{$key}",
            null,
            [],
            200,
            ['served' => true, 'size' => $fileSize, 'content_type' => $contentType],
            $durationMs
        );

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $fileSize);
        header('ETag: "' . $etag . '"');
        header('Accept-Ranges: bytes');
        http_response_code(200);
        readfile($filePath);
        exit;
    }

    /**
     * Handle HEAD request: return metadata without body.
     */
    public static function head(string $bucket, string $key): never
    {
        $filePath = self::STORAGE_DIR . '/' . $bucket . '/' . $key;

        if (!file_exists($filePath)) {
            http_response_code(404);
            exit;
        }

        header('Content-Type: ' . self::guessContentType($key));
        header('Content-Length: ' . filesize($filePath));
        header('ETag: "' . md5_file($filePath) . '"');
        http_response_code(200);
        exit;
    }

    private static function guessContentType(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'pdf' => 'application/pdf',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }
}
