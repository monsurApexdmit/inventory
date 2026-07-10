<?php

namespace App\Services\Shipping;

use App\DTOs\Shipping\OrderShipmentDTO;
use App\DTOs\Shipping\OrderShipmentMapper;
use App\Models\OrderShipment;
use App\Models\ShipmentTrackingHistory;
use App\Repositories\Contracts\IOrderShipmentRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderShipmentService
{
    private readonly OrderShipmentMapper $mapper;

    public function __construct(private readonly IOrderShipmentRepository $repository)
    {
        $this->mapper = new OrderShipmentMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($shipment) => $this->mapper->toDTO($shipment), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): OrderShipmentDTO
    {
        $shipment = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }
        // Ensure relationships are loaded
        return $this->mapper->toDTO($shipment->load(['sell', 'trackingHistory']));
    }

    public function create(int $companyId, array $data): OrderShipmentDTO
    {
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;

        $shipment = DB::transaction(function () use ($dbData) {
            $shipment = $this->repository->create($dbData);

            // Add initial tracking event
            ShipmentTrackingHistory::create([
                'shipment_id' => $shipment->id,
                'status' => $shipment->status,
                'description' => "Shipment created with status: {$shipment->status}",
                'event_time' => now(),
            ]);

            // Update sell fulfillment status
            if ($shipment->sell) {
                $shipment->sell->update([
                    'fulfillment_status' => $this->mapStatusToFulfillment($shipment->status),
                ]);
            }

            return $shipment;
        });

        return $this->mapper->toDTO($shipment->fresh(['sell', 'trackingHistory']));
    }

    public function updateStatus(int $id, int $companyId, array $data): OrderShipmentDTO
    {
        $shipment = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }

        $shipment = DB::transaction(function () use ($shipment, $data) {
            $shipment->update(['status' => $data['status']]);

            // Add tracking history
            ShipmentTrackingHistory::create([
                'shipment_id' => $shipment->id,
                'status' => $data['status'],
                'location' => $data['location'] ?? null,
                'description' => $data['description'] ?? "Status updated to: {$data['status']}",
                'event_time' => now(),
            ]);

            // Update sell fulfillment status
            if ($shipment->sell) {
                $shipment->sell->update([
                    'fulfillment_status' => $this->mapStatusToFulfillment($data['status']),
                ]);
            }

            return $shipment;
        });

        return $this->mapper->toDTO($shipment->fresh(['sell', 'trackingHistory']));
    }

    public function addTrackingEvent(int $id, int $companyId, array $data): OrderShipmentDTO
    {
        $shipment = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }

        ShipmentTrackingHistory::create([
            'shipment_id' => $id,
            'status' => $data['status'] ?? null,
            'location' => $data['location'] ?? null,
            'description' => $data['description'] ?? null,
            'event_time' => $data['eventTime'] ?? now(),
        ]);

        return $this->mapper->toDTO($shipment->fresh('trackingHistory'));
    }

    public function getPublicTracking(string $trackingNumber): array
    {
        $shipment = $this->repository->findByTrackingNumber($trackingNumber);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }

        return [
            'trackingNumber' => $shipment->tracking_number,
            'carrier' => $shipment->carrier,
            'status' => $shipment->status,
            'shippedAt' => $shipment->shipped_at,
            'deliveredAt' => $shipment->delivered_at,
            'estimatedDelivery' => $shipment->estimated_delivery,
            'trackingHistory' => $shipment->trackingHistory->map(fn($h) => [
                'status' => $h->status,
                'location' => $h->location,
                'description' => $h->description,
                'eventTime' => $h->event_time,
            ])->toArray(),
        ];
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function mapStatusToFulfillment(string $shipmentStatus): string
    {
        return match ($shipmentStatus) {
            'pending' => 'processing',
            'picked_up', 'in_transit', 'out_for_delivery' => 'shipped',
            'delivered' => 'delivered',
            'failed', 'returned' => 'cancelled',
            default => 'processing',
        };
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];
        $map = [
            'sellId' => 'sell_id',
            'trackingNumber' => 'tracking_number',
            'carrier' => 'carrier',
            'shippingMethod' => 'shipping_method',
            'status' => 'status',
            'shippedAt' => 'shipped_at',
            'estimatedDelivery' => 'estimated_delivery',
            'deliveredAt' => 'delivered_at',
            'shippingCost' => 'shipping_cost',
            'weight' => 'weight',
            'dimensions' => 'dimensions',
            'notes' => 'notes',
        ];
        foreach ($map as $camel => $snake) {
            if (isset($data[$camel])) {
                $dbData[$snake] = $data[$camel];
            }
        }
        $dbData['status'] = $dbData['status'] ?? 'pending';
        return $dbData;
    }
}
