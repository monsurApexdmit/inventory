<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\PaymentMethod;
use App\Models\ShippingMethod;
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
            ->where('company_id', $companyId)
            ->where('published', true);

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

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

    // GET /api/store/products/{id}?company_id=11
    public function product(Request $request, int $id): JsonResponse
    {
        $companyId = $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $product = Product::with(['category', 'images', 'variants', 'attributes'])
            ->where('company_id', $companyId)
            ->where('published', true)
            ->find($id);

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

        $methods = PaymentMethod::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn($m) => [
                'id'          => $m->id,
                'name'        => $m->name,
                'description' => $m->description,
                'icon'        => $m->icon,
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

    private function formatProduct(Product $product): array
    {
        $primaryImage = $product->images->firstWhere('is_primary', true)?->path
            ?? $product->images->first()?->path
            ?? $product->image;

        return [
            'id'            => $product->id,
            'name'          => $product->name,
            'description'   => $product->description,
            'price'         => $product->price,
            'sale_price'    => $product->sale_price,
            'sku'           => $product->sku,
            'stock'         => $product->stock,
            'image'         => $primaryImage,
            'images'        => $product->images->sortBy('position')->pluck('path')->values(),
            'category_id'   => $product->category_id,
            'category_name' => $product->category?->category_name,
            'variants'      => $product->variants->map(fn($v) => [
                'id'         => $v->id,
                'name'       => $v->name,
                'price'      => $v->price,
                'sale_price' => $v->sale_price,
                'stock'      => $v->stock,
                'sku'        => $v->sku,
                'attributes' => $v->attributes,
            ])->values(),
        ];
    }
}
