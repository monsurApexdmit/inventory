<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class WishlistController extends Controller
{
    // GET /api/store/wishlist
    public function index(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $items = Wishlist::where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->with(['product.images', 'product.category', 'product.variants'])
            ->orderByDesc('created_at')
            ->get();

        $data = $items->map(fn($w) => [
            'wishlist_id' => $w->id,
            'product_id'  => $w->product_id,
            'added_at'    => $w->created_at,
            'product'     => $w->product ? $this->formatProduct($w->product) : null,
        ])->filter(fn($item) => $item['product'] !== null)->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'total'   => $data->count(),
        ]);
    }

    // POST /api/store/wishlist  { product_id: int }
    public function store(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $request->validate(['product_id' => 'required|integer']);

        $product = Product::where('company_id', $customer->company_id)
            ->where('published', true)
            ->find($request->product_id);

        if (!$product) {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        // Upsert — silently ignore duplicate
        $item = Wishlist::firstOrCreate([
            'customer_id' => $customer->id,
            'company_id'  => $customer->company_id,
            'product_id'  => $product->id,
        ]);

        return response()->json([
            'success'     => true,
            'message'     => 'Added to wishlist',
            'wishlist_id' => $item->id,
            'product_id'  => $product->id,
        ], 201);
    }

    // DELETE /api/store/wishlist/{productId}
    public function destroy(Request $request, int $productId): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $deleted = Wishlist::where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->where('product_id', $productId)
            ->delete();

        if (!$deleted) {
            return response()->json(['success' => false, 'message' => 'Item not in wishlist'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Removed from wishlist']);
    }

    // DELETE /api/store/wishlist  (clear all)
    public function clear(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        Wishlist::where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->delete();

        return response()->json(['success' => true, 'message' => 'Wishlist cleared']);
    }

    // GET /api/store/wishlist/check/{productId}
    public function check(Request $request, int $productId): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $exists = Wishlist::where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->where('product_id', $productId)
            ->exists();

        return response()->json(['success' => true, 'in_wishlist' => $exists]);
    }

    // GET /api/store/wishlist/ids  — returns just product IDs for quick sync
    public function ids(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $ids = Wishlist::where('customer_id', $customer->id)
            ->where('company_id', $customer->company_id)
            ->pluck('product_id');

        return response()->json(['success' => true, 'data' => $ids]);
    }

    private function formatProduct(Product $product): array
    {
        $imageBase    = rtrim(config('app.url'), '/');
        $primaryImage = $product->images->firstWhere('is_primary', true)?->path
            ?? $product->images->first()?->path;

        return [
            'id'            => $product->id,
            'name'          => $product->name,
            'slug'          => $product->slug ?? str($product->name)->slug()->value(),
            'price'         => $product->price,
            'sale_price'    => $product->sale_price,
            'stock'         => $product->stock,
            'image'         => $primaryImage ? "{$imageBase}/storage/{$primaryImage}" : null,
            'images'        => $product->images->sortBy('position')->pluck('path')
                ->map(fn($p) => "{$imageBase}/storage/{$p}")->values(),
            'category_id'   => $product->category_id,
            'category_name' => $product->category?->category_name,
            'variants'      => $product->variants->map(fn($v) => [
                'id'         => $v->id,
                'name'       => $v->name,
                'price'      => $v->price,
                'sale_price' => $v->sale_price,
                'stock'      => $v->stock,
            ])->values(),
        ];
    }
}
