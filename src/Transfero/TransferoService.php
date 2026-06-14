<?php

declare(strict_types=1);

namespace App\Transfero;

use App\Entity\EntityManager;

/**
 * Virtual Transfero: openbanking.bit.one Brazil PIX payout provider.
 * Called outbound by `rampas-brasil` (and `nest-backend-service-brasil`) via env `TRANSF_URL`.
 * Wrapper lives at ExperimentRepos/alfred-payments/rampas-brasil.
 *
 * Endpoints mirrored (match what rampas-brasil/src/modules/transfero/*.service.ts and
 * rampas-brasil/src/modules/copterpay/*.service.ts actually call):
 *   POST /auth/token                          → { accessToken }
 *   POST /transferoAuth/send-payment          → { statusCode, message, data: { paymentGroupId, payments: [{ paymentId }] } }
 *   GET  /transferoAuth/get-payment/{id}      → { statusCode, data: { payments: [{ paymentStatus }] } }
 *   POST /copterpay/payout                    → { statusCode, data: { id, code } }
 *   GET  /copterpay/get-status/{id}           → { statusCode, data: { status } }
 *
 * State model: each payment persisted as `transfero_payment` entity keyed by paymentGroupId/id.
 * Default outcome = success. Control-plane endpoints can override per-namespace to drive Pending/FAILED.
 */
final class TransferoService
{
    public const ENTITY_PAYMENT = 'transfero_payment';

    public const STATUS_TRANSFERO_SUCCESS = 'CompletedWithSuccess';
    public const STATUS_COPTERPAY_SUCCESS = 'PAID';

    /** POST /auth/token: body { apiKey, apiSecret }. Any creds accepted. */
    public static function authToken(): array
    {
        return [
            'status' => 200,
            'body'   => [
                'accessToken' => 'vrt_transfero_' . bin2hex(random_bytes(16)),
                'expiresIn'   => 3600,
                'tokenType'   => 'Bearer',
            ],
        ];
    }

    /**
     * POST /transferoAuth/send-payment: batch payout.
     * Body is an array of payments: [{ amount, currency, name, taxIdCountry, taxId, pixKey }, …].
     */
    public static function transferoSendPayment(string $namespace, array $body): array
    {
        $paymentGroupId = 'PG' . strtoupper(bin2hex(random_bytes(6)));
        $items = array_is_list($body) ? $body : [$body];
        $payments = [];

        foreach ($items as $item) {
            $paymentId = 'PAY' . strtoupper(bin2hex(random_bytes(6)));
            $payments[] = ['paymentId' => $paymentId];

            EntityManager::create($namespace, self::ENTITY_PAYMENT, $paymentGroupId . ':' . $paymentId, self::STATUS_TRANSFERO_SUCCESS, [
                'kind'           => 'transferoAuth',
                'paymentGroupId' => $paymentGroupId,
                'paymentId'      => $paymentId,
                'paymentStatus'  => self::STATUS_TRANSFERO_SUCCESS,
                'amount'         => $item['amount'] ?? null,
                'currency'       => $item['currency'] ?? 'BRL',
                'pixKey'         => $item['pixKey'] ?? null,
                'taxId'          => $item['taxId'] ?? null,
                'name'           => $item['name'] ?? null,
            ]);
        }

        // also store a group-level record so /get-payment/{paymentGroupId} can resolve
        EntityManager::create($namespace, self::ENTITY_PAYMENT, $paymentGroupId, self::STATUS_TRANSFERO_SUCCESS, [
            'kind'           => 'transferoAuth_group',
            'paymentGroupId' => $paymentGroupId,
            'payments'       => $payments,
            'paymentStatus'  => self::STATUS_TRANSFERO_SUCCESS,
        ]);

        return [
            'status' => 200,
            'body'   => [
                'statusCode' => 200,
                'message'    => 'Success',
                'data'       => [
                    'paymentGroupId' => $paymentGroupId,
                    'payments'       => $payments,
                ],
            ],
        ];
    }

    /** GET /transferoAuth/get-payment/{id}: id = paymentGroupId. */
    public static function transferoGetPayment(string $namespace, string $paymentGroupId): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_PAYMENT, $paymentGroupId);
        $status = $entity['data']['paymentStatus'] ?? self::STATUS_TRANSFERO_SUCCESS;

        return [
            'status' => 200,
            'body'   => [
                'statusCode' => 200,
                'data'       => [
                    'payments' => [
                        ['paymentStatus' => $status],
                    ],
                ],
            ],
        ];
    }

    /**
     * POST /copterpay/payout: single payment.
     * Body: { amount, pixKey, opId, bankAccountType, type }.
     */
    public static function copterpayPayout(string $namespace, array $body): array
    {
        $id = $body['opId'] ?? ('cpt_' . bin2hex(random_bytes(8)));
        $code = 'CPT' . strtoupper(bin2hex(random_bytes(5)));

        EntityManager::create($namespace, self::ENTITY_PAYMENT, $id, self::STATUS_COPTERPAY_SUCCESS, [
            'kind'    => 'copterpay',
            'id'      => $id,
            'code'    => $code,
            'status'  => self::STATUS_COPTERPAY_SUCCESS,
            'amount'  => $body['amount'] ?? null,
            'pixKey'  => $body['pixKey'] ?? null,
            'type'    => $body['type'] ?? 'INDIVIDUAL',
        ]);

        return [
            'status' => 200,
            'body'   => [
                'statusCode' => 200,
                'data'       => [
                    'id'   => $id,
                    'code' => $code,
                ],
            ],
        ];
    }

    /** GET /copterpay/get-status/{id}. */
    public static function copterpayGetStatus(string $namespace, string $id): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_PAYMENT, $id);
        $status = $entity['data']['status'] ?? self::STATUS_COPTERPAY_SUCCESS;

        return [
            'status' => 200,
            'body'   => [
                'statusCode' => 200,
                'data'       => ['status' => $status],
            ],
        ];
    }

    /**
     * Control-plane helper for tests that need to flip a payment to a failed state
     * before polling. Not called by rampas-brasil.
     */
    public static function controlSetStatus(string $namespace, string $id, string $status): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_PAYMENT, $id);
        if (!$entity) {
            return ['status' => 404, 'body' => ['error' => 'payment not found']];
        }

        $data = $entity['data'];
        // both naming conventions, since get-payment reads paymentStatus, get-status reads status
        $data['paymentStatus'] = $status;
        $data['status'] = $status;
        EntityManager::updateData($entity['id'], $data);

        return [
            'status' => 200,
            'body'   => ['id' => $id, 'status' => $status],
        ];
    }
}
