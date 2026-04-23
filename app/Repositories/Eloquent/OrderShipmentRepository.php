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
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('tracking_number', 'like', $term)
                  ->orWhere('carrier', 'like', $term)
                  ->orWhereHas('sell', fn($sq) =>
                      $sq->where('invoice_no', 'like', $term)
                         ->orWhere('customer_name', 'like', $term)
                  );
            });
        }

        if (isset($filters['tracking_number'])) {
            $query->where('tracking_number', 'like', '%' . $filters['tracking_number'] . '%');
        }

        if (isset($filters['carrier'])) {
            $query->where('carrier', $filters['carrier']);
        }

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
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

        return [
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
