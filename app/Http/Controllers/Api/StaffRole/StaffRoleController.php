<?php

namespace App\Http\Controllers\Api\StaffRole;

use App\Http\Controllers\Controller;
use App\Http\Requests\StaffRole\CreateStaffRoleRequest;
use App\Http\Requests\StaffRole\UpdateStaffRoleRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Permission;
use App\Services\StaffRole\RoleMatrixService;
use App\Services\StaffRole\StaffRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffRoleController extends Controller
{
    use ApiResponse;

    private const DEFAULT_PERMISSIONS = [
        'Dashboard',
        'Products',
        'Categories',
        'Attributes',
        'Coupons',
        'Print Barcode',
        'Customers',
        'Orders',
        'Shipments',
        'Vendors',
        'POS',
        'Sells',
        'Inventory',
        'Transfers',
        'Customer Returns',
        'Vendor Returns',
        'Staff',
        'Role & Permission',
        'Salary Management',
        'Settings',
        'Aura Shop',
        'Company Profile',
        'Company Settings',
        'Billing Contact',
        'Team Members',
        'Subscriptions',
        'Billing Plans',
        'Store',
        'Shipping Methods',
        'Payment Methods',
        'Shipping Addresses',
        'Store Locations',
        'Store Wishlist',
        'Pages',
        'International',
        'Notifications',
        'Support',
    ];

    public function __construct(
        private readonly StaffRoleService $staffRoleService,
        private readonly RoleMatrixService $matrixService,
    ) {
    }

    public function matrixModules(): JsonResponse
    {
        return $this->success($this->matrixService->modules());
    }

    public function matrix(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->matrixService->show($id, $companyId));
    }

    private function matrixRules(bool $creating): array
    {
        return [
            'name' => ($creating ? 'required' : 'sometimes|required') . '|string|max:255',
            'description' => 'nullable|string|max:255',
            'matrix' => 'nullable|array',
            'sensitive' => 'nullable|array',
        ];
    }

    public function updateMatrix(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $request->validate($this->matrixRules(false));

        return $this->success($this->matrixService->update($id, $companyId, $request->only(['name', 'description', 'matrix', 'sensitive'])), 'Role saved');
    }

    public function storeMatrix(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $request->validate($this->matrixRules(true));

        return $this->success($this->matrixService->create($companyId, $request->only(['name', 'description', 'matrix', 'sensitive'])), 'Role created', 201);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->matrixService->duplicate($id, $companyId), 'Role duplicated', 201);
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success($this->staffRoleService->list($companyId));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffRoleService->get($id, $companyId);

        return $this->success($dto->toArray());
    }

    public function store(CreateStaffRoleRequest $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffRoleService->create($companyId, $request->validated());

        return $this->success(
            $dto->toArray(),
            'Staff role created successfully',
            201
        );
    }

    public function update(UpdateStaffRoleRequest $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $dto = $this->staffRoleService->update($id, $companyId, $request->validated());

        return $this->success($dto->toArray());
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $this->staffRoleService->delete($id, $companyId);

        return $this->success(['message' => 'Staff role deleted'], 200);
    }

    public function permissions(): JsonResponse
    {
        foreach (self::DEFAULT_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $permissions = Permission::all(['id', 'name'])->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
        ])->toArray();

        return $this->success(
            $permissions,
            'Permissions retrieved successfully',
            200
        );
    }
}
