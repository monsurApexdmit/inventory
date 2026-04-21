<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');
        $token  = str_replace('Bearer ', '', $header);

        if (!$token) {
            return response()->json(['success' => false, 'message' => 'Customer token required'], 401);
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return response()->json(['success' => false, 'message' => 'Invalid token format'], 401);
        }

        [$payload, $sig] = $parts;

        $expected = hash_hmac('sha256', $payload, config('app.key'));
        if (!hash_equals($expected, $sig)) {
            return response()->json(['success' => false, 'message' => 'Invalid token'], 401);
        }

        $decoded = base64_decode($payload);
        $parts   = explode('|', $decoded);

        if (count($parts) < 3) {
            return response()->json(['success' => false, 'message' => 'Invalid token payload'], 401);
        }

        [$customerId, $companyId, $issuedAt] = $parts;

        $customer = Customer::where('id', (int) $customerId)
            ->where('company_id', (int) $companyId)
            ->where('status', 'active')
            ->first();

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found or inactive'], 401);
        }

        $request->attributes->set('storefront_customer', $customer);

        return $next($request);
    }
}
