<?php

namespace App\Services\Salary;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Location;
use App\Models\SalaryPayment;
use App\Models\Staff;
use App\Services\Notification\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Monthly payroll: generate, adjust, pay (-> expense), reverse (-> expense removed). */
class PayrollService
{
    public const CATEGORY_NAME = 'Salaries';

    public function __construct(private readonly NotificationService $notifications)
    {
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public static function monthLabel(string $ym): string
    {
        return Carbon::createFromFormat('!Y-m', $ym)->format('M Y');
    }

    private function assertMonth(?string $month): string
    {
        $month = $month ?: now()->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new HttpException(422, 'month must be in YYYY-MM format');
        }
        return $month;
    }

    private function money(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }

    private function base(SalaryPayment $p): float
    {
        return (float) ($p->base_amount ?? $p->amount);
    }

    /** True when the staff member had joined by the end of $month (unparseable/empty dates count as joined). */
    private function joinedBy(Staff $s, string $month): bool
    {
        $j = trim((string) $s->joining_date);
        if ($j === '') {
            return true;
        }
        if (preg_match('/^(\d{4})-(\d{2})/', $j, $m)) {
            return ($m[1] . '-' . $m[2]) <= $month;
        }
        try {
            return Carbon::parse($j)->format('Y-m') <= $month;
        } catch (\Throwable) {
            return true;
        }
    }

    private function roleNames(int $companyId): array
    {
        return DB::table('staff_roles')->where('company_id', $companyId)->pluck('name', 'id')->all();
    }

    private function roleOf(Staff $s, array $roles): string
    {
        return ($s->staff_role_id && isset($roles[$s->staff_role_id])) ? $roles[$s->staff_role_id] : (string) $s->role;
    }

    private function paymentQuery(int $companyId)
    {
        return SalaryPayment::query()->whereHas('staff', fn ($q) => $q->withTrashed()->where('company_id', $companyId));
    }

    public function findPayment(int $id, int $companyId, bool $lock = false): SalaryPayment
    {
        $q = $this->paymentQuery($companyId)->with(['staff' => fn ($q) => $q->withTrashed()]);
        if ($lock) {
            $q->lockForUpdate();
        }
        $p = $q->find($id);
        if (!$p) {
            throw new HttpException(404, 'Salary payment not found');
        }
        return $p;
    }

    private function paymentRow(SalaryPayment $p, array $roles): array
    {
        $s = $p->staff;
        $base = $this->base($p);
        return [
            'staffId' => (int) $p->staff_id,
            'name' => $s?->name ?? '(removed staff)',
            'role' => $s ? $this->roleOf($s, $roles) : '',
            'joined' => $s?->joining_date,
            'paymentId' => (int) $p->id,
            'base' => $base,
            'bonus' => (float) $p->bonus,
            'deduction' => (float) $p->deduction,
            'bonusNote' => $p->bonus_note,
            'deductionNote' => $p->deduction_note,
            'net' => (float) $p->amount,
            'method' => $p->payment_method ?: ($s?->payment_method ?: 'Cash'),
            'status' => strtolower((string) $p->status) === 'paid' ? 'paid' : 'pending',
        ];
    }

    // ------------------------------------------------------------------
    // Payroll view
    // ------------------------------------------------------------------

    public function payroll(int $companyId, ?string $month): array
    {
        $month = $this->assertMonth($month);
        $roles = $this->roleNames($companyId);

        $payments = $this->paymentQuery($companyId)->where('month', $month)
            ->with(['staff' => fn ($q) => $q->withTrashed()])->get();
        $rows = $payments->map(fn ($p) => $this->paymentRow($p, $roles))->all();

        $have = $payments->pluck('staff_id')->all();
        $eligible = Staff::where('company_id', $companyId)->where('status', 'Active')->where('salary', '>', 0)
            ->whereNotIn('id', $have ?: [0])->get()
            ->filter(fn ($s) => $this->joinedBy($s, $month));
        foreach ($eligible as $s) {
            $rows[] = [
                'staffId' => (int) $s->id,
                'name' => $s->name,
                'role' => $this->roleOf($s, $roles),
                'joined' => $s->joining_date,
                'paymentId' => null,
                'base' => (float) $s->salary,
                'bonus' => 0.0,
                'deduction' => 0.0,
                'bonusNote' => null,
                'deductionNote' => null,
                'net' => (float) $s->salary,
                'method' => $s->payment_method ?: 'Cash',
                'status' => 'not_generated',
            ];
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        $col = collect($rows);
        $paid = $col->where('status', 'paid');
        $pending = $col->where('status', 'pending');

        $yearPaid = (float) $this->paymentQuery($companyId)->where('status', 'paid')
            ->where('month', 'like', now()->format('Y') . '-%')->sum('amount');

        $end = Carbon::createFromFormat('!Y-m', $month)->endOfMonth()->startOfDay();

        $sel = Carbon::createFromFormat('!Y-m', $month);
        $months = collect([$sel->copy()->subMonthNoOverflow(), $sel, $sel->copy()->addMonthNoOverflow()])
            ->map(fn ($d) => ['month' => $d->format('Y-m'), 'label' => $d->format('M Y')])->all();

        return [
            'month' => $month,
            'label' => self::monthLabel($month),
            'payday' => $end->toDateString(),
            'daysLeft' => (int) now()->startOfDay()->diffInDays($end, false),
            'rows' => $rows,
            'stats' => [
                'baseTotal' => round($col->sum('base'), 2),
                'netTotal' => round($col->sum('net'), 2),
                'paid' => round($paid->sum('net'), 2),
                'paidCount' => $paid->count(),
                'pendingNet' => round($pending->sum('net'), 2),
                'pendingCount' => $pending->count(),
                'notGenerated' => $col->where('status', 'not_generated')->count(),
                'paidThisYear' => round($yearPaid, 2),
                'peopleCount' => $col->count(),
            ],
            'history' => $this->history($companyId),
            'months' => $months,
        ];
    }

    private function history(int $companyId): array
    {
        $recs = $this->paymentQuery($companyId)->get(['month', 'amount', 'status', 'staff_id']);
        return $recs->groupBy('month')->map(function ($g, $m) {
            $paid = $g->filter(fn ($p) => strtolower($p->status) === 'paid');
            $pending = $g->reject(fn ($p) => strtolower($p->status) === 'paid');
            return [
                'month' => $m,
                'label' => preg_match('/^\d{4}-\d{2}$/', (string) $m) ? self::monthLabel($m) : (string) $m,
                'people' => $g->count(),
                'net' => round((float) $g->sum('amount'), 2),
                'paid' => round((float) $paid->sum('amount'), 2),
                'pending' => round((float) $pending->sum('amount'), 2),
            ];
        })->sortByDesc('month')->values()->all();
    }

    // ------------------------------------------------------------------
    // Generate / adjust
    // ------------------------------------------------------------------

    public function generate(int $companyId, ?string $month): array
    {
        $month = $this->assertMonth($month);
        $created = 0;

        DB::transaction(function () use ($companyId, $month, &$created) {
            $staff = Staff::where('company_id', $companyId)->where('status', 'Active')->where('salary', '>', 0)->get();
            foreach ($staff as $s) {
                if (!$this->joinedBy($s, $month)) {
                    continue;
                }
                $exists = SalaryPayment::where('staff_id', $s->id)->where('month', $month)->exists();
                if ($exists) {
                    continue;
                }
                SalaryPayment::create([
                    'staff_id' => $s->id,
                    'month' => $month,
                    'amount' => $s->salary,
                    'base_amount' => $s->salary,
                    'bonus' => 0,
                    'deduction' => 0,
                    'paid_amount' => 0,
                    'status' => 'pending',
                    'payment_method' => $s->payment_method ?: 'Cash',
                ]);
                $created++;
            }
        });

        return array_merge($this->payroll($companyId, $month), ['created' => $created]);
    }

    public function adjust(int $id, int $companyId, array $data): array
    {
        return DB::transaction(function () use ($id, $companyId, $data) {
            $p = $this->findPayment($id, $companyId, true);
            if (strtolower($p->status) === 'paid') {
                throw new HttpException(422, 'Paid salaries cannot be changed.');
            }

            $bonus = round((float) ($data['bonus'] ?? $p->bonus), 2);
            $deduction = round((float) ($data['deduction'] ?? $p->deduction), 2);
            if ($bonus < 0 || $deduction < 0) {
                throw new HttpException(422, 'Amounts cannot be negative.');
            }
            $base = $this->base($p);
            $net = round($base + $bonus - $deduction, 2);
            if ($net < 0) {
                throw new HttpException(422, 'Deduction cannot exceed base pay plus bonus.');
            }

            $p->base_amount = $base;
            $p->bonus = $bonus;
            $p->deduction = $deduction;
            $p->amount = $net;
            if (array_key_exists('method', $data) && $data['method']) {
                $p->payment_method = $data['method'];
            }
            if (array_key_exists('bonusNote', $data)) {
                $p->bonus_note = $data['bonusNote'];
            }
            if (array_key_exists('deductionNote', $data)) {
                $p->deduction_note = $data['deductionNote'];
            }
            $p->status = 'pending';
            $p->save();

            return $this->paymentRow($p->fresh(['staff' => fn ($q) => $q->withTrashed()]), $this->roleNames($companyId));
        });
    }

    // ------------------------------------------------------------------
    // Pay / reverse
    // ------------------------------------------------------------------

    public function pay(int $id, int $companyId, ?int $userId, array $opts = []): array
    {
        $payment = DB::transaction(function () use ($id, $companyId, $userId, $opts) {
            $p = $this->findPayment($id, $companyId, true);
            if (strtolower($p->status) === 'paid') {
                throw new HttpException(422, 'This salary is already paid.');
            }
            if ((float) $p->amount <= 0) {
                throw new HttpException(422, 'Net pay must be greater than zero.');
            }

            $date = !empty($opts['date']) ? Carbon::parse($opts['date'])->startOfDay() : now();
            $p->status = 'paid';
            $p->paid_amount = $p->amount;
            $p->payment_date = $date;
            if (!empty($opts['method'])) {
                $p->payment_method = $opts['method'];
            } elseif (!$p->payment_method) {
                $p->payment_method = $p->staff?->payment_method ?: 'Cash';
            }
            if ($p->base_amount === null) {
                $p->base_amount = $p->amount;
            }
            $p->save();

            $this->syncExpense($p, $userId);

            return $p->fresh(['staff' => fn ($q) => $q->withTrashed()]);
        });

        $this->notifyPaid($payment, $companyId);

        return $this->paymentRow($payment, $this->roleNames($companyId));
    }

    public function payAll(int $companyId, ?int $userId, ?string $month): array
    {
        $month = $this->assertMonth($month);
        $ids = $this->paymentQuery($companyId)->where('month', $month)
            ->where('status', '!=', 'paid')->orderBy('id')->pluck('id');

        $paid = 0;
        $skipped = 0;
        $failed = [];
        $total = 0.0;
        foreach ($ids as $id) {
            try {
                $row = $this->pay((int) $id, $companyId, $userId);
                $paid++;
                $total += $row['net'];
            } catch (HttpException $e) {
                $skipped++;
                $failed[] = ['paymentId' => (int) $id, 'message' => $e->getMessage()];
            }
        }

        return [
            'month' => $month,
            'paidCount' => $paid,
            'skippedCount' => $skipped,
            'paidTotal' => round($total, 2),
            'errors' => $failed,
        ];
    }

    public function reverse(int $id, int $companyId): array
    {
        return DB::transaction(function () use ($id, $companyId) {
            $p = $this->findPayment($id, $companyId, true);
            if (strtolower($p->status) !== 'paid') {
                throw new HttpException(422, 'Only paid salaries can be reversed.');
            }
            $p->status = 'pending';
            $p->paid_amount = 0;
            $p->payment_date = null;
            $p->save();

            $this->removeExpense($p);

            return $this->paymentRow($p->fresh(['staff' => fn ($q) => $q->withTrashed()]), $this->roleNames($companyId));
        });
    }

    // ------------------------------------------------------------------
    // Expense linkage
    // ------------------------------------------------------------------

    public function salariesCategory(int $companyId): ExpenseCategory
    {
        $cat = ExpenseCategory::withTrashed()->where('company_id', $companyId)->where('name', self::CATEGORY_NAME)->first();
        if ($cat) {
            if ($cat->trashed()) {
                $cat->restore();
            }
            return $cat;
        }

        return ExpenseCategory::create([
            'company_id' => $companyId,
            'name' => self::CATEGORY_NAME,
            'description' => 'Staff pay and bonuses',
            'color' => '#5B21B6',
            'type' => 'Operating cost',
            'status' => true,
        ]);
    }

    /**
     * Keep exactly one 'salary' expense for a paid payment and none otherwise.
     * Call inside the transaction that changed the payment.
     */
    public function syncExpense(SalaryPayment $p, ?int $userId = null): void
    {
        if (strtolower((string) $p->status) !== 'paid') {
            $this->removeExpense($p);
            return;
        }

        $p->loadMissing(['staff' => fn ($q) => $q->withTrashed()]);
        $staff = $p->staff;
        $companyId = (int) $staff->company_id;
        $label = preg_match('/^\d{4}-\d{2}$/', (string) $p->month) ? self::monthLabel($p->month) : (string) $p->month;
        $date = $p->payment_date ? Carbon::parse($p->payment_date)->toDateString() : now()->toDateString();

        $locationId = null;
        foreach ((array) $staff->location_ids as $lid) {
            if (Location::where('company_id', $companyId)->where('id', $lid)->exists()) {
                $locationId = (int) $lid;
                break;
            }
        }

        $attrs = [
            'company_id' => $companyId,
            'expense_category_id' => $this->salariesCategory($companyId)->id,
            'location_id' => $locationId,
            'title' => "Salary — {$staff->name} {$label}",
            'paid_to' => $staff->name,
            'amount' => (float) $p->amount,
            'expense_date' => $date,
            'payment_method' => $p->payment_method ?: ($staff->payment_method ?: 'Cash'),
            'notes' => "Salary {$label}",
            'source' => 'salary',
            'salary_payment_id' => $p->id,
            'is_recurring' => false,
        ];

        $existing = Expense::where('salary_payment_id', $p->id)->where('source', 'salary')->get();
        if ($existing->isNotEmpty()) {
            $keep = $existing->first();
            $existing->skip(1)->each->delete();
            $keep->update($attrs);
            return;
        }

        $attrs['uploaded_by'] = $userId ? (\App\Models\SaasUser::where('id', $userId)->exists() ? $userId : null) : null;
        Expense::create($attrs);
    }

    public function removeExpense(SalaryPayment $p): void
    {
        Expense::where('salary_payment_id', $p->id)->where('source', 'salary')->get()->each->delete();
    }

    private function notifyPaid(SalaryPayment $p, int $companyId): void
    {
        try {
            $label = preg_match('/^\d{4}-\d{2}$/', (string) $p->month) ? self::monthLabel($p->month) : (string) $p->month;
            $this->notifications->notifySalaryPaid($companyId, $p->staff?->name ?? 'Staff member', $this->money((float) $p->amount), $label);
        } catch (\Throwable $e) {
            // a failed notification must not undo a payment
        }
    }

    // ------------------------------------------------------------------
    // Per staff summary (staff details panel)
    // ------------------------------------------------------------------

    public function staffSummary(Staff $staff): array
    {
        $recs = SalaryPayment::where('staff_id', $staff->id)->orderByDesc('month')->orderByDesc('id')->get();
        $thisMonth = now()->format('Y-m');
        $thisYear = now()->format('Y');
        $isPaid = fn ($p) => strtolower($p->status) === 'paid';

        $paidYear = $recs->filter(fn ($p) => $isPaid($p) && str_starts_with((string) $p->month, $thisYear))->sum('amount');
        $due = $recs->filter(fn ($p) => !$isPaid($p) && (string) $p->month <= $thisMonth)->sum('amount');

        $history = $recs->take(6)->map(function ($p) use ($isPaid) {
            $paid = $isPaid($p);
            $detail = 'Base ' . $this->money($this->base($p));
            if ((float) $p->bonus) {
                $detail .= ' · +' . $this->money((float) $p->bonus);
            }
            if ((float) $p->deduction) {
                $detail .= ' · −' . $this->money((float) $p->deduction);
            }
            if ($paid && $p->payment_date) {
                $detail .= ' · paid ' . $p->payment_date->format('j M Y');
            }
            return [
                'id' => (int) $p->id,
                'month' => $p->month,
                'label' => preg_match('/^\d{4}-\d{2}$/', (string) $p->month) ? self::monthLabel($p->month) : (string) $p->month,
                'detail' => $detail,
                'net' => (float) $p->amount,
                'status' => $paid ? 'paid' : 'pending',
                'canPay' => !$paid && (float) $p->amount > 0,
            ];
        })->values()->all();

        return [
            'monthly' => (float) $staff->salary,
            'paidThisYear' => round((float) $paidYear, 2),
            'dueNow' => round((float) $due, 2),
            'history' => $history,
        ];
    }
}
