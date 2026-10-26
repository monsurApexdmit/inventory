<?php

namespace App\Services\Tailor;

use App\Models\TailorFabric;
use App\Models\TailorFabricMovement;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read model + restock for the Fabric inventory screen.
 */
class TailorFabricOverviewService
{
    private function base(int $companyId): Builder
    {
        return TailorFabric::query()->where('tailor_fabrics.company_id', $companyId);
    }

    /** Apply a status tab: all | ok | low | out | inactive. */
    private function applyTab(Builder $q, string $tab): Builder
    {
        return match ($tab) {
            'inactive' => $q->where('status', 'inactive'),
            'out'      => $q->where('status', 'active')->where('stock_quantity', '<=', 0),
            'low'      => $q->where('status', 'active')->where('stock_quantity', '>', 0)
                             ->whereColumn('stock_quantity', '<=', 'reorder_level'),
            'ok'       => $q->where('status', 'active')->where('stock_quantity', '>', 0)
                             ->whereColumn('stock_quantity', '>', 'reorder_level'),
            default    => $q,
        };
    }

    private function stockState(TailorFabric $f): string
    {
        if ($f->status === 'inactive') return 'inactive';
        if ($f->stock_quantity <= 0) return 'out';
        if ($f->stock_quantity <= $f->reorder_level) return 'low';
        return 'ok';
    }

    public function formatRow(TailorFabric $f, array $ordersCount = []): array
    {
        $sell = (float) $f->selling_price;
        $cost = (float) $f->purchase_price;
        $row = $f->toArray();
        unset($row['vendor']);
        return array_merge($row, [
            'vendor_name' => $f->vendor?->name,
            'stock_state' => $this->stockState($f),
            'stock_value' => round((float) $f->stock_quantity * $cost, 2),
            'margin_pct'  => $sell > 0 ? (int) round(($sell - $cost) / $sell * 100) : null,
            'orders_count' => (int) ($ordersCount[$f->id] ?? 0),
        ]);
    }

    public function overview(int $companyId, array $f = []): array
    {
        $perPage = max(1, min((int) ($f['per_page'] ?? 10), 100));
        $page    = max(1, (int) ($f['page'] ?? 1));
        $tab     = (string) ($f['tab'] ?? 'all');

        $filtered = $this->base($companyId);
        if (!empty($f['type']))      $filtered->where('fabric_type', $f['type']);
        if (!empty($f['vendor_id'])) $filtered->where('vendor_id', (int) $f['vendor_id']);
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $filtered->where(fn ($w) => $w->where('name', 'like', $s)->orWhere('fabric_type', 'like', $s)
                ->orWhere('color', 'like', $s)->orWhere('code', 'like', $s));
        }

        // Tab counts are global (not narrowed by search / type / supplier).
        $counts = [];
        foreach (['all', 'ok', 'low', 'out', 'inactive'] as $t) {
            $counts[$t] = $this->applyTab($this->base($companyId), $t)->count();
        }

        $listQ = $this->applyTab(clone $filtered, $tab)->with('vendor')->orderBy('name');
        $total = (clone $listQ)->count();
        $items = $listQ->forPage($page, $perPage)->get();

        $usage = DB::table('tailor_order_items')
            ->join('tailor_orders', 'tailor_orders.id', '=', 'tailor_order_items.order_id')
            ->where('tailor_orders.company_id', $companyId)
            ->whereIn('tailor_order_items.fabric_id', $items->pluck('id'))
            ->groupBy('tailor_order_items.fabric_id')
            ->select('tailor_order_items.fabric_id', DB::raw('COUNT(DISTINCT tailor_order_items.order_id) as n'))
            ->pluck('n', 'fabric_id')->all();

        return [
            'data'    => $items->map(fn ($x) => $this->formatRow($x, $usage))->all(),
            'total'   => $total,
            'page'    => $page,
            'per_page' => $perPage,
            'counts'  => $counts,
            'types'   => $this->base($companyId)->whereNotNull('fabric_type')->where('fabric_type', '!=', '')
                ->distinct()->orderBy('fabric_type')->pluck('fabric_type')->all(),
            'stats'   => $this->stats($companyId),
        ];
    }

    public function stats(int $companyId): array
    {
        $all    = $this->base($companyId)->get();
        $active = $all->where('status', 'active');
        $units  = $all->pluck('unit')->unique()->values();
        $low    = $all->filter(fn ($x) => in_array($this->stockState($x), ['low', 'out'], true));

        $from = now()->startOfMonth()->toDateString();
        $used = DB::table('tailor_order_items')
            ->join('tailor_orders', 'tailor_orders.id', '=', 'tailor_order_items.order_id')
            ->where('tailor_orders.company_id', $companyId)
            ->where('tailor_orders.order_status', '!=', 'cancelled')
            ->whereNotNull('tailor_order_items.fabric_id')
            ->whereDate('tailor_orders.order_date', '>=', $from)
            ->groupBy('tailor_order_items.fabric_id')
            ->select('tailor_order_items.fabric_id', DB::raw('SUM(tailor_order_items.fabric_quantity) as q'))
            ->pluck('q', 'fabric_id');
        $topId = $used->sortDesc()->keys()->first();

        return [
            'fabrics_count'  => $all->count(),
            'inactive_count' => $all->where('status', 'inactive')->count(),
            'types_count'    => $all->pluck('fabric_type')->filter()->unique()->count(),
            'stock_value'    => round($active->sum(fn ($x) => $x->stock_quantity * $x->purchase_price), 2),
            'total_stock'    => round((float) $active->sum('stock_quantity'), 2),
            'unit'           => $units->count() === 1 ? $units->first() : ($units->isEmpty() ? 'm' : null),
            'low_count'      => $low->count(),
            'low_names'      => $low->take(3)->pluck('name')->values()->all(),
            'used_this_month' => round((float) $used->sum(), 2),
            'top_fabric'     => $topId ? $all->firstWhere('id', $topId)?->name : null,
        ];
    }

    /** Add stock to a fabric and log it. */
    public function restock(int $companyId, int $id, float $qty, ?int $vendorId, ?string $note): array
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['quantity' => 'Enter metres greater than 0.']);
        }
        if ($vendorId && !Vendor::where('company_id', $companyId)->where('id', $vendorId)->exists()) {
            throw ValidationException::withMessages(['vendor_id' => 'Supplier not found.']);
        }

        return DB::transaction(function () use ($companyId, $id, $qty, $vendorId, $note) {
            $fabric = TailorFabric::where('company_id', $companyId)->lockForUpdate()->findOrFail($id);
            $fabric->skipMovementLog = true;
            $fabric->stock_quantity = round((float) $fabric->stock_quantity + $qty, 2);
            if ($vendorId) $fabric->vendor_id = $vendorId;
            $fabric->save();

            TailorFabricMovement::log($fabric, 'restock', $qty, $note ?: 'Restock', [
                'unit_cost' => (float) $fabric->purchase_price,
                'vendor_id' => $vendorId ?: $fabric->vendor_id,
            ]);

            return $this->formatRow($fabric->load('vendor'));
        });
    }

    /** Called when an order consumes fabric (stock is already decremented). */
    public function logOrderUse(int $fabricId, float $qty, int $orderId, ?string $orderNo): void
    {
        $fabric = TailorFabric::find($fabricId);
        if (!$fabric) return;
        TailorFabricMovement::log($fabric, 'order_use', -abs($qty), $orderNo ? "Order {$orderNo}" : 'Order', [
            'reference_type' => 'tailor_order',
            'reference_id'   => $orderId,
        ]);
    }

    public function movements(int $companyId, int $id, int $limit = 50): array
    {
        TailorFabric::where('company_id', $companyId)->findOrFail($id);
        return TailorFabricMovement::where('company_id', $companyId)->where('fabric_id', $id)
            ->orderByDesc('id')->limit(min($limit, 200))->get()->toArray();
    }
}
