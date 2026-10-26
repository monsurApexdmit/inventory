<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Due\DueGroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DueGroupController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DueGroupService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function customerSummary(Request $request): JsonResponse
    {
        return $this->summary('customer', $request);
    }

    public function vendorSummary(Request $request): JsonResponse
    {
        return $this->summary('vendor', $request);
    }

    private function summary(string $kind, Request $request): JsonResponse
    {
        $request->validate([
            'payment' => 'nullable|in:all,partial,unpaid',
            'bucket' => 'nullable|in:0,1,2,3,all',
            'sort' => 'nullable|in:age,amount,name',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:500',
        ]);
        $result = $this->service->summary($kind, $this->companyId($request), $request->query());

        return $this->success($result['data'], 'Dues retrieved successfully', 200, $result['meta']);
    }

    public function customerDocuments(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->documents('customer', $this->companyId($request), $id));
    }

    public function vendorDocuments(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->documents('vendor', $this->companyId($request), $id));
    }

    public function collect(Request $request, int $id): JsonResponse
    {
        return $this->pay('customer', $request, $id, 'Payment collected successfully');
    }

    public function payVendor(Request $request, int $id): JsonResponse
    {
        return $this->pay('vendor', $request, $id, 'Payment recorded successfully');
    }

    private function pay(string $kind, Request $request, int $id, string $message): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'method' => 'nullable|string|max:50',
            'date' => 'nullable|date|before_or_equal:tomorrow',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'documentIds' => 'nullable|array',
            'documentIds.*.type' => 'required_with:documentIds|string|in:sell,purchase_order,opening_balance',
            'documentIds.*.id' => 'nullable|integer',
        ]);
        $userId = $request->attributes->get('auth_user_id');

        $result = $this->service->pay($kind, $this->companyId($request), $id, $data, $userId ? (int) $userId : null);

        return $this->success($result, $message, 201);
    }

    public function history(Request $request): JsonResponse
    {
        $request->validate(['direction' => 'nullable|in:in,out', 'limit' => 'nullable|integer|min:1|max:100']);

        return $this->success($this->service->history(
            $this->companyId($request),
            $request->query('direction', 'in'),
            (int) $request->query('limit', 15)
        ));
    }

    public function remind(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['channel' => 'required|in:SMS,WhatsApp,Copy', 'preview' => 'nullable|boolean']);
        $preview = (bool) ($data['preview'] ?? false);
        $userId = $request->attributes->get('auth_user_id');

        return $this->success(
            $this->service->remind($this->companyId($request), $id, $data['channel'], $preview, $userId ? (int) $userId : null),
            $preview ? 'Reminder preview' : 'Reminder recorded'
        );
    }
}
