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
            ->where('published', true);

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
        if ($request->boolean('on_sale'))        $query->where(fn($q) => $q->where(fn($q2) => $q2->where('offer_price', '>', 0))->orWhere(fn($q2) => $q2->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price')));

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
            ->where('published', true);

        match ($filter) {
            'hot_deal'    => $query->where('is_hot_deal', true),
            'best_seller' => $query->where('is_best_seller', true),
            'featured'    => $query->where('is_featured', true),
            'on_sale'     => $query->where(fn($q) => $q->where('offer_price', '>', 0)->orWhere(fn($q2) => $q2->where('sale_price', '>', 0)->whereColumn('sale_price', '<', 'price'))),
            default       => $query->where(function ($q) {
                $q->where('is_hot_deal', true)
                  ->orWhere('is_best_seller', true)
                  ->orWhere('is_featured', true)
                  ->orWhere('offer_price', '>', 0)
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

        $query = Product::with(['category', 'images', 'variants', 'attributes'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('orderItems', 'quantity')
            ->where('company_id', $companyId)
            ->where('published', true);

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
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
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
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
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

        $methods = PaymentMethod::where('company_id', $companyId)
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
                'cod_deposit_required'   => ($m->gateway_type ?? 'cod') === 'cod' && $depositActive,
                'cod_deposit_amount'     => ($m->gateway_type ?? 'cod') === 'cod' && $depositActive ? $depositAmount : null,
            ]);

        return response()->json(['success' => true, 'data' => $methods]);
    }

    // GET /api/store/shipping-methods?company_id=11
    public function shippingMethods(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $methods = ShippingMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn($m) => [
                'id'             => $m->id,
                'name'           => $m->name,
                'description'    => $m->description,
                'price'          => (float) $m->price,
                'estimated_days' => $m->estimated_days,
                'icon'           => $m->icon,
            ]);

        return response()->json(['success' => true, 'data' => $methods]);
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
                'content' => $page->content,
                'publishedAt' => optional($page->published_at)?->toIso8601String(),
                'updatedAt' => optional($page->updated_at)?->toIso8601String(),
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
            'offer_price'   => $product->offer_price,
            'offer_type'    => $product->offer_type,
            'sku'           => $product->sku,
            'stock'         => $product->stock,
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
                'stock'       => $v->stock,
                'sku'        => $v->sku,
                'attributes' => $v->attributes,
            ])->values(),
        ];
    }
}
