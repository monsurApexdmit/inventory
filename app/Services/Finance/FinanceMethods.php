<?php

namespace App\Services\Finance;

/**
 * Normalises the free-text payment methods stored across sells, purchases, expenses, salaries and
 * tailor payments to a small set of keys, so each key can be mapped to one finance account.
 * PHP (key()) and SQL (sqlKey()) must stay in step.
 */
final class FinanceMethods
{
    public const LABELS = [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'card' => 'Card',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'rocket' => 'Rocket',
        'online' => 'Online',
        'cod' => 'Cash on delivery',
        'cheque' => 'Cheque',
    ];

    private const EXACT = [
        'cash' => ['cash'],
        'bank' => ['bank', 'bank transfer', 'bank_transfer', 'bank-transfer', 'bank deposit'],
        'card' => ['card', 'credit card', 'debit card', 'credit_card', 'debit_card', 'stripe'],
        'online' => ['online', 'sslcommerz', 'portwallet', 'paypal', 'gateway'],
        'cod' => ['cod', 'cash on delivery', 'cash_on_delivery'],
        'cheque' => ['cheque', 'check'],
        'bkash' => ['mobile banking'],
    ];

    private const PREFIX = ['bkash' => 'bkash', 'nagad' => 'nagad', 'rocket' => 'rocket'];

    public static function key(?string $method): string
    {
        $m = strtolower(trim((string) $method));
        if ($m === '') {
            return 'cash';
        }
        foreach (self::PREFIX as $prefix => $key) {
            if (str_starts_with($m, $prefix)) {
                return $key;
            }
        }
        foreach (self::EXACT as $key => $values) {
            if (in_array($m, $values, true)) {
                return $key;
            }
        }

        return mb_substr($m, 0, 30);
    }

    public static function label(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::LABELS[$key] ?? ucwords(str_replace('_', ' ', $key));
    }

    /** SQL expression giving the method key of a column (same rules as key()). */
    public static function sqlKey(string $col): string
    {
        $l = "LOWER(TRIM(COALESCE({$col}, '')))";
        $cases = ["WHEN {$l} = '' THEN 'cash'"];
        foreach (self::PREFIX as $prefix => $key) {
            $cases[] = "WHEN {$l} LIKE '{$prefix}%' THEN '{$key}'";
        }
        foreach (self::EXACT as $key => $values) {
            $in = implode(', ', array_map(fn ($v) => "'" . $v . "'", $values));
            $cases[] = "WHEN {$l} IN ({$in}) THEN '{$key}'";
        }

        return 'CASE ' . implode(' ', $cases) . " ELSE LEFT({$l}, 30) END";
    }

    /** @return array<int, array{key:string,label:string}> */
    public static function options(): array
    {
        $out = [];
        foreach (self::LABELS as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label];
        }

        return $out;
    }
}
