<?php

namespace App\Services\Courier;

/**
 * Courier raw status → our normalized courier state, and courier state → existing shipment status.
 * Raw strings are matched case-insensitively; unknown strings return null (= keep the previous state).
 */
final class CourierStatusMapper
{
    public const BOOKED = 'booked';
    public const PICKUP_PENDING = 'pickup_pending';
    public const PICKED = 'picked';
    public const IN_TRANSIT = 'in_transit';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const PARTIAL_DELIVERED = 'partial_delivered';
    public const RETURNED = 'returned';
    public const CANCELLED = 'cancelled';
    public const ON_HOLD = 'on_hold';
    public const FAILED = 'failed';

    public const STATES = [self::BOOKED, self::PICKUP_PENDING, self::PICKED, self::IN_TRANSIT, self::OUT_FOR_DELIVERY,
        self::DELIVERED, self::PARTIAL_DELIVERED, self::RETURNED, self::CANCELLED, self::ON_HOLD, self::FAILED];

    public const FINAL = [self::DELIVERED, self::PARTIAL_DELIVERED, self::RETURNED, self::CANCELLED];

    /** Progress rank: a state never moves to a lower rank. on_hold / failed sit beside the progress line. */
    private const RANK = [
        self::BOOKED => 0, self::PICKUP_PENDING => 1, self::PICKED => 2, self::IN_TRANSIT => 3, self::ON_HOLD => 3,
        self::OUT_FOR_DELIVERY => 4, self::FAILED => 4,
        self::DELIVERED => 9, self::PARTIAL_DELIVERED => 9, self::RETURNED => 9, self::CANCELLED => 9,
    ];

    /** Steadfast documented statuses. *_approval_pending = rider reported, Steadfast still has to approve: not final yet. */
    private const STEADFAST = [
        'in_review' => self::BOOKED,
        'pending' => self::IN_TRANSIT,
        'hold' => self::ON_HOLD,
        'unknown' => null,
        'delivered_approval_pending' => self::OUT_FOR_DELIVERY,
        'partial_delivered_approval_pending' => self::OUT_FOR_DELIVERY,
        'cancelled_approval_pending' => self::ON_HOLD,
        'unknown_approval_pending' => self::ON_HOLD,
        'delivered' => self::DELIVERED,
        'partial_delivered' => self::PARTIAL_DELIVERED,
        'cancelled' => self::CANCELLED,   // turned into RETURNED once the parcel was picked (see normalize)
    ];

    /** Pathao: UNCONFIRMED list (from package/community docs) — exact match first, then keywords. */
    private const PATHAO = [
        'pending' => self::BOOKED,
        'order_created' => self::BOOKED,
        'pickup_requested' => self::PICKUP_PENDING,
        'assigned_for_pickup' => self::PICKUP_PENDING,
        'pickup_failed' => self::ON_HOLD,
        'pickup_cancelled' => self::CANCELLED,
        'pickup_cancel' => self::CANCELLED,
        'picked' => self::PICKED,
        'at_the_sorting_hub' => self::IN_TRANSIT,
        'in_transit' => self::IN_TRANSIT,
        'received_at_last_mile_hub' => self::IN_TRANSIT,
        'assigned_for_delivery' => self::OUT_FOR_DELIVERY,
        'out_for_delivery' => self::OUT_FOR_DELIVERY,
        'delivered' => self::DELIVERED,
        'partial_delivery' => self::PARTIAL_DELIVERED,
        'partial_delivered' => self::PARTIAL_DELIVERED,
        'return' => self::RETURNED,
        'returned' => self::RETURNED,
        'paid_return' => self::RETURNED,
        'delivery_failed' => self::FAILED,
        'on_hold' => self::ON_HOLD,
        'hold' => self::ON_HOLD,
        'cancelled' => self::CANCELLED,
        'canceled' => self::CANCELLED,
        'payment_invoice' => null,   // money settlement after delivery: no parcel movement
        'exchange' => null,
    ];

    public static function slug(?string $raw): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $raw)), '_');
    }

    /**
     * Normalized state for a courier's raw status, or null when unknown / not a parcel movement.
     * $current lets Steadfast "cancelled" after pickup become "returned" (the parcel goes back to the merchant).
     */
    public static function normalize(string $provider, ?string $raw, ?string $current = null): ?string
    {
        $slug = self::slug($raw);
        if ($slug === '') {
            return null;
        }
        if ($provider === 'steadfast') {
            if (!array_key_exists($slug, self::STEADFAST)) {
                return self::byKeyword($slug);
            }
            $state = self::STEADFAST[$slug];
            if ($state === self::CANCELLED && $current !== null && (self::RANK[$current] ?? 0) >= self::RANK[self::PICKED] && !in_array($current, self::FINAL, true)) {
                return self::RETURNED;
            }
            return $state;
        }
        if ($provider === 'pathao' && array_key_exists($slug, self::PATHAO)) {
            return self::PATHAO[$slug];
        }
        return self::byKeyword($slug);
    }

    private static function byKeyword(string $s): ?string
    {
        return match (true) {
            str_contains($s, 'partial') => self::PARTIAL_DELIVERED,
            str_contains($s, 'fail') && str_contains($s, 'pick') => self::ON_HOLD,
            str_contains($s, 'fail') => self::FAILED,
            str_contains($s, 'return') => self::RETURNED,
            str_contains($s, 'cancel') => self::CANCELLED,
            str_contains($s, 'hold') => self::ON_HOLD,
            str_contains($s, 'approval') => self::ON_HOLD,
            str_contains($s, 'assigned_for_delivery'), str_contains($s, 'out_for_delivery') => self::OUT_FOR_DELIVERY,
            str_contains($s, 'deliver') => self::DELIVERED,
            str_contains($s, 'pickup') || str_contains($s, 'assigned_for_pick') => self::PICKUP_PENDING,
            str_contains($s, 'picked') => self::PICKED,
            str_contains($s, 'transit'), str_contains($s, 'hub'), str_contains($s, 'sort') => self::IN_TRANSIT,
            str_contains($s, 'review'), str_contains($s, 'pending'), str_contains($s, 'created') => self::BOOKED,
            default => null,
        };
    }

    public static function isFinal(?string $state): bool
    {
        return in_array($state, self::FINAL, true);
    }

    /** May the shipment move from $from to $to? Final states are terminal; never backwards on the progress line. */
    public static function canMove(?string $from, string $to): bool
    {
        if ($from === null) {
            return true;
        }
        if ($from === $to || self::isFinal($from)) {
            return false;
        }
        if (self::isFinal($to)) {
            return true;
        }
        if (in_array($to, [self::ON_HOLD, self::FAILED], true)) {
            return true;   // side states can happen at any point in progress
        }
        return (self::RANK[$to] ?? 0) >= (self::RANK[$from] ?? 0);
    }

    /** Existing shipment.status for a courier state. null = keep the current shipment status. */
    public static function shipmentStatus(string $state): ?string
    {
        return match ($state) {
            self::BOOKED, self::PICKUP_PENDING => 'pending',
            self::PICKED => 'picked_up',
            self::IN_TRANSIT, self::ON_HOLD => 'in_transit',
            self::OUT_FOR_DELIVERY => 'out_for_delivery',
            self::DELIVERED, self::PARTIAL_DELIVERED => 'delivered',
            self::RETURNED => 'returned',
            self::CANCELLED => 'cancelled',
            default => null,   // failed: keep the shipment live (usually a re-attempt or a return follows)
        };
    }

    /** Existing shipment status order, for "never backwards" on shipment.status. */
    public static function shipmentRank(?string $status): int
    {
        return match ($status) {
            'pending' => 0, 'picked_up' => 1, 'in_transit' => 2, 'out_for_delivery' => 3,
            'delivered', 'returned', 'cancelled', 'failed' => 9,
            default => 0,
        };
    }
}
