<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanySettings;

/**
 * Formats an amount in the company's currency (symbol, position, decimals and
 * separators from Company settings), e.g. "৳1,200.00" for BDT.
 * Use for any server-built text that shows money (notifications, summaries).
 */
class Money
{
    private const SYMBOLS = ['BDT' => '৳', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'JPY' => '¥'];

    /** @var array<int, array{symbol:string, after:bool, dec:int, decSep:string, thouSep:string}> */
    private static array $cache = [];

    public static function format(int $companyId, float $amount): string
    {
        $f = self::$cache[$companyId] ??= self::load($companyId);
        $num = number_format($amount, $f['dec'], $f['decSep'], $f['thouSep']);

        return $f['after'] ? $num . $f['symbol'] : $f['symbol'] . $num;
    }

    /** Currency symbol only (e.g. "৳"). */
    public static function symbol(int $companyId): string
    {
        return trim((self::$cache[$companyId] ??= self::load($companyId))['symbol']);
    }

    private static function load(int $companyId): array
    {
        $s = CompanySettings::where('company_id', $companyId)->first();
        $code = strtoupper((string) ($s?->currency ?: Company::where('id', $companyId)->value('currency') ?: 'BDT'));

        return [
            'symbol' => self::SYMBOLS[$code] ?? $code . ' ',
            'after' => ($s?->currency_symbol_position ?? 'before') === 'after',
            'dec' => (int) ($s?->currency_decimal_places ?? 2),
            'decSep' => $s?->currency_decimal_separator ?: '.',
            'thouSep' => $s?->currency_thousands_separator ?? ',',
        ];
    }
}
