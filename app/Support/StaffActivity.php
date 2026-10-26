<?php

namespace App\Support;

use App\Models\SaasUser;
use App\Models\Staff;
use Illuminate\Support\Facades\Cache;

/** Records staff.last_active_at (throttled to once per 5 minutes per account). */
class StaffActivity
{
    private const THROTTLE_SECONDS = 300;

    /** A SaaS (team) user is mapped to its staff row by company + email. */
    public static function touchSaasUser(SaasUser $user, bool $force = false): void
    {
        if (!$user->company_id || !$user->email) {
            return;
        }
        self::run("saas:{$user->id}", $force, fn () => Staff::where('company_id', $user->company_id)->where('email', $user->email));
    }

    /** A legacy (users table) staff account is mapped through staff.user_id. */
    public static function touchLegacyUser(int $userId, bool $force = false): void
    {
        self::run("legacy:{$userId}", $force, fn () => Staff::where('user_id', $userId));
    }

    private static function run(string $key, bool $force, \Closure $query): void
    {
        try {
            if (!$force && !Cache::add("staff_active:{$key}", 1, self::THROTTLE_SECONDS)) {
                return;
            }
            if ($force) {
                Cache::put("staff_active:{$key}", 1, self::THROTTLE_SECONDS);
            }
            $now = now();
            $query()->update(['last_active_at' => $now]);
            // First activity from an invited member means the invite was accepted.
            $query()->where('status', 'Invited')->update(['status' => 'Active']);
        } catch (\Throwable $e) {
            // Activity tracking must never break a request.
        }
    }
}
