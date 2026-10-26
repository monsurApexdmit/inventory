<?php

namespace App\Services\Notification;

use App\Services\Dashboard\DashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Live alerts computed from current data (nothing is stored per alert). A user can dismiss an alert;
 * the dismissal is kept while the condition holds and dropped as soon as the condition clears, so the
 * alert shows again if the same condition returns ("re-arm").
 */
class NotificationAlertService
{
    private const PER_KIND_LIMIT = 100; // keys considered per kind
    private const PER_KIND_SHOWN = 20;

    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    public function list(int $companyId, int $userId): array
    {
        $all = array_merge(
            $this->outOfStock($companyId),
            $this->lowStock($companyId),
            $this->pendingOrders($companyId),
            $this->customerOverdue($companyId),
            $this->poOverdue($companyId),
        );
        $activeKeys = array_column($all, 'key');

        // Re-arm: forget dismissals whose condition has cleared.
        $dismissed = DB::table('notification_dismissals')
            ->where('company_id', $companyId)->where('user_id', $userId)->pluck('alert_key')->all();
        $stale = array_values(array_diff($dismissed, $activeKeys));
        if ($stale !== []) {
            DB::table('notification_dismissals')
                ->where('company_id', $companyId)->where('user_id', $userId)->whereIn('alert_key', $stale)->delete();
            $dismissed = array_values(array_diff($dismissed, $stale));
        }

        $visible = array_values(array_filter($all, fn ($a) => !in_array($a['key'], $dismissed, true)));
        $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort($visible, fn ($a, $b) => [$rank[$a['priority']], $a['kind']] <=> [$rank[$b['priority']], $b['kind']]);

        $counts = [];
        $shown = [];
        foreach ($visible as $a) {
            $counts[$a['kind']] = ($counts[$a['kind']] ?? 0) + 1;
            $shown[$a['kind']] = ($shown[$a['kind']] ?? 0) + 1;
        }
        $out = [];
        $seen = [];
        foreach ($visible as $a) {
            $seen[$a['kind']] = ($seen[$a['kind']] ?? 0) + 1;
            if ($seen[$a['kind']] <= self::PER_KIND_SHOWN) {
                $out[] = $a;
            }
        }

        return [
            'alerts' => $out,
            'total' => count($visible),
            'counts' => $counts,
            'dismissedCount' => count($dismissed),
            'attention' => $this->attention($companyId),
        ];
    }

    public function dismiss(int $companyId, int $userId, string $key): void
    {
        DB::table('notification_dismissals')->updateOrInsert(
            ['company_id' => $companyId, 'user_id' => $userId, 'alert_key' => mb_substr($key, 0, 100)],
            ['dismissed_at' => now()]
        );
    }

    /** Bring back every dismissed alert (or one key). */
    public function restore(int $companyId, int $userId, ?string $key = null): void
    {
        $q = DB::table('notification_dismissals')->where('company_id', $companyId)->where('user_id', $userId);
        if ($key !== null) {
            $q->where('alert_key', $key);
        }
        $q->delete();
    }

    /** Totals across ALL records (not a page): what the "Needs your attention" card shows. */
    public function attention(int $companyId): array
    {
        $recv = DB::table('sells')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount),0) as due, COUNT(*) as cnt')->first();
        $vend = DB::table('purchase_orders')->where('company_id', $companyId)
            ->whereNotIn('status', ['cancelled', 'draft'])->where('due_amount', '>', 0)
            ->selectRaw('COALESCE(SUM(due_amount),0) as due, COUNT(*) as cnt')->first();
        $sal = $this->dashboard->alerts($companyId, [])['salaries'];
        $pending = DB::table('sells')->where('company_id', $companyId)->whereNull('deleted_at')->where('status', 'Pending')->count();

        return [
            'customerDue' => (float) $recv->due,
            'customerDueInvoices' => (int) $recv->cnt,
            'vendorDue' => (float) $vend->due,
            'vendorDueOrders' => (int) $vend->cnt,
            'salariesUnpaid' => (float) $sal['unpaid'],
            'salaryMonth' => $sal['month'],
            'pendingOrders' => $pending,
        ];
    }

    // ── Conditions ─────────────────────────────────────────────────────────────

    private function products(int $cid)
    {
        return DB::table('products')->where('company_id', $cid)->whereNull('deleted_at')->where('is_bundle', false);
    }

    private function outOfStock(int $cid): array
    {
        return $this->products($cid)->where('stock', '<=', 0)->orderBy('name')->limit(self::PER_KIND_LIMIT)
            ->get(['id', 'name'])->map(fn ($p) => $this->alert(
                "out:{$p->id}", 'out_of_stock', 'high', 'Out of stock',
                "{$p->name} is out of stock", '/dashboard/vendors/purchase-orders?new=1', 'Reorder'
            ))->all();
    }

    private function lowStock(int $cid): array
    {
        return $this->products($cid)->where('stock', '>', 0)->where('reorder_point', '>', 0)
            ->whereColumn('stock', '<=', 'reorder_point')->orderBy('name')->limit(self::PER_KIND_LIMIT)
            ->get(['id', 'name', 'stock', 'reorder_point'])->map(fn ($p) => $this->alert(
                "low:{$p->id}", 'low_stock', 'medium', 'Low stock',
                "{$p->name}: {$p->stock} left (reorder at {$p->reorder_point})", '/dashboard/vendors/purchase-orders?new=1', 'Reorder'
            ))->all();
    }

    private function pendingOrders(int $cid): array
    {
        return DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')->where('status', 'Pending')
            ->where('order_time', '<', now()->subDay())->orderBy('order_time')->limit(self::PER_KIND_LIMIT)
            ->get(['id', 'invoice_no', 'customer_name', 'order_time'])->map(function ($s) {
                $hours = (int) Carbon::parse($s->order_time)->diffInHours(now());
                $age = $hours >= 48 ? intdiv($hours, 24) . ' days' : "{$hours} hours";

                return $this->alert(
                    "order:{$s->id}", 'pending_order', $hours >= 72 ? 'high' : 'medium', 'Order pending over 24 hours',
                    "Order {$s->invoice_no}" . ($s->customer_name ? " ({$s->customer_name})" : '') . " has been pending for {$age}",
                    '/dashboard/orders?search=' . urlencode((string) $s->invoice_no), 'View order', $s->order_time
                );
            })->all();
    }

    private function customerOverdue(int $cid): array
    {
        $rows = DB::table('sells')->where('company_id', $cid)->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)
            ->where('order_time', '<', now()->subDays(30))
            ->groupBy('customer_id', 'customer_name')
            ->selectRaw('customer_id, customer_name, SUM(due_amount) as due, MIN(order_time) as oldest, COUNT(*) as cnt')
            ->orderByDesc('due')->limit(self::PER_KIND_LIMIT)->get();

        return $rows->map(function ($r) use ($cid) {
            $days = (int) Carbon::parse($r->oldest)->diffInDays(now());
            $name = $r->customer_name ?: 'A walk-in customer';
            $key = $r->customer_id ? "owes:c{$r->customer_id}" : 'owes:n' . substr(md5((string) $r->customer_name), 0, 12);

            return $this->alert(
                $key, 'customer_overdue', 'high', 'Customer owes over 30 days',
                "{$name} owes " . \App\Support\Money::format($cid, (float) $r->due) . " across {$r->cnt} invoice(s), oldest {$days} days",
                '/dashboard/dues/customers', 'Collect', $r->oldest
            );
        })->all();
    }

    private function poOverdue(int $cid): array
    {
        return DB::table('purchase_orders')->leftJoin('vendors', 'vendors.id', '=', 'purchase_orders.vendor_id')
            ->where('purchase_orders.company_id', $cid)->where('purchase_orders.status', 'sent')
            ->whereNotNull('purchase_orders.expected_date')->where('purchase_orders.expected_date', '<', now()->toDateString())
            ->orderBy('purchase_orders.expected_date')->limit(self::PER_KIND_LIMIT)
            ->get(['purchase_orders.id', 'purchase_orders.po_number', 'purchase_orders.expected_date', 'purchase_orders.due_amount', 'vendors.name as vendor'])
            ->map(function ($p) {
                $days = (int) Carbon::parse($p->expected_date)->diffInDays(now());
                $due = (float) $p->due_amount > 0;

                return $this->alert(
                    "po:{$p->id}", 'po_overdue', 'medium', 'Purchase order overdue',
                    "{$p->po_number}" . ($p->vendor ? " from {$p->vendor}" : '') . " was expected {$days} day(s) ago and is not received",
                    $due ? '/dashboard/dues/vendors' : '/dashboard/vendors/purchase-orders',
                    $due ? 'Pay vendor' : 'View order', $p->expected_date
                );
            })->all();
    }

    private function alert(string $key, string $kind, string $priority, string $title, string $message, string $url, string $cta, ?string $since = null): array
    {
        return [
            'key' => $key, 'kind' => $kind, 'priority' => $priority, 'title' => $title, 'message' => $message,
            'actionUrl' => $url, 'ctaLabel' => $cta,
            'since' => $since ? Carbon::parse($since)->toIso8601String() : null,
        ];
    }
}
