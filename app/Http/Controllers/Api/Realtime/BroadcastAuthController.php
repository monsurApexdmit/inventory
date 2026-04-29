<?php

namespace App\Http\Controllers\Api\Realtime;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SupportTicket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BroadcastAuthController extends Controller
{
    public function __invoke(Request $request)
    {
        if (config('broadcasting.default') === 'reverb' && !class_exists(\Pusher\Pusher::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Realtime service is not available.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if (!$request->user()) {
            $customer = $this->resolveStorefrontCustomer($request);
            if ($customer) {
                $request->attributes->set('storefront_customer', $customer);
                $request->setUserResolver(static fn() => $customer);
            }
        }

        if ($request->user()) {
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

        if ($this->canAuthorizeGuestSupportTicket($request)) {
            return $this->authorizeGuestChannel($request);
        }

        throw new AccessDeniedHttpException('Unauthenticated.');
    }

    private function resolveStorefrontCustomer(Request $request): ?Customer
    {
        $header = (string) $request->header('Authorization', '');
        $token = str_replace('Bearer ', '', $header);

        if ($token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, config('app.key'));

        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            return null;
        }

        $segments = explode('|', $decoded);
        if (count($segments) < 3) {
            return null;
        }

        [$customerId, $companyId] = $segments;

        return Customer::query()
            ->where('id', (int) $customerId)
            ->where('company_id', (int) $companyId)
            ->where('status', 'active')
            ->first();
    }

    private function canAuthorizeGuestSupportTicket(Request $request): bool
    {
        $channelName = (string) $request->input('channel_name', '');
        $guestToken = (string) $request->input('guest_token', '');
        $companyId = (int) $request->input('company_id');

        if ($guestToken === '' || $companyId <= 0) {
            return false;
        }

        if (!preg_match('/^private-support\.ticket\.(\d+)$/', $channelName, $matches)) {
            return false;
        }

        $ticketId = (int) $matches[1];
        $ticket = SupportTicket::query()
            ->select(['id', 'company_id', 'guest_access_token'])
            ->find($ticketId);

        if (!$ticket) {
            return false;
        }

        return (int) $ticket->company_id === $companyId
            && is_string($ticket->guest_access_token)
            && hash_equals($ticket->guest_access_token, $guestToken);
    }

    private function authorizeGuestChannel(Request $request): Response
    {
        $socketId = (string) $request->input('socket_id');
        $channelName = (string) $request->input('channel_name');

        $config = config('broadcasting.connections.reverb');
        $pusher = new \Pusher\Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options'] ?? [],
        );

        try {
            return response($pusher->authorizeChannel($channelName, $socketId), 200, ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            Log::warning('Guest broadcast auth failed: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Realtime authentication failed.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }
}
