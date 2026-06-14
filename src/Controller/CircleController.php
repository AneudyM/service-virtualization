<?php

declare(strict_types=1);

namespace App\Controller;

use App\Circle\CircleService;
use App\Core\JsonResponse;

/**
 * Circle CPN API: quote flow.
 *
 * Default namespace: cpn-ofi-api does not pass X-Test-Namespace, so we fall
 * back to "circle-default" for the local single-tenant stack. E2E tests that
 * need isolation can override via the header.
 */
final class CircleController
{
    private const DEFAULT_NAMESPACE = 'circle-default';

    public static function createQuote(?string $namespace, array $body): never
    {
        $ns = $namespace ?: self::DEFAULT_NAMESPACE;

        try {
            $response = CircleService::createQuote($ns, $body);
            JsonResponse::send($response, 200);
        } catch (\Throwable $e) {
            JsonResponse::send([
                'code'    => 'VIRTUAL_CIRCLE_QUOTE_FAILED',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    public static function getQuote(?string $namespace, string $quoteId): never
    {
        $ns = $namespace ?: self::DEFAULT_NAMESPACE;

        $response = CircleService::getQuote($ns, $quoteId);
        if ($response === null) {
            JsonResponse::send([
                'code'    => 'RESOURCE_NOT_FOUND',
                'message' => "Quote {$quoteId} not found in namespace {$ns}",
            ], 404);
        }

        JsonResponse::send($response, 200);
    }
}
