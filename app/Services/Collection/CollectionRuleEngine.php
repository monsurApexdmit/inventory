<?php

namespace App\Services\Collection;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Turns a collection definition ({type, match, rules} or a manual product list) into a Product query,
 * validates/normalises rule JSON and applies the collection sort options.
 *
 * Rule shape: {field, operator, value}. See COLLECTIONS_API_CONTRACT.md section 4.
 */
class CollectionRuleEngine
{
    public const MAX_RULES = 20;

    /** field => allowed operators (first = default when omitted). */
    public const FIELDS = [
        'category' => ['is', 'is_not'],
        'brand' => ['is', 'is_not'],
        'tag' => ['equals', 'not_equals', 'contains'],
        'flag' => ['is', 'is_not'],
        'price' => ['greater_than', 'less_than'],
        'on_offer' => ['is'],
        'new_arrivals' => ['within_days'],
        'best_sellers' => ['top'],
        'in_stock' => ['is'],
    ];

    public const FLAGS = ['best_seller' => 'is_best_seller', 'hot_deal' => 'is_hot_deal', 'featured' => 'is_featured'];

    /** Per-request cache of best-seller rankings: key => ordered product ids. */
    private array $bestSellerCache = [];

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Validate and normalise rules. Returns [normalisedRules, errors] where errors is
     * ['rules.N' => ['Rule N+1: ...']]. Unknown keys inside a rule are dropped.
     */
    public function normalize(mixed $rules, int $companyId): array
    {
        if ($rules === null || $rules === '') {
            return [[], []];
        }
        if (is_string($rules)) {
            $decoded = json_decode($rules, true);
            if (!is_array($decoded)) {
                return [[], ['rules' => ['Rules must be a list of rule objects.']]];
            }
            $rules = $decoded;
        }
        if (!is_array($rules) || !array_is_list($rules)) {
            return [[], ['rules' => ['Rules must be a list of rule objects.']]];
        }
        if (count($rules) > self::MAX_RULES) {
            return [[], ['rules' => ['A collection can have at most ' . self::MAX_RULES . ' rules.']]];
        }

        $out = [];
        $errors = [];
        foreach ($rules as $i => $rule) {
            $n = $i + 1;
            $key = "rules.{$i}";
            if (!is_array($rule)) {
                $errors[$key] = ["Rule {$n}: must be an object with field, operator and value."];
                continue;
            }
            $field = is_string($rule['field'] ?? null) ? strtolower(trim($rule['field'])) : '';
            if ($field === '') {
                $errors[$key] = ["Rule {$n}: choose what the rule checks (field)."];
                continue;
            }
            if (!isset(self::FIELDS[$field])) {
                $errors[$key] = ["Rule {$n}: unknown rule field \"{$field}\"."];
                continue;
            }
            $allowed = self::FIELDS[$field];
            $operator = is_string($rule['operator'] ?? null) && trim($rule['operator']) !== '' ? strtolower(trim($rule['operator'])) : null;
            if ($operator === null) {
                if (count($allowed) > 1) {
                    $errors[$key] = ["Rule {$n}: choose an operator for {$this->label($field)} (" . implode(', ', $allowed) . ').'];
                    continue;
                }
                $operator = $allowed[0];
            }
            if (!in_array($operator, $allowed, true)) {
                $errors[$key] = ["Rule {$n}: operator \"{$operator}\" is not allowed for {$this->label($field)} (use " . implode(', ', $allowed) . ').'];
                continue;
            }

            $value = $rule['value'] ?? null;
            [$value, $error] = $this->normalizeValue($field, $value, $companyId);
            if ($error !== null) {
                $errors[$key] = ["Rule {$n}: {$error}"];
                continue;
            }

            $out[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
        }

        return [$out, $errors];
    }

    /** @return array{0: mixed, 1: ?string} normalised value and error */
    private function normalizeValue(string $field, mixed $value, int $companyId): array
    {
        switch ($field) {
            case 'category':
                if (!is_numeric($value) || (int) $value < 1) {
                    return [null, 'choose a category.'];
                }
                if (!Category::where('company_id', $companyId)->whereKey((int) $value)->exists()) {
                    return [null, 'category not found.'];
                }
                return [(int) $value, null];

            case 'brand':
                if (!is_numeric($value) || (int) $value < 1) {
                    return [null, 'choose a brand.'];
                }
                if (!Brand::where('company_id', $companyId)->whereKey((int) $value)->exists()) {
                    return [null, 'brand not found.'];
                }
                return [(int) $value, null];

            case 'tag':
                $tag = is_scalar($value) ? trim((string) $value) : '';
                if ($tag === '') {
                    return [null, 'enter a tag.'];
                }
                if (mb_strlen($tag) > 100) {
                    return [null, 'tag may not be longer than 100 characters.'];
                }
                return [$tag, null];

            case 'flag':
                $flag = is_string($value) ? strtolower(trim($value)) : '';
                if (!isset(self::FLAGS[$flag])) {
                    return [null, 'choose a flag (best_seller, hot_deal or featured).'];
                }
                return [$flag, null];

            case 'price':
                if (!is_numeric($value) || (float) $value < 0) {
                    return [null, 'enter a price of 0 or more.'];
                }
                return [round((float) $value, 2), null];

            case 'new_arrivals':
                if (!is_numeric($value) || (int) $value != $value || (int) $value < 1 || (int) $value > 3650) {
                    return [null, 'days must be between 1 and 3650.'];
                }
                return [(int) $value, null];

            case 'best_sellers':
                if (!is_array($value)) {
                    return [null, 'best sellers needs a limit and a number of days.'];
                }
                $limit = $value['limit'] ?? null;
                $days = $value['days'] ?? null;
                if (!is_numeric($limit) || (int) $limit != $limit || (int) $limit < 1 || (int) $limit > 500) {
                    return [null, 'best sellers limit must be between 1 and 500.'];
                }
                if (!is_numeric($days) || (int) $days != $days || (int) $days < 1 || (int) $days > 3650) {
                    return [null, 'days must be between 1 and 3650.'];
                }
                return [['limit' => (int) $limit, 'days' => (int) $days], null];

            case 'on_offer':
            case 'in_stock':
                return [true, null];
        }

        return [null, 'invalid value.'];
    }

    private function label(string $field): string
    {
        return str_replace('_', ' ', $field);
    }

    // ------------------------------------------------------------------
    // Query building
    // ------------------------------------------------------------------

    /**
     * THE rule engine entry point: Product query for a collection definition.
     *
     * $definition = ['type' => manual|automatic, 'match' => all|any, 'rules' => [...normalised], 'productIds' => [...ordered]]
     * $storefront = true limits to published + visible_online products (best-seller ranking too).
     */
    public function productQuery(int $companyId, array $definition, bool $storefront = false): Builder
    {
        $query = Product::query()->select('products.*')->where('products.company_id', $companyId);

        if ($storefront) {
            $query->where('products.published', true)->where('products.visible_online', true);
        }

        if (($definition['type'] ?? 'manual') !== 'automatic') {
            $ids = array_values(array_map('intval', $definition['productIds'] ?? []));
            return $query->whereIn('products.id', $ids);
        }

        $rules = $definition['rules'] ?? [];
        $match = ($definition['match'] ?? 'all') === 'any' ? 'any' : 'all';

        $inStock = false;
        $conditions = [];
        foreach ($rules as $rule) {
            if ($rule['field'] === 'in_stock') {
                $inStock = true; // always an AND filter
                continue;
            }
            $conditions[] = $this->condition($rule, $companyId, $storefront);
        }

        if ($conditions) {
            if ($match === 'all') {
                foreach ($conditions as $c) {
                    $query->where($c);
                }
            } else {
                $query->where(function (Builder $q) use ($conditions) {
                    foreach ($conditions as $c) {
                        $q->orWhere($c);
                    }
                });
            }
        }

        if ($inStock) {
            $query->where(function (Builder $q) {
                $q->where('products.stock', '>', 0)
                    ->orWhereExists(fn ($s) => $s->from('product_variants')
                        ->whereColumn('product_variants.product_id', 'products.id')
                        ->whereNull('product_variants.deleted_at')
                        ->where('product_variants.stock', '>', 0));
            });
        }

        return $query;
    }

    /** Definition array from a saved collection. */
    public function definitionFor(Collection $collection, ?array $manualIds = null): array
    {
        return [
            'type' => $collection->type,
            'match' => $collection->match ?: 'all',
            'rules' => is_array($collection->rules) ? $collection->rules : [],
            'productIds' => $collection->type === 'automatic' ? [] : ($manualIds ?? \Illuminate\Support\Facades\DB::table('collection_product')
                ->where('collection_id', $collection->id)->orderBy('position')->orderBy('id')->pluck('product_id')->map(fn ($i) => (int) $i)->all()),
        ];
    }

    /** One rule => closure applying its WHERE clause. */
    private function condition(array $rule, int $companyId, bool $storefront): \Closure
    {
        $value = $rule['value'];
        $op = $rule['operator'];

        return match ($rule['field']) {
            'category' => function (Builder $q) use ($value, $op, $companyId) {
                $ids = $this->categoryWithDescendants((int) $value, $companyId);
                if ($op === 'is') {
                    $q->whereIn('products.category_id', $ids);
                } else {
                    $q->where(fn ($w) => $w->whereNull('products.category_id')->orWhereNotIn('products.category_id', $ids));
                }
            },
            'brand' => function (Builder $q) use ($value, $op) {
                if ($op === 'is') {
                    $q->where('products.brand_id', (int) $value);
                } else {
                    $q->where(fn ($w) => $w->whereNull('products.brand_id')->orWhere('products.brand_id', '!=', (int) $value));
                }
            },
            'tag' => function (Builder $q) use ($value, $op) {
                $v = mb_strtolower((string) $value);
                if ($op === 'equals') {
                    $q->whereRaw('LOWER(TRIM(products.tag)) = ?', [$v]);
                } elseif ($op === 'not_equals') {
                    $q->where(fn ($w) => $w->whereNull('products.tag')->orWhereRaw('LOWER(TRIM(products.tag)) <> ?', [$v]));
                } else {
                    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v) . '%';
                    $q->whereRaw('LOWER(products.tag) LIKE ?', [$like]);
                }
            },
            'flag' => function (Builder $q) use ($value, $op) {
                $col = 'products.' . self::FLAGS[$value];
                if ($op === 'is') {
                    $q->where($col, true);
                } else {
                    $q->where(fn ($w) => $w->whereNull($col)->orWhere($col, false));
                }
            },
            'price' => function (Builder $q) use ($value, $op) {
                $q->whereRaw($this->effectivePriceSql() . ($op === 'greater_than' ? ' > ?' : ' < ?'), [(float) $value]);
            },
            'on_offer' => function (Builder $q) {
                $q->whereRaw($this->offerLiveSql());
            },
            'new_arrivals' => function (Builder $q) use ($value) {
                $q->where('products.created_at', '>=', now()->subDays((int) $value));
            },
            'best_sellers' => function (Builder $q) use ($value, $companyId, $storefront) {
                $q->whereIn('products.id', $this->bestSellerIds($companyId, (int) $value['limit'], (int) $value['days'], $storefront));
            },
        };
    }

    /** Category id plus every descendant id (any depth) within the company. */
    public function categoryWithDescendants(int $categoryId, int $companyId): array
    {
        $byParent = [];
        foreach (Category::where('company_id', $companyId)->get(['id', 'parent_id']) as $c) {
            $byParent[(int) $c->parent_id][] = (int) $c->id;
        }
        $ids = [$categoryId];
        $queue = [$categoryId];
        while ($queue) {
            $id = array_shift($queue);
            foreach ($byParent[$id] ?? [] as $child) {
                if (!in_array($child, $ids, true)) {
                    $ids[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $ids;
    }

    /**
     * Top N product ids by units sold in non-cancelled sells over the last N days, best first.
     * In storefront mode only storefront-visible products are ranked.
     */
    public function bestSellerIds(int $companyId, int $limit, int $days, bool $storefront = false): array
    {
        $key = "{$companyId}:{$limit}:{$days}:" . (int) $storefront;
        if (isset($this->bestSellerCache[$key])) {
            return $this->bestSellerCache[$key];
        }

        $rows = DB::table('order_items')
            ->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->where('sells.company_id', $companyId)
            ->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled')
            ->where('sells.order_time', '>=', now()->subDays($days))
            ->where('products.company_id', $companyId)
            ->whereNull('products.deleted_at')
            ->when($storefront, fn ($q) => $q->where('products.published', true)->where('products.visible_online', true))
            ->groupBy('order_items.product_id')
            ->havingRaw('SUM(order_items.quantity) > 0')
            ->orderByRaw('SUM(order_items.quantity) DESC')
            ->orderBy('order_items.product_id')
            ->limit($limit)
            ->pluck('order_items.product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return $this->bestSellerCache[$key] = $rows;
    }

    // ------------------------------------------------------------------
    // SQL fragments
    // ------------------------------------------------------------------

    private function today(): string
    {
        return now()->toDateString(); // Y-m-d, safe to inline
    }

    /** Normal selling price (mirrors PriceService::basePrice for products). */
    public function basePriceSql(): string
    {
        return '(CASE WHEN products.is_bundle = 1 AND COALESCE(products.bundle_price_override, 0) > 0 THEN products.bundle_price_override'
            . ' WHEN COALESCE(products.sale_price, 0) > 0 THEN products.sale_price ELSE COALESCE(products.price, 0) END)';
    }

    /** Offer is live: offer price set and end date (inclusive) not passed. */
    public function offerLiveSql(): string
    {
        return "(COALESCE(products.offer_price, 0) > 0 AND (products.offer_ends_at IS NULL OR products.offer_ends_at >= '" . $this->today() . "'))";
    }

    /** Price a buyer pays now (mirrors PriceService: base lowered by a live offer, never below 0). */
    public function effectivePriceSql(): string
    {
        $base = $this->basePriceSql();
        $offer = "GREATEST(0, CASE WHEN COALESCE(NULLIF(products.offer_type, ''), 'percentage') = 'percentage'"
            . " THEN {$base} * (1 - products.offer_price / 100) ELSE {$base} - products.offer_price END)";

        return "ROUND(CASE WHEN {$this->offerLiveSql()} AND {$offer} < {$base} THEN {$offer} ELSE {$base} END, 2)";
    }

    /** Units sold in non-cancelled sells, all time (optionally within N days). */
    public function unitsSoldSql(?int $days = null): string
    {
        $since = $days ? " AND sells.order_time >= '" . now()->subDays($days)->format('Y-m-d H:i:s') . "'" : '';

        return '(SELECT COALESCE(SUM(order_items.quantity), 0) FROM order_items JOIN sells ON sells.id = order_items.sell_id'
            . " WHERE order_items.product_id = products.id AND sells.deleted_at IS NULL AND sells.status <> 'Cancelled'{$since})";
    }

    // ------------------------------------------------------------------
    // Sorting
    // ------------------------------------------------------------------

    /** Apply a sort option; returns the sort actually used. */
    public function applySort(Builder $query, string $sort, array $definition, int $companyId, bool $storefront = false): string
    {
        if (!in_array($sort, Collection::SORTS, true)) {
            $sort = 'newest';
        }

        if ($sort === 'manual') {
            if (($definition['type'] ?? 'manual') !== 'automatic') {
                $ids = array_values(array_map('intval', $definition['productIds'] ?? []));
                if ($ids) {
                    $query->orderByRaw('FIELD(products.id, ' . implode(',', $ids) . ')');
                }
                return 'manual';
            }
            // Automatic: best-sellers rank when such a rule exists, else newest.
            foreach ($definition['rules'] ?? [] as $rule) {
                if ($rule['field'] === 'best_sellers') {
                    $ids = $this->bestSellerIds($companyId, (int) $rule['value']['limit'], (int) $rule['value']['days'], $storefront);
                    if ($ids) {
                        // Products outside the ranking (possible with match=any) go last.
                        $query->orderByRaw('FIELD(products.id, ' . implode(',', array_reverse($ids)) . ') DESC');
                    }
                    $query->orderByDesc('products.created_at')->orderByDesc('products.id');
                    return 'manual';
                }
            }
            $sort = 'newest';
        }

        match ($sort) {
            'best_selling' => $query->orderByRaw($this->unitsSoldSql() . ' DESC')->orderByDesc('products.created_at')->orderByDesc('products.id'),
            'price_asc' => $query->orderByRaw($this->effectivePriceSql() . ' ASC')->orderBy('products.id'),
            'price_desc' => $query->orderByRaw($this->effectivePriceSql() . ' DESC')->orderBy('products.id'),
            'name' => $query->orderBy('products.name')->orderBy('products.id'),
            default => $query->orderByDesc('products.created_at')->orderByDesc('products.id'),
        };

        return $sort;
    }
}
