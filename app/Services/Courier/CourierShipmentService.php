<?php

namespace App\Services\Courier;

use App\DTOs\Shipping\OrderShipmentDTO;
use App\Models\CourierAccount;
use App\Models\OrderShipment;
use App\Models\Sell;
use App\Models\ShipmentTrackingHistory;
use App\Repositories\Contracts\ICourierAccountRepository;
use App\Repositories\Contracts\IOrderShipmentRepository;
use App\Services\Notification\NotificationService;
use App\Services\Shipping\OrderShipmentService;
use App\Support\BdPhone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Book orders with a courier API, keep their status in sync (manual refresh, scheduler, webhooks) and
 * apply the courier's status to our shipment + order (contract: COURIER_API_CONTRACT.md §3–6).
 */
class CourierShipmentService
{
    public function __construct(
        private readonly ICourierAccountRepository $accounts,
        private readonly IOrderShipmentRepository $shipments,
        private readonly CourierManager $manager,
        private readonly OrderShipmentService $shipmentService,
        private readonly NotificationService $notifications,
    ) {
    }

    // ── Booking ──────────────────────────────────────────────────────────────

    public function book(int $sellId, int $companyId, array $data): OrderShipmentDTO
    {
        $account = $this->resolveAccount($companyId, $data['courierAccountId'] ?? null);
        $driver = $this->manager->driver($account);

        $lock = Cache::lock("courier-book:{$companyId}:{$sellId}", 60);
        if (!$lock->get()) {
            throw new HttpException(409, 'This order is being sent to the courier already. Refresh in a moment.');
        }
        try {
            $sell = $this->findSell($sellId, $companyId);
            $reuse = $this->assertBookable($sell);
            $parcel = $this->buildParcel($sell, $account, $data);
            $result = $driver->createParcel($parcel);
            try {
                $shipment = $this->persistBooking($sell, $account, $driver, $parcel, $result, $data, $reuse);
            } catch (\Throwable $e) {
                Log::error('Courier booked but saving the shipment failed', ['sell' => $sell->id, 'consignment' => $result->consignmentId, 'error' => $e->getMessage()]);
                throw new HttpException(500, "Booked at {$driver->label()} (consignment {$result->consignmentId}, tracking {$result->trackingCode}) but saving it here failed. Add the tracking code to the order manually.");
            }
        } finally {
            $lock->release();
        }

        return $this->shipmentService->get($shipment->id, $companyId);
    }

    /** Book many orders with one account. Partial success: every order gets its own result. */
    public function bookBulk(int $companyId, array $sellIds, ?int $accountId, array $defaults): array
    {
        $account = $this->resolveAccount($companyId, $accountId);
        $driver = $this->manager->driver($account);
        $sellIds = array_values(array_unique(array_map('intval', $sellIds)));

        $results = [];
        $parcels = [];
        $ctx = [];
        $locks = [];
        try {
            foreach ($sellIds as $id) {
                $results[$id] = ['sellId' => $id, 'invoiceNo' => null, 'ok' => false, 'shipmentId' => null, 'consignmentId' => null, 'trackingCode' => null, 'message' => null];
                $lock = Cache::lock("courier-book:{$companyId}:{$id}", 120);
                if (!$lock->get()) {
                    $results[$id]['message'] = 'This order is being sent to the courier already';
                    continue;
                }
                $locks[] = $lock;
                try {
                    $sell = $this->findSell($id, $companyId);
                    $results[$id]['invoiceNo'] = $sell->invoice_no;
                    $reuse = $this->assertBookable($sell);
                    $parcel = $this->buildParcel($sell, $account, $defaults);
                    $parcels[] = $parcel;
                    $ctx[$parcel->ref] = [$sell, $parcel, $reuse];
                } catch (HttpException $e) {
                    $results[$id]['message'] = $e->getMessage();
                }
            }

            $created = $parcels ? $driver->bulkCreate($parcels) : [];
            foreach ($ctx as $ref => [$sell, $parcel, $reuse]) {
                $r = $created[$ref] ?? null;
                if ($r instanceof ParcelResult) {
                    try {
                        $shipment = $this->persistBooking($sell, $account, $driver, $parcel, $r, $defaults, $reuse);
                        $results[$sell->id] = array_merge($results[$sell->id], [
                            'ok' => true, 'shipmentId' => $shipment->id, 'consignmentId' => $r->consignmentId, 'trackingCode' => $shipment->tracking_number,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('Courier booked but saving the shipment failed', ['sell' => $sell->id, 'consignment' => $r->consignmentId, 'error' => $e->getMessage()]);
                        $results[$sell->id]['message'] = "Booked at the courier (consignment {$r->consignmentId}) but saving it failed — add the tracking code manually";
                        $results[$sell->id]['consignmentId'] = $r->consignmentId;
                    }
                } else {
                    $results[$sell->id]['message'] = $r instanceof \Throwable ? $r->getMessage() : 'No result from the courier';
                }
            }
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        $rows = array_values($results);
        $ok = count(array_filter($rows, fn ($r) => $r['ok']));

        return ['total' => count($rows), 'succeeded' => $ok, 'failed' => count($rows) - $ok, 'results' => $rows];
    }

    public function quote(int $companyId, array $q): array
    {
        $account = $this->resolveAccount($companyId, $q['courierAccountId'] ?? null);
        $settingsFallback = [
            'weightKg' => $q['weightKg'] ?? $account->setting('weightKg', 0.5),
            'deliveryType' => $q['deliveryType'] ?? $account->setting('deliveryType', 48),
            'itemType' => $q['itemType'] ?? $account->setting('itemType', 2),
        ];
        $res = $this->manager->driver($account)->quote(array_merge($q, $settingsFallback));

        return $res + ['currency' => 'BDT', 'provider' => $account->providerKey()];
    }

    public function customerRisk(int $companyId, string $phone, ?int $accountId): array
    {
        $normalized = BdPhone::normalize($phone);
        if (!$normalized) {
            throw new HttpException(422, 'Enter a valid Bangladeshi mobile number');
        }
        $account = $this->resolveAccount($companyId, $accountId);
        $base = ['available' => false, 'provider' => $account->providerKey(), 'phone' => $normalized,
            'totalParcels' => null, 'delivered' => null, 'returned' => null, 'successRate' => null, 'rating' => null, 'message' => null];

        $res = Cache::remember("courier:risk:{$account->id}:{$normalized}", now()->addHours(6), function () use ($account, $normalized) {
            return $this->manager->driver($account)->customerSuccessRate($normalized) ?? false;
        });
        if ($res === false) {
            return array_merge($base, ['message' => CourierManager::label($account->providerKey()) . ' does not share customer delivery history']);
        }
        return array_merge($base, $res, ['available' => true]);
    }

    // ── Sync / cancel / tracking ─────────────────────────────────────────────

    public function sync(int $shipmentId, int $companyId): OrderShipmentDTO
    {
        $shipment = $this->findCourierShipment($shipmentId, $companyId);
        $this->syncShipment($shipment, true);

        return $this->shipmentService->get($shipment->id, $companyId);
    }

    /**
     * Pull the latest status for one shipment. $throw=false (scheduler) records the error on the shipment instead.
     * Returns true when the shipment status changed.
     */
    public function syncShipment(OrderShipment $shipment, bool $throw = false): bool
    {
        $account = $shipment->courierAccount;
        if (!$account) {
            if ($throw) {
                throw new HttpException(422, 'The courier account of this shipment was removed');
            }
            return false;
        }
        try {
            $status = $this->manager->driver($account)->fetchStatus($shipment);
        } catch (CourierException $e) {
            $shipment->forceFill(['last_synced_at' => now(), 'sync_error' => mb_substr($e->getMessage(), 0, 500)])->saveQuietly();
            if ($throw) {
                throw $e;
            }
            return false;
        }
        $isFinalState = in_array(CourierStatusMapper::normalize($account->providerKey(), $status->rawStatus, $shipment->courier_state), [CourierStatusMapper::DELIVERED, CourierStatusMapper::PARTIAL_DELIVERED], true);

        return $this->applyUpdate($shipment, $status->rawStatus, [
            'source' => 'sync',
            'codCollected' => $isFinalState ? $status->codCollected : null,
            'deliveryFee' => $status->deliveryFee,
            'statusAt' => $status->statusAt,
            'payload' => $status->raw,
        ]);
    }

    public function cancel(int $shipmentId, int $companyId): OrderShipmentDTO
    {
        $shipment = $this->findCourierShipment($shipmentId, $companyId);
        $account = $shipment->courierAccount;
        if (!$account) {
            throw new HttpException(422, 'The courier account of this shipment was removed');
        }
        $driver = $this->manager->driver($account);
        if (!$driver->supportsCancel()) {
            throw new HttpException(422, "{$driver->label()} does not allow cancelling through the API. Cancel it from the {$driver->label()} portal — the status will update here automatically.");
        }
        if (CourierStatusMapper::isFinal($shipment->courier_state) || !$shipment->isOpen()) {
            throw new HttpException(422, 'This parcel can no longer be cancelled');
        }
        if (!in_array($shipment->courier_state, [null, CourierStatusMapper::BOOKED, CourierStatusMapper::PICKUP_PENDING], true)) {
            throw new HttpException(422, 'The courier has already picked up this parcel. Ask the courier to return it instead.');
        }
        $driver->cancel($shipment);
        $this->applyUpdate($shipment, 'Cancelled', ['source' => 'cancel', 'message' => "Cancelled at {$driver->label()} by staff"]);

        return $this->shipmentService->get($shipment->id, $companyId);
    }

    public function tracking(int $shipmentId, int $companyId): array
    {
        $shipment = $this->findCourierShipment($shipmentId, $companyId);
        $events = null;
        $source = 'local';
        $error = null;
        if ($shipment->courierAccount) {
            try {
                $events = $this->manager->driver($shipment->courierAccount)->trackingEvents($shipment);
                $source = $events !== null ? 'courier' : 'local';
            } catch (CourierException $e) {
                $error = $e->getMessage();
            }
        }
        if ($events === null || $events === []) {
            $source = 'local';
            $events = ShipmentTrackingHistory::where('shipment_id', $shipment->id)->orderByDesc('event_time')->orderByDesc('id')->get()
                ->map(fn ($h) => [
                    'status' => $h->status,
                    'message' => (string) ($h->description ?? $h->status),
                    'time' => $h->event_time?->toIso8601String(),
                ])->all();
        }

        return [
            'provider' => $shipment->courier_provider,
            'status' => $shipment->courier_state,
            'rawStatus' => $shipment->courier_status,
            'source' => $source,
            'message' => $error,
            'events' => $events,
        ];
    }

    // ── Applying a courier status ────────────────────────────────────────────

    /**
     * Record a courier's raw status on the shipment and, when it moves the parcel forward, update the shipment
     * and order. Never moves backwards; repeated / older statuses only refresh the sync time.
     *
     * @param array{source?: string, codCollected?: ?float, deliveryFee?: ?float, statusAt?: ?string, payload?: ?array, message?: ?string} $opts
     */
    public function applyUpdate(OrderShipment $shipment, ?string $raw, array $opts = []): bool
    {
        $provider = (string) $shipment->courier_provider;
        $label = CourierManager::label($provider);
        $current = $shipment->courier_state;
        $state = CourierStatusMapper::normalize($provider, $raw, $current);
        $moves = $state !== null && CourierStatusMapper::canMove($current, $state);
        $rawChanged = $raw !== null && $raw !== '' && $raw !== $shipment->courier_status;

        $shipment->last_synced_at = now();
        $shipment->sync_error = null;
        if (isset($opts['deliveryFee']) && $opts['deliveryFee'] !== null) {
            $shipment->delivery_fee = $opts['deliveryFee'];
        }
        if (!empty($opts['payload'])) {
            $shipment->courier_payload = array_merge(is_array($shipment->courier_payload) ? $shipment->courier_payload : [], ['last' => $opts['payload']]);
        }
        if ($rawChanged) {
            $shipment->courier_status = mb_substr((string) $raw, 0, 100);
            $shipment->courier_status_at = $this->parseTime($opts['statusAt'] ?? null) ?? now();
        }

        if (!$moves && !$rawChanged && empty($opts['message'])) {
            $shipment->save();
            return false;
        }

        $codCollected = null;
        $collectCod = true;
        if ($moves && in_array($state, [CourierStatusMapper::DELIVERED, CourierStatusMapper::PARTIAL_DELIVERED], true)) {
            $codCollected = isset($opts['codCollected']) && $opts['codCollected'] !== null ? (float) $opts['codCollected'] : null;
            if ($codCollected !== null) {
                $shipment->courier_cod_collected = $codCollected;
            }
            // A partial delivery with an unknown amount: leave the COD for staff to record
            if ($state === CourierStatusMapper::PARTIAL_DELIVERED && $codCollected === null) {
                $collectCod = false;
            }
        }
        if ($moves) {
            $shipment->courier_state = $state;
        }

        $desc = $opts['message'] ?? null;
        if (!$desc) {
            $desc = "{$label}: " . ($raw ?: $state) . ($moves && $state !== null ? " → {$state}" : '');
        }
        $newShipmentStatus = $moves ? CourierStatusMapper::shipmentStatus($state) : null;

        $changed = $this->shipmentService->applyCourierStatus($shipment, $newShipmentStatus, $desc, $codCollected, null, $collectCod);

        if ($changed && $moves) {
            $this->afterStateChange($shipment->fresh(['sell']), $state, $label);
        }
        return $changed;
    }

    private function afterStateChange(OrderShipment $shipment, string $state, string $label): void
    {
        $sell = $shipment->sell;
        if (!$sell) {
            return;
        }
        if (in_array($state, [CourierStatusMapper::DELIVERED, CourierStatusMapper::PARTIAL_DELIVERED], true) && !$sell->delivered_at && $sell->status === 'Delivered') {
            $sell->forceFill(['delivered_at' => now()])->save();
        }
        if (in_array($state, [CourierStatusMapper::DELIVERED, CourierStatusMapper::PARTIAL_DELIVERED, CourierStatusMapper::RETURNED, CourierStatusMapper::CANCELLED], true)) {
            $this->notifications->notifyCourierUpdate((int) $shipment->company_id, (string) $sell->invoice_no, $label, $state, $shipment->tracking_number);
        }
    }

    // ── Webhooks ─────────────────────────────────────────────────────────────

    /**
     * Handle a courier webhook. Returns [httpStatus, body, headers]. Auth: token in the URL (per account) and,
     * for Steadfast, a matching Bearer token. Duplicate deliveries are ignored.
     */
    public function handleWebhook(string $provider, string $token, array $payload, ?string $bearer, ?string $signature): array
    {
        $provider = strtolower($provider);
        $ok = $provider === 'pathao' ? 202 : 200;
        $okBody = ['status' => 'success', 'message' => 'Webhook received'];
        $deny = [401, ['status' => 'error', 'message' => 'Unauthorized'], []];

        if (!CourierManager::supports($provider) || $token === '') {
            return [404, ['status' => 'error', 'message' => 'Not found'], []];
        }
        $account = $this->accounts->findByWebhookToken($provider, $token);
        if (!$account) {
            return $deny;
        }
        if ($provider === 'steadfast' && ($bearer === null || $bearer === '' || !hash_equals((string) $account->webhook_token, $bearer))) {
            return $deny;
        }
        if ($provider === 'pathao' && $signature !== null && $signature !== '' && !hash_equals((string) $account->webhook_token, $signature)) {
            return $deny;
        }

        // Pathao integration handshake (UNCONFIRMED): echo the integration secret header when configured
        $headers = [];
        if ($provider === 'pathao' && ($secret = config('couriers.pathao.webhook_integration_secret'))) {
            $headers['X-Pathao-Merchant-Webhook-Integration-Secret'] = (string) $secret;
        }
        $event = (string) ($payload['event'] ?? $payload['notification_type'] ?? '');
        if ($provider === 'pathao' && $event === 'webhook_integration') {
            return [$ok, $okBody, $headers];
        }

        $consignment = (string) ($payload['consignment_id'] ?? $payload['consignmentId'] ?? $payload['tracking_code'] ?? '');
        $invoice = $payload['invoice'] ?? $payload['merchant_order_id'] ?? null;
        $raw = $payload['status'] ?? $payload['order_status'] ?? $payload['delivery_status'] ?? $payload['order_status_slug'] ?? null;
        $raw = is_scalar($raw) ? (string) $raw : null;
        if (!$raw && $provider === 'pathao' && str_starts_with($event, 'order.')) {
            $raw = substr($event, 6);   // e.g. "order.delivered" (UNCONFIRMED event naming)
        }
        $message = $payload['tracking_message'] ?? $payload['message'] ?? null;
        $updatedAt = (string) ($payload['updated_at'] ?? $payload['timestamp'] ?? '');
        if ($consignment === '' && !$invoice) {
            return [422, ['status' => 'error', 'message' => 'consignment_id is required'], $headers];
        }

        $dedupe = sha1(implode('|', [$account->id, $consignment, $invoice, $raw, $event, $updatedAt, is_scalar($message) ? $message : '']));
        try {
            DB::transaction(function () use ($account, $provider, $dedupe, $payload, $consignment, $invoice, $raw, $message, $updatedAt) {
                $exists = DB::table('courier_webhook_events')->where('provider', $provider)->where('dedupe_key', $dedupe)->lockForUpdate()->exists();
                if ($exists) {
                    return;
                }
                $shipment = $this->accounts->findShipmentByConsignment($account, $consignment, is_scalar($invoice) ? (string) $invoice : null);
                $result = 'ignored';
                if ($shipment) {
                    $shipment->setRelation('courierAccount', $account);
                    if ($raw) {
                        $cod = $payload['cod_amount'] ?? $payload['collected_amount'] ?? null;
                        $this->applyUpdate($shipment, $raw, [
                            'source' => 'webhook',
                            'codCollected' => is_numeric($cod) ? (float) $cod : null,
                            'deliveryFee' => isset($payload['delivery_fee']) && is_numeric($payload['delivery_fee']) ? (float) $payload['delivery_fee'] : null,
                            'statusAt' => $updatedAt ?: null,
                        ]);
                        $result = 'applied';
                    } elseif (is_scalar($message) && $message !== '') {
                        ShipmentTrackingHistory::create([
                            'shipment_id' => $shipment->id,
                            'status' => $shipment->status,
                            'description' => mb_substr(CourierManager::label($provider) . ': ' . $message, 0, 250),
                            'event_time' => $this->parseTime($updatedAt) ?? now(),
                        ]);
                        $result = 'tracking';
                    }
                }
                DB::table('courier_webhook_events')->insert([
                    'company_id' => $account->company_id,
                    'courier_account_id' => $account->id,
                    'provider' => $provider,
                    'dedupe_key' => $dedupe,
                    'shipment_id' => $shipment?->id,
                    'result' => $result,
                    'payload' => json_encode(array_intersect_key($payload, array_flip([
                        'notification_type', 'event', 'consignment_id', 'invoice', 'merchant_order_id', 'status', 'order_status', 'order_status_slug',
                        'delivery_status', 'cod_amount', 'collected_amount', 'delivery_fee', 'updated_at', 'tracking_message',
                    ]))),
                    'created_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // concurrent duplicate delivery: already handled
        } catch (\Throwable $e) {
            Log::error('Courier webhook processing failed', ['provider' => $provider, 'account' => $account->id, 'error' => $e->getMessage()]);
            return [500, ['status' => 'error', 'message' => 'Could not process the update'], $headers];
        }

        return [$ok, $okBody, $headers];
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function resolveAccount(int $companyId, mixed $accountId): CourierAccount
    {
        $account = $accountId ? $this->accounts->findByIdAndCompany((int) $accountId, $companyId) : $this->accounts->findDefault($companyId);
        if (!$account) {
            throw new HttpException($accountId ? 404 : 422, $accountId ? 'Courier account not found' : 'Connect a courier first (Shipping methods → Courier integrations) or pick one');
        }
        if (!$account->is_active) {
            throw new HttpException(422, CourierManager::label($account->providerKey()) . ' is turned off. Turn it on in Shipping methods first.');
        }
        if (!CourierManager::supports($account->providerKey())) {
            throw new HttpException(422, CourierManager::label($account->providerKey()) . ' is not integrated yet');
        }
        return $account;
    }

    private function findSell(int $sellId, int $companyId): Sell
    {
        $sell = Sell::where('company_id', $companyId)->with(['items', 'shipments', 'customer'])->find($sellId);
        if (!$sell) {
            throw new HttpException(404, 'Order not found');
        }
        return $sell;
    }

    private function findCourierShipment(int $id, int $companyId): OrderShipment
    {
        $shipment = $this->accounts->findCourierShipment($id, $companyId);
        if (!$shipment) {
            throw new HttpException(404, 'Shipment not found');
        }
        if (!$shipment->isCourierBooked()) {
            throw new HttpException(422, 'This shipment was not booked through a courier integration');
        }
        return $shipment;
    }

    /** Guards; returns an existing un-booked pending shipment to fill instead of creating a second one. */
    private function assertBookable(Sell $sell): ?OrderShipment
    {
        if ($sell->status === 'Cancelled') {
            throw new HttpException(422, 'A cancelled order cannot be sent to a courier');
        }
        if ($sell->status === 'Delivered') {
            throw new HttpException(422, 'This order is already delivered');
        }
        $live = $sell->shipments->filter(fn (OrderShipment $s) => $s->isLive());
        $booked = $live->first(fn (OrderShipment $s) => $s->isCourierBooked());
        if ($booked) {
            throw new HttpException(409, "This order is already booked with {$booked->carrier} (tracking {$booked->tracking_number})");
        }
        $other = $live->first();
        if ($other && $other->status !== 'pending') {
            throw new HttpException(409, "This order already has an active shipment ({$other->carrier}, {$other->status}). Close it before booking a courier.");
        }
        return $other ?: null;
    }

    private function buildParcel(Sell $sell, CourierAccount $account, array $data): ParcelData
    {
        $r = is_array($data['recipient'] ?? null) ? $data['recipient'] : [];
        $name = trim((string) ($r['name'] ?? '')) ?: ($sell->shipping_full_name ?: ($sell->customer_name ?: $sell->customer?->name));
        $phoneRaw = trim((string) ($r['phone'] ?? '')) ?: ($sell->shipping_phone ?: $sell->customer?->phone);
        $address = trim((string) ($r['address'] ?? '')) ?: implode(', ', array_filter([
            $sell->shipping_address_line1, $sell->shipping_address_line2, $sell->shipping_city, $sell->shipping_state, $sell->shipping_postal_code,
        ]));

        $phone = BdPhone::normalize($phoneRaw);
        if (!$phone) {
            throw new HttpException(422, $phoneRaw ? "Recipient phone \"{$phoneRaw}\" is not a valid Bangladeshi mobile number" : 'The order has no recipient phone');
        }
        if (mb_strlen(trim($address)) < 5) {
            throw new HttpException(422, 'The order has no delivery address');
        }
        if (!$name) {
            throw new HttpException(422, 'The order has no recipient name');
        }

        $due = max(0, round((float) $sell->amount - (float) $sell->paid_amount, 2));
        $cod = array_key_exists('codAmount', $data) && $data['codAmount'] !== null ? max(0, (float) $data['codAmount']) : $due;

        $qty = (int) $sell->items->sum('quantity');
        $desc = trim((string) ($data['itemDescription'] ?? '')) ?: (string) $account->setting('itemDescription', '');
        $desc = $desc !== '' ? $desc : Str::limit($sell->items->map(fn ($i) => ((int) $i->quantity) . 'x ' . trim($i->product_name . ($i->variant_name ? " ({$i->variant_name})" : '')))->implode(', '), 200, '…');

        // Re-booking after a cancel/return needs a new merchant order id (couriers reject duplicates)
        $previous = $sell->shipments->filter(fn (OrderShipment $s) => !empty($s->consignment_id))->count();
        $invoice = (string) ($sell->invoice_no ?: 'ORD-' . $sell->id);
        if ($previous > 0) {
            $invoice .= '-R' . ($previous + 1);
        }

        $p = is_array($data['pathao'] ?? null) ? $data['pathao'] : [];

        return new ParcelData(
            ref: (string) $sell->id,
            invoice: $invoice,
            recipientName: (string) $name,
            recipientPhone: $phone,
            recipientAddress: $address,
            codAmount: $cod,
            weightKg: (float) ($data['weightKg'] ?? $account->setting('weightKg', 0.5)),
            deliveryType: (int) ($data['deliveryType'] ?? $account->setting('deliveryType', 48)),
            itemType: (int) ($data['itemType'] ?? $account->setting('itemType', 2)),
            itemQuantity: max(1, $qty),
            itemDescription: $desc ?: null,
            note: ($data['note'] ?? null) ?: ($sell->delivery_note ?: $account->setting('note')),
            storeId: isset($p['storeId']) && $p['storeId'] !== '' ? (string) $p['storeId'] : null,
            cityId: !empty($p['cityId']) ? (int) $p['cityId'] : null,
            zoneId: !empty($p['zoneId']) ? (int) $p['zoneId'] : null,
            areaId: !empty($p['areaId']) ? (int) $p['areaId'] : null,
            recipientEmail: filter_var($sell->customer_email, FILTER_VALIDATE_EMAIL) ? $sell->customer_email : null,
        );
    }

    private function persistBooking(Sell $sell, CourierAccount $account, CourierDriver $driver, ParcelData $parcel, ParcelResult $result, array $data, ?OrderShipment $reuse): OrderShipment
    {
        $provider = $account->providerKey();
        $state = CourierStatusMapper::normalize($provider, $result->rawStatus) ?? CourierStatusMapper::BOOKED;
        if (CourierStatusMapper::isFinal($state)) {
            $state = CourierStatusMapper::BOOKED;
        }
        $companyId = (int) $sell->company_id;

        $tracking = $result->trackingCode;
        if ($this->shipments->trackingExists($companyId, $tracking, $reuse?->id)) {
            $tracking .= '-' . strtoupper(Str::random(4));
        }
        $p = is_array($data['pathao'] ?? null) ? $data['pathao'] : [];
        $isPathao = $provider === CourierAccount::PROVIDER_PATHAO;

        $fields = [
            'tracking_number' => $tracking,
            'carrier' => $driver->label(),
            'courier_account_id' => $account->id,
            'courier_provider' => $provider,
            'consignment_id' => $result->consignmentId,
            'courier_state' => $state,
            'courier_status' => $result->rawStatus,
            'courier_status_at' => now(),
            'courier_payload' => ['invoice' => $parcel->invoice, 'booking' => $result->raw],
            'tracking_url' => $driver->trackingUrl($result->trackingCode, $parcel->recipientPhone),
            'delivery_fee' => $result->deliveryFee,
            'cod_amount' => $parcel->codAmount,
            'weight' => $parcel->weightKg,
            'courier_store_id' => $isPathao ? ($parcel->storeId ?: $account->cred('storeId')) : null,
            'pathao_city_id' => $isPathao ? $parcel->cityId : null,
            'pathao_city_name' => $isPathao ? (isset($p['cityName']) ? mb_substr((string) $p['cityName'], 0, 120) : null) : null,
            'pathao_zone_id' => $isPathao ? $parcel->zoneId : null,
            'pathao_zone_name' => $isPathao ? (isset($p['zoneName']) ? mb_substr((string) $p['zoneName'], 0, 120) : null) : null,
            'pathao_area_id' => $isPathao ? $parcel->areaId : null,
            'pathao_area_name' => $isPathao ? (isset($p['areaName']) ? mb_substr((string) $p['areaName'], 0, 120) : null) : null,
            'booked_at' => now(),
            'last_synced_at' => now(),
            'sync_error' => null,
            'delivery_speed' => $isPathao && $parcel->deliveryType === 12 ? 'express' : ($reuse?->delivery_speed ?? 'regular'),
        ];
        if (!empty($data['note'])) {
            $fields['notes'] = mb_substr((string) $data['note'], 0, 1000);
        }

        return DB::transaction(function () use ($sell, $reuse, $fields, $companyId, $driver, $result) {
            if ($reuse) {
                $reuse->forceFill($fields + ['status' => 'pending'])->save();
                $shipment = $reuse;
            } else {
                $shipment = $this->shipments->create($fields + [
                    'company_id' => $companyId,
                    'sell_id' => $sell->id,
                    'status' => 'pending',
                    'shipping_method' => $sell->shipping_method,
                    'shipping_cost' => (float) $sell->shipping_cost,
                ]);
            }
            ShipmentTrackingHistory::create([
                'shipment_id' => $shipment->id,
                'status' => 'pending',
                'description' => "Booked with {$driver->label()} (consignment {$result->consignmentId})",
                'event_time' => now(),
            ]);

            // Handing the order to a courier = confirmed + shipped stage (same as "Mark shipped")
            $locked = Sell::whereKey($sell->id)->lockForUpdate()->first();
            $oldStatus = $locked->status;
            $update = ['fulfillment_status' => 'shipped', 'shipped_at' => $locked->shipped_at ?: now()];
            if (!$locked->carrier) {
                $update['carrier'] = $driver->label();
            }
            if ($oldStatus === 'Pending') {
                $update['status'] = 'Processing';
            }
            $locked->update($update);
            if ($oldStatus === 'Pending') {
                DB::afterCommit(fn () => $this->notifications->notifyOrderStatusChanged($companyId, (string) $locked->invoice_no, 'Pending', 'Processing'));
            }

            return $shipment;
        });
    }

    private function parseTime(?string $v): ?\Carbon\Carbon
    {
        if (!$v) {
            return null;
        }
        try {
            $t = \Carbon\Carbon::parse($v);
            return $t->isFuture() ? now() : $t;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
