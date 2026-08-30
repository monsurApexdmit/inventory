<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\TryOn\TryOnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TryOnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TryOnService $tryOn)
    {
    }

    /**
     * POST /api/store/try-on
     * Composites the uploaded photo with a garment image. Public (storefront),
     * client-only — nothing is stored.
     */
    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'photo'      => 'required|image|max:8192',
            'garmentUrl' => 'required|url',
            'category'   => 'nullable|string|max:50',
        ]);

        $result = $this->tryOn->generate(
            $request->file('photo'),
            $request->input('garmentUrl'),
            $request->input('category'),
        );

        return $this->success($result, 'Try-on generated');
    }
}
