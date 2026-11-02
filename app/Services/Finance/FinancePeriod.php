<?php

namespace App\Services\Finance;

use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** A reporting window from a preset or from/to dates (inclusive days), with its previous period. */
final class FinancePeriod
{
    public const PRESETS = [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'this_week' => 'This week',
        'last_7_days' => 'Last 7 days',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'last_30_days' => 'Last 30 days',
        'this_quarter' => 'This quarter',
        'last_quarter' => 'Last quarter',
        'this_year' => 'This year',
        'last_year' => 'Last year',
        'all_time' => 'All time',
        'custom' => 'Custom range',
    ];

    private function __construct(
        public readonly string $preset,
        public readonly ?Carbon $from,
        public readonly ?Carbon $to,
        public readonly ?Carbon $prevFrom,
        public readonly ?Carbon $prevTo,
        public readonly ?string $tz = null,
    ) {
    }

    /** $tz: company time zone used for presets ("today", "this month", …) and open-range buckets. */
    public static function fromInput(array $in, string $default = 'this_month', ?string $tz = null): self
    {
        $fromRaw = $in['from'] ?? null;
        $toRaw = $in['to'] ?? null;
        $preset = $in['preset'] ?? null;
        $preset = is_string($preset) ? strtolower(trim($preset)) : null;

        if (($fromRaw !== null && $fromRaw !== '') || ($toRaw !== null && $toRaw !== '') || $preset === 'custom') {
            $from = self::date($fromRaw, 'From');
            $to = self::date($toRaw, 'To');
            if ($from && $to && $to->lt($from)) {
                throw new HttpException(422, 'The to date must be on or after the from date.');
            }
            $prevFrom = $prevTo = null;
            if ($from && $to) {
                $days = $from->diffInDays($to) + 1;
                $prevTo = $from->copy()->subDay();
                $prevFrom = $prevTo->copy()->subDays($days - 1);
            }

            return new self('custom', $from, $to, $prevFrom, $prevTo, $tz);
        }

        $preset = $preset ?: $default;
        if (!array_key_exists($preset, self::PRESETS)) {
            throw new HttpException(422, 'Unknown period preset.');
        }
        $t = Carbon::now($tz ?: config('app.timezone'))->startOfDay();
        [$f, $to, $pf, $pt] = match ($preset) {
            'today' => [$t->copy(), $t->copy(), $t->copy()->subDay(), $t->copy()->subDay()],
            'yesterday' => [$t->copy()->subDay(), $t->copy()->subDay(), $t->copy()->subDays(2), $t->copy()->subDays(2)],
            'this_week' => [$t->copy()->startOfWeek(), $t->copy()->endOfWeek()->startOfDay(), $t->copy()->startOfWeek()->subWeek(), $t->copy()->startOfWeek()->subDay()],
            'last_7_days' => [$t->copy()->subDays(6), $t->copy(), $t->copy()->subDays(13), $t->copy()->subDays(7)],
            'this_month' => [$t->copy()->startOfMonth(), $t->copy()->endOfMonth()->startOfDay(), $t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_month' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay(), $t->copy()->subMonthsNoOverflow(2)->startOfMonth(), $t->copy()->subMonthsNoOverflow(2)->endOfMonth()->startOfDay()],
            'last_30_days' => [$t->copy()->subDays(29), $t->copy(), $t->copy()->subDays(59), $t->copy()->subDays(30)],
            'this_quarter' => [$t->copy()->startOfQuarter(), $t->copy()->endOfQuarter()->startOfDay(), $t->copy()->startOfQuarter()->subQuarterNoOverflow(), $t->copy()->startOfQuarter()->subDay()],
            'last_quarter' => [$t->copy()->startOfQuarter()->subQuarterNoOverflow(), $t->copy()->startOfQuarter()->subDay(), $t->copy()->startOfQuarter()->subQuartersNoOverflow(2), $t->copy()->startOfQuarter()->subQuarterNoOverflow()->subDay()],
            'this_year' => [$t->copy()->startOfYear(), $t->copy()->endOfYear()->startOfDay(), $t->copy()->subYear()->startOfYear(), $t->copy()->subYear()->endOfYear()->startOfDay()],
            'last_year' => [$t->copy()->subYear()->startOfYear(), $t->copy()->subYear()->endOfYear()->startOfDay(), $t->copy()->subYears(2)->startOfYear(), $t->copy()->subYears(2)->endOfYear()->startOfDay()],
            default => [null, null, null, null], // all_time
        };

        return new self($preset, $f, $to, $pf, $pt, $tz);
    }

    private static function date(mixed $raw, string $which): ?Carbon
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new HttpException(422, "{$which} date must be a valid date (YYYY-MM-DD).");
        }
        try {
            $d = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            $d = false;
        }
        if (!$d || $d->format('Y-m-d') !== $raw) {
            throw new HttpException(422, "{$which} date must be a valid date (YYYY-MM-DD).");
        }

        return $d->startOfDay();
    }

    public function fromDate(): ?string
    {
        return $this->from?->toDateString();
    }

    public function toDate(): ?string
    {
        return $this->to?->toDateString();
    }

    public function hasPrevious(): bool
    {
        return $this->prevFrom !== null && $this->prevTo !== null;
    }

    public function previous(): ?self
    {
        return $this->hasPrevious() ? new self('custom', $this->prevFrom, $this->prevTo, null, null, $this->tz) : null;
    }

    /** Daily buckets when the (bounded) window is at most 62 days, else monthly. */
    public function granularity(): string
    {
        if ($this->from && $this->to) {
            return $this->from->diffInDays($this->to) + 1 <= 62 ? 'day' : 'month';
        }

        return 'month';
    }

    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'label' => $this->preset === 'custom' ? $this->customLabel() : self::PRESETS[$this->preset],
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'previousFrom' => $this->prevFrom?->toDateString(),
            'previousTo' => $this->prevTo?->toDateString(),
        ];
    }

    private function customLabel(): string
    {
        if ($this->from && $this->to) {
            return $this->from->format('j M Y') . ' – ' . $this->to->format('j M Y');
        }
        if ($this->from) {
            return 'From ' . $this->from->format('j M Y');
        }
        if ($this->to) {
            return 'Until ' . $this->to->format('j M Y');
        }

        return 'All time';
    }

    /** Bucket keys/labels between from and to (bounded by $min/$max when open). */
    public function buckets(?string $minDate = null): array
    {
        $gran = $this->granularity();
        $now = Carbon::now($this->tz ?: config('app.timezone'));
        $from = $this->from ?? ($minDate ? Carbon::parse($minDate)->startOfDay() : $now->copy()->startOfMonth());
        $to = $this->to ?? $now->copy()->startOfDay();
        if ($gran === 'month') {
            $from = $from->copy()->startOfMonth();
            // keep the last 24 months of an open/long range
            $cap = $to->copy()->startOfMonth()->subMonths(23);
            if ($from->lt($cap)) {
                $from = $cap;
            }
        }
        $out = [];
        $c = $from->copy();
        $guard = 0;
        while ($c->lte($to) && $guard++ < 800) {
            if ($gran === 'day') {
                $out[] = ['key' => $c->toDateString(), 'label' => $c->format('j M')];
                $c->addDay();
            } else {
                $out[] = ['key' => $c->format('Y-m'), 'label' => $c->format('M Y')];
                $c->addMonthNoOverflow()->startOfMonth();
            }
        }

        return $out;
    }
}
