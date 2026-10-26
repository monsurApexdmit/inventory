<?php

namespace App\Services\Vendor;

use App\DTOs\Vendor\VendorDTO;
use App\DTOs\Vendor\VendorMapper;
use App\Models\DuePayment;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Payment\DuePaymentService;
use Illuminate\Support\Facades\DB;
use App\Models\Vendor;
use App\Repositories\Contracts\IVendorRepository;
use App\Repositories\Eloquent\PurchaseOrderRepository;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorService
{
    private readonly VendorMapper $mapper;

    public function __construct(
        private readonly IVendorRepository $repository,
        private readonly PurchaseOrderRepository $purchaseOrderRepository,
        private readonly DuePaymentService $duePaymentService,
    ) {
        $this->mapper = new VendorMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(function ($vendor) use ($companyId) {
            $poDueSummary = $this->purchaseOrderRepository->getVendorDuesSummary($companyId, ['vendor_id' => $vendor->id]);
            return $this->mapper->toDTO($vendor, $poDueSummary['totalDue']);
        }, $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): VendorDTO
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        $poDueSummary = $this->purchaseOrderRepository->getVendorDuesSummary($companyId, ['vendor_id' => $id]);

        return $this->mapper->toDTO($vendor, $poDueSummary['totalDue']);
    }

    public function create(int $companyId, array $data): VendorDTO
    {
        $email = $data['email'] ?? null;
        $user = null;

        $this->assertUniqueName($companyId, (string) ($data['name'] ?? ''));

        if ($email) {
            $existing = $this->repository->findByEmailAndCompany($email, $companyId);
            if ($existing) {
                throw new HttpException(409, 'Email already registered for this company');
            }
        }

        return DB::transaction(function () use ($companyId, $data, $email) {
            $dbData = $this->mapInputToDb($data);
            $dbData['company_id'] = $companyId;

            // A linked login user is only created when the vendor has an email.
            if ($email) {
                $user = User::create([
                    'username' => $data['name'],
                    'email' => $email,
                    'password' => Hash::make('changeme'),
                ]);
                $dbData['user_id'] = $user->id;
            }

            $vendor = $this->repository->create($dbData);

            return $this->mapper->toDTO($vendor->fresh('user', 'user.role'));
        });
    }

    public function update(int $id, int $companyId, array $data): VendorDTO
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        if (!empty($data['name']) && mb_strtolower(trim($data['name'])) !== mb_strtolower(trim($vendor->name))) {
            $this->assertUniqueName($companyId, $data['name'], $id);
        }

        $emailProvided = array_key_exists('email', $data);
        $newEmail = $emailProvided ? ($data['email'] ?: null) : $vendor->email;

        if ($emailProvided && $newEmail && $newEmail !== $vendor->email) {
            $existing = $this->repository->findByEmailAndCompany($newEmail, $companyId);
            if ($existing && $existing->id !== $vendor->id) {
                throw new HttpException(409, 'Email already registered for this company');
            }
        }

        $dbData = $this->mapInputToDb($data);

        DB::transaction(function () use ($vendor, $data, $emailProvided, $newEmail, &$dbData, $id) {
            // Keep the linked login user in sync, when there is one.
            if ($vendor->user_id && $vendor->user) {
                $userUpdate = [];
                if (!empty($data['name'])) {
                    $userUpdate['username'] = $data['name'];
                }
                if ($emailProvided && $newEmail) {
                    $userUpdate['email'] = $newEmail;
                }
                if ($userUpdate) {
                    $vendor->user->update($userUpdate);
                }
            } elseif ($emailProvided && $newEmail && !$vendor->user_id) {
                // Email added to a vendor that had none: create the linked user now.
                $user = User::create([
                    'username' => $data['name'] ?? $vendor->name,
                    'email' => $newEmail,
                    'password' => Hash::make('changeme'),
                ]);
                $dbData['user_id'] = $user->id;
            }

            $this->repository->update($id, $dbData);
        });

        return $this->get($id, $companyId);
    }

    private function assertUniqueName(int $companyId, string $name, ?int $ignoreId = null): void
    {
        $name = trim($name);
        if ($name === '') {
            return;
        }
        $q = Vendor::where('company_id', $companyId)->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)]);
        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }
        if ($q->exists()) {
            throw new HttpException(422, "A supplier named '{$name}' already exists.");
        }
    }

    public function overview(int $companyId, array $filters): array
    {
        $result = $this->repository->overview($companyId, $filters);
        $summaries = $this->repository->itemsSummaries($result['rows']->pluck('id')->all());

        $data = $result['rows']->map(fn(Vendor $v) => array_merge(
            $this->mapper->toDTO($v, round((float) $v->po_due, 2))->toArray(),
            [
                'purchased' => round((float) $v->purchased, 2),
                'poCount' => (int) $v->po_count,
                'openPoCount' => (int) $v->open_po_count,
                'owed' => round((float) $v->owed, 2),
                'itemsSummary' => $summaries[$v->id] ?? null,
            ]
        ))->all();

        return [
            'data' => $data,
            'total' => $result['total'],
            'stats' => $this->repository->overviewStats($companyId),
        ];
    }

    public function profile(int $id, int $companyId): array
    {
        $vendor = $this->repository->findWithAggregates($id, $companyId);
        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        $poQuery = fn() => PurchaseOrder::where('company_id', $companyId)->where('vendor_id', $id);

        // Distinct products ordered (cancelled orders excluded); newest line gives lastCost.
        $lines = DB::table('purchase_order_items as i')
            ->join('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('po.company_id', $companyId)
            ->where('po.vendor_id', $id)
            ->where('po.status', '!=', 'cancelled')
            ->whereNull('p.deleted_at')
            ->orderByDesc('i.id')
            ->get(['i.product_id', 'p.name', 'i.unit_cost', 'p.stock', 'p.cost_price']);

        $products = [];
        foreach ($lines as $l) {
            if (isset($products[$l->product_id])) {
                continue;
            }
            $products[$l->product_id] = [
                'productId' => (int) $l->product_id,
                'name' => $l->name,
                'lastCost' => (float) $l->unit_cost,
                'stock' => (int) $l->stock,
                'stockValue' => round((int) $l->stock * (float) $l->cost_price, 2),
            ];
        }
        $products = array_values($products);

        $recent = $poQuery()->with('items.product')->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get()
            ->map(fn(PurchaseOrder $po) => [
                'id' => $po->id,
                'poNumber' => $po->po_number,
                'itemsSummary' => $po->itemsSummary(),
                'total' => (float) $po->total_amount,
                'status' => $po->status,
                'paymentStatus' => $po->payment_status ?? 'pending',
                'expectedDate' => $po->expected_date?->format('Y-m-d'),
                'due' => (float) $po->due_amount,
                'isOverdue' => $po->isOverdue(),
            ])->all();

        $poPaid = (float) $poQuery()->sum('paid_amount');
        $openingPaid = (float) VendorPayment::where('vendor_id', $id)->sum('amount');

        return [
            'vendor' => $this->mapper->toDTO($vendor, round((float) $vendor->po_due, 2))->toArray(),
            'purchased' => round((float) $vendor->purchased, 2),
            'openPos' => (int) $vendor->open_po_count,
            'owed' => round((float) $vendor->owed, 2),
            'totalPaid' => round($poPaid + $openingPaid, 2),
            'productCount' => count($products),
            'stockValue' => round(array_sum(array_column($products, 'stockValue')), 2),
            'recentPurchaseOrders' => $recent,
            'products' => $products,
            'payments' => $this->recentPayments($id, $companyId),
        ];
    }

    /**
     * Latest 20 payments: PO payments (due_payments) merged with opening-balance payments.
     * ids are prefixed ("po-12" / "ob-3") because they come from two tables.
     */
    private function recentPayments(int $vendorId, int $companyId): array
    {
        $poPayments = DuePayment::query()
            ->join('purchase_orders as po', function ($j) {
                $j->on('po.id', '=', 'due_payments.payable_id')
                    ->where('due_payments.payable_type', '=', PurchaseOrder::class);
            })
            ->where('po.company_id', $companyId)
            ->where('po.vendor_id', $vendorId)
            ->where('due_payments.direction', 'out')
            ->orderByDesc('due_payments.payment_date')->orderByDesc('due_payments.id')
            ->limit(20)
            ->get(['due_payments.id', 'due_payments.payment_date', 'due_payments.payment_method', 'due_payments.notes', 'due_payments.amount', 'po.po_number'])
            ->map(fn($p) => [
                'id' => 'po-' . $p->id,
                'date' => $p->payment_date?->format('Y-m-d'),
                'method' => $p->payment_method,
                'note' => $p->notes,
                'amount' => (float) $p->amount,
                'poNumber' => $p->po_number,
                '_sort' => $p->id,
            ]);

        $openingPayments = VendorPayment::where('vendor_id', $vendorId)
            ->orderByDesc('payment_date')->orderByDesc('id')->limit(20)->get()
            ->map(fn($p) => [
                'id' => 'ob-' . $p->id,
                'date' => $p->payment_date?->format('Y-m-d'),
                'method' => $p->payment_method,
                'note' => $p->notes,
                'amount' => (float) $p->amount,
                'poNumber' => null,
                '_sort' => $p->id,
            ]);

        return $poPayments->concat($openingPayments)
            ->sort(fn($a, $b) => [$b['date'], $b['_sort']] <=> [$a['date'], $a['_sort']])
            ->take(20)
            ->map(function ($p) {
                unset($p['_sort']);
                return $p;
            })
            ->values()
            ->all();
    }

    /**
     * Pay a supplier: oldest-due purchase orders first (through the standard
     * due-payment flow so PO paid/due/status stay consistent), remainder reduces
     * the opening balance.
     */
    public function recordPayment(int $id, int $companyId, array $data, ?int $recordedBy = null): array
    {
        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new HttpException(422, 'Payment amount must be greater than zero');
        }

        DB::transaction(function () use ($id, $companyId, $data, $amount, $recordedBy) {
            $this->allocatePayment($id, $companyId, $amount, $data['method'] ?? 'cash', $data['date'] ?? now()->toDateString(), $data['note'] ?? null, $recordedBy, false);
        });

        return $this->profile($id, $companyId);
    }

    /**
     * Apply a supplier credit (e.g. a credit note from a vendor return) against
     * what the company owes: oldest PO first, remainder reduces opening balance.
     * Capped at the amount owed. Returns the amount actually applied.
     */
    public function applyCredit(int $vendorId, int $companyId, float $amount, string $method, ?string $note, ?int $recordedBy = null): float
    {
        return DB::transaction(fn () => $this->allocatePayment($vendorId, $companyId, round($amount, 2), $method, now()->toDateString(), $note, $recordedBy, true));
    }

    private function allocatePayment(int $id, int $companyId, float $amount, string $method, string $date, ?string $note, ?int $recordedBy, bool $capAtOwed): float
    {
        $vendor = Vendor::where('company_id', $companyId)->lockForUpdate()->find($id);
        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        $duePos = PurchaseOrder::where('company_id', $companyId)
            ->where('vendor_id', $id)
            ->whereIn('status', ['sent', 'partial', 'received'])
            ->where('due_amount', '>', 0)
            ->orderByRaw('COALESCE(expected_date, DATE(created_at)) ASC')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $owed = round((float) $vendor->opening_balance + (float) $duePos->sum('due_amount'), 2);
        if ($amount > $owed) {
            if (!$capAtOwed) {
                throw new HttpException(422, 'Payment amount exceeds the amount owed to this supplier');
            }
            $amount = $owed;
        }
        if ($amount <= 0) {
            return 0.0;
        }
        $applied = $amount;
        $remaining = $amount;

        foreach ($duePos as $po) {
            if ($remaining <= 0) {
                break;
            }
            $pay = round(min($remaining, (float) $po->due_amount), 2);
            if ($pay <= 0) {
                continue;
            }
            $this->duePaymentService->recordPayment('purchase-orders', $po->id, [
                'amount' => $pay,
                'payment_method' => $method,
                'payment_date' => $date,
                'notes' => $note,
            ], $companyId, $recordedBy);
            $remaining = round($remaining - $pay, 2);
        }

        if ($remaining > 0) {
            VendorPayment::create([
                'company_id' => $companyId,
                'vendor_id' => $id,
                'amount' => $remaining,
                'payment_method' => $method,
                'payment_date' => $date,
                'notes' => $note,
                'recorded_by' => $recordedBy,
            ]);
            $vendor->opening_balance = max(0, round((float) $vendor->opening_balance - $remaining, 2));
            $vendor->save();
        }

        return $applied;
    }

    public function delete(int $id, int $companyId): void
    {
        $vendor = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$vendor) {
            throw new HttpException(404, 'Vendor not found');
        }

        $poCount = \App\Models\PurchaseOrder::where('company_id', $companyId)->where('vendor_id', $id)->count();
        if ($poCount > 0) {
            throw new HttpException(422, "Cannot delete supplier '{$vendor->name}': it has {$poCount} purchase order(s). Deactivate the supplier instead.");
        }
        $payCount = \App\Models\VendorPayment::where('company_id', $companyId)->where('vendor_id', $id)->count();
        if ($payCount > 0) {
            throw new HttpException(422, "Cannot delete supplier '{$vendor->name}': it has {$payCount} payment(s) on record.");
        }

        // Soft delete linked user if exists
        if ($vendor->user_id) {
            $vendor->user()->delete();
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    /**
     * Fields where an explicit null clears the stored value.
     */
    private const NULLABLE_FIELDS = [
        'email' => 'email',
        'phone' => 'phone',
        'address' => 'address',
        'logo' => 'logo',
        'description' => 'description',
        'contactPerson' => 'contact_person',
        'district' => 'district',
        'country' => 'country',
        'paymentTerms' => 'payment_terms',
        'tin' => 'tin',
        'payTo' => 'pay_to',
        'accountNumber' => 'account_number',
        'notes' => 'notes',
        'leadTimeDays' => 'lead_time_days',
        'safetyDays' => 'safety_days',
    ];

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (!empty($data['name'])) {
            $dbData['name'] = $data['name'];
        }
        foreach (self::NULLABLE_FIELDS as $input => $column) {
            if (array_key_exists($input, $data)) {
                $dbData[$column] = ($data[$input] === '' ? null : $data[$input]);
            }
        }
        if (isset($data['uploadedBy'])) {
            $dbData['uploaded_by'] = $data['uploadedBy'];
        }
        if (!empty($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['totalPaid'])) {
            $dbData['total_paid'] = $data['totalPaid'];
        }
        if (isset($data['amountPayable'])) {
            $dbData['amount_payable'] = $data['amountPayable'];
        }
        if (array_key_exists('openingBalance', $data)) {
            $dbData['opening_balance'] = (float) ($data['openingBalance'] ?? 0);
        }

        return $dbData;
    }
}
