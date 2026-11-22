<?php

namespace App\DTOs\Shipping;

use App\Models\OrderShipment;
use App\Services\Courier\CourierStatusMapper;
use Carbon\CarbonInterface;

/** The `courierBooking` block of a shipment (null for manual shipments). */
final class CourierBookingMapper
{
    public static function toArray(OrderShipment $s): ?array
    {
        if ($s->courier_account_id === null && empty($s->consignment_id)) {
            return null;
        }
        $provider = (string) $s->courier_provider;
        $state = $s->courier_state;
        $final = CourierStatusMapper::isFinal($state) || in_array($s->status, OrderShipment::DEAD_STATUSES, true);

        return [
            'provider' => $provider ?: null,
            'accountId' => $s->courier_account_id !== null ? (int) $s->courier_account_id : null,
            'consignmentId' => $s->consignment_id,
            'trackingCode' => $s->tracking_number,
            'status' => $state,
            'rawStatus' => $s->courier_status,
            'statusAt' => self::ts($s->courier_status_at),
            'bookedAt' => self::ts($s->booked_at),
            'trackingUrl' => $s->tracking_url,
            'deliveryFee' => $s->delivery_fee !== null ? (float) $s->delivery_fee : null,
            'codAmount' => (float) $s->cod_amount,
            'codCollected' => $s->courier_cod_collected !== null ? (float) $s->courier_cod_collected : null,
            'isFinal' => $final,
            'canCancel' => !$final && $provider === 'pathao' && in_array($state, [CourierStatusMapper::BOOKED, CourierStatusMapper::PICKUP_PENDING, null], true),
            'canSync' => !$final && !empty($s->consignment_id),
            'lastSyncedAt' => self::ts($s->last_synced_at),
            'syncError' => $s->sync_error,
            'pathao' => $provider === 'pathao' ? [
                'storeId' => $s->courier_store_id !== null ? (int) $s->courier_store_id : null,
                'cityId' => $s->pathao_city_id !== null ? (int) $s->pathao_city_id : null,
                'cityName' => $s->pathao_city_name,
                'zoneId' => $s->pathao_zone_id !== null ? (int) $s->pathao_zone_id : null,
                'zoneName' => $s->pathao_zone_name,
                'areaId' => $s->pathao_area_id !== null ? (int) $s->pathao_area_id : null,
                'areaName' => $s->pathao_area_name,
            ] : null,
        ];
    }

    private static function ts(mixed $v): ?string
    {
        return $v instanceof CarbonInterface ? $v->toIso8601String() : ($v ? (string) $v : null);
    }
}
