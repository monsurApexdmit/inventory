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
            'createdAt' => $u->created_at?->toIso8601String(),
            'updatedAt' => $u->updated_at?->toIso8601String(),
        ];
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = (int) ($filters['limit'] ?? $filters['per_page'] ?? 100);

        $query = Unit::where('company_id', $companyId)->orderBy('unit_name');
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

    public function create(int $companyId, array $data): array
    {
        $unit = Unit::create([
            'company_id' => $companyId,
            'unit_name'  => $data['unitName'],
            'symbol'     => $data['symbol'] ?? null,
            'status'     => $data['status'] ?? true,
        ]);

        return $this->toArray($unit);
    }

    public function update(int $id, int $companyId, array $data): array
    {
        $unit = $this->find($id, $companyId);

        if (array_key_exists('unitName', $data)) $unit->unit_name = $data['unitName'];
        if (array_key_exists('symbol', $data))   $unit->symbol = $data['symbol'];
        if (array_key_exists('status', $data))   $unit->status = (bool) $data['status'];
        $unit->save();

        return $this->toArray($unit);
    }

    public function delete(int $id, int $companyId): void
    {
        $this->find($id, $companyId)->delete();
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
