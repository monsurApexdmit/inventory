<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\TailorCustomer;
use App\Services\Tailor\TailorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TailorController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TailorService $tailor) {}

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    private function userId(Request $request): int
    {
        return (int) $request->attributes->get('auth_user_id', 0);
    }

    // ─── Fabrics ─────────────────────────────────────────────────────────────

    public function fabricIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->tailor->listFabrics($this->companyId($request), $request->query());
            return $this->success($data, 'Fabrics retrieved');
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve fabrics', 500);
        }
    }

    public function fabricStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'fabric_type'    => 'nullable|string|max:100',
            'color'          => 'nullable|string|max:100',
            'pattern'        => 'nullable|string|max:100',
            'unit'           => 'nullable|in:goj,gaj',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price'  => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|numeric|min:0',
            'vendor_id'      => 'nullable|integer|exists:vendors,id',
            'image_path'     => 'nullable|string',
            'status'         => 'nullable|in:active,inactive',
        ]);
        try {
            $fabric = $this->tailor->createFabric($this->companyId($request), $validated);
            return $this->success($fabric, 'Fabric created', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create fabric', 500);
        }
    }

    public function fabricUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'fabric_type'    => 'nullable|string|max:100',
            'color'          => 'nullable|string|max:100',
            'pattern'        => 'nullable|string|max:100',
            'unit'           => 'nullable|in:goj,gaj',
            'purchase_price' => 'nullable|numeric|min:0',
            'selling_price'  => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|numeric|min:0',
            'vendor_id'      => 'nullable|integer|exists:vendors,id',
            'image_path'     => 'nullable|string',
            'status'         => 'nullable|in:active,inactive',
        ]);
        try {
            $fabric = $this->tailor->updateFabric($this->companyId($request), $id, $validated);
            return $this->success($fabric, 'Fabric updated');
        } catch (\Exception $e) {
            return $this->error('Failed to update fabric', 500);
        }
    }

    public function fabricDestroy(Request $request, int $id): JsonResponse
    {
        try {
            $this->tailor->deleteFabric($this->companyId($request), $id);
            return $this->success(null, 'Fabric deleted');
        } catch (\Exception $e) {
            return $this->error('Failed to delete fabric', 500);
        }
    }

    // ─── Customers ───────────────────────────────────────────────────────────

    public function customerIndex(Request $request): JsonResponse
    {
        return $this->success($this->tailor->listCustomers($this->companyId($request), $request->query()), 'Customers retrieved');
    }

    public function customerFindByPhone(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string']);
        $customer = $this->tailor->findCustomerByPhone($this->companyId($request), $request->phone);
        return $customer
            ? $this->success($customer, 'Customer found')
            : $this->error('Customer not found', 404);
    }

    public function customerStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'phone'   => 'required|string|max:30',
            'address' => 'nullable|string',
            'notes'   => 'nullable|string',
        ]);
        $customer = $this->tailor->upsertCustomer($this->companyId($request), $validated);
        return $this->success($customer, 'Customer saved', 201);
    }

    public function customerShow(Request $request, int $id): JsonResponse
    {
        $customer = TailorCustomer::where('company_id', $this->companyId($request))->findOrFail($id);
        return $this->success($customer, 'Customer retrieved');
    }

    public function customerUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'sometimes|string|max:255',
            'phone'   => 'sometimes|string|max:30',
            'address' => 'nullable|string',
            'notes'   => 'nullable|string',
        ]);
        $customer = $this->tailor->updateCustomer($this->companyId($request), $id, $validated);
        return $this->success($customer, 'Customer updated');
    }

    public function customerDelete(Request $request, int $id): JsonResponse
    {
        $this->tailor->deleteCustomer($this->companyId($request), $id);
        return $this->success(null, 'Customer deleted');
    }

    public function customerOrders(Request $request, int $id): JsonResponse
    {
        $orders = $this->tailor->ordersByCustomer($this->companyId($request), $id);
        return $this->success($orders, 'Customer orders retrieved');
    }

    // ─── Measurements ────────────────────────────────────────────────────────

    public function measurementIndex(Request $request): JsonResponse
    {
        return $this->success(
            $this->tailor->listMeasurements($this->companyId($request), $request->query()),
            'Measurements retrieved'
        );
    }

    public function measurementByCustomer(Request $request, int $customerId): JsonResponse
    {
        return $this->success(
            $this->tailor->measurementsByCustomer($this->companyId($request), $customerId),
            'Measurements retrieved'
        );
    }

    public function measurementStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'   => 'required|integer',
            'product_type'  => 'required|string|max:100',
            'chest'         => 'nullable|numeric',
            'waist'         => 'nullable|numeric',
            'hip'           => 'nullable|numeric',
            'shoulder'      => 'nullable|numeric',
            'sleeve'        => 'nullable|numeric',
            'length'        => 'nullable|numeric',
            'neck'          => 'nullable|numeric',
            'bottom_length' => 'nullable|numeric',
            'inseam'        => 'nullable|numeric',
            'pajama_waist'  => 'nullable|numeric',
            'pajama_length' => 'nullable|numeric',
            'custom_fields' => 'nullable|array',
            'notes'         => 'nullable|string',
            'measured_at'   => 'required|date',
        ]);
        $m = $this->tailor->createMeasurement($this->companyId($request), $validated);
        return $this->success($m, 'Measurement saved', 201);
    }

    public function measurementUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'product_type'  => 'sometimes|string|max:100',
            'chest'         => 'nullable|numeric',
            'waist'         => 'nullable|numeric',
            'hip'           => 'nullable|numeric',
            'shoulder'      => 'nullable|numeric',
            'sleeve'        => 'nullable|numeric',
            'length'        => 'nullable|numeric',
            'neck'          => 'nullable|numeric',
            'bottom_length' => 'nullable|numeric',
            'inseam'        => 'nullable|numeric',
            'pajama_waist'  => 'nullable|numeric',
            'pajama_length' => 'nullable|numeric',
            'custom_fields' => 'nullable|array',
            'notes'         => 'nullable|string',
            'measured_at'   => 'sometimes|date',
        ]);
        $m = $this->tailor->updateMeasurement($this->companyId($request), $id, $validated);
        return $this->success($m, 'Measurement updated');
    }

    // ─── Dorjis ──────────────────────────────────────────────────────────────

    public function dorjiIndex(Request $request): JsonResponse
    {
        return $this->success($this->tailor->listDorjis($this->companyId($request), $request->query()), 'Dorjis retrieved');
    }

    public function dorjiStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:255',
            'phone'            => 'required|string|max:30',
            'address'          => 'nullable|string',
            'speciality'       => 'nullable|array',
            'commission_type'  => 'nullable|in:fixed,percentage',
            'commission_value' => 'nullable|numeric|min:0',
            'status'           => 'nullable|in:active,inactive',
            'notes'            => 'nullable|string',
        ]);
        $d = $this->tailor->createDorji($this->companyId($request), $validated);
        return $this->success($d, 'Dorji created', 201);
    }

    public function dorjiUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name'             => 'sometimes|string|max:255',
            'phone'            => 'sometimes|string|max:30',
            'address'          => 'nullable|string',
            'speciality'       => 'nullable|array',
            'commission_type'  => 'nullable|in:fixed,percentage',
            'commission_value' => 'nullable|numeric|min:0',
            'status'           => 'nullable|in:active,inactive',
            'notes'            => 'nullable|string',
        ]);
        $d = $this->tailor->updateDorji($this->companyId($request), $id, $validated);
        return $this->success($d, 'Dorji updated');
    }

    public function dorjiDestroy(Request $request, int $id): JsonResponse
    {
        $this->tailor->deleteDorji($this->companyId($request), $id);
        return $this->success(null, 'Dorji deleted');
    }

    // ─── Orders ──────────────────────────────────────────────────────────────

    public function orderIndex(Request $request): JsonResponse
    {
        try {
            $data = $this->tailor->listOrders($this->companyId($request), $request->query());
            return $this->success($data['data'], 'Orders retrieved', 200, ['pagination' => $data['pagination']]);
        } catch (\Exception $e) {
            return $this->error('Failed to retrieve orders', 500);
        }
    }

    public function orderStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_name'          => 'required|string|max:255',
            'customer_phone'         => 'required|string|max:30',
            'customer_address'       => 'nullable|string',
            'order_date'             => 'required|date',
            'delivery_date'          => 'nullable|date',
            'stitching_charge'       => 'nullable|numeric|min:0',
            'extra_charge'           => 'nullable|numeric|min:0',
            'discount'               => 'nullable|numeric|min:0',
            'advance_payment'        => 'nullable|numeric|min:0',
            'advance_payment_method' => 'nullable|string',
            'order_status'           => 'nullable|string',
            'notes'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.product_type'   => 'required|string',
            'items.*.fabric_id'      => 'nullable|integer',
            'items.*.fabric_quantity'=> 'nullable|numeric|min:0',
            'items.*.fabric_unit_price' => 'nullable|numeric|min:0',
            'items.*.measurement_id' => 'nullable|integer',
            'items.*.notes'          => 'nullable|string',
        ]);
        try {
            $order = $this->tailor->createOrder($this->companyId($request), $validated, $this->userId($request));
            return $this->success($order, 'Order created', 201);
        } catch (\Exception $e) {
            \Log::error('Tailor order create failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to create order', 500);
        }
    }

    public function orderShow(Request $request, int $id): JsonResponse
    {
        try {
            $order = $this->tailor->getOrder($this->companyId($request), $id);
            return $this->success($order, 'Order retrieved');
        } catch (\Exception $e) {
            return $this->error('Order not found', 404);
        }
    }

    public function orderUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'delivery_date'    => 'nullable|date',
            'stitching_charge' => 'nullable|numeric|min:0',
            'extra_charge'     => 'nullable|numeric|min:0',
            'discount'         => 'nullable|numeric|min:0',
            'notes'            => 'nullable|string',
        ]);
        try {
            $order = $this->tailor->updateOrder($this->companyId($request), $id, $validated);
            return $this->success($order, 'Order updated');
        } catch (\Exception $e) {
            return $this->error('Failed to update order', 500);
        }
    }

    public function orderUpdateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,measurement_taken,assigned,cutting,stitching,ready,delivered,cancelled',
            'note'   => 'nullable|string',
        ]);
        try {
            $order = $this->tailor->updateOrderStatus(
                $this->companyId($request), $id,
                $validated['status'], $validated['note'] ?? null,
                $this->userId($request)
            );
            return $this->success($order, 'Order status updated');
        } catch (\Exception $e) {
            return $this->error('Failed to update status', 500);
        }
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function assignmentIndex(Request $request): JsonResponse
    {
        return $this->success($this->tailor->listAssignments($this->companyId($request), $request->query()), 'Assignments retrieved');
    }

    public function assignmentStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id'            => 'required|integer',
            'dorji_id'            => 'required|integer',
            'assigned_date'       => 'required|date',
            'expected_completion' => 'nullable|date',
            'dorji_charge'        => 'nullable|numeric|min:0',
            'work_status'         => 'nullable|in:assigned,in_progress,completed,returned',
            'admin_notes'         => 'nullable|string',
        ]);
        try {
            $a = $this->tailor->createAssignment($this->companyId($request), $validated);
            return $this->success($a, 'Assignment created', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to create assignment', 500);
        }
    }

    public function assignmentUpdate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'expected_completion' => 'nullable|date',
            'dorji_charge'        => 'nullable|numeric|min:0',
            'work_status'         => 'nullable|in:assigned,in_progress,completed,returned',
            'admin_notes'         => 'nullable|string',
        ]);
        $a = $this->tailor->updateAssignment($this->companyId($request), $id, $validated);
        return $this->success($a, 'Assignment updated');
    }

    // ─── Payments ────────────────────────────────────────────────────────────

    public function paymentIndex(Request $request): JsonResponse
    {
        return $this->success($this->tailor->listPayments($this->companyId($request), $request->query()), 'Payments retrieved');
    }

    public function paymentStore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id'       => 'required|integer',
            'amount'         => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string',
            'payment_date'   => 'required|date',
            'reference'      => 'nullable|string',
            'notes'          => 'nullable|string',
        ]);
        try {
            $p = $this->tailor->createPayment($this->companyId($request), $validated);
            return $this->success($p, 'Payment recorded', 201);
        } catch (\Exception $e) {
            return $this->error('Failed to record payment', 500);
        }
    }

    // ─── Reports & Dashboard ─────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        return $this->success($this->tailor->dashboardStats($this->companyId($request)), 'Dashboard stats retrieved');
    }

    public function reportOrders(Request $request): JsonResponse
    {
        return $this->success($this->tailor->ordersReport($this->companyId($request), $request->query()), 'Orders report retrieved');
    }

    public function reportFabrics(Request $request): JsonResponse
    {
        return $this->success($this->tailor->fabricsReport($this->companyId($request)), 'Fabrics report retrieved');
    }

    public function reportDorjis(Request $request): JsonResponse
    {
        return $this->success($this->tailor->dorjisReport($this->companyId($request), $request->query()), 'Dorjis report retrieved');
    }

    // ─── Public Order Tracking (no auth) ─────────────────────────────────────

    public function publicTrack(Request $request, string $token): JsonResponse
    {
        $order = \App\Models\TailorOrder::with(['customer', 'items', 'statusLogs'])
            ->where('tracking_token', strtoupper($token))
            ->first();

        if (!$order) {
            return $this->error('Order not found', 404);
        }

        return $this->success([
            'order_number'   => $order->order_number,
            'order_status'   => $order->order_status,
            'payment_status' => $order->payment_status,
            'order_date'     => $order->order_date?->toDateString(),
            'delivery_date'  => $order->delivery_date?->toDateString(),
            'total_amount'   => $order->total_amount,
            'paid_amount'    => $order->paid_amount,
            'due_amount'     => $order->due_amount,
            'customer_name'  => $order->customer?->name,
            'notes'          => $order->notes,
            'status_logs'    => $order->statusLogs->map(fn($l) => [
                'from'       => $l->from_status,
                'to'         => $l->to_status,
                'note'       => $l->note,
                'date'       => $l->created_at?->toDateTimeString(),
            ]),
        ], 'Order tracking info');
    }
}
