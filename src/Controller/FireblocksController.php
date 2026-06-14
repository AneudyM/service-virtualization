<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\JsonResponse;
use App\Fireblocks\FireblocksService;

/**
 * Fireblocks Controller: API endpoints.
 *
 * The fireblock container (NestJS) sends requests here instead of https://api.fireblocks.io.
 * Auth headers (JWT + X-API-Key) are accepted but not validated.
 *
 * Routes are prefixed with /v1/fireblocks/ in index.php to avoid collisions.
 * The fireblock service's FIREBLOCKS_BASE_URL should point to
 * http://service-virtualization/v1/fireblocks so that its paths like
 * /v1/transactions resolve to /v1/fireblocks/v1/transactions.
 *
 * Wait: the fireblock service appends its own paths to baseURL:
 *   baseURL + '/v1/transactions' = 'http://service-virtualization/v1/transactions'
 * So the routes should be at the ROOT /v1/ level, not prefixed.
 */
final class FireblocksController
{
    /**
     * POST /v1/transactions
     */
    public static function createTransaction(array $body, ?string $namespace): never
    {
        $result = FireblocksService::createTransaction($namespace, $body);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /v1/vault/accounts_paged
     */
    public static function listVaultAccounts(): never
    {
        $result = FireblocksService::listVaultAccounts();
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /v1/vault/accounts/:vaultAccountId/:assetId/addresses_paginated
     */
    public static function getVaultAddresses(string $vaultId, string $assetId): never
    {
        $result = FireblocksService::getVaultAddresses($vaultId, $assetId);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /v1/transactions/:txId
     * Also handles GET /v1/transactions (list) when txId is empty/query-only.
     */
    public static function getTransaction(string $txId, ?string $namespace): never
    {
        // If txId is empty or looks like a query param artifact, treat as list
        if ($txId === '' || str_starts_with($txId, '?')) {
            self::listTransactions($namespace);
        }
        $result = FireblocksService::getTransaction($namespace, $txId);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * GET /v1/transactions (list with filters)
     */
    public static function listTransactions(?string $namespace): never
    {
        $filters = [
            'txHash'   => $_GET['txHash'] ?? null,
            'assets'   => $_GET['assets'] ?? null,
            'limit'    => $_GET['limit'] ?? null,
            'status'   => $_GET['status'] ?? null,
            'after'    => $_GET['after'] ?? null,
            'before'   => $_GET['before'] ?? null,
            'sourceId' => $_GET['sourceId'] ?? null,
        ];
        $result = FireblocksService::listTransactions($namespace, $filters);
        JsonResponse::send($result['body'], $result['status']);
    }

    /**
     * POST /control/fireblocks/deposit
     * Simulate a crypto deposit arriving at a vault.
     */
    public static function controlDeposit(array $body): never
    {
        $result = FireblocksService::controlDeposit($body);
        JsonResponse::send($result['body'], $result['status']);
    }
}
