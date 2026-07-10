<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shipping\CreateOrderShipmentRequest;
use App\Http\Requests\Shipping\UpdateShipmentStatusRequest;
use App\Http\Requests\Shipping\AddTrackingEventRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Shipping\OrderShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderShipmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OrderShipmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $shipments = $this->service->list($companyId, $request->query());
        return $this->success($shipments);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->get($id, $companyId);
        return $this->success($dto->toArray());
    }

    public function store(CreateOrderShipmentRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->create($companyId, $request->validated());
        return $this->success($dto->toArray(), 'Shipment created successfully', 201);
    }

    public function updateStatus(UpdateShipmentStatusRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->updateStatus($id, $companyId, $request->validated());
        return $this->success($dto->toArray(), 'Shipment status updated successfully');
    }

    public function addTracking(AddTrackingEventRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->service->addTrackingEvent($id, $companyId, $request->validated());
        return $this->success($dto->toArray(), 'Tracking event added successfully');
    }

    public function stats(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $stats = $this->service->getStats($companyId);
        return $this->success($stats);
    }

    public function publicTracking(string $trackingNumber): JsonResponse
    {
        $tracking = $this->service->getPublicTracking($trackingNumber);
        return $this->success($tracking);
    }
}
