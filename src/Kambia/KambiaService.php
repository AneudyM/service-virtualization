<?php

declare(strict_types=1);

namespace App\Kambia;

use App\Entity\EntityManager;

/**
 * Virtual microservice-colombia (the AlfredPay wrapper in front of real Kambia).
 * Called outbound by `rampas-colombia` via env URL_MICROSERVICE_COLOMBIA.
 *
 * URL_MICROSERVICE_COLOMBIA=http://service-virtualization/virtual/kambia already
 * set in the running wrapper; rampas-colombia appends `/kambia/*`, so routes live
 * at `/virtual/kambia/kambia/*`.
 *
 * Endpoints mirrored (offramp flow only: what rampas-colombia actually calls):
 *   GET  /kambia/user/login                              → { token }
 *   GET  /kambia/account/details                         → { account_id, balance, currency }
 *   POST /kambia/transaction/arch                        → { data: { transfer_id, external_reference } }
 *   GET  /kambia/transaction/arch/details/{id}           → { data: "PAID|PENDING|FAILED" }
 *   GET  /kambia/list/bank                               → static bank list
 *   GET  /kambia/list/typeDocument                       → static doc types
 *   GET  /kambia/list/account                            → static account types
 *   POST /control/kambia/webhook/{transferId}            → simulate a webhook back to rampas-colombia
 *
 * State: each arch transaction persisted by transfer_id. Default outcome PAID.
 * Control-plane endpoints flip status + fire the callback webhook to rampas-colombia.
 *
 * Note on AES: the real Kambia API uses AES-encrypted payloads, but that encryption
 * lives between microservice-colombia and Kambia. We cut in BEFORE microservice-colombia,
 * so no crypto is required here.
 */
final class KambiaService
{
    public const ENTITY_TRANSFER = 'kambia_transfer';

    public const STATUS_PAID = 'PAID';
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_FAILED = 'FAILED';

    private const RAMPAS_COLOMBIA_WEBHOOK_URL = 'http://rampas-colombia-local:3010/v1/webhook/webhookKambia';

    public static function userLogin(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'token'     => 'vrt_kambia_' . bin2hex(random_bytes(16)),
                'expiresIn' => 3600,
            ],
        ];
    }

    public static function accountDetails(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'account_id'     => '89c7ff24-5e0c-4173-89f5-d3d34dadb2d4',
                'balance'        => '1000000.00',
                'available'      => '950000.00',
                'currency'       => 'COP',
                'account_holder' => 'Virtual AlfredPay CO',
            ],
        ];
    }

    /**
     * POST /kambia/transaction/arch: create payout.
     * Body required: transfer_id, amount, external_account: { account_alias, account_number, ... }.
     * Returns wrapped data { transfer_id, external_reference } on success.
     * Known error codes the wrapper handles: MUST_PROVIDE_TRANSFER_ID, UNKNOWN_ERROR,
     * EXTERNAL_ACCOUNT_LOCKED, NEQUI_PHONE_NUMBER_NOT_FOUND: easy to simulate via control plane.
     */
    public static function createTransferArch(string $namespace, array $body): array
    {
        $transferId = $body['transfer_id'] ?? null;
        if (!$transferId) {
            return [
                'status' => 400,
                'body'   => [
                    'code'    => 'MUST_PROVIDE_TRANSFER_ID',
                    'message' => 'transfer_id is required',
                ],
            ];
        }

        $externalReference = 'KAM' . strtoupper(bin2hex(random_bytes(5)));

        EntityManager::create($namespace, self::ENTITY_TRANSFER, $transferId, self::STATUS_PAID, [
            'transfer_id'         => $transferId,
            'external_reference'  => $externalReference,
            'amount'              => $body['amount'] ?? null,
            'subject'             => $body['subject'] ?? null,
            'status'              => self::STATUS_PAID,
            'external_account'    => $body['external_account'] ?? null,
        ]);

        return [
            'status' => 200,
            'body'   => [
                'data' => [
                    'transfer_id'        => $transferId,
                    'external_reference' => $externalReference,
                ],
            ],
        ];
    }

    public static function getTransferArchDetails(string $namespace, string $transferId): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_TRANSFER, $transferId);
        $status = $entity['data']['status'] ?? self::STATUS_PAID;

        return [
            'status' => 200,
            'body'   => [
                'data' => $status,
            ],
        ];
    }

    public static function listBanks(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'data' => [
                    ['id' => 1, 'name' => 'Bancolombia', 'code' => '007'],
                    ['id' => 2, 'name' => 'Davivienda', 'code' => '051'],
                    ['id' => 3, 'name' => 'Banco de Bogota', 'code' => '001'],
                    ['id' => 4, 'name' => 'BBVA Colombia', 'code' => '013'],
                    ['id' => 5, 'name' => 'Nequi', 'code' => '507'],
                ],
            ],
        ];
    }

    public static function listDocumentTypes(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'data' => [
                    ['id' => 1, 'code' => 'CC', 'name' => 'Cedula de Ciudadania'],
                    ['id' => 2, 'code' => 'CE', 'name' => 'Cedula de Extranjeria'],
                    ['id' => 3, 'code' => 'NIT', 'name' => 'NIT'],
                    ['id' => 4, 'code' => 'PP', 'name' => 'Pasaporte'],
                ],
            ],
        ];
    }

    public static function listAccountTypes(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'data' => [
                    ['id' => 1, 'code' => 'SAVINGS', 'name' => 'Ahorros'],
                    ['id' => 2, 'code' => 'CHECKING', 'name' => 'Corriente'],
                ],
            ],
        ];
    }

    /**
     * Control plane: flip a transfer status AND fire a webhook back to rampas-colombia.
     * Webhook body shape from rampas-colombia/src/common/dto/webhookBankaool.dto.ts::WebhookKambia.
     */
    public static function controlFireWebhook(string $namespace, string $transferId, string $status): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_TRANSFER, $transferId);
        if (!$entity) {
            return ['status' => 404, 'body' => ['error' => 'transfer not found']];
        }

        $data = $entity['data'];
        $data['status'] = $status;
        EntityManager::updateData($entity['id'], $data);

        $operationStatusId = match ($status) {
            self::STATUS_PAID => 4,
            self::STATUS_FAILED => 5,
            default => 2,
        };

        $webhookBody = [
            'transfer_id'             => $transferId,
            'operation_status_id'     => $operationStatusId,
            'transaction_type_id'     => 34,
            'external_reject_reason'  => $status === self::STATUS_FAILED ? 'Simulated failure from control plane' : null,
            'external_reference'      => $data['external_reference'] ?? null,
        ];

        $ch = curl_init(self::RAMPAS_COLOMBIA_WEBHOOK_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($webhookBody),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => 200,
            'body'   => [
                'fired'           => true,
                'transferId'      => $transferId,
                'status'          => $status,
                'webhookHttpCode' => $code,
                'webhookResponse' => $resp,
            ],
        ];
    }
}
