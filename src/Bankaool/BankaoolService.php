<?php

declare(strict_types=1);

namespace App\Bankaool;

use App\Callback\CallbackScheduler;
use App\Entity\EntityManager;

/**
 * Bankaool banking API + Paymax/SRC microservice for SPEI transfers.
 *
 * Entity types stored via EntityManager:
 *   - bankaool_account:    Bank accounts with CLABE and balance
 *   - bankaool_transfer:   SPEI transfers (outbound)
 *   - bankaool_deposit:    Inbound SPEI deposits, triggered from browser UI
 *   - bankaool_customer:   Paymax customer-is-balance accounts
 *   - bankaool_otp:        OTP tokens for transfer approval
 */
final class BankaoolService
{
    // Entity type constants
    public const ENTITY_ACCOUNT  = 'bankaool_account';
    public const ENTITY_TRANSFER = 'bankaool_transfer';
    public const ENTITY_DEPOSIT  = 'bankaool_deposit';
    public const ENTITY_CUSTOMER = 'bankaool_customer';
    public const ENTITY_OTP      = 'bankaool_otp';

    /**
     * Simulate OAuth2 token endpoint.
     * Real Bankaool uses: username, password, grant_type, client_id, client_secret
     * We accept anything and return a token.
     */
    public static function authenticate(array $body): array
    {
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'access_token' => 'vrt_bkool_' . bin2hex(random_bytes(24)),
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
                'scope'        => 'read write transfer',
            ],
        ];
    }

    /**
     * GET /v1/cuenta: Returns the main account at Bankaool.
     */
    public static function getAccounts(string $namespace): array
    {
        $account = self::getOrCreateMainAccount($namespace);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                [
                    'id'        => $account['data']['id'],
                    'no_cuenta' => $account['data']['no_cuenta'],
                    'alias'     => $account['data']['alias'],
                    'saldo'     => $account['data']['saldo'],
                    'moneda'    => $account['data']['moneda'] ?? 'MXN',
                    'estatus'   => $account['data']['estatus'] ?? 'ACTIVA',
                    'clabe'     => $account['data']['clabe'],
                ],
            ],
        ];
    }

    /**
     * GET /v1/cuenta/{id}/medios-pago: Payment methods for an account.
     */
    public static function getPaymentMethods(string $namespace, string $accountId): array
    {
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                [
                    'id'          => BankaoolFixtures::ALFREDPAY_PAYMENT_METHOD['id'],
                    'id_cuenta'   => (int) $accountId,
                    'tipo'        => 'SPEI',
                    'descripcion' => 'SPEI - Transferencia Interbancaria',
                    'activo'      => true,
                ],
            ],
        ];
    }

    /**
     * GET /v1/consulta-banco: Look up bank by destination account.
     */
    public static function lookupBank(string $accountNumber): array
    {
        $bank = BankaoolFixtures::lookupBank($accountNumber);
        if ($bank === null) {
            return [
                'ok'     => false,
                'status' => 404,
                'body'   => ['error' => true, 'msg' => 'Banco no encontrado'],
            ];
        }

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => $bank,
        ];
    }

    /**
     * POST /v1/token-otp: Generate a virtual OTP.
     * Real Bankaool sends SMS; we store it and log it.
     */
    public static function generateOtp(string $namespace): array
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_OTP,
            entityRef: $otp,
            state: 'active',
            data: [
                'otp'        => $otp,
                'created_at' => date('c'),
                'expires_at' => date('c', strtotime('+5 minutes')),
            ],
        );

        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  BANKAOOL VIRTUAL OTP: {$otp}");
        error_log("║  Namespace: {$namespace}");
        error_log("║  Expires: " . date('H:i:s', strtotime('+5 minutes')));
        error_log("╚══════════════════════════════════════════════════╝");

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'error' => false,
                'msg'   => 'Token OTP generado exitosamente',
            ],
        ];
    }

    /**
     * POST /v1/transferir: Execute an outbound SPEI transfer.
     *
     * Called by ramps-mexico to send MXN to a user's CLABE (off-ramp).
     * Returns a pending transaction that needs approval.
     */
    public static function createTransfer(string $namespace, array $body): array
    {
        $claveRastreo = BankaoolFixtures::generateClaveRastreo();
        $txId = random_int(100000, 999999);

        $destAccount = $body['cuenta_destino'] ?? '';
        $clabeCheck = BankaoolFixtures::validateClabe($destAccount);

        $entityId = EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_TRANSFER,
            entityRef: $claveRastreo,
            state: 'INSTRUIDA',
            data: [
                'id_tx_pendiente'      => $txId,
                'clave_rastreo_previa' => $claveRastreo,
                'id_medio_pago'        => $body['id_medio_pago'] ?? '',
                'id_cuenta_origen'     => $body['id_cuenta_origen'] ?? '',
                'cuenta_destino'       => $destAccount,
                'banco_destino'        => $body['banco_destino'] ?? '',
                'banco_destino_nombre' => $clabeCheck['valid'] ? $clabeCheck['bank_name'] : 'DESCONOCIDO',
                'importe'              => (float) ($body['importe'] ?? 0),
                'concepto'             => $body['concepto'] ?? '',
                'referencia'           => $body['referencia'] ?? '',
                'nombre_beneficiario'  => $body['nombre_beneficiario'] ?? '',
                'created_at'           => date('c'),
            ],
        );

        $amount = (float) ($body['importe'] ?? 0);
        self::adjustBalance($namespace, -$amount);

        error_log("[Bankaool] Transfer created: {$claveRastreo} | MXN {$amount} -> {$destAccount}");

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'error'                => false,
                'msg'                  => 'Transferencia registrada',
                'id_tx_pendiente'      => $txId,
                'clave_rastreo_previa' => $claveRastreo,
            ],
        ];
    }

    /**
     * POST /v1/aprobar-transferencia: Approve a pending transfer.
     *
     * ramps-mexico calls this with OTP after the transfer is created.
     * Transfers are auto-settled and a webhook callback is scheduled.
     */
    public static function approveTransfer(string $namespace, array $body): array
    {
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'error' => false,
                'msg'   => 'Transferencia aprobada exitosamente',
            ],
        ];
    }

    /**
     * POST /v1/cobranza: Create a collection deposit reference.
     *
     * Generates a CLABE + reference pair for a customer to pay into.
     */
    public static function createCollectionDeposit(string $namespace, array $body): array
    {
        $clabe = BankaoolFixtures::generateClabe(BankaoolFixtures::BANKAOOL_BANK_CODE);
        $refId = random_int(10000, 99999);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'error'      => false,
                'msg'        => 'Cobranza registrada',
                'id_cobro'   => $refId,
                'clabe'      => $clabe,
                'referencia' => $body['nombre_cobranza'] ?? 'COBRANZA',
            ],
        ];
    }

    /**
     * POST /auth/token: Authenticate with the Paymax microservice.
     */
    public static function authTokenSRC(array $body): array
    {
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'accessToken' => 'vrt_paymax_' . bin2hex(random_bytes(16)),
            ],
        ];
    }

    /**
     * POST /paymax/executen-payment: Execute a payment (off-ramp).
     *
     * This is the main endpoint rampas-penny-api-polybase calls to send MXN.
     * Returns clave_rastreo_previa and id_tx_aprovate which are stored on the offramp.
     */
    public static function executePayment(string $namespace, array $body): array
    {
        $claveRastreo = BankaoolFixtures::generateClaveRastreo();
        $txApproveId = random_int(100000, 999999);
        $amount = $body['amount'] ?? '0.00';

        $entityId = EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_TRANSFER,
            entityRef: $claveRastreo,
            state: 'INSTRUIDA',
            data: [
                'id_tx_aprovate'       => $txApproveId,
                'clave_rastreo_previa' => $claveRastreo,
                'account_number'       => $body['accountNumber'] ?? '',
                'amount'               => $amount,
                'concepto'             => $body['concepto'] ?? '',
                'referencia'           => $body['referencia'] ?? '',
                'name_beneficiary'     => $body['nameBeneficiary'] ?? '',
                'customer'             => $body['customer'] ?? '',
                'source'               => 'paymax',
                'created_at'           => date('c'),
            ],
        );

        // Auto-settle after a short delay to simulate SPEI processing
        $webhookUrl = $_ENV['BANKAOOL_WEBHOOK_URL'] ?? '';
        if ($webhookUrl) {
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $webhookUrl,
                payload: [
                    'id'                => (string) $txApproveId,
                    'amount'            => (float) $amount,
                    'clabeRastreo'      => $claveRastreo,
                    'reference'         => $body['referencia'] ?? '',
                    'accountBeneficiary'=> $body['accountNumber'] ?? '',
                    'nameOrdenante'     => 'ALFREDPAY SA DE CV',
                    'rfcCurpOrdenante'  => 'APY200101ABC',
                    'accountOrdenante'  => BankaoolFixtures::ALFREDPAY_ACCOUNT['clabe'],
                    'status'            => 'COMPLETED',
                    'failureReason'     => null,
                    'type'              => 'WITHDRAWAL',
                ],
                entityId: $entityId,
                delaySeconds: (int) ($_ENV['BANKAOOL_SETTLE_DELAY'] ?? 5),
            );
        }

        // Also auto-transition to settled
        EntityManager::transition(
            entityId: $entityId,
            newState: 'LIQUIDADA',
            triggerType: 'auto_settle',
            metadata: ['settled_at' => date('c')],
        );

        self::adjustBalance($namespace, -(float) $amount);

        $destAccount = $body['accountNumber'] ?? '?';
        error_log("[Bankaool/Paymax] Payment executed: {$claveRastreo} | MXN {$amount} -> {$destAccount}");

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'data' => [
                    'clave_rastreo_previa' => $claveRastreo,
                    'id_tx_aprovate'       => $txApproveId,
                    'amount'               => $amount,
                ],
            ],
        ];
    }

    /**
     * POST /paymax/customer-is-balance/create: Create a customer SRC account.
     */
    public static function createCustomerAccount(string $namespace, array $body): array
    {
        $customerId = $body['customer'] ?? 'cust-' . bin2hex(random_bytes(8));
        $clabe = BankaoolFixtures::generateClabe(BankaoolFixtures::STP_BANK_CODE);

        $entityId = EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_CUSTOMER,
            entityRef: $customerId,
            state: 'active',
            data: [
                'customer'   => $customerId,
                'email'      => $body['email'] ?? '',
                'name'       => $body['name'] ?? '',
                'curp'       => $body['curp'] ?? '',
                'clabe'      => $clabe,
                'balance'    => 0.00,
                'created_at' => date('c'),
            ],
        );

        $name = $body['name'] ?? '';

        return [
            'ok'     => true,
            'status' => 201,
            'body'   => [
                'data' => [
                    'customerId'    => $customerId,
                    'customer'      => $customerId,
                    'clabe'         => $clabe,
                    'name'          => $name,
                    'email'         => $body['email'] ?? '',
                    'balance'       => 0.00,
                    'accountHolder' => $name,
                    'account'       => 'CLABE',
                    'bankName'      => 'STP',
                    'metadata'      => BankaoolFixtures::stpMetadata(),
                ],
            ],
        ];
    }

    /**
     * GET /customer/customer-is-balance/account/{customer}: Get customer account.
     */
    public static function getCustomerAccount(string $namespace, string $customerId): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_CUSTOMER, $customerId);
        if (!$entity) {
            return [
                'ok'     => false,
                'status' => 404,
                'body'   => ['error' => true, 'message' => 'Customer not found'],
            ];
        }

        $d = $entity['data'];
        $name = $d['name'] ?? '';
        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'data' => [
                    'customerId'    => $d['customer'] ?? $customerId,
                    'customer'      => $d['customer'] ?? $customerId,
                    'clabe'         => $d['clabe'] ?? null,
                    'name'          => $name,
                    'email'         => $d['email'] ?? '',
                    'balance'       => $d['balance'] ?? 0.00,
                    'accountHolder' => $name,
                    'account'       => 'CLABE',
                    'bankName'      => 'STP',
                    'metadata'      => BankaoolFixtures::stpMetadata(),
                    'curp'          => $d['curp'] ?? '',
                    'created_at'    => $d['created_at'] ?? null,
                ],
            ],
        ];
    }

    /**
     * GET /customer/customer-is-balance/account-clabe/{clabe}: Get customer by CLABE.
     */
    public static function getCustomerByClabe(string $namespace, string $clabe): array
    {
        $customers = EntityManager::findAllByNamespace($namespace, self::ENTITY_CUSTOMER);
        foreach ($customers as $c) {
            if (($c['data']['clabe'] ?? '') === $clabe) {
                return [
                    'ok'     => true,
                    'status' => 200,
                    'body'   => $c['data'],
                ];
            }
        }

        return [
            'ok'     => false,
            'status' => 404,
            'body'   => ['error' => true, 'message' => 'Customer not found for CLABE'],
        ];
    }

    /**
     * POST /paymax/customer-is-balance/accredit-payment: Credit a customer.
     */
    public static function accreditPayment(string $namespace, array $body): array
    {
        $customerId = $body['customer'] ?? '';
        $amount = (float) ($body['amount'] ?? 0);

        $entity = EntityManager::find($namespace, self::ENTITY_CUSTOMER, $customerId);
        if (!$entity) {
            return [
                'ok'     => false,
                'status' => 404,
                'body'   => ['error' => true, 'message' => 'Customer not found'],
            ];
        }

        $newBalance = ($entity['data']['balance'] ?? 0) + $amount;
        EntityManager::updateData((int) $entity['id'], ['balance' => $newBalance]);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'customer' => $customerId,
                'amount'   => $amount,
                'balance'  => $newBalance,
            ],
        ];
    }

    /**
     * POST /paymax/customer-is-balance/debit-payment: Debit a customer.
     */
    public static function debitPayment(string $namespace, array $body): array
    {
        $customerId = $body['customer'] ?? '';
        $amount = (float) ($body['amount'] ?? 0);

        $entity = EntityManager::find($namespace, self::ENTITY_CUSTOMER, $customerId);
        if (!$entity) {
            return [
                'ok'     => false,
                'status' => 404,
                'body'   => ['error' => true, 'message' => 'Customer not found'],
            ];
        }

        $newBalance = ($entity['data']['balance'] ?? 0) - $amount;
        EntityManager::updateData((int) $entity['id'], ['balance' => $newBalance]);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'customer' => $customerId,
                'amount'   => $amount,
                'balance'  => $newBalance,
            ],
        ];
    }

    /**
     * POST /paymax/generate/deposit: Generate a deposit reference.
     *
     * Returns a CLABE and reference for the customer to send SPEI to.
     */
    public static function generateDeposit(string $namespace, array $body): array
    {
        $clabe = BankaoolFixtures::ALFREDPAY_ACCOUNT['clabe'];
        $reference = 'AP' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'clabe'          => $clabe,
                'reference'      => $reference,
                'account_holder' => 'ALFREDPAY SA DE CV',
                'bank_name'      => 'BANKAOOL',
                'amount'         => $body['amount'] ?? 0,
                'message'        => $body['message'] ?? 'AlfredPay',
            ],
        ];
    }

    /**
     * Simulate sending money TO the main CLABE (on-ramp).
     *
     * This is called from the browser UI when the user clicks "Send".
     * It creates a deposit entity and fires a Bankaool webhook to ramps-mexico.
     */
    public static function simulateInboundDeposit(string $namespace, array $body): array
    {
        $amount = (float) ($body['amount'] ?? 0);
        if ($amount <= 0) {
            return ['ok' => false, 'status' => 400, 'body' => ['error' => 'Amount must be positive']];
        }

        $senderClabe = $body['sender_clabe'] ?? BankaoolFixtures::generateClabe('012');
        $senderName = $body['sender_name'] ?? 'VIRTUAL SENDER';
        $senderRfc = $body['sender_rfc'] ?? 'XAXX010101000';
        $concepto = $body['concepto'] ?? 'Deposito SPEI';
        $referencia = $body['referencia'] ?? str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        $destinationClabe = $body['destination_clabe'] ?? BankaoolFixtures::ALFREDPAY_ACCOUNT['clabe'];

        $claveRastreo = BankaoolFixtures::generateClaveRastreo();
        $txId = (string) random_int(100000, 999999);

        $senderBank = BankaoolFixtures::lookupBank($senderClabe);
        $senderBankCode = $senderBank['codigo_banco'] ?? '012';
        $senderBankName = $senderBank['banco'] ?? 'BBVA MEXICO';

        $destBank = BankaoolFixtures::lookupBank($destinationClabe);
        $destBankCode = $destBank['codigo_banco'] ?? BankaoolFixtures::BANKAOOL_BANK_CODE;
        $destBankName = $destBank['banco'] ?? 'BANKAOOL';

        $entityId = EntityManager::create(
            namespace: $namespace,
            entityType: self::ENTITY_DEPOSIT,
            entityRef: $claveRastreo,
            state: 'LIQUIDADA',
            data: [
                'id_transaccion'          => $txId,
                'clave_rastreo'           => $claveRastreo,
                'monto'                   => number_format($amount, 2, '.', ''),
                'comision'                => '0.00',
                'tipo'                    => BankaoolFixtures::TYPE_INCREMENTO,
                'nombre_ordenante'        => $senderName,
                'institucion_ordenante'   => $senderBankCode,
                'cuenta_ordenante'        => $senderClabe,
                'rfc_curp_ordenante'      => $senderRfc,
                'nombre_beneficiario'     => 'ALFREDPAY SA DE CV',
                'institucion_beneficiario'=> $destBankCode,
                'cuenta_beneficiario'     => $destinationClabe,
                'concepto'                => $concepto,
                'referencia'              => $referencia,
                'estatus'                 => BankaoolFixtures::STATUS_LIQUIDADA,
                'created_at'              => date('c'),
            ],
        );

        self::adjustBalance($namespace, $amount);

        // webhookBankaoolDto format
        $bankaoolWebhookUrl = $_ENV['BANKAOOL_DEPOSIT_WEBHOOK_URL'] ?? '';
        if ($bankaoolWebhookUrl) {
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $bankaoolWebhookUrl,
                payload: [
                    'id_transaccion'          => $txId,
                    'nombre_componente'        => 'SPEI',
                    'monto'                    => number_format($amount, 2, '.', ''),
                    'comision'                 => '0.00',
                    'clave_rastreo'            => $claveRastreo,
                    'tipo'                     => BankaoolFixtures::TYPE_INCREMENTO,
                    'nombre_ordenante'         => $senderName,
                    'institucion_ordenante'    => $senderBankCode,
                    'cuenta_ordenante'         => $senderClabe,
                    'rfc_curp_ordenante'       => $senderRfc,
                    'nombre_beneficiario'      => 'ALFREDPAY SA DE CV',
                    'institucion_beneficiario' => $destBankCode,
                    'cuenta_beneficiario'      => $destinationClabe,
                    'concepto'                 => $concepto,
                    'referencia'               => $referencia,
                    'estatus'                  => BankaoolFixtures::STATUS_LIQUIDADA,
                ],
                entityId: $entityId,
                delaySeconds: (int) ($_ENV['BANKAOOL_DEPOSIT_DELAY'] ?? 2),
            );
        }

        // Also fire MXN-format webhook, webhookPaymentMxnPayload format
        $mxnWebhookUrl = $_ENV['BANKAOOL_MXN_WEBHOOK_URL'] ?? '';
        if ($mxnWebhookUrl) {
            CallbackScheduler::schedule(
                namespace: $namespace,
                targetUrl: $mxnWebhookUrl,
                payload: [
                    'id'                => $txId,
                    'amount'            => $amount,
                    'clabeRastreo'      => $claveRastreo,
                    'reference'         => $referencia,
                    'accountBeneficiary'=> $destinationClabe,
                    'nameOrdenante'     => $senderName,
                    'rfcCurpOrdenante'  => $senderRfc,
                    'accountOrdenante'  => $senderClabe,
                    'status'            => 'COMPLETED',
                    'failureReason'     => null,
                    'type'              => 'DEPOSIT',
                ],
                entityId: $entityId,
                delaySeconds: (int) ($_ENV['BANKAOOL_DEPOSIT_DELAY'] ?? 2),
            );
        }

        error_log("╔══════════════════════════════════════════════════╗");
        error_log("║  BANKAOOL INBOUND DEPOSIT");
        error_log("║  Clave Rastreo: {$claveRastreo}");
        error_log("║  Amount: MXN {$amount}");
        error_log("║  From: {$senderName} ({$senderClabe})");
        error_log("║  To: {$destinationClabe}");
        error_log("║  Concept: {$concepto}");
        error_log("╚══════════════════════════════════════════════════╝");

        return [
            'ok'     => true,
            'status' => 200,
            'body'   => [
                'deposit_id'    => $entityId,
                'clave_rastreo' => $claveRastreo,
                'amount'        => $amount,
                'status'        => 'LIQUIDADA',
                'webhook_sent'  => ($bankaoolWebhookUrl !== '' || $mxnWebhookUrl !== ''),
            ],
        ];
    }

    /**
     * Get all Bankaool transactions for the UI, both deposits and transfers.
     */
    public static function getTransactionHistory(string $namespace): array
    {
        $deposits = EntityManager::findAllByNamespace($namespace, self::ENTITY_DEPOSIT);
        $transfers = EntityManager::findAllByNamespace($namespace, self::ENTITY_TRANSFER);

        $all = [];

        foreach ($deposits as $d) {
            $all[] = [
                'id'             => $d['id'],
                'type'           => 'DEPOSIT',
                'direction'      => 'IN',
                'clave_rastreo'  => $d['entity_ref'],
                'amount'         => (float) ($d['data']['monto'] ?? 0),
                'sender'         => $d['data']['nombre_ordenante'] ?? '',
                'sender_clabe'   => $d['data']['cuenta_ordenante'] ?? '',
                'receiver'       => $d['data']['nombre_beneficiario'] ?? '',
                'receiver_clabe' => $d['data']['cuenta_beneficiario'] ?? '',
                'concepto'       => $d['data']['concepto'] ?? '',
                'referencia'     => $d['data']['referencia'] ?? '',
                'status'         => $d['state'],
                'created_at'     => $d['data']['created_at'] ?? $d['created_at'],
            ];
        }

        foreach ($transfers as $t) {
            $all[] = [
                'id'             => $t['id'],
                'type'           => 'TRANSFER',
                'direction'      => 'OUT',
                'clave_rastreo'  => $t['entity_ref'],
                'amount'         => (float) ($t['data']['importe'] ?? $t['data']['amount'] ?? 0),
                'sender'         => 'ALFREDPAY SA DE CV',
                'sender_clabe'   => BankaoolFixtures::ALFREDPAY_ACCOUNT['clabe'],
                'receiver'       => $t['data']['nombre_beneficiario'] ?? $t['data']['name_beneficiary'] ?? '',
                'receiver_clabe' => $t['data']['cuenta_destino'] ?? $t['data']['account_number'] ?? '',
                'concepto'       => $t['data']['concepto'] ?? '',
                'referencia'     => $t['data']['referencia'] ?? '',
                'status'         => $t['state'],
                'created_at'     => $t['data']['created_at'] ?? $t['created_at'],
            ];
        }

        usort($all, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $all;
    }

    /**
     * @internal
     */
    private static function getOrCreateMainAccount(string $namespace): array
    {
        $ref = 'alfredpay-main';
        $entity = EntityManager::find($namespace, self::ENTITY_ACCOUNT, $ref);

        if ($entity === null) {
            $entityId = EntityManager::create(
                namespace: $namespace,
                entityType: self::ENTITY_ACCOUNT,
                entityRef: $ref,
                state: 'ACTIVA',
                data: BankaoolFixtures::ALFREDPAY_ACCOUNT,
            );
            $entity = EntityManager::findById($entityId);
        }

        return $entity;
    }

    /**
     * @internal
     */
    private static function adjustBalance(string $namespace, float $amount): void
    {
        $account = self::getOrCreateMainAccount($namespace);
        $currentBalance = (float) ($account['data']['saldo'] ?? 0);
        $newBalance = $currentBalance + $amount;
        EntityManager::updateData((int) $account['id'], ['saldo' => round($newBalance, 2)]);
    }

    public static function getBalance(string $namespace): float
    {
        $account = self::getOrCreateMainAccount($namespace);
        return (float) ($account['data']['saldo'] ?? 0);
    }
}
