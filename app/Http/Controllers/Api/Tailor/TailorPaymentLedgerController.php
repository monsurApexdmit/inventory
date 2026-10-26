<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Tailor\TailorPaymentLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TailorPaymentLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TailorPaymentLedgerService $svc) {}

    private function companyId(Request $r): int
    {
        return (int) $r->attributes->get('auth_company_id');
    }

    public function ledger(Request $request): JsonResponse
    {
        return $this->success($this->svc->ledger($this->companyId($request), $request->query()), 'Payments retrieved');
    }

    public function options(Request $request): JsonResponse
    {
        $kind = $request->query('kind') === 'dorji' ? 'dorji' : 'customer';
        return $this->success($this->svc->options($this->companyId($request), $kind), 'Options retrieved');
    }

    public function record(Request $request): JsonResponse
    {
        $v = $request->validate([
            'kind'           => 'required|in:customer,dorji',
            'order_id'       => 'required_if:kind,customer|nullable|integer',
            'dorji_id'       => 'required_if:kind,dorji|nullable|integer',
            'amount'         => 'required|numeric|gt:0',
            'payment_method' => 'nullable|string|max:50',
            'payment_date'   => 'required|date',
            'reference'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string|max:1000',
            'expected_balance' => 'nullable|numeric',
        ]);
        $uid = $request->attributes->get('auth_user_id');
        try {
            $res = $this->svc->record($this->companyId($request), $v, $uid ? (int) $uid : null);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->success($res, 'Payment recorded', 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->svc->reversePayout($this->companyId($request), $id);
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), $e->getCode() ?: 422);
        }
        return $this->success(null, 'Payout reversed');
    }
}
