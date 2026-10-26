<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

class ActorName
{
    /** Display name of the authenticated actor (saas user, staff or legacy user). */
    public static function current(): ?string
    {
        $u = Auth::user();
        if (!$u) {
            return null;
        }
        $name = $u->full_name ?? $u->name ?? $u->username ?? $u->email ?? null;
        return $name ? mb_substr((string) $name, 0, 150) : null;
    }
}
