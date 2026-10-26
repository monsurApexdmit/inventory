<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\CompanySettings;
use App\Models\ContentPage;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Product;
use App\Models\PaymentMethod;
use App\Models\Sell;
use App\Models\Setting;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorefrontController extends Controller
{
    // GET /api/store/products?company_id=11&search=&category_id=&limit=20&page=1
    public function products(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $query = Product::with(['category', 'images', 'variants'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('orderItems', 'quantity')
            ->where('company_id', $companyId)
            ->where('published', true)->where('visible_online', true);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Deal filters
        if ($request->boolean('is_hot_deal'))    $query->where('is_hot_deal', true);
        if ($request->boolean('is_best_seller')) $query->where('is_best_seller', true);
        if ($request->boolean('is_featured'))    $query->where('is_featured', true);
        if ($request->boolean('on_sale'))        $query->where(fn($q) => $q->where(fn($q2) => $q2->where('offer_price', '>', 0)->where(fn($q3) => $q3->whereNull('offer_ends_at')->orWhereDate('offer_ends_at', '>=', now()->toDateString())))->orWhere(fn($q2) => $q2->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price')));

        $limit    = min((int) $request->query('limit', 20), 100);
        $products = $query->orderBy('created_at', 'desc')->paginate($limit);

        return response()->json([
            'success' => true,
            'data'    => $products->map(fn($p) => $this->formatProduct($p)),
            'meta'    => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    // GET /api/store/deals?company_id=11&filter=hot_deal|best_seller|on_sale|featured|all
    public function deals(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $filter = $request->query('filter', 'all');

        $query = Product::with(['category', 'images', 'variants'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('orderItems', 'quantity')
            ->where('company_id', $companyId)
            ->where('published', true)->where('visible_online', true);

        match ($filter) {
            'hot_deal'    => $query->where('is_hot_deal', true),
            'best_seller' => $query->where('is_best_seller', true),
            'featured'    => $query->where('is_featured', true),
            'on_sale'     => $query->where(fn($q) => $q->where(fn($q2) => $q2->where('offer_price', '>', 0)->where(fn($q3) => $q3->whereNull('offer_ends_at')->orWhereDate('offer_ends_at', '>=', now()->toDateString())))->orWhere(fn($q2) => $q2->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price'))),
            default       => $query->where(function ($q) {
                $q->where('is_hot_deal', true)
                  ->orWhere('is_best_seller', true)
                  ->orWhere('is_featured', true)
                  ->orWhere(fn($q2) => $q2->where('offer_price', '>', 0)->where(fn($q3) => $q3->whereNull('offer_ends_at')->orWhereDate('offer_ends_at', '>=', now()->toDateString())))
                  ->orWhere(fn($q2) => $q2->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price'));
            }),
        };

        $limit    = min((int) $request->query('limit', 48), 100);
        $products = $query->orderByDesc('is_hot_deal')
            ->orderByDesc('is_best_seller')
            ->orderByDesc('updated_at')
            ->paginate($limit);

        return response()->json([
            'success' => true,
            'data'    => $products->map(fn($p) => $this->formatProduct($p)),
            'meta'    => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    // GET /api/store/products/{slugOrId}?company_id=11
    public function product(Request $request, string $slugOrId): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $query = Product::with(['category', 'images', 'variants', 'attributes', 'bundleItems.product', 'bundleItems.variant'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('orderItems', 'quantity')
            ->where('company_id', $companyId)
            ->where('published', true)->where('visible_online', true);

        if (ctype_digit($slugOrId)) {
            $query->where(function ($builder) use ($slugOrId) {
                $builder->where('id', (int) $slugOrId)
                    ->orWhere('slug', $slugOrId);
            });
        } else {
            $query->where('slug', $slugOrId);
        }

        $product = $query->first();

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $this->formatProduct($product)]);
    }

    // GET /api/store/categories?company_id=11
    public function categories(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $categories = Category::where('company_id', $companyId)
            ->where('status', true)
            ->whereNull('parent_id')
            ->with(['children' => fn($q) => $q->where('status', true)])
            ->get()
            ->map(fn($c) => [
                'id'       => $c->id,
                'name'     => $c->category_name,
                'slug'     => str($c->category_name)->slug()->value(),
                'children' => $c->children->map(fn($sub) => [
                    'id'   => $sub->id,
                    'name' => $sub->category_name,
                    'slug' => str($sub->category_name)->slug()->value(),
                ]),
            ]);

        return response()->json(['success' => true, 'data' => $categories]);
    }

    // GET /api/store/coupons/validate?company_id=11&code=SAVE10
    public function validateCoupon(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');
        $code      = $request->query('code');

        if (!$companyId || !$code) {
            return response()->json(['success' => false, 'message' => 'company_id and code are required'], 400);
        }

        $coupon = Coupon::where('company_id', $companyId)
            ->where('code', $code)
            ->where('status', true)
            ->where('channel_online', true)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay()))
            ->whereRaw('(usage_limit IS NULL OR times_used < usage_limit)')
            ->first();

        if (!$coupon) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired coupon'], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'code'             => $coupon->code,
                'campaign_name'    => $coupon->campaign_name,
                'type'             => $coupon->type,
                'discount'         => $coupon->discount,
                'min_order_amount' => $coupon->min_order_amount,
                'free_shipping'    => $coupon->free_shipping,
            ],
        ]);
    }

    // GET /api/store/coupons/active?company_id=11
    public function activeCoupons(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $coupons = Coupon::where('company_id', $companyId)
            ->where('status', true)
            ->where('channel_online', true)
            ->where('start_date', '<=', now())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay()))
            ->whereRaw('(usage_limit IS NULL OR times_used < usage_limit)')
            ->orderBy('priority', 'desc')
            ->get()
            ->map(fn($c) => [
                'code'             => $c->code,
                'campaign_name'    => $c->campaign_name,
                'type'             => $c->type,
                'discount'         => $c->discount,
                'min_order_amount' => $c->min_order_amount,
                'free_shipping'    => $c->free_shipping,
            ]);

        return response()->json(['success' => true, 'data' => $coupons]);
    }

    // GET /api/store/payment-methods?company_id=11
    public function paymentMethods(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $setting        = Setting::where('company_id', $companyId)->first();
        $paymentSettings = $setting?->payment_settings ?? [];
        $codDeposit      = $paymentSettings['cod_shipping_deposit'] ?? [];
        $depositEnabled  = (bool) ($codDeposit['enabled'] ?? false);
        $depositGateway  = $codDeposit['gateway'] ?? 'sslcommerz';
        $depositAmount   = (float) ($codDeposit['custom_amount'] ?? 0);

        // Check if deposit gateway actually has credentials
        $gatewayCreds = $paymentSettings[$depositGateway] ?? [];
        $depositHasCreds = match($depositGateway) {
            'sslcommerz' => !empty($gatewayCreds['store_id']) && !empty($gatewayCreds['store_passwd']),
            'portwallet'  => !empty($gatewayCreds['app_key']) && !empty($gatewayCreds['app_secret']),
            'bkash'       => !empty($gatewayCreds['app_key']) && !empty($gatewayCreds['app_secret'])
                             && !empty($gatewayCreds['username']) && !empty($gatewayCreds['password']),
            'nagad'       => !empty($gatewayCreds['merchant_id']) && !empty($gatewayCreds['private_key']),
            default       => false,
        };
        $depositActive = $depositEnabled && $depositHasCreds;

        $codLimit = (float) ($paymentSettings['cod_limit'] ?? 5000);
        $codFee   = (float) ($paymentSettings['cod_fee'] ?? 0);

        $legacy = PaymentMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn($m) => [
                'id'                     => $m->id,
                'name'                   => $m->name,
                'description'            => $m->description,
                'icon'                   => $m->icon,
                'gateway_type'           => $m->gateway_type ?? 'cod',
                'cod_limit'              => ($m->gateway_type ?? 'cod') === 'cod' ? $codLimit : null,
                'cod_fee'                => ($m->gateway_type ?? 'cod') === 'cod' ? $codFee : null,
                'cod_deposit_required'   => ($m->gateway_type ?? 'cod') === 'cod' && $depositActive,
                'cod_deposit_amount'     => ($m->gateway_type ?? 'cod') === 'cod' && $depositActive ? $depositAmount : null,
            ]);

        // Methods turned on for online orders in Settings > Payments; gateways keep working beside them
        $default = null;
        $methods = $legacy;
        if (is_array($paymentSettings['methods'] ?? null) && $paymentSettings['methods']) {
            $labels = \App\Http\Requests\Setting\UpdatePaymentSettingsRequest::METHODS;
            $saved = [];
            foreach (StorefrontOrderController::onlineMethodSlugs($paymentSettings) as $slug) {
                $isCod = $slug === 'cod';
                $f = $paymentSettings['methods'][$slug]['fields'] ?? [];
                $saved[] = [
                    'id'                   => $slug,
                    'slug'                 => $slug,
                    'name'                 => $labels[$slug][0] ?? ucfirst($slug),
                    'description'          => null,
                    'icon'                 => null,
                    'gateway_type'         => $isCod ? 'cod' : 'manual',
                    'account'              => is_array($f) ? ($f['account'] ?? null) : null,
                    'account_name'         => is_array($f) ? ($f['accountName'] ?? null) : null,
                    'bank_name'            => is_array($f) ? ($f['bankName'] ?? null) : null,
                    'cod_limit'            => $isCod ? $codLimit : null,
                    'cod_fee'              => $isCod ? $codFee : null,
                    'cod_deposit_required' => $isCod && $depositActive,
                    'cod_deposit_amount'   => $isCod && $depositActive ? $depositAmount : null,
                ];
            }
            $gateways = $legacy->filter(fn ($m) => $m['gateway_type'] !== 'cod')->values()->all();
            $methods = array_merge($saved, $gateways);
            $d = $paymentSettings['default'] ?? null;
            $default = $d && in_array($d, StorefrontOrderController::onlineMethodSlugs($paymentSettings), true)
                ? $d : ($saved[0]['slug'] ?? null);
        }

        return response()->json(['success' => true, 'data' => $methods, 'default' => $default]);
    }

    // GET /api/store/shipping-methods?company_id=11
    public function shippingMethods(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $district = mb_strtolower(trim((string) $request->query('district', '')));

        $methods = ShippingMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            // Zone matching: when a district is given, zones that list areas_covered and
            // do not contain it are dropped (pickup methods and zones without areas stay).
            ->filter(function ($m) use ($district) {
                if ($district === '' || ($m->type ?: 'delivery') === 'pickup') {
                    return true;
                }
                $areas = is_array($m->areas_covered) ? implode(',', $m->areas_covered) : (string) $m->areas_covered;
                $areas = mb_strtolower(trim($areas));
                return $areas === '' || str_contains($areas, $district);
            })
            ->values()
            ->map(fn($m) => [
                'id'             => $m->id,
                'name'           => $m->name,
                'description'    => $m->description,
                'price'          => (float) $m->price,
                'express_rate'   => (float) $m->express_rate,
                'type'           => $m->type ?: 'delivery',
                'requires_address' => ($m->type ?: 'delivery') !== 'pickup',
                'areas_covered'  => $m->areas_covered,
                'estimated_days' => $m->estimated_days,
                'icon'           => $m->icon,
            ]);

        return response()->json(['success' => true, 'data' => $methods]);
    }

    // GET /api/store/pickup-points?company_id=11 — locations flagged is_pickup_point that customers can collect from
    public function pickupPoints(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $q = \App\Models\Location::where('company_id', $companyId)->pickupPoints()->orderBy('name');

        return response()->json(['success' => true, 'data' => $q->get()->map(fn ($l) => [
            'id' => $l->id,
            'name' => $l->name,
            'address' => $l->address,
            'district' => $l->district,
            'phone' => $l->phone,
        ])->values()]);
    }

    // GET /api/store/settings/hours?company_id=11 — weekly opening hours + holiday closures
    public function storeHours(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $setting = Setting::where('company_id', $companyId)->first();
        $hours = is_array($setting?->store_hours) ? $setting->store_hours : [];
        $general = is_array($setting?->general_settings) ? $setting->general_settings : [];
        $today = now()->toDateString();
        $holidays = collect($general['holidays'] ?? [])
            ->filter(fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d >= $today)
            ->sort()->values();

        $week = [];
        foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
            $h = $hours[$day] ?? null;
            if (is_array($h)) {
                $week[$day] = ['open' => $h['open'] ?? '', 'close' => $h['close'] ?? '', 'isOpen' => (bool) ($h['isOpen'] ?? false)];
            }
        }

        return response()->json(['success' => true, 'data' => [
            'hours' => $week,
            'holidays' => $holidays,
            'closedToday' => $holidays->contains($today),
            'nextClosure' => $holidays->first(fn ($d) => $d > $today),
            'today' => $today,
        ]]);
    }

    // GET /api/store/pages/{slug}?company_id=11
    public function page(Request $request, string $slug): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $page = ContentPage::where('company_id', $companyId)
            ->where('slug', $slug)
            ->where('is_published', true)
            ->first();

        if (!$page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'summary' => $page->summary,
                'type' => $page->type,
                'place' => $page->place,
                'body' => $page->body,
                'content' => $page->content,
                'publishedAt' => optional($page->published_at)?->toIso8601String(),
                'updatedAt' => optional($page->updated_at)?->toIso8601String(),
            ],
        ]);
    }

    // GET /api/store/pages?company_id=  — published pages for header/footer links
    public function pages(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $pages = ContentPage::where('company_id', $companyId)
            ->where('is_published', true)
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'summary', 'type', 'place', 'body']);

        return response()->json([
            'success' => true,
            'data' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'summary' => $p->summary,
                'type' => $p->type,
                'place' => $p->place,
                'body' => $p->body,
            ])->values(),
        ]);
    }

    // GET /api/store/settings/online-store?company_id=  — public store identity, policies and social links
    public function onlineStore(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $business = Setting::where('company_id', $companyId)->first()?->business_settings ?? [];
        $o = is_array($business['onlineStore'] ?? null) ? $business['onlineStore'] : [];
        $social = is_array($o['social'] ?? null) ? $o['social'] : [];
        $legacy = is_array($business['socialLinks'] ?? null) ? $business['socialLinks'] : [];
        $policies = is_array($o['policies'] ?? null) ? $o['policies'] : [];
        $str = fn ($v) => is_string($v) ? trim($v) : '';

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $str($o['name'] ?? '') ?: $str($business['businessName'] ?? ''),
                'slug' => $str($o['slug'] ?? ''),
                'theme' => $str($o['theme'] ?? ''),
                'published' => (bool) ($o['published'] ?? true),
                'policies' => [
                    'returns' => $str($policies['returns'] ?? ''),
                    'shipping' => $str($policies['shipping'] ?? ''),
                    'privacy' => $str($policies['privacy'] ?? ''),
                ],
                'social' => [
                    'facebook' => $str($social['facebook'] ?? '') ?: $str($legacy['facebook'] ?? ''),
                    'instagram' => $str($social['instagram'] ?? '') ?: $str($legacy['instagram'] ?? ''),
                    'whatsapp' => $str($social['whatsapp'] ?? ''),
                    'youtube' => $str($social['youtube'] ?? ''),
                    'twitter' => $str($legacy['twitter'] ?? ''),
                ],
            ],
        ]);
    }

    // GET /api/store/settings/company?company_id=11
    public function companySettings(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $settings = CompanySettings::where('company_id', $companyId)->first();

        if (!$settings) {
            return response()->json([
                'success' => true,
                'data' => [
                    'companyId' => (int) $companyId,
                    'taxRate' => 0.0,
                    'currency' => 'USD',
                    'timezone' => 'UTC',
                    'language' => 'en',
                    'currencySymbolPosition' => 'before',
                    'currencyDecimalSeparator' => '.',
                    'currencyThousandsSeparator' => ',',
                    'currencyDecimalPlaces' => 2,
                ],
            ]);
        }

        $storeSettings   = Setting::where('company_id', $companyId)->first();
        $generalSettings = $storeSettings?->general_settings ?? [];
        $paymentSettings = $storeSettings?->payment_settings ?? [];
        $storeHours      = $storeSettings?->store_hours ?? [];

        $gatewayLabels = [
            'sslcommerz' => 'SSLCommerz',
            'bkash'      => 'bKash',
            'nagad'      => 'Nagad',
            'portwallet'  => 'PortWallet',
            'stripe'     => 'Stripe',
            'paypal'     => 'PayPal',
            'cod'        => 'Cash on Delivery',
        ];

        $enabledPaymentMethods = [];
        foreach ($gatewayLabels as $key => $label) {
            $cfg = $paymentSettings[$key] ?? [];
            if (!empty($cfg['enabled'])) {
                $enabledPaymentMethods[] = $label;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'companyId' => $settings->company_id,
                'storeName' => $generalSettings['storeName'] ?? 'StoreFront',
                'taxRate' => (float) ($settings->tax_rate ?? 0),
                'currency' => $settings->currency ?? 'USD',
                'timezone' => $settings->timezone ?? 'UTC',
                'language' => $settings->language ?? 'en',
                'currencySymbolPosition' => $settings->getAttribute('currency_symbol_position') ?? 'before',
                'currencyDecimalSeparator' => $settings->getAttribute('currency_decimal_separator') ?? '.',
                'currencyThousandsSeparator' => $settings->getAttribute('currency_thousands_separator') ?? ',',
                'currencyDecimalPlaces' => (int) ($settings->getAttribute('currency_decimal_places') ?? 2),
                'paymentMethods' => $enabledPaymentMethods,
                'freeShippingThreshold' => isset($generalSettings['freeShippingThreshold']) && $generalSettings['freeShippingThreshold'] !== ''
                    ? (float) $generalSettings['freeShippingThreshold']
                    : null,
                'storePhone'    => $generalSettings['storePhone'] ?? null,
                'storeEmail'    => $generalSettings['storeEmail'] ?? null,
                'storeAddress'  => $generalSettings['storeAddress'] ?? null,
                'storeHours'    => $storeHours,
                'logoUrl'       => $storeSettings?->logo_url ?? null,
                'faviconUrl'    => $generalSettings['faviconUrl'] ?? $storeSettings?->logo_url ?? null,
                'bannerUrl'     => $storeSettings?->banner_url ?? null,
                'primaryColor'     => $generalSettings['primaryColor'] ?? null,
                'accentColor'      => $generalSettings['accentColor'] ?? null,
                'backgroundColor'  => $generalSettings['backgroundColor'] ?? null,
            ],
        ]);
    }

    // GET /api/store/settings/homepage-hero?company_id=11
    public function homepageHero(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $setting = Setting::where('company_id', $companyId)->first();
        $hero = data_get($setting?->business_settings, 'auraShopHero', []);
        $slides = collect($hero['slides'] ?? [])
            ->filter(fn($slide) => is_array($slide))
            ->map(fn($slide) => [
                'id' => $slide['id'] ?? null,
                'imagePath' => $slide['imagePath'] ?? null,
                'tag' => $slide['tag'] ?? '',
                'title' => $slide['title'] ?? '',
                'subtitle' => $slide['subtitle'] ?? '',
                'cta' => $slide['cta'] ?? '',
                'link' => $slide['link'] ?? '/shop',
                'gradient' => $slide['gradient'] ?? 'from-primary/80 via-primary/40 to-transparent',
                'enabled' => (bool) ($slide['enabled'] ?? true),
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'autoplayMs' => max(2000, (int) ($hero['autoplayMs'] ?? 6000)),
                'slides' => $slides,
            ],
        ]);
    }

    // GET /api/store/settings/promo-banner?company_id=11
    public function promoBanner(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $setting = Setting::where('company_id', $companyId)->first();
        $promo = data_get($setting?->business_settings, 'promoBanner', []);

        return response()->json([
            'success' => true,
            'data' => [
                'enabled'  => (bool) ($promo['enabled'] ?? false),
                'title'    => $promo['title']    ?? 'Flash Sale — Up to 60% Off Everything',
                'subtitle' => $promo['subtitle'] ?? 'Limited time offer on thousands of products. Don\'t miss out!',
                'cta'      => $promo['cta']      ?? 'Shop the Sale',
                'link'     => $promo['link']     ?? '/shop',
            ],
        ]);
    }

    // GET /api/store/stats?company_id=11
    public function stats(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $totalProducts  = Product::where('company_id', $companyId)->whereNull('deleted_at')->where('published', true)->count();
        $totalOrders    = Sell::where('company_id', $companyId)->whereNull('deleted_at')->count();
        $totalCustomers = Customer::where('company_id', $companyId)->count();
        $todayOrders    = Sell::where('company_id', $companyId)->whereNull('deleted_at')->whereDate('created_at', now()->toDateString())->count();

        return response()->json([
            'success' => true,
            'data'    => compact('totalProducts', 'totalOrders', 'totalCustomers', 'todayOrders'),
        ]);
    }

    private function formatProduct(Product $product): array
    {
        // Online availability = stock at ships-online locations once location stock is set up, else the flat total
        $locStock = app(\App\Services\Inventory\LocationStockService::class);
        $onlineLocs = $locStock->storefrontLocationIds((int) $product->company_id);
        $stock = $onlineLocs ? $locStock->availableAcross($product, null, $onlineLocs) : $product->stock;

        $primaryImage = $product->images->firstWhere('is_primary', true)?->path
            ?? $product->images->first()?->path
            ?? $product->image;

        return [
            'id'            => $product->id,
            'slug'          => $product->slug,
            'name'          => $product->name,
            'description'   => $product->description,
            'price'         => $product->price,
            'sale_price'    => $product->sale_price,
            'offer_price'   => $product->isOfferActive() ? $product->offer_price : null,
            'offer_type'    => $product->isOfferActive() ? $product->offer_type : null,
            'offer_ends_at' => $product->offer_ends_at?->format('Y-m-d'),
            'offer_active'  => $product->isOfferActive(),
            'sku'           => $product->sku,
            'stock'         => $stock,
            'total_sold'    => (int) ($product->order_items_sum_quantity ?? 0),
            'image'         => $primaryImage,
            'images'        => $product->images->sortBy('position')->pluck('path')->values(),
            'category_id'   => $product->category_id,
            'category_name' => $product->category?->category_name,
            'rating'         => round((float) ($product->reviews_avg_rating ?? 0), 1),
            'reviews_count'  => (int) ($product->reviews_count ?? 0),
            'is_featured'    => (bool) $product->is_featured,
            'is_hot_deal'    => (bool) $product->is_hot_deal,
            'is_best_seller' => (bool) $product->is_best_seller,
            'deal_label'     => $product->deal_label,
            'variants'       => $product->variants->map(fn($v) => [
                'id'         => $v->id,
                'name'       => $v->name,
                'price'       => $v->price,
                'sale_price'  => $v->sale_price,
                'offer_price' => $v->offer_price,
                'offer_type'  => $v->offer_type,
                'wholesale_price'   => $v->wholesale_price,
                'min_wholesale_qty' => $v->min_wholesale_qty,
                'stock'       => $onlineLocs ? $locStock->availableAcross($product, (int) $v->id, $onlineLocs) : $v->stock,
                'sku'        => $v->sku,
                'attributes' => $v->attributes,
            ])->values(),
            'is_bundle'      => (bool) $product->is_bundle,
            'bundle_price_override' => $product->bundle_price_override,
            'bundle_items'   => $product->relationLoaded('bundleItems')
                ? $product->bundleItems->map(function ($bi) {
                    $name = $bi->product?->name ?? 'Unknown';
                    if ($bi->variant) $name .= ' — ' . $bi->variant->name;
                    return [
                        'product_id'   => $bi->product_id,
                        'product_name' => $name,
                        'variant_id'   => $bi->variant_id,
                        'quantity'     => $bi->quantity,
                        'sku'          => $bi->product?->sku,
                        'image'        => $bi->product?->image,
                    ];
                })->values()
                : [],
        ];
    }
}
