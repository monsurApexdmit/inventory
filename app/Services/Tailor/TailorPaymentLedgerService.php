<?php

namespace App\Services\Tailor;

use App\Models\TailorDorji;
use App\Models\TailorOrder;
use App\Models\TailorPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ledger of customer payments received and dorji payouts for the Payments screen.
 */
class TailorPaymentLedgerService
{
    public const METHODS = [
        'cash'  => 'Cash',
        'bank'  => 'Bank transfer',
        'bkash' => 'bKash',
        'nagad' => 'Nagad',
        'card'  => 'Card',
    ];

    private const METHOD_ALIASES = [
        'cash'  => ['cash'],
        'bank'  => ['bank', 'bank transfer', 'bank_transfer'],
        'bkash' => ['bkash', 'mobile banking'],
        'nagad' => ['nagad'],
        'card'  => ['card'],
    ];

    public function methodKey(?string $m): string
    {
        $l = strtolower(trim((string) $m));
        foreach (self::METHOD_ALIASES as $key => $aliases) {
            if (in_array($l, $aliases, true)) return $key;
        }
        return $l ?: 'cash';
    }

    private function typeOf(TailorPayment $p): string
    {
        if ($p->kind === 'dorji') return 'payout';
        return $p->payment_type ?: ($p->notes === 'Advance payment' ? 'advance' : 'payment');
    }

    private const TYPE_LABELS = ['advance' => 'Advance', 'payment' => 'Payment', 'final' => 'Final payment', 'payout' => 'Payout'];

    // ─── Owed / due helpers ──────────────────────────────────────────────────

    /** dorji_id => amount owed for completed work. */
    public function dorjiOwed(int $companyId): array
    {
        $owed = [];
        DB::table('tailor_assignments')
            ->where('company_id', $companyId)->whereNull('deleted_at')->where('work_status', 'completed')
            ->get(['dorji_id', 'dorji_charge', 'paid_amount'])
            ->each(function ($a) use (&$owed) {
                $rem = max(0, (float) $a->dorji_charge - (float) $a->paid_amount);
                if ($rem > 0) $owed[$a->dorji_id] = round(($owed[$a->dorji_id] ?? 0) + $rem, 2);
            });
        return $owed;
    }

    private function dueOrders(int $companyId): Builder
    {
        return TailorOrder::query()->where('company_id', $companyId)
            ->where('order_status', '!=', 'cancelled')->where('due_amount', '>', 0);
    }

    // ─── Ledger ──────────────────────────────────────────────────────────────

    public function ledger(int $companyId, array $f = []): array
    {
        $kind    = ($f['kind'] ?? 'customer') === 'dorji' ? 'dorji' : 'customer';
        $perPage = max(1, min((int) ($f['per_page'] ?? 10), 100));
        $page    = max(1, (int) ($f['page'] ?? 1));

        $q = TailorPayment::query()->where('company_id', $companyId)->where('kind', $kind);

        if (!empty($f['method']) && $f['method'] !== 'all') {
            $q->whereIn(DB::raw('LOWER(payment_method)'), self::METHOD_ALIASES[$f['method']] ?? [strtolower($f['method'])]);
        }
        $range = $f['range'] ?? 'all';
        if ($range === 'month') $q->whereDate('payment_date', '>=', now()->startOfMonth()->toDateString())->whereDate('payment_date', '<=', now()->toDateString());
        if ($range === 'today') $q->whereDate('payment_date', now()->toDateString());
        if (!empty($f['from'])) $q->whereDate('payment_date', '>=', $f['from']);
        if (!empty($f['to']))   $q->whereDate('payment_date', '<=', $f['to']);
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $q->where(function ($w) use ($s) {
                $w->where('reference', 'like', $s)->orWhere('notes', 'like', $s)
                  ->orWhereIn('order_id', TailorOrder::select('id')->where('order_number', 'like', $s)
                      ->orWhereIn('customer_id', DB::table('tailor_customers')->select('id')->where('name', 'like', $s)->orWhere('phone', 'like', $s)))
                  ->orWhereIn('dorji_id', DB::table('tailor_dorjis')->select('id')->where('name', 'like', $s));
            });
        }

        $total       = (clone $q)->count();
        $totalAmount = round((float) (clone $q)->sum('amount'), 2);
        $items = $q->with('order.customer')->orderByDesc('payment_date')->orderByDesc('id')
            ->forPage($page, $perPage)->get();

        $dorjiNames = TailorDorji::withTrashed()->whereIn('id', $items->pluck('dorji_id')->filter())->pluck('name', 'id');

        $rows = $items->map(function (TailorPayment $p) use ($dorjiNames) {
            $type = $this->typeOf($p);
            $key  = $this->methodKey($p->payment_method);
            $orderNo = $p->order?->order_number;
            $extra = collect([$p->reference, $p->notes === 'Advance payment' ? null : $p->notes])->filter()->implode(' · ');
            return [
                'id'            => $p->id,
                'kind'          => $p->kind,
                'payment_date'  => optional($p->payment_date)->toDateString(),
                'who'           => $p->kind === 'dorji'
                    ? ($dorjiNames[$p->dorji_id] ?? 'Unknown dorji')
                    : ($p->order?->customer?->name ?? '—'),
                'order_id'      => $p->order_id,
                'order_number'  => $orderNo,
                'dorji_id'      => $p->dorji_id,
                'type'          => $type,
                'type_label'    => self::TYPE_LABELS[$type] ?? ucfirst($type),
                'method'        => $key,
                'method_label'  => self::METHODS[$key] ?? ($p->payment_method ?: '—'),
                'amount'        => (float) $p->amount,
                'reference'     => $p->reference,
                'notes'         => $p->notes,
                'created_at'    => optional($p->created_at)->toDateString(),
                'ref_label'     => ($orderNo ?: ($p->kind === 'dorji' ? 'Payout' : '—')) . ($extra ? ' · ' . $extra : ''),
            ];
        })->all();

        return [
            'data'         => $rows,
            'total'        => $total,
            'page'         => $page,
            'per_page'     => $perPage,
            'total_amount' => $totalAmount,
            'counts'       => [
                'customer' => TailorPayment::where('company_id', $companyId)->where('kind', 'customer')->count(),
                'dorji'    => TailorPayment::where('company_id', $companyId)->where('kind', 'dorji')->count(),
            ],
            'stats'        => $this->stats($companyId),
        ];
    }

    public function stats(int $companyId): array
    {
        $from = now()->startOfMonth()->toDateString();
        $in  = TailorPayment::where('company_id', $companyId)->where('kind', 'customer')->whereDate('payment_date', '>=', $from);
        $out = TailorPayment::where('company_id', $companyId)->where('kind', 'dorji')->whereDate('payment_date', '>=', $from);
        $due = $this->dueOrders($companyId);

        return [
            'collected_month'       => round((float) (clone $in)->sum('amount'), 2),
            'collected_month_count' => (clone $in)->count(),
            'to_collect'            => round((float) (clone $due)->sum('due_amount'), 2),
            'to_collect_orders'     => (clone $due)->count(),
            'paid_dorjis_month'     => round((float) (clone $out)->sum('amount'), 2),
            'paid_dorjis_count'     => (clone $out)->count(),
            'owed_dorjis'           => round(array_sum($this->dorjiOwed($companyId)), 2),
        ];
    }

    // ─── Record form options ─────────────────────────────────────────────────

    public function options(int $companyId, string $kind): array
    {
        if ($kind === 'dorji') {
            $owed = $this->dorjiOwed($companyId);
            if (!$owed) return [];
            return TailorDorji::where('company_id', $companyId)->whereIn('id', array_keys($owed))->orderBy('name')->get()
                ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'balance' => $owed[$d->id]])->all();
        }

        return $this->dueOrders($companyId)->with('customer')->orderByDesc('id')->limit(300)->get()
            ->map(fn ($o) => [
                'id'            => $o->id,
                'order_number'  => $o->order_number,
                'name'          => $o->customer?->name,
                'balance'       => round((float) $o->due_amount, 2),
                'total_amount'  => (float) $o->total_amount,
                'paid_amount'   => (float) $o->paid_amount,
            ])->all();
    }

    // ─── Record ──────────────────────────────────────────────────────────────

    public function record(int $companyId, array $d, ?int $userId = null): array
    {
        $amount = round((float) $d['amount'], 2);
        $date   = $d['payment_date'];
        if ($date > now()->toDateString()) {
            throw ValidationException::withMessages(['payment_date' => 'Payment date cannot be in the future.']);
        }
        $method = $this->methodKey($d['payment_method'] ?? 'cash');

        return DB::transaction(function () use ($companyId, $d, $amount, $date, $method, $userId) {
            if (($d['kind'] ?? 'customer') === 'dorji') {
                return $this->recordPayout($companyId, (int) ($d['dorji_id'] ?? 0), $amount, $date, $method, $d, $userId);
            }

            $order = TailorOrder::with('items')->where('company_id', $companyId)
                ->lockForUpdate()->findOrFail((int) ($d['order_id'] ?? 0));
            if ($order->order_status === 'cancelled') {
                throw ValidationException::withMessages(['order_id' => 'This order is cancelled.']);
            }
            $due = round(max(0, (float) $order->total_amount - (float) $order->paid_amount), 2);
            $this->assertBalanceUnchanged($d, $due, 'order');
            if ($amount > $due + 0.005) {
                throw ValidationException::withMessages(['amount' => 'Amount is more than the balance (' . number_format($due, 2) . ').']);
            }

            $payment = TailorPayment::create([
                'company_id'     => $companyId,
                'kind'           => 'customer',
                'order_id'       => $order->id,
                'amount'         => $amount,
                'payment_method' => $method,
                'payment_type'   => $amount + 0.005 >= $due ? 'final' : 'payment',
                'payment_date'   => $date,
                'reference'      => $d['reference'] ?? null,
                'notes'          => $d['notes'] ?? null,
                'created_by'     => $userId,
            ]);

            $order->paid_amount = TailorPayment::where('order_id', $order->id)->sum('amount');
            $order->recalculate();
            $order->save();

            return ['id' => $payment->id, 'kind' => 'customer', 'amount' => $amount, 'balance_after' => (float) $order->due_amount];
        });
    }

    /** The client sends the balance it showed; a different current balance means it changed meanwhile. */
    private function assertBalanceUnchanged(array $d, float $current, string $what): void
    {
        if (!isset($d['expected_balance']) || $d['expected_balance'] === '') return;
        if (abs(round((float) $d['expected_balance'], 2) - $current) > 0.005) {
            throw new \DomainException("This {$what} balance changed. Review and retry.", 409);
        }
    }

    /** Mirror a dorji payout into Expenses (category Salaries, source dorji_payout, reference TP-{payment id}). */
    private function syncPayoutExpense(int $companyId, TailorPayment $payment, TailorDorji $dorji, ?int $userId): void
    {
        $cat = app(\App\Services\Salary\PayrollService::class)->salariesCategory($companyId);
        $uploader = $userId && \App\Models\SaasUser::where('id', $userId)->exists() ? $userId : null;
        \App\Models\Expense::create([
            'company_id' => $companyId,
            'expense_category_id' => $cat->id,
            'title' => "Dorji payout — {$dorji->name}",
            'paid_to' => $dorji->name,
            'amount' => (float) $payment->amount,
            'expense_date' => $payment->payment_date,
            'payment_method' => self::METHODS[$payment->payment_method] ?? ($payment->payment_method ?: 'Cash'),
            'reference_no' => 'TP-' . $payment->id,
            'notes' => 'Dorji payout' . ($payment->notes ? ' · ' . $payment->notes : ''),
            'source' => 'dorji_payout',
            'is_recurring' => false,
            'uploaded_by' => $uploader,
        ]);
    }

    /** Remove the Expenses row of a dorji payout (call when the payout is deleted or reversed). */
    public function removePayoutExpense(TailorPayment $payment): void
    {
        \App\Models\Expense::where('source', 'dorji_payout')->where('company_id', $payment->company_id)
            ->where('reference_no', 'TP-' . $payment->id)->get()->each->delete();
    }

    private function recordPayout(int $companyId, int $dorjiId, float $amount, string $date, string $method, array $d, ?int $userId): array
    {
        $dorji = TailorDorji::where('company_id', $companyId)->findOrFail($dorjiId);

        $assignments = DB::table('tailor_assignments')
            ->where('company_id', $companyId)->where('dorji_id', $dorji->id)->whereNull('deleted_at')
            ->where('work_status', 'completed')->orderBy('assigned_date')->orderBy('id')->lockForUpdate()->get();
        $owed = round($assignments->sum(fn ($a) => max(0, (float) $a->dorji_charge - (float) $a->paid_amount)), 2);

        $this->assertBalanceUnchanged($d, $owed, 'dorji');
        if ($amount > $owed + 0.005) {
            throw ValidationException::withMessages(['amount' => 'Amount is more than what is owed to this dorji (' . number_format($owed, 2) . ').']);
        }

        $left = $amount;
        foreach ($assignments as $a) {
            if ($left <= 0) break;
            $rem  = max(0, (float) $a->dorji_charge - (float) $a->paid_amount);
            $take = round(min($left, $rem), 2);
            if ($take <= 0) continue;
            $newPaid = round((float) $a->paid_amount + $take, 2);
            $update = ['paid_amount' => $newPaid];
            // A job that is fully paid out becomes 'paid' (locked on the Assignments page)
            if ($newPaid + 0.005 >= (float) $a->dorji_charge) {
                $update['work_status'] = 'paid';
            }
            DB::table('tailor_assignments')->where('id', $a->id)->update($update);
            $left = round($left - $take, 2);
        }

        $payment = TailorPayment::create([
            'company_id'     => $companyId,
            'kind'           => 'dorji',
            'order_id'       => null,
            'dorji_id'       => $dorji->id,
            'amount'         => $amount,
            'payment_method' => $method,
            'payment_type'   => 'payout',
            'payment_date'   => $date,
            'reference'      => $d['reference'] ?? null,
            'notes'          => $d['notes'] ?? null,
            'created_by'     => $userId,
        ]);
        $this->syncPayoutExpense($companyId, $payment, $dorji, $userId);

        return ['id' => $payment->id, 'kind' => 'dorji', 'amount' => $amount, 'balance_after' => round($owed - $amount, 2)];
    }

    // ─── Reverse ─────────────────────────────────────────────────────────────

    /**
     * Reverse a dorji payout: inverts recordPayout()'s oldest-first allocation, newest-first,
     * so only the dorji's most recent payout can be reversed (keeps the inversion exact).
     */
    public function reversePayout(int $companyId, int $paymentId): void
    {
        DB::transaction(function () use ($companyId, $paymentId) {
            $payment = TailorPayment::where('company_id', $companyId)->lockForUpdate()->findOrFail($paymentId);
            if ($payment->kind !== 'dorji') {
                throw new \DomainException('Only dorji payouts can be reversed', 422);
            }
            $later = TailorPayment::where('company_id', $companyId)->where('kind', 'dorji')
                ->where('dorji_id', $payment->dorji_id)->where('id', '>', $payment->id)->exists();
            if ($later) {
                throw new \DomainException('Reverse this dorji\'s most recent payout first', 422);
            }

            $left = round((float) $payment->amount, 2);
            $assignments = DB::table('tailor_assignments')
                ->where('company_id', $companyId)->where('dorji_id', $payment->dorji_id)->whereNull('deleted_at')
                ->whereIn('work_status', ['completed', 'paid'])->where('paid_amount', '>', 0)
                ->orderByDesc('assigned_date')->orderByDesc('id')->lockForUpdate()->get();
            foreach ($assignments as $a) {
                if ($left <= 0) break;
                $give = round(min($left, (float) $a->paid_amount), 2);
                if ($give <= 0) continue;
                $newPaid = round((float) $a->paid_amount - $give, 2);
                $upd = ['paid_amount' => $newPaid];
                if ($a->work_status === 'paid' && $newPaid + 0.005 < (float) $a->dorji_charge) {
                    $upd['work_status'] = 'completed';
                }
                DB::table('tailor_assignments')->where('id', $a->id)->update($upd);
                $left = round($left - $give, 2);
            }

            $this->removePayoutExpense($payment);
            $payment->delete();
        });
    }
}
