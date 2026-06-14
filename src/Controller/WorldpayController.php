<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Worldpay\WorldpayService;

/**
 * Worldpay Access virtual controller.
 *
 * Mounted at (mirrors Worldpay Payments API @20240601):
 *   POST /worldpay/api/payments
 *   GET  /worldpay/api/payments/{id}
 *   POST /worldpay/api/payments/{id}/3dsDeviceData
 *   POST /worldpay/api/payments/{id}/3dsChallenges
 *   POST /worldpay/api/payments/{id}/settlements
 *   POST /worldpay/api/payments/{id}/partialSettlements
 *   POST /worldpay/api/payments/{id}/refunds
 *   POST /worldpay/api/payments/{id}/partialRefunds
 *   POST /worldpay/api/payments/{id}/cancellations
 *   POST /worldpay/api/payments/{id}/reversals
 *   POST /worldpay/verifiedTokens/oneTime
 *   POST /worldpay/verifiedTokens/cardOnFile
 */
final class WorldpayController
{
    public static function createPayment(array $body): never
    {
        $result = WorldpayService::createPayment($body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function getPayment(string $id): never
    {
        $result = WorldpayService::getPayment($id);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function supply3dsDeviceData(string $id, array $body): never
    {
        $result = WorldpayService::supply3dsDeviceData($id, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function complete3dsChallenge(string $id, array $body): never
    {
        $result = WorldpayService::complete3dsChallenge($id, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function settle(string $id, array $body): never
    {
        $result = WorldpayService::settle($id, $body, false);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function partialSettle(string $id, array $body): never
    {
        $result = WorldpayService::settle($id, $body, true);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function refund(string $id, array $body): never
    {
        $result = WorldpayService::refund($id, $body, false);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function partialRefund(string $id, array $body): never
    {
        $result = WorldpayService::refund($id, $body, true);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function cancel(string $id, array $body): never
    {
        $result = WorldpayService::cancel($id, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function reverse(string $id, array $body): never
    {
        $result = WorldpayService::reverse($id, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function createVerifiedTokenOneTime(array $body): never
    {
        $result = WorldpayService::createVerifiedToken($body, 'oneTime');
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function createVerifiedTokenCardOnFile(array $body): never
    {
        $result = WorldpayService::createVerifiedToken($body, 'cardOnFile');
        JsonResponse::send($result['body'], $result['status']);
    }
}
