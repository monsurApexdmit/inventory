<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\SaasUser;
use App\Models\Staff;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Check route-level permission.
     *
     * Permission name format: "Module.action"  e.g. "Products.read"
     *
     * Rules:
     *  - owner / admin (SaasUser role field) → bypass, always allowed
     *  - manager / staff (SaasUser with role) → check via staff_role_id → role_permissions
     *  - legacy staff (User model) → check via Staff record → staff_role_id → role_permissions
     */
    /**
     * Supports OR permissions via comma separation: "TailorOrders.read,TailorMeasurements.read"
     * Access granted if ANY of the listed permissions passes.
     */
    public function handle(Request $request, Closure $next, string $permissionDotAction): Response
    {
        $userId    = $request->attributes->get('auth_user_id');
        $companyId = (int) $request->attributes->get('auth_company_id');
        $isLegacy  = (bool) $request->attributes->get('auth_is_legacy', false);

        // Super admins bypass all permission checks
        if ($request->attributes->get('auth_is_super_admin')) {
            return $next($request);
        }

        if (!$userId || !$companyId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $permissions = array_map('trim', explode(',', $permissionDotAction));

        if (!$isLegacy) {
            $saasUser = SaasUser::find($userId);
            if (!$saasUser || (int) $saasUser->company_id !== $companyId) {
                return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
            }

            if (in_array($saasUser->role, ['owner', 'admin'], true)) {
                return $next($request);
            }

            foreach ($permissions as $perm) {
                [$module, $action] = array_pad(explode('.', $perm, 2), 2, 'read');
                if ($this->checkRolePermission($saasUser->role_id, $module, $action, $request)) {
                    return $next($request);
                }
            }

            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        $staff = Staff::where('user_id', $userId)
            ->where('company_id', $companyId)
            ->with('user.role')
            ->first();

        if (!$staff) {
            return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
        }

        // Legacy admin/owner roles bypass permission checks
        $legacyRoleTitle = $staff->user?->role?->title ?? '';
        if (in_array(strtolower($legacyRoleTitle), ['admin', 'owner'], true)) {
            return $next($request);
        }

        if (!$staff->staff_role_id) {
            return response()->json(['success' => false, 'message' => 'Access denied. No role assigned.'], 403);
        }

        foreach ($permissions as $perm) {
            [$module, $action] = array_pad(explode('.', $perm, 2), 2, 'read');
            if ($this->checkRolePermission($staff->staff_role_id, $module, $action, $request)) {
                return $next($request);
            }
        }

        return response()->json(['success' => false, 'message' => 'Access denied.'], 403);
    }

    /**
     * Actions: read | write | create | edit | delete.
     *  - "write" is method-aware: POST needs `create` (or `edit` when the route targets an existing
     *    record, e.g. POST /x/{id}/approve), PUT/PATCH need `edit`, anything else needs create||edit.
     *    Legacy rows that only carry the old `write` flag (create=edit=false) keep working.
     *  - "create" / "edit" check those flags explicitly (for action-style routes).
     */
    private function checkRolePermission(int|null $roleId, string $module, string $action, ?Request $request = null): bool
    {
        if (!$roleId) {
            return false;
        }

        // The locked Owner role always has full access.
        if (\App\Models\StaffRole::find($roleId)?->isLocked()) {
            return true;
        }

        $permission = Permission::where('name', $module)->first();
        if (!$permission) {
            return false;
        }

        $rp = RolePermission::where('role_id', $roleId)
            ->where('permission_id', $permission->id)
            ->first();

        if (!$rp) {
            return false;
        }

        $legacyWrite = (bool) $rp->write && !$rp->create && !$rp->edit;

        return match ($action) {
            'write'  => $legacyWrite || $this->writeAllowed($rp, $request),
            'create' => $legacyWrite || (bool) $rp->create,
            'edit'   => $legacyWrite || (bool) $rp->edit,
            'delete' => (bool) $rp->delete,
            default  => (bool) $rp->read,
        };
    }

    private function writeAllowed(RolePermission $rp, ?Request $request): bool
    {
        $method = strtoupper($request?->method() ?? 'POST');

        if ($method === 'POST') {
            $targetsRecord = $request && $request->route() && !empty($request->route()->parameters());
            return (bool) $rp->create || ($targetsRecord && (bool) $rp->edit);
        }

        if (in_array($method, ['PUT', 'PATCH'], true)) {
            return (bool) $rp->edit;
        }

        return (bool) $rp->create || (bool) $rp->edit;
    }
}
