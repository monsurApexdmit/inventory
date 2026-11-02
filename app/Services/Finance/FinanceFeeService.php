<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Payment-method fee settings (finance_settings.payment_fees = {methodKey: {percent, fixed}}).
 * Fees are estimates computed at read time on customer money-in ledger lines:
 * fee = round(amount × percent / 100 + fixed, 2). Nothing is stored on payments.
 */
class FinanceFeeService
{
    /** Ledger source types that carry a payment fee (money in from customers). */
    public const FEE_SOURCES = ['sale', 'cod', 'tailor_payment'];

    /** @var array<int, array<string, array{percent: float, fixed: float}>> */
    private static array $cache = [];

    /** @return array<string, array{percent: float, fixed: float}> only methods with a fee */
    public function settings(int $cid): array
    {
        if (isset(self::$cache[$cid])) {
            return self::$cache[$cid];
        }
        $raw = DB::table('finance_settings')->where('company_id', $cid)->value('payment_fees');
        $arr = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        $out = [];
        foreach ($arr as $k => $v) {
            $p = round((float) ($v['percent'] ?? 0), 4);
            $f = round((float) ($v['fixed'] ?? 0), 2);
            if ($p > 0 || $f > 0) {
                $out[(string) $k] = ['percent' => $p, 'fixed' => $f];
            }
        }

        return self::$cache[$cid] = $out;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /** Fee on one ledger line (array row from mapRow-like data or raw object). */
    public function fee(int $cid, string $sourceType, string $direction, ?string $mkey, float $amount): float
    {
        if ($direction !== 'in' || !in_array($sourceType, self::FEE_SOURCES, true) || $mkey === null) {
            return 0.0;
        }
        $s = $this->settings($cid)[$mkey] ?? null;
        if (!$s) {
            return 0.0;
        }

        return round($amount * $s['percent'] / 100 + $s['fixed'], 2);
    }

    /** SQL fee expression over a ledger alias (same rule as fee()). */
    public function sql(int $cid, string $alias = 'l'): string
    {
        $set = $this->settings($cid);
        if (!$set) {
            return '0';
        }
        $cases = [];
        foreach ($set as $k => $v) {
            $key = str_replace("'", "''", $k);
            $cases[] = "WHEN '{$key}' THEN ROUND({$alias}.amount * {$v['percent']} / 100 + {$v['fixed']}, 2)";
        }
        $src = "'" . implode("', '", self::FEE_SOURCES) . "'";

        return "(CASE WHEN {$alias}.direction = 'in' AND {$alias}.source_type IN ({$src}) THEN (CASE {$alias}.mkey " . implode(' ', $cases) . ' ELSE 0 END) ELSE 0 END)';
    }

    public function get(int $cid): array
    {
        $set = $this->settings($cid);
        $keys = array_keys(FinanceMethods::LABELS);
        foreach (array_keys($set) as $k) {
            if (!in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        $fees = array_map(fn ($k) => [
            'methodKey' => $k,
            'label' => FinanceMethods::label($k),
            'percent' => $set[$k]['percent'] ?? 0.0,
            'fixed' => $set[$k]['fixed'] ?? 0.0,
        ], $keys);

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'fees' => $fees,
            'appliesTo' => self::FEE_SOURCES,
        ];
    }

    public function update(int $cid, mixed $rows): array
    {
        if (!is_array($rows)) {
            throw new HttpException(422, 'Send the fees as a list.');
        }
        $raw = DB::table('finance_settings')->where('company_id', $cid)->value('payment_fees');
        $cur = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
        foreach ($rows as $r) {
            $key = strtolower(trim((string) ($r['methodKey'] ?? '')));
            if ($key === '' || mb_strlen($key) > 30 || !preg_match('/^[a-z0-9 _\-]+$/', $key)) {
                throw new HttpException(422, 'Unknown payment method: ' . ($key ?: '(empty)') . '.');
            }
            $p = $r['percent'] ?? 0;
            $f = $r['fixed'] ?? 0;
            if (!is_numeric($p) || (float) $p < 0 || (float) $p > 100) {
                throw new HttpException(422, 'Fee percent must be between 0 and 100.');
            }
            if (!is_numeric($f) || (float) $f < 0) {
                throw new HttpException(422, 'Fixed fee cannot be negative.');
            }
            if ((float) $p == 0.0 && (float) $f == 0.0) {
                unset($cur[$key]);
            } else {
                $cur[$key] = ['percent' => round((float) $p, 4), 'fixed' => round((float) $f, 2)];
            }
        }
        $json = json_encode((object) $cur);
        $exists = DB::table('finance_settings')->where('company_id', $cid)->exists();
        if ($exists) {
            DB::table('finance_settings')->where('company_id', $cid)->update(['payment_fees' => $json, 'updated_at' => now()]);
        } else {
            DB::table('finance_settings')->insert(['company_id' => $cid, 'payment_fees' => $json, 'created_at' => now(), 'updated_at' => now()]);
        }
        unset(self::$cache[$cid]);

        return $this->get($cid);
    }
}
