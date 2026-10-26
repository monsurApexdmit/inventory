<?php

namespace App\Services\Sell;

use App\DTOs\Sell\SellDTO;
use App\Models\OrderFollowUp;
use App\Models\Sell;
use App\Support\ActorName;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Order follow-ups: staff phone the customer to confirm an order (typical for COD).
 * Status changes go through SellService so the normal order rules apply.
 */
class OrderFollowUpService
{
    public function __construct(private readonly SellService $sellService)
    {
    }

    /** Follow-ups of an order, newest first. */
    public function list(int $sellId, int $companyId): array
    {
        $this->findSell($sellId, $companyId);

        return OrderFollowUp::where('company_id', $companyId)
            ->where('sell_id', $sellId)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (OrderFollowUp $f) => $this->toArray($f))
            ->all();
    }

    /**
     * Log a follow-up and apply its effect on the order.
     *
     * @return array{followUp: array, order: array}
     */
    public function log(int $sellId, int $companyId, array $data): array
    {
        return DB::transaction(function () use ($sellId, $companyId, $data) {
            $sell = $this->findSell($sellId, $companyId, true);
            if (!self::canFollowUp($sell)) {
                throw new HttpException(422, 'Only pending or confirmed orders that have not shipped can be followed up.');
            }

            $outcome = (string) $data['outcome'];
            $note = isset($data['note']) ? trim((string) $data['note']) : '';
            $note = $note === '' ? null : mb_substr($note, 0, 1000);
            if (in_array($outcome, OrderFollowUp::NOTE_REQUIRED, true) && $note === null) {
                throw new HttpException(422, 'Add a note for this outcome.');
            }

            $next = null;
            if ($outcome === 'call_later' || !empty($data['nextFollowUpAt'])) {
                if (empty($data['nextFollowUpAt'])) {
                    throw new HttpException(422, 'Pick when to call back.');
                }
                $next = Carbon::parse($data['nextFollowUpAt'])->setTimezone(config('app.timezone'));
                if ($next->lessThanOrEqualTo(now())) {
                    throw new HttpException(422, 'The next follow-up must be in the future.');
                }
            }
            // A settled order needs no more calls
            if (in_array($outcome, ['confirmed', 'customer_cancelled'], true)) {
                $next = null;
            }

            // Effect on the order first, so a refused change logs nothing
            if ($outcome === 'confirmed' && $sell->status === 'Pending') {
                $this->sellService->updateStatus($sellId, $companyId, 'Processing');
            } elseif ($outcome === 'customer_cancelled') {
                $this->sellService->updateStatus($sellId, $companyId, 'Cancelled');
                $reason = 'Customer cancelled on follow-up: ' . $note;
                $sell->refresh();
                $sell->update(['notes' => mb_substr(trim(($sell->notes ? $sell->notes . "\n" : '') . $reason), 0, 5000)]);
            }

            $now = now();
            $followUp = OrderFollowUp::create([
                'company_id' => $companyId,
                'sell_id' => $sellId,
                'user_id' => Auth::id(),
                'staff_name' => ActorName::current(),
                'outcome' => $outcome,
                'channel' => $data['channel'] ?? 'call',
                'note' => $note,
                'next_follow_up_at' => $next,
                'created_at' => $now,
            ]);

            Sell::whereKey($sellId)->update([
                'follow_up_count' => DB::raw('COALESCE(follow_up_count, 0) + 1'),
                'last_follow_up_at' => $now,
                'last_follow_up_outcome' => $outcome,
                'next_follow_up_at' => $next,
            ]);

            return [
                'followUp' => $this->toArray($followUp),
                'order' => $this->sellService->get($sellId, $companyId)->toArray(),
            ];
        });
    }

    /** Pending, or confirmed (Processing) but not yet handed to a courier. */
    public static function canFollowUp(Sell $sell): bool
    {
        if ($sell->status === 'Pending') {
            return true;
        }
        return $sell->status === 'Processing'
            && $sell->fulfillment_status !== 'shipped'
            && $sell->shipped_at === null;
    }

    private function findSell(int $sellId, int $companyId, bool $lock = false): Sell
    {
        $q = Sell::where('company_id', $companyId)->whereKey($sellId);
        if ($lock) {
            $q->lockForUpdate();
        }
        $sell = $q->first();
        if (!$sell) {
            throw new HttpException(404, 'Sell not found');
        }
        return $sell;
    }

    private function toArray(OrderFollowUp $f): array
    {
        return [
            'id' => $f->id,
            'sellId' => (int) $f->sell_id,
            'userId' => $f->user_id !== null ? (int) $f->user_id : null,
            'staffName' => $f->staff_name,
            'outcome' => $f->outcome,
            'channel' => $f->channel,
            'note' => $f->note,
            'nextFollowUpAt' => $f->next_follow_up_at?->toIso8601String(),
            'createdAt' => $f->created_at?->toIso8601String(),
        ];
    }
}
