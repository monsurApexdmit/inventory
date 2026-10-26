<?php

namespace App\Services\Tailor;

use App\Models\TailorCustomer;
use App\Models\TailorMeasurement;

/**
 * Measurements screen: customers with their measurement sets, one set per garment
 * (unit, fit preference, note, free-form values, version history).
 */
class TailorMeasurementSetService
{
    /** Keys that also live in dedicated legacy columns (kept in sync for older screens). */
    public const LEGACY = ['chest', 'waist', 'hip', 'shoulder', 'sleeve', 'length', 'neck',
        'bottom_length', 'inseam', 'pajama_waist', 'pajama_length'];

    /** Effective values: JSON `values` if present, else built from legacy columns. */
    public function valuesOf(TailorMeasurement $m): array
    {
        if (is_array($m->values) && $m->values) return $m->values;
        $out = [];
        foreach (self::LEGACY as $k) if ($m->{$k} !== null) $out[$k] = (float) $m->{$k};
        return $out;
    }

    public function format(TailorMeasurement $m): array
    {
        $arr = $m->toArray();
        $arr['values'] = (object) $this->valuesOf($m);
        $arr['history'] = $m->history ?? [];
        $arr['label'] = $m->label ?? '';
        $arr['unit'] = $m->unit ?: 'in';
        $arr['fit_preference'] = $m->fit_preference ?: 'Regular';
        $arr['custom_fields'] = (object) ($m->custom_fields ?? []);
        $arr['measured_at'] = $m->measured_at?->toDateString();
        return $arr;
    }

    public function customers(int $companyId, array $f = []): array
    {
        $q = TailorCustomer::where('company_id', $companyId);
        if (!empty($f['search'])) {
            $s = '%' . $f['search'] . '%';
            $q->where(fn($w) => $w->where('name', 'like', $s)->orWhere('phone', 'like', $s));
        }
        $limit = min(max((int) ($f['limit'] ?? 10), 1), 100);
        $page = max(1, (int) ($f['page'] ?? 1));
        $res = $q->with('measurements:id,customer_id,product_type,measured_at')->orderBy('name')->paginate($limit, ['*'], 'page', $page);
        $rows = $res->getCollection()->map(fn($c) => [
            'id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'address' => $c->address,
            'garments' => $c->measurements->pluck('product_type')->unique()->values()->all(),
            'sets_count' => $c->measurements->count(),
            'last_measured_at' => optional($c->measurements->max('measured_at'))->toDateString(),
        ])->values()->all();
        return ['rows' => $rows, 'pagination' => ['page' => $res->currentPage(), 'per_page' => $res->perPage(),
            'total' => $res->total(), 'last_page' => $res->lastPage()]];
    }

    public function setsForCustomer(int $companyId, int $customerId): array
    {
        $sets = TailorMeasurement::where('company_id', $companyId)->where('customer_id', $customerId)->orderBy('id')->get();
        $used = \Illuminate\Support\Facades\DB::table('tailor_order_items')
            ->join('tailor_orders', 'tailor_orders.id', '=', 'tailor_order_items.order_id')
            ->whereNull('tailor_orders.deleted_at')->whereIn('tailor_order_items.measurement_id', $sets->pluck('id'))
            ->groupBy('tailor_order_items.measurement_id')
            ->selectRaw('tailor_order_items.measurement_id as k, COUNT(DISTINCT tailor_order_items.order_id) as n')->pluck('n', 'k');
        return $sets->map(fn($m) => $this->format($m) + ['used_by_orders' => (int) ($used[$m->id] ?? 0)])->all();
    }

    private function clean(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            if ($v === null || $v === '') continue;
            $out[(string) $k] = (float) $v;
        }
        return $out;
    }

    private function fill(array $d, array $values): array
    {
        foreach (self::LEGACY as $k) $d[$k] = $values[$k] ?? null;
        $d['values'] = $values ?: null;
        return $d;
    }

    public function create(int $companyId, array $d): TailorMeasurement
    {
        TailorCustomer::where('company_id', $companyId)->findOrFail($d['customer_id']);
        $values = $this->clean($d['values'] ?? array_intersect_key($d, array_flip(self::LEGACY)));
        unset($d['values']);
        $d = $this->fill($d, $values);
        $d['company_id'] = $companyId;
        $d['history'] = [];
        return TailorMeasurement::create($d);
    }

    public function update(int $companyId, int $id, array $d): TailorMeasurement
    {
        $m = TailorMeasurement::where('company_id', $companyId)->findOrFail($id);
        $old = $this->valuesOf($m);
        if (array_key_exists('values', $d)) {
            $values = $this->clean($d['values'] ?? []);
        } else {
            $values = $this->clean(array_merge($old, array_intersect_key($d, array_flip(self::LEGACY))));
        }
        unset($d['values']);
        $d = $this->fill($d, $values);
        $history = $m->history ?? [];
        if ($old != $values && $old) {
            $history[] = ['at' => now()->toIso8601String(), 'values' => $old];
        }
        $d['history'] = $history;
        $m->update($d);
        return $m->fresh();
    }

    public function delete(int $companyId, int $id): void
    {
        TailorMeasurement::where('company_id', $companyId)->findOrFail($id)->delete();
    }
}
