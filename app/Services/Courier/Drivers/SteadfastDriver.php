<?php

namespace App\Services\Courier\Drivers;

use App\Models\OrderShipment;
use App\Services\Courier\CourierException;
use App\Services\Courier\ParcelData;
use App\Services\Courier\ParcelResult;
use App\Services\Courier\StatusResult;
use Illuminate\Http\Client\Response;

/**
 * Steadfast (portal.packzy.com). Auth = Api-Key + Secret-Key headers, no token, no sandbox.
 * No cancel / price API. Some errors arrive as HTTP 200 with {"status": 4xx} in the body.
 */
class SteadfastDriver extends AbstractCourierDriver
{
    public const PATH_CREATE = '/create_order';
    public const PATH_BULK = '/create_order/bulk-order';
    public const PATH_STATUS_BY_CID = '/status_by_cid/%s';
    public const PATH_STATUS_BY_INVOICE = '/status_by_invoice/%s';
    public const PATH_STATUS_BY_TRACKING = '/status_by_trackingcode/%s';
    public const PATH_TRACKINGS = '/trackings_by_invoice/%s';   // UNCONFIRMED
    public const PATH_BALANCE = '/get_balance';
    /** UNCONFIRMED: bulk body is a bare JSON array (package source); null = bare array, else wrap under this key. */
    public const BULK_WRAP_KEY = null;

    public function provider(): string
    {
        return 'steadfast';
    }

    public function label(): string
    {
        return 'Steadfast';
    }

    protected function baseUrl(): string
    {
        return (string) config('couriers.steadfast.base_url');
    }

    protected function headers(): array
    {
        $key = $this->account->cred('apiKey');
        $secret = $this->account->cred('secretKey');
        if (!$key || !$secret) {
            throw new CourierException('Steadfast: add the API key and secret key in Shipping methods → Courier integrations', 422, 'steadfast');
        }
        return ['Api-Key' => $key, 'Secret-Key' => $secret];
    }

    /** Steadfast may answer HTTP 200 with a 4xx "status" in the body. */
    private function call(string $method, string $path, array $data = []): array
    {
        $json = $this->send($method, $path, $data);
        $code = isset($json['status']) && is_numeric($json['status']) ? (int) $json['status'] : 200;
        if ($code >= 400) {
            $msg = self::extractMessage($json) ?? "request failed ({$code})";
            throw new CourierException("Steadfast: {$msg}", 422, 'steadfast');
        }
        return $json;
    }

    public function testConnection(): array
    {
        $json = $this->call('GET', self::PATH_BALANCE);
        $balance = isset($json['current_balance']) && is_numeric($json['current_balance']) ? (float) $json['current_balance'] : null;

        return ['ok' => true, 'message' => 'Connected to Steadfast', 'balance' => $balance];
    }

    private function payload(ParcelData $p): array
    {
        return array_filter([
            'invoice' => mb_substr($p->invoice, 0, 100),
            'recipient_name' => self::trim($p->recipientName, 100),
            'recipient_phone' => $p->recipientPhone,
            'recipient_address' => self::trim($p->recipientAddress, 250),
            'cod_amount' => round(max(0, $p->codAmount), 2),
            'recipient_email' => $p->recipientEmail,
            'item_description' => self::trim($p->itemDescription, 250),
            'note' => self::trim($p->note, 250),
        ], fn ($v) => $v !== null);
    }

    public function createParcel(ParcelData $parcel): ParcelResult
    {
        $json = $this->call('POST', self::PATH_CREATE, $this->payload($parcel));
        $c = $json['consignment'] ?? null;
        if (!is_array($c) || empty($c['consignment_id'])) {
            throw new CourierException('Steadfast: ' . (self::extractMessage($json) ?? 'no consignment returned'), 422, 'steadfast');
        }
        return $this->resultFrom($c);
    }

    private function resultFrom(array $c): ParcelResult
    {
        return new ParcelResult(
            consignmentId: (string) $c['consignment_id'],
            trackingCode: (string) ($c['tracking_code'] ?? $c['consignment_id']),
            rawStatus: isset($c['status']) && $c['status'] !== 'success' ? (string) $c['status'] : 'in_review',
            deliveryFee: null,
            raw: self::slim($c),
        );
    }

    /** Bulk endpoint (max 500 per call); each item can succeed or fail on its own. */
    public function bulkCreate(array $parcels): array
    {
        $out = [];
        $byInvoice = [];
        foreach ($parcels as $p) {
            $byInvoice[mb_substr($p->invoice, 0, 100)] = $p;
        }
        foreach (array_chunk($parcels, (int) config('couriers.steadfast.bulk_max', 500)) as $chunk) {
            $items = array_map(fn (ParcelData $p) => $this->payload($p), $chunk);
            $body = self::BULK_WRAP_KEY ? [self::BULK_WRAP_KEY => $items] : $items;
            try {
                $json = $this->call('POST', self::PATH_BULK, $body);
            } catch (CourierException $e) {
                if ($e->getStatusCode() === 422) {
                    // Whole request rejected (shape/validation): fall back to one-by-one. Invoices are unique at
                    // Steadfast, so a parcel that did get created cannot be booked twice.
                    foreach ($chunk as $p) {
                        try {
                            $out[$p->ref] = $this->createParcel($p);
                        } catch (CourierException $one) {
                            $out[$p->ref] = $one;
                        }
                    }
                    continue;
                }
                foreach ($chunk as $p) {
                    $out[$p->ref] = $e;
                }
                continue;
            }
            $rows = $json['data'] ?? $json;
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) || !isset($row['invoice'], $byInvoice[(string) $row['invoice']])) {
                    continue;
                }
                $p = $byInvoice[(string) $row['invoice']];
                $ok = ($row['status'] ?? 'success') !== 'error' && !empty($row['consignment_id']);
                $out[$p->ref] = $ok
                    ? $this->resultFrom($row)
                    : new CourierException('Steadfast: ' . (self::extractMessage($row) ?? 'order rejected'), 422, 'steadfast');
            }
            foreach ($chunk as $p) {
                $out[$p->ref] ??= new CourierException('Steadfast: no result returned for this order', 422, 'steadfast');
            }
        }
        return $out;
    }

    public function fetchStatus(OrderShipment $shipment): StatusResult
    {
        $path = $shipment->consignment_id
            ? sprintf(self::PATH_STATUS_BY_CID, rawurlencode((string) $shipment->consignment_id))
            : sprintf(self::PATH_STATUS_BY_TRACKING, rawurlencode((string) $shipment->tracking_number));
        $json = $this->call('GET', $path);
        $status = $json['delivery_status'] ?? null;

        return new StatusResult(rawStatus: $status !== null ? (string) $status : null, raw: self::slim($json));
    }

    public function trackingEvents(OrderShipment $shipment): ?array
    {
        $invoice = $shipment->courier_payload['invoice'] ?? null;
        if (!$invoice) {
            return null;
        }
        $json = $this->call('GET', sprintf(self::PATH_TRACKINGS, rawurlencode((string) $invoice)));
        $rows = $json['tracking'] ?? $json['trackings'] ?? $json['data'] ?? [];
        $events = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            if (!is_array($r)) {
                continue;
            }
            $text = $r['text'] ?? $r['message'] ?? $r['details'] ?? $r['tracking_message'] ?? null;
            if ($text === null) {
                continue;
            }
            $events[] = [
                'status' => isset($r['status']) ? (string) $r['status'] : null,
                'message' => (string) $text,
                'time' => $r['created_at'] ?? $r['time'] ?? $r['date'] ?? $r['updated_at'] ?? null,
            ];
        }
        usort($events, fn ($a, $b) => strcmp((string) $b['time'], (string) $a['time']));
        return $events;
    }

    public function trackingUrl(string $trackingCode, ?string $phone = null): ?string
    {
        $tpl = (string) config('couriers.steadfast.tracking_url');
        return $tpl === '' ? null : str_replace('{code}', rawurlencode($trackingCode), $tpl);
    }
}
