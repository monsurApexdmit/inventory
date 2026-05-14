<?php

namespace App\Http\Controllers\Api\V1\CustomerReturn;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\CustomerReturn\CustomerReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontCustomerReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CustomerReturnService $customerReturnService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $companyId = $customer->company_id;

        $filters = array_merge($request->query(), ['customer_id' => $customer->id]);
        $returns = $this->customerReturnService->getByCustomer($customer->id, $companyId, $filters);

        return $this->success($returns);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $companyId = $customer->company_id;

        $dto = $this->customerReturnService->get($id, $companyId);

        // Only allow customer to see their own return
        if ($dto->customerId !== $customer->id) {
            return $this->error('Return not found', 404);
        }

        return $this->success($dto->toArray());
    }

    public function store(Request $request): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $companyId = $customer->company_id;

        $data = $request->validate([
            'sell_id'       => 'nullable|integer',
            'order_number'  => 'nullable|string',
            'refund_method' => 'nullable|string|in:original_payment,cash,store_credit',
            'notes'         => 'nullable|string',
            'items'         => 'required|array|min:1',
            'items.*.product_id'  => 'required|integer',
            'items.*.variant_id'  => 'nullable|integer',
            'items.*.quantity'    => 'required|integer|min:1',
            'items.*.reason'      => 'required|string',
            'items.*.price'       => 'nullable|numeric',
        ]);

        // Force customerId to authenticated customer
        $data['customerId'] = $customer->id;
        if (isset($data['sell_id']))      $data['sellId']      = $data['sell_id'];
        if (isset($data['order_number'])) $data['orderNumber'] = $data['order_number'];
        if (isset($data['refund_method'])) $data['refundMethod'] = $data['refund_method'];

        // Map items to camelCase expected by service
        $data['items'] = array_map(fn($item) => [
            'productId' => $item['product_id'],
            'variantId' => $item['variant_id'] ?? null,
            'quantity'  => $item['quantity'],
            'reason'    => $item['reason'],
            'price'     => $item['price'] ?? null,
        ], $data['items']);

        $dto = $this->customerReturnService->create($companyId, $data);

        return $this->success($dto->toArray(), 'Return request submitted successfully', 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $companyId = $customer->company_id;

        $dto = $this->customerReturnService->get($id, $companyId);

        if ($dto->customerId !== $customer->id) {
            return $this->error('Return not found', 404);
        }

        if ($dto->status !== 'pending') {
            return $this->error('Only pending returns can be cancelled', 422);
        }

        $this->customerReturnService->delete($id, $companyId);

        return $this->success(null, 'Return request cancelled successfully');
    }
}
