<?php

namespace App\Services\Finance;

use App\Models\Expense;
use App\Services\Expense\ExpenseService;
use App\Support\ActorName;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Expense voids (EXP-1): the row is kept and marked voided; a reversing ledger line (expense_void) and a
 * negative P&L expense are posted on the void date, which must be in an open month. Plus the activity log.
 */
class FinanceExpenseService
{
    private const ACTION_LABELS = ['created' => 'Created', 'edited' => 'Edited', 'voided' => 'Voided', 'deleted' => 'Deleted'];

    public function __construct(private readonly ExpenseService $expenses)
    {
    }

    private function find(int $cid, int $id): Expense
    {
        $e = Expense::where('company_id', $cid)->find($id);
        if (!$e) {
            throw new HttpException(404, 'Expense not found');
        }

        return $e;
    }

    public function void(int $cid, int $id, mixed $reason, mixed $date, ?int $userId): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new HttpException(422, 'Give a reason for voiding.');
        }
        if (mb_strlen($reason) > 500) {
            throw new HttpException(422, 'The reason may not be longer than 500 characters.');
        }
        $today = FinanceContext::todayDate($cid);
        if ($date === null || $date === '') {
            $date = $today;
        } elseif (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || Carbon::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
            throw new HttpException(422, 'Void date must be a valid date (YYYY-MM-DD).');
        }

        return DB::transaction(function () use ($cid, $id, $reason, $date, $today, $userId) {
            $e = Expense::where('company_id', $cid)->lockForUpdate()->find($id);
            if (!$e) {
                throw new HttpException(404, 'Expense not found');
            }
            if ($e->voided_at !== null) {
                throw new HttpException(422, 'This expense is already voided.');
            }
            if (($e->source ?? 'manual') === 'salary') {
                throw new HttpException(422, 'Salary expenses are generated from salary payments and cannot be voided here');
            }
            if (($e->source ?? 'manual') === 'dorji_payout') {
                throw new HttpException(422, 'Dorji payout expenses are generated from Tailor payments and cannot be voided here');
            }
            if ($date > $today) {
                throw new HttpException(422, 'The void date cannot be in the future.');
            }
            if ($e->expense_date && $date < $e->expense_date->toDateString()) {
                throw new HttpException(422, 'The void date cannot be before the expense date.');
            }
            FinancePeriodLock::assertOpen($cid, $date, 'The void date is in a closed month (:month). Pick a date in an open month.');

            $e->forceFill([
                'voided_at' => $date,
                'void_reason' => $reason,
                'voided_by' => $userId,
                'voided_by_name' => ActorName::current(),
            ]);
            if ($e->is_recurring) {
                // a voided template stops repeating
                $e->is_recurring = false;
                $e->next_due_date = null;
            }
            $e->save();
            FinancePeriodLock::log($cid, 'expense', (int) $e->id, 'voided', ['amount' => round((float) $e->amount, 2), 'voidDate' => $date], $reason);

            return $this->expenses->get($id, $cid)->toArray();
        });
    }

    public function activity(int $cid, int $id): array
    {
        $e = Expense::withTrashed()->where('company_id', $cid)->find($id);
        if (!$e) {
            throw new HttpException(404, 'Expense not found');
        }
        $rows = DB::table('finance_activity_log')->where('company_id', $cid)->where('subject_type', 'expense')->where('subject_id', $id)
            ->orderBy('created_at')->orderBy('id')->get();
        $out = [];
        if (!$rows->contains(fn ($r) => $r->action === 'created')) {
            $by = null;
            try {
                $by = $e->uploaded_by ? DB::table('users')->where('id', $e->uploaded_by)->value('name') : null;
            } catch (\Throwable) {
                $by = null;
            }
            $out[] = ['id' => null, 'action' => 'created', 'label' => 'Created', 'at' => FinanceContext::iso($cid, $e->getRawOriginal('created_at')),
                'byId' => $e->uploaded_by ? (int) $e->uploaded_by : null, 'byName' => $by, 'changes' => null, 'note' => null];
        }
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r->id,
                'action' => $r->action,
                'label' => self::ACTION_LABELS[$r->action] ?? ucfirst($r->action),
                'at' => FinanceContext::iso($cid, $r->created_at),
                'byId' => $r->user_id !== null ? (int) $r->user_id : null,
                'byName' => $r->user_name,
                'changes' => $r->changes ? json_decode($r->changes, true) : null,
                'note' => $r->note,
            ];
        }

        return ['expenseId' => $id, 'rows' => $out, 'context' => FinanceContext::context($cid, null, 'all', true)];
    }
}
