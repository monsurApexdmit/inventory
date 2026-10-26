<?php

namespace App\Services\Location;

use App\DTOs\Location\LocationDTO;
use App\DTOs\Location\LocationMapper;
use App\Repositories\Contracts\ILocationRepository;
use App\Services\Company\PlanLimitService;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LocationService
{
    private readonly LocationMapper $mapper;

    public function __construct(
        private readonly ILocationRepository $locationRepository,
        private readonly PlanLimitService    $planLimitService,
    ) {
        $this->mapper = new LocationMapper();
    }

    public function list(int $companyId): array
    {
        $locations = $this->locationRepository->findByCompany($companyId);
        $stats = $this->statsFor($companyId);
        return array_map(fn ($location) => $this->mapper->toDTO($location, $stats[$location->id] ?? []), $locations);
    }

    public function get(int $id, int $companyId): LocationDTO
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        return $this->mapper->toDTO($location, $this->statsFor($companyId)[$id] ?? []);
    }

    /** Stock value (cost), SKU count, unit count and staff count per location. */
    private function statsFor(int $companyId): array
    {
        $ids = Location::where('company_id', $companyId)->pluck('id')->all();
        $stats = [];
        foreach ($ids as $id) {
            $stats[$id] = ['value' => 0, 'skus' => 0, 'units' => 0, 'staff' => 0];
        }
        if (!$ids) {
            return $stats;
        }

        $rows = DB::table('variant_inventory as vi')
            ->join('product_variants as pv', 'pv.id', '=', 'vi.variant_id')
            ->whereIn('vi.location_id', $ids)
            ->where('vi.quantity', '>', 0)
            ->groupBy('vi.location_id')
            ->selectRaw('vi.location_id, COUNT(*) as skus, SUM(vi.quantity) as units, SUM(vi.quantity * pv.cost_price) as value')
            ->get();
        foreach ($rows as $r) {
            $stats[$r->location_id]['skus'] = (int) $r->skus;
            $stats[$r->location_id]['units'] = (int) $r->units;
            $stats[$r->location_id]['value'] = (float) $r->value;
        }

        foreach (DB::table('staff')->where('company_id', $companyId)->whereNotNull('location_ids')->pluck('location_ids') as $json) {
            foreach ((array) json_decode($json, true) as $lid) {
                if (isset($stats[(int) $lid])) {
                    $stats[(int) $lid]['staff']++;
                }
            }
        }

        return $stats;
    }

    private function unitsAt(int $locationId): int
    {
        return (int) DB::table('variant_inventory')->where('location_id', $locationId)->where('quantity', '>', 0)->sum('quantity');
    }

    private function assertUniqueName(int $companyId, string $name, ?int $exceptId = null): void
    {
        $q = Location::where('company_id', $companyId)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))]);
        if ($exceptId) {
            $q->where('id', '!=', $exceptId);
        }
        if ($q->exists()) {
            throw new HttpException(422, 'A location with this name already exists.');
        }
    }

    private function mapFields(array $data, array $db = []): array
    {
        $map = [
            'name' => 'name', 'type' => 'type', 'address' => 'address', 'district' => 'district',
            'phone' => 'phone', 'manager' => 'manager', 'contactPerson' => 'contact_person',
            'sellsInPos' => 'sells_in_pos', 'shipsOnline' => 'ships_online', 'isPickupPoint' => 'is_pickup_point', 'isDefault' => 'is_default',
        ];
        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $db[$col] = $data[$in];
            }
        }
        return $db;
    }

    public function create(int $companyId, array $data): LocationDTO
    {
        $this->planLimitService->enforceBranchLimit($companyId);
        $this->assertUniqueName($companyId, (string) ($data['name'] ?? ''));

        $dbData = $this->mapFields($data, ['company_id' => $companyId]);
        $dbData['type'] = $dbData['type'] ?? 'Shop';
        $dbData['is_default'] = (bool) ($dbData['is_default'] ?? false);

        $location = DB::transaction(function () use ($companyId, $dbData) {
            if ($dbData['is_default']) {
                Location::where('company_id', $companyId)->update(['is_default' => false]);
            }
            return $this->locationRepository->create($dbData);
        });

        return $this->get($location->id, $companyId);
    }

    public function update(int $id, int $companyId, array $data): LocationDTO
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        if (isset($data['name'])) {
            $this->assertUniqueName($companyId, $data['name'], $id);
        }

        $dbData = $this->mapFields($data);
        foreach (['name', 'type', 'is_default', 'sells_in_pos', 'ships_online', 'is_pickup_point'] as $notNull) {
            if (array_key_exists($notNull, $dbData) && $dbData[$notNull] === null) {
                unset($dbData[$notNull]);
            }
        }

        DB::transaction(function () use ($id, $companyId, $dbData) {
            if (!empty($dbData['is_default'])) {
                Location::where('company_id', $companyId)->where('id', '!=', $id)->update(['is_default' => false]);
            }
            $this->locationRepository->update($id, $dbData);
        });

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $location = $this->locationRepository->findByIdAndCompany($id, $companyId);

        if (!$location) {
            throw new HttpException(404, 'Location not found');
        }

        if ($location->is_default) {
            throw new HttpException(422, 'The default location cannot be deleted. Set another default first.');
        }

        $units = $this->unitsAt($id);
        if ($units > 0) {
            throw new HttpException(422, "{$location->name} still holds {$units} unit(s) of stock. Transfer it first.");
        }

        $this->locationRepository->delete($id);
    }
}
