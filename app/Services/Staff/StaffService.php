<?php

namespace App\Services\Staff;

use App\DTOs\Staff\StaffDTO;
use App\DTOs\Staff\StaffMapper;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\Location;
use App\Models\Invitation;
use Illuminate\Support\Facades\Hash;
use App\Repositories\Contracts\ISubscriptionRepository;
use App\Services\Salary\PayrollService;
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
        private readonly ISubscriptionRepository $subscriptionRepository,
        private readonly PayrollService $payrollService,
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

    /** camelCase input key => staff column (nullable columns accept null, others are skipped when null). */
    private const FIELD_MAP = [
        'name' => 'name',
        'email' => 'email',
        'contact' => 'contact',
        'joiningDate' => 'joining_date',
        'role' => 'role',
        'staffRoleId' => 'staff_role_id',
        'status' => 'status',
        'published' => 'published',
        'avatar' => 'avatar',
        'salary' => 'salary',
        'bankAccount' => 'bank_account',
        'paymentMethod' => 'payment_method',
        'nid' => 'nid',
        'emergencyContact' => 'emergency_contact',
        'loginMethod' => 'login_method',
    ];

    private const NOT_NULL = ['name', 'status', 'published', 'salary', 'login_method'];

    private function attributesFromInput(array $data): array
    {
        $out = [];
        foreach (self::FIELD_MAP as $in => $col) {
            if (!array_key_exists($in, $data)) {
                continue;
            }
            if ($data[$in] === null && in_array($col, self::NOT_NULL, true)) {
                continue;
            }
            $out[$col] = $data[$in];
        }
        if (array_key_exists('locationIds', $data)) {
            $out['location_ids'] = array_values(array_unique(array_map('intval', (array) $data['locationIds'])));
        }
        if (!empty($data['pin'])) {
            $out['pin_hash'] = Hash::make((string) $data['pin']);
        }

        return $out;
    }

    private function enforceSeatLimit(int $companyId): void
    {
        $limit = $this->seatLimit($companyId);
        if ($limit !== null && Staff::where('company_id', $companyId)->count() >= $limit) {
            throw new HttpException(422, "User limit reached ({$limit}). Upgrade your plan to add more staff.");
        }
    }

    /** Plan seat limit, or null when there is no plan / effectively unlimited. */
    public function seatLimit(int $companyId): ?int
    {
        $max = $this->subscriptionRepository->findByCompanyId($companyId)?->plan?->max_users;

        return ($max === null || (int) $max >= 999999) ? null : (int) $max;
    }

    private function ensureLoginUser(Staff|array $staffOrAttrs, string $email, ?string $password): int
    {
        if (User::where('email', $email)->exists() || \App\Models\SaasUser::where('email', $email)->exists()) {
            throw new HttpException(422, 'This email is already used by another account.');
        }
        $staffRole = Role::firstOrCreate(['title' => 'Staff'], ['status' => true]);
        $user = $this->userRepository->create([
            'username' => $email,
            'email' => $email,
            'password' => $password ?: 'ChangeMe123',
            'role_id' => $staffRole->id,
        ]);

        return $user->id;
    }

    private function isOwnerStaff(Staff $s): bool
    {
        $name = $s->staff_role_id ? StaffRole::where('id', $s->staff_role_id)->value('name') : $s->role;

        return strtolower(trim((string) $name)) === 'owner';
    }

    private function ownerCount(int $companyId): int
    {
        $ids = StaffRole::where('company_id', $companyId)->whereRaw('LOWER(TRIM(name)) = ?', ['owner'])->pluck('id');

        return Staff::where('company_id', $companyId)->whereIn('staff_role_id', $ids)->count();
    }

    public function create(int $companyId, array $data): StaffDTO
    {
        $this->enforceSeatLimit($companyId);

        $method = $data['loginMethod'] ?? 'email';
        $attrs = $this->attributesFromInput($data);
        $attrs['company_id'] = $companyId;
        $attrs['login_method'] = $method;
        $attrs['published'] = $attrs['published'] ?? false;
        $attrs['salary'] = $attrs['salary'] ?? 0;

        if (empty($attrs['role']) && !empty($attrs['staff_role_id'])) {
            $attrs['role'] = StaffRole::where('id', $attrs['staff_role_id'])->value('name');
        }

        // An explicit email login that is still "Active" starts as an invite until the person signs in.
        $status = $attrs['status'] ?? 'Active';
        if ($method === 'email' && array_key_exists('loginMethod', $data) && $status === 'Active') {
            $status = 'Invited';
        }
        $attrs['status'] = $status;
        if ($status === 'Invited') {
            $attrs['invited_at'] = now();
        }
        if ($method === 'email') {
            unset($attrs['pin_hash']);
        }

        $staff = DB::transaction(function () use ($data, $attrs, $method) {
            // PIN-only staff have no email and therefore no login account.
            $attrs['user_id'] = ($method === 'email' && !empty($attrs['email']))
                ? $this->ensureLoginUser($attrs, $attrs['email'], $data['password'] ?? null)
                : null;

            return $this->staffRepository->create($attrs);
        });

        $this->notificationService->notifyStaffJoined(
            $companyId,
            $staff->name,
            $staff->role ?? 'Staff'
        );

        return $this->mapper->toDTO($staff->fresh());
    }

    public function update(int $id, int $companyId, array $data): StaffDTO
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);

        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        $attrs = $this->attributesFromInput($data);
        $method = $data['loginMethod'] ?? $staff->login_method ?: 'email';

        if ($this->isOwnerStaff($staff) && $this->ownerCount($companyId) <= 1) {
            $newRoleId = array_key_exists('staff_role_id', $attrs) ? $attrs['staff_role_id'] : $staff->staff_role_id;
            $newRole = $newRoleId ? strtolower(trim((string) StaffRole::where('id', $newRoleId)->value('name'))) : '';
            if ($newRole !== 'owner') {
                throw new HttpException(422, 'The only Owner cannot be moved to another role. Make someone else an Owner first.');
            }
            if (isset($attrs['status']) && $attrs['status'] !== 'Active') {
                throw new HttpException(422, 'The only Owner cannot be deactivated.');
            }
            if (array_key_exists('published', $attrs) && !$attrs['published']) {
                throw new HttpException(422, 'The only Owner cannot be unpublished.');
            }
        }

        if (array_key_exists('staff_role_id', $attrs) && empty($data['role'])) {
            $attrs['role'] = $attrs['staff_role_id'] ? StaffRole::where('id', $attrs['staff_role_id'])->value('name') : $staff->role;
        }
        if ($method === 'email' && array_key_exists('loginMethod', $data)) {
            $attrs['pin_hash'] = null;
        }
        if (($attrs['status'] ?? null) === 'Invited' && $staff->status !== 'Invited') {
            $attrs['invited_at'] = now();
        }

        $staff = DB::transaction(function () use ($staff, $data, $attrs, $method) {
            $email = array_key_exists('email', $attrs) ? $attrs['email'] : $staff->email;

            if ($staff->user_id) {
                $userUpdate = [];
                if (!empty($attrs['email'])) {
                    $userUpdate['email'] = $attrs['email'];
                    $userUpdate['username'] = $attrs['email'];
                }
                if (!empty($data['password'])) {
                    $userUpdate['password'] = $data['password'];
                }
                if ($userUpdate) {
                    $this->userRepository->update($staff->user_id, $userUpdate);
                }
            } elseif ($method === 'email' && !empty($email)) {
                $attrs['user_id'] = $this->ensureLoginUser($staff, $email, $data['password'] ?? null);
            }

            return $this->staffRepository->update($staff->id, $attrs);
        });

        return $this->mapper->toDTO($staff->fresh());
    }

    public function delete(int $id, int $companyId): void
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);

        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        if ($this->isOwnerStaff($staff) && $this->ownerCount($companyId) <= 1) {
            throw new HttpException(422, 'The only Owner cannot be removed.');
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

    // ------------------------------------------------------------------
    // Team overview (Staff page)
    // ------------------------------------------------------------------

    private const STATUSES = ['Active', 'Invited', 'On leave', 'Inactive'];

    public function overview(int $companyId, array $f): array
    {
        $search = trim((string) ($f['search'] ?? ''));
        $statusTab = $f['status'] ?? 'all';
        $roleId = !empty($f['role_id']) ? (int) $f['role_id'] : null;
        $locationId = !empty($f['location_id']) ? (int) $f['location_id'] : null;
        $page = max(1, (int) ($f['page'] ?? 1));
        $perPage = min(500, max(1, (int) ($f['per_page'] ?? 100)));

        $roles = StaffRole::where('company_id', $companyId)->withCount('staff')->orderBy('id')->get();
        $roleNames = $roles->pluck('name', 'id');
        $locations = Location::where('company_id', $companyId)->orderBy('id')->get(['id', 'name']);
        $locNames = $locations->pluck('name', 'id');

        $q = Staff::where('company_id', $companyId);
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('contact', 'like', $like));
        }
        if ($roleId) {
            $q->where('staff_role_id', $roleId);
        }

        $base = $q->orderBy('name')->get()->filter(function (Staff $s) use ($locationId) {
            if (!$locationId) {
                return true;
            }
            $ids = array_map('intval', (array) $s->location_ids);
            return !$ids || in_array($locationId, $ids, true);
        })->values();

        $counts = ['all' => $base->count()];
        foreach (self::STATUSES as $st) {
            $counts[$st] = $base->where('status', $st)->count();
        }

        $shown = (in_array($statusTab, self::STATUSES, true) ? $base->where('status', $statusTab) : $base)->values();
        $total = $shown->count();
        $pageItems = $shown->slice(($page - 1) * $perPage, $perPage);

        $isOwner = fn (Staff $s) => strtolower(trim((string) ($roleNames[$s->staff_role_id] ?? ''))) === 'owner';
        $today = now()->startOfDay();
        $activeToday = $base->filter(fn (Staff $s) => $s->last_active_at && $s->last_active_at->gte($today))->values();
        $invited = $base->where('status', 'Invited')->sortBy('invited_at')->values();
        $owners = $base->filter($isOwner)->count();

        $rows = $pageItems->map(function (Staff $s) use ($roleNames, $locNames, $isOwner, $owners) {
            $ids = array_values(array_map('intval', (array) $s->location_ids));
            return [
                'id' => (int) $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->contact,
                'roleId' => $s->staff_role_id ? (int) $s->staff_role_id : null,
                'roleName' => $s->staff_role_id ? ($roleNames[$s->staff_role_id] ?? $s->role) : $s->role,
                'locationIds' => $ids,
                'locationNames' => array_values(array_filter(array_map(fn ($i) => $locNames[$i] ?? null, $ids))),
                'salary' => (float) $s->salary,
                'paymentMethod' => $s->payment_method,
                'account' => $s->bank_account,
                'nid' => $s->nid,
                'emergencyContact' => $s->emergency_contact,
                'joiningDate' => $s->joining_date,
                'status' => $s->status,
                'published' => (bool) $s->published,
                'loginMethod' => $s->login_method ?: 'email',
                'hasPin' => !empty($s->pin_hash),
                'lastActiveAt' => $s->last_active_at?->toIso8601String(),
                'invited' => $s->status === 'Invited',
                'isOwner' => $isOwner($s),
                'isOnlyOwner' => $isOwner($s) && $owners <= 1,
            ];
        })->values()->all();

        return [
            'data' => [
                'rows' => $rows,
                'stats' => [
                    'total' => $base->count(),
                    'owners' => $owners,
                    'staffCount' => $base->count() - $owners,
                    'activeToday' => $activeToday->count(),
                    'activeTodayNames' => $activeToday->take(2)->map(fn ($s) => explode(' ', trim($s->name))[0])->values()->all(),
                    'inactive' => $counts['Inactive'],
                    'invited' => $counts['Invited'],
                    'firstInvitedName' => $invited->first()?->name,
                    'monthlyPayroll' => round((float) $base->where('status', 'Active')->sum('salary'), 2),
                    'seatsUsed' => Staff::where('company_id', $companyId)->count(),
                    'seatLimit' => $this->seatLimit($companyId),
                ],
                'counts' => $counts,
                'roles' => $roles->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'description' => $r->description, 'isOwner' => strtolower(trim((string) $r->name)) === 'owner', 'memberCount' => (int) $r->staff_count])->values()->all(),
                'locations' => $locations->map(fn ($l) => ['id' => (int) $l->id, 'name' => $l->name])->values()->all(),
            ],
            'meta' => [
                'total' => $total,
                'page' => $page,
                'perPage' => $perPage,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function resendInvite(int $id, int $companyId): array
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);
        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }
        if ($staff->status !== 'Invited') {
            throw new HttpException(422, 'Only invited team members can be sent an invite again.');
        }

        DB::transaction(function () use ($staff, $companyId) {
            $staff->forceFill(['invited_at' => now()])->save();

            // Refresh a pending team invitation for the same address, when there is one.
            if ($staff->email) {
                Invitation::where('company_id', $companyId)->where('email', $staff->email)->where('status', 'pending')
                    ->update(['expires_at' => now()->addDays(7), 'invited_at' => now()]);
            }
        });

        $this->notificationService->notifyStaffInviteResent($companyId, $staff->name, $staff->email);

        return ['id' => (int) $staff->id, 'status' => $staff->status, 'invitedAt' => $staff->fresh()->invited_at?->toIso8601String()];
    }

    public function salarySummary(int $id, int $companyId): array
    {
        $staff = $this->staffRepository->findByIdAndCompany($id, $companyId);
        if (!$staff) {
            throw new HttpException(404, 'Staff member not found');
        }

        return $this->payrollService->staffSummary($staff);
    }

    public function getStats(int $companyId): array
    {
        return $this->staffRepository->getStats($companyId);
    }
}
