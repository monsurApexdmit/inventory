<?php

namespace App\Http\Controllers\Api\V1\StockTransfer;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\StockTransfer\TransferDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransferDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TransferDocumentService $service)
    {
    }

    /** GET /transfers/documents */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $r = $this->service->list($companyId, $request->query());

        return $this->success($r['data'], 'Transfer documents retrieved successfully', 200, [
            'total'        => $r['total'],
            'per_page'     => $r['per_page'],
            'current_page' => $r['current_page'],
            'last_page'    => $r['last_page'],
            'stats'        => $r['stats'],
        ]);
    }

    /** POST /transfers/document */
    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $v = $request->validate([
            'fromLocationId'    => 'required|integer|min:1',
            'toLocationId'      => 'required|integer|min:1',
            'reason'            => 'nullable|string|max:255',
            'sentWith'          => 'nullable|string|max:255',
            'note'              => 'nullable|string|max:2000',
            'items'             => 'required|array|min:1',
            'items.*.productId' => 'required|integer|min:1',
            'items.*.variantId' => 'nullable|integer|min:1',
            'items.*.quantity'  => 'required|integer|min:1',
        ]);

        $doc = $this->service->create($companyId, [
            'from_location_id' => $v['fromLocationId'],
            'to_location_id'   => $v['toLocationId'],
            'reason'           => $v['reason'] ?? null,
            'sent_with'        => $v['sentWith'] ?? null,
            'note'             => $v['note'] ?? null,
            'items'            => array_map(fn ($i) => [
                'product_id' => $i['productId'],
                'variant_id' => $i['variantId'] ?? null,
                'quantity'   => $i['quantity'],
            ], $v['items']),
        ]);

        return $this->success($doc, 'Transfer created successfully', 201);
    }

    /** PUT /transfers/documents/{id}/cancel */
    public function cancel(int $id, Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        return $this->success($this->service->cancel($companyId, $id), 'Transfer cancelled successfully');
    }
}
