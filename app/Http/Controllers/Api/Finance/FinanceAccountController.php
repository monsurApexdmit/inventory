<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceListRequest;
use App\Http\Requests\Finance\StoreFinanceAccountRequest;
use App\Http\Requests\Finance\UpdateFinanceAccountRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Finance\FinanceAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinanceAccountController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinanceAccountService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function index(FinanceListRequest $request): JsonResponse
    {
        return $this->success($this->service->list($this->companyId($request), $request->filters()));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->get($id, $this->companyId($request))->toArray());
    }

    public function store(StoreFinanceAccountRequest $request): JsonResponse
    {
        return $this->success($this->service->create($this->companyId($request), $request->validated())->toArray(), 'Account created', 201);
    }

    public function update(UpdateFinanceAccountRequest $request, int $id): JsonResponse
    {
        return $this->success($this->service->update($id, $this->companyId($request), $request->validated())->toArray(), 'Account updated');
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->setDefault($id, $this->companyId($request))->toArray(), 'Default account updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->service->delete($id, $this->companyId($request));

        return $this->success(null, 'Account deleted');
    }
}
