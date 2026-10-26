<?php

namespace App\Http\Controllers\Api\V1\Salary;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryPayment\CreateSalaryPaymentRequest;
use App\Http\Requests\SalaryPayment\UpdateSalaryPaymentRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Salary\PayrollService;
use App\Services\Salary\SalaryPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalaryPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SalaryPaymentService $salaryPaymentService,
        private readonly PayrollService $payroll,
    ) {}

    /** saas_users id of the caller (null for legacy/staff tokens, which expenses.uploaded_by cannot reference). */
    private function actorId(Request $request): ?int
    {
        return $request->attributes->get('auth_is_legacy') ? null : ((int) $request->attributes->get('auth_user_id') ?: null);
    }

    public function payroll(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->payroll->payroll($companyId, $request->query('month')));
    }

    public function generate(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return $this->success($this->payroll->generate($companyId, $data['month']), 'Payroll generated');
    }

    public function payAll(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return $this->success($this->payroll->payAll($companyId, $this->actorId($request), $data['month']), 'Payroll paid');
    }

    public function adjust(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate([
            'bonus' => 'nullable|numeric|min:0',
            'deduction' => 'nullable|numeric|min:0',
            'method' => 'nullable|string|max:60',
            'bonusNote' => 'nullable|string|max:255',
            'deductionNote' => 'nullable|string|max:255',
        ]);

        return $this->success($this->payroll->adjust($id, $companyId, $data), 'Salary adjusted');
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $data = $request->validate(['method' => 'nullable|string|max:60', 'date' => 'nullable|date']);

        return $this->success($this->payroll->pay($id, $companyId, $this->actorId($request), $data), 'Salary paid');
    }

    public function reverse(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->payroll->reverse($id, $companyId), 'Payment reversed');
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $filters = [
            'staff_id' => $request->query('staffId'),
            'status' => $request->query('status'),
            'month' => $request->query('month'),
            'limit' => (int) $request->query('limit', 15),
            'page' => (int) $request->query('page', 1),
        ];

        $result = $this->salaryPaymentService->list($companyId, $filters);

        return $this->success($result);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateSalaryPaymentRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->create($companyId, $request->validated(), $this->actorId($request));

        return $this->success($dto->toArray(), 'Salary payment created successfully', 201);
    }

    public function update(UpdateSalaryPaymentRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->salaryPaymentService->update($id, $companyId, $request->validated(), $this->actorId($request));

        return $this->success($dto->toArray(), 'Salary payment updated successfully');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->salaryPaymentService->delete($id, $companyId);

        return $this->success(['message' => 'Salary payment deleted successfully']);
    }
}
