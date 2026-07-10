<?php

namespace App\Http\Controllers\Api\V1\Product;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\ProductReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductReviewReplyController extends Controller
{
    use ApiResponse;

    public function store(Request $request, int $productId, int $reviewId): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $request->validate([
            'reply' => 'required|string|min:2|max:2000',
        ]);

        $review = ProductReview::query()
            ->where('company_id', $companyId)
            ->where('product_id', $productId)
            ->find($reviewId);

        if (!$review) {
            return $this->error('Review not found', 404);
        }

        $review->update([
            'reply_body' => trim((string) $request->reply),
            'reply_author_name' => $request->user()?->full_name ?? 'Store Team',
            'replied_by' => (int) $request->attributes->get('auth_user_id'),
            'replied_at' => now(),
        ]);

        return $this->success([
            'id' => $review->id,
            'reply' => [
                'body' => $review->reply_body,
                'author_name' => $review->reply_author_name,
                'replied_at' => $review->replied_at?->toISOString(),
            ],
        ], 'Reply saved successfully');
    }
}
