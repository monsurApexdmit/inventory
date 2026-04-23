<?php

namespace App\Http\Controllers\Api\Realtime;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        if (!$request->user()) {
            throw new AccessDeniedHttpException('Unauthenticated.');
        }

        if (config('broadcasting.default') === 'reverb' && !class_exists(\Pusher\Pusher::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Realtime service is not available.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        try {
            return Broadcast::auth($request);
        } catch (\Throwable $e) {
            Log::warning('Broadcast auth failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Realtime authentication failed.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
