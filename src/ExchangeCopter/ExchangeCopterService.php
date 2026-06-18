<?php

declare(strict_types=1);

namespace App\ExchangeCopter;

use App\Entity\EntityManager;

/**
 * Virtual ExchangeCopter (api.exchangecopter.com): TotalPay-side CVU provisioning.
 * Called outbound by `microserivces-argentina-payex` via env `URL_COPTER`.
 *
 * Endpoints mirrored (just the ones the AR create / alias flow hits today):
 *   GET  /login                         : auth, returns { token }
 *   POST /creacionCVUConRegistroWallets : RESIDENT create → { proveedor: { idUsuario, cvu, alias } }
 *   PUT  /creacionAlias                 : mutate alias
 *   POST /devolucionCVUonPayexAlfred    : NOTRESIDENT create → { firestore: { id }, cvu, alias }
 *   GET  /checkCBUALIAS?aliasOcvu=…     : alias lookup by CVU
 *
 * Deterministic output: same input UUID always yields the same CVU+alias so test
 * evidence is stable across runs. Stateless; if we later need create-then-read
 * continuity (e.g., an alias update followed by a lookup), swap to EntityManager.
 */
final class ExchangeCopterService
{
    public const ENTITY_CVU = 'exchangecopter_cvu';
    public const ENTITY_SCENARIO = 'exchangecopter_scenario';

    public const SCENARIO_HAPPY = 'happy';
    public const SCENARIO_AUTH_FAIL = 'auth-fail';
    public const SCENARIO_NO_CVU_AVAILABLE = 'no-cvu-available';
    public const SCENARIO_INVALID_CUIT = 'invalid-cuit';

    private const DEFAULT_BANK_CODE = '00000058';

    public static function getScenario(string $namespace): string
    {
        $e = EntityManager::find($namespace, self::ENTITY_SCENARIO, 'active');
        return $e['data']['scenario'] ?? self::SCENARIO_HAPPY;
    }

    public static function setScenario(string $namespace, string $scenario): void
    {
        $e = EntityManager::find($namespace, self::ENTITY_SCENARIO, 'active');
        if ($e) {
            EntityManager::updateData($e['id'], ['scenario' => $scenario]);
        } else {
            EntityManager::create($namespace, self::ENTITY_SCENARIO, 'active', 'active', ['scenario' => $scenario]);
        }
    }

    /** GET /login: any creds. Respects auth-fail scenario. */
    public static function login(string $namespace = 'default'): array
    {
        if (self::getScenario($namespace) === self::SCENARIO_AUTH_FAIL) {
            return [
                'status' => 401,
                'body'   => ['error' => 'invalid credentials (scenario: auth-fail)'],
            ];
        }

        return [
            'status' => 200,
            'body'   => [
                'token'     => 'vrt_exchangecopter_' . bin2hex(random_bytes(16)),
                'expiresIn' => 3600,
            ],
        ];
    }

    /**
     * POST /creacionCVUConRegistroWallets: RESIDENT create.
     * Real body fields (Spanish): applicantId, nombre, apellido, numeroDocumento,
     * sexo, cuit, email, caracteristicaPaisTelefono, codigoAreaTelefono, numeroTelefono,
     * numeroCuentaEntidad, fechaNacimiento, codPostal, direccion, localidad, provincia, …
     */
    public static function creacionCvuConRegistroWallets(string $namespace, array $body): array
    {
        $accountRef = (string)($body['numeroCuentaEntidad'] ?? $body['applicantId'] ?? '');

        if ($accountRef === '') {
            return [
                'status' => 400,
                'body'   => ['error' => 'numeroCuentaEntidad or applicantId required'],
            ];
        }

        $scenario = self::getScenario($namespace);
        if ($scenario === self::SCENARIO_NO_CVU_AVAILABLE) {
            return ['status' => 400, 'body' => 'No hay más CVUs disponibles. Por favor, contactate con soporte.'];
        }
        if ($scenario === self::SCENARIO_INVALID_CUIT) {
            return ['status' => 400, 'body' => 'Error al crear cuenta o guardar el usuario'];
        }

        [$cvu, $alias] = self::deriveCvuAlias($accountRef);
        $idUsuario = 'vrt_usr_' . substr(hash('sha256', $accountRef), 0, 16);

        EntityManager::create($namespace, self::ENTITY_CVU, $accountRef, 'ACTIVE', [
            'accountRef'    => $accountRef,
            'idUsuario'     => $idUsuario,
            'cvu'           => $cvu,
            'alias'         => $alias,
            'flow'          => 'RESIDENT',
            'firstName'     => $body['nombre'] ?? null,
            'lastName'      => $body['apellido'] ?? null,
            'email'         => $body['email'] ?? null,
            'documentNumber' => $body['numeroDocumento'] ?? null,
        ]);

        return [
            'status' => 200,
            'body'   => [
                'proveedor' => [
                    'idUsuario' => $idUsuario,
                    'cvu'       => $cvu,
                    'alias'     => $alias,
                ],
                'mensaje' => 'CVU creada correctamente',
            ],
        ];
    }

    /** PUT /creacionAlias: body: { idCvu, alias, userCvu }. */
    public static function creacionAlias(string $namespace, array $body): array
    {
        $alias = (string)($body['alias'] ?? '');
        $idCvu = (string)($body['idCvu'] ?? '');

        if ($alias === '' || $idCvu === '') {
            return [
                'status' => 400,
                'body'   => ['error' => 'idCvu and alias required'],
            ];
        }

        // Apply alias to any matching CVU entity for visibility in the dashboard.
        $entities = EntityManager::findAllByNamespace($namespace, self::ENTITY_CVU);
        foreach ($entities as $e) {
            if (($e['data']['idUsuario'] ?? null) === $idCvu || ($e['data']['cvu'] ?? null) === ($body['userCvu'] ?? '_')) {
                EntityManager::updateData($e['id'], ['alias' => $alias]);
                break;
            }
        }

        return [
            'status' => 200,
            'body'   => [
                'success' => true,
                'idCvu'   => $idCvu,
                'alias'   => $alias,
                'mensaje' => 'Alias actualizado correctamente',
            ],
        ];
    }

    /**
     * POST /devolucionCVUonPayexAlfred: NOTRESIDENT create.
     * Real body: { email, userId }. Response must include firestore.id, cvu, alias.
     */
    public static function devolucionCvuOnPayexAlfred(string $namespace, array $body): array
    {
        $userId = (string)($body['userId'] ?? '');

        if ($userId === '') {
            return [
                'status' => 400,
                'body'   => ['error' => 'userId required'],
            ];
        }

        $scenario = self::getScenario($namespace);
        if ($scenario === self::SCENARIO_NO_CVU_AVAILABLE) {
            return ['status' => 400, 'body' => 'No hay más CVUs disponibles. Por favor, contactate con soporte.'];
        }

        [$cvu, $alias] = self::deriveCvuAlias($userId);
        $firestoreId = 'vrt_fs_' . substr(hash('sha256', $userId), 0, 20);

        EntityManager::create($namespace, self::ENTITY_CVU, $userId, 'ACTIVE', [
            'accountRef' => $userId,
            'idUsuario'  => $firestoreId,
            'cvu'        => $cvu,
            'alias'      => $alias,
            'flow'       => 'NOTRESIDENT',
            'email'      => $body['email'] ?? null,
        ]);

        return [
            'status' => 200,
            'body'   => [
                'firestore' => ['id' => $firestoreId],
                'cvu'       => $cvu,
                'alias'     => $alias,
            ],
        ];
    }

    /**
     * GET /UAT/balance?idCvu=…: virtual ARS balance for a CVU.
     * microserivces-argentina-payex calls this with the /UAT/ prefix via URL_COPTER.
     */
    public static function balance(string $namespace, string $idCvu): array
    {
        if ($idCvu === '') {
            return [
                'status' => 400,
                'body'   => ['error' => 'idCvu required'],
            ];
        }

        return [
            'status' => 200,
            'body'   => [
                'idCvu'    => $idCvu,
                'balance'  => 100000.00,
                'currency' => 'ARS',
            ],
        ];
    }

    /**
     * POST /createTransactionTotalPay: virtual COELSA withdrawal.
     * Body: { amount, origin (CVU), destination (CVU) }.
     * The TS caller reads response.data.respuesta2.Data.details.codigo for the COELSA ID.
     * Reached via COPTER_TOTALPAY_URL (patched from hardcoded api.exchangecopter.com).
     */
    public static function createTransactionTotalPay(string $namespace, array $body): array
    {
        $coelsaId = 'COELSA-' . bin2hex(random_bytes(8));

        EntityManager::create($namespace, 'exchangecopter_totalpay', $coelsaId, 'PENDING', [
            'coelsaId'    => $coelsaId,
            'amount'      => $body['amount'] ?? 0,
            'origin'      => $body['origin'] ?? '',
            'destination' => $body['destination'] ?? '',
        ]);

        return [
            'status' => 200,
            'body'   => [
                'respuesta2' => [
                    'Data' => [
                        'details' => [
                            'codigo' => $coelsaId,
                        ],
                        'status' => 'PENDING',
                        'amount' => $body['amount'] ?? 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * GET /consultaCoelsaIdTotalPay?CoelsaId=…: virtual COELSA transaction status.
     * The TS caller reads data.data.Data from the response.
     * Reached via COPTER_TOTALPAY_URL (patched from hardcoded api.exchangecopter.com).
     */
    public static function consultaCoelsaIdTotalPay(string $namespace, string $coelsaId): array
    {
        if ($coelsaId === '') {
            return [
                'status' => 400,
                'body'   => ['error' => 'CoelsaId required'],
            ];
        }

        $e = EntityManager::find($namespace, 'exchangecopter_totalpay', $coelsaId);
        $data = $e ? $e['data'] : [];

        return [
            'status' => 200,
            'body'   => [
                'Data' => [
                    'idCoelsa'    => $coelsaId,
                    'status'      => 'SETTLED',
                    'amount'      => $data['amount'] ?? null,
                    'origin'      => $data['origin'] ?? null,
                    'destination' => $data['destination'] ?? null,
                ],
            ],
        ];
    }

    /** GET /checkCBUALIAS?aliasOcvu=…: reverse lookup. */
    public static function checkCbuAlias(string $aliasOrCvu): array
    {
        if ($aliasOrCvu === '') {
            return [
                'status' => 400,
                'body'   => ['data' => 'Hubo un errror en la conexión.'],
            ];
        }

        [$cvu, $alias] = self::deriveCvuAlias($aliasOrCvu);

        return [
            'status' => 200,
            'body'   => [
                'data' => [
                    'alias'         => $alias,
                    'cvu'           => $cvu,
                    'accountHolder' => 'Virtual Account Holder',
                    'bank'          => 'Agil Pagos',
                ],
            ],
        ];
    }

    /**
     * Same derivation as PayexService used earlier so values line up with the
     * wrapper's own seeded rows if both were populated from the same UUID.
     */
    private static function deriveCvuAlias(string $seed): array
    {
        $hash = hash('sha256', $seed);
        $digits = preg_replace('/\D/', '', $hash) ?: '0';
        $digits = str_pad($digits, 14, '0', STR_PAD_RIGHT);
        $cvu = self::DEFAULT_BANK_CODE . substr($digits, 0, 14);

        $pool = ['blanco','leon','rojo','azul','verde','pampa','sierra','puerto','faro','rio'];
        $h1 = hexdec(substr($hash, 0, 4)) % count($pool);
        $h2 = hexdec(substr($hash, 4, 4)) % count($pool);
        $n  = hexdec(substr($hash, 8, 4)) % 1000;
        $alias = sprintf('%s.%s.%03d', $pool[$h1], $pool[$h2], $n);

        return [$cvu, $alias];
    }
}
