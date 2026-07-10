<?php

namespace App\Services\Salary;

use App\DTOs\Salary\SalaryPaymentDTO;
use App\DTOs\Salary\SalaryPaymentMapper;
use App\Models\SalaryPayment;
use App\Models\Staff;
use App\Repositories\Contracts\ISalaryPaymentRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SalaryPaymentService
{
    private readonly SalaryPaymentMapper $mapper;

    public function __construct(private readonly ISalaryPaymentRepository $repository)
    {
        $this->mapper = new SalaryPaymentMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $result = $this->repository->findByCompany($companyId, $filters);
        // The repository already returns formatted data and pagination
        return [
            'data' => $result['data'] ?? [],
            'pagination' => $result['pagination'] ?? []
        ];
    }

    public function get(int $id, int $companyId): SalaryPaymentDTO
    {
        $payment = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$payment) {
            throw new HttpException(404, 'Salary payment not found');
        }

        return $this->mapper->toDTO($payment->load('staff'));
    }

    public function create(int $companyId, array $data): SalaryPaymentDTO
    {
        $dbData = $this->mapInputToDb($data);

        $staff = Staff::where('id', $dbData['staff_id'])
            ->where('company_id', $companyId)
            ->first();

        if (!$staff) {
            throw new HttpException(400, 'Staff member not found');
        }

        $dbData['status'] = $this->calculateStatus($dbData['amount'], $dbData['paid_amount'] ?? 0);

        $existing = $this->repository->findByStaffAndMonth($dbData['staff_id'], $dbData['month']);
        if ($existing && !$existing->trashed()) {
            throw new HttpException(409, 'Salary payment for this staff and month already exists');
        }

        $payment = $this->repository->create($dbData);

        return $this->mapper->toDTO($payment->load('staff'));
    }

    public function update(int $id, int $companyId, array $data): SalaryPaymentDTO
    {
        $payment = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$payment) {
            throw new HttpException(404, 'Salary payment not found');
        }

        $dbData = $this->mapInputToDb($data);

        // Recalculate status based on amount and paid_amount
        if (isset($dbData['paid_amount']) || isset($dbData['amount'])) {
            $amount = $dbData['amount'] ?? $payment->amount;
            $paidAmount = $dbData['paid_amount'] ?? $payment->paid_amount;
            $dbData['status'] = $this->calculateStatus($amount, $paidAmount);
        }

        $updated = $this->repository->update($id, $dbData);

        return $this->mapper->toDTO($updated->load('staff'));
    }

    public function delete(int $id, int $companyId): void
    {
        $payment = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$payment) {
            throw new HttpException(404, 'Salary payment not found');
        }

        $this->repository->delete($id);
    }

    private function calculateStatus(float $amount, float $paidAmount): string
    {
        if ($paidAmount >= $amount) {
            return 'paid';
        } elseif ($paidAmount > 0) {
            return 'partial';
        }
        return 'pending';
    }

    private function mapInputToDb(array $data): array
    {
        $mapped = [
            'staff_id' => $data['staffId'] ?? ($data['staff_id'] ?? null),
            'month' => $data['month'] ?? null,
            'amount' => $data['amount'] ?? null,
            'paid_amount' => $data['paidAmount'] ?? ($data['paid_amount'] ?? null),
            'payment_date' => $data['paymentDate'] ?? ($data['payment_date'] ?? null),
            'payment_method' => $data['paymentMethod'] ?? ($data['payment_method'] ?? null),
            'notes' => $data['notes'] ?? null,
            'remarks' => $data['remarks'] ?? null,
        ];

        return array_filter($mapped, fn($v) => $v !== null);
    }
}
