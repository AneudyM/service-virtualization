<?php

declare(strict_types=1);

namespace App\Sift;

use App\Entity\EntityManager;

/**
 * Sift Events API stub.
 *
 * Real Sift contract:
 *   POST https://api.sift.com/v205/events
 *   POST https://api.sift.com/v205/events?return_score=true
 *
 * Response shape (success):
 *   {
 *     "status": 0,
 *     "error_message": "OK",
 *     "time": <unix>,
 *     "score_response"?: {
 *       "scores": {
 *         "payment_abuse": { "score": <0-1>, "reasons": [...] }
 *       }
 *     }
 *   }
 *
 * Score derivation (deterministic, no real ML):
 *   - default low_risk: 0.05
 *   - if $user_email or $user_id contains "block", "fraud", or "highrisk": 0.85
 *   - emits event row in entity store for inspection.
 */
final class SiftService
{
    public const ENTITY_EVENT = 'sift_event';

    public static function ingest(array $body, bool $returnScore): array
    {
        $type     = (string) ($body['$type'] ?? '');
        $userId   = (string) ($body['$user_id'] ?? '');
        $email    = (string) ($body['$user_email'] ?? '');
        $orderId  = (string) ($body['$order_id'] ?? '');
        $sessionId = (string) ($body['$session_id'] ?? '');

        // Persist event for audit
        $entityRef = $orderId ?: $userId . '-' . bin2hex(random_bytes(6));
        EntityManager::create('default', self::ENTITY_EVENT, $entityRef, $type, [
            'type'       => $type,
            'user_id'    => $userId,
            'email'      => $email,
            'order_id'   => $orderId,
            'session_id' => $sessionId,
            'payload'    => $body,
            'created_at' => date('c'),
        ]);

        $response = [
            'status'        => 0,
            'error_message' => 'OK',
            'time'          => time(),
            'request'       => json_encode($body, JSON_UNESCAPED_SLASHES),
        ];

        if ($returnScore && $type === '$create_order') {
            $score = self::deriveScore($email, $userId);
            $response['score_response'] = [
                'scores' => [
                    'payment_abuse' => [
                        'score'   => $score,
                        'reasons' => $score > 0.5
                            ? [['name' => 'velocity_signal'], ['name' => 'email_reputation']]
                            : [['name' => 'low_velocity']],
                    ],
                ],
            ];
        }

        return [
            'status' => 200,
            'body'   => $response,
        ];
    }

    private static function deriveScore(string $email, string $userId): float
    {
        $needles = ['block', 'fraud', 'highrisk', 'high-risk'];
        $haystack = strtolower($email . '|' . $userId);
        foreach ($needles as $n) {
            if (str_contains($haystack, $n)) {
                return 0.85;
            }
        }
        return 0.05;
    }
}
