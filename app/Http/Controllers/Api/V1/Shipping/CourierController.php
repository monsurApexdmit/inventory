<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\BookCourierRequest;
use App\Http\Requests\Shipping\BulkBookCourierRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Courier\CourierAccountService;
use App\Services\Courier\CourierShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Courier booking, quotes and parcel sync (contract: COURIER_API_CONTRACT.md §3–4). */
class CourierController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CourierShipmentService $service,
        private readonly CourierAccountService $accounts,
    ) {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    public function accounts(Request $request): JsonResponse
    {
        return $this->success($this->accounts->listForBooking($this->companyId($request)));
    }

    public function locations(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:city,zone,area',
            'parentId' => 'nullable|integer|min:1',
        ]);

        return $this->success($this->accounts->locations($id, $this->companyId($request), $data['type'], isset($data['parentId']) ? (int) $data['parentId'] : null));
    }

    public function stores(Request $request, int $id): JsonResponse
    {
        return $this->success($this->accounts->stores($id, $this->companyId($request)));
    }

    public function book(BookCourierRequest $request, int $id): JsonResponse
    {
        $dto = $this->service->book($id, $this->companyId($request), $request->validated());

        return $this->success($dto->toArray(), "Booked with {$dto->carrier}", 201);
    }

    public function bulk(BulkBookCourierRequest $request): JsonResponse
    {
        $v = $request->validated();
        $result = $this->service->bookBulk($this->companyId($request), $v['sellIds'], $v['courierAccountId'] ?? null, $v['defaults'] ?? []);

        return $this->success($result, "{$result['succeeded']} of {$result['total']} orders booked");
    }

    public function quote(Request $request): JsonResponse
    {
        $q = $request->validate([
            'courierAccountId' => 'nullable|integer|min:1',
            'sellId' => 'nullable|integer|min:1',
            'weightKg' => 'nullable|numeric|min:0.1|max:30',
            'deliveryType' => 'nullable|integer|in:12,48',
            'itemType' => 'nullable|integer|in:1,2',
            'cityId' => 'nullable|integer|min:1',
            'zoneId' => 'nullable|integer|min:1',
            'storeId' => 'nullable|integer|min:1',
        ]);

        return $this->success($this->service->quote($this->companyId($request), $q));
    }

    public function customerRisk(Request $request): JsonResponse
    {
        $q = $request->validate([
            'phone' => 'required|string|max:30',
            'courierAccountId' => 'nullable|integer|min:1',
        ]);

        return $this->success($this->service->customerRisk($this->companyId($request), $q['phone'], isset($q['courierAccountId']) ? (int) $q['courierAccountId'] : null));
    }

    public function sync(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->sync($id, $this->companyId($request))->toArray(), 'Courier status refreshed');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->cancel($id, $this->companyId($request))->toArray(), 'Cancelled at the courier');
    }

    public function tracking(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->tracking($id, $this->companyId($request)));
    }
}
