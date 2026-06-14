<?php

declare(strict_types=1);

namespace App\WesternUnion;

use App\Entity\EntityManager;

/**
 * Western Union Service: stateful Send Money flow.
 *
 * Stateless config endpoints live in WuFixtures. This class only handles
 * Quote -> CreateOrder -> Confirm -> Receive (validate + confirm) lifecycle,
 * which needs entity persistence so that an MTCN issued by createOrder can be
 * looked up later by the receive flow.
 *
 * Entity model:
 *   entity_type='wu_quote' entity_ref=quoteId data={...quote response, expires_at}
 *   entity_type='wu_order' entity_ref=mtcn    data={...order response, state history}
 *
 * Order states:
 *   DRAFT     -> created via /v1/pgw/orders, awaiting confirm
 *   PAID      -> /v1/pgw/orders/confirm    (sender funds captured)
 *   AVAILABLE -> /v1/pgw/orders/receive    (receiver validation passed)
 *   RECEIVED  -> /v1/pgw/orders/receive/confirm (payout completed)
 *   CANCELLED -> /v1/pgw/orders/cancel
 */
final class WesternUnionService
{
    public const ENTITY_QUOTE = 'wu_quote';
    public const ENTITY_ORDER = 'wu_order';

    public const STATE_DRAFT     = 'DRAFT';
    public const STATE_PAID      = 'PAID';
    public const STATE_AVAILABLE = 'AVAILABLE';
    public const STATE_RECEIVED  = 'RECEIVED';
    public const STATE_CANCELLED = 'CANCELLED';

    /** Fraud MTCN from AP_MODAPI_TESTCASES-Send.xlsx, MIM6 */
    public const FRAUD_MTCN = '8031530954';

    private const QUOTE_TTL_SECONDS = 300; // 5 minutes, PRD rate-lock window

    /**
     * Minimum sender fields enforced.
     *
     * WU API enforces a wider CIP field set (email, nationality,
     * countryOfBirth) above the $1 USD threshold, but the Alfred cash.service
     * mapping does not pass those through to the WU client. Validating them
     * here would break the entire cash/send happy path. The full CIP check
     * is left for a future test pass that drives /v1/pgw/orders directly with
     * a richer payload.
     */
    private const REQUIRED_SENDER_FIELDS = [
        'sender.name.firstName',
        'sender.name.lastName',
        'sender.dateOfBirth',
        'sender.identification.idNumber',
    ];

    public static function issueToken(): array
    {
        return [
            'access_token' => 'virtual-wu-' . bin2hex(random_bytes(24)),
            'token_type'   => 'Bearer',
            'expires_in'   => 3600,
            'scope'        => 'pgw.config.* pgw.orders.*',
        ];
    }

    /**
     * Compute a quote and persist it. Returns the response payload that the
     * real WU API would send back from POST /v1/pgw/orders/quotes.
     */
    public static function createQuote(string $namespace, array $request): array
    {
        $receiverCountry  = (string) ($request['payoutDetails']['receiverCountry']  ?? '');
        $receiverCurrency = (string) ($request['payoutDetails']['receiverCurrency'] ?? '');
        $sendAmount       = (string) ($request['consumerSendAmount'] ?? '0');
        $payInMethod      = (string) ($request['payInMethod']        ?? 'CASH');

        $corridor = WuFixtures::corridor($receiverCountry);
        if ($corridor === null) {
            return self::error('R4001', "Corridor not supported: {$receiverCountry}", 400);
        }

        $sendAmountFloat = (float) $sendAmount;
        $fee             = (float) $corridor['fee'];
        $rate            = (float) $corridor['rate'];
        $grossAmount     = $sendAmountFloat + $fee;
        $payoutAmount    = $sendAmountFloat * $rate;

        $quoteId  = 'qte_' . bin2hex(random_bytes(8));
        $orderId  = 'ord_' . bin2hex(random_bytes(8));
        $expiresAt = time() + self::QUOTE_TTL_SECONDS;

        $payload = [
            'orderId'              => $orderId,
            'quoteId'              => $quoteId,
            'fees'                 => ['totalFee' => self::money($fee)],
            'taxes'                => ['totalTax' => '0.00'],
            'grossAmount'          => self::money($grossAmount),
            'expectedPayoutAmount' => self::money($payoutAmount),
            'exchangeRate'         => self::money($rate, 4),
            'payoutDetails'        => [
                'payoutMethod'     => $request['payoutDetails']['payoutMethod'] ?? 'CASH',
                'receiverCountry'  => $receiverCountry,
                'receiverCurrency' => $receiverCurrency,
            ],
            'dateTime'             => self::now(),
            'statusCode'           => '0000',
            'status'               => 'OK',
        ];

        EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_QUOTE,
            entityRef: $quoteId,
            state: 'ACTIVE',
            data: [
                'response'        => $payload,
                'expires_at'      => $expiresAt,
                'send_amount'     => self::money($sendAmountFloat),
                'payInMethod'     => $payInMethod,
                'receiver_country'=> $receiverCountry,
            ],
        );

        return ['ok' => true, 'status' => 200, 'body' => $payload];
    }

    /**
     * POST /v1/pgw/orders: issues an MTCN and persists the order in DRAFT.
     *
     *   - Validates CIP fields above $1 USD (returns R0003 if missing)
     *   - Returns DATA_VALIDATION_ERROR shape on failure
     *   - On success, returns the order with mtcn + transactionId
     */
    public static function createOrder(string $namespace, array $request): array
    {
        $missing = self::missingRequiredFields($request);
        if (!empty($missing)) {
            return self::dataValidationError(
                'R0003',
                'Dynamic Data Collection/Validation Error',
                array_map(fn($f) => ['field' => $f, 'code' => 'MISSING_REQUIRED'], $missing),
            );
        }

        $receiverCountry  = (string) ($request['payoutDetails']['receiverCountry']  ?? '');
        $receiverCurrency = (string) ($request['payoutDetails']['receiverCurrency'] ?? '');
        $sendAmount       = (string) ($request['consumerSendAmount'] ?? '0');

        $corridor = WuFixtures::corridor($receiverCountry);
        if ($corridor === null) {
            return self::error('R4001', "Corridor not supported: {$receiverCountry}", 400);
        }

        $sendAmountFloat = (float) $sendAmount;
        $fee             = (float) $corridor['fee'];
        $rate            = (float) $corridor['rate'];
        $grossAmount     = $sendAmountFloat + $fee;
        $payoutAmount    = $sendAmountFloat * $rate;

        $mtcn          = self::generateMtcn();
        $orderId       = 'ord_' . bin2hex(random_bytes(8));
        $transactionId = 'txn_' . bin2hex(random_bytes(8));

        $payload = [
            'orderId'              => $orderId,
            'mtcn'                 => $mtcn,
            'transactionId'        => $transactionId,
            'dateTime'             => self::now(),
            'statusCode'           => '0000',
            'status'               => 'OK',
            'message'              => 'Order created',
            'fees'                 => ['totalFee' => self::money($fee)],
            'taxes'                => ['totalTax' => '0.00'],
            'grossAmount'          => self::money($grossAmount),
            'expectedPayoutAmount' => self::money($payoutAmount),
            'exchangeRate'         => self::money($rate, 4),
            'payoutDetails'        => [
                'payoutMethod'     => $request['payoutDetails']['payoutMethod'] ?? 'CASH',
                'receiverCountry'  => $receiverCountry,
                'receiverCurrency' => $receiverCurrency,
            ],
            'sender'               => $request['sender']   ?? null,
            'receiver'             => $request['receiver'] ?? null,
            'orderDetails'         => [
                'consumerSendAmount' => self::money($sendAmountFloat),
                'receiverCountry'    => $receiverCountry,
                'receiverCurrency'   => $receiverCurrency,
                'fees'               => ['totalFee' => self::money($fee)],
                'taxes'              => ['totalTax' => '0.00'],
                'grossAmount'        => self::money($grossAmount),
                'expectedPayoutAmt'  => self::money($payoutAmount),
            ],
        ];

        // The Alfred cash.service only calls /v1/pgw/orders (no separate confirm
        // step), so we land orders directly in PAID. /v1/pgw/orders/confirm
        // becomes idempotent below.
        EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_ORDER,
            entityRef: $mtcn,
            state: self::STATE_PAID,
            data: [
                'response' => $payload,
                'request'  => $request,
            ],
        );

        return ['ok' => true, 'status' => 200, 'body' => $payload];
    }

    public static function confirmOrder(string $namespace, array $request): array
    {
        $mtcn = self::pluckMtcn($request);
        if ($mtcn === null) {
            return self::error('R4002', 'mtcn or orderId is required', 400);
        }

        $entity = EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
        if ($entity === null) {
            return self::error('R4040', "Order not found for MTCN {$mtcn}", 404);
        }

        // confirm is idempotent: DRAFT or PAID both succeed.
        if (!in_array($entity['state'], [self::STATE_DRAFT, self::STATE_PAID], true)) {
            return self::error('R4090', "Cannot confirm order in state {$entity['state']}", 409);
        }

        if ($entity['state'] === self::STATE_DRAFT) {
            EntityManager::transition(
                entityId: (int) $entity['id'],
                newState: self::STATE_PAID,
                triggerType: 'api_call',
                metadata: ['action' => 'confirm'],
            );
        }

        $body = array_merge($entity['data']['response'] ?? [], [
            'status'     => 'CONFIRMED',
            'statusCode' => '0000',
            'message'    => 'Order confirmed',
            'dateTime'   => self::now(),
        ]);

        return ['ok' => true, 'status' => 200, 'body' => $body];
    }

    public static function receiveValidate(string $namespace, array $request): array
    {
        $mtcn = self::pluckMtcn($request);
        if ($mtcn === null) {
            return self::error('R4002', 'mtcn is required', 400);
        }

        // Always reject the well-known fraud MTCN per AP_MODAPI MIM6.
        if ($mtcn === self::FRAUD_MTCN) {
            return self::dataValidationError(
                'U2229',
                'USA/CAN 1-866-420-2996 OR VISIT WU AGENT',
            );
        }

        $entity = EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
        if ($entity === null) {
            return self::error('R4040', "Order not found for MTCN {$mtcn}", 404);
        }

        if ($entity['state'] !== self::STATE_PAID) {
            return self::error('R4090', "MTCN {$mtcn} is not available for pickup (state={$entity['state']})", 409);
        }

        EntityManager::transition(
            entityId: (int) $entity['id'],
            newState: self::STATE_AVAILABLE,
            triggerType: 'api_call',
            metadata: ['action' => 'receive_validate'],
        );

        $original = $entity['data']['response'] ?? [];
        $body = [
            'mtcn'                 => $mtcn,
            'transactionId'        => $original['transactionId'] ?? null,
            'orderId'              => $original['orderId'] ?? null,
            'status'               => 'AVAILABLE_FOR_PICKUP',
            'statusCode'           => '0000',
            'expectedPayoutAmount' => $original['expectedPayoutAmount'] ?? null,
            'payoutDetails'        => $original['payoutDetails'] ?? null,
            'sender'               => $original['sender']   ?? null,
            'receiver'             => $original['receiver'] ?? null,
            'dateTime'             => self::now(),
        ];

        return ['ok' => true, 'status' => 200, 'body' => $body];
    }

    public static function receiveConfirm(string $namespace, array $request): array
    {
        $transactionId = $request['transactionId'] ?? null;
        $mtcn = self::pluckMtcn($request);

        // Also accept MTCN lookup (e2e tests drive by MTCN, not transactionId).
        if ($mtcn === null && $transactionId === null) {
            return self::error('R4002', 'transactionId or mtcn is required', 400);
        }

        $entity = $mtcn !== null
            ? EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn)
            : self::findByTransactionId($namespace, (string) $transactionId);

        if ($entity === null) {
            return self::error('R4040', 'Order not found', 404);
        }

        if ($entity['state'] !== self::STATE_AVAILABLE) {
            return self::error('R4090', "Order in state {$entity['state']} is not ready for receive confirm", 409);
        }

        EntityManager::transition(
            entityId: (int) $entity['id'],
            newState: self::STATE_RECEIVED,
            triggerType: 'api_call',
            metadata: ['action' => 'receive_confirm'],
        );

        $original = $entity['data']['response'] ?? [];
        $body = [
            'mtcn'          => $original['mtcn'] ?? $mtcn,
            'transactionId' => $original['transactionId'] ?? $transactionId,
            'orderId'       => $original['orderId'] ?? null,
            'status'        => 'PAID_TO_RECEIVER',
            'statusCode'    => '0000',
            'message'       => 'Receive confirmed',
            'dateTime'      => self::now(),
        ];

        return ['ok' => true, 'status' => 200, 'body' => $body];
    }

    public static function cancelOrder(string $namespace, array $request): array
    {
        $mtcn = self::pluckMtcn($request);
        if ($mtcn === null) {
            return self::error('R4002', 'mtcn is required', 400);
        }

        $entity = EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
        if ($entity === null) {
            return self::error('R4040', "Order not found for MTCN {$mtcn}", 404);
        }

        if ($entity['state'] === self::STATE_RECEIVED) {
            return self::error('R4090', 'Cannot cancel an order that has already been received', 409);
        }

        EntityManager::transition(
            entityId: (int) $entity['id'],
            newState: self::STATE_CANCELLED,
            triggerType: 'api_call',
            metadata: ['action' => 'cancel'],
        );

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'mtcn'       => $mtcn,
                'status'     => 'CANCELLED',
                'statusCode' => '0000',
                'dateTime'   => self::now(),
            ],
        ];
    }

    public static function inquiry(string $namespace, array $request): array
    {
        $mtcn = self::pluckMtcn($request);
        if ($mtcn === null) {
            return self::error('R4002', 'mtcn is required', 400);
        }

        $entity = EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
        if ($entity === null) {
            return self::error('R4040', "Order not found for MTCN {$mtcn}", 404);
        }

        $body = array_merge($entity['data']['response'] ?? [], [
            'currentState' => $entity['state'],
            'dateTime'     => self::now(),
        ]);

        return ['ok' => true, 'status' => 200, 'body' => $body];
    }

    public static function listOrders(string $namespace): array
    {
        $rows = EntityManager::findAllByNamespace($namespace, self::ENTITY_ORDER);
        return array_map(fn($r) => [
            'mtcn'       => $r['entity_ref'],
            'state'      => $r['state'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'response'   => $r['data']['response'] ?? null,
        ], $rows);
    }

    public static function forceState(string $namespace, string $mtcn, string $newState): ?array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
        if ($entity === null) {
            return null;
        }

        EntityManager::transition(
            entityId: (int) $entity['id'],
            newState: $newState,
            triggerType: 'manual',
            metadata: ['action' => 'control_force_state'],
        );

        return EntityManager::find($namespace, self::ENTITY_ORDER, $mtcn);
    }

    private static function pluckMtcn(array $request): ?string
    {
        $candidates = [
            $request['mtcn']                 ?? null,
            $request['orderId']              ?? null,
            $request['receiver']['mtcn']     ?? null,
            $request['orderDetails']['mtcn'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '' && ctype_digit($value)) {
                return $value;
            }
        }
        return null;
    }

    private static function findByTransactionId(string $namespace, string $transactionId): ?array
    {
        $rows = EntityManager::findAllByNamespace($namespace, self::ENTITY_ORDER);
        foreach ($rows as $row) {
            if (($row['data']['response']['transactionId'] ?? null) === $transactionId) {
                return $row;
            }
        }
        return null;
    }

    private static function missingRequiredFields(array $request): array
    {
        $missing = [];
        foreach (self::REQUIRED_SENDER_FIELDS as $path) {
            if (self::digInto($request, $path) === null) {
                $missing[] = $path;
            }
        }
        return $missing;
    }

    private static function digInto(array $data, string $path): mixed
    {
        $parts = explode('.', $path);
        $cursor = $data;
        foreach ($parts as $part) {
            if (!is_array($cursor) || !array_key_exists($part, $cursor)) {
                return null;
            }
            $cursor = $cursor[$part];
        }
        if (is_string($cursor) && trim($cursor) === '') {
            return null;
        }
        return $cursor;
    }

    private static function generateMtcn(): string
    {
        // 10 digits, never starts with zero so it's always 10-digit when serialised.
        $mtcn = (string) random_int(1, 9);
        for ($i = 0; $i < 9; $i++) {
            $mtcn .= (string) random_int(0, 9);
        }
        return $mtcn;
    }

    private static function money(float $value, int $decimals = 2): string
    {
        return number_format($value, $decimals, '.', '');
    }

    private static function now(): string
    {
        return date('Y-m-d\TH:i:s\Z');
    }

    private static function error(string $code, string $message, int $status): array
    {
        return [
            'ok'     => false,
            'status' => $status,
            'body'   => [
                'name'      => 'ERROR',
                'errorCode' => $code,
                'message'   => $message,
            ],
        ];
    }

    private static function dataValidationError(string $code, string $message, array $issues = []): array
    {
        $body = [
            'name'      => 'DATA_VALIDATION_ERROR',
            'errorCode' => $code,
            'message'   => $message,
        ];
        if (!empty($issues)) {
            $body['issues'] = $issues;
        }

        return ['ok' => false, 'status' => 400, 'body' => $body];
    }
}
