<?php

namespace App\Repositories\Eloquent;

use App\Models\PurchaseOrder;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class PurchaseOrderRepository implements IPurchaseOrderRepository
{
    public function __construct(private readonly PurchaseOrder $model) {}

    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model->with(['vendor', 'location', 'items.product', 'items.variant'])
            ->where('company_id', $companyId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('po_number', 'like', "%{$search}%")
                  ->orWhereHas('vendor', fn($v) => $v->where('name', 'like', "%{$search}%"));
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function findById(int $id, int $companyId): ?PurchaseOrder
    {
        return $this->model->with(['vendor', 'location', 'items.product', 'items.variant'])
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->first();
    }

    public function create(array $data): PurchaseOrder
    {
        return $this->model->create($data);
    }

    public function update(PurchaseOrder $po, array $data): PurchaseOrder
    {
        $po->update($data);
        return $po->fresh(['vendor', 'location', 'items.product', 'items.variant']);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->model->where('id', $id)->where('company_id', $companyId)->delete();
    }

    public function nextPoNumber(int $companyId): string
    {
        $year = date('Y');
        $prefix = "PO-{$year}-";
        $last = $this->model
            ->where('company_id', $companyId)
            ->where('po_number', 'like', "{$prefix}%")
            ->orderByDesc('po_number')
            ->value('po_number');

        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad($next, 4, '0', STR_PAD_LEFT);
    }

    public function getStats(int $companyId): array
    {
        $base = $this->model->where('company_id', $companyId);

        return [
            'total'    => (clone $base)->count(),
            'draft'    => (clone $base)->where('status', 'draft')->count(),
            'sent'     => (clone $base)->where('status', 'sent')->count(),
            'partial'  => (clone $base)->where('status', 'partial')->count(),
            'received' => (clone $base)->where('status', 'received')->count(),
            'cancelled'=> (clone $base)->where('status', 'cancelled')->count(),
        ];
    }
}
