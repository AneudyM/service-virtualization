<?php

declare(strict_types=1);

namespace App\WesternUnion;

/**
 * Canned responses for the stateless Western Union Modernized API endpoints.
 *
 * Source of truth for the response shapes:
 *   - DTOs in western-union-backend/src/modules/western-union/client/dto/config/*
 *   - AP_MODAPI_TESTCASES-Send.xlsx (sample responses captured during self-certification)
 *
 * Currently supports Send Money MIM (Money In Minutes) WALLET corridor for
 * US, MX, CO, TR, ZM. Other corridors return the same fixture shape; extend
 * the lookup tables here as new corridors are needed.
 */
final class WuFixtures
{
    /**
     * Lookup table of supported corridors. Indexed by receiver country ISO-2.
     *
     * fee:        flat send fee in sender currency (USD)
     * rate:       FX rate USD -> receiver currency
     * currencies: list of currencies the destination accepts
     */
    private const CORRIDORS = [
        'MX' => ['fee' => '4.99',  'rate' => '18.5000',   'currencies' => ['MXN', 'USD']],
        'CO' => ['fee' => '5.99',  'rate' => '4500.0000', 'currencies' => ['COP', 'USD']],
        'TR' => ['fee' => '6.99',  'rate' => '34.2500',   'currencies' => ['TRY', 'USD', 'EUR']],
        'ZM' => ['fee' => '7.49',  'rate' => '26.7800',   'currencies' => ['ZMW']],
        'US' => ['fee' => '0.99',  'rate' => '1.0000',    'currencies' => ['USD']],
    ];

    public static function corridor(string $receiverCountry): ?array
    {
        return self::CORRIDORS[strtoupper($receiverCountry)] ?? null;
    }

    public static function supportedCountries(): array
    {
        return array_keys(self::CORRIDORS);
    }

    /**
     * GET /v1/pgw/config/origination-currencies
     */
    public static function originationCurrencies(): array
    {
        return [
            'moreData'          => 'N',
            'numOfRecords'      => 1,
            'totalNumOfRecords' => 1,
            'currencies'        => [
                ['code' => 'USD', 'name' => 'US Dollar'],
            ],
        ];
    }

    /**
     * GET /v1/pgw/config/entitled-destinations
     */
    public static function entitledDestinations(): array
    {
        $names = [
            'MX' => 'Mexico',
            'CO' => 'Colombia',
            'TR' => 'Turkey',
            'ZM' => 'Zambia',
            'US' => 'United States',
        ];

        $records = [];
        foreach (self::CORRIDORS as $iso => $row) {
            $records[] = [
                'country'    => ['code' => $iso, 'name' => $names[$iso] ?? $iso],
                'currencies' => array_map(
                    fn($c) => ['code' => $c, 'name' => $c],
                    $row['currencies']
                ),
            ];
        }

        return [
            'moreData'          => 'N',
            'numOfRecords'      => count($records),
            'totalNumOfRecords' => count($records),
            'countryCurrency'   => $records,
        ];
    }

    /**
     * GET /v1/pgw/config/currency-info
     */
    public static function currencyInfo(?string $currency = null): array
    {
        $catalog = [
            'MXN' => ['name' => 'Mexican Peso',     'equivalence' => '18.50',   'decimals' => 2],
            'USD' => ['name' => 'US Dollar',        'equivalence' => '1.00',    'decimals' => 2],
            'COP' => ['name' => 'Colombian Peso',   'equivalence' => '4500.00', 'decimals' => 0],
            'TRY' => ['name' => 'Turkish Lira',     'equivalence' => '34.25',   'decimals' => 2],
            'EUR' => ['name' => 'Euro',             'equivalence' => '0.93',    'decimals' => 2],
            'ZMW' => ['name' => 'Zambian Kwacha',   'equivalence' => '26.78',   'decimals' => 2],
        ];

        $codes = $currency ? [strtoupper($currency)] : array_keys($catalog);
        $records = [];
        foreach ($codes as $code) {
            if (!isset($catalog[$code])) {
                continue;
            }
            $records[] = [
                'code'        => $code,
                'name'        => $catalog[$code]['name'],
                'equivalence' => $catalog[$code]['equivalence'],
                'decimals'    => $catalog[$code]['decimals'],
            ];
        }

        return [
            'moreData'          => 'N',
            'numOfRecords'      => count($records),
            'totalNumOfRecords' => count($records),
            'currencyInfo'      => $records,
        ];
    }

    /**
     * GET /v1/pgw/config/payout-options
     */
    public static function payoutOptions(): array
    {
        return [
            'moreData'          => 'N',
            'numOfRecords'      => 2,
            'totalNumOfRecords' => 2,
            'deliveryServices'  => [
                [
                    'payoutMethodCode' => '000',
                    'payoutMethodName' => 'MONEY IN MINUTES',
                    'serviceCode'      => 'MIM',
                ],
                [
                    'payoutMethodCode' => '800',
                    'payoutMethodName' => 'WALLET MONEY TRANSFER',
                    'serviceCode'      => 'WMT',
                ],
            ],
        ];
    }

    /**
     * GET /v1/pgw/config/state-list
     *
     * Returns a stub for US/MX/CO. Other corridors get an empty list.
     */
    public static function stateList(string $country): array
    {
        $catalog = [
            'US' => [
                ['code' => 'CA', 'name' => 'California'],
                ['code' => 'TX', 'name' => 'Texas'],
                ['code' => 'NY', 'name' => 'New York'],
                ['code' => 'FL', 'name' => 'Florida'],
            ],
            'MX' => [
                [
                    'name'     => 'AGUASCALIENTES',
                    'code'     => 'AGS',
                    'cityList' => [
                        ['name' => 'AGUASCALIENTES', 'code' => 'AGU'],
                    ],
                ],
                [
                    'name'     => 'JALISCO',
                    'code'     => 'JAL',
                    'cityList' => [
                        ['name' => 'GUADALAJARA', 'code' => 'GDL'],
                    ],
                ],
            ],
            'CO' => [
                ['code' => 'BOG', 'name' => 'Bogota D.C.'],
                ['code' => 'ANT', 'name' => 'Antioquia'],
            ],
        ];

        $list = $catalog[strtoupper($country)] ?? [];
        return [
            'moreData'          => 'N',
            'numOfRecords'      => count($list),
            'totalNumOfRecords' => count($list),
            'stateList'         => $list,
        ];
    }

    /**
     * GET /v1/pgw/config/reasonlist
     */
    public static function reasonList(): array
    {
        return [
            'moreData'          => 'N',
            'numOfRecords'      => 4,
            'totalNumOfRecords' => 4,
            'reasons'           => [
                ['code' => '01', 'name' => 'Family Support'],
                ['code' => '02', 'name' => 'Gift'],
                ['code' => '03', 'name' => 'Goods Purchased'],
                ['code' => '99', 'name' => 'Other'],
            ],
        ];
    }

    /**
     * GET /v1/pgw/config/error-translations
     */
    public static function errorTranslations(): array
    {
        return [
            'moreData'          => 'N',
            'numOfRecords'      => 3,
            'totalNumOfRecords' => 3,
            'errors'            => [
                ['code' => 'R0003', 'message' => 'Dynamic Data Collection/Validation Error'],
                ['code' => 'U2229', 'message' => 'USA/CAN 1-866-420-2996 OR VISIT WU AGENT'],
                ['code' => 'Q4001', 'message' => 'Quote expired. Please request a new one.'],
            ],
        ];
    }

    /**
     * GET /v1/pgw/config/templates
     *
     * Returns the FieldTemplate response that drives the dynamic recipient form.
     * Real WU returns the schema partitioned by sender / receiver / agentDocument
     * with name, label, length, required and regex per field. We mirror that here.
     */
    public static function fieldTemplate(string $receiverCountry, string $payoutMethod): array
    {
        $senderFields = self::standardSenderFields();
        $receiverFields = self::standardReceiverFields($receiverCountry);
        $agentDocFields = self::standardAgentDocFields();

        return [
            'fieldsDetails' => [
                'corridor' => [
                    'receiverCountry'  => strtoupper($receiverCountry),
                    'payoutMethodName' => strtoupper($payoutMethod),
                ],
                'sender'        => ['fields' => $senderFields],
                'receiver'      => ['fields' => $receiverFields],
                'agentDocument' => ['fields' => $agentDocFields],
            ],
        ];
    }

    private static function standardSenderFields(): array
    {
        return [
            ['name' => 'sender.name.nameType',      'label' => 'Name Type',     'length' => 1,  'required' => 'Y', 'regex' => '^[IM]$'],
            ['name' => 'sender.name.firstName',    'label' => 'First Name',    'length' => 50, 'required' => 'Y', 'regex' => '^[A-Za-z ]+$'],
            ['name' => 'sender.name.lastName',     'label' => 'Last Name',     'length' => 50, 'required' => 'Y', 'regex' => '^[A-Za-z ]+$'],
            ['name' => 'sender.address.line1',     'label' => 'Street Address','length' => 100,'required' => 'Y'],
            ['name' => 'sender.address.city',      'label' => 'City',          'length' => 60, 'required' => 'Y'],
            ['name' => 'sender.address.country',   'label' => 'Country',       'length' => 2,  'required' => 'Y'],
            ['name' => 'sender.dateOfBirth',       'label' => 'Date of Birth', 'length' => 10, 'required' => 'Y', 'regex' => '^\\d{8}$'],
            ['name' => 'sender.contactNumber',     'label' => 'Phone Number',  'length' => 20, 'required' => 'Y'],
            ['name' => 'sender.id1.type',          'label' => 'ID Type',       'length' => 2,  'required' => 'Y'],
            ['name' => 'sender.id1.number',        'label' => 'ID Number',     'length' => 40, 'required' => 'Y'],
            ['name' => 'sender.id1.issuingAgency', 'label' => 'ID Issuer',     'length' => 30, 'required' => 'Y'],
            ['name' => 'sender.email',             'label' => 'Email',         'length' => 60, 'required' => 'C', 'cipThreshold' => '1.00'],
            ['name' => 'sender.nationality',       'label' => 'Nationality',   'length' => 30, 'required' => 'C', 'cipThreshold' => '1.00'],
            ['name' => 'sender.countryOfBirth',    'label' => 'Country Of Birth', 'length' => 30, 'required' => 'C', 'cipThreshold' => '1.00'],
        ];
    }

    private static function standardReceiverFields(string $country): array
    {
        $fields = [
            ['name' => 'receiver.name.nameType',     'label' => 'Name Type',  'length' => 1,  'required' => 'Y', 'regex' => '^[IM]$'],
            ['name' => 'receiver.name.firstName',    'label' => 'First Name', 'length' => 50, 'required' => 'Y'],
            ['name' => 'receiver.name.lastName',     'label' => 'Last Name',  'length' => 50, 'required' => 'Y'],
            ['name' => 'receiver.address.country',   'label' => 'Country',    'length' => 2,  'required' => 'Y'],
            ['name' => 'receiver.address.city',      'label' => 'City',       'length' => 60, 'required' => 'Y'],
        ];

        // Mexico requires state + city codes (per xlsx C5).
        if (strtoupper($country) === 'MX') {
            $fields[] = ['name' => 'receiver.address.stateProvince', 'label' => 'State', 'length' => 3, 'required' => 'Y'];
        }

        return $fields;
    }

    private static function standardAgentDocFields(): array
    {
        return [
            ['name' => 'agentDocument.payInMethod', 'label' => 'Pay-in Method', 'length' => 10, 'required' => 'Y'],
            ['name' => 'agentDocument.reasonCode',  'label' => 'Reason Code',   'length' => 2,  'required' => 'Y'],
        ];
    }

    /**
     * Sample agent locations returned by the Locator API.
     */
    public static function agentLocations(string $country): array
    {
        return [
            'agents' => [
                [
                    'agentId'     => 'WU-' . strtoupper($country) . '-001',
                    'name'        => 'Western Union ' . strtoupper($country) . ' Branch 1',
                    'address'     => '123 Main Street',
                    'city'        => 'Capital City',
                    'country'     => strtoupper($country),
                    'phone'       => '+1-555-0001',
                    'serviceList' => ['MIM', 'WMT'],
                    'openHours'   => '08:00-20:00',
                ],
                [
                    'agentId'     => 'WU-' . strtoupper($country) . '-002',
                    'name'        => 'Western Union ' . strtoupper($country) . ' Branch 2',
                    'address'     => '456 Market Street',
                    'city'        => 'Second City',
                    'country'     => strtoupper($country),
                    'phone'       => '+1-555-0002',
                    'serviceList' => ['MIM'],
                    'openHours'   => '09:00-18:00',
                ],
            ],
        ];
    }
}
