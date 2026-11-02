<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick orders from social ads: at most 10 attempts per hour per store + IP.
 * (Returns the 429 directly; the per-phone daily limit lives in QuickOrderService.)
 */
class ThrottleQuickOrders
{
    public const MAX_ATTEMPTS = 10;
    public const DECAY_SECONDS = 3600;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'quick-order-ip:' . ($request->query('company_id') ?: $request->input('company_id')) . ':' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many orders from this device. Please try again later.',
            ], 429, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}
