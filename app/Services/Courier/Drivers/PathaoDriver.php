<?php

namespace App\Services\Courier\Drivers;

use App\Models\OrderShipment;
use App\Services\Courier\CourierException;
use App\Services\Courier\ParcelData;
use App\Services\Courier\ParcelResult;
use App\Services\Courier\StatusResult;
use Illuminate\Support\Facades\Cache;

/**
 * Pathao Courier (merchant API "aladdin"). OAuth password grant → bearer token, cached encrypted on the
 * courier account row (per account, never global). A 401 re-issues the token once and retries.
 */
class PathaoDriver extends AbstractCourierDriver
{
    public const PATH_TOKEN = 'aladdin/api/v1/issue-token';
    public const PATH_CITIES = 'aladdin/api/v1/countries/1/city-list';
    public const PATH_ZONES = 'aladdin/api/v1/cities/%d/zone-list';
    public const PATH_AREAS = 'aladdin/api/v1/zones/%d/area-list';
    public const PATH_STORES = 'aladdin/api/v1/stores';
    public const PATH_ORDERS = 'aladdin/api/v1/orders';
    /** UNCONFIRMED: order details path; tried in order, a 404 moves to the next. */
    public const PATHS_ORDER_INFO = ['aladdin/api/v1/orders/%s/info', 'aladdin/api/v1/orders/%s'];
    public const PATH_CANCEL = 'aladdin/api/v1/orders/%s/cancel';            // UNCONFIRMED
    public const PATH_PRICE = 'aladdin/api/v1/merchant/price-plan';
    public const PATH_SUCCESS_RATE = 'aladdin/api/v1/user/success';          // UNCONFIRMED response shape
    public const MIN_WEIGHT = 0.5;
    public const MAX_WEIGHT = 10.0;

    private ?string $token = null;

    public function provider(): string
    {
        return 'pathao';
    }

    public function label(): string
    {
        return 'Pathao';
    }

    private function env(): string
    {
        return $this->account->environment === 'sandbox' ? 'sandbox' : 'live';
    }

    protected function baseUrl(): string
    {
        return (string) config('couriers.pathao.base_url.' . $this->env());
    }

    protected function headers(): array
    {
        return $this->token ? ['Authorization' => 'Bearer ' . $this->token] : [];
    }

    // ── Token ────────────────────────────────────────────────────────────────

    private function ensureToken(bool $force = false): void
    {
        if (!$force) {
            if ($this->token) {
                return;
            }
            $cached = $this->account->token_cache;
            $exp = $this->account->token_expires_at;
            if ($cached && $exp && $exp->isFuture()) {
                $this->token = $cached;
                return;
            }
        }
        $this->token = null;
        $c = $this->account->creds();
        foreach (['clientId' => 'client id', 'clientSecret' => 'client secret', 'username' => 'merchant email', 'password' => 'password'] as $k => $label) {
            if (empty($c[$k])) {
                throw new CourierException("Pathao: add the {$label} in Shipping methods → Courier integrations", 422, 'pathao');
            }
        }
        $res = $this->raw('POST', self::PATH_TOKEN, [
            'client_id' => (string) $c['clientId'],
            'client_secret' => (string) $c['clientSecret'],
            'username' => (string) $c['username'],
            'password' => (string) $c['password'],
            'grant_type' => 'password',
        ]);
        if ($res->failed() || empty($res->json('access_token'))) {
            $msg = self::extractMessage($res->json()) ?? ($res->serverError() ? 'the courier server returned an error' : 'the credentials were rejected');
            throw new CourierException("Pathao: could not sign in — {$msg}", $res->serverError() ? 502 : 422, 'pathao');
        }
        $this->token = (string) $res->json('access_token');
        $ttl = max(60, (int) ($res->json('expires_in') ?: 3600) - 300);
        $this->account->forceFill(['token_cache' => $this->token, 'token_expires_at' => now()->addSeconds($ttl)])->saveQuietly();
    }

    /** Authenticated call; on 401 re-issue the token once and retry. Returns the decoded body. */
    private function api(string $method, string $path, array $data = []): array
    {
        $res = $this->apiResponse($method, $path, $data);
        if ($res->failed()) {
            throw $this->errorFrom($res, $path);
        }
        $json = $res->json();
        return is_array($json) ? $json : [];
    }

    private function apiResponse(string $method, string $path, array $data = []): \Illuminate\Http\Client\Response
    {
        $this->ensureToken();
        $res = $this->raw($method, $path, $data);
        if ($res->status() === 401) {
            $this->ensureToken(true);
            $res = $this->raw($method, $path, $data);
        }
        return $res;
    }

    // ── Operations ───────────────────────────────────────────────────────────

    public function testConnection(): array
    {
        $this->ensureToken(true);
        $stores = $this->stores();

        return [
            'ok' => true,
            'message' => $stores ? 'Connected to Pathao' : 'Connected to Pathao, but no pickup store was found — add one in the Pathao merchant panel',
            'balance' => null,
            'stores' => $stores,
        ];
    }

    public function stores(): array
    {
        $out = [];
        for ($page = 1; $page <= 5; $page++) {
            $json = $this->api('GET', self::PATH_STORES, ['page' => $page]);
            $data = $json['data'] ?? [];
            $rows = $data['data'] ?? (array_is_list($data) ? $data : []);
            foreach ($rows as $s) {
                if (!is_array($s) || !isset($s['store_id'])) {
                    continue;
                }
                $out[] = [
                    'id' => (int) $s['store_id'],
                    'name' => (string) ($s['store_name'] ?? ('Store ' . $s['store_id'])),
                    'address' => $s['store_address'] ?? null,
                    'cityId' => isset($s['city_id']) ? (int) $s['city_id'] : null,
                    'zoneId' => isset($s['zone_id']) ? (int) $s['zone_id'] : null,
                    'isActive' => (bool) ($s['is_active'] ?? true),
                    'isDefault' => (bool) ($s['is_default_store'] ?? false),
                ];
            }
            $last = (int) ($data['last_page'] ?? 1);
            if ($page >= $last || !$rows) {
                break;
            }
        }
        return $out;
    }

    public function locations(string $type, ?int $parentId): array
    {
        [$path, $idKey, $nameKey] = match ($type) {
            'city' => [self::PATH_CITIES, 'city_id', 'city_name'],
            'zone' => [sprintf(self::PATH_ZONES, (int) $parentId), 'zone_id', 'zone_name'],
            'area' => [sprintf(self::PATH_AREAS, (int) $parentId), 'area_id', 'area_name'],
            default => throw new CourierException('Unknown location type', 422, 'pathao'),
        };
        if ($type !== 'city' && !$parentId) {
            throw new CourierException($type === 'zone' ? 'Pick a city first' : 'Pick a zone first', 422, 'pathao');
        }
        // Location lists are the same for every merchant: cache per environment, not per account
        $key = "courier:pathao:{$this->env()}:{$type}:" . (int) $parentId;
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        $json = $this->api('GET', $path);
        $data = $json['data'] ?? [];
        $rows = $data['data'] ?? (array_is_list($data) ? $data : []);
        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r[$idKey])) {
                continue;
            }
            $item = ['id' => (int) $r[$idKey], 'name' => (string) ($r[$nameKey] ?? $r[$idKey])];
            if ($type === 'area') {
                $item['homeDeliveryAvailable'] = isset($r['home_delivery_available']) ? (bool) $r['home_delivery_available'] : null;
                $item['pickupAvailable'] = isset($r['pickup_available']) ? (bool) $r['pickup_available'] : null;
            }
            $out[] = $item;
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        if ($out) {
            Cache::put($key, $out, now()->addHours((int) config('couriers.pathao.locations_cache_hours', 24)));
        }
        return $out;
    }

    public function createParcel(ParcelData $p): ParcelResult
    {
        $storeId = $p->storeId ?: $this->account->cred('storeId');
        if (!$storeId) {
            throw new CourierException('Pathao: pick a pickup store for this account first (Shipping methods → Courier integrations)', 422, 'pathao');
        }
        $weight = min(self::MAX_WEIGHT, max(self::MIN_WEIGHT, round($p->weightKg, 2)));
        $body = array_filter([
            'store_id' => (int) $storeId,
            'merchant_order_id' => mb_substr($p->invoice, 0, 100),
            'recipient_name' => self::trim($p->recipientName, 100),
            'recipient_phone' => $p->recipientPhone,
            'recipient_address' => self::trim($p->recipientAddress, 220),
            'recipient_city' => $p->cityId,
            'recipient_zone' => $p->zoneId,
            'recipient_area' => $p->areaId,
            'delivery_type' => in_array($p->deliveryType, [12, 48], true) ? $p->deliveryType : 48,
            'item_type' => in_array($p->itemType, [1, 2], true) ? $p->itemType : 2,
            'special_instruction' => self::trim($p->note, 250),
            'item_quantity' => max(1, $p->itemQuantity),
            'item_weight' => (string) $weight,
            'amount_to_collect' => (int) round(max(0, $p->codAmount)),
            'item_description' => self::trim($p->itemDescription, 250),
        ], fn ($v) => $v !== null);

        $json = $this->api('POST', self::PATH_ORDERS, $body);
        $d = $json['data'] ?? null;
        if (!is_array($d) || empty($d['consignment_id'])) {
            throw new CourierException('Pathao: ' . (self::extractMessage($json) ?? 'no consignment returned'), 422, 'pathao');
        }
        return new ParcelResult(
            consignmentId: (string) $d['consignment_id'],
            trackingCode: (string) $d['consignment_id'],
            rawStatus: isset($d['order_status']) ? (string) $d['order_status'] : 'Pending',
            deliveryFee: isset($d['delivery_fee']) && is_numeric($d['delivery_fee']) ? (float) $d['delivery_fee'] : null,
            raw: self::slim($d + ['store_id' => (int) $storeId]),
        );
    }

    public function fetchStatus(OrderShipment $shipment): StatusResult
    {
        $id = rawurlencode((string) ($shipment->consignment_id ?: $shipment->tracking_number));
        // Remember which details path answered last time so each sync costs one call
        $paths = self::PATHS_ORDER_INFO;
        $known = Cache::get('courier:pathao:order_info_path');
        if (is_string($known) && in_array($known, $paths, true)) {
            $paths = array_values(array_unique(array_merge([$known], $paths)));
        }
        foreach ($paths as $tpl) {
            $path = sprintf($tpl, $id);
            $res = $this->apiResponse('GET', $path);
            if ($res->status() === 404) {
                continue;
            }
            if ($res->successful() && $known !== $tpl) {
                Cache::put('courier:pathao:order_info_path', $tpl, now()->addDay());
            }
            if ($res->failed()) {
                throw $this->errorFrom($res, $path);
            }
            $json = is_array($res->json()) ? $res->json() : [];
            $d = $json['data'] ?? $json;
            $raw = $d['order_status'] ?? $d['order_status_slug'] ?? $d['status'] ?? null;
            $cod = $d['collected_amount'] ?? $d['cash_collected'] ?? null;      // UNCONFIRMED field names
            $fee = $d['delivery_fee'] ?? null;

            return new StatusResult(
                rawStatus: $raw !== null ? (string) $raw : null,
                codCollected: is_numeric($cod) ? (float) $cod : null,
                deliveryFee: is_numeric($fee) ? (float) $fee : null,
                statusAt: isset($d['updated_at']) ? (string) $d['updated_at'] : null,
                raw: self::slim(is_array($d) ? $d : []),
            );
        }
        throw new CourierException('Pathao: consignment not found', 422, 'pathao');
    }

    public function supportsCancel(): bool
    {
        return true;
    }

    public function cancel(OrderShipment $shipment): void
    {
        $this->api('POST', sprintf(self::PATH_CANCEL, rawurlencode((string) $shipment->consignment_id)));
    }

    public function quote(array $params): array
    {
        $storeId = $params['storeId'] ?? $this->account->cred('storeId');
        if (!$storeId || empty($params['cityId']) || empty($params['zoneId'])) {
            return ['available' => false, 'deliveryFee' => null, 'codFeePercent' => null,
                'message' => !$storeId ? 'Pick a pickup store first' : 'Pick the city and zone to see the delivery charge'];
        }
        $json = $this->api('POST', self::PATH_PRICE, [
            'store_id' => (int) $storeId,
            'item_type' => (int) ($params['itemType'] ?? 2),
            'delivery_type' => (int) ($params['deliveryType'] ?? 48),
            'item_weight' => (string) min(self::MAX_WEIGHT, max(self::MIN_WEIGHT, (float) ($params['weightKg'] ?? self::MIN_WEIGHT))),
            'recipient_city' => (int) $params['cityId'],
            'recipient_zone' => (int) $params['zoneId'],
        ]);
        $d = $json['data'] ?? [];
        $fee = $d['final_price'] ?? $d['price'] ?? null;

        return [
            'available' => is_numeric($fee),
            'deliveryFee' => is_numeric($fee) ? (float) $fee : null,
            // cod_percentage comes as a fraction (0.01 = 1%) — UNCONFIRMED, values >= 1 are taken as percent already
            'codFeePercent' => isset($d['cod_percentage']) && is_numeric($d['cod_percentage'])
                ? round((float) $d['cod_percentage'] < 1 ? (float) $d['cod_percentage'] * 100 : (float) $d['cod_percentage'], 2) : null,
            'message' => is_numeric($fee) ? null : 'Pathao did not return a price',
        ];
    }

    public function customerSuccessRate(string $phone): ?array
    {
        $json = $this->api('POST', self::PATH_SUCCESS_RATE, ['phone' => $phone]);
        $d = $json['data'] ?? [];
        $c = is_array($d['customer'] ?? null) ? $d['customer'] : $d;
        $total = $c['total_delivery'] ?? $c['total'] ?? null;
        $ok = $c['successful_delivery'] ?? $c['success'] ?? null;
        $total = is_numeric($total) ? (int) $total : null;
        $ok = is_numeric($ok) ? (int) $ok : null;

        return [
            'totalParcels' => $total,
            'delivered' => $ok,
            'returned' => $total !== null && $ok !== null ? max(0, $total - $ok) : null,
            'successRate' => $total ? round(($ok ?? 0) * 100 / $total, 1) : null,
            'rating' => isset($d['customer_rating']) ? (string) $d['customer_rating'] : null,
        ];
    }

    public function trackingUrl(string $trackingCode, ?string $phone = null): ?string
    {
        $tpl = (string) config('couriers.pathao.tracking_url');
        if ($tpl === '') {
            return null;
        }
        return str_replace(['{code}', '{phone}'], [rawurlencode($trackingCode), rawurlencode((string) $phone)], $tpl);
    }
}
