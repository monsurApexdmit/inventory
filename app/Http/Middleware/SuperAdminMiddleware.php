<?php

namespace App\Http\Middleware;

use App\Models\SaasUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->attributes->get('auth_user_id');

        if (!$userId) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $user = SaasUser::find($userId);

        if (!$user || $user->role !== 'super_admin') {
            return response()->json(['success' => false, 'message' => 'Platform admin access required.'], 403);
        }

        $request->attributes->set('auth_is_super_admin', true);

        return $next($request);
    }
}
