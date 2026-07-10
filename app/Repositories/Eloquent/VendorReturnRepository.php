<?php

namespace App\Repositories\Eloquent;

use App\Models\VendorReturn;
use App\Repositories\Contracts\IVendorReturnRepository;

class VendorReturnRepository implements IVendorReturnRepository
{
    public function __construct(private readonly VendorReturn $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['vendor', 'items']);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        $query->orderBy('return_date', 'desc');

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?VendorReturn
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['vendor', 'items'])
            ->find($id);
    }

    public function findByVendor(int $vendorId, int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('vendor_id', $vendorId)
            ->with(['vendor', 'items'])
            ->orderBy('return_date', 'desc');

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function create(array $data): VendorReturn
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): VendorReturn
    {
        $return = $this->model->findOrFail($id);
        $return->fill($data)->save();

        return $return;
    }

    public function delete(int $id): bool
    {
        $return = $this->model->findOrFail($id);

        return (bool) $return->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->selectRaw('
                COUNT(*) as total_returns,
                COUNT(CASE WHEN status = ? THEN 1 END) as pending,
                COUNT(CASE WHEN status = ? THEN 1 END) as shipped,
                COUNT(CASE WHEN status = ? THEN 1 END) as received_by_vendor,
                COUNT(CASE WHEN status = ? THEN 1 END) as completed,
                SUM(total_amount) as total_credit_amount
            ', ['pending', 'shipped', 'received_by_vendor', 'completed'])
            ->first();

        return [
            'totalReturns' => $stats->total_returns ?? 0,
            'pending' => $stats->pending ?? 0,
            'shipped' => $stats->shipped ?? 0,
            'receivedByVendor' => $stats->received_by_vendor ?? 0,
            'completed' => $stats->completed ?? 0,
            'totalCreditAmount' => $stats->total_credit_amount ?? 0,
        ];
    }

}
