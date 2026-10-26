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
            'readyToShip' => (int) $this->repository->readyToShipQuery($companyId)->count(),
        ];
    }

    /** Orders that can be shipped now (no row cap, paginated). */
    public function readyToShip(int $companyId, array $filters): array
    {
        $paginated = $this->repository->readyToShip($companyId, $filters);
        $data = array_map(function ($sell) {
            $isCod = strcasecmp((string) $sell->method, 'COD') === 0;
            $due = (float) $sell->due_amount;
            $label = ($sell->payment_status === 'paid' || $due <= 0 && (float) $sell->paid_amount > 0) ? 'Paid' : ($isCod ? 'COD' : 'Unpaid');
            return [
                'id' => $sell->id,
                'sellId' => $sell->id,
                'invoiceNo' => $sell->invoice_no,
                'customerName' => $sell->shipping_full_name ?: $sell->customer_name,
                'customerPhone' => $sell->shipping_phone,
                'address' => implode(', ', array_filter([
                    $sell->shipping_address_line1, $sell->shipping_address_line2,
                    $sell->shipping_city, $sell->shipping_state, $sell->shipping_postal_code,
                ])),
                'amount' => (float) $sell->amount,
                'paidAmount' => (float) $sell->paid_amount,
                'dueAmount' => $due,
                'shippingCost' => (float) $sell->shipping_cost,
                'paymentMethod' => $sell->method,
                'paymentLabel' => $label,
                'isCod' => $isCod,
                'itemCount' => (int) $sell->items_count,
                'shippingMethod' => $sell->shipping_method,
                'status' => $sell->status,
                'fulfillmentStatus' => $sell->fulfillment_status,
                'orderTime' => $sell->order_time ? (string) $sell->order_time : null,
            ];
        }, $paginated->items());

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
        $sell = \App\Models\Sell::where('company_id', $companyId)->find($data['sellId'] ?? 0);
        if (!$sell) {
            throw new HttpException(404, 'Order not found');
        }
        $live = OrderShipment::where('company_id', $companyId)->where('sell_id', $sell->id)->live()->exists();
        if ($live) {
            throw new HttpException(422, 'This order already has an active shipment');
        }

        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        if (!empty($dbData['tracking_number'])) {
            if ($this->repository->trackingExists($companyId, $dbData['tracking_number'])) {
                throw new HttpException(422, 'Tracking number already exists');
            }
        } else {
            do {
                $dbData['tracking_number'] = $this->generateTracking($data['carrier'] ?? null);
            } while ($this->repository->trackingExists($companyId, $dbData['tracking_number']));
        }
        $markShipped = array_key_exists('markShipped', $data) ? filter_var($data['markShipped'], FILTER_VALIDATE_BOOLEAN) : null;

        $shipment = DB::transaction(function () use ($dbData, $markShipped) {
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
                if ($markShipped === false && $shipment->status === 'pending') {
                    // Caller does not want the order flagged as shipped yet: leave it untouched
                } elseif ($markShipped === true && $shipment->status === 'pending') {
                    $shipment->sell->update(['fulfillment_status' => 'shipped']);
                } else {
                    $this->syncSellWithShipment($shipment, $shipment->status);
                }
            }

            return $shipment;
        });

        return $this->mapper->toDTO($shipment->fresh(['sell', 'trackingHistory']));
    }

    private const FLOW = ['pending', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'];

    private function assertTransition(string $from, string $to, ?string $note): void
    {
        if ($from === $to) {
            throw new HttpException(422, "Shipment is already {$from}");
        }
        if (in_array($from, ['delivered', 'returned'], true)) {
            throw new HttpException(422, "A {$from} shipment cannot be changed");
        }
        if ($from === 'failed') {
            if ($to !== 'returned') {
                throw new HttpException(422, 'A failed shipment can only be marked as returned');
            }
            return;
        }
        if ($to === 'failed') {
            if (!in_array($from, ['picked_up', 'in_transit', 'out_for_delivery'], true)) {
                throw new HttpException(422, 'Only a shipment that has been picked up can fail');
            }
            if (trim((string) $note) === '') {
                throw new HttpException(422, 'Add a note explaining why it failed');
            }
            return;
        }
        $fromIdx = array_search($from, self::FLOW, true);
        $toIdx = array_search($to, self::FLOW, true);
        if ($toIdx === false || $fromIdx === false || $toIdx <= $fromIdx) {
            throw new HttpException(422, "Cannot move a shipment from {$from} to {$to}");
        }
    }

    private function generateTracking(?string $carrier): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) $carrier), 0, 3)) ?: 'SHP';
        return $prefix . '-' . now()->format('ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(6));
    }

    /** Record the COD cash against the order (once) and flag the shipment as collected. */
    private function collectCod(OrderShipment $shipment, \App\Models\Sell $sell, string $method = 'COD', ?string $notes = null): void
    {
        if ($shipment->cod_collected) {
            return;
        }
        $sell->recalculate();
        $due = (float) $sell->due_amount;
        $cod = (float) $shipment->cod_amount;
        $amount = 0.0;
        if ($due > 0) {
            $amount = $cod > 0 ? min($cod, $due) : $due;
            $sell->payments()->create([
                'company_id'     => $sell->company_id,
                'direction'      => 'in',
                'amount'         => $amount,
                'payment_method' => $method,
                'payment_date'   => now()->toDateString(),
                'reference'      => $shipment->tracking_number,
                'notes'          => $notes ?: 'COD collected on delivery',
            ]);
            $sell->recalculate();
        }
        $shipment->update([
            'cod_collected' => true,
            'cod_collected_amount' => $amount,
            'cod_collected_at' => now(),
        ]);
    }

    public function recordCodPayment(int $id, int $companyId, array $data): OrderShipmentDTO
    {
        $shipment = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }
        if ($shipment->status !== 'delivered') {
            throw new HttpException(422, 'COD payment can only be recorded once the shipment is delivered');
        }
        if (OrderShipmentMapper::codState($shipment) === 'prepaid') {
            throw new HttpException(422, 'This shipment is not cash on delivery');
        }
        if ($shipment->cod_collected) {
            throw new HttpException(422, 'COD payment is already recorded');
        }

        DB::transaction(function () use ($shipment, $data) {
            $sell = $shipment->sell()->lockForUpdate()->first();
            if (!$sell) {
                throw new HttpException(404, 'Order not found');
            }
            $this->collectCod($shipment, $sell, $data['paymentMethod'] ?? 'COD', $data['notes'] ?? 'COD payment recorded');
            ShipmentTrackingHistory::create([
                'shipment_id' => $shipment->id,
                'status' => 'delivered',
                'description' => 'COD payment recorded',
                'event_time' => now(),
            ]);
        });

        return $this->mapper->toDTO($shipment->fresh(['sell', 'trackingHistory']));
    }

    public function updateStatus(int $id, int $companyId, array $data): OrderShipmentDTO
    {
        $shipment = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }

        $note = $data['description'] ?? $data['note'] ?? null;
        $this->assertTransition($shipment->status, $data['status'], $note);

        $shipment = DB::transaction(function () use ($shipment, $data, $note) {
            $shipment->update(['status' => $data['status']] + (!empty($data['rider']) ? ['rider' => $data['rider']] : []));

            // Add tracking history
            ShipmentTrackingHistory::create([
                'shipment_id' => $shipment->id,
                'status' => $data['status'],
                'location' => $data['location'] ?? null,
                'description' => $note ?: "Status updated to: {$data['status']}",
                'event_time' => now(),
            ]);

            // Update sell fulfillment status
            if ($shipment->sell) {
                $this->syncSellWithShipment($shipment, $data['status']);
            }

            return $shipment;
        });

        return $this->mapper->toDTO($shipment->fresh(['sell', 'trackingHistory']));
    }

    /**
     * Push a shipment status change onto its order: fulfillment stage, delivered status and
     * COD collection. Must run inside a transaction.
     */
    private function syncSellWithShipment(OrderShipment $shipment, string $status): void
    {
        $sell = $shipment->sell()->lockForUpdate()->first();
        if (!$sell || $sell->status === 'Cancelled') {
            return;
        }

        $update = ['fulfillment_status' => $this->mapStatusToFulfillment($status)];
        if ($status === 'delivered') {
            $update['status'] = 'Delivered';
            if (!$shipment->delivered_at) {
                $shipment->update(['delivered_at' => now()]);
            }
        }
        $sell->update($update);

        if ($status !== 'delivered') {
            return;
        }

        $isCod = strcasecmp((string) $sell->method, 'COD') === 0 || (float) $shipment->cod_amount > 0;
        if ($isCod) {
            $this->collectCod($shipment, $sell);
        }
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
            'failed', 'returned' => 'packed',
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
            'codAmount' => 'cod_amount',
            'deliveryPayer' => 'delivery_payer',
            'deliverySpeed' => 'delivery_speed',
            'rider' => 'rider',
            'package' => 'package',
            'pickupDate' => 'pickup_date',
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
