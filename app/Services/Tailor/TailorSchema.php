<?php

namespace App\Services\Tailor;

use Illuminate\Support\Facades\Schema;

/**
 * Shared, schema-tolerant helpers for the tailor dashboard / reports services.
 * The tailor tables are evolving, so optional columns are detected once and cached for the request.
 */
trait TailorSchema
{
    /** @var array<string, bool> */
    private array $colCache = [];

    protected const STAGES = [
        'new' => 'Received', 'cutting' => 'Cutting', 'stitching' => 'Stitching',
        'trial' => 'Trial', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled',
    ];
    protected const OPEN_WORK = ['assigned', 'in_progress', 'returned', 'pending'];
    protected const DONE_WORK = ['completed', 'done', 'paid'];

    protected function hasCol(string $table, string $col): bool
    {
        return $this->colCache["$table.$col"] ??= Schema::hasColumn($table, $col);
    }

    /** First existing column among candidates, or null. */
    protected function pickCol(string $table, array $cands): ?string
    {
        foreach ($cands as $c) {
            if ($this->hasCol($table, $c)) {
                return $c;
            }
        }

        return null;
    }

    /** Collapse legacy statuses (pending, measurement_taken, assigned ...) to the board stages. */
    protected function stage(?string $s): string
    {
        $s = strtolower(trim((string) $s));

        return isset(self::STAGES[$s]) ? $s : 'new';
    }

    protected function payState(float $total, float $paid): string
    {
        return $total > 0 && $paid + 0.005 >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid');
    }

    protected function itemQtyExpr(): string
    {
        $c = $this->pickCol('tailor_order_items', ['quantity', 'qty']);

        return $c ? "GREATEST(COALESCE(tailor_order_items.$c, 1), 1)" : '1';
    }

    protected function garmentExpr(): string
    {
        $c = $this->pickCol('tailor_order_items', ['garment', 'product_type']) ?? 'product_type';

        return "tailor_order_items.$c";
    }

    protected function assignQtyExpr(): string
    {
        $c = $this->pickCol('tailor_assignments', ['quantity', 'qty', 'pieces']);

        return $c ? "GREATEST(COALESCE(tailor_assignments.$c, 1), 1)" : '1';
    }

    /** Reorder level column of tailor_fabrics, or a literal default threshold. */
    protected function reorderExpr(): string
    {
        $c = $this->pickCol('tailor_fabrics', ['reorder_level', 'reorder_meters', 'reorder_quantity', 'reorder_point', 'low_stock_level']);

        return $c ? "COALESCE(tailor_fabrics.$c, 0)" : '5';
    }

    /** Orders and their items rendered as "Shirt ×2, Pant ×1" keyed by order id. */
    protected function itemsText(array $orderIds): array
    {
        if (!$orderIds) {
            return [];
        }
        $rows = \Illuminate\Support\Facades\DB::table('tailor_order_items')->whereIn('order_id', $orderIds)
            ->selectRaw('order_id, ' . $this->garmentExpr() . ' as g, ' . $this->itemQtyExpr() . ' as q')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->order_id][] = ($r->g ?: 'Item') . ' ×' . (int) $r->q;
        }

        return array_map(fn($a) => implode(', ', $a), $out);
    }
}
