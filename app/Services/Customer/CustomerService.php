<?php

namespace App\Services\Customer;

use App\DTOs\Customer\CustomerDTO;
use App\DTOs\Customer\CustomerMapper;
use App\Models\User;
use App\Models\Customer;
use App\Repositories\Contracts\ICustomerRepository;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerService
{
    private readonly CustomerMapper $mapper;

    public function __construct(private readonly ICustomerRepository $repository)
    {
        $this->mapper = new CustomerMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($customer) => $this->mapper->toDTO($customer), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): CustomerDTO
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        return $this->mapper->toDTO($customer);
    }

    public function create(int $companyId, array $data): CustomerDTO
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

        // Prepare customer data
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;
        $dbData['user_id'] = $user->id;

        $customer = $this->repository->create($dbData);

        return $this->mapper->toDTO($customer->fresh('user', 'user.role'));
    }

    public function update(int $id, int $companyId, array $data): CustomerDTO
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        // Check email uniqueness if email is being updated
        if (isset($data['email']) && $data['email'] !== $customer->email) {
            $existing = $this->repository->findByEmailAndCompany($data['email'], $companyId);
            if ($existing) {
                throw new HttpException(409, 'Email already registered for this company');
            }
        }

        $dbData = $this->mapInputToDb($data);

        // Sync user record if name or email changed
        if ($customer->user_id && (isset($data['name']) || isset($data['email']))) {
            $userUpdate = [];
            if (isset($data['name'])) {
                $userUpdate['username'] = $data['name'];
            }
            if (isset($data['email'])) {
                $userUpdate['email'] = $data['email'];
            }
            $customer->user()->update($userUpdate);
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $customer = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$customer) {
            throw new HttpException(404, 'Customer not found');
        }

        // Soft delete linked user if exists
        if ($customer->user_id) {
            $customer->user()->delete();
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
        if (isset($data['city'])) {
            $dbData['city'] = $data['city'];
        }
        if (isset($data['state'])) {
            $dbData['state'] = $data['state'];
        }
        if (isset($data['zipCode'])) {
            $dbData['zip_code'] = $data['zipCode'];
        }
        if (isset($data['country'])) {
            $dbData['country'] = $data['country'];
        }
        if (isset($data['customerType'])) {
            $dbData['customer_type'] = $data['customerType'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['storeCredit'])) {
            $dbData['store_credit'] = $data['storeCredit'];
        }

        return $dbData;
    }
}
