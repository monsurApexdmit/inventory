<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\ICustomerRepository;

class CustomerRepository implements ICustomerRepository
{
    public function __construct(private readonly Customer $model)
    {
    }

    public function findByCompany(int $companyId, array $filters): mixed
    {
        $query = $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role']);

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }

        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('customer_type', $filters['type']);
        }

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Customer
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role'])
            ->find($id);
    }

    public function findByEmailAndCompany(string $email, int $companyId): ?Customer
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->first();
    }

    public function create(array $data): Customer
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Customer
    {
        $customer = $this->model->findOrFail($id);
        $customer->fill($data)->save();

        return $customer;
    }

    public function delete(int $id): bool
    {
        $customer = $this->model->findOrFail($id);

        return (bool) $customer->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN customer_type = "individual" THEN 1 ELSE 0 END) as individuals,
                SUM(CASE WHEN customer_type = "business" THEN 1 ELSE 0 END) as businesses
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
            'individuals' => (int) ($stats->individuals ?? 0),
            'businesses' => (int) ($stats->businesses ?? 0),
        ];
    }

}
