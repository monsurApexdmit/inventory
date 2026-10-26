<?php

namespace App\Services\Expense;

use App\DTOs\Expense\ExpenseDTO;
use App\DTOs\Expense\ExpenseMapper;
use App\Repositories\Contracts\IExpenseCategoryRepository;
use App\Repositories\Contracts\IExpenseRepository;
use App\Models\Expense;
use App\Models\Location;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExpenseService
{
    private readonly ExpenseMapper $mapper;

    public function __construct(
        private readonly IExpenseRepository $repository,
        private readonly IExpenseCategoryRepository $categoryRepository,
    ) {
        $this->mapper = new ExpenseMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($expense) => $this->mapper->toDTO($expense), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
            'stats' => array_merge($this->repository->getListStats($companyId, $filters), [
                'counts' => $this->repository->getTabCounts($companyId, $filters),
            ]),
        ];
    }

    public function get(int $id, int $companyId): ExpenseDTO
    {
        $expense = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$expense) {
            throw new HttpException(404, 'Expense not found');
        }

        return $this->mapper->toDTO($expense);
    }

    public function create(int $companyId, array $data, ?\Illuminate\Http\UploadedFile $receipt = null): ExpenseDTO
    {
        $this->assertRelations($data, $companyId);
        $this->assertDateOrder($data['expenseDate'] ?? null, $data['nextDueDate'] ?? null, $data['recurringEndDate'] ?? null, !empty($data['isRecurring']));

        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        $dbData['source'] = 'manual';
        if (!empty($dbData['is_recurring'])) {
            if (empty($dbData['recurring_frequency'])) {
                throw new HttpException(422, 'A recurring expense needs a frequency');
            }
            $dbData['next_due_date'] ??= Expense::advanceDate(Carbon::parse($dbData['expense_date']), $dbData['recurring_frequency'])->toDateString();
        } else {
            $dbData['is_recurring'] = false;
            $dbData['recurring_frequency'] = null;
            $dbData['recurring_end_date'] = null;
            $dbData['next_due_date'] = null;
        }

        if ($receipt) {
            $dbData['receipt_path'] = $receipt->store('expenses', 'public');
        }

        $expense = $this->repository->create($dbData);

        return $this->mapper->toDTO($expense->fresh(['category', 'vendor', 'location']));
    }

    public function update(int $id, int $companyId, array $data, ?\Illuminate\Http\UploadedFile $receipt = null): ExpenseDTO
    {
        $expense = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$expense) {
            throw new HttpException(404, 'Expense not found');
        }

        $this->assertNotLocked($expense);
        $this->assertRelations($data, $companyId);

        $dbData = $this->mapInputToDb($data);

        $isRecurring = array_key_exists('is_recurring', $dbData) ? (bool) $dbData['is_recurring'] : (bool) $expense->is_recurring;
        $this->assertDateOrder(
            $dbData['expense_date'] ?? $expense->expense_date?->toDateString(),
            array_key_exists('next_due_date', $dbData) ? $dbData['next_due_date'] : $expense->next_due_date?->toDateString(),
            array_key_exists('recurring_end_date', $dbData) ? $dbData['recurring_end_date'] : $expense->recurring_end_date?->toDateString(),
            $isRecurring,
        );
        if (!$isRecurring) {
            if (array_key_exists('is_recurring', $dbData)) {
                $dbData['recurring_frequency'] = null;
                $dbData['recurring_end_date'] = null;
                $dbData['next_due_date'] = null;
            }
        } else {
            $freq = $dbData['recurring_frequency'] ?? $expense->recurring_frequency;
            if (!$freq) {
                throw new HttpException(422, 'A recurring expense needs a frequency');
            }
            if (empty($dbData['next_due_date']) && !$expense->next_due_date) {
                $from = Carbon::parse($dbData['expense_date'] ?? $expense->expense_date);
                $dbData['next_due_date'] = Expense::advanceDate($from, $freq)->toDateString();
            }
        }

        if ($receipt) {
            $dbData['receipt_path'] = $receipt->store('expenses', 'public');
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $expense = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$expense) {
            throw new HttpException(404, 'Expense not found');
        }

        $this->assertNotLocked($expense);

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function assertCategoryBelongsToCompany(int $categoryId, int $companyId): void
    {
        $category = $this->categoryRepository->findByIdAndCompany($categoryId, $companyId);

        if (!$category) {
            throw new HttpException(404, 'Expense category not found');
        }
    }

    public function templates(int $companyId): array
    {
        return $this->repository->getTemplates($companyId, 6);
    }

    /**
     * Create child expenses for every recurring template that is due. Idempotent:
     * each template's next_due_date is advanced past $today, so a re-run creates nothing.
     *
     * @return int number of expenses created
     */
    public function generateRecurring(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $created = 0;

        foreach ($this->repository->getDueRecurring($today) as $due) {
            $created += DB::transaction(function () use ($due, $today) {
                $template = Expense::lockForUpdate()->find($due->id);
                if (!$template || !$template->is_recurring || !$template->next_due_date) {
                    return 0;
                }

                $count = 0;
                $next = Carbon::instance($template->next_due_date)->startOfDay();
                $guard = 0;
                while ($next->lte($today) && $guard++ < 1000) {
                    if ($template->recurring_end_date !== null && $next->gt($template->recurring_end_date)) {
                        break;
                    }
                    Expense::create([
                        'company_id' => $template->company_id,
                        'expense_category_id' => $template->expense_category_id,
                        'vendor_id' => $template->vendor_id,
                        'location_id' => $template->location_id,
                        'title' => $template->title,
                        'paid_to' => $template->paid_to,
                        'amount' => $template->amount,
                        'expense_date' => $next->toDateString(),
                        'payment_method' => $template->payment_method,
                        'reference_no' => $template->reference_no,
                        'notes' => $template->notes,
                        'is_recurring' => false,
                        'source' => 'recurring',
                        'uploaded_by' => $template->uploaded_by,
                    ]);
                    $count++;
                    $next = Expense::advanceDate($next, $template->recurring_frequency);
                }

                $template->next_due_date = $next->toDateString();
                $template->save();

                return $count;
            });
        }

        return $created;
    }

    /** Next due and repeat-end dates may not fall before the expense date (only checked for recurring expenses). */
    private function assertDateOrder(mixed $expenseDate, mixed $nextDue, mixed $recEnd, bool $recurring): void
    {
        if (!$recurring || !$expenseDate) {
            return;
        }
        $date = Carbon::parse($expenseDate)->startOfDay();
        if ($nextDue && Carbon::parse($nextDue)->startOfDay()->lt($date)) {
            throw new HttpException(422, 'Next due date must be on or after the expense date.');
        }
        if ($recEnd && Carbon::parse($recEnd)->startOfDay()->lt($date)) {
            throw new HttpException(422, 'The repeat end date must be on or after the expense date.');
        }
    }

    private function assertNotLocked(Expense $expense): void
    {
        if (($expense->source ?? 'manual') === 'dorji_payout') {
            throw new HttpException(422, 'Dorji payout expenses are generated from Tailor payments and cannot be edited or deleted here');
        }
        if (($expense->source ?? 'manual') === 'salary') {
            throw new HttpException(422, 'Salary expenses are generated from salary payments and cannot be edited or deleted here');
        }
    }

    private function assertRelations(array $data, int $companyId): void
    {
        if (!empty($data['expenseCategoryId'])) {
            $this->assertCategoryBelongsToCompany($data['expenseCategoryId'], $companyId);
        }
        if (!empty($data['locationId']) && !Location::where('company_id', $companyId)->where('id', $data['locationId'])->exists()) {
            throw new HttpException(404, 'Location not found');
        }
    }

    private function mapInputToDb(array $data): array
    {
        $map = [
            'expenseCategoryId' => 'expense_category_id',
            'vendorId' => 'vendor_id',
            'locationId' => 'location_id',
            'title' => 'title',
            'paidTo' => 'paid_to',
            'amount' => 'amount',
            'expenseDate' => 'expense_date',
            'paymentMethod' => 'payment_method',
            'referenceNo' => 'reference_no',
            'notes' => 'notes',
            'isRecurring' => 'is_recurring',
            'recurringFrequency' => 'recurring_frequency',
            'recurringEndDate' => 'recurring_end_date',
            'nextDueDate' => 'next_due_date',
            'uploadedBy' => 'uploaded_by',
        ];
        // Columns that may be explicitly cleared with null; the rest are NOT NULL.
        $nullable = ['expense_category_id', 'vendor_id', 'location_id', 'paid_to', 'reference_no', 'notes', 'recurring_frequency', 'recurring_end_date', 'next_due_date', 'uploaded_by'];

        $db = [];
        foreach ($map as $in => $col) {
            if (!array_key_exists($in, $data)) {
                continue;
            }
            if ($data[$in] === null && !in_array($col, $nullable, true)) {
                continue;
            }
            $db[$col] = $data[$in];
        }
        if (isset($db['recurring_frequency'])) {
            $db['recurring_frequency'] = Expense::normalizeFrequency($db['recurring_frequency']);
        }
        if (isset($db['is_recurring'])) {
            $db['is_recurring'] = filter_var($db['is_recurring'], FILTER_VALIDATE_BOOLEAN);
        }

        return $db;
    }
}
