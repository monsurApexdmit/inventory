<?php

namespace App\Repositories\Eloquent;

use App\Models\Vendor;
use App\Repositories\Contracts\IVendorRepository;

class VendorRepository implements IVendorRepository
{
    public function __construct(private readonly Vendor $model)
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

        $limit = min($filters['limit'] ?? 10, 100);
        return $query->paginate($limit);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?Vendor
    {
        return $this->model
            ->where('company_id', $companyId)
            ->with(['user', 'user.role'])
            ->find($id);
    }

    public function findByEmailAndCompany(string $email, int $companyId): ?Vendor
    {
        return $this->model
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->first();
    }

    public function create(array $data): Vendor
    {
        return $this->model->create($data);
    }

    public function update(int $id, array $data): Vendor
    {
        $vendor = $this->model->findOrFail($id);
        $vendor->fill($data)->save();

        return $vendor;
    }

    public function delete(int $id): bool
    {
        $vendor = $this->model->findOrFail($id);

        return (bool) $vendor->delete();
    }

    public function getStats(int $companyId): array
    {
        $stats = $this->model
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = "inactive" THEN 1 ELSE 0 END) as inactive
            ')
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'active' => (int) ($stats->active ?? 0),
            'inactive' => (int) ($stats->inactive ?? 0),
        ];
    }
}
