<?php

declare(strict_types=1);

namespace App\Fireblocks;

use App\Entity\EntityManager;

/**
 * Fireblocks REST API (https://api.fireblocks.io).
 *
 * Called by the fireblock container (NestJS) which forwards requests from
 * penny-api and rampas-penny-api for custody operations.
 *
 * Entity types stored via EntityManager:
 *   - fb_transaction:  Fireblocks transactions (transfers, signings, contract calls)
 *   - fb_vault:        Vault accounts with asset addresses
 */
final class FireblocksService
{
    public const ENTITY_TRANSACTION = 'fb_transaction';
    public const ENTITY_VAULT       = 'fb_vault';

    // Known vault account IDs (from fireblock.service.ts line 52)
    private const VAULT_ACCOUNTS = [
        '17' => ['name' => 'AlfredPay Main',     'hiddenOnUI' => false, 'autoFuel' => false],
        '19' => ['name' => 'AlfredPay Stellar',  'hiddenOnUI' => false, 'autoFuel' => false],
        '20' => ['name' => 'AlfredPay Payments', 'hiddenOnUI' => false, 'autoFuel' => false],
    ];

    // Addresses per vault + asset
    private const VAULT_ADDRESSES = [
        '17' => [
            'XLM_TEST' => ['address' => 'GDUMTTU2R4HDAPZGTFYOMXGZRAQLPPHCFQ2NBXHYZVILLN3XDQAFOOEF', 'tag' => '272821812'],
            'USDC'     => ['address' => '0xbc6ecc8c9c218850dc99ae11d2d780a0bb76d7ec', 'tag' => ''],
            'ETH_TEST6'=> ['address' => '0xbc6ecc8c9c218850dc99ae11d2d780a0bb76d7ec', 'tag' => ''],
        ],
        '19' => [
            'XLM_TEST' => ['address' => 'GBVIRTUAL19STELLARADDR000000000000000000000000000', 'tag' => '190001'],
            'USDC'     => ['address' => '0x1900000000000000000000000000000000000019', 'tag' => ''],
        ],
        '20' => [
            'XLM_TEST' => ['address' => 'GBVIRTUAL20STELLARADDR000000000000000000000000000', 'tag' => '200001'],
            'USDC'     => ['address' => '0x2000000000000000000000000000000000000020', 'tag' => ''],
        ],
    ];

    /**
     * POST /v1/transactions
     *
     * Supports operations: TRANSFER, TYPED_MESSAGE, CONTRACT_CALL.
     * Returns { id, status }.
     * The fireblock service saves response.data.id and response.data.status.
     */
    public static function createTransaction(?string $namespace, array $body): array
    {
        $ns = $namespace ?? 'default';
        $operation = $body['operation'] ?? 'TRANSFER';
        $txId = 'vrt_fb_' . bin2hex(random_bytes(16));

        $status = match ($operation) {
            'TRANSFER'       => 'SUBMITTED',
            'TYPED_MESSAGE'  => 'COMPLETED',
            'CONTRACT_CALL'  => 'SUBMITTED',
            default          => 'SUBMITTED',
        };

        $source = $body['source'] ?? [];
        $destination = $body['destination'] ?? [];
        $amount = $body['amount'] ?? '0';
        $assetId = $body['assetId'] ?? '';

        $destAddress = '';
        $destType = $destination['type'] ?? '';
        if ($destType === 'ONE_TIME_ADDRESS') {
            $destAddress = $destination['oneTimeAddress']['address'] ?? '';
        } elseif ($destType === 'VAULT_ACCOUNT') {
            $destAddress = 'vault:' . ($destination['id'] ?? '');
        }

        $entityId = EntityManager::create(
            namespace: $ns,
            entityType: self::ENTITY_TRANSACTION,
            entityRef: $txId,
            state: $status,
            data: [
                'id'          => $txId,
                'status'      => $status,
                'operation'   => $operation,
                'source'      => $source,
                'destination' => $destination,
                'amount'      => $amount,
                'assetId'     => $assetId,
                'txHash'      => '0x' . bin2hex(random_bytes(32)),
                'createdAt'   => time() * 1000,
                'lastUpdated' => time() * 1000,
            ],
        );

        error_log("[Fireblocks] Transaction created: {$txId} | {$operation} | {$assetId} | {$amount} -> {$destAddress}");

        // Return the minimal response the fireblock service actually uses
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'id'     => $txId,
                'status' => $status,
            ],
        ];
    }

    /**
     * GET /v1/vault/accounts_paged
     *
     * Returns the three known vault accounts.
     */
    public static function listVaultAccounts(): array
    {
        $accounts = [];
        foreach (self::VAULT_ACCOUNTS as $id => $info) {
            $assets = [];
            if (isset(self::VAULT_ADDRESSES[$id])) {
                foreach (self::VAULT_ADDRESSES[$id] as $assetId => $addr) {
                    $assets[] = [
                        'id'             => $assetId,
                        'total'          => '1000.000000',
                        'available'      => '1000.000000',
                        'pending'        => '0',
                        'frozen'         => '0',
                        'lockedAmount'   => '0',
                        'blockHeight'    => '0',
                        'blockHash'      => '',
                    ];
                }
            }

            $accounts[] = [
                'id'          => $id,
                'name'        => $info['name'],
                'hiddenOnUI'  => $info['hiddenOnUI'],
                'autoFuel'    => $info['autoFuel'],
                'assets'      => $assets,
            ];
        }

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'accounts'  => $accounts,
                'paging'    => ['before' => '', 'after' => ''],
            ],
        ];
    }

    /**
     * GET /v1/vault/accounts/:vaultAccountId/:assetId/addresses_paginated
     *
     * Returns deposit addresses for a vault + asset pair.
     */
    public static function getVaultAddresses(string $vaultId, string $assetId): array
    {
        $addrInfo = self::VAULT_ADDRESSES[$vaultId][$assetId] ?? null;

        if ($addrInfo === null) {
            // Generate a plausible address for unknown vault/asset combos
            $addrInfo = [
                'address' => '0x' . bin2hex(random_bytes(20)),
                'tag'     => '',
            ];
        }

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'addresses' => [
                    [
                        'assetId'       => $assetId,
                        'address'       => $addrInfo['address'],
                        'tag'           => $addrInfo['tag'],
                        'description'   => '',
                        'type'          => 'Permanent',
                        'legacyAddress' => '',
                        'bip44AddressIndex' => 0,
                    ],
                ],
                'paging' => ['before' => '', 'after' => ''],
            ],
        ];
    }

    /**
     * GET /v1/transactions/:txId
     *
     * Returns a previously created transaction by its Fireblocks ID.
     */
    public static function getTransaction(?string $namespace, string $txId): array
    {
        $ns = $namespace ?? 'default';

        // Look up in EntityManager
        $entities = EntityManager::findAllByNamespace($ns, self::ENTITY_TRANSACTION);
        foreach ($entities as $entity) {
            if (($entity['data']['id'] ?? '') === $txId || ($entity['entity_ref'] ?? '') === $txId) {
                return [
                    'ok'     => true,
                    'status' => 200,
                    'body'   => $entity['data'],
                ];
            }
        }

        // If not found, return a synthetic completed transaction
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'id'          => $txId,
                'status'      => 'COMPLETED',
                'operation'   => 'TRANSFER',
                'txHash'      => '0x' . bin2hex(random_bytes(32)),
                'createdAt'   => time() * 1000,
                'lastUpdated' => time() * 1000,
                'source'      => ['type' => 'VAULT_ACCOUNT', 'id' => '17'],
                'destination'  => ['type' => 'ONE_TIME_ADDRESS'],
                'amount'      => '0',
                'assetId'     => 'USDC',
            ],
        ];
    }

    /**
     * GET /v1/transactions?txHash=...&assets=...&status=...
     *
     * Returns transactions filtered by txHash, assets, and/or status.
     */
    public static function listTransactions(?string $namespace, array $filters): array
    {
        $ns = $namespace ?? 'default';
        $entities = EntityManager::findAllByNamespace($ns, self::ENTITY_TRANSACTION);
        $results = [];

        foreach ($entities as $entity) {
            $data = $entity['data'] ?? [];
            $match = true;

            if (!empty($filters['txHash']) && ($data['txHash'] ?? '') !== $filters['txHash']) {
                $match = false;
            }
            if (!empty($filters['assets']) && ($data['assetId'] ?? '') !== $filters['assets']) {
                $match = false;
            }
            if (!empty($filters['status']) && ($data['status'] ?? '') !== $filters['status']) {
                $match = false;
            }
            if (!empty($filters['sourceId'])) {
                $sourceId = $data['source']['id'] ?? '';
                if ($sourceId !== $filters['sourceId']) {
                    $match = false;
                }
            }

            if ($match) {
                $results[] = $data;
            }
        }

        $limit = (int) ($filters['limit'] ?? 20);
        $results = array_slice($results, 0, $limit);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => $results,
        ];
    }

    /**
     * POST /control/fireblocks/deposit
     *
     * Simulate a crypto deposit arriving at a vault.
     * Constructs and fires a Fireblocks TRANSACTION_STATUS_UPDATED webhook
     * to rampas-penny-api, exactly as Fireblocks would in production.
     *
     * Required fields:
     *   sourceAddress  - the customer's wallet address (must match offramp/onramp record)
     *   amount         - crypto amount (string, must match offramp/onramp record)
     *   assetId        - Fireblocks asset ID (e.g., "USDC", "XLM_USDC_5F3T", "USDC_POLYGON_NXTB")
     *
     * Optional:
     *   destinationTag - Stellar memo (required for XLM assets to match)
     *   status         - default "COMPLETED" (use "CONFIRMING" etc. for partial scenarios)
     */
    public static function controlDeposit(array $body): array
    {
        $sourceAddress  = $body['sourceAddress'] ?? '';
        $amount         = $body['amount'] ?? '0';
        $assetId        = $body['assetId'] ?? 'USDC';
        $destinationTag = $body['destinationTag'] ?? '';
        $status         = $body['status'] ?? 'COMPLETED';

        if (!$sourceAddress || !$amount) {
            return [
                'ok'     => false,
                'status' => 400,
                'body'   => ['error' => 'sourceAddress and amount are required'],
            ];
        }

        $txId   = 'vrt_fb_' . bin2hex(random_bytes(16));
        $txHash = '0x' . bin2hex(random_bytes(32));

        // Build the Fireblocks webhook payload.
        // fireblocksConciliation() uses: type, data.assetId, data.sourceAddress,
        // data.amount, data.destinationTag, data.status, data.txHash
        $webhookPayload = [
            'type'      => 'TRANSACTION_STATUS_UPDATED',
            'tenantId'  => 'virtual',
            'timestamp' => time() * 1000,
            'data'      => [
                'id'              => $txId,
                'assetId'         => $assetId,
                'source'          => [
                    'type' => 'UNKNOWN',
                    'name' => 'External',
                ],
                'destination'     => [
                    'type' => 'VAULT_ACCOUNT',
                    'id'   => '17',
                    'name' => 'AlfredPay Main',
                ],
                'amount'          => (float) $amount,
                'sourceAddress'   => $sourceAddress,
                'destinationAddress' => self::VAULT_ADDRESSES['17']['USDC']['address'] ?? '',
                'destinationTag'  => $destinationTag,
                'txHash'          => $txHash,
                'status'          => $status,
                'operation'       => 'TRANSFER',
                'createdAt'       => time() * 1000,
                'lastUpdated'     => time() * 1000,
            ],
        ];

        // Fire the webhook to rampas-penny-api
        $targetUrl = $_ENV['FIREBLOCKS_WEBHOOK_URL'] ?? 'http://rampas-penny-api:3007/v1/webhook/alfredPay/fireblocks';

        $ch = curl_init($targetUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($webhookPayload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        $delivered = $curlError === '' && $httpCode >= 200 && $httpCode < 300;

        error_log("[Fireblocks Control] Deposit webhook fired: {$txId} | {$assetId} {$amount} from {$sourceAddress} | HTTP {$httpCode} | " . ($delivered ? 'OK' : "FAILED: {$curlError}"));

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'txId'      => $txId,
                'txHash'    => $txHash,
                'status'    => $status,
                'assetId'   => $assetId,
                'amount'    => $amount,
                'webhook'   => [
                    'targetUrl' => $targetUrl,
                    'httpCode'  => $httpCode,
                    'delivered' => $delivered,
                    'error'     => $curlError ?: null,
                ],
            ],
        ];
    }
}
