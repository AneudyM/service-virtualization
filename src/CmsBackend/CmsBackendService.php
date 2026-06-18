<?php

declare(strict_types=1);

namespace App\CmsBackend;

use App\Entity\EntityManager;

final class CmsBackendService
{
    private const ENTITY_STATE = 'cms_backend_state';
    private const STATE_REF = 'default';

    public static function getState(string $namespace): array
    {
        $entity = EntityManager::find($namespace, self::ENTITY_STATE, self::STATE_REF);

        if ($entity === null) {
            $entityId = EntityManager::create(
                namespace: $namespace,
                entityType: self::ENTITY_STATE,
                entityRef: self::STATE_REF,
                state: 'active',
                data: self::defaultState(),
            );
            $entity = EntityManager::findById($entityId);
        }

        return $entity['data'] ?? self::defaultState();
    }

    public static function reset(string $namespace): array
    {
        self::saveState($namespace, self::defaultState());
        return self::getState($namespace);
    }

    public static function replaceState(string $namespace, array $state): array
    {
        $defaults = self::defaultState();
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $state)) {
                $state[$key] = $value;
            }
        }

        self::saveState($namespace, $state);
        return self::getState($namespace);
    }

    public static function applyPreset(string $namespace, string $preset): ?array
    {
        $state = self::defaultState();

        if ($preset === 'local-balance-happy') {
            self::setAccountFeatures($state, 'local-balances@alfredpay.test', [
                'SEND_GLOBAL',
                'TRANSACTIONS_CUSTOMER',
                'SEND_MONEY',
                'ACCOUNTS_CUSTOMER',
                'TRADE_CUSTOMER',
                'CUSTOMERS',
                'COMPLIANCE',
                'DASHBOARD_CUSTOMER',
                'LOCAL_BALANCES',
            ]);
        } elseif ($preset === 'original-flow') {
            self::setAccountFeatures($state, 'original-flow@alfredpay.test', [
                'SEND_GLOBAL',
                'TRANSACTIONS_CUSTOMER',
                'SEND_MONEY',
                'ACCOUNTS_CUSTOMER',
                'TRADE_CUSTOMER',
                'CUSTOMERS',
                'COMPLIANCE',
                'METRICS',
            ]);
        } elseif ($preset === 'duplicate-dashboard') {
            self::setAccountFeatures($state, 'local-balances@alfredpay.test', [
                'SEND_GLOBAL',
                'TRANSACTIONS_CUSTOMER',
                'SEND_MONEY',
                'ACCOUNTS_CUSTOMER',
                'TRADE_CUSTOMER',
                'CUSTOMERS',
                'COMPLIANCE',
                'DASHBOARD_CUSTOMER',
                'METRICS',
                'LOCAL_BALANCES',
            ]);
        } elseif ($preset === 'no-recipients') {
            $state['fiatAccounts'] = [];
        } elseif ($preset === 'kyb-pending') {
            foreach ($state['accounts'] as &$account) {
                if ($account['email'] === 'local-balances@alfredpay.test') {
                    $account['user']['account']['status'] = 'pending';
                    $account['user']['client']['KYB'] = false;
                }
            }
        } else {
            return null;
        }

        self::saveState($namespace, $state);
        return self::getState($namespace);
    }

    public static function passwordlessLogin(array $body): array
    {
        return [
            'status' => 200,
            'body' => [
                'code' => '000000',
                'token' => 'cms-vrt-passwordless-token',
                'message' => 'Code sent to ' . ($body['email'] ?? 'mock user'),
            ],
        ];
    }

    public static function passwordLogin(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $email = strtolower((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

        foreach ($state['accounts'] as $account) {
            if (strtolower($account['email']) === $email && $account['password'] === $password) {
                return ['status' => 200, 'body' => ['accessToken' => $account['accessToken']]];
            }
        }

        return ['status' => 401, 'body' => ['message' => 'Invalid mock credentials']];
    }

    public static function tokenLogin(string $namespace): array
    {
        $state = self::getState($namespace);
        return ['status' => 200, 'body' => ['accessToken' => $state['accounts'][0]['accessToken']]];
    }

    public static function currentUser(string $namespace, ?string $authorization): array
    {
        $account = self::accountFromAuthorization($namespace, $authorization);
        return ['status' => 200, 'body' => $account['user']];
    }

    public static function getMainAccount(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $account = self::findMainAccount($state, $id) ?? $state['mainAccounts'][0];

        return ['status' => 200, 'body' => self::v2Envelope($account)];
    }

    public static function listMainAccounts(string $namespace): array
    {
        $state = self::getState($namespace);
        return [
            'status' => 200,
            'body' => self::v2Envelope($state['mainAccounts'], 'Success', self::meta(count($state['mainAccounts']))),
        ];
    }

    public static function getMainAccountBalance(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $account = self::findMainAccount($state, $id) ?? $state['mainAccounts'][0];
        return ['status' => 200, 'body' => self::balanceEnvelope($state, $account)];
    }

    public static function getMainAccountBalanceByCurrency(string $namespace, string $currency): array
    {
        $state = self::getState($namespace);
        $account = self::findMainAccountByCurrency($state, strtoupper($currency)) ?? $state['mainAccounts'][0];
        return ['status' => 200, 'body' => self::balanceEnvelope($state, $account)];
    }

    public static function createQuote(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $quote = self::buildQuote($body);
        array_unshift($state['quotes'], $quote);
        self::saveState($namespace, $state);

        return [
            'status' => 201,
            'body' => self::v2Envelope($quote, 'Quote created', null, $quote['externalReference'] ?? null),
        ];
    }

    public static function getQuote(string $namespace, string $idOrRef): array
    {
        $state = self::getState($namespace);
        $quote = self::findByKeys($state['quotes'], $idOrRef, ['quoteId', 'externalReference', 'id']);

        if (!$quote) {
            return ['status' => 404, 'body' => ['message' => 'Quote not found']];
        }

        return [
            'status' => 200,
            'body' => self::v2Envelope($quote, 'Quote created', null, $quote['externalReference'] ?? null),
        ];
    }

    public static function createInternalTransfer(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $transferId = self::id('transfer');
        $fromCountry = $body['metadata']['from'] ?? 'MX';
        $toCountry = $body['metadata']['to'] ?? 'AR';
        $transfer = [
            'transferId' => $transferId,
            'id' => $transferId,
            'businessId' => 1,
            'externalReference' => $body['externalReference'] ?? 'TR-' . date('YmdHis'),
            'fromAccountId' => $body['fromAccountId'] ?? 'main-account-mx',
            'toAccountId' => $body['toAccountId'] ?? 'main-account-ar',
            'quoteId' => $body['quoteId'] ?? 'quote-local-1',
            'fromAmount' => (string)($body['metadata']['amountFrom'] ?? '100.00'),
            'fromCurrency' => $fromCountry === 'AR' ? 'ARS' : 'MXN',
            'toAmount' => (string)($body['metadata']['amountTo'] ?? '4825.00'),
            'toCurrency' => $toCountry === 'MX' ? 'MXN' : 'ARS',
            'status' => 'COMPLETED',
            'createdAt' => date('c'),
            'completedTime' => date('c'),
            'metadata' => $body['metadata'] ?? [],
        ];

        array_unshift($state['transfers'], $transfer);
        self::saveState($namespace, $state);

        return [
            'status' => 201,
            'body' => self::v2Envelope($transfer, 'Transfer initiated', null, $transfer['externalReference']),
        ];
    }

    public static function getTransfer(string $namespace, string $idOrRef): array
    {
        $state = self::getState($namespace);
        $transfer = self::findByKeys($state['transfers'], $idOrRef, ['transferId', 'externalReference', 'id']);

        if (!$transfer) {
            return ['status' => 404, 'body' => ['message' => 'Transfer not found']];
        }

        return [
            'status' => 200,
            'body' => self::v2Envelope($transfer, 'Success', null, $transfer['externalReference'] ?? null),
        ];
    }

    public static function listTransfers(string $namespace, array $query): array
    {
        $state = self::getState($namespace);
        $items = self::filterByQuery($state['transfers'], $query, ['status', 'fromAccountId', 'toAccountId']);

        return ['status' => 200, 'body' => self::v2List($items, $query)];
    }

    public static function updateTransfer(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $id = $body['transferId'] ?? $body['id'] ?? '';

        foreach ($state['transfers'] as &$transfer) {
            if (($transfer['transferId'] ?? '') === $id || ($transfer['id'] ?? '') === $id) {
                $transfer = array_merge($transfer, $body);
                self::saveState($namespace, $state);
                return ['status' => 200, 'body' => self::v2Envelope($transfer)];
            }
        }

        return ['status' => 404, 'body' => ['message' => 'Transfer not found']];
    }

    public static function createPayin(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $payin = self::buildPayin($body);
        array_unshift($state['payins'], $payin);
        self::saveState($namespace, $state);

        return ['status' => 200, 'body' => self::v2Envelope($payin)];
    }

    public static function getPayin(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $payin = self::findByKeys($state['payins'], $id, ['payinId', 'id', 'requestId', 'systemReference']);

        if (!$payin) {
            return ['status' => 404, 'body' => ['message' => 'Payin not found']];
        }

        return ['status' => 200, 'body' => self::v2Envelope($payin)];
    }

    public static function listPayins(string $namespace, array $query): array
    {
        $state = self::getState($namespace);
        $items = self::filterByQuery($state['payins'], $query, ['status', 'currency', 'customerId']);
        return ['status' => 200, 'body' => self::v2List($items, $query)];
    }

    public static function createPayout(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $payout = self::buildPayout($body);
        array_unshift($state['payouts'], $payout);
        self::saveState($namespace, $state);

        return ['status' => 201, 'body' => self::v2Envelope($payout, 'Payout initiated')];
    }

    public static function getPayout(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $payout = self::findByKeys($state['payouts'], $id, ['payoutId', 'id', 'requestReferenceId', 'paymentReferenceId']);

        if (!$payout) {
            return ['status' => 404, 'body' => ['message' => 'Payout not found']];
        }

        return ['status' => 200, 'body' => self::v2Envelope($payout)];
    }

    public static function listPayouts(string $namespace, array $query): array
    {
        $state = self::getState($namespace);
        $items = self::filterByQuery($state['payouts'], $query, ['status', 'currency', 'transactionType']);
        return ['status' => 200, 'body' => self::v2List($items, $query)];
    }

    public static function getVirtualAccountDeposit(string $namespace, string $country, string $vaId): array
    {
        $state = self::getState($namespace);
        $country = strtoupper($country);
        $account = $country === 'AR'
            ? self::findMainAccountByCurrency($state, 'ARS')
            : self::findMainAccountByCurrency($state, 'MXN');

        if ($country === 'AR') {
            return [
                'status' => 200,
                'body' => [
                    'statusCode' => '1000',
                    'statusDescription' => 'Virtual account deposit successfully.',
                    'externalReference' => $vaId,
                    'data' => [
                        'accountNumber' => $account['bankAccount']['accountNumber'] ?? '0000003100012345678901',
                        'cvu' => $account['bankAccount']['accountNumber'] ?? '0000003100012345678901',
                        'alias' => $account['bankAccount']['alias'] ?? 'alfred.local.ars',
                        'bankName' => $account['bankAccount']['bankName'] ?? 'Coinag',
                        'accountHolder' => $account['accountHolderName'] ?? 'AlfredPay Local Balances',
                        'currency' => 'ARS',
                        'customerId' => 'local-balances-owner',
                    ],
                ],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'statusCode' => '1000',
                'statusDescription' => 'Virtual account deposit successfully.',
                'externalReference' => $vaId,
                'data' => [
                    'accountNumber' => $account['bankAccount']['accountNumber'] ?? '646180123400000001',
                    'clabe' => $account['bankAccount']['accountNumber'] ?? '646180123400000001',
                    'bankName' => $account['bankAccount']['bankName'] ?? 'STP',
                    'accountHolder' => $account['accountHolderName'] ?? 'AlfredPay Local Balances',
                    'currency' => 'MXN',
                    'customerId' => 'local-balances-owner',
                ],
            ],
        ];
    }

    public static function listTransactions(string $namespace, array $query): array
    {
        $state = self::getState($namespace);
        $items = self::filterByQuery($state['transactions'], $query, ['status', 'type', 'transactionType']);

        return [
            'status' => 200,
            'body' => [
                'page' => (int)($query['page'] ?? 1),
                'pageSize' => (int)($query['pageSize'] ?? 10),
                'result' => $items,
                'total' => count($items),
            ],
        ];
    }

    public static function findTransaction(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $transaction = self::findByKeys($state['transactions'], $id, ['transactionId', 'id']);
        return ['status' => 200, 'body' => ['transaction' => $transaction ?? $state['transactions'][0]]];
    }

    public static function transactionLogs(string $namespace, string $id): array
    {
        return [
            'status' => 200,
            'body' => [
                'logs' => [
                    [
                        'id' => self::id('log'),
                        'process' => 'CMS_BACKEND_VIRTUAL',
                        'method' => 'GET',
                        'createdAt' => date('c'),
                        'error' => null,
                        'message' => 'Virtual transaction lookup',
                        'description' => 'Generated by service-virtualization CMS backend mock for ' . $id,
                    ],
                ],
            ],
        ];
    }

    public static function createTransaction(string $namespace, string $type, array $body): array
    {
        $state = self::getState($namespace);
        $quote = self::findByKeys($state['quotes'], (string)($body['quoteId'] ?? ''), ['quoteId', 'id']) ?? self::buildQuote([]);
        $transactionId = self::id('tx');
        $transaction = [
            'transactionId' => $transactionId,
            'id' => $transactionId,
            'customerId' => $body['customerId'] ?? 'local-balances-owner',
            'createdAt' => date('c'),
            'updatedAt' => date('c'),
            'fromCurrency' => $quote['fromCurrency'],
            'fromAmount' => $quote['fromAmount'],
            'toCurrency' => $quote['toCurrency'],
            'toAmount' => $quote['toAmount'],
            'chain' => $quote['chain'] ?? 'ETH',
            'status' => 'CREATED',
            'typeTx' => $type === 'transfer' || $type === 'payment' ? 'Fiat' : 'Digital',
            'type' => strtoupper($type),
            'quote' => $quote,
            'quoteId' => $quote['quoteId'],
            'fiatAccountId' => $body['fiatAccountId'] ?? $body['recipientId'] ?? null,
            'liquidationAddressId' => $body['liquidationAddressId'] ?? null,
            'originAddress' => $body['originAddress'] ?? null,
        ];

        array_unshift($state['transactions'], $transaction);
        self::saveState($namespace, $state);

        return ['status' => 200, 'body' => ['transaction' => $transaction]];
    }

    public static function listFiatAccounts(string $namespace, array $query, ?string $customerId = null): array
    {
        $state = self::getState($namespace);
        $items = $state['fiatAccounts'];

        if ($customerId !== null) {
            $items = array_values(array_filter($items, fn(array $item) => ($item['customerId'] ?? 'local-balances-owner') === $customerId));
        }
        if (!empty($query['currency'])) {
            $currency = strtoupper((string)$query['currency']);
            $items = array_values(array_filter($items, fn(array $item) => ($item['currency'] ?? '') === $currency));
        }
        if (array_key_exists('isExternal', $query)) {
            $isExternal = filter_var($query['isExternal'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isExternal !== null) {
                $items = array_values(array_filter($items, fn(array $item) => (bool)($item['isExternal'] ?? false) === $isExternal));
            }
        }

        if ($customerId !== null) {
            return ['status' => 200, 'body' => ['result' => $items, 'total' => count($items)]];
        }

        return [
            'status' => 200,
            'body' => [
                'page' => (int)($query['page'] ?? 1),
                'pageSize' => (string)($query['pageSize'] ?? '10'),
                'result' => $items,
                'total' => count($items),
            ],
        ];
    }

    public static function createFiatAccount(string $namespace, array $body, ?string $customerId = null): array
    {
        $state = self::getState($namespace);
        $data = $body['data'] ?? [];
        $fields = $data['fiatAccountFields'] ?? [];
        $id = self::id('fiat');
        $currency = self::currencyFromFiatType((string)($data['type'] ?? 'SPEI_MEX'));
        $accountName = $fields['accountName'] ?? $fields['accountHolderName'] ?? $data['accountName'] ?? 'New recipient';
        $accountNumber = $fields['accountNumber'] ?? $fields['clabe'] ?? $fields['cvu'] ?? '000000000000000000';

        $fiatAccount = [
            'id' => $id,
            'fiatAccountId' => $id,
            'customerId' => $customerId ?? $data['customerId'] ?? 'local-balances-owner',
            'type' => $data['type'] ?? ($currency === 'ARS' ? 'CVU_ARG' : 'SPEI_MEX'),
            'currency' => $currency,
            'country' => $currency === 'ARS' ? 'AR' : 'MX',
            'default' => false,
            'isExternal' => $data['isExternal'] ?? true,
            'accountName' => $accountName,
            'name' => $accountName,
            'accountNumber' => $accountNumber,
            'fiatAccountFields' => array_merge([
                'accountNumber' => $accountNumber,
                'accountType' => $fields['accountType'] ?? ($currency === 'ARS' ? 'CVU' : 'CLABE'),
                'accountName' => $accountName,
            ], $fields),
            'metadata' => [
                'accountHolderName' => $accountName,
                'typeCustomer' => 'BUSINESS',
                'bankCountry' => $currency === 'ARS' ? 'AR' : 'MX',
                'bankName' => $currency === 'ARS' ? 'Coinag' : 'STP',
            ],
            'createdAt' => date('c'),
        ];

        array_unshift($state['fiatAccounts'], $fiatAccount);
        self::saveState($namespace, $state);

        return ['status' => 200, 'body' => ['fiatAccount' => $fiatAccount] + $fiatAccount];
    }

    public static function setDefaultFiatAccount(string $namespace, string $fiatAccountId, ?string $customerId = null): array
    {
        $state = self::getState($namespace);
        $selected = null;

        foreach ($state['fiatAccounts'] as &$account) {
            if ($customerId === null || ($account['customerId'] ?? null) === $customerId) {
                $account['default'] = ($account['id'] ?? '') === $fiatAccountId || ($account['fiatAccountId'] ?? '') === $fiatAccountId;
                if ($account['default']) {
                    $selected = $account;
                }
            }
        }

        self::saveState($namespace, $state);
        return ['status' => 200, 'body' => ['fiatAccount' => $selected]];
    }

    public static function deleteFiatAccount(string $namespace, string $fiatAccountId, ?string $customerId = null): array
    {
        $state = self::getState($namespace);
        $before = count($state['fiatAccounts']);
        $state['fiatAccounts'] = array_values(array_filter($state['fiatAccounts'], function (array $account) use ($fiatAccountId, $customerId): bool {
            $idMatches = ($account['id'] ?? '') === $fiatAccountId || ($account['fiatAccountId'] ?? '') === $fiatAccountId;
            $customerMatches = $customerId === null || ($account['customerId'] ?? null) === $customerId;
            return !($idMatches && $customerMatches);
        }));
        self::saveState($namespace, $state);

        return [
            'status' => 200,
            'body' => [
                'success' => count($state['fiatAccounts']) < $before,
                'deletedId' => $fiatAccountId,
            ],
        ];
    }

    public static function listLiquidationAddresses(string $namespace, array $query): array
    {
        $state = self::getState($namespace);
        return [
            'status' => 200,
            'body' => [
                'page' => (int)($query['page'] ?? 1),
                'pageSize' => (int)($query['limit'] ?? 10),
                'total' => count($state['liquidationAddresses']),
                'result' => $state['liquidationAddresses'],
            ],
        ];
    }

    public static function createLiquidationAddress(string $namespace, array $body): array
    {
        $state = self::getState($namespace);
        $address = [
            'id' => self::id('wallet'),
            'currency' => $body['currency'] ?? 'USDC',
            'address' => $body['address'] ?? '0x' . bin2hex(random_bytes(20)),
            'chain' => $body['chain'] ?? 'ETH',
            'default' => false,
            'createdAt' => date('c'),
        ];
        array_unshift($state['liquidationAddresses'], $address);
        self::saveState($namespace, $state);
        return ['status' => 200, 'body' => $address];
    }

    public static function setDefaultLiquidationAddress(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        foreach ($state['liquidationAddresses'] as &$address) {
            $address['default'] = ($address['id'] ?? '') === $id;
        }
        self::saveState($namespace, $state);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public static function deleteLiquidationAddress(string $namespace, string $id): array
    {
        $state = self::getState($namespace);
        $state['liquidationAddresses'] = array_values(array_filter(
            $state['liquidationAddresses'],
            fn(array $address): bool => ($address['id'] ?? '') !== $id,
        ));
        self::saveState($namespace, $state);
        return ['status' => 200, 'body' => ['ok' => true]];
    }

    public static function emptyList(): array
    {
        return ['status' => 200, 'body' => ['page' => 1, 'pageSize' => 10, 'result' => [], 'total' => 0]];
    }

    public static function rolesAllowed(): array
    {
        return ['status' => 200, 'body' => ['ADMIN', 'OWNER', 'VIEWER']];
    }

    public static function clients(string $namespace): array
    {
        $state = self::getState($namespace);
        return [
            'status' => 200,
            'body' => array_map(fn(array $account): array => $account['user']['client'], $state['accounts']),
        ];
    }

    public static function profiles(): array
    {
        return ['status' => 200, 'body' => []];
    }

    public static function apiKeys(): array
    {
        return ['status' => 200, 'body' => []];
    }

    private static function saveState(string $namespace, array $state): void
    {
        $entity = EntityManager::find($namespace, self::ENTITY_STATE, self::STATE_REF);

        if ($entity === null) {
            EntityManager::create($namespace, self::ENTITY_STATE, self::STATE_REF, 'active', $state);
            return;
        }

        EntityManager::updateData((int)$entity['id'], $state);
    }

    private static function accountFromAuthorization(string $namespace, ?string $authorization): array
    {
        $state = self::getState($namespace);
        $token = preg_replace('/^Bearer\s+/i', '', (string)$authorization);

        foreach ($state['accounts'] as $account) {
            if ($account['accessToken'] === $token) {
                return $account;
            }
        }

        return $state['accounts'][0];
    }

    private static function defaultState(): array
    {
        $sharedFeatures = [
            'SEND_GLOBAL',
            'TRANSACTIONS_CUSTOMER',
            'SEND_MONEY',
            'ACCOUNTS_CUSTOMER',
            'TRADE_CUSTOMER',
            'CUSTOMERS',
            'COMPLIANCE',
        ];

        return [
            'accounts' => [
                [
                    'email' => 'local-balances@alfredpay.test',
                    'password' => 'password123',
                    'accessToken' => 'local-balances-service-virtualization-token',
                    'user' => [
                        'id' => 'local-balances-user',
                        'sub' => 'local-balances-user',
                        'firstName' => 'Local',
                        'lastName' => 'Balances',
                        'fullname' => 'Local Balances',
                        'email' => 'local-balances@alfredpay.test',
                        'client_id' => 'local-balances-client',
                        'clientId' => 'local-balances-client',
                        'ownerId' => 'local-balances-owner',
                        'businessId' => 'local-balances-business',
                        'mainAccount' => true,
                        'roles' => ['ADMIN', 'OWNER'],
                        'disabledFeatures' => [],
                        'features' => array_merge($sharedFeatures, ['DASHBOARD_CUSTOMER', 'LOCAL_BALANCES']),
                        'mfa' => false,
                        'client' => [
                            'id' => 'local-balances-client',
                            'key' => 'xtransfer',
                            'name' => 'AlfredPay Local Balances',
                            'KYB' => true,
                            'mainAccount' => true,
                        ],
                        'account' => [
                            'customerId' => 'local-balances-owner',
                            'country' => 'MX',
                            'businessName' => 'AlfredPay Local Balances',
                            'fullname' => 'AlfredPay Local Balances',
                            'taxId' => 'APX260618TST',
                            'status' => 'approved',
                            'updatedAt' => date('c'),
                        ],
                    ],
                ],
                [
                    'email' => 'original-flow@alfredpay.test',
                    'password' => 'password123',
                    'accessToken' => 'original-flow-service-virtualization-token',
                    'user' => [
                        'id' => 'original-flow-user',
                        'sub' => 'original-flow-user',
                        'firstName' => 'Original',
                        'lastName' => 'Flow',
                        'fullname' => 'Original Flow',
                        'email' => 'original-flow@alfredpay.test',
                        'client_id' => 'original-flow-client',
                        'clientId' => 'original-flow-client',
                        'ownerId' => 'original-flow-owner',
                        'businessId' => 'original-flow-business',
                        'roles' => ['ADMIN', 'OWNER'],
                        'disabledFeatures' => [],
                        'features' => array_merge($sharedFeatures, ['METRICS']),
                        'mfa' => false,
                        'client' => [
                            'id' => 'original-flow-client',
                            'key' => 'alfred-cms',
                            'name' => 'AlfredPay Original Flow',
                            'KYB' => true,
                        ],
                        'account' => [
                            'customerId' => 'original-flow-owner',
                            'country' => 'MX',
                            'businessName' => 'Original Flow',
                            'fullname' => 'Original Flow',
                            'status' => 'approved',
                            'updatedAt' => date('c'),
                        ],
                    ],
                ],
            ],
            'mainAccounts' => [
                self::mainAccount('main-account-mx', 'MXN', 'Mexico Account', 'STP', '646180123400000001'),
                self::mainAccount('main-account-ar', 'ARS', 'Argentina Account', 'Coinag', '0000003100012345678901', ['alias' => 'alfred.local.ars']),
                self::mainAccount('main-account-usd', 'USD', 'USD Account', 'Erebor', '284300194837'),
            ],
            'balances' => [
                'main-account-mx' => '1250000.75',
                'main-account-ar' => '9876543.21',
                'main-account-usd' => '12500.00',
            ],
            'fiatAccounts' => [
                self::fiatAccount('recipient-mx-business-1', 'Grupo Norte MXN', '646180000000000123', 'MXN', true),
                self::fiatAccount('recipient-mx-person-2', 'Ana Mercado', '646180000000000456', 'MXN', false),
                self::fiatAccount('recipient-ar-business-1', 'Comercio Sur ARS', '0000003100012345678001', 'ARS', true),
            ],
            'quotes' => [self::buildQuote(['externalReference' => 'Q-LOCAL-1', 'fromCurrency' => 'MXN', 'toCurrency' => 'ARS', 'fromAmount' => '100.00'])],
            'payins' => [
                self::buildPayin(['requestId' => 'PAY-IN-LOCAL-1', 'amount' => '100.00', 'createdAt' => '2026-06-17T14:30:00.000Z']),
                self::buildPayin(['requestId' => 'PAY-IN-LOCAL-2', 'amount' => '100.00', 'createdAt' => '2026-06-17T12:20:00.000Z']),
                self::buildPayin(['requestId' => 'PAY-IN-LOCAL-3', 'amount' => '100.00', 'createdAt' => '2026-06-17T10:10:00.000Z']),
                self::buildPayin(['requestId' => 'PAY-IN-LOCAL-4', 'amount' => '100.00', 'createdAt' => '2026-06-15T18:45:00.000Z']),
            ],
            'payouts' => [self::buildPayout(['requestReferenceId' => 'PAYOUT-LOCAL-1', 'amount' => '250.00'])],
            'transfers' => [
                [
                    'transferId' => 'transfer-local-1',
                    'id' => 'transfer-local-1',
                    'businessId' => 1,
                    'externalReference' => 'TR-LOCAL-1',
                    'fromAccountId' => 'main-account-mx',
                    'toAccountId' => 'main-account-ar',
                    'quoteId' => 'quote-local-1',
                    'fromAmount' => '100.00',
                    'fromCurrency' => 'MXN',
                    'toAmount' => '4825.00',
                    'toCurrency' => 'ARS',
                    'status' => 'COMPLETED',
                    'createdAt' => '2026-06-18T09:15:00.000Z',
                    'completedTime' => '2026-06-18T09:16:00.000Z',
                ],
            ],
            'liquidationAddresses' => [
                [
                    'id' => 'wallet-local-1',
                    'currency' => 'USDC',
                    'address' => '0x742d35Cc6634C0532925a3b844Bc454e4438f44e',
                    'chain' => 'ETH',
                    'default' => true,
                    'createdAt' => '2026-06-18T09:00:00.000Z',
                ],
                [
                    'id' => 'wallet-local-2',
                    'currency' => 'USDT',
                    'address' => '0x8ba1f109551bD432803012645Ac136ddd64DBA72',
                    'chain' => 'POLYGON',
                    'default' => false,
                    'createdAt' => '2026-06-18T09:00:00.000Z',
                ],
            ],
            'transactions' => [
                [
                    'transactionId' => 'tx-local-1',
                    'id' => 'tx-local-1',
                    'customerId' => 'local-balances-owner',
                    'createdAt' => date('c'),
                    'updatedAt' => date('c'),
                    'fromCurrency' => 'MXN',
                    'fromAmount' => '1000.00',
                    'toCurrency' => 'USDC',
                    'toAmount' => '52.86',
                    'chain' => 'ETH',
                    'status' => 'COMPLETED',
                    'typeTx' => 'Digital',
                    'type' => 'ONRAMP',
                    'toAddress' => ['id' => 'wallet-local-1', 'value' => '0x742d35Cc6634C0532925a3b844Bc454e4438f44e'],
                    'quote' => self::buildQuote(['fromCurrency' => 'MXN', 'toCurrency' => 'USDC', 'fromAmount' => '1000.00']),
                ],
                [
                    'transactionId' => 'tx-local-2',
                    'id' => 'tx-local-2',
                    'customerId' => 'local-balances-owner',
                    'createdAt' => date('c', strtotime('-1 day')),
                    'updatedAt' => date('c', strtotime('-1 day')),
                    'fromCurrency' => 'USDC',
                    'fromAmount' => '50.00',
                    'toCurrency' => 'MXN',
                    'toAmount' => '946.00',
                    'chain' => 'ETH',
                    'status' => 'PENDING',
                    'typeTx' => 'Digital',
                    'type' => 'OFFRAMP',
                    'fiatAccountId' => 'recipient-mx-business-1',
                    'quote' => self::buildQuote(['fromCurrency' => 'USDC', 'toCurrency' => 'MXN', 'fromAmount' => '50.00']),
                ],
            ],
        ];
    }

    private static function mainAccount(string $id, string $currency, string $displayName, string $bankName, string $accountNumber, array $extraBank = []): array
    {
        return [
            'accountId' => $id,
            'id' => $id,
            'customerId' => 'local-balances-owner',
            'currency' => $currency,
            'status' => 'ACTIVE',
            'displayName' => $displayName,
            'accountHolderName' => 'AlfredPay Local Balances',
            'capabilities' => [
                'prefund' => true,
                'payout' => true,
                'withdrawal' => true,
                'fx' => true,
            ],
            'bankAccount' => array_merge([
                'bankName' => $bankName,
                'bankCode' => $currency === 'MXN' ? '646' : '000',
                'branchCode' => '001',
                'accountNumber' => $accountNumber,
                'bankAddress' => $currency === 'ARS' ? 'Buenos Aires, Argentina' : 'Mexico City, Mexico',
            ], $extraBank),
            'metadata' => [],
            'createdAt' => '2026-03-04T19:22:20.460Z',
        ];
    }

    private static function fiatAccount(string $id, string $name, string $accountNumber, string $currency, bool $isBusiness): array
    {
        return [
            'id' => $id,
            'fiatAccountId' => $id,
            'customerId' => 'local-balances-owner',
            'type' => $currency === 'ARS' ? 'CVU_ARG' : 'SPEI_MEX',
            'currency' => $currency,
            'country' => $currency === 'ARS' ? 'AR' : 'MX',
            'default' => $id === 'recipient-mx-business-1',
            'isExternal' => true,
            'accountName' => $name,
            'name' => $name,
            'accountNumber' => $accountNumber,
            'fiatAccountFields' => [
                'accountNumber' => $accountNumber,
                'accountType' => $currency === 'ARS' ? 'CVU' : 'CLABE',
                'accountName' => $name,
            ],
            'metadata' => [
                'accountHolderName' => $name,
                'typeCustomer' => $isBusiness ? 'BUSINESS' : 'INDIVIDUAL',
                'bankCountry' => $currency === 'ARS' ? 'AR' : 'MX',
                'bankName' => $currency === 'ARS' ? 'Coinag' : 'STP',
            ],
            'createdAt' => '2026-06-18T09:00:00.000Z',
        ];
    }

    private static function buildQuote(array $body): array
    {
        $fromCurrency = strtoupper((string)($body['fromCurrency'] ?? 'MXN'));
        $toCurrency = strtoupper((string)($body['toCurrency'] ?? 'USDC'));
        $rate = match ($fromCurrency . '_' . $toCurrency) {
            'MXN_ARS' => 48.25,
            'ARS_MXN' => 0.0207,
            'USDC_MXN' => 18.92,
            'MXN_USDC' => 0.05286,
            default => 1.0,
        };
        $fromAmount = (float)($body['fromAmount'] ?? (($body['toAmount'] ?? null) ? (float)$body['toAmount'] / $rate : 1000));
        $toAmount = (float)($body['toAmount'] ?? ($fromAmount * $rate));

        return [
            'quoteId' => $body['quoteId'] ?? 'quote-' . substr(hash('sha1', json_encode($body) . microtime()), 0, 12),
            'id' => $body['id'] ?? 'quote-local-1',
            'externalReference' => $body['externalReference'] ?? 'Q-' . date('YmdHis'),
            'fromCurrency' => $fromCurrency,
            'toCurrency' => $toCurrency,
            'fromAmount' => number_format($fromAmount, 2, '.', ''),
            'toAmount' => number_format($toAmount, 4, '.', ''),
            'expiration' => date('c', strtotime('+5 minutes')),
            'rate' => number_format($rate, 6, '.', ''),
            'chain' => $body['chain'] ?? 'ETH',
            'status' => 'CREATED',
            'fees' => [
                ['type' => 'crossBorderFee', 'amount' => '0.00', 'currency' => $fromCurrency],
            ],
        ];
    }

    private static function buildPayin(array $body): array
    {
        $amount = number_format((float)($body['amount'] ?? 100), 2, '.', '');
        $createdAt = $body['createdAt'] ?? date('c');
        $payinId = $body['payinId'] ?? ($body['id'] ?? self::id('payin'));

        return [
            'payinId' => $payinId,
            'id' => $body['id'] ?? $payinId,
            'businessId' => 1,
            'customerId' => $body['customerId'] ?? 'local-balances-owner',
            'notificationType' => 'DEPOSIT',
            'requestId' => $body['requestId'] ?? 'PAY-IN-' . date('YmdHis'),
            'systemReference' => $body['systemReference'] ?? '20260618' . random_int(100000, 999999),
            'currency' => $body['currency'] ?? 'MXN',
            'amount' => $amount,
            'paymentRail' => ['type' => 'SPEI', 'name' => 'SPEI'],
            'debtorDetail' => [
                'name' => 'XTRANSFER',
                'accountIdentifier' => ['type' => 'CLABE', 'value' => '646180000000000999'],
                'bankDetail' => ['name' => 'STP', 'identifier' => ['clabe' => '646180000000000999']],
            ],
            'creditorDetail' => [
                'name' => 'AlfredPay Local Balances',
                'accountIdentifier' => ['type' => 'CLABE', 'value' => '646180123400000001'],
                'bankDetail' => ['name' => 'STP', 'identifier' => ['clabe' => '646180123400000001']],
            ],
            'remittanceInformation' => 'Local mock deposit',
            'paymentSettledDateTime' => $createdAt,
            'status' => $body['status'] ?? 'SETTLED',
            'createdAt' => $createdAt,
        ];
    }

    private static function buildPayout(array $body): array
    {
        $amount = number_format((float)($body['amount'] ?? 250), 2, '.', '');
        $payoutId = $body['payoutId'] ?? ($body['id'] ?? self::id('payout'));

        return [
            'payoutId' => $payoutId,
            'id' => $body['id'] ?? $payoutId,
            'businessId' => 1,
            'requestReferenceId' => $body['requestReferenceId'] ?? 'PAYOUT-' . date('YmdHis'),
            'paymentReferenceId' => $body['paymentReferenceId'] ?? 'SPEI-' . random_int(100000, 999999),
            'transactionType' => $body['transactionType'] ?? 'B2C',
            'currency' => $body['currency'] ?? 'MXN',
            'amount' => $amount,
            'paymentRail' => ['type' => 'SPEI', 'name' => 'SPEI'],
            'payerDetail' => [
                'payerAccountNumber' => '646180123400000001',
                'payerName' => 'AlfredPay Local Balances',
            ],
            'beneficiaryDetail' => $body['beneficiaryDetail'] ?? [
                'name' => 'Grupo Norte MXN',
                'accountIdentifier' => ['type' => 'CLABE', 'value' => '646180000000000123'],
                'bankDetail' => ['name' => 'STP', 'identifier' => ['clabe' => '646180000000000123']],
            ],
            'status' => $body['status'] ?? 'PROCESSING',
            'createdAt' => $body['createdAt'] ?? '2026-06-18T13:00:00.000Z',
        ];
    }

    private static function setAccountFeatures(array &$state, string $email, array $features): void
    {
        foreach ($state['accounts'] as &$account) {
            if ($account['email'] === $email) {
                $account['user']['features'] = $features;
            }
        }
    }

    private static function v2Envelope(array $data, string $description = 'Success', ?array $meta = null, ?string $externalReference = null): array
    {
        $body = [
            'statusCode' => '1000',
            'statusDescription' => $description,
            'data' => $data,
        ];

        if ($externalReference !== null) {
            $body['externalReference'] = $externalReference;
        }
        if ($meta !== null) {
            $body['meta'] = $meta;
        }

        return $body;
    }

    private static function v2List(array $items, array $query): array
    {
        $page = max(1, (int)($query['page'] ?? 1));
        $limit = max(1, (int)($query['limit'] ?? $query['pageSize'] ?? 20));
        $total = count($items);
        $offset = ($page - 1) * $limit;

        return self::v2Envelope(array_slice($items, $offset, $limit), 'Success', self::meta($total, $page, $limit));
    }

    private static function meta(int $total, int $page = 1, int $limit = 20): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => (int)ceil($total / max(1, $limit)),
        ];
    }

    private static function balanceEnvelope(array $state, array $account): array
    {
        $accountId = $account['accountId'] ?? $account['id'];

        return self::v2Envelope([
            'accountId' => $accountId,
            'currency' => $account['currency'],
            'available' => $state['balances'][$accountId] ?? '0.00',
            'pending' => '0.00',
            'blocked' => '0.00',
            'backingAsset' => 'FIAT',
            'asOf' => date('c'),
        ]);
    }

    private static function findMainAccount(array $state, string $id): ?array
    {
        foreach ($state['mainAccounts'] as $account) {
            if (($account['accountId'] ?? '') === $id || ($account['id'] ?? '') === $id || ($account['customerId'] ?? '') === $id) {
                return $account;
            }
        }

        return null;
    }

    private static function findMainAccountByCurrency(array $state, string $currency): ?array
    {
        foreach ($state['mainAccounts'] as $account) {
            if (($account['currency'] ?? '') === $currency) {
                return $account;
            }
        }

        return null;
    }

    private static function findByKeys(array $items, string $value, array $keys): ?array
    {
        foreach ($items as $item) {
            foreach ($keys as $key) {
                if (($item[$key] ?? null) === $value) {
                    return $item;
                }
            }
        }

        return null;
    }

    private static function filterByQuery(array $items, array $query, array $keys): array
    {
        return array_values(array_filter($items, function (array $item) use ($query, $keys): bool {
            foreach ($keys as $key) {
                if (isset($query[$key]) && $query[$key] !== '' && (string)($item[$key] ?? '') !== (string)$query[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }

    private static function currencyFromFiatType(string $type): string
    {
        if (str_contains($type, 'ARG') || str_contains($type, 'CVU')) {
            return 'ARS';
        }

        return 'MXN';
    }

    private static function id(string $prefix): string
    {
        return $prefix . '-' . substr(bin2hex(random_bytes(8)), 0, 12);
    }
}
