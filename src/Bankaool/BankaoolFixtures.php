<?php

declare(strict_types=1);

namespace App\Bankaool;

/**
 * Static fixtures for Bankaool.
 *
 * Mexican bank codes (3-digit), CLABE validation, and default account data.
 * Source: Banxico (Banco de Mexico) catalog + Bankaool API integration in ramps-mexico.
 */
final class BankaoolFixtures
{
    public const BANKS = [
        '002' => 'BANAMEX',
        '006' => 'BANCOMEXT',
        '009' => 'BANOBRAS',
        '012' => 'BBVA MEXICO',
        '014' => 'SANTANDER',
        '021' => 'HSBC',
        '030' => 'BAJIO',
        '032' => 'IXE',
        '036' => 'INBURSA',
        '037' => 'INTERACCIONES',
        '042' => 'MIFEL',
        '044' => 'SCOTIABANK',
        '058' => 'BANREGIO',
        '059' => 'INVEX',
        '060' => 'BANSI',
        '062' => 'AFIRME',
        '072' => 'BANORTE',
        '102' => 'ABN AMEX',
        '103' => 'AMERICAN EXPRESS',
        '106' => 'BAMSA',
        '108' => 'TOKYO',
        '110' => 'JP MORGAN',
        '112' => 'BMONEX',
        '113' => 'VE POR MAS',
        '116' => 'ING',
        '124' => 'DEUTSCHE',
        '126' => 'CREDIT SUISSE',
        '127' => 'AZTECA',
        '128' => 'AUTOFIN',
        '129' => 'BARCLAYS',
        '130' => 'COMPARTAMOS',
        '131' => 'BANCO FAMSA',
        '132' => 'MULTIVA',
        '133' => 'ACTINVER',
        '134' => 'WAL-MART',
        '135' => 'NAFIN',
        '136' => 'INTERCAM',
        '137' => 'BANCOPPEL',
        '138' => 'ABC CAPITAL',
        '139' => 'UBS BANK',
        '140' => 'CONSUBANCO',
        '141' => 'VOLKSWAGEN',
        '143' => 'CIBANCO',
        '145' => 'BBASE',
        '147' => 'BANKAOOL',
        '148' => 'PAGATODO',
        '149' => 'INMOBILIARIO MEXICANO',
        '150' => 'DONDE',
        '155' => 'ICBC',
        '156' => 'SABADELL',
        '166' => 'BANSEFI',
        '168' => 'HIPOTECARIA FEDERAL',
        '600' => 'MONEXCB',
        '601' => 'GBM',
        '602' => 'MASARI',
        '605' => 'VALUE',
        '606' => 'ESTRUCTURADORES',
        '607' => 'TIBER',
        '608' => 'VECTOR',
        '610' => 'B&B',
        '614' => 'ACCIVAL',
        '615' => 'MERRILL LYNCH',
        '616' => 'FINAMEX',
        '617' => 'VALMEX',
        '618' => 'UNICA',
        '619' => 'MAPFRE',
        '620' => 'PROFUTURO',
        '621' => 'CB ACTINVER',
        '622' => 'OACTIN',
        '623' => 'CBURSAMET',
        '626' => 'CBDEUTSCHE',
        '627' => 'ZURICH',
        '628' => 'ZURICHVI',
        '629' => 'SU CASITA',
        '630' => 'CB INTERCAM',
        '631' => 'CI BOLSA',
        '632' => 'BULLTICK CB',
        '633' => 'STERLING',
        '634' => 'FINCOMUN',
        '636' => 'HDI SEGUROS',
        '637' => 'ORDER',
        '638' => 'AKALA',
        '640' => 'CB JPMORGAN',
        '642' => 'REFORMA',
        '646' => 'STP',
        '648' => 'EVERCORE',
        '649' => 'SKANDIA',
        '651' => 'SEGMTY',
        '652' => 'ASEA',
        '653' => 'KUSPIT',
        '655' => 'SOFIEXPRESS',
        '656' => 'UNAGRA',
        '659' => 'ASP INTEGRA OPC',
        '670' => 'LIBERTAD',
        '674' => 'CAJA TELEFONISTAS',
        '680' => 'CRISTOBAL COLON',
        '683' => 'CAJA POP MEXICANA',
        '684' => 'TRANSFER',
        '685' => 'FONDO (FIRA)',
        '686' => 'INVERCAP',
        '689' => 'FOMPED',
        '699' => 'CoDi Valida',
        '706' => 'ARCUS',
        '710' => 'NVIO',
        '722' => 'MERCADOPAGO',
        '902' => 'INDEVAL',
        '999' => 'N/A',
    ];

    // Bankaool's own bank code
    public const BANKAOOL_BANK_CODE = '147';

    // STP bank code (used for SPEI intermediation)
    public const STP_BANK_CODE = '646';

    public static function stpMetadata(): array
    {
        return [
            'bank'    => 'STP',
            'code'    => '90646',
            'address' => 'Av. Insurgentes Sur 1425, Pisos 10, 12 y 14, Colonia Insurgentes Mixcoac, Alcaldia Benito Juarez, C.P. 03920, Ciudad de Mexico',
        ];
    }

    public const ALFREDPAY_ACCOUNT = [
        'id'         => 1001,
        'no_cuenta'  => '147001000000001',
        'clabe'      => '147180010000000012',  // 18-digit CLABE
        'alias'      => 'ALFREDPAY OPERACIONES MX',
        'saldo'      => 5000000.00,             // MXN balance
        'moneda'     => 'MXN',
        'estatus'    => 'ACTIVA',
    ];

    // Default payment method for the AlfredPay account
    public const ALFREDPAY_PAYMENT_METHOD = [
        'id'           => 2001,
        'id_cuenta'    => 1001,
        'tipo'         => 'SPEI',
        'descripcion'  => 'SPEI - Transferencia Interbancaria',
        'activo'       => true,
    ];

    public const STATUS_LIQUIDADA  = '40';  // Settled/completed
    public const STATUS_DEVUELTA   = '50';  // Returned
    public const STATUS_INSTRUIDA  = '10';  // Instructed/pending
    public const STATUS_ERROR      = '60';  // Error

    public const TYPE_INCREMENTO = '10';  // Deposit/credit
    public const TYPE_DECREMENTO = '20';  // Withdrawal/debit
    public const TYPE_DEVOLUCION = '40';  // Return/reversal

    /**
     * Validate a Mexican CLABE number (18 digits with check digit).
     *
     * Structure: BBB-P-CCCCCCCCCC-D
     *   BBB = 3-digit bank code
     *   P   = 1-digit plaza code
     *   C   = 10-digit account number
     *   D   = 1-digit check digit (Luhn mod 10 variant)
     */
    public static function validateClabe(string $clabe): array
    {
        // Must be exactly 18 digits
        if (!preg_match('/^\d{18}$/', $clabe)) {
            return ['valid' => false, 'error' => 'CLABE must be exactly 18 digits'];
        }

        $bankCode = substr($clabe, 0, 3);

        // Bank code must exist
        if (!isset(self::BANKS[$bankCode])) {
            return ['valid' => false, 'error' => "Unknown bank code: {$bankCode}"];
        }

        // Verify check digit using CLABE weighting factors
        $weights = [3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $sum += ((int)$clabe[$i] * $weights[$i]) % 10;
        }
        $expectedCheck = (10 - ($sum % 10)) % 10;
        $actualCheck = (int)$clabe[17];

        if ($expectedCheck !== $actualCheck) {
            return [
                'valid' => false,
                'error' => "Invalid check digit: expected {$expectedCheck}, got {$actualCheck}",
            ];
        }

        return [
            'valid'     => true,
            'bank_code' => $bankCode,
            'bank_name' => self::BANKS[$bankCode],
            'plaza'     => $clabe[3],
            'account'   => substr($clabe, 4, 10),
        ];
    }

    /**
     * Generate a valid CLABE for a given bank code.
     */
    public static function generateClabe(string $bankCode = '147', string $plaza = '1'): string
    {
        $account = str_pad((string)random_int(1, 9999999999), 10, '0', STR_PAD_LEFT);
        $partial = str_pad($bankCode, 3, '0', STR_PAD_LEFT)
                 . $plaza
                 . $account;

        // Calculate check digit
        $weights = [3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7, 1, 3, 7];
        $sum = 0;
        for ($i = 0; $i < 17; $i++) {
            $digit = (int)($partial[$i] ?? '0');
            $sum += ($digit * $weights[$i]) % 10;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $partial . $check;
    }

    /**
     * Generate a clave de rastreo (tracking key) for SPEI transfers.
     * Format: BKOL + YYMMDD + random alphanumeric (20 chars total).
     */
    public static function generateClaveRastreo(): string
    {
        $prefix = 'BKOL';
        $date = date('ymd');
        $randomLen = 20 - strlen($prefix) - strlen($date);
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $random = '';
        for ($i = 0; $i < $randomLen; $i++) {
            $random .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $prefix . $date . $random;
    }

    /**
     * Look up bank info by CLABE or account number.
     */
    public static function lookupBank(string $accountNumber): ?array
    {
        if (strlen($accountNumber) === 18 && ctype_digit($accountNumber)) {
            $bankCode = substr($accountNumber, 0, 3);
            if (isset(self::BANKS[$bankCode])) {
                return [
                    'codigo_banco' => $bankCode,
                    'banco'        => self::BANKS[$bankCode],
                ];
            }
        }

        // If it's a card number (16 digits) or shorter account, try common mappings
        return null;
    }

    /**
     * Get all banks as an array of {codigo_banco, banco} objects.
     */
    public static function getAllBanks(): array
    {
        $result = [];
        foreach (self::BANKS as $code => $name) {
            $result[] = [
                'codigo_banco' => $code,
                'banco'        => $name,
            ];
        }
        return $result;
    }
}
