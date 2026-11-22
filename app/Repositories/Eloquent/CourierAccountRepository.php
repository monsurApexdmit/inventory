<?php

namespace App\Repositories\Eloquent;

use App\Models\CourierAccount;
use App\Models\OrderShipment;
use App\Repositories\Contracts\ICourierAccountRepository;
use App\Services\Courier\CourierStatusMapper;
use Illuminate\Support\Collection;

class CourierAccountRepository implements ICourierAccountRepository
{
    public function __construct(private readonly CourierAccount $model)
    {
    }

    public function listByCompany(int $companyId, bool $activeOnly = false): Collection
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_default')->orderBy('provider')->get();
    }

    public function findByIdAndCompany(int $id, int $companyId): ?CourierAccount
    {
        return $this->model->newQuery()->where('company_id', $companyId)->find($id);
    }

    public function findByProvider(int $companyId, string $provider): ?CourierAccount
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->whereRaw('LOWER(TRIM(provider)) = ?', [strtolower(trim($provider))])->first();
    }

    public function findDefault(int $companyId): ?CourierAccount
    {
        $active = $this->listByCompany($companyId, true)
            ->filter(fn (CourierAccount $a) => in_array($a->providerKey(), CourierAccount::PROVIDERS, true));

        return $active->firstWhere('is_default', true) ?? ($active->count() === 1 ? $active->first() : null);
    }

    public function findByWebhookToken(string $provider, string $token): ?CourierAccount
    {
        if ($token === '') {
            return null;
        }
        $candidates = $this->model->newQuery()->where('webhook_token', $token)->limit(5)->get();
        foreach ($candidates as $a) {
            if ($a->providerKey() === $provider && is_string($a->webhook_token) && hash_equals($a->webhook_token, $token)) {
                return $a;
            }
        }
        return null;
    }

    public function create(array $data): CourierAccount
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(CourierAccount $account, array $data): CourierAccount
    {
        $account->fill($data)->save();
        return $account->fresh();
    }

    public function delete(CourierAccount $account): void
    {
        $account->delete();
    }

    public function clearDefault(int $companyId, ?int $exceptId = null): void
    {
        $this->model->newQuery()->where('company_id', $companyId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }

    public function hasOpenShipments(CourierAccount $account): bool
    {
        return OrderShipment::where('company_id', $account->company_id)->where('courier_account_id', $account->id)
            ->live()->where(fn ($q) => $q->whereNull('courier_state')->orWhereNotIn('courier_state', CourierStatusMapper::FINAL))
            ->exists();
    }

    public function findCourierShipment(int $id, int $companyId): ?OrderShipment
    {
        return OrderShipment::where('company_id', $companyId)->with('sell')->find($id);
    }

    public function findShipmentByConsignment(CourierAccount $account, string $consignmentId, ?string $invoice = null): ?OrderShipment
    {
        $q = OrderShipment::where('company_id', $account->company_id)->where('courier_account_id', $account->id);
        $found = $consignmentId !== '' ? (clone $q)->where('consignment_id', $consignmentId)->latest('id')->first() : null;
        if (!$found && $consignmentId !== '') {
            $found = (clone $q)->where('tracking_number', $consignmentId)->latest('id')->first();
        }
        if (!$found && $invoice) {
            $found = (clone $q)->where('courier_payload->invoice', $invoice)->latest('id')->first()
                ?? (clone $q)->where('courier_payload->merchant_order_id', $invoice)->latest('id')->first();
        }
        return $found;
    }

    public function syncCandidates(\DateTimeInterface $staleBefore, int $limit, ?int $companyId = null): Collection
    {
        return OrderShipment::query()
            ->join('courier_accounts', 'courier_accounts.id', '=', 'order_shipments.courier_account_id')
            ->where('courier_accounts.is_active', true)
            ->whereColumn('courier_accounts.company_id', 'order_shipments.company_id')
            ->when($companyId, fn ($q) => $q->where('order_shipments.company_id', $companyId))
            ->whereNotNull('order_shipments.consignment_id')
            ->live()
            ->where(fn ($q) => $q->whereNull('order_shipments.courier_state')->orWhereNotIn('order_shipments.courier_state', CourierStatusMapper::FINAL))
            ->where(fn ($q) => $q->whereNull('order_shipments.last_synced_at')->orWhere('order_shipments.last_synced_at', '<', $staleBefore))
            ->orderByRaw('order_shipments.last_synced_at IS NOT NULL, order_shipments.last_synced_at ASC')
            ->limit($limit)
            ->select('order_shipments.*')
            ->get();
    }
}
