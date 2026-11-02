<?php

namespace App\Repositories\Eloquent;

use App\Models\CampaignLink;
use App\Models\CampaignLinkClick;
use App\Models\Sell;
use App\Repositories\Contracts\ICampaignLinkRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CampaignLinkRepository implements ICampaignLinkRepository
{
    private const REVENUE = "COALESCE(SUM(CASE WHEN status <> 'Cancelled' THEN amount ELSE 0 END), 0)";

    public function __construct(private readonly CampaignLink $model)
    {
    }

    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->where('company_id', $companyId)->with('creator:id,full_name');

        if (!empty($filters['search']) && is_string($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")
                ->orWhere('utm_campaign', 'like', "%{$s}%"));
        }
        if (!empty($filters['channel']) && in_array($filters['channel'], CampaignLink::CHANNELS, true)) {
            $query->where('channel', $filters['channel']);
        }
        if (!empty($filters['targetType']) && in_array($filters['targetType'], CampaignLink::TARGET_TYPES, true)) {
            $query->where('target_type', $filters['targetType']);
        }
        if (array_key_exists('isActive', $filters) && $filters['isActive'] !== '' && $filters['isActive'] !== null) {
            $active = filter_var($filters['isActive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active !== null) {
                $query->where('is_active', $active);
            }
        }

        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? $filters['per_page'] ?? $filters['limit'] ?? 20)));

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function findByIdAndCompany(int $id, int $companyId): ?CampaignLink
    {
        return $this->model->newQuery()->where('company_id', $companyId)->with('creator:id,full_name')->find($id);
    }

    public function findActiveByCode(string $code, int $companyId): ?CampaignLink
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->where('code', strtolower($code))->where('is_active', true)->first();
    }

    public function codeExists(string $code, int $companyId, ?int $excludeId = null): bool
    {
        return $this->model->newQuery()->withTrashed()->where('company_id', $companyId)->where('code', strtolower($code))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))->exists();
    }

    public function create(array $data): CampaignLink
    {
        return $this->model->newQuery()->create($data);
    }

    public function update(CampaignLink $link, array $data): CampaignLink
    {
        $link->fill($data)->save();

        return $link->refresh()->load('creator:id,full_name');
    }

    public function delete(CampaignLink $link): void
    {
        $link->delete();
    }

    public function clicksByLink(int $companyId, array $linkIds, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if (!$linkIds) {
            return [];
        }

        return CampaignLinkClick::query()->where('company_id', $companyId)->whereIn('campaign_link_id', $linkIds)
            ->when($from, fn ($q) => $q->where('clicked_at', '>=', $from))->where('clicked_at', '<=', $to)
            ->groupBy('campaign_link_id')->selectRaw('campaign_link_id, COUNT(*) as c')
            ->pluck('c', 'campaign_link_id')->map(fn ($v) => (int) $v)->all();
    }

    public function ordersByLink(int $companyId, array $linkIds, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        if (!$linkIds) {
            return [];
        }
        $out = [];
        $rows = $this->sells($companyId, $from, $to)->whereIn('campaign_link_id', $linkIds)
            ->groupBy('campaign_link_id')->selectRaw('campaign_link_id, COUNT(*) as orders, ' . self::REVENUE . ' as revenue')->get();
        foreach ($rows as $r) {
            $out[(int) $r->campaign_link_id] = ['orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)];
        }

        return $out;
    }

    public function clicksByChannel(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return CampaignLinkClick::query()->from('campaign_link_clicks as c')
            ->join('campaign_links as l', 'l.id', '=', 'c.campaign_link_id')
            ->where('c.company_id', $companyId)
            ->when($from, fn ($q) => $q->where('c.clicked_at', '>=', $from))->where('c.clicked_at', '<=', $to)
            ->groupBy('l.channel')->selectRaw('l.channel as channel, COUNT(*) as clicks')
            ->pluck('clicks', 'channel')->map(fn ($v) => (int) $v)->all();
    }

    public function ordersByAdSource(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $out = [];
        $rows = $this->sells($companyId, $from, $to)->whereNotNull('ad_source')
            ->groupBy('ad_source')->selectRaw('ad_source, COUNT(*) as orders, ' . self::REVENUE . ' as revenue')->get();
        foreach ($rows as $r) {
            $out[(string) $r->ad_source] = ['orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)];
        }

        return $out;
    }

    public function linkOrderBreakdown(int $companyId, int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $r = $this->sells($companyId, $from, $to)->where('campaign_link_id', $linkId)->selectRaw(
            'COUNT(*) as orders, ' . self::REVENUE . " as revenue,
             SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
             SUM(CASE WHEN status IN ('Processing', 'Delivered') THEN 1 ELSE 0 END) as confirmed,
             SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
             SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) as cancelled"
        )->first();

        return [
            'orders' => (int) ($r->orders ?? 0),
            'revenue' => round((float) ($r->revenue ?? 0), 2),
            'pending' => (int) ($r->pending ?? 0),
            'confirmed' => (int) ($r->confirmed ?? 0),
            'delivered' => (int) ($r->delivered ?? 0),
            'cancelled' => (int) ($r->cancelled ?? 0),
        ];
    }

    public function clicksPerDay(int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return CampaignLinkClick::query()->where('campaign_link_id', $linkId)
            ->when($from, fn ($q) => $q->where('clicked_at', '>=', $from))->where('clicked_at', '<=', $to)
            ->groupByRaw('DATE(clicked_at)')->selectRaw('DATE(clicked_at) as d, COUNT(*) as c')
            ->pluck('c', 'd')->map(fn ($v) => (int) $v)->all();
    }

    public function ordersPerDay(int $companyId, int $linkId, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $out = [];
        $rows = $this->sells($companyId, $from, $to)->where('campaign_link_id', $linkId)
            ->groupByRaw('DATE(order_time)')->selectRaw('DATE(order_time) as d, COUNT(*) as orders, ' . self::REVENUE . ' as revenue')->get();
        foreach ($rows as $r) {
            $out[(string) $r->d] = ['orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)];
        }

        return $out;
    }

    public function recentOrders(int $companyId, int $linkId, int $limit = 10): \Illuminate\Support\Collection
    {
        return Sell::query()->where('company_id', $companyId)->where('campaign_link_id', $linkId)
            ->orderByDesc('order_time')->orderByDesc('id')->limit($limit)
            ->get(['id', 'invoice_no', 'customer_name', 'amount', 'status', 'order_time', 'is_quick_order', 'possible_duplicate']);
    }

    public function topLinks(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 5): array
    {
        return $this->sells($companyId, $from, $to)->whereNotNull('campaign_link_id')
            ->groupBy('campaign_link_id')->selectRaw('campaign_link_id, COUNT(*) as orders, ' . self::REVENUE . ' as revenue')
            ->orderByDesc('orders')->orderByDesc('revenue')->limit($limit)->get()
            ->map(fn ($r) => ['id' => (int) $r->campaign_link_id, 'orders' => (int) $r->orders, 'revenue' => round((float) $r->revenue, 2)])
            ->all();
    }

    public function recentClickExists(int $linkId, string $ipHash, \DateTimeInterface $since): bool
    {
        return CampaignLinkClick::query()->where('campaign_link_id', $linkId)->where('ip_hash', $ipHash)
            ->where('clicked_at', '>=', $since)->exists();
    }

    public function recordClick(CampaignLink $link, array $click): void
    {
        DB::transaction(function () use ($link, $click) {
            CampaignLinkClick::query()->create($click + ['campaign_link_id' => $link->id, 'company_id' => $link->company_id]);
            $this->model->newQuery()->whereKey($link->id)->update([
                'clicks_count' => DB::raw('clicks_count + 1'),
                'last_clicked_at' => $click['clicked_at'] ?? now(),
            ]);
        });
    }

    public function linkCounts(int $companyId): array
    {
        $r = $this->model->newQuery()->where('company_id', $companyId)
            ->selectRaw('COUNT(*) as links, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')->first();

        return ['links' => (int) ($r->links ?? 0), 'activeLinks' => (int) ($r->active ?? 0)];
    }

    private function sells(int $companyId, ?\DateTimeInterface $from, \DateTimeInterface $to)
    {
        return Sell::query()->where('company_id', $companyId)
            ->when($from, fn ($q) => $q->where('order_time', '>=', $from))->where('order_time', '<=', $to);
    }
}
