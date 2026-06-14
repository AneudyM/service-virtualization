<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Sift\SiftService;

/**
 * Sift Events API virtual controller.
 *
 * Mounted at:
 *   POST /sift/v205/events          : ingest event, no score
 *   POST /sift/v205/events?return_score=true: ingest + return synthetic score
 */
final class SiftController
{
    public static function ingest(array $body): never
    {
        $returnScore = isset($_GET['return_score'])
            && filter_var($_GET['return_score'], FILTER_VALIDATE_BOOLEAN);

        $result = SiftService::ingest($body, $returnScore);
        JsonResponse::send($result['body'], $result['status']);
    }
}
