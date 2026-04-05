<?php

namespace App\Repositories\Eloquent;

use App\Models\CustomerReturn;
use App\Repositories\Contracts\ICustomerReturnRepository;

class CustomerReturnRepository implements ICustomerReturnRepository
{
    public function __construct(private readonly CustomerReturn $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['customer', 'items']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('return_number', 'like', '%' . $search . '%')
                    ->orWhere('customer_name', 'like', '%' . $search . '%')
                    ->orWhere('order_number', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        $query->orderBy('request_date', 'desc');

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?CustomerReturn
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['customer', 'items'])
            ->find($id);
    }

    public function findByCustomer(int $customerId, int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->with(['customer', 'items'])
            ->orderBy('request_date', 'desc');

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function create(array $data): CustomerReturn
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): CustomerReturn
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
                COUNT(*) as total,
                COUNT(CASE WHEN status = ? THEN 1 END) as pending,
                COUNT(CASE WHEN status = ? THEN 1 END) as approved,
                COUNT(CASE WHEN status = ? THEN 1 END) as rejected,
                COUNT(CASE WHEN status = ? THEN 1 END) as completed,
                SUM(total_amount) as total_refund_amount
            ', ['pending', 'approved', 'rejected', 'completed'])
            ->first();

        return [
            'total' => $stats->total ?? 0,
            'pending' => $stats->pending ?? 0,
            'approved' => $stats->approved ?? 0,
            'rejected' => $stats->rejected ?? 0,
            'completed' => $stats->completed ?? 0,
            'totalRefundAmount' => $stats->total_refund_amount ?? 0,
        ];
    }

}
