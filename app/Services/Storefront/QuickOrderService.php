<?php

namespace App\Services\Storefront;

use App\Http\Controllers\Api\Storefront\StorefrontOrderController;
use App\Http\Requests\Setting\UpdatePaymentSettingsRequest;
use App\Models\CampaignLink;
use App\Models\CompanySettings;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sell;
use App\Models\Setting;
use App\Models\ShippingMethod;
use App\Services\Inventory\LocationStockService;
use App\Services\Marketing\AttributionService;
use App\Services\Pricing\PriceService;
use App\Support\BdPhone;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * One-page "quick order" for shoppers coming from a social post / ad (no login, COD first).
 * Order creation goes through StorefrontCheckoutService, exactly like the normal checkout.
 */
class QuickOrderService
{
    public const PHONE_DAILY_LIMIT = 5;
    public const DUPLICATE_WINDOW_MINUTES = 30;
    private const TXN_REQUIRED = ['bkash', 'nagad'];

    public function __construct(
        private readonly StorefrontCheckoutService $checkout,
        private readonly AttributionService $attribution,
        private readonly LocationStockService $locStock,
    ) {
    }

    // ------------------------------------------------------------------
    // GET /store/quick-order/{slugOrId}
    // ------------------------------------------------------------------

    public function show(int $companyId, string $slugOrId): array
    {
        $product = $this->findProduct($companyId, $slugOrId);
        if (!$product) {
            throw new StorefrontOrderException('Product not found', 404);
        }

        $onlineLocs = $this->locStock->storefrontLocationIds($companyId);
        $stockOf = fn (?int $variantId) => $onlineLocs
            ? $this->locStock->availableAcross($product, $variantId, $onlineLocs)
            : ($variantId ? (int) ($product->variants->firstWhere('id', $variantId)?->stock ?? 0) : (int) $product->stock);

        $p = PriceService::resolve($product, null, 1);
        $images = $product->images->sortBy('position')->pluck('path')->filter()->values()->all();
        $primary = $product->images->firstWhere('is_primary', true)?->path ?? ($images[0] ?? $product->image);
        if (!$images && $product->image) {
            $images = [$product->image];
        }

        // A lone "Default" variant is the product itself, not a choice
        $variants = $product->variants->where('name', '!=', 'Default')->map(function ($v) use ($product, $stockOf) {
            $vp = PriceService::resolve($product, $v, 1);
            $stock = (int) $stockOf((int) $v->id);

            return [
                'id' => (int) $v->id,
                'name' => $v->name,
                'attributes' => is_string($v->attributes) ? (json_decode($v->attributes, true) ?: null) : $v->attributes,
                'sku' => $v->sku,
                'price' => (float) $vp['base'],
                'currentPrice' => (float) $vp['unit'],
                'stock' => $stock,
                'inStock' => $stock > 0,
            ];
        })->values()->all();
        $stock = $variants ? array_sum(array_column($variants, 'stock')) : (int) $stockOf(null);

        $short = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $product->description))));

        [$methods, $default, $cod] = $this->paymentMethods($companyId);

        return [
            'product' => [
                'id' => (int) $product->id,
                'slug' => $product->slug,
                'name' => $product->name,
                'shortDescription' => $short !== '' ? Str::limit($short, 300) : null,
                'image' => $primary,
                'images' => $images,
                'price' => (float) $p['base'],
                'currentPrice' => (float) $p['unit'],
                'offerPrice' => $p['offer'] !== null ? (float) $p['offer'] : null,
                'offerActive' => (bool) $p['offerActive'],
                'offerEndsAt' => $p['offerActive'] ? $product->offer_ends_at?->format('Y-m-d') : null,
                'stock' => $stock,
                'inStock' => $stock > 0,
                'hasVariants' => (bool) $variants,
                'variants' => $variants,
            ],
            'deliveryOptions' => ShippingMethod::where('company_id', $companyId)
                ->where('is_active', true)
                ->where(fn ($q) => $q->where('type', 'delivery')->orWhereNull('type')->orWhere('type', ''))
                ->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn ($m) => [
                    'id' => (int) $m->id,
                    'name' => $m->name,
                    'description' => $m->description,
                    'price' => (float) $m->price,
                    'estimatedDays' => $m->estimated_days,
                ])->values()->all(),
            'paymentMethods' => $methods,
            'defaultPaymentMethod' => $default,
            'cod' => $cod,
            'store' => $this->storeInfo($companyId),
        ];
    }

    // ------------------------------------------------------------------
    // POST /store/quick-order
    // ------------------------------------------------------------------

    /**
     * @param array $data validated QuickOrderRequest data (camelCase)
     * @throws StorefrontOrderException
     */
    public function place(int $companyId, array $data, ?string $storeHost = null): array
    {
        // Honeypot: real shoppers never see / fill this field
        if (trim((string) ($data['website'] ?? '')) !== '') {
            throw new StorefrontOrderException('Your order could not be placed. Please try again.');
        }

        $phone = BdPhone::normalize($data['phone'] ?? null);
        if (!$phone) {
            $msg = 'Enter a valid Bangladeshi mobile number, e.g. 01712345678.';
            throw new StorefrontOrderException($msg, 422, ['phone' => [$msg]]);
        }

        $phoneKey = "quick-order-phone:{$companyId}:{$phone}";
        if (RateLimiter::tooManyAttempts($phoneKey, self::PHONE_DAILY_LIMIT)) {
            throw new StorefrontOrderException('Too many orders for this phone number today. Please call us to order.', 429);
        }

        $product = Product::where('company_id', $companyId)->where('published', true)->where('visible_online', true)->find((int) $data['productId']);
        if (!$product) {
            throw new StorefrontOrderException('This product is not available.', 422, ['productId' => ['This product is not available.']]);
        }

        $paymentMethod = strtolower(trim((string) $data['paymentMethod']));
        $txn = trim((string) ($data['transactionId'] ?? ''));
        if (in_array($paymentMethod, self::TXN_REQUIRED, true) && $txn === '') {
            $label = UpdatePaymentSettingsRequest::METHODS[$paymentMethod][0] ?? ucfirst($paymentMethod);
            $msg = "Enter the transaction ID of your {$label} payment.";
            throw new StorefrontOrderException($msg, 422, ['transactionId' => [$msg]]);
        }

        $variantId = !empty($data['variantId']) ? (int) $data['variantId'] : null;
        $possibleDuplicate = $this->isPossibleDuplicate($companyId, $phone, (int) $product->id);
        $customer = $this->findOrCreateCustomer($companyId, $phone, $data);
        $attribution = $this->attribution->fromInput($companyId, $data['attribution'] ?? [], $storeHost);
        $linkName = $attribution['campaign_link_id']
            ? CampaignLink::withTrashed()->whereKey($attribution['campaign_link_id'])->value('name') : null;

        $district = trim((string) ($data['district'] ?? ''));
        $area = trim((string) ($data['area'] ?? ''));
        $name = trim((string) $data['name']);

        $placed = $this->checkout->place($companyId, [
            'items' => [['product_id' => (int) $product->id, 'variant_id' => $variantId, 'quantity' => (int) $data['quantity']]],
            'shipping_address' => [
                'name' => $name,
                'phone' => $phone,
                'address' => trim((string) $data['address']),
                'address2' => $area !== '' ? $area : null,
                'city' => $district !== '' ? $district : null,
                'country' => 'Bangladesh',
            ],
            'payment_method' => $paymentMethod,
            'shipping_method_id' => $data['shippingMethodId'] ?? null,
            'transaction_id' => $txn !== '' ? $txn : null,
            'note' => $data['note'] ?? null,
        ], [
            'customer_id' => (int) $customer->id,
            'customer_name' => $name,
            'customer_email' => $customer->email,
            'attribution' => $attribution,
            'is_quick_order' => true,
            'possible_duplicate' => $possibleDuplicate,
            'require_variant' => true,
            'campaign_link_name' => $linkName,
        ]);

        RateLimiter::hit($phoneKey, 86400);

        /** @var Sell $sell */
        $sell = $placed['sell']->refresh();

        return [
            'orderId' => (int) $sell->id,
            'invoiceNo' => $sell->invoice_no,
            'subtotal' => round($placed['subtotal'], 2),
            'discount' => round($placed['discount'], 2),
            'deliveryCharge' => round($placed['shipping_cost'], 2),
            'codFee' => round($placed['cod_fee'], 2),
            'total' => round((float) $sell->amount, 2),
            'paymentMethod' => $paymentMethod,
            'paymentStatus' => $sell->payment_status,
            'status' => $sell->status,
            'trackingUrl' => '/track-order?invoice=' . rawurlencode($sell->invoice_no),
            'paymentUrl' => $placed['payment_url'],
        ];
    }

    // ------------------------------------------------------------------

    private function findProduct(int $companyId, string $slugOrId): ?Product
    {
        $q = Product::with(['images', 'variants'])->where('company_id', $companyId)
            ->where('published', true)->where('visible_online', true);
        if (ctype_digit($slugOrId)) {
            $q->where(fn ($b) => $b->where('id', (int) $slugOrId)->orWhere('slug', $slugOrId));
        } else {
            $q->where('slug', $slugOrId);
        }

        return $q->first();
    }

    /** Same phone ordered the same product within the last 30 minutes. */
    private function isPossibleDuplicate(int $companyId, string $phone, int $productId): bool
    {
        return Sell::where('company_id', $companyId)
            ->whereIn('shipping_phone', BdPhone::variants($phone))
            ->where('created_at', '>=', now()->subMinutes(self::DUPLICATE_WINDOW_MINUTES))
            ->where('status', '!=', 'Cancelled')
            ->whereHas('items', fn ($q) => $q->where('product_id', $productId))
            ->exists();
    }

    private function findOrCreateCustomer(int $companyId, string $phone, array $data): Customer
    {
        $existing = Customer::where('company_id', $companyId)->whereIn('phone', BdPhone::variants($phone))->orderBy('id')->first();
        if ($existing) {
            // Fill gaps only; never overwrite what the shop already has
            $fill = [];
            foreach (['address' => $data['address'] ?? null, 'district' => $data['district'] ?? null, 'area' => $data['area'] ?? null] as $col => $v) {
                if (empty($existing->{$col}) && is_string($v) && trim($v) !== '') {
                    $fill[$col] = mb_substr(trim($v), 0, 255);
                }
            }
            if ($fill) {
                $existing->update($fill);
            }

            return $existing;
        }

        $district = trim((string) ($data['district'] ?? ''));

        return Customer::create([
            'company_id' => $companyId,
            'name' => mb_substr(trim((string) $data['name']), 0, 255),
            'phone' => $phone,
            'address' => mb_substr(trim((string) $data['address']), 0, 255),
            'city' => $district !== '' ? mb_substr($district, 0, 255) : null,
            'district' => $district !== '' ? mb_substr($district, 0, 255) : null,
            'area' => trim((string) ($data['area'] ?? '')) !== '' ? mb_substr(trim((string) $data['area']), 0, 255) : null,
            'country' => 'Bangladesh',
            'notes' => 'Created from a quick order',
        ]);
    }

    /** @return array{0: array, 1: ?string, 2: array} [methods, default slug, cod info] */
    private function paymentMethods(int $companyId): array
    {
        $pay = Setting::where('company_id', $companyId)->first()?->payment_settings ?? [];
        $codLimit = (float) ($pay['cod_limit'] ?? 5000);
        $codFee = max(0, (float) ($pay['cod_fee'] ?? 0));
        $methods = [];

        if (is_array($pay['methods'] ?? null) && $pay['methods']) {
            $labels = UpdatePaymentSettingsRequest::METHODS;
            foreach (StorefrontOrderController::onlineMethodSlugs($pay) as $slug) {
                $f = $pay['methods'][$slug]['fields'] ?? [];
                $methods[] = [
                    'slug' => $slug,
                    'name' => $labels[$slug][0] ?? ucfirst($slug),
                    'type' => $slug === 'cod' ? 'cod' : 'manual',
                    'account' => is_array($f) ? ($f['account'] ?? null) : null,
                    'accountName' => is_array($f) ? ($f['accountName'] ?? null) : null,
                    'bankName' => is_array($f) ? ($f['bankName'] ?? null) : null,
                    'requiresTransactionId' => in_array($slug, self::TXN_REQUIRED, true),
                ];
            }
            $d = $pay['default'] ?? null;
            $slugs = array_column($methods, 'slug');
            $default = $d && in_array($d, $slugs, true) ? $d : (in_array('cod', $slugs, true) ? 'cod' : ($slugs[0] ?? null));
        } else {
            // No Payments settings yet: active legacy payment methods (matched by name at checkout)
            foreach (PaymentMethod::where('company_id', $companyId)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $m) {
                $type = $m->gateway_type ?: 'cod';
                $methods[] = [
                    'slug' => strtolower((string) $m->name),
                    'name' => $m->name,
                    'type' => $type,
                    'account' => null,
                    'accountName' => null,
                    'bankName' => null,
                    'requiresTransactionId' => false,
                ];
            }
            $codSlug = collect($methods)->firstWhere('type', 'cod')['slug'] ?? null;
            $default = $codSlug ?? ($methods[0]['slug'] ?? null);
        }

        $codEnabled = (bool) collect($methods)->firstWhere('type', 'cod');

        return [$methods, $default, ['enabled' => $codEnabled, 'limit' => $codLimit, 'fee' => $codFee]];
    }

    private function storeInfo(int $companyId): array
    {
        $setting = Setting::where('company_id', $companyId)->first();
        $business = $setting?->business_settings ?? [];
        $general = $setting?->general_settings ?? [];
        $o = is_array($business['onlineStore'] ?? null) ? $business['onlineStore'] : [];
        $str = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => $str($o['name'] ?? null) ?? $str($business['businessName'] ?? null) ?? $str($general['storeName'] ?? null),
            'phone' => $str($general['storePhone'] ?? null),
            'whatsapp' => $str($o['whatsappNumber'] ?? null) ?? $str($o['social']['whatsapp'] ?? null),
            'logoUrl' => $setting?->logo_url,
            'currency' => CompanySettings::where('company_id', $companyId)->value('currency') ?: 'BDT',
        ];
    }
}
