<?php

namespace App\Services\Unit;

use App\Models\Unit;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Company-scoped CRUD for units of measurement. Self-contained (no repository/
 * DTO layer) — units are a flat, simple lookup.
 */
class UnitService
{
    private function toArray(Unit $u): array
    {
        return [
            'id'        => $u->id,
            'unitName'  => $u->unit_name,
            'symbol'    => $u->symbol,
            'status'    => (bool) $u->status,
            'type'      => $u->type ?: 'Count',
            'conversionQty'    => $u->conversion_qty !== null ? (float) $u->conversion_qty : null,
            'conversionUnitId' => $u->conversion_unit_id,
            'conversion'       => $this->conversionString($u),
            'usedBy'    => isset($u->products_count) ? (int) $u->products_count : $u->products()->count(),
            'productsCount' => isset($u->products_count) ? (int) $u->products_count : null,
            'createdAt' => $u->created_at?->toIso8601String(),
            'updatedAt' => $u->updated_at?->toIso8601String(),
        ];
    }

    private function conversionString(Unit $u): ?string
    {
        if ($u->conversion_unit_id === null || $u->conversion_qty === null) {
            return null;
        }
        $target = $u->relationLoaded('conversionUnit') ? $u->conversionUnit : $u->conversionUnit()->first();
        if (!$target) {
            return null;
        }
        $qty = rtrim(rtrim(number_format((float) $u->conversion_qty, 4, '.', ','), '0'), '.');
        $own = $u->symbol ?: $u->unit_name;
        $tgt = $target->symbol ?: $target->unit_name;

        return "1 {$own} = {$qty} {$tgt}";
    }

    /** Validate + normalise conversion fields into column values. */
    private function conversionColumns(int $companyId, array $data, ?Unit $existing): array
    {
        $out = [];
        $touched = array_key_exists('conversionUnitId', $data) || array_key_exists('conversionQty', $data);
        if (!$touched) {
            return $out;
        }

        $unitId = array_key_exists('conversionUnitId', $data) ? $data['conversionUnitId'] : $existing?->conversion_unit_id;
        $qty    = array_key_exists('conversionQty', $data) ? $data['conversionQty'] : $existing?->conversion_qty;

        if ($unitId === null || $unitId === '') {
            return ['conversion_unit_id' => null, 'conversion_qty' => null];
        }
        $unitId = (int) $unitId;
        if ($existing && $unitId === $existing->id) {
            throw new HttpException(422, 'A unit cannot convert to itself');
        }
        if (!Unit::where('company_id', $companyId)->whereKey($unitId)->exists()) {
            throw new HttpException(422, 'Conversion unit not found for this company');
        }
        if ($qty === null || $qty === '' || (float) $qty <= 0) {
            throw new HttpException(422, 'Conversion quantity must be greater than 0');
        }

        return ['conversion_unit_id' => $unitId, 'conversion_qty' => (float) $qty];
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = (int) ($filters['limit'] ?? $filters['per_page'] ?? 100);

        $query = Unit::where('company_id', $companyId)->with('conversionUnit')->withCount('products')->orderBy('unit_name');
        if (!empty($filters['search'])) {
            $query->where('unit_name', 'like', '%' . $filters['search'] . '%');
        }
        if (array_key_exists('status', $filters) && $filters['status'] !== '' && $filters['status'] !== null) {
            $query->where('status', filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN));
        }

        $paginated = $query->paginate($perPage);

        return [
            'data'         => array_map(fn ($u) => $this->toArray($u), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
        ];
    }

    /** Lightweight active-unit list for dropdowns. */
    public function simple(int $companyId): array
    {
        return Unit::where('company_id', $companyId)
            ->with('conversionUnit')->withCount('products')
            ->where('status', true)
            ->orderBy('unit_name')
            ->get()
            ->map(fn ($u) => $this->toArray($u))
            ->all();
    }

    public function get(int $id, int $companyId): array
    {
        return $this->toArray($this->find($id, $companyId));
    }

    /** Reject a duplicate unit name or symbol (case-insensitive) within the company. */
    private function assertUnique(int $companyId, ?string $name, ?string $symbol, ?int $exceptId): void
    {
        $base = fn () => Unit::where('company_id', $companyId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId));

        $name = $name !== null ? trim($name) : '';
        if ($name !== '' && $base()->whereRaw('LOWER(unit_name) = ?', [mb_strtolower($name)])->exists()) {
            throw new HttpException(422, "A unit named \"{$name}\" already exists");
        }
        $symbol = $symbol !== null ? trim($symbol) : '';
        if ($symbol !== '' && $base()->whereRaw('LOWER(symbol) = ?', [mb_strtolower($symbol)])->exists()) {
            throw new HttpException(422, "The symbol \"{$symbol}\" is already used by another unit");
        }
    }

    /** Conversion target must share the unit's type. */
    private function assertSameType(int $companyId, array $cols, string $type): void
    {
        if (empty($cols['conversion_unit_id'])) {
            return;
        }
        $target = Unit::where('company_id', $companyId)->find($cols['conversion_unit_id']);
        if ($target && ($target->type ?: 'Count') !== $type) {
            throw new HttpException(422, "Conversion unit must be a {$type} unit");
        }
    }

    public function create(int $companyId, array $data): array
    {
        $this->assertUnique($companyId, $data['unitName'] ?? null, $data['symbol'] ?? null, null);
        $this->assertSameType($companyId, $this->conversionColumns($companyId, $data, null), $data['type'] ?? 'Count');
        $unit = Unit::create([
            'company_id' => $companyId,
            'unit_name'  => $data['unitName'],
            'symbol'     => $data['symbol'] ?? null,
            'type'       => $data['type'] ?? 'Count',
            'status'     => $data['status'] ?? true,
        ] + $this->conversionColumns($companyId, $data, null));

        return $this->toArray($unit);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $unit = $this->find($id, $companyId);

        $this->assertUnique(
            $companyId,
            array_key_exists('unitName', $data) ? $data['unitName'] : null,
            array_key_exists('symbol', $data) ? $data['symbol'] : null,
            $unit->id,
        );
        $newType = !empty($data['type']) ? $data['type'] : ($unit->type ?: 'Count');
        $cols = $this->conversionColumns($companyId, $data, $unit);
        if (!$cols && $unit->conversion_unit_id && !empty($data['type'])) {
            $cols = ['conversion_unit_id' => $unit->conversion_unit_id];
        }
        $this->assertSameType($companyId, $cols, $newType);

        if (array_key_exists('unitName', $data)) $unit->unit_name = $data['unitName'];
        if (array_key_exists('symbol', $data))   $unit->symbol = $data['symbol'];
        if (array_key_exists('status', $data))   $unit->status = (bool) $data['status'];
        if (!empty($data['type']))               $unit->type = $data['type'];
        foreach ($this->conversionColumns($companyId, $data, $unit) as $col => $val) {
            $unit->{$col} = $val;
        }
        $unit->save();

        return $this->toArray($unit);
    }

    public function delete(int $id, int $companyId): void
    {
        $unit = $this->find($id, $companyId);

        $used = $unit->products()->count();
        if ($used > 0) {
            throw new HttpException(422, "Unit is used by {$used} product(s). Change them first.");
        }
        $dependents = Unit::where('company_id', $companyId)->where('conversion_unit_id', $unit->id)->pluck('unit_name')->all();
        if ($dependents) {
            throw new HttpException(422, 'Unit is the conversion target of: ' . implode(', ', $dependents) . '. Change them first.');
        }

        $unit->delete();
    }

    private function find(int $id, int $companyId): Unit
    {
        $unit = Unit::where('company_id', $companyId)->find($id);
        if (!$unit) {
            throw new HttpException(404, 'Unit not found');
        }
        return $unit;
    }
}
