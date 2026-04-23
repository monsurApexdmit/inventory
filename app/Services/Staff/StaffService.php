<?php

namespace App\Services\Staff;

use App\DTOs\Staff\StaffDTO;
use App\DTOs\Staff\StaffMapper;
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
            // Create User with role_id = 2 (User role, since 1 is Admin)
            $user = $this->userRepository->create([
                'username' => $data['email'],
                'email' => $data['email'],
                'password' => $data['password'] ?? 'ChangeMe123',
                'role_id' => 2,
            ]);

            // Create Staff record
            $staff = $this->staffRepository->create([
                'company_id' => $companyId,
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'contact' => $data['contact'] ?? null,
                'joining_date' => $data['joiningDate'] ?? null,
                'role' => $data['role'] ?? null,
                'status' => $data['status'] ?? 'Active',
                'published' => $data['published'] ?? false,
                'avatar' => $data['avatar'] ?? null,
                'salary' => $data['salary'] ?? 0,
                'bank_account' => $data['bankAccount'] ?? null,
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
