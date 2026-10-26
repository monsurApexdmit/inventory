<?php

namespace App\Services\Notification;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-company notification preferences: events x channels matrix,
 * customer SMS template and quiet hours. Stored on settings.notification_preferences.
 */
class NotificationPreferenceService
{
    public const CHANNELS = ['app', 'email', 'sms', 'cust'];

    public const DEFAULT_TEMPLATE = 'Dear {customer}, your order #{no} ({total}) is confirmed. Thank you — {business}';

    /** [key, group, name, sub, default-on channels, n/a channels] */
    private const EVENTS = [
        ['new_order', 'Sales', 'New order', 'Online or manual order placed', ['app', 'email'], []],
        ['payment_received', 'Sales', 'Payment received', 'bKash, card or COD collected', ['app'], []],
        ['return_requested', 'Sales', 'Refund / return requested', 'From a customer', ['app', 'email'], []],
        ['low_stock', 'Stock', 'Low stock', 'Item reaches its reorder point', ['app', 'email'], ['cust']],
        ['po_received', 'Stock', 'Purchase order received', 'Stock added to a warehouse', ['app'], ['cust']],
        ['batch_expiring', 'Stock', 'Batch expiring soon', '30 days before expiry', ['app', 'email'], ['cust']],
        ['tailor_ready', 'Tailor & team', 'Tailor order ready', 'Ready for pickup', ['app'], []],
        ['salary_due', 'Tailor & team', 'Salary due', '3 days before payday', ['app', 'email', 'sms'], ['cust']],
        ['daily_summary', 'Tailor & team', 'Daily summary', 'Sales, cash and dues at 9 pm', ['app', 'email', 'sms'], ['cust']],
    ];

    /** Notification type (NotificationService::TYPE_*) => event key. Unmapped types are never filtered. */
    private const TYPE_TO_EVENT = [
        'order_placed' => 'new_order',
        'return_requested' => 'return_requested',
        'low_stock' => 'low_stock',
        'batch_expiry' => 'batch_expiring',
        'payroll' => 'salary_due',
        'payment_received' => 'payment_received',
        'po_received' => 'po_received',
        'tailor_ready' => 'tailor_ready',
        'daily_summary' => 'daily_summary',
    ];

    public function catalog(): array
    {
        return array_map(fn ($e) => [
            'key' => $e[0], 'group' => $e[1], 'name' => $e[2], 'sub' => $e[3], 'na' => $e[5],
        ], self::EVENTS);
    }

    private function defaults(): array
    {
        $events = [];
        foreach (self::EVENTS as [$k, , , , $on, $na]) {
            foreach (self::CHANNELS as $c) {
                $events[$k][$c] = in_array($c, $on, true) && !in_array($c, $na, true);
            }
        }
        return ['events' => $events, 'quietFrom' => '22:00', 'quietTo' => '08:00', 'smsTemplate' => self::DEFAULT_TEMPLATE];
    }

    private function normalize(?array $stored): array
    {
        $d = $this->defaults();
        $stored = $stored ?? [];
        foreach (self::EVENTS as [$k, , , , , $na]) {
            foreach (self::CHANNELS as $c) {
                $v = $stored['events'][$k][$c] ?? null;
                if (is_bool($v)) {
                    $d['events'][$k][$c] = in_array($c, $na, true) ? false : $v;
                }
            }
        }
        foreach (['quietFrom', 'quietTo', 'smsTemplate'] as $k) {
            if (array_key_exists($k, $stored) && is_string($stored[$k])) {
                $d[$k] = $stored[$k];
            }
        }
        return $d;
    }

    public function get(int $companyId): array
    {
        $setting = Setting::where('company_id', $companyId)->first();

        return [
            'events' => $this->catalog(),
            'preferences' => $this->normalize($setting?->notification_preferences),
            'smsBalance' => (int) ($setting?->sms_balance ?? 0),
            'smsProviderConnected' => false,
            'preview' => $this->previewContext($companyId),
        ];
    }

    public function update(int $companyId, array $data): array
    {
        $prefs = $this->normalize($data);
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
        $from = trim((string) $prefs['quietFrom']);
        $to = trim((string) $prefs['quietTo']);

        foreach ([$from, $to] as $t) {
            if ($t !== '' && !preg_match($time, $t)) {
                throw new HttpException(422, 'Quiet hours must be valid times.');
            }
        }
        if (($from === '') !== ($to === '')) {
            throw new HttpException(422, 'Set both start and end of quiet hours, or clear both.');
        }
        if ($from !== '' && $from === $to) {
            throw new HttpException(422, 'Quiet hours start and end must be different.');
        }
        $tpl = trim((string) $prefs['smsTemplate']);
        if ($tpl === '') {
            throw new HttpException(422, 'The SMS template cannot be empty.');
        }
        if (mb_strlen($tpl) > 500) {
            throw new HttpException(422, 'The SMS template is too long (500 characters max).');
        }
        $prefs['quietFrom'] = $from;
        $prefs['quietTo'] = $to;
        $prefs['smsTemplate'] = $tpl;

        Setting::updateOrCreate(['company_id' => $companyId], ['notification_preferences' => $prefs]);

        return $this->get($companyId);
    }

    /** Latest sale + business name so the UI can render a real "Customer SMS preview". */
    private function previewContext(int $companyId): array
    {
        $business = DB::table('company_settings')->where('company_id', $companyId)->value('company_name')
            ?: DB::table('companies')->where('id', $companyId)->value('name');
        $sale = DB::table('sells')->where('company_id', $companyId)->orderByDesc('id')->first(['invoice_no', 'customer_name', 'amount']);

        return [
            'business' => (string) ($business ?? ''),
            'sale' => $sale ? ['no' => $sale->invoice_no, 'customer' => $sale->customer_name, 'total' => (float) $sale->amount] : null,
        ];
    }

    /** Is the in-app channel switched on for this notification type? Unmapped types are always on. */
    public function appEnabled(int $companyId, string $type): bool
    {
        $event = self::TYPE_TO_EVENT[$type] ?? null;
        if ($event === null) {
            return true;
        }
        $stored = Setting::where('company_id', $companyId)->value('notification_preferences');
        $prefs = $this->normalize(is_string($stored) ? json_decode($stored, true) : $stored);

        return (bool) $prefs['events'][$event]['app'];
    }

    public function inQuietHours(int $companyId): bool
    {
        $stored = Setting::where('company_id', $companyId)->value('notification_preferences');
        $prefs = $this->normalize(is_string($stored) ? json_decode($stored, true) : $stored);
        $from = $prefs['quietFrom'];
        $to = $prefs['quietTo'];
        if ($from === '' || $to === '') {
            return false;
        }
        $tz = DB::table('company_settings')->where('company_id', $companyId)->value('timezone') ?: config('app.timezone');
        try {
            $now = now($tz)->format('H:i');
        } catch (\Throwable) {
            $now = now()->format('H:i');
        }

        return $from < $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}
