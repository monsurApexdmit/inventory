<?php

namespace App\Services\StaffRole;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SaasUser;
use App\Models\StaffRole;
use App\Support\Sensitive;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** View / Create / Edit / Delete matrix over the UI's module list, mapped onto backend permissions. */
class RoleMatrixService
{
    public const ACTIONS = ['view', 'create', 'edit', 'delete'];

    /** group => [module => [summary, backend permission names]] */
    public const GROUPS = [
        'Sell' => [
            'POS' => ['Point of sale: ring up sales, hold carts, open and close register shifts', ['POS']],
            'Sales' => ['Sales list, invoices, payments and sale status changes', ['Sells']],
            'Orders' => ['Customer orders and their fulfilment', ['Orders']],
            'Shipments' => ['Shipments, tracking and delivery status', ['Shipments']],
            'Customers' => ['Customer records, history and balances', ['Customers']],
        ],
        'Catalog' => [
            'Products' => ['Products, variants, bundles and pricing', ['Products']],
            'Categories & brands' => ['Categories, brands, units and attributes', ['Categories', 'Brands', 'Units', 'Attributes']],
            'Coupons' => ['Discount coupons and campaigns', ['Coupons']],
            'Print barcode' => ['Barcode and label printing', ['Print Barcode']],
        ],
        'Inventory' => [
            'Stock' => ['Stock levels, adjustments, batches/serials and transfers between locations', ['Inventory', 'Transfers']],
            'Suppliers & POs' => ['Suppliers, purchase orders and supplier payments', ['Vendors']],
            'Returns' => ['Customer returns and returns to suppliers', ['Customer Returns', 'Vendor Returns']],
            'Tailor shop' => ['Tailor orders, measurements, fabrics, dorji and payments', ['TailorDorji', 'TailorFabric', 'TailorMeasurements', 'TailorOrders', 'TailorPayments', 'TailorReports', 'TailorShop']],
        ],
        'Finance' => [
            'Expenses' => ['Expense entries and categories', ['Expenses']],
            'Dues' => ['Customer and supplier dues, collections and reminders', ['Dues']],
            'Reports' => ['Sales, profit, stock and finance reports', ['Reports']],
        ],
        'Workspace' => [
            'Staff' => ['Team members, invites and activity', ['Staff']],
            'Roles & permissions' => ['Roles and what each role may do', ['Role & Permission']],
            'Salary' => ['Payroll, payslips and salary payments', ['Salary Management']],
            'Settings' => ['Company profile, tax/currency, billing and plan settings', ['Settings', 'Company Profile', 'Company Settings', 'Billing Contact', 'International', 'Subscriptions', 'Billing Plans', 'Team Members']],
            'Store' => ['Online store, checkout, shipping and payment methods, reviews', ['Store', 'Aura Shop', 'Checkout', 'Shipping Methods', 'Payment Methods', 'Shipping Addresses', 'Store Locations', 'Store Wishlist', 'Product Reviews']],
            'Pages' => ['Content pages', ['Pages']],
            'Support' => ['Support tickets and messages', ['Support']],
            'Notifications' => ['Notification centre', ['Notifications']],
        ],
    ];

    // ------------------------------------------------------------------

    public function modules(): array
    {
        $out = [];
        foreach (self::GROUPS as $group => $mods) {
            $out[] = [
                'group' => $group,
                'modules' => collect($mods)->map(fn ($m, $name) => [
                    'name' => $name,
                    'summary' => $m[0],
                    'permissions' => $m[1],
                ])->values()->all(),
            ];
        }

        return $out;
    }

    private function allModuleNames(): array
    {
        return collect(self::GROUPS)->flatMap(fn ($mods) => array_keys($mods))->all();
    }

    private function moduleDef(string $name): ?array
    {
        foreach (self::GROUPS as $mods) {
            if (isset($mods[$name])) {
                return $mods[$name];
            }
        }
        return null;
    }

    /** name => id, creating any missing permission rows. */
    private function permissionIds(): array
    {
        $ids = Permission::pluck('id', 'name')->all();
        foreach (self::GROUPS as $mods) {
            foreach ($mods as $m) {
                foreach ($m[1] as $perm) {
                    if (!isset($ids[$perm])) {
                        $ids[$perm] = Permission::create(['name' => $perm])->id;
                    }
                }
            }
        }
        return $ids;
    }

    private function find(int $id, int $companyId): StaffRole
    {
        $role = StaffRole::where('company_id', $companyId)->find($id);
        if (!$role) {
            throw new HttpException(404, 'Staff role not found');
        }
        return $role;
    }

    private function assertUniqueName(string $name, int $companyId, ?int $exceptId = null): void
    {
        if (strtolower(trim($name)) === 'owner') {
            $existsOwner = StaffRole::where('company_id', $companyId)->whereRaw('LOWER(name) = ?', ['owner'])
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
            if (!$existsOwner && $exceptId === null) {
                throw new HttpException(422, '"Owner" is a reserved role name.');
            }
        }
        $dupe = StaffRole::where('company_id', $companyId)->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($dupe) {
            throw new HttpException(422, 'A role with this name already exists.');
        }
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    public function show(int $id, int $companyId): array
    {
        return $this->payload($this->find($id, $companyId));
    }

    public function payload(StaffRole $role): array
    {
        $locked = $role->isLocked();
        $rows = RolePermission::where('role_id', $role->id)->get()->keyBy('permission_id');
        $ids = $this->permissionIds();

        $matrix = [];
        foreach ($this->allModuleNames() as $name) {
            if ($locked) {
                $matrix[$name] = array_fill_keys(self::ACTIONS, true);
                continue;
            }
            $cell = array_fill_keys(self::ACTIONS, true);
            foreach ($this->moduleDef($name)[1] as $perm) {
                $rp = $rows->get($ids[$perm]);
                $legacyWrite = $rp && $rp->write && !$rp->create && !$rp->edit;
                $cell['view'] = $cell['view'] && $rp && $rp->read;
                $cell['create'] = $cell['create'] && $rp && ($rp->create || $legacyWrite);
                $cell['edit'] = $cell['edit'] && $rp && ($rp->edit || $legacyWrite);
                $cell['delete'] = $cell['delete'] && $rp && $rp->delete;
            }
            $matrix[$name] = array_map('boolval', $cell);
        }

        return [
            'id' => (int) $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'locked' => $locked,
            'memberCount' => $role->staff()->count(),
            'matrix' => $matrix,
            'sensitive' => Sensitive::forRole($role),
        ];
    }

    // ------------------------------------------------------------------
    // Write
    // ------------------------------------------------------------------

    private function normalizeCell(array $c): array
    {
        $cell = [
            'view' => (bool) ($c['view'] ?? $c['read'] ?? false),
            'create' => (bool) ($c['create'] ?? false),
            'edit' => (bool) ($c['edit'] ?? false),
            'delete' => (bool) ($c['delete'] ?? false),
        ];
        if ($cell['create'] || $cell['edit'] || $cell['delete']) {
            $cell['view'] = true;
        }
        return $cell;
    }

    private function writeMatrix(StaffRole $role, array $matrix): void
    {
        $ids = $this->permissionIds();
        $anyView = false;

        foreach ($matrix as $module => $cellIn) {
            $def = $this->moduleDef((string) $module);
            if (!$def || !is_array($cellIn)) {
                continue; // unknown modules are ignored
            }
            $cell = $this->normalizeCell($cellIn);
            $anyView = $anyView || $cell['view'];
            foreach ($def[1] as $perm) {
                RolePermission::updateOrCreate(
                    ['role_id' => $role->id, 'permission_id' => $ids[$perm]],
                    [
                        'read' => $cell['view'],
                        'create' => $cell['create'],
                        'edit' => $cell['edit'],
                        'write' => $cell['create'] || $cell['edit'],
                        'delete' => $cell['delete'],
                    ]
                );
            }
        }

        // The matrix has no Dashboard row: anyone who can open something may open the dashboard.
        if ($anyView && isset($ids['Dashboard'])) {
            $dash = RolePermission::firstOrNew(['role_id' => $role->id, 'permission_id' => $ids['Dashboard']]);
            if (!$dash->exists) {
                $dash->fill(['read' => true, 'create' => false, 'edit' => false, 'write' => false, 'delete' => false])->save();
            } elseif (!$dash->read) {
                $dash->read = true;
                $dash->save();
            }
        }
    }

    private function sensitiveFrom(array $raw): array
    {
        if (isset($raw['disc']) && !isset($raw['discount'])) {
            $raw['discount'] = $raw['disc'];
        }
        return Sensitive::normalize($raw);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $role = $this->find($id, $companyId);
        if ($role->isLocked()) {
            throw new HttpException(422, 'The Owner role always has full access and can’t be changed.');
        }

        DB::transaction(function () use ($role, $companyId, $data) {
            if (isset($data['name'])) {
                $name = trim($data['name']);
                $this->assertUniqueName($name, $companyId, $role->id);
                if (strtolower($name) === 'owner') {
                    throw new HttpException(422, '"Owner" is a reserved role name.');
                }
                $role->name = $name;
            }
            if (array_key_exists('description', $data)) {
                $role->description = $data['description'];
            }
            if (isset($data['sensitive']) && is_array($data['sensitive'])) {
                $role->sensitive = $this->sensitiveFrom($data['sensitive']);
            }
            $role->save();

            if (isset($data['matrix']) && is_array($data['matrix'])) {
                $this->writeMatrix($role, $data['matrix']);
            }
        });

        return $this->payload($role->fresh());
    }

    public function create(int $companyId, array $data): array
    {
        $name = trim($data['name']);
        $this->assertUniqueName($name, $companyId);

        $role = DB::transaction(function () use ($companyId, $name, $data) {
            $role = StaffRole::create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $data['description'] ?? null,
                'locked' => false,
                'sensitive' => $this->sensitiveFrom($data['sensitive'] ?? []),
            ]);
            if (!empty($data['matrix']) && is_array($data['matrix'])) {
                $this->writeMatrix($role, $data['matrix']);
            }
            return $role;
        });

        return $this->payload($role->fresh());
    }

    public function duplicate(int $id, int $companyId): array
    {
        $src = $this->find($id, $companyId);

        $name = $src->name . ' copy';
        $i = 2;
        while (StaffRole::where('company_id', $companyId)->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])->exists()) {
            $name = $src->name . ' copy ' . $i++;
        }

        $payload = $this->payload($src);

        $role = DB::transaction(function () use ($companyId, $name, $src, $payload) {
            $role = StaffRole::create([
                'company_id' => $companyId,
                'name' => $name,
                'description' => $src->description,
                'locked' => false,
                'sensitive' => $payload['sensitive'],
            ]);
            $this->writeMatrix($role, $payload['matrix']);
            return $role;
        });

        return $this->payload($role->fresh());
    }

    public function assertDeletable(StaffRole $role): void
    {
        if ($role->isLocked()) {
            throw new HttpException(422, 'The Owner role can’t be deleted.');
        }
        $n = $role->staff()->count() + SaasUser::where('role_id', $role->id)->count();
        if ($n > 0) {
            throw new HttpException(422, "{$n} staff member(s) have this role. Reassign them first.");
        }
    }
}
