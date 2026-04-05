<?php

namespace App\Services\Vendor;

use App\DTOs\Vendor\VendorDTO;
use App\DTOs\Vendor\VendorMapper;
use App\Models\User;
use App\Models\Vendor;
use App\Repositories\Contracts\IVendorRepository;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorService
{
    private readonly VendorMapper $mapper;

    public function __construct(private readonly IVendorRepository $repository)
    {
        $this->mapper = new VendorMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($vendor) => $this->mapper->toDTO($vendor), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): VendorDTO
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        return $this->mapper->toDTO($vendor);
    }

    public function create(int $companyId, array $data): VendorDTO
    {
        // Check for email uniqueness per company
        $existing = $this->repository->findByEmailAndCompany($data['email'], $companyId);
        if ($existing) {
            throw new HttpException(409, 'Email already registered for this company');
        }

        // Create linked user with default password
        $user = User::create([
            'username' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make('changeme'),
        ]);

        // Prepare vendor data
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        $dbData['user_id'] = $user->id;

        $vendor = $this->repository->create($dbData);

        return $this->mapper->toDTO($vendor->fresh('user', 'user.role'));
    }

    public function update(int $id, int $companyId, array $data): VendorDTO
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        // Check email uniqueness if email is being updated
        if (isset($data['email']) && $data['email'] !== $vendor->email) {
            $existing = $this->repository->findByEmailAndCompany($data['email'], $companyId);
            if ($existing) {
                throw new HttpException(409, 'Email already registered for this company');
            }
        }

        $dbData = $this->mapInputToDb($data);

        // Sync user record if name or email changed
        if ($vendor->user_id && (isset($data['name']) || isset($data['email']))) {
            $userUpdate = [];
            if (isset($data['name'])) {
                $userUpdate['username'] = $data['name'];
            }
            if (isset($data['email'])) {
                $userUpdate['email'] = $data['email'];
            }
            $vendor->user()->update($userUpdate);
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        // Soft delete linked user if exists
        if ($vendor->user_id) {
            $vendor->user()->delete();
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $dbData['email'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $dbData['phone'] = $data['phone'];
        }
        if (isset($data['address'])) {
            $dbData['address'] = $data['address'];
        }
        if (isset($data['logo'])) {
            $dbData['logo'] = $data['logo'];
        }
        if (isset($data['uploadedBy'])) {
            $dbData['uploaded_by'] = $data['uploadedBy'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['description'])) {
            $dbData['description'] = $data['description'];
        }
        if (isset($data['totalPaid'])) {
            $dbData['total_paid'] = $data['totalPaid'];
        }
        if (isset($data['amountPayable'])) {
            $dbData['amount_payable'] = $data['amountPayable'];
        }

        return $dbData;
    }
}
