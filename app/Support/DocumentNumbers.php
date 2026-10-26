<?php

namespace App\Support;

use App\Models\PurchaseOrder;
use App\Models\Sell;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Document numbering driven by the company's General settings
 * (invoicePrefix + nextInvoiceNumber, poPrefix, lowStockDefault).
 * Returns null / falls back to the legacy generators when nothing is configured.
 */
class DocumentNumbers
{
    private static function general(int $companyId): array
    {
        $setting = Setting::where('company_id', $companyId)->first();
        return is_array($setting?->general_settings) ? $setting->general_settings : [];
    }

    /** Next invoice number such as "INV-1001", or null when no prefix is configured. */
    public static function nextInvoice(int $companyId): ?string
    {
        return DB::transaction(function () use ($companyId) {
            $setting = Setting::where('company_id', $companyId)->lockForUpdate()->first();
            $general = is_array($setting?->general_settings) ? $setting->general_settings : [];
            $prefix = trim((string) ($general['invoicePrefix'] ?? ''));
            if ($prefix === '') {
                return null;
            }

            $n = max(1, (int) ($general['nextInvoiceNumber'] ?? 1));
            // invoice_no is unique across the whole table, so skip numbers any company already used
            for ($i = 0; $i < 200; $i++, $n++) {
                $candidate = $prefix . $n;
                if (!Sell::withTrashed()->where('invoice_no', $candidate)->exists()) {
                    $general['nextInvoiceNumber'] = $n + 1;
                    $setting->general_settings = $general;
                    $setting->save();
                    return $candidate;
                }
            }
            return null;
        });
    }

    /** Next PO number such as "PO-000007" when a PO prefix is configured, else null. */
    public static function nextPo(int $companyId): ?string
    {
        $prefix = trim((string) (self::general($companyId)['poPrefix'] ?? ''));
        if ($prefix === '') {
            return null;
        }

        $max = 0;
        PurchaseOrder::where('company_id', $companyId)
            ->where('po_number', 'like', $prefix . '%')
            ->pluck('po_number')
            ->each(function ($no) use ($prefix, &$max) {
                $tail = substr($no, strlen($prefix));
                if ($tail !== '' && ctype_digit($tail)) {
                    $max = max($max, (int) $tail);
                }
            });

        return $prefix . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Default reorder point for new products, or null when not set. */
    public static function lowStockDefault(int $companyId): ?int
    {
        $v = self::general($companyId)['lowStockDefault'] ?? null;
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }
}
