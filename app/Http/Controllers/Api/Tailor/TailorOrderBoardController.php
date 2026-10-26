<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Tailor\TailorOrderQueryService;
use App\Services\Tailor\TailorOrderWriteService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints behind the "Tailor orders" and "New tailor order" screens.
 * The legacy TailorController order/payment routes are untouched.
 */
class TailorOrderBoardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TailorOrderQueryService $query,
        private readonly TailorOrderWriteService $write,
    ) {}

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('auth_user_id', 0);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status'         => ['nullable', Rule::in(['all', 'open', ...TailorOrderWriteService::STATUSES])],
            'delivery'       => 'nullable|in:overdue,today,week',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'delivery_from'  => 'nullable|date',
            'delivery_to'    => 'nullable|date',
            'page'           => 'nullable|integer|min:1',
            'limit'          => 'nullable|integer|min:1|max:100',
        ]);
        return $this->success($this->query->board($this->companyId($request), $request->query()), 'Tailor orders retrieved');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            return $this->success($this->query->detail($this->companyId($request), $id), 'Order retrieved');
        } catch (ModelNotFoundException) {
            return $this->error('Order not found', 404);
        }
    }

    public function formOptions(Request $request): JsonResponse
    {
        return $this->success($this->query->formOptions($this->companyId($request)), 'Order form options retrieved');
    }

    public function place(Request $request): JsonResponse
    {
        $v = $request->validate([
            'customer_id'            => 'nullable|integer',
            'customer_name'          => 'nullable|string|max:255',
            'customer_phone'         => 'nullable|string|max:30',
            'customer_address'       => 'nullable|string',
            'order_date'             => 'required|date',
            'delivery_date'          => 'required|date',
            'is_urgent'              => 'nullable|boolean',
            'dorji_id'               => 'nullable|integer',
            'notes'                  => 'nullable|string',
            'extra_charge'           => 'nullable|numeric|min:0',
            'discount'               => 'nullable|numeric|min:0',
            'advance_payment'        => 'nullable|numeric|min:0',
            'advance_payment_method' => 'nullable|string|max:30',
            'items'                  => 'required|array|min:1',
            'items.*.product_type'   => 'required|string|max:100',
            'items.*.quantity'       => 'required|integer|min:1',
            'items.*.stitching_price'=> 'required|numeric|min:0',
            'items.*.fabric_source'  => 'nullable|in:own,shop',
            'items.*.fabric_id'      => 'nullable|integer',
            'items.*.fabric_quantity'=> 'nullable|numeric|min:0',
            'items.*.fabric_unit_price' => 'nullable|numeric|min:0',
            'items.*.own_fabric_note'=> 'nullable|string',
            'items.*.measurement_id' => 'nullable|integer',
            'items.*.style_options'  => 'nullable|array',
            'items.*.style_options.*'=> 'string|max:100',
            'items.*.notes'          => 'nullable|string',
        ]);
        $order = $this->write->place($this->companyId($request), $v, $this->userId($request));
        return $this->success($order, 'Order created', 201);
    }

    public function transition(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'status'      => ['required', Rule::in(TailorOrderWriteService::STATUSES)],
            'note'        => 'nullable|string|max:500',
            'collect_due' => 'nullable|boolean',
        ]);
        try {
            $order = $this->write->transition($this->companyId($request), $id, $v['status'], $v['note'] ?? null,
                (bool) ($v['collect_due'] ?? false), $this->userId($request));
            return $this->success($order, 'Order status updated');
        } catch (ModelNotFoundException) {
            return $this->error('Order not found', 404);
        }
    }

    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:30',
            'payment_date'   => 'required|date',
            'reference'      => 'nullable|string|max:255',
            'notes'          => 'nullable|string',
        ]);
        try {
            $p = $this->write->recordPayment($this->companyId($request), $id, $v, $this->userId($request));
            return $this->success($p, 'Payment recorded', 201);
        } catch (ModelNotFoundException) {
            return $this->error('Order not found', 404);
        }
    }
}
