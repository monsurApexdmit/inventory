<?php

namespace App\Http\Controllers\Api\V1\Shipping;

use App\Http\Controllers\Controller;
use App\Services\Courier\CourierShipmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Public courier status callbacks: POST /api/webhooks/courier/{provider}/{token} (no JWT). */
class CourierWebhookController extends Controller
{
    public function __construct(private readonly CourierShipmentService $service)
    {
    }

    public function handle(Request $request, string $provider, string $token): JsonResponse
    {
        $payload = $request->json()->all() ?: $request->all();
        [$status, $body, $headers] = $this->service->handleWebhook(
            $provider,
            $token,
            is_array($payload) ? $payload : [],
            $request->bearerToken(),
            $request->header('X-PATHAO-Signature'),
        );

        return response()->json($body, $status, $headers);
    }
}
