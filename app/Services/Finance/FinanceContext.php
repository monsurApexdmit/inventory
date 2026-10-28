<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Models\CompanySettings;
use App\Support\Money;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Company-level finance context: time zone (company_settings.timezone, default Asia/Dhaka), currency
 * (single currency per company) and the sales channel filter. Every finance response carries
 * context() = {period, currency, channel, tz, asOf, currentState}.
 */
final class FinanceContext
{
    public const DEFAULT_TZ = 'Asia/Dhaka';

    public const CHANNELS = ['all' => 'All channels', 'pos' => 'POS', 'online' => 'Online store', 'manual' => 'Manual'];

    /** @var array<int, string> */
    private static array $tz = [];

    /** @var array<int, array{code:string,symbol:string}> */
    private static array $currency = [];

    public static function tz(int $cid): string
    {
        if (isset(self::$tz[$cid])) {
            return self::$tz[$cid];
        }
        $raw = trim((string) CompanySettings::where('company_id', $cid)->value('timezone'));
        $tz = self::DEFAULT_TZ;
        if ($raw !== '') {
            try {
                new \DateTimeZone($raw);
                $tz = $raw;
            } catch (\Throwable) {
                $tz = self::DEFAULT_TZ;
            }
        }

        return self::$tz[$cid] = $tz;
    }

    /** UTC offset of the company zone right now, e.g. "+06:00" (for CONVERT_TZ on UTC timestamps). */
    public static function offset(int $cid): string
    {
        return Carbon::now(self::tz($cid))->format('P');
    }

    public static function today(int $cid): Carbon
    {
        return Carbon::now(self::tz($cid))->startOfDay();
    }

    public static function todayDate(int $cid): string
    {
        return self::today($cid)->toDateString();
    }

    /** SQL: local calendar date of a UTC timestamp column. */
    public static function localDateSql(int $cid, string $col): string
    {
        return "DATE(CONVERT_TZ({$col}, '+00:00', '" . self::offset($cid) . "'))";
    }

    /** SQL: local datetime of a UTC timestamp column. */
    public static function localSql(int $cid, string $col): string
    {
        return "CONVERT_TZ({$col}, '+00:00', '" . self::offset($cid) . "')";
    }

    /** UTC datetime string of local midnight starting $date. */
    public static function utcStartOf(int $cid, string $date): string
    {
        return Carbon::parse($date, self::tz($cid))->startOfDay()->utc()->toDateTimeString();
    }

    /** ISO-8601 in the company zone. */
    public static function iso(int $cid, mixed $ts): ?string
    {
        if ($ts === null || $ts === '') {
            return null;
        }

        return Carbon::parse($ts, 'UTC')->setTimezone(self::tz($cid))->toIso8601String();
    }

    public static function currency(int $cid): array
    {
        if (isset(self::$currency[$cid])) {
            return self::$currency[$cid];
        }
        $code = CompanySettings::where('company_id', $cid)->value('currency') ?: Company::where('id', $cid)->value('currency') ?: 'BDT';

        return self::$currency[$cid] = ['code' => strtoupper((string) $code), 'symbol' => Money::symbol($cid)];
    }

    public static function currencyCode(int $cid): string
    {
        return self::currency($cid)['code'];
    }

    public static function channel(array $in): string
    {
        $c = strtolower(trim((string) ($in['channel'] ?? 'all')));
        if ($c === '') {
            return 'all';
        }
        if ($c === 'storefront') {
            return 'online';
        }
        if (!isset(self::CHANNELS[$c])) {
            throw new HttpException(422, 'Unknown channel. Use all, pos, online or manual.');
        }

        return $c;
    }

    /** SQL: channel key of a sells alias ("pos" | "online" | "manual"). */
    public static function channelSql(string $alias = 's'): string
    {
        return "CASE WHEN {$alias}.source = 'pos' THEN 'pos' WHEN {$alias}.source IN ('storefront', 'online') THEN 'online' ELSE 'manual' END";
    }

    /** Apply a channel filter to a query on sells (alias). */
    public static function applyChannel($q, string $channel, string $alias = 'sells')
    {
        if ($channel === 'pos') {
            $q->where("{$alias}.source", 'pos');
        } elseif ($channel === 'online') {
            $q->whereIn("{$alias}.source", ['storefront', 'online']);
        } elseif ($channel === 'manual') {
            $q->where(fn ($w) => $w->whereNull("{$alias}.source")->orWhereNotIn("{$alias}.source", ['pos', 'storefront', 'online']));
        }

        return $q;
    }

    public static function period(int $cid, array $in, string $default = 'this_month'): FinancePeriod
    {
        return FinancePeriod::fromInput($in, $default, self::tz($cid));
    }

    public static function context(int $cid, ?FinancePeriod $period, string $channel = 'all', bool $currentState = false): array
    {
        return [
            'period' => $period?->toArray(),
            'currency' => self::currency($cid),
            'channel' => $channel,
            'channelLabel' => self::CHANNELS[$channel] ?? $channel,
            'tz' => self::tz($cid),
            'asOf' => self::todayDate($cid),
            'currentState' => $currentState,
        ];
    }

    /** "September 2026" for "2026-09". */
    public static function monthLabel(string $month): string
    {
        return Carbon::createFromFormat('!Y-m', $month)->format('F Y');
    }

    /** Reset per-request caches (tests). */
    public static function flush(): void
    {
        self::$tz = [];
        self::$currency = [];
    }
}
