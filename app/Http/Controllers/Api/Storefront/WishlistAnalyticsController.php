<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Wishlist;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WishlistAnalyticsController extends Controller
{
    // GET /api/wishlists/analytics
    public function analytics(Request $request): JsonResponse
    {
        $companyId = $request->query('company_id');

        $totalItems = Wishlist::where('company_id', $companyId)->count();

        $uniqueCustomers = Wishlist::where('company_id', $companyId)
            ->distinct('customer_id')
            ->count('customer_id');

        $uniqueProducts = Wishlist::where('company_id', $companyId)
            ->distinct('product_id')
            ->count('product_id');

        // Top 10 most wishlisted products
        $topProducts = Wishlist::where('wishlists.company_id', $companyId)
            ->join('products', 'wishlists.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->selectRaw('wishlists.product_id, products.name, products.price, products.sale_price, categories.category_name, COUNT(*) as wishlist_count')
            ->groupBy('wishlists.product_id', 'products.name', 'products.price', 'products.sale_price', 'categories.category_name')
            ->orderByDesc('wishlist_count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'productId'    => $row->product_id,
                'name'         => $row->name,
                'price'        => $row->price,
                'salePrice'    => $row->sale_price,
                'categoryName' => $row->category_name,
                'wishlistCount' => $row->wishlist_count,
            ]);

        // Customers with most wishlist items (top 10)
        $topCustomers = Wishlist::where('wishlists.company_id', $companyId)
            ->join('customers', 'wishlists.customer_id', '=', 'customers.id')
            ->selectRaw('wishlists.customer_id, customers.name, customers.email, COUNT(*) as wishlist_count')
            ->groupBy('wishlists.customer_id', 'customers.name', 'customers.email')
            ->orderByDesc('wishlist_count')
            ->limit(10)
            ->get()
            ->map(fn($row) => [
                'customerId'   => $row->customer_id,
                'name'         => $row->name,
                'email'        => $row->email,
                'wishlistCount' => $row->wishlist_count,
            ]);

        // Daily adds for the last 30 days
        $dailyTrend = Wishlist::where('company_id', $companyId)
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => ['date' => $row->date, 'count' => $row->count]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => [
                    'totalItems'      => $totalItems,
                    'uniqueCustomers' => $uniqueCustomers,
                    'uniqueProducts'  => $uniqueProducts,
                ],
                'topProducts'  => $topProducts,
                'topCustomers' => $topCustomers,
                'dailyTrend'   => $dailyTrend,
            ],
        ]);
    }
}
