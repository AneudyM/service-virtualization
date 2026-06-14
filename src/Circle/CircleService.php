<?php

declare(strict_types=1);

namespace App\Circle;

use App\Entity\EntityManager;

/**
 * Circle CPN OFI API: quote flow.
 *
 * Accepted quirks:
 *   - FEDWIRE paymentMethodType, not in Circle's enum but sent for US corridors
 *   - Empty senderCountry/destinationCountry, read from wrong field upstream
 */
final class CircleService
{
    public const ENTITY_QUOTE = 'circle_quote';

    /** 30 min: Circle sandbox quote TTL. */
    private const QUOTE_TTL_SECONDS = 1800;

    /** 60 min: Circle sandbox settlement deadline. */
    private const SETTLEMENT_TTL_SECONDS = 3600;

    /**
     * Create a quote and persist it.
     * Returns the response envelope (wrapped in `{data: [quote]}`) that real
     * Circle CPN returns from POST /v1/cpn/quotes.
     */
    public static function createQuote(string $namespace, array $request): array
    {
        $quote = self::buildQuoteItem($request);

        EntityManager::create(
            namespace:  $namespace,
            entityType: self::ENTITY_QUOTE,
            entityRef:  $quote['id'],
            state:      'ACTIVE',
            data:       $quote,
        );

        return ['data' => [$quote]];
    }

    /**
     * Retrieve a previously-created quote by id.
     * Returns the response envelope; 404 handled by the controller via null.
     */
    public static function getQuote(string $namespace, string $quoteId): ?array
    {
        $row = EntityManager::find($namespace, self::ENTITY_QUOTE, $quoteId);
        if ($row === null) {
            return null;
        }
        return ['data' => $row['data']];
    }

    /**
     * Handles both amount directions:
     *   - sourceAmount set: compute destinationAmount from rate
     *   - destinationAmount set: compute sourceAmount from rate
     */
    private static function buildQuoteItem(array $request): array
    {
        $sourceAmount      = $request['sourceAmount']      ?? [];
        $destinationAmount = $request['destinationAmount'] ?? [];

        $sourceCurrency = $sourceAmount['currency']      ?? 'USDC';
        $destCurrency   = $destinationAmount['currency'] ?? 'USD';

        $rateData = CircleFixtures::lookupRate($sourceCurrency, $destCurrency);
        $rate     = $rateData['rate'];

        $sourceAmountStr = $sourceAmount['amount']      ?? null;
        $destAmountStr   = $destinationAmount['amount'] ?? null;

        if ($sourceAmountStr !== null && $sourceAmountStr !== '') {
            // Flow 1: source amount known, compute destination.
            $destComputed = (float) $sourceAmountStr * (float) $rate;
            $destAmountStr = number_format($destComputed, 2, '.', '');
        } elseif ($destAmountStr !== null && $destAmountStr !== '') {
            // Flow 2: destination amount known, compute source.
            $rateFloat = (float) $rate;
            if ($rateFloat <= 0) {
                throw new \RuntimeException("Rate {$sourceCurrency}->{$destCurrency} is zero or negative");
            }
            $sourceComputed = (float) $destAmountStr / $rateFloat;
            $sourceAmountStr = number_format($sourceComputed, 2, '.', '');
        } else {
            // Neither amount provided: invalid per spec, but penny-api does this.
            $sourceAmountStr = '0.00';
            $destAmountStr   = '0.00';
        }

        $paymentMethodType = (string) ($request['paymentMethodType'] ?? 'WIRE');
        $blockchain        = (string) ($request['blockchain']        ?? 'SOL');
        $senderCountry     = (string) ($request['senderCountry']     ?? '');
        $destCountry       = (string) ($request['destinationCountry'] ?? '');
        $senderType        = (string) ($request['senderType']        ?? 'INDIVIDUAL');
        $recipientType     = (string) ($request['recipientType']     ?? 'INDIVIDUAL');
        $transactionVersion = (string) ($request['transactionVersion'] ?? 'VERSION_2');
        $quoteOptions = $request['quoteOptions'] ?? ['isFirstParty' => false];

        $now       = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $createDate = $now->format('Y-m-d\TH:i:s.v\Z');
        $quoteExpireDate = $now->add(new \DateInterval('PT' . self::QUOTE_TTL_SECONDS . 'S'))
                               ->format('Y-m-d\TH:i:s.v\Z');
        $settlementExpireDate = $now->add(new \DateInterval('PT' . self::SETTLEMENT_TTL_SECONDS . 'S'))
                                    ->format('Y-m-d\TH:i:s.v\Z');

        return [
            'type'                             => 'quote',
            'id'                               => self::generateUuid(),
            'paymentMethodType'                => $paymentMethodType,
            'blockchain'                       => $blockchain,
            'senderCountry'                    => $senderCountry,
            'destinationCountry'               => $destCountry,
            'createDate'                       => $createDate,
            'quoteExpireDate'                  => $quoteExpireDate,
            'cryptoFundsSettlementExpireDate'  => $settlementExpireDate,
            'sourceAmount'                     => [
                'amount'   => $sourceAmountStr,
                'currency' => $sourceCurrency,
            ],
            'destinationAmount'                => [
                'amount'   => $destAmountStr,
                'currency' => $destCurrency,
            ],
            'fiatSettlementTime'               => CircleFixtures::fiatSettlementTime($paymentMethodType),
            'exchangeRate'                     => [
                'pair' => $rateData['pair'],
                'rate' => $rate,
            ],
            'rawExchangeRate'                  => [
                'pair' => $rateData['pair'],
                'rate' => $rateData['rawRate'],
            ],
            'fees'                             => CircleFixtures::buildFees($sourceAmountStr, $sourceCurrency),
            'senderType'                       => $senderType,
            'recipientType'                    => $recipientType,
            'certificate'                      => CircleFixtures::testCertificate(),
            'quoteOptions'                     => $quoteOptions,
            'transactionVersion'               => $transactionVersion,
        ];
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // v4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
