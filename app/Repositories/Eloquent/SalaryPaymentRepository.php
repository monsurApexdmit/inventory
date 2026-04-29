<?php

namespace App\Repositories\Eloquent;

use App\Models\SalaryPayment;
use App\Models\Staff;
use App\Repositories\Contracts\ISalaryPaymentRepository;

class SalaryPaymentRepository implements ISalaryPaymentRepository
{
    public function __construct(private readonly SalaryPayment $model) {}

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->whereHas('staff', fn($q) => $q->where('company_id', $companyId));

        if (!empty($filters['staff_id'])) {
            $query = $query->where('staff_id', $filters['staff_id']);
        }

        if (!empty($filters['status'])) {
            $query = $query->where('status', $filters['status']);
        }

        if (!empty($filters['month'])) {
            $query = $query->where('month', $filters['month']);
        }

        $limit = $filters['limit'] ?? 15;
        $page = $filters['page'] ?? 1;

        $items = $query->with('staff')
            ->orderBy('month', 'desc')
            ->paginate($limit, ['*'], 'page', $page);

        return [
            'data' => $items->map(fn($item) => $this->formatSalaryPayment($item))->toArray(),
            'pagination' => [
                'page' => $items->currentPage(),
                'limit' => $items->perPage(),
                'total' => $items->total(),
                'lastPage' => $items->lastPage(),
            ],
        ];
    }

    public function findByIdAndCompany(int $id, int $companyId): ?SalaryPayment
    {
        return $this->model
            ->whereHas('staff', fn($q) => $q->where('company_id', $companyId))
            ->with('staff')
            ->find($id);
    }

    public function findByStaffAndMonth(int $staffId, string $month): ?SalaryPayment
    {
        return $this->model
            ->withTrashed()
            ->where('staff_id', $staffId)
            ->where('month', $month)
            ->first();
    }

    public function create(array $data): SalaryPayment
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): SalaryPayment
    {
        $payment = $this->model->findOrFail($id);
        $payment->fill($data)->save();
        return $payment;
    }

    public function delete(int $id): bool
    {
        return $this->model->destroy($id) > 0;
    }

    private function formatSalaryPayment(SalaryPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'staffId' => $payment->staff_id,
            'month' => $payment->month,
            'amount' => (float) $payment->amount,
            'paidAmount' => (float) $payment->paid_amount,
            'status' => ucfirst(strtolower($payment->status)),
            'paymentDate' => $payment->payment_date?->toIso8601String(),
            'paymentMethod' => $payment->payment_method,
            'notes' => $payment->notes,
            'createdAt' => $payment->created_at?->toIso8601String(),
            'updatedAt' => $payment->updated_at?->toIso8601String(),
            'staff' => $payment->relationLoaded('staff') && $payment->staff ? [
                'id' => $payment->staff->id,
                'name' => $payment->staff->name,
                'email' => $payment->staff->email,
            ] : null,
        ];
    }
}
