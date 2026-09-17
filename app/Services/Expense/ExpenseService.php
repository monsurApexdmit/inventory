<?php

namespace App\Services\Expense;

use App\DTOs\Expense\ExpenseDTO;
use App\DTOs\Expense\ExpenseMapper;
use App\Repositories\Contracts\IExpenseCategoryRepository;
use App\Repositories\Contracts\IExpenseRepository;
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
        if (isset($data['expenseCategoryId'])) {
            $this->assertCategoryBelongsToCompany($data['expenseCategoryId'], $companyId);
        }

        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;

        if ($receipt) {
            $dbData['receipt_path'] = $receipt->store('expenses', 'public');
        }

        $expense = $this->repository->create($dbData);

        return $this->mapper->toDTO($expense->fresh(['category', 'vendor']));
    }

    public function update(int $id, int $companyId, array $data, ?\Illuminate\Http\UploadedFile $receipt = null): ExpenseDTO
    {
        $expense = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$expense) {
            throw new HttpException(404, 'Expense not found');
        }

        if (isset($data['expenseCategoryId'])) {
            $this->assertCategoryBelongsToCompany($data['expenseCategoryId'], $companyId);
        }

        $dbData = $this->mapInputToDb($data);

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

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['expenseCategoryId'])) {
            $dbData['expense_category_id'] = $data['expenseCategoryId'];
        }
        if (isset($data['vendorId'])) {
            $dbData['vendor_id'] = $data['vendorId'];
        }
        if (isset($data['title'])) {
            $dbData['title'] = $data['title'];
        }
        if (isset($data['amount'])) {
            $dbData['amount'] = $data['amount'];
        }
        if (isset($data['expenseDate'])) {
            $dbData['expense_date'] = $data['expenseDate'];
        }
        if (isset($data['paymentMethod'])) {
            $dbData['payment_method'] = $data['paymentMethod'];
        }
        if (isset($data['referenceNo'])) {
            $dbData['reference_no'] = $data['referenceNo'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['isRecurring'])) {
            $dbData['is_recurring'] = $data['isRecurring'];
        }
        if (isset($data['recurringFrequency'])) {
            $dbData['recurring_frequency'] = $data['recurringFrequency'];
        }
        if (isset($data['recurringEndDate'])) {
            $dbData['recurring_end_date'] = $data['recurringEndDate'];
        }
        if (isset($data['uploadedBy'])) {
            $dbData['uploaded_by'] = $data['uploadedBy'];
        }

        return $dbData;
    }
}
