<?php

namespace App\Repositories\Eloquent;

use App\Models\OrderShipment;
use App\Repositories\Contracts\IOrderShipmentRepository;

class OrderShipmentRepository implements IOrderShipmentRepository
{
    public function __construct(private readonly OrderShipment $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['sell', 'trackingHistory']);

        if (isset($filters['sell_id'])) {
            $query->where('sell_id', $filters['sell_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            // Accepts one status or a comma-separated group, e.g. "failed,returned"
            $statuses = array_filter(array_map('trim', explode(',', (string) $filters['status'])));
            $query->whereIn('status', $statuses);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('tracking_number', 'like', $term)
                  ->orWhere('carrier', 'like', $term)
                  ->orWhereHas('sell', fn($sq) =>
                      $sq->where('invoice_no', 'like', $term)
                         ->orWhere('customer_name', 'like', $term)
                         ->orWhere('shipping_phone', 'like', $term)
                  )->orWhere('rider', 'like', $term);
            });
        }

        if (isset($filters['tracking_number'])) {
            $query->where('tracking_number', 'like', '%' . $filters['tracking_number'] . '%');
        }

        if (isset($filters['carrier'])) {
            $query->where('carrier', $filters['carrier']);
        }

        if (!empty($filters['start_date'])) {
            $query->whereDate('created_at', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('created_at', '<=', $filters['end_date']);
        }
        if (!empty($filters['days']) && (int) $filters['days'] > 0) {
            $query->where('created_at', '>=', now()->subDays((int) $filters['days'])->startOfDay());
        }

        if (!empty($filters['cod']) && in_array((string) $filters['cod'], ['1', 'true'], true)) {
            $query->where(function ($q) {
                $q->where('cod_amount', '>', 0)
                  ->orWhereHas('sell', fn($sq) => $sq->where('method', 'COD'));
            });
        }

        $limit = (int) ($filters['per_page'] ?? $filters['limit'] ?? 10);
        $limit = max(1, min($limit, 2000));
        return $query->orderByDesc('id')->paginate($limit);
    }

    /** Orders that can still be shipped: unshipped, not POS, no live shipment (see OrderShipment::scopeLive). */
    public function readyToShipQuery(int $companyId)
    {
        return \App\Models\Sell::where('sells.company_id', $companyId)
            ->whereIn('status', ['Pending', 'Processing'])
            ->where(fn($q) => $q->whereNull('fulfillment_status')
                ->orWhereIn('fulfillment_status', ['unfulfilled', 'processing', 'packed']))
            ->where(fn($q) => $q->whereNull('source')->orWhere('source', '!=', 'pos'))
            ->whereDoesntHave('shipments', fn($q) => $q->live());
    }

    public function readyToShip(int $companyId, array $filters): mixed
    {
        $query = $this->readyToShipQuery($companyId)->withCount('items');
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(fn($q) => $q->where('invoice_no', 'like', $term)
                ->orWhere('customer_name', 'like', $term)
                ->orWhere('shipping_full_name', 'like', $term)
                ->orWhere('shipping_phone', 'like', $term));
        }
        $limit = max(1, min((int) ($filters['per_page'] ?? $filters['limit'] ?? 20), 2000));
        return $query->orderByDesc('id')->paginate($limit);
    }

    public function trackingExists(int $companyId, string $trackingNumber, ?int $ignoreId = null): bool
    {
        return $this->model->where('company_id', $companyId)
            ->where('tracking_number', $trackingNumber)
            ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?OrderShipment
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['sell', 'trackingHistory'])
            ->find($id);
    }

    public function findByTrackingNumber(string $trackingNumber): ?OrderShipment
    {
        return $this->model
            ->where('tracking_number', $trackingNumber)
            ->with(['sell', 'trackingHistory'])
            ->first();
    }

    public function create(array $data): OrderShipment
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): OrderShipment
    {
        $shipment = $this->model->findOrFail($id);
        $shipment->fill($data)->save();
        return $shipment;
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->selectRaw('
                COUNT(*) as total,
                COUNT(CASE WHEN status = ? THEN 1 END) as pending,
                COUNT(CASE WHEN status = ? THEN 1 END) as picked_up,
                COUNT(CASE WHEN status = ? THEN 1 END) as in_transit,
                COUNT(CASE WHEN status = ? THEN 1 END) as out_for_delivery,
                COUNT(CASE WHEN status = ? THEN 1 END) as delivered,
                COUNT(CASE WHEN status = ? THEN 1 END) as failed,
                COUNT(CASE WHEN status = ? THEN 1 END) as returned,
                SUM(shipping_cost) as total_shipping_cost
            ', ['pending', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed', 'returned'])
            ->first();

        $open = ['pending', 'picked_up', 'in_transit', 'out_for_delivery'];

        // Orders that still need a shipment: not delivered, not a POS pickup, no shipment yet
        $readyToShip = $this->readyToShipQuery($companyId)->count();

        // COD cash that couriers are holding: unpaid COD sales whose shipment is still on the road
        $codWithCouriers = (float) $this->model
            ->where('order_shipments.company_id', $companyId)
            ->whereIn('order_shipments.status', $open)
            ->join('sells', 'sells.id', '=', 'order_shipments.sell_id')
            ->where('sells.method', 'COD')
            ->where('sells.payment_status', '!=', 'paid')
            ->sum('sells.amount');

        return [
            'readyToShip' => (int) $readyToShip,
            'codWithCouriers' => $codWithCouriers,
            'total' => $stats->total ?? 0,
            'pending' => $stats->pending ?? 0,
            'pickedUp' => $stats->picked_up ?? 0,
            'inTransit' => $stats->in_transit ?? 0,
            'outForDelivery' => $stats->out_for_delivery ?? 0,
            'delivered' => $stats->delivered ?? 0,
            'failed' => $stats->failed ?? 0,
            'returned' => $stats->returned ?? 0,
            'totalShippingCost' => $stats->total_shipping_cost ?? 0,
        ];
    }

}
