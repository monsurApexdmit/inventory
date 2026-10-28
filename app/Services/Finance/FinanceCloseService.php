<?php

namespace App\Services\Finance;

use App\Support\ActorName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Month-end close (RPT-1/RPT-3): close / reopen local calendar months, a P&L snapshot at close
 * ("net at close") and the status texts. Locks are enforced by FinancePeriodLock.
 */
class FinanceCloseService
{
    public function __construct(private readonly FinanceMetricsService $metrics)
    {
    }

    /** @return array<string, object> month => finance_period_closes row */
    public function closedRows(int $cid): array
    {
        return DB::table('finance_period_closes')->where('company_id', $cid)->get()->keyBy('month')->all();
    }

    private function currentMonth(int $cid): string
    {
        return FinanceContext::today($cid)->format('Y-m');
    }

    private static function lastDay(string $month): string
    {
        return Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->toDateString();
    }

    private static function validMonth(mixed $m): string
    {
        if (!is_string($m) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            throw new HttpException(422, 'Choose a month (YYYY-MM).');
        }

        return $m;
    }

    /** Months from the first activity (or first close) to the current month, ascending. */
    private function monthRange(int $cid, array $rows): array
    {
        $cur = $this->currentMonth($cid);
        $first = $this->metrics->firstActivityMonth($cid) ?? $cur;
        foreach (array_keys($rows) as $m) {
            if ($m < $first) {
                $first = $m;
            }
        }
        if ($first > $cur) {
            $first = $cur;
        }
        $out = [];
        $c = Carbon::createFromFormat('!Y-m', $first);
        $guard = 0;
        while ($c->format('Y-m') <= $cur && $guard++ < 600) {
            $out[] = $c->format('Y-m');
            $c->addMonthNoOverflow();
        }

        return $out;
    }

    public function status(int $cid, ?array $rows = null, ?array $range = null): array
    {
        $rows ??= $this->closedRows($cid);
        $range ??= $this->monthRange($cid, $rows);
        $cur = $this->currentMonth($cid);
        $closed = fn (string $m) => isset($rows[$m]) && $rows[$m]->is_closed;

        $through = null;
        foreach ($range as $m) {
            if (!$closed($m)) {
                break;
            }
            $through = $m;
        }
        $lastClosed = null;
        foreach ($range as $m) {
            if ($closed($m)) {
                $lastClosed = $m;
            }
        }
        $endedOpen = array_values(array_filter($range, fn ($m) => $m < $cur && !$closed($m)));
        $suggest = $endedOpen ? end($endedOpen) : null;
        $older = $endedOpen && $endedOpen[0] < $suggest ? $endedOpen[0] : null;
        $throughDay = $through ? self::lastDay($through) : null;

        return [
            'closedThrough' => $throughDay,
            'closedThroughLabel' => $throughDay ? Carbon::parse($throughDay)->format('j F Y') : null,
            'text' => $throughDay ? 'Everything up to ' . Carbon::parse($throughDay)->format('j F Y') . ' is closed.' : 'No months closed yet.',
            'suggestion' => $suggest ? ['month' => $suggest, 'label' => FinanceContext::monthLabel($suggest), 'text' => 'Close ' . FinanceContext::monthLabel($suggest)] : null,
            'olderOpenMonth' => $older ? ['month' => $older, 'label' => FinanceContext::monthLabel($older), 'text' => 'Close an older month instead'] : null,
            'lastClosedMonth' => $lastClosed,
        ];
    }

    private function monthRow(int $cid, string $month, ?object $r, ?float $liveNet): array
    {
        $isClosed = $r && $r->is_closed;
        $netAt = $isClosed && $r->net_at_close !== null ? round((float) $r->net_at_close, 2) : null;

        return [
            'month' => $month,
            'label' => FinanceContext::monthLabel($month),
            'from' => $month . '-01',
            'to' => self::lastDay($month),
            'status' => $isClosed ? 'closed' : 'open',
            'canClose' => !$isClosed && $month < $this->currentMonth($cid),
            'closedAt' => $isClosed ? FinanceContext::iso($cid, $r->closed_at) : null,
            'closedByName' => $isClosed ? $r->closed_by_name : null,
            'note' => $r->note ?? null,
            'netAtClose' => $netAt,
            'liveNet' => $liveNet,
            'drift' => $netAt !== null && $liveNet !== null ? round($liveNet - $netAt, 2) : null,
            'reopenedAt' => $r && $r->reopened_at ? FinanceContext::iso($cid, $r->reopened_at) : null,
            'reopenedByName' => $r->reopened_by_name ?? null,
            'reopenReason' => $r->reopen_reason ?? null,
        ];
    }

    public function overview(int $cid): array
    {
        $rows = $this->closedRows($cid);
        $range = $this->monthRange($cid, $rows);
        $live = [];
        if ($range) {
            foreach ($this->metrics->pnlMonths($cid, $range[0] . '-01', self::lastDay(end($range))) as $m) {
                $live[$m['key']] = $m['netProfit'];
            }
        }
        $months = [];
        foreach (array_reverse($range) as $m) {
            $months[] = $this->monthRow($cid, $m, $rows[$m] ?? null, $live[$m] ?? 0.0);
        }

        return [
            'context' => FinanceContext::context($cid, null, 'all', true),
            'status' => $this->status($cid, $rows, $range),
            'months' => $months,
        ];
    }

    /** P&L snapshot of a month (stored at close, compared with live figures later). */
    public function snapshot(int $cid, string $month): array
    {
        $from = $month . '-01';
        $to = self::lastDay($month);
        $p = $this->metrics->pnl($cid, $from, $to);
        $cash = $this->metrics->moneyInOut($cid, $from, $to);

        return [
            'revenue' => $p['revenue'], 'cogs' => $p['cogs'], 'grossProfit' => $p['grossProfit'], 'expenses' => $p['expenses'],
            'paymentFees' => $p['paymentFees'], 'otherIncome' => $p['otherIncome'], 'otherExpenses' => $p['otherExpenses'],
            'netProfit' => $p['netProfit'], 'moneyIn' => $cash['moneyIn'], 'moneyOut' => $cash['moneyOut'],
            'tax' => $p['info']['tax'], 'orders' => $p['info']['orders'],
        ];
    }

    public function get(int $cid, string $month): array
    {
        $month = self::validMonth($month);
        $r = DB::table('finance_period_closes')->where('company_id', $cid)->where('month', $month)->first();
        $live = $this->snapshot($cid, $month);
        $snap = $r && $r->snapshot ? json_decode($r->snapshot, true) : null;

        return $this->monthRow($cid, $month, $r, $live['netProfit']) + [
            'snapshot' => $r && $r->is_closed ? $snap : null,
            'live' => $live,
            'context' => FinanceContext::context($cid, null, 'all', true),
        ];
    }

    public function close(int $cid, mixed $month, ?string $note, ?int $userId): array
    {
        $month = self::validMonth($month);
        $label = FinanceContext::monthLabel($month);
        if ($month >= $this->currentMonth($cid)) {
            throw new HttpException(422, 'You can only close a month after it ends.');
        }
        DB::transaction(function () use ($cid, $month, $note, $userId, $label) {
            $r = DB::table('finance_period_closes')->where('company_id', $cid)->where('month', $month)->lockForUpdate()->first();
            if ($r && $r->is_closed) {
                throw new HttpException(422, "{$label} is already closed.");
            }
            $snap = $this->snapshot($cid, $month);
            $data = [
                'period_start' => $month . '-01',
                'period_end' => self::lastDay($month),
                'is_closed' => true,
                'net_at_close' => $snap['netProfit'],
                'snapshot' => json_encode($snap),
                'note' => $note !== null && $note !== '' ? mb_substr($note, 0, 500) : null,
                'closed_at' => now(),
                'closed_by' => $userId,
                'closed_by_name' => ActorName::current(),
                'updated_at' => now(),
            ];
            if ($r) {
                DB::table('finance_period_closes')->where('id', $r->id)->update($data);
                $id = (int) $r->id;
            } else {
                $id = (int) DB::table('finance_period_closes')->insertGetId($data + ['company_id' => $cid, 'month' => $month, 'created_at' => now()]);
            }
            FinancePeriodLock::log($cid, 'period', $id, 'closed', ['month' => $month, 'netAtClose' => $snap['netProfit']], $note);
        });
        FinancePeriodLock::forget($cid);

        return $this->get($cid, $month);
    }

    public function reopen(int $cid, mixed $month, mixed $reason, ?int $userId): array
    {
        $month = self::validMonth($month);
        $label = FinanceContext::monthLabel($month);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new HttpException(422, 'Give a reason for reopening.');
        }
        DB::transaction(function () use ($cid, $month, $reason, $userId, $label) {
            $r = DB::table('finance_period_closes')->where('company_id', $cid)->where('month', $month)->lockForUpdate()->first();
            if (!$r || !$r->is_closed) {
                throw new HttpException(422, "{$label} is not closed.");
            }
            DB::table('finance_period_closes')->where('id', $r->id)->update([
                'is_closed' => false,
                'reopened_at' => now(),
                'reopened_by' => $userId,
                'reopened_by_name' => ActorName::current(),
                'reopen_reason' => mb_substr($reason, 0, 500),
                'updated_at' => now(),
            ]);
            FinancePeriodLock::log($cid, 'period', (int) $r->id, 'reopened', ['month' => $month], $reason);
        });
        FinancePeriodLock::forget($cid);

        return $this->get($cid, $month);
    }
}
