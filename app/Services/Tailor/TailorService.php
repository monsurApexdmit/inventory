<?php

namespace App\Services\Tailor;

use App\Models\TailorAssignment;
use App\Models\TailorCustomer;
use App\Models\TailorDorji;
use App\Models\TailorFabric;
use App\Models\TailorMeasurement;
use App\Models\TailorOrder;
use App\Models\TailorOrderItem;
use App\Models\TailorPayment;
use App\Models\TailorStatusLog;
use Illuminate\Support\Facades\DB;

class TailorService
{
    // ─── Fabrics ─────────────────────────────────────────────────────────────

    public function listFabrics(int $companyId, array $filters = []): array
    {
        $q = TailorFabric::where('company_id', $companyId);
        if (!empty($filters['status']))      $q->where('status', $filters['status']);
        if (!empty($filters['fabric_type'])) $q->where('fabric_type', $filters['fabric_type']);
        if (!empty($filters['search']))      $q->where('name', 'like', '%' . $filters['search'] . '%');
        $limit = min((int)($filters['limit'] ?? 50), 200);
        $page  = max(1, (int)($filters['page'] ?? 1));
        return $q->with('vendor')->orderBy('name')->paginate($limit, ['*'], 'page', $page)->toArray();
    }

    public function createFabric(int $companyId, array $data): TailorFabric
    {
        return TailorFabric::create(array_merge($data, ['company_id' => $companyId]));
    }

    public function updateFabric(int $companyId, int $id, array $data): TailorFabric
    {
        $fabric = TailorFabric::where('company_id', $companyId)->findOrFail($id);
        $fabric->update($data);
        return $fabric->fresh();
    }

    public function deleteFabric(int $companyId, int $id): void
    {
        TailorFabric::where('company_id', $companyId)->findOrFail($id)->delete();
    }

    // ─── Customers ───────────────────────────────────────────────────────────

    public function listCustomers(int $companyId, array $filters = []): array
    {
        $q = TailorCustomer::where('company_id', $companyId);
        if (!empty($filters['search'])) {
            $q->where(function ($q2) use ($filters) {
                $q2->where('name', 'like', '%' . $filters['search'] . '%')
                   ->orWhere('phone', 'like', '%' . $filters['search'] . '%');
            });
        }
        return $q->orderBy('name')->get()->toArray();
    }

    public function findCustomerByPhone(int $companyId, string $phone): ?TailorCustomer
    {
        return TailorCustomer::where('company_id', $companyId)
            ->where('phone', $phone)->first();
    }

    public function upsertCustomer(int $companyId, array $data): TailorCustomer
    {
        return TailorCustomer::updateOrCreate(
            ['company_id' => $companyId, 'phone' => $data['phone']],
            array_merge($data, ['company_id' => $companyId])
        );
    }

    public function updateCustomer(int $companyId, int $id, array $data): TailorCustomer
    {
        $customer = TailorCustomer::where('company_id', $companyId)->findOrFail($id);
        $customer->update($data);
        return $customer->fresh();
    }

    public function deleteCustomer(int $companyId, int $id): void
    {
        TailorCustomer::where('company_id', $companyId)->findOrFail($id)->delete();
    }

    public function ordersByCustomer(int $companyId, int $customerId): array
    {
        return TailorOrder::where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->with(['customer', 'items'])
            ->orderByDesc('order_date')
            ->get()->toArray();
    }

    // ─── Measurements ────────────────────────────────────────────────────────

    public function listMeasurements(int $companyId, array $filters = []): array
    {
        $q = TailorMeasurement::where('company_id', $companyId)->with('customer');
        if (!empty($filters['customer_id'])) {
            $q->where('customer_id', $filters['customer_id']);
        }
        if (!empty($filters['search'])) {
            $q->whereHas('customer', fn($q2) => $q2->where('name', 'like', '%' . $filters['search'] . '%'));
        }
        return $q->orderByDesc('measured_at')->get()->toArray();
    }

    public function measurementsByCustomer(int $companyId, int $customerId): array
    {
        return TailorMeasurement::where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->orderByDesc('measured_at')
            ->get()->toArray();
    }

    public function createMeasurement(int $companyId, array $data): TailorMeasurement
    {
        return TailorMeasurement::create(array_merge($data, ['company_id' => $companyId]));
    }

    public function updateMeasurement(int $companyId, int $id, array $data): TailorMeasurement
    {
        $m = TailorMeasurement::where('company_id', $companyId)->findOrFail($id);
        $m->update($data);
        return $m->fresh();
    }

    // ─── Dorjis ──────────────────────────────────────────────────────────────

    public function listDorjis(int $companyId, array $filters = []): array
    {
        $q = TailorDorji::where('company_id', $companyId);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        return $q->withCount(['assignments as active_orders' => fn($q) =>
            $q->whereHas('order', fn($q2) => $q2->whereNotIn('order_status', ['delivered', 'cancelled']))
        ])->orderBy('name')->get()->toArray();
    }

    public function createDorji(int $companyId, array $data): TailorDorji
    {
        return TailorDorji::create(array_merge($data, ['company_id' => $companyId]));
    }

    public function updateDorji(int $companyId, int $id, array $data): TailorDorji
    {
        $d = TailorDorji::where('company_id', $companyId)->findOrFail($id);
        $d->update($data);
        return $d->fresh();
    }

    public function deleteDorji(int $companyId, int $id): void
    {
        TailorDorji::where('company_id', $companyId)->findOrFail($id)->delete();
    }

    // ─── Orders ──────────────────────────────────────────────────────────────

    public function listOrders(int $companyId, array $filters = []): array
    {
        $q = TailorOrder::with(['customer', 'items.fabric', 'assignments.dorji'])
            ->where('company_id', $companyId);

        if (!empty($filters['order_status']))   $q->where('order_status', $filters['order_status']);
        if (!empty($filters['payment_status'])) $q->where('payment_status', $filters['payment_status']);
        if (!empty($filters['customer_phone'])) {
            $q->whereHas('customer', fn($q2) => $q2->where('phone', 'like', '%' . $filters['customer_phone'] . '%'));
        }
        if (!empty($filters['dorji_id'])) {
            $q->whereHas('assignments', fn($q2) => $q2->where('dorji_id', $filters['dorji_id']));
        }
        if (!empty($filters['delivery_from'])) $q->whereDate('delivery_date', '>=', $filters['delivery_from']);
        if (!empty($filters['delivery_to']))   $q->whereDate('delivery_date', '<=', $filters['delivery_to']);
        if (!empty($filters['search'])) {
            $q->where(function ($q2) use ($filters) {
                $q2->where('order_number', 'like', '%' . $filters['search'] . '%')
                   ->orWhereHas('customer', fn($q3) => $q3->where('name', 'like', '%' . $filters['search'] . '%'));
            });
        }

        $limit = min((int)($filters['limit'] ?? 20), 100);
        $page  = max(1, (int)($filters['page'] ?? 1));
        $result = $q->orderByDesc('created_at')->paginate($limit, ['*'], 'page', $page);

        return [
            'data'       => $result->items(),
            'pagination' => [
                'page'      => $result->currentPage(),
                'per_page'  => $result->perPage(),
                'total'     => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ];
    }

    public function createOrder(int $companyId, array $data, int $userId): TailorOrder
    {
        return DB::transaction(function () use ($companyId, $data, $userId) {
            $items = $data['items'] ?? [];
            unset($data['items']);

            $customer = $this->upsertCustomer($companyId, [
                'name'    => $data['customer_name'],
                'phone'   => $data['customer_phone'],
                'address' => $data['customer_address'] ?? null,
            ]);

            $order = TailorOrder::create(array_merge($data, [
                'company_id'  => $companyId,
                'customer_id' => $customer->id,
            ]));

            foreach ($items as $item) {
                TailorOrderItem::create(array_merge($item, ['order_id' => $order->id]));

                if (!empty($item['fabric_id']) && !empty($item['fabric_quantity'])) {
                    TailorFabric::where('id', $item['fabric_id'])
                        ->decrement('stock_quantity', (float)$item['fabric_quantity']);
                }
            }

            $order->load('items');
            $order->recalculate();
            $order->save();

            if (!empty($data['advance_payment']) && $data['advance_payment'] > 0) {
                TailorPayment::create([
                    'company_id'     => $companyId,
                    'order_id'       => $order->id,
                    'amount'         => $data['advance_payment'],
                    'payment_method' => $data['advance_payment_method'] ?? 'cash',
                    'payment_date'   => $order->order_date,
                    'notes'          => 'Advance payment',
                ]);
                $order->paid_amount = $data['advance_payment'];
                $order->recalculate();
                $order->save();
            }

            TailorStatusLog::create([
                'order_id'    => $order->id,
                'from_status' => null,
                'to_status'   => $order->order_status ?? $data['order_status'] ?? 'pending',
                'changed_by'  => $userId,
                'note'        => 'Order created',
            ]);

            return $order->load(['customer', 'items.fabric', 'items.measurement', 'assignments.dorji', 'payments', 'statusLogs']);
        });
    }

    public function updateOrder(int $companyId, int $id, array $data): TailorOrder
    {
        $order = TailorOrder::with('items')->where('company_id', $companyId)->findOrFail($id);
        $order->update($data);
        $order->load('items');
        $order->recalculate();
        $order->save();
        return $order->fresh(['customer', 'items.fabric']);
    }

    public function updateOrderStatus(int $companyId, int $id, string $status, ?string $note, int $userId): TailorOrder
    {
        $order = TailorOrder::where('company_id', $companyId)->findOrFail($id);
        $from  = $order->order_status;
        $order->update(['order_status' => $status]);

        TailorStatusLog::create([
            'order_id'    => $order->id,
            'from_status' => $from,
            'to_status'   => $status,
            'changed_by'  => $userId,
            'note'        => $note,
        ]);

        return $order->fresh(['customer', 'items.fabric', 'assignments.dorji', 'payments', 'statusLogs']);
    }

    public function getOrder(int $companyId, int $id): TailorOrder
    {
        return TailorOrder::with(['customer', 'items.fabric', 'items.measurement',
            'assignments.dorji', 'payments', 'statusLogs'])
            ->where('company_id', $companyId)->findOrFail($id);
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function listAssignments(int $companyId, array $filters = []): array
    {
        $q = TailorAssignment::with(['order.customer', 'dorji'])
            ->where('company_id', $companyId);
        if (!empty($filters['dorji_id']))    $q->where('dorji_id', $filters['dorji_id']);
        if (!empty($filters['work_status'])) $q->where('work_status', $filters['work_status']);
        return $q->orderByDesc('assigned_date')->get()->toArray();
    }

    public function createAssignment(int $companyId, array $data): TailorAssignment
    {
        $assignment = TailorAssignment::create(array_merge($data, ['company_id' => $companyId]));
        TailorOrder::where('id', $data['order_id'])->where('company_id', $companyId)
            ->update(['order_status' => 'assigned']);
        return $assignment->load(['order.customer', 'dorji']);
    }

    public function updateAssignment(int $companyId, int $id, array $data): TailorAssignment
    {
        $a = TailorAssignment::where('company_id', $companyId)->findOrFail($id);
        $a->update($data);
        return $a->fresh(['order.customer', 'dorji']);
    }

    // ─── Payments ────────────────────────────────────────────────────────────

    public function listPayments(int $companyId, array $filters = []): array
    {
        $q = TailorPayment::with('order.customer')->where('company_id', $companyId);
        if (!empty($filters['order_id']))        $q->where('order_id', $filters['order_id']);
        if (!empty($filters['payment_method']))  $q->where('payment_method', $filters['payment_method']);
        if (!empty($filters['date_from']))       $q->whereDate('payment_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to']))         $q->whereDate('payment_date', '<=', $filters['date_to']);
        return $q->orderByDesc('payment_date')->get()->toArray();
    }

    public function createPayment(int $companyId, array $data): TailorPayment
    {
        return DB::transaction(function () use ($companyId, $data) {
            $payment = TailorPayment::create(array_merge($data, ['company_id' => $companyId]));

            $order = TailorOrder::with('items')->where('company_id', $companyId)
                ->findOrFail($data['order_id']);
            $order->paid_amount = TailorPayment::where('order_id', $order->id)->sum('amount');
            $order->recalculate();
            $order->save();

            return $payment->load('order.customer');
        });
    }

    // ─── Dashboard Stats ─────────────────────────────────────────────────────

    public function dashboardStats(int $companyId): array
    {
        $today = today()->toDateString();
        return [
            'today_orders'      => TailorOrder::where('company_id', $companyId)->whereDate('created_at', $today)->count(),
            'pending_orders'    => TailorOrder::where('company_id', $companyId)->where('order_status', 'pending')->count(),
            'ready_for_delivery'=> TailorOrder::where('company_id', $companyId)->where('order_status', 'ready')->count(),
            'delivered_orders'  => TailorOrder::where('company_id', $companyId)->where('order_status', 'delivered')->count(),
            'total_due'         => TailorOrder::where('company_id', $companyId)->sum('due_amount'),
            'low_stock_fabrics' => TailorFabric::where('company_id', $companyId)->where('status', 'active')->where('stock_quantity', '<', 5)->count(),
            'active_dorjis'     => TailorDorji::where('company_id', $companyId)->where('status', 'active')->count(),
            'recent_orders'     => TailorOrder::with('customer')->where('company_id', $companyId)->orderByDesc('created_at')->limit(5)->get(),
        ];
    }

    // ─── Reports ─────────────────────────────────────────────────────────────

    public function ordersReport(int $companyId, array $filters = []): array
    {
        $q = TailorOrder::with('customer')->where('company_id', $companyId);
        if (!empty($filters['date_from'])) $q->whereDate('order_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to']))   $q->whereDate('order_date', '<=', $filters['date_to']);
        $orders = $q->orderByDesc('order_date')->get();
        return [
            'orders'         => $orders,
            'total_orders'   => $orders->count(),
            'total_revenue'  => $orders->sum('total_amount'),
            'total_paid'     => $orders->sum('paid_amount'),
            'total_due'      => $orders->sum('due_amount'),
            'pending_count'  => $orders->where('order_status', 'pending')->count(),
            'delivered_count'=> $orders->where('order_status', 'delivered')->count(),
        ];
    }

    public function fabricsReport(int $companyId): array
    {
        return TailorFabric::where('company_id', $companyId)
            ->orderBy('stock_quantity')
            ->get()->toArray();
    }

    public function dorjisReport(int $companyId, array $filters = []): array
    {
        $dorjis = TailorDorji::with(['assignments' => function ($q) use ($filters) {
            if (!empty($filters['date_from'])) $q->whereDate('assigned_date', '>=', $filters['date_from']);
            if (!empty($filters['date_to']))   $q->whereDate('assigned_date', '<=', $filters['date_to']);
        }])->where('company_id', $companyId)->get();

        return $dorjis->map(fn($d) => [
            'id'               => $d->id,
            'name'             => $d->name,
            'phone'            => $d->phone,
            'status'           => $d->status,
            'total_assigned'   => $d->assignments->count(),
            'completed'        => $d->assignments->where('work_status', 'completed')->count(),
            'total_charge'     => $d->assignments->sum('dorji_charge'),
        ])->toArray();
    }
}
