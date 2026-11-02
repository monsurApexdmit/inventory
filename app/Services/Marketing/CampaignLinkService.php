<?php

namespace App\Services\Marketing;

use App\DTOs\Marketing\CampaignLinkDTO;
use App\DTOs\Marketing\CampaignLinkMapper;
use App\Models\CampaignLink;
use App\Models\Collection;
use App\Models\Product;
use App\Repositories\Contracts\ICampaignLinkRepository;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Ad order links: CRUD, URL building, click recording and per-link / per-channel performance. */
class CampaignLinkService
{
    public const PERIODS = ['today', '7d', '30d', '90d', 'all'];

    private const CODE_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Clicks from the same IP on the same link within this window count once. */
    public const CLICK_DEDUPE_MINUTES = 30;

    private const MEDIUM = [
        'facebook' => 'social', 'instagram' => 'social', 'tiktok' => 'social', 'youtube' => 'social', 'messenger' => 'social',
        'whatsapp' => 'chat', 'google' => 'cpc', 'other' => 'referral',
    ];

    private readonly CampaignLinkMapper $mapper;

    public function __construct(private readonly ICampaignLinkRepository $repository)
    {
        $this->mapper = new CampaignLinkMapper();
    }

    // ------------------------------------------------------------------
    // Admin
    // ------------------------------------------------------------------

    public function list(int $companyId, array $filters): array
    {
        [$periodKey, $from, $to] = $this->period($filters, 'all');
        $page = $this->repository->paginateByCompany($companyId, $filters);
        $links = collect($page->items());
        $ids = $links->pluck('id')->map(fn ($v) => (int) $v)->all();

        $clicks = $this->repository->clicksByLink($companyId, $ids, $from, $to);
        $orders = $this->repository->ordersByLink($companyId, $ids, $from, $to);
        $targets = $this->preloadTargets($companyId, $links);

        $data = $links->map(fn (CampaignLink $l) => $this->mapper->toDTO($l, [
            'targets' => $targets,
            'stats' => $this->stats($clicks[$l->id] ?? 0, $orders[$l->id]['orders'] ?? 0, $orders[$l->id]['revenue'] ?? 0.0),
        ])->toArray())->all();

        return [
            'data' => $data,
            'pagination' => [
                'page' => $page->currentPage(),
                'perPage' => $page->perPage(),
                'total' => $page->total(),
                'lastPage' => $page->lastPage(),
            ],
            'period' => $this->periodMeta($periodKey, $from, $to),
        ];
    }

    public function get(int $id, int $companyId): CampaignLinkDTO
    {
        return $this->toDTOWithLifetimeStats($this->find($id, $companyId));
    }

    public function create(int $companyId, ?int $userId, array $data): CampaignLinkDTO
    {
        $row = $this->normalize($companyId, $data, null);
        $row['company_id'] = $companyId;
        $row['created_by'] = $userId;
        $row['is_active'] = (bool) ($data['isActive'] ?? true);
        if (empty($row['code'])) {
            $row['code'] = $this->generateCode($companyId);
        }

        return $this->toDTOWithLifetimeStats($this->repository->create($row));
    }

    public function update(int $id, int $companyId, array $data): CampaignLinkDTO
    {
        $link = $this->find($id, $companyId);
        $row = $this->normalize($companyId, $data, $link);
        if (array_key_exists('isActive', $data)) {
            $row['is_active'] = (bool) $data['isActive'];
        }

        return $this->toDTOWithLifetimeStats($this->repository->update($link, $row));
    }

    public function setStatus(int $id, int $companyId, bool $active): CampaignLinkDTO
    {
        return $this->toDTOWithLifetimeStats($this->repository->update($this->find($id, $companyId), ['is_active' => $active]));
    }

    public function delete(int $id, int $companyId): void
    {
        $this->repository->delete($this->find($id, $companyId));
    }

    public function linkStats(int $id, int $companyId, array $query): array
    {
        $link = $this->find($id, $companyId);
        [$periodKey, $from, $to] = $this->period($query, '30d');

        $clicks = $this->repository->clicksByLink($companyId, [$link->id], $from, $to)[$link->id] ?? 0;
        $b = $this->repository->linkOrderBreakdown($companyId, $link->id, $from, $to);

        // One row per day of the range (for "all": since the link was created)
        $start = Carbon::parse($from ?? $link->created_at)->startOfDay();
        $clickDays = $this->repository->clicksPerDay($link->id, $from, $to);
        $orderDays = $this->repository->ordersPerDay($companyId, $link->id, $from, $to);
        $firstSeen = array_merge(array_keys($clickDays), array_keys($orderDays));
        if ($firstSeen) {
            $earliest = Carbon::parse(min($firstSeen))->startOfDay();
            if ($earliest->lt($start)) {
                $start = $earliest;
            }
        }
        $byDay = [];
        for ($d = $start->copy(); $d->lte($to) && count($byDay) < 400; $d->addDay()) {
            $k = $d->toDateString();
            $byDay[] = [
                'date' => $k,
                'clicks' => $clickDays[$k] ?? 0,
                'orders' => $orderDays[$k]['orders'] ?? 0,
                'revenue' => $orderDays[$k]['revenue'] ?? 0.0,
            ];
        }

        $recent = $this->repository->recentOrders($companyId, $link->id)->map(fn ($s) => [
            'id' => (int) $s->id,
            'invoiceNo' => $s->invoice_no,
            'customerName' => $s->customer_name,
            'amount' => (float) $s->amount,
            'status' => $s->status,
            'orderTime' => $s->order_time?->toIso8601String(),
            'isQuickOrder' => (bool) $s->is_quick_order,
            'possibleDuplicate' => (bool) $s->possible_duplicate,
        ])->all();

        return [
            'link' => $this->toDTOWithLifetimeStats($link)->toArray(),
            'period' => $this->periodMeta($periodKey, $from ?? $start, $to),
            'clicks' => $clicks,
            'orders' => $b['orders'],
            'revenue' => $b['revenue'],
            'confirmed' => $b['confirmed'],
            'delivered' => $b['delivered'],
            'cancelled' => $b['cancelled'],
            'pending' => $b['pending'],
            'conversionRate' => $this->rate($b['orders'], $clicks),
            'byDay' => $byDay,
            'recentOrders' => $recent,
        ];
    }

    public function summary(int $companyId, array $query): array
    {
        [$periodKey, $from, $to] = $this->period($query, '30d');

        $clicksByChannel = $this->repository->clicksByChannel($companyId, $from, $to);
        $ordersBySource = $this->repository->ordersByAdSource($companyId, $from, $to);
        unset($ordersBySource['phone']);

        $byChannel = [];
        foreach (array_unique(array_merge(array_keys($clicksByChannel), array_keys($ordersBySource))) as $ch) {
            $c = $clicksByChannel[$ch] ?? 0;
            $o = $ordersBySource[$ch]['orders'] ?? 0;
            $byChannel[] = [
                'channel' => $ch,
                'label' => AttributionService::label($ch),
                'clicks' => $c,
                'orders' => $o,
                'revenue' => $ordersBySource[$ch]['revenue'] ?? 0.0,
                'conversionRate' => $this->rate($o, $c),
            ];
        }
        usort($byChannel, fn ($a, $b) => [$b['revenue'], $b['orders'], $b['clicks']] <=> [$a['revenue'], $a['orders'], $a['clicks']]);

        $top = $this->repository->topLinks($companyId, $from, $to, 5);
        $topIds = array_column($top, 'id');
        $topClicks = $this->repository->clicksByLink($companyId, $topIds, $from, $to);
        $topModels = CampaignLink::withTrashed()->where('company_id', $companyId)->whereIn('id', $topIds)->get()->keyBy('id');
        $topLinks = [];
        foreach ($top as $t) {
            $m = $topModels[$t['id']] ?? null;
            if (!$m) {
                continue;
            }
            $c = $topClicks[$t['id']] ?? 0;
            $topLinks[] = [
                'id' => $t['id'],
                'name' => $m->name,
                'code' => $m->code,
                'channel' => $m->channel,
                'isDeleted' => $m->trashed(),
                'clicks' => $c,
                'orders' => $t['orders'],
                'revenue' => $t['revenue'],
                'conversionRate' => $this->rate($t['orders'], $c),
            ];
        }

        $totalClicks = array_sum($clicksByChannel);
        $totalOrders = array_sum(array_column($ordersBySource, 'orders'));

        return [
            'period' => $this->periodMeta($periodKey, $from, $to),
            'totals' => [
                'clicks' => $totalClicks,
                'orders' => $totalOrders,
                'revenue' => round(array_sum(array_column($ordersBySource, 'revenue')), 2),
                'conversionRate' => $this->rate($totalOrders, $totalClicks),
            ] + $this->repository->linkCounts($companyId),
            'byChannel' => $byChannel,
            'topLinks' => $topLinks,
        ];
    }

    // ------------------------------------------------------------------
    // Public storefront: /store/l/{code}
    // ------------------------------------------------------------------

    /**
     * Resolve a short link and record a click (deduped per IP hash + link for 30 minutes, bots ignored).
     * @return array{linkId:int, code:string, targetType:string, targetUrl:string, counted:bool}
     */
    public function resolveClick(int $companyId, string $code, ?string $ip, ?string $userAgent, ?string $referrer, ?string $storeHost = null): array
    {
        $link = preg_match('/^[A-Za-z0-9_-]{1,20}$/', $code) ? $this->repository->findActiveByCode($code, $companyId) : null;
        if (!$link) {
            throw new HttpException(404, 'Link not found');
        }

        $counted = false;
        $ua = (string) $userAgent;
        $isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp\/|telegrambot|headless|curl|wget|python-requests/i', $ua);
        if (!$isBot) {
            $ipHash = self::hash((string) $ip);
            $now = now();
            if (!$this->repository->recentClickExists($link->id, $ipHash, $now->copy()->subMinutes(self::CLICK_DEDUPE_MINUTES))) {
                $ref = $referrer ? mb_substr(trim($referrer), 0, 500) : null;
                if ($ref && $storeHost && strtolower((string) parse_url($ref, PHP_URL_HOST)) === strtolower($storeHost)) {
                    $ref = null; // the storefront itself, not a real referrer
                }
                $this->repository->recordClick($link, [
                    'clicked_at' => $now,
                    'ip_hash' => $ipHash,
                    'ua_hash' => self::hash($ua),
                    'referrer' => $ref,
                ]);
                $counted = true;
            }
        }

        return [
            'linkId' => (int) $link->id,
            'code' => $link->code,
            'targetType' => $link->target_type,
            'targetUrl' => CampaignLinkMapper::targetPath($link, $this->targetKey($link)),
            'counted' => $counted,
        ];
    }

    /** HMAC-SHA256 with the app key: stable for dedupe, not reversible to the raw IP / UA. */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** @return array{0:string, 1:?Carbon, 2:Carbon} [key, from (null = all time), to] */
    public function period(array $query, string $default): array
    {
        $to = now()->endOfDay();
        $fromQ = $query['from'] ?? null;
        $toQ = $query['to'] ?? null;
        if ($fromQ || $toQ) {
            try {
                $from = $fromQ ? Carbon::createFromFormat('Y-m-d', (string) $fromQ)->startOfDay() : null;
                $to = $toQ ? Carbon::createFromFormat('Y-m-d', (string) $toQ)->endOfDay() : $to;
            } catch (\Throwable) {
                throw new HttpException(422, 'Dates must be in YYYY-MM-DD format.');
            }
            if ($from && $from->gt($to)) {
                throw new HttpException(422, 'The start date must be before the end date.');
            }

            return ['custom', $from, $to];
        }

        $key = (string) ($query['period'] ?? $default);
        if ($key === '') {
            $key = $default;
        }
        if (!in_array($key, self::PERIODS, true)) {
            throw new HttpException(422, 'Period must be one of: ' . implode(', ', self::PERIODS));
        }
        $from = match ($key) {
            'today' => now()->startOfDay(),
            '7d' => now()->subDays(6)->startOfDay(),
            '30d' => now()->subDays(29)->startOfDay(),
            '90d' => now()->subDays(89)->startOfDay(),
            default => null,
        };

        return [$key, $from, $to];
    }

    private function periodMeta(string $key, ?\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return ['key' => $key, 'from' => $from?->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    private function stats(int $clicks, int $orders, float $revenue): array
    {
        return ['clicks' => $clicks, 'orders' => $orders, 'revenue' => round($revenue, 2), 'conversionRate' => $this->rate($orders, $clicks)];
    }

    private function rate(int $orders, int $clicks): float
    {
        return $clicks > 0 ? round($orders / $clicks * 100, 2) : 0.0;
    }

    private function toDTOWithLifetimeStats(CampaignLink $link): CampaignLinkDTO
    {
        $cid = (int) $link->company_id;
        $to = now()->endOfDay();
        $clicks = $this->repository->clicksByLink($cid, [$link->id], null, $to)[$link->id] ?? 0;
        $orders = $this->repository->ordersByLink($cid, [$link->id], null, $to)[$link->id] ?? ['orders' => 0, 'revenue' => 0.0];

        return $this->mapper->toDTO($link, ['stats' => $this->stats($clicks, $orders['orders'], $orders['revenue'])]);
    }

    private function find(int $id, int $companyId): CampaignLink
    {
        $link = $this->repository->findByIdAndCompany($id, $companyId);
        if (!$link) {
            throw new HttpException(404, 'Campaign link not found');
        }

        return $link;
    }

    /** Validated request data → columns; checks the target exists and fills UTM defaults. */
    private function normalize(int $companyId, array $data, ?CampaignLink $existing): array
    {
        $row = [];
        $str = fn ($v) => $v === null ? null : (trim((string) $v) === '' ? null : trim((string) $v));

        if (array_key_exists('name', $data)) {
            $row['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('channel', $data)) {
            $row['channel'] = $data['channel'];
        }
        if (array_key_exists('targetType', $data)) {
            $row['target_type'] = $data['targetType'];
        }
        if (array_key_exists('targetId', $data)) {
            $row['target_id'] = $data['targetId'] !== null ? (int) $data['targetId'] : null;
        }
        if (array_key_exists('notes', $data)) {
            $row['notes'] = $str($data['notes']);
        }

        $name = $row['name'] ?? $existing?->name ?? '';
        $channel = $row['channel'] ?? $existing?->channel ?? 'other';
        $targetType = $row['target_type'] ?? $existing?->target_type ?? 'store';
        $targetId = array_key_exists('target_id', $row) ? $row['target_id'] : $existing?->target_id;

        // Target must exist in this company
        $targetSlug = null;
        if ($targetType === 'store') {
            $row['target_id'] = null;
        } elseif ($targetType === 'collection') {
            if (!$targetId) {
                throw new HttpException(422, 'Choose a collection.');
            }
            $c = Collection::where('company_id', $companyId)->find($targetId);
            if (!$c) {
                throw new HttpException(422, 'That collection was not found.');
            }
            $targetSlug = $c->slug;
        } else {
            if (!$targetId) {
                throw new HttpException(422, 'Choose a product.');
            }
            $p = Product::where('company_id', $companyId)->find($targetId);
            if (!$p) {
                throw new HttpException(422, 'That product was not found.');
            }
            $targetSlug = $p->slug ?: (string) $p->id;
        }

        // Code: custom (unique per company, never reused) or generated on create
        if (array_key_exists('code', $data) && $str($data['code']) !== null) {
            $code = strtolower($str($data['code']));
            if ($this->repository->codeExists($code, $companyId, $existing?->id)) {
                throw new HttpException(422, 'That code is already used by another link.');
            }
            $row['code'] = $code;
        }

        // UTM: sent value, else keep existing, else default from channel + name + target
        $defaults = [
            'utm_source' => $channel,
            'utm_medium' => self::MEDIUM[$channel] ?? 'referral',
            'utm_campaign' => Str::limit(Str::slug($name), 100, '') ?: null,
            'utm_content' => $targetType === 'store' ? 'store' : (Str::limit((string) $targetSlug, 100, '') ?: null),
        ];
        foreach (['utmSource' => 'utm_source', 'utmMedium' => 'utm_medium', 'utmCampaign' => 'utm_campaign', 'utmContent' => 'utm_content'] as $in => $col) {
            if (array_key_exists($in, $data) && $str($data[$in]) !== null) {
                $row[$col] = mb_substr($str($data[$in]), 0, 100);
            } elseif (!$existing || (array_key_exists($in, $data) && $str($data[$in]) === null)) {
                $row[$col] = $defaults[$col];
            }
        }

        return $row;
    }

    private function generateCode(int $companyId): string
    {
        for ($i = 0; $i < 20; $i++) {
            $len = $i < 10 ? 6 : 8;
            $code = '';
            for ($j = 0; $j < $len; $j++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
            if (!$this->repository->codeExists($code, $companyId)) {
                return $code;
            }
        }
        throw new HttpException(500, 'Could not generate a unique link code. Please try again.');
    }

    /** Preload products / collections for a page of links. */
    private function preloadTargets(int $companyId, \Illuminate\Support\Collection $links): array
    {
        $productIds = $links->whereIn('target_type', ['product', 'quick_order'])->pluck('target_id')->filter()->unique()->all();
        $collectionIds = $links->where('target_type', 'collection')->pluck('target_id')->filter()->unique()->all();

        return [
            'product' => $productIds ? Product::with('images')->where('company_id', $companyId)->whereIn('id', $productIds)->get()->keyBy('id')->all() : [],
            'collection' => $collectionIds ? Collection::where('company_id', $companyId)->whereIn('id', $collectionIds)->get()->keyBy('id')->all() : [],
        ];
    }

    /** Minimal target info for building the target path of a public click. */
    private function targetKey(CampaignLink $link): ?array
    {
        if ($link->target_type === 'store' || !$link->target_id) {
            return null;
        }
        $row = $link->target_type === 'collection'
            ? Collection::where('company_id', $link->company_id)->find($link->target_id, ['id', 'slug'])
            : Product::where('company_id', $link->company_id)->find($link->target_id, ['id', 'slug']);

        return $row ? ['id' => (int) $row->id, 'slug' => $row->slug] : null;
    }
}
