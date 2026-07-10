<?php

namespace App\Services\Staff;

use App\DTOs\Staff\StaffDTO;
use App\DTOs\Staff\StaffMapper;
use App\Models\Role;
use App\Repositories\Contracts\IStaffRepository;
use App\Repositories\Contracts\IUserRepository;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffService
{
    private readonly StaffMapper $mapper;

    public function __construct(
        private readonly IStaffRepository $staffRepository,
        private readonly IUserRepository $userRepository,
        private readonly NotificationService $notificationService,
    ) {
        $this->mapper = new StaffMapper();
    }

    public function list(int $companyId, array $filters = []): array
    {
        $paginated = $this->staffRepository->findAllByCompany($companyId);
        $data = array_map(fn ($staff) => $this->mapper->toDTO($staff), $paginated->items());

        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): StaffDTO
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);

        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        return $this->mapper->toDTO($staff);
    }

    public function create(int $companyId, array $data): StaffDTO
    {
        $staff = DB::transaction(function () use ($companyId, $data) {
            $staffRole = Role::firstOrCreate(['title' => 'Staff'], ['status' => true]);
            $user = $this->userRepository->create([
                'username' => $data['email'],
                'email' => $data['email'],
                'password' => $data['password'] ?? 'ChangeMe123',
                'role_id' => $staffRole->id,
            ]);

            // Create Staff record
            $staff = $this->staffRepository->create([
                'company_id'    => $companyId,
                'user_id'       => $user->id,
                'name'          => $data['name'],
                'email'         => $data['email'],
                'contact'       => $data['contact'] ?? null,
                'joining_date'  => $data['joiningDate'] ?? null,
                'role'          => $data['role'] ?? null,
                'staff_role_id' => $data['staffRoleId'] ?? null,
                'status'        => $data['status'] ?? 'Active',
                'published'     => $data['published'] ?? false,
                'avatar'        => $data['avatar'] ?? null,
                'salary'        => $data['salary'] ?? 0,
                'bank_account'  => $data['bankAccount'] ?? null,
                'payment_method' => $data['paymentMethod'] ?? null,
            ]);

            return $staff;
        });

        $this->notificationService->notifyStaffJoined(
            $companyId,
            $staff->name,
            $staff->role ?? 'Staff'
        );

        return $this->mapper->toDTO($staff);
    }

    public function update(int $id, int $companyId, array $data): StaffDTO
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);

        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        $staff = DB::transaction(function () use ($staff, $data) {
            // Update User if email or password changed
            if (isset($data['email']) || isset($data['password'])) {
                $updateData = [];
                if (isset($data['email'])) {
                    $updateData['email'] = $data['email'];
                }
                if (isset($data['password'])) {
                    $updateData['password'] = $data['password'];
                }
                if ($staff->user_id) {
                    $this->userRepository->update($staff->user_id, $updateData);
                }
            }

            // Remap camelCase keys for staff table
            if (isset($data['staffRoleId'])) {
                $data['staff_role_id'] = $data['staffRoleId'];
                unset($data['staffRoleId']);
            }
            if (isset($data['joiningDate'])) {
                $data['joining_date'] = $data['joiningDate'];
                unset($data['joiningDate']);
            }
            if (isset($data['bankAccount'])) {
                $data['bank_account'] = $data['bankAccount'];
                unset($data['bankAccount']);
            }
            if (isset($data['paymentMethod'])) {
                $data['payment_method'] = $data['paymentMethod'];
                unset($data['paymentMethod']);
            }

            // Update Staff record
            $staff = $this->staffRepository->update($staff->id, $data);

            return $staff;
        });

        return $this->mapper->toDTO($staff);
    }

    public function delete(int $id, int $companyId): void
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);

        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        DB::transaction(function () use ($staff) {
            // Soft delete staff record
            $this->staffRepository->delete($staff->id);

            // Optionally delete linked user
            if ($staff->user_id) {
                $this->userRepository->delete($staff->user_id);
            }
        });
    }

    public function getStats(int $companyId): array
    {
        return $this->staffRepository->getStats($companyId);
    }
}
