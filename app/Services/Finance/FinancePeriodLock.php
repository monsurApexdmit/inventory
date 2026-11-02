<?php

namespace App\Services\Finance;

use App\Models\DuePayment;
use App\Models\Expense;
use App\Models\FinanceEntry;
use App\Models\FinanceTransfer;
use App\Models\TailorPayment;
use App\Models\VendorPayment;
use App\Support\ActorName;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Month-end close locks: nothing dated in a closed month (expenses, manual entries, transfers, sale /
 * purchase / supplier / tailor payments) may be created, changed or deleted. Registered as model
 * events so every existing endpoint is covered. Also writes the expense activity log.
 */
final class FinancePeriodLock
{
    /** model class => [date column, columns that may change while the month is closed] */
    private const GUARDED = [
        Expense::class => ['expense_date', ['voided_at', 'void_reason', 'voided_by', 'voided_by_name', 'updated_at', 'next_due_date', 'recurring_end_date', 'is_recurring', 'recurring_frequency', 'receipt_path']],
        FinanceEntry::class => ['date', ['updated_at']],
        FinanceTransfer::class => ['date', ['updated_at']],
        DuePayment::class => ['payment_date', ['updated_at']],
        VendorPayment::class => ['payment_date', ['updated_at']],
        TailorPayment::class => ['payment_date', ['updated_at']],
    ];

    /** Expense columns worth logging as edits. */
    private const EXPENSE_LOGGED = ['title', 'amount', 'expense_date', 'expense_category_id', 'vendor_id', 'location_id', 'paid_to', 'payment_method', 'reference_no', 'notes', 'is_recurring', 'recurring_frequency', 'recurring_end_date'];

    /** @var array<int, array<string, bool>> company => closed months */
    private static array $closed = [];

    public static bool $disabled = false;

    public static function register(): void
    {
        foreach (self::GUARDED as $class => [$col, $free]) {
            $class::creating(fn (Model $m) => self::check($m, $col, 'create'));
            $class::updating(function (Model $m) use ($col, $free) {
                $dirty = array_diff(array_keys($m->getDirty()), $free);
                if ($dirty) {
                    self::check($m, $col, 'update');
                }
            });
            $class::deleting(fn (Model $m) => self::check($m, $col, 'delete'));
        }

        Expense::created(fn (Expense $e) => self::logExpense($e, 'created'));
        Expense::updated(function (Expense $e) {
            if (array_key_exists('voided_at', $e->getChanges())) {
                return; // the void logs itself
            }
            $changes = [];
            foreach (array_intersect(array_keys($e->getChanges()), self::EXPENSE_LOGGED) as $k) {
                $from = $e->getOriginal($k);
                $to = $e->getAttribute($k);
                $changes[lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $k))))] = ['from' => self::plain($from), 'to' => self::plain($to)];
            }
            if ($changes) {
                self::logExpense($e, 'edited', $changes);
            }
        });
        Expense::deleted(fn (Expense $e) => self::logExpense($e, 'deleted'));
    }

    private static function plain(mixed $v): mixed
    {
        if ($v instanceof \DateTimeInterface) {
            return Carbon::instance($v)->toDateString();
        }

        return is_numeric($v) && !is_string($v) ? round((float) $v, 2) : $v;
    }

    private static function check(Model $m, string $col, string $op): void
    {
        if (self::$disabled) {
            return;
        }
        $cid = (int) ($m->company_id ?? 0);
        if (!$cid) {
            return;
        }
        $dates = [$m->getAttribute($col)];
        if ($op !== 'create' && $m->exists) {
            $dates[] = $m->getOriginal($col);
        }
        foreach ($dates as $d) {
            if ($d === null || $d === '') {
                continue;
            }
            $month = Carbon::parse($d)->format('Y-m');
            if (self::isClosedMonth($cid, $month)) {
                if ($m instanceof Expense && $op !== 'create') {
                    $label = FinanceContext::monthLabel($month);
                    throw new HttpException(422, "{$label} is closed. Void this expense and add a new one in an open month instead.");
                }
                throw new HttpException(422, self::message($month));
            }
        }
    }

    public static function message(string $month): string
    {
        $label = FinanceContext::monthLabel($month);

        return "{$label} is closed. Pick a date in an open month, or reopen {$label} in Finance → Reports.";
    }

    /** @return array<string, bool> closed months (YYYY-MM => true) */
    public static function closedMonths(int $cid): array
    {
        if (!isset(self::$closed[$cid])) {
            try {
                self::$closed[$cid] = DB::table('finance_period_closes')->where('company_id', $cid)->where('is_closed', true)
                    ->pluck('month')->mapWithKeys(fn ($m) => [(string) $m => true])->all();
            } catch (\Illuminate\Database\QueryException) {
                return []; // table not migrated yet
            }
        }

        return self::$closed[$cid];
    }

    public static function isClosedMonth(int $cid, string $month): bool
    {
        return isset(self::closedMonths($cid)[$month]);
    }

    public static function isClosedDate(int $cid, ?string $date): bool
    {
        return $date ? self::isClosedMonth($cid, substr($date, 0, 7)) : false;
    }

    /** Throw the standard 422 when $date falls in a closed month. */
    public static function assertOpen(int $cid, ?string $date, ?string $message = null): void
    {
        if ($date && self::isClosedDate($cid, $date)) {
            $month = substr($date, 0, 7);
            throw new HttpException(422, $message ? str_replace(':month', FinanceContext::monthLabel($month), $message) : self::message($month));
        }
    }

    public static function forget(int $cid): void
    {
        unset(self::$closed[$cid]);
    }

    public static function flush(): void
    {
        self::$closed = [];
    }

    public static function log(int $cid, string $type, ?int $id, string $action, ?array $changes = null, ?string $note = null): void
    {
        $uid = Auth::id();
        DB::table('finance_activity_log')->insert([
            'company_id' => $cid,
            'subject_type' => $type,
            'subject_id' => $id,
            'action' => $action,
            'changes' => $changes ? json_encode($changes) : null,
            'note' => $note !== null ? mb_substr($note, 0, 1000) : null,
            'user_id' => is_numeric($uid) ? (int) $uid : null,
            'user_name' => ActorName::current(),
            'created_at' => now(),
        ]);
    }

    private static function logExpense(Expense $e, string $action, ?array $changes = null): void
    {
        if (!$e->company_id) {
            return;
        }
        try {
            self::log((int) $e->company_id, 'expense', (int) $e->id, $action, $changes);
        } catch (\Throwable) {
            // the log never blocks the expense itself (e.g. table missing before migration)
        }
    }
}
