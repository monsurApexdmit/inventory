<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\QuickOrderRequest;
use App\Services\Marketing\CampaignLinkService;
use App\Services\Storefront\QuickOrderService;
use App\Services\Storefront\StorefrontOrderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public storefront endpoints for orders from social ads:
 *  GET  /api/store/l/{code}                    short link → target + click
 *  GET  /api/store/quick-order/{slugOrId}      quick order form data
 *  POST /api/store/quick-order                 place a quick order
 */
class StorefrontMarketingController extends Controller
{
    private const ERROR_KEYS = ['variant_id' => 'variantId', 'shipping_method_id' => 'shippingMethodId', 'payment_method' => 'paymentMethod'];

    public function __construct(
        private readonly CampaignLinkService $links,
        private readonly QuickOrderService $quickOrders,
    ) {
    }

    private function companyId(Request $request): int
    {
        return (int) ($request->query('company_id') ?: $request->input('company_id'));
    }

    public function link(Request $request, string $code): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }
        $ref = $request->query('ref');
        $referrer = is_string($ref) && $ref !== '' ? $ref : $request->headers->get('referer');

        $data = $this->links->resolveClick($companyId, $code, $request->ip(), $request->userAgent(), $referrer, $request->getHost());

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function quickOrderForm(Request $request, string $slugOrId): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }
        try {
            return response()->json(['success' => true, 'data' => $this->quickOrders->show($companyId, $slugOrId)]);
        } catch (StorefrontOrderException $e) {
            return $e->toResponse();
        }
    }

    public function quickOrder(QuickOrderRequest $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        if (!$companyId) {
            return response()->json(['success' => false, 'message' => 'Store could not be resolved'], 400);
        }
        try {
            $input = $request->validated();
            $key = $request->header('Idempotency-Key') ?: $request->input('idempotencyKey');
            $input['idempotencyKey'] = is_string($key) && $key !== '' ? mb_substr($key, 0, 100) : null;
            $data = $this->quickOrders->place($companyId, $input, $request->getHost());
        } catch (StorefrontOrderException $e) {
            return $e->toResponse(self::ERROR_KEYS);
        }

        return response()->json(['success' => true, 'message' => 'Order placed', 'data' => $data], 201);
    }
}
