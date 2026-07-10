<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StorefrontProductReviewController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request, string $slugOrId): JsonResponse
    {
        $companyId = (int) $request->query('company_id');

        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $query = Product::query()
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

        $perPage = min((int) $request->query('per_page', 10), 50);
        $reviews = ProductReview::query()
            ->where('company_id', $companyId)
            ->where('product_id', $product->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $this->buildSummary($companyId, $product->id),
                'reviews' => $reviews->getCollection()->map(fn(ProductReview $review) => $this->formatReview($review))->values(),
            ],
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    public function store(Request $request, string $slugOrId): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|min:5|max:2000',
        ]);

        $query = Product::query()
            ->where('company_id', $customer->company_id)
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

        $review = ProductReview::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'customer_id' => $customer->id,
            ],
            [
                'company_id' => $customer->company_id,
                'rating' => (int) $request->rating,
                'comment' => trim((string) $request->comment),
                'verified_purchase' => $this->hasPurchasedProduct($customer->company_id, $customer->id, $product->id),
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
            ]
        );
        $isNewReview = $review->wasRecentlyCreated;
        $freshReview = $review->fresh();

        if ($isNewReview && $freshReview) {
            $this->notificationService->notifyProductReviewReceived(
                companyId: $customer->company_id,
                productId: $product->id,
                reviewId: $freshReview->id,
                productName: $product->name,
                customerName: $customer->name,
                rating: (int) $freshReview->rating,
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Review saved successfully',
            'data' => [
                'review' => $this->formatReview($freshReview ?? $review),
                'summary' => $this->buildSummary($customer->company_id, $product->id),
            ],
        ], 201);
    }

    private function buildSummary(int $companyId, int $productId): array
    {
        $baseQuery = ProductReview::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId);

        $reviewCount = (clone $baseQuery)->count();
        $average = $reviewCount > 0 ? round((float) (clone $baseQuery)->avg('rating'), 1) : 0.0;

        $distribution = collect([5, 4, 3, 2, 1])->map(function (int $stars) use ($baseQuery, $reviewCount) {
            $count = (clone $baseQuery)->where('rating', $stars)->count();

            return [
                'stars' => $stars,
                'count' => $count,
                'percent' => $reviewCount > 0 ? (int) round(($count / $reviewCount) * 100) : 0,
            ];
        })->values();

        return [
            'average_rating' => $average,
            'review_count' => $reviewCount,
            'distribution' => $distribution,
        ];
    }

    private function formatReview(ProductReview $review): array
    {
        return [
            'id' => $review->id,
            'product_id' => $review->product_id,
            'customer_id' => $review->customer_id,
            'customer_name' => $review->customer_name ?: $review->customer?->name ?: 'Anonymous',
            'rating' => $review->rating,
            'comment' => $review->comment,
            'verified_purchase' => $review->verified_purchase,
            'created_at' => $review->created_at?->toISOString(),
            'reply' => $review->reply_body ? [
                'body' => $review->reply_body,
                'author_name' => $review->reply_author_name ?: 'Store Team',
                'replied_at' => $review->replied_at?->toISOString(),
            ] : null,
        ];
    }

    private function hasPurchasedProduct(int $companyId, int $customerId, int $productId): bool
    {
        return DB::table('order_items as oi')
            ->join('sells as s', 's.id', '=', 'oi.sell_id')
            ->where('s.company_id', $companyId)
            ->where('s.customer_id', $customerId)
            ->where('oi.product_id', $productId)
            ->exists();
    }
}
