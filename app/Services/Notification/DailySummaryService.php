<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\DB;

/**
 * Builds one in-app "Daily summary" notification per company for the previous
 * day (company timezone). In-app only: no email/SMS is sent.
 */
class DailySummaryService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /** @return int number of notifications created */
    public function run(): int
    {
        $created = 0;
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if ($this->summarise((int) $companyId)) {
                $created++;
            }
        }

        return $created;
    }

    public function summarise(int $companyId): bool
    {
        $tz = DB::table('company_settings')->where('company_id', $companyId)->value('timezone') ?: config('app.timezone');
        try {
            $day = now($tz)->subDay();
        } catch (\Throwable) {
            $day = now()->subDay();
        }
        $from = $day->copy()->startOfDay()->setTimezone(config('app.timezone'));
        $to = $day->copy()->endOfDay()->setTimezone(config('app.timezone'));

        $sales = DB::table('sells')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('status', '!=', 'Cancelled')->whereBetween('created_at', [$from, $to]);
        $count = (clone $sales)->count();
        $total = (float) (clone $sales)->sum('amount');
        $orders = (clone $sales)->where('source', '!=', 'pos')->count();
        $low = DB::table('products')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('reorder_point', '>', 0)->whereColumn('stock', '<=', 'reorder_point')->count();

        $label = $day->format('M j');
        $message = "{$label}: {$count} sale(s) totalling " . number_format($total, 2)
            . ", {$orders} new order(s), {$low} item(s) low on stock";

        return $this->notifications->notifyDailySummary($companyId, $message, [
            'date' => $day->toDateString(), 'salesCount' => $count, 'salesTotal' => $total,
            'newOrders' => $orders, 'lowStock' => $low,
        ]);
    }
}
