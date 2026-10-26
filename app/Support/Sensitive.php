<?php

namespace App\Support;

use App\Models\SaasUser;
use App\Models\Staff;
use App\Models\StaffRole;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Role "sensitive action" flags: cost, discount, refund, export, void.
 *
 * - owner / admin / super admin always have every flag.
 * - a role whose `sensitive` column is NULL (created before the matrix existed) keeps full access.
 * - no authenticated request (console, tinker, jobs) is not restricted.
 */
class Sensitive
{
    public static function all(bool $value = true): array
    {
        return array_fill_keys(StaffRole::SENSITIVE_KEYS, $value);
    }

    /** Normalise a stored/posted map to the five boolean keys (missing keys = false). */
    public static function normalize(?array $raw): array
    {
        $out = [];
        foreach (StaffRole::SENSITIVE_KEYS as $k) {
            $out[$k] = (bool) ($raw[$k] ?? false);
        }
        return $out;
    }

    public static function forRole(?StaffRole $role): array
    {
        if (!$role) {
            return self::all(false);
        }
        if ($role->isLocked() || $role->sensitive === null) {
            return self::all(true);
        }
        return self::normalize($role->sensitive);
    }

    public static function forSaasUser(SaasUser $user): array
    {
        if (in_array($user->role, ['owner', 'admin', 'super_admin'], true)) {
            return self::all(true);
        }
        return self::forRole($user->role_id ? StaffRole::find($user->role_id) : null);
    }

    public static function forStaff(Staff $staff): array
    {
        $legacyTitle = strtolower((string) $staff->user?->role?->title);
        if (in_array($legacyTitle, ['admin', 'owner'], true)) {
            return self::all(true);
        }
        return self::forRole($staff->staff_role_id ? StaffRole::find($staff->staff_role_id) : null);
    }

    /** Flags of the user behind the current request. */
    public static function current(): array
    {
        $req = request();
        $userId = $req->attributes->get('auth_user_id');

        if ($req->attributes->get('auth_is_super_admin') || !$userId) {
            return self::all(true);
        }

        if (!$req->attributes->get('auth_is_legacy', false)) {
            $user = SaasUser::find($userId);
            return $user ? self::forSaasUser($user) : self::all(false);
        }

        $staff = Staff::where('user_id', $userId)->with('user.role')->first();
        return $staff ? self::forStaff($staff) : self::all(false);
    }

    public static function allows(string $key): bool
    {
        return (bool) (self::current()[$key] ?? false);
    }

    public static function require(string $key, string $message, int $status = 403): void
    {
        if (!self::allows($key)) {
            throw new HttpException($status, $message);
        }
    }
}
