<?php

namespace App\Services\Pos;

use App\Models\HeldSale;
use App\Models\RegisterCashMovement;
use App\Models\RegisterSession;
use App\Models\Sell;
use App\Models\SaasUser;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PosService
{
    private const REFUND_STATUSES = ['approved', 'completed'];

    /* ------------------------------------------------------------------ */
    /* Shifts                                                              */
    /* ------------------------------------------------------------------ */

    public function getShift(int $companyId, ?string $register): array
    {
        $query = RegisterSession::where('company_id', $companyId)->where('status', 'open');
        if ($register !== null && $register !== '') {
            $query->where('register', $register);
        }
        $open = $query->orderByDesc('id')->first();

        $history = RegisterSession::where('company_id', $companyId)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(4)
            ->get()
            ->map(fn (RegisterSession $s) => $this->toDto($s, false))
            ->all();

        return [
            'session' => $open ? $this->toDto($open, true) : null,
            'history' => $history,
        ];
    }

    public function openShift(int $companyId, ?int $userId, array $data): array
    {
        return DB::transaction(function () use ($companyId, $userId, $data) {
            // Lock this company's sessions so numbering / duplicate checks are safe
            $existingOpen = RegisterSession::where('company_id', $companyId)
                ->where('register', $data['register'])
                ->where('status', 'open')
                ->lockForUpdate()
                ->exists();
            if ($existingOpen) {
                throw new HttpException(409, 'This register already has an open shift.');
            }

            $last = RegisterSession::where('company_id', $companyId)->orderByDesc('id')->lockForUpdate()->first();
            $next = 1;
            if ($last && preg_match('/(\d+)$/', (string) $last->session_no, $m)) {
                $next = ((int) $m[1]) + 1;
            }

            $session = RegisterSession::create([
                'company_id' => $companyId,
                'register' => $data['register'],
                'location_id' => $data['locationId'] ?? null,
                'session_no' => 'SH-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'status' => 'open',
                'opening_cash' => round((float) $data['openingCash'], 2),
                'opened_by' => $this->userName($userId),
                'opened_at' => now(),
            ]);

            return $this->toDto($session->fresh(), true);
        });
    }

    public function addMovement(int $companyId, ?int $userId, int $sessionId, array $data): array
    {
        return DB::transaction(function () use ($companyId, $userId, $sessionId, $data) {
            $session = RegisterSession::where('id', $sessionId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            if (!$session) {
                throw new HttpException(404, 'Shift not found');
            }
            if ($session->status !== 'open') {
                throw new HttpException(422, 'This shift is already closed.');
            }

            $amount = round((float) $data['amount'], 2);
            if ($data['type'] === 'out') {
                $summary = $this->summary($session);
                if ($amount > $summary['expected'] + 0.005) {
                    throw new HttpException(422, 'Cash out is more than the cash in the drawer.');
                }
            }

            RegisterCashMovement::create([
                'register_session_id' => $session->id,
                'type' => $data['type'],
                'amount' => $amount,
                'reason' => $data['reason'],
                'created_by' => $this->userName($userId),
            ]);

            return $this->toDto($session->fresh(), true);
        });
    }

    public function closeShift(int $companyId, int $sessionId, array $data): array
    {
        return DB::transaction(function () use ($companyId, $sessionId, $data) {
            $session = RegisterSession::where('id', $sessionId)
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->first();
            if (!$session) {
                throw new HttpException(404, 'Shift not found');
            }
            if ($session->status !== 'open') {
                throw new HttpException(422, 'This shift is already closed.');
            }

            $closedAt = now();
            $summary = $this->summary($session, $closedAt);
            $expected = $summary['expected'];
            $counted = round((float) $data['counted'], 2);
            $variance = round($counted - $expected, 2);
            $note = isset($data['note']) ? trim((string) $data['note']) : '';

            if (abs($variance) > 0.005 && $note === '') {
                throw new HttpException(422, 'Add a note explaining the difference.');
            }

            $session->update([
                'status' => 'closed',
                'closed_at' => $closedAt,
                'expected_cash' => $expected,
                'counted_cash' => $counted,
                'variance' => $variance,
                'close_note' => $note !== '' ? $note : null,
            ]);

            return $this->toDto($session->fresh(), true);
        });
    }

    /* ------------------------------------------------------------------ */
    /* Held sales                                                          */
    /* ------------------------------------------------------------------ */

    public function listHeld(int $companyId): array
    {
        return HeldSale::where('company_id', $companyId)
            ->orderBy('id')
            ->get()
            ->map(fn (HeldSale $h) => $this->heldToArray($h))
            ->all();
    }

    public function createHeld(int $companyId, ?int $userId, array $data): array
    {
        $label = isset($data['label']) ? trim((string) $data['label']) : '';
        if ($label === '') {
            $label = 'Hold #' . (HeldSale::where('company_id', $companyId)->count() + 1);
        }

        $held = HeldSale::create([
            'company_id' => $companyId,
            'label' => $label,
            'customer_id' => $data['customerId'] ?? null,
            'item_count' => (int) $data['itemCount'],
            'total' => round((float) $data['total'], 2),
            'payload' => $data['payload'],
            'created_by' => $this->userName($userId),
        ]);

        return $this->heldToArray($held->fresh());
    }

    public function deleteHeld(int $companyId, int $id): void
    {
        $held = HeldSale::where('id', $id)->where('company_id', $companyId)->first();
        if (!$held) {
            throw new HttpException(404, 'Held sale not found');
        }
        $held->delete();
    }

    /* ------------------------------------------------------------------ */
    /* Best sellers                                                        */
    /* ------------------------------------------------------------------ */

    public function bestSellers(int $companyId, int $days): array
    {
        // $days <= 0 means all sales, no time limit
        $since = $days > 0 ? now()->subDays($days) : null;

        $rows = DB::table('order_items')
            ->join('sells', 'sells.id', '=', 'order_items.sell_id')
            ->where('sells.company_id', $companyId)
            ->whereNull('sells.deleted_at')
            ->where('sells.status', '!=', 'Cancelled')
            ->when($since, fn ($q) => $q->where('sells.order_time', '>=', $since))
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as qty'))
            ->orderByDesc('qty')
            ->limit(500)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->product_id] = (int) $row->qty;
        }

        return $out;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function userName(?int $userId): string
    {
        if ($userId) {
            $name = SaasUser::where('id', $userId)->value('full_name');
            if (!empty($name)) {
                return (string) $name;
            }
        }

        return 'Staff';
    }

    private function heldToArray(HeldSale $h): array
    {
        return [
            'id' => $h->id,
            'label' => $h->label,
            'customerId' => $h->customer_id !== null ? (int) $h->customer_id : null,
            'itemCount' => (int) $h->item_count,
            'total' => (float) $h->total,
            'payload' => $h->payload ?? new \stdClass(),
            'createdAt' => $h->created_at?->toIso8601String(),
        ];
    }

    private function toDto(RegisterSession $s, bool $withSummary): array
    {
        $isOpen = $s->status === 'open';
        $summary = $withSummary || $isOpen ? $this->summary($s) : null;

        if ($isOpen) {
            $expected = $summary['expected'];
        } else {
            $expected = $s->expected_cash !== null ? (float) $s->expected_cash : null;
        }

        return [
            'id' => $s->id,
            'no' => $s->session_no,
            'register' => $s->register,
            'locationId' => $s->location_id !== null ? (int) $s->location_id : null,
            'status' => $s->status,
            'openedBy' => $s->opened_by,
            'openedAt' => $s->opened_at?->toIso8601String(),
            'closedAt' => $s->closed_at?->toIso8601String(),
            'openingCash' => (float) $s->opening_cash,
            'expectedCash' => $expected,
            'countedCash' => $s->counted_cash !== null ? (float) $s->counted_cash : null,
            'variance' => $s->variance !== null ? (float) $s->variance : null,
            'closeNote' => $s->close_note,
            'summary' => $isOpen || $withSummary ? $summary : null,
        ];
    }

    /**
     * Cash reconciliation summary for a session window [opened_at, closed_at ?? now()].
     */
    public function summary(RegisterSession $s, $windowEnd = null): array
    {
        $companyId = (int) $s->company_id;
        $from = $s->opened_at;
        $to = $windowEnd ?? $s->closed_at ?? now();

        $cashSales = (float) Sell::where('register_session_id', $s->id)
            ->whereRaw('LOWER(method) = ?', ['cash'])
            ->sum('paid_amount');
        $saleCount = (int) Sell::where('register_session_id', $s->id)
            ->whereRaw('LOWER(method) = ?', ['cash'])
            ->count();
        $nonCashSales = (float) Sell::where('register_session_id', $s->id)
            ->whereRaw('LOWER(method) <> ?', ['cash'])
            ->sum('paid_amount');

        $collections = (float) DB::table('due_payments')
            ->where('company_id', $companyId)
            ->where('direction', 'in')
            ->whereRaw('LOWER(payment_method) = ?', ['cash'])
            ->where('payable_type', Sell::class)
            ->where(function ($q) {
                $q->whereNull('notes')->orWhere('notes', '!=', 'Payment at sale creation');
            })
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $supplierPaid = (float) DB::table('due_payments')
            ->where('company_id', $companyId)
            ->where('direction', 'out')
            ->where('payable_type', '!=', Sell::class)
            ->whereRaw('LOWER(payment_method) = ?', ['cash'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $movements = $s->movements()->orderBy('id')->get();
        $cashIn = (float) $movements->where('type', 'in')->sum('amount');
        $cashOut = (float) $movements->where('type', 'out')->sum('amount');

        $refunds = (float) DB::table('customer_returns')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(refund_method) LIKE ?', ['%cash%'])
            ->whereIn('status', self::REFUND_STATUSES)
            ->whereBetween('created_at', [$from, $to])
            ->sum('total_amount');

        $expenses = (float) DB::table('expenses')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(payment_method) = ?', ['cash'])
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');

        $opening = (float) $s->opening_cash;
        $expected = round($opening + $cashSales + $collections + $cashIn - $cashOut - $refunds - $expenses - $supplierPaid, 2);

        return [
            'opening' => round($opening, 2),
            'cashSales' => round($cashSales, 2),
            'saleCount' => $saleCount,
            'collections' => round($collections, 2),
            'cashIn' => round($cashIn, 2),
            'cashOut' => round($cashOut, 2),
            'refunds' => round($refunds, 2),
            'expenses' => round($expenses, 2),
            'supplierPaid' => round($supplierPaid, 2),
            'nonCashSales' => round($nonCashSales, 2),
            'expected' => $expected,
            'movements' => $movements->map(fn (RegisterCashMovement $m) => [
                'id' => $m->id,
                'type' => $m->type,
                'amount' => (float) $m->amount,
                'reason' => $m->reason,
                'createdAt' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
