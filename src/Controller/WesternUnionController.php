<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\WesternUnion\WesternUnionService;
use App\WesternUnion\WuFixtures;

/**
 * Western Union Modernized API + Agent Locator.
 *
 * Implements the WU API surface consumed by western-union-backend
 * (see ExperimentRepos/alfred-payments/western-union-backend/src/modules/western-union/client/services/*).
 *
 * Default namespace: when no X-Test-Namespace header is provided we fall back
 * to "wu-default" so the local stack can drive the flow without per-test
 * isolation. E2E tests that need isolation should set X-Test-Namespace.
 */
final class WesternUnionController
{
    private const DEFAULT_NAMESPACE = 'wu-default';

    public static function token(): never
    {
        JsonResponse::send(WesternUnionService::issueToken(), 200);
    }

    public static function originationCurrencies(): never
    {
        JsonResponse::send(WuFixtures::originationCurrencies(), 200);
    }

    public static function entitledDestinations(): never
    {
        JsonResponse::send(WuFixtures::entitledDestinations(), 200);
    }

    public static function currencyInfo(): never
    {
        $currency = $_GET['currency'] ?? null;
        JsonResponse::send(WuFixtures::currencyInfo($currency), 200);
    }

    public static function payoutOptions(): never
    {
        JsonResponse::send(WuFixtures::payoutOptions(), 200);
    }

    public static function stateList(): never
    {
        $country = (string) ($_GET['country'] ?? 'US');
        JsonResponse::send(WuFixtures::stateList($country), 200);
    }

    public static function reasonList(): never
    {
        JsonResponse::send(WuFixtures::reasonList(), 200);
    }

    public static function errorTranslations(): never
    {
        JsonResponse::send(WuFixtures::errorTranslations(), 200);
    }

    public static function fieldTemplate(): never
    {
        $country = (string) ($_GET['receiverCountry'] ?? $_GET['country'] ?? 'US');
        $method  = (string) ($_GET['payoutMethod'] ?? 'MONEY IN MINUTES');
        JsonResponse::send(WuFixtures::fieldTemplate($country, $method), 200);
    }

    public static function feeSurvey(): never
    {
        $country  = (string) ($_GET['receiverCountry'] ?? 'MX');
        $corridor = WuFixtures::corridor($country);
        if ($corridor === null) {
            JsonResponse::send([
                'name'      => 'ERROR',
                'errorCode' => 'R4001',
                'message'   => "Corridor not supported: {$country}",
            ], 400);
        }

        JsonResponse::send([
            'moreData'          => 'N',
            'numOfRecords'      => 1,
            'totalNumOfRecords' => 1,
            'fees'              => [[
                'receiverCountry' => $country,
                'totalFee'        => $corridor['fee'],
                'currency'        => 'USD',
            ]],
        ], 200);
    }

    public static function quote(?string $namespace, array $body): never
    {
        $result = WesternUnionService::createQuote($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function createOrder(?string $namespace, array $body): never
    {
        $result = WesternUnionService::createOrder($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function confirmOrder(?string $namespace, array $body): never
    {
        $result = WesternUnionService::confirmOrder($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function cancelOrder(?string $namespace, array $body): never
    {
        $result = WesternUnionService::cancelOrder($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function inquiryOrder(?string $namespace, array $body): never
    {
        $result = WesternUnionService::inquiry($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function receiveValidate(?string $namespace, array $body): never
    {
        $result = WesternUnionService::receiveValidate($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    public static function receiveConfirm(?string $namespace, array $body): never
    {
        $result = WesternUnionService::receiveConfirm($namespace ?? self::DEFAULT_NAMESPACE, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * Stub for /v1/pgw/orders/release|resume|suspend|modify endpoints.
     * Returns a generic success. State transitions not modeled.
     */
    public static function notImplementedNoOp(string $action): never
    {
        JsonResponse::send([
            'statusCode' => '0000',
            'status'     => 'OK',
            'message'    => "{$action} accepted (no-op)",
            'dateTime'   => date('Y-m-d\TH:i:s\Z'),
        ], 200);
    }

    public static function agentLocations(): never
    {
        $country = (string) ($_GET['country'] ?? 'US');
        JsonResponse::send(WuFixtures::agentLocations($country), 200);
    }

    public static function agentFindById(array $params): never
    {
        $agentId = $params['id'] ?? ($_GET['id'] ?? 'WU-US-001');
        JsonResponse::send([
            'agent' => [
                'agentId' => $agentId,
                'name'    => "Western Union {$agentId}",
                'status'  => 'ACTIVE',
                'address' => '123 Main Street',
                'country' => 'US',
                'phone'   => '+1-555-0001',
            ],
        ], 200);
    }

    public static function agentFindByPhone(array $params): never
    {
        $phone = $params['phone'] ?? '0000000000';
        JsonResponse::send([
            'agents' => [[
                'agentId' => 'WU-PHONE-001',
                'name'    => 'Western Union Phone Lookup Result',
                'phone'   => $phone,
                'country' => 'US',
            ]],
        ], 200);
    }

    public static function controlListOrders(?string $namespace): never
    {
        $ns = $namespace ?? self::DEFAULT_NAMESPACE;
        JsonResponse::send([
            'namespace' => $ns,
            'orders'    => WesternUnionService::listOrders($ns),
        ], 200);
    }

    public static function controlForceState(?string $namespace, string $mtcn, array $body): never
    {
        $newState = (string) ($body['state'] ?? '');
        if ($newState === '') {
            JsonResponse::error('Missing "state" in body', 400);
        }

        $entity = WesternUnionService::forceState($namespace ?? self::DEFAULT_NAMESPACE, $mtcn, $newState);
        if ($entity === null) {
            JsonResponse::error("Order not found for MTCN {$mtcn}", 404);
        }

        JsonResponse::send([
            'mtcn'  => $mtcn,
            'state' => $entity['state'],
        ], 200);
    }
}
