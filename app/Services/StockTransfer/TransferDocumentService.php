<?php

namespace App\Services\StockTransfer;

use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\TransferDocument;
use App\Models\VariantInventory;
use App\Support\ActorName;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Multi-line transfer documents. Each line is still a `stock_transfers` row and
 * stock is moved with the existing StockTransferService logic.
 */
class TransferDocumentService
{
    public function __construct(private readonly StockTransferService $transfers)
    {
    }

    public function create(int $companyId, array $data): array
    {
        $from = (int) $data['from_location_id'];
        $to   = (int) $data['to_location_id'];

        if ($from === $to) {
            throw new HttpException(422, 'From location and to location must be different');
        }
        $locs = Location::where('company_id', $companyId)->whereIn('id', [$from, $to])->count();
        if ($locs !== 2) {
            throw new HttpException(404, 'One or both locations not found');
        }

        // Normalise + validate lines, aggregate duplicates for the stock check.
        $lines = [];
        $need  = [];
        foreach ($data['items'] as $item) {
            $pid = (int) $item['product_id'];
            $vid = !empty($item['variant_id']) ? (int) $item['variant_id'] : null;
            $qty = (int) $item['quantity'];
            if ($qty < 1) {
                throw new HttpException(422, 'Quantity must be at least 1');
            }
            $lines[] = [$pid, $vid, $qty];
            $key = $pid . ':' . ($vid ?? 0);
            $need[$key] = ($need[$key] ?? 0) + $qty;
        }

        return DB::transaction(function () use ($companyId, $data, $from, $to, $lines, $need) {
            foreach ($need as $key => $qty) {
                [$pid, $vid] = array_map('intval', explode(':', $key));
                $product = Product::where('company_id', $companyId)->find($pid);
                if (!$product) {
                    throw new HttpException(404, "Product #{$pid} not found");
                }
                $variant = null;
                if ($vid) {
                    $variant = ProductVariant::where('product_id', $pid)->find($vid);
                    if (!$variant) {
                        throw new HttpException(404, "Variant not found for {$product->name}");
                    }
                }
                $avail = $this->availableAt($product, $variant, $from);
                if ($avail < $qty) {
                    $label = $product->name . ($variant ? ' (' . $variant->name . ')' : '');
                    throw new HttpException(422, "Insufficient stock for {$label}: {$avail} available, {$qty} requested");
                }
            }

            // Next number per company, serialised by locking the company's documents.
            $last = TransferDocument::where('company_id', $companyId)->lockForUpdate()->orderByDesc('id')->value('transfer_no');
            $next = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;
            $units = array_sum(array_column($lines, 2));

            $doc = TransferDocument::create([
                'company_id'       => $companyId,
                'transfer_no'      => 'TR-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'from_location_id' => $from,
                'to_location_id'   => $to,
                'reason'           => $data['reason'] ?? null,
                'sent_with'        => $data['sent_with'] ?? null,
                'note'             => $data['note'] ?? null,
                'status'           => 'completed',
                'units'            => $units,
                'created_by'       => Auth::id(),
                'created_by_name'  => ActorName::current(),
            ]);

            foreach ($lines as [$pid, $vid, $qty]) {
                $dto = $this->transfers->createTransfer($companyId, [
                    'product_id'       => $pid,
                    'variant_id'       => $vid,
                    'from_location_id' => $from,
                    'to_location_id'   => $to,
                    'quantity'         => $qty,
                    'notes'            => $doc->transfer_no,
                ]);
                StockTransfer::whereKey($dto->id)->update(['transfer_document_id' => $doc->id]);
            }

            return $this->format($doc->fresh(['fromLocation', 'toLocation', 'lines.product', 'lines.variant']));
        });
    }

    public function list(int $companyId, array $filters): array
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 500);

        $base = TransferDocument::where('company_id', $companyId);
        if (!empty($filters['status'])) {
            $base->where('status', $filters['status']);
        }
        if (!empty($filters['location_id'])) {
            $loc = (int) $filters['location_id'];
            $base->where(fn ($q) => $q->where('from_location_id', $loc)->orWhere('to_location_id', $loc));
        }
        if (!empty($filters['search'])) {
            $s = trim((string) $filters['search']);
            $base->where(function ($q) use ($s) {
                $q->where('transfer_no', 'like', "%{$s}%")
                    ->orWhereHas('lines.product', fn ($p) => $p->where('name', 'like', "%{$s}%"));
            });
        }

        $stats = [
            'count'      => (clone $base)->count(),
            'unitsMoved' => (int) (clone $base)->where('status', '<>', 'cancelled')->whereNull('reversal_of_id')->sum('units'),
        ];

        $paginated = (clone $base)
            ->with(['fromLocation:id,name', 'toLocation:id,name', 'lines.product:id,name', 'lines.variant:id,name'])
            ->orderByDesc('id')
            ->paginate($perPage);

        return [
            'data'         => array_map(fn ($d) => $this->format($d), $paginated->items()),
            'total'        => $paginated->total(),
            'per_page'     => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'stats'        => $stats,
        ];
    }

    public function cancel(int $companyId, int $id): array
    {
        return DB::transaction(function () use ($companyId, $id) {
            $doc = TransferDocument::where('company_id', $companyId)->lockForUpdate()->find($id);
            if (!$doc) {
                throw new HttpException(404, 'Transfer document not found');
            }
            if ($doc->status !== 'completed') {
                throw new HttpException(422, 'Only completed transfers can be cancelled');
            }
            if ($doc->reversal_of_id) {
                throw new HttpException(422, 'A reversal record cannot be cancelled');
            }

            $lines = StockTransfer::with(['product', 'variant'])->where('transfer_document_id', $doc->id)->get();

            // Destination must still hold every quantity (aggregate per SKU).
            $need = [];
            foreach ($lines as $l) {
                $k = $l->product_id . ':' . ($l->variant_id ?? 0);
                $need[$k] = ['line' => $l, 'qty' => ($need[$k]['qty'] ?? 0) + $l->quantity];
            }
            foreach ($need as $n) {
                $l = $n['line'];
                $avail = $this->availableAt($l->product, $l->variant, $doc->to_location_id);
                if ($avail < $n['qty']) {
                    $label = ($l->product?->name ?? 'Product') . ($l->variant ? ' (' . $l->variant->name . ')' : '');
                    throw new HttpException(422, "Cannot cancel: destination no longer has {$n['qty']} of {$label} (only {$avail} left)");
                }
            }

            foreach ($lines as $l) {
                $this->transfers->reverseLine($l);
                $l->status = 'Cancelled';
                $l->save();
            }

            // Record the return as its own (completed) transfer going the other way.
            $last = TransferDocument::where('company_id', $companyId)->lockForUpdate()->orderByDesc('id')->value('transfer_no');
            $next = $last ? ((int) preg_replace('/\D/', '', $last)) + 1 : 1;
            $reversal = TransferDocument::create([
                'company_id'       => $companyId,
                'transfer_no'      => 'TR-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT),
                'from_location_id' => $doc->to_location_id,
                'to_location_id'   => $doc->from_location_id,
                'reason'           => 'Reversal',
                'note'             => 'Reversal of ' . $doc->transfer_no,
                'status'           => 'completed',
                'units'            => (int) $doc->units,
                'created_by'       => Auth::id(),
                'created_by_name'  => ActorName::current(),
                'reversal_of_id'   => $doc->id,
            ]);
            foreach ($lines as $l) {
                StockTransfer::create([
                    'company_id'           => $companyId,
                    'transfer_document_id' => $reversal->id,
                    'product_id'           => $l->product_id,
                    'variant_id'           => $l->variant_id,
                    'from_location_id'     => $doc->to_location_id,
                    'to_location_id'       => $doc->from_location_id,
                    'quantity'             => $l->quantity,
                    'status'               => 'Completed',
                    'notes'                => $reversal->transfer_no,
                ]);
            }

            $doc->status = 'cancelled';
            $doc->cancelled_at = now();
            $doc->reversed_by_no = $reversal->transfer_no;
            $doc->save();

            return $this->format($doc->fresh(['fromLocation', 'toLocation', 'lines.product', 'lines.variant']));
        });
    }

    /** On-hand quantity of a product (or real variant) at a location. */
    private function availableAt(?Product $product, ?ProductVariant $variant, int $locationId): int
    {
        if (!$product) {
            return 0;
        }
        if ($variant) {
            $row = VariantInventory::where('variant_id', $variant->id)->where('location_id', $locationId)->first();
            if ($row) {
                return (int) $row->quantity;
            }
            // Same fallback the single transfer uses: legacy stock at the product location.
            return $product->location_id === $locationId && !VariantInventory::where('variant_id', $variant->id)->exists()
                ? (int) $product->stock : 0;
        }

        $default = ProductVariant::where('product_id', $product->id)->where('name', 'Default')->first();
        if ($default) {
            $row = VariantInventory::where('variant_id', $default->id)->where('location_id', $locationId)->first();
            if ($row) {
                return (int) $row->quantity;
            }
            if (VariantInventory::where('variant_id', $default->id)->exists()) {
                return 0;
            }
        }
        return $product->location_id === $locationId ? (int) $product->stock : 0;
    }

    private function format(TransferDocument $d): array
    {
        $lines = $d->lines->map(fn (StockTransfer $l) => [
            'productId' => $l->product_id,
            'variantId' => $l->variant_id,
            'name'      => ($l->product?->name ?? 'Product') . ($l->variant && $l->variant->name !== 'Default' ? ' (' . $l->variant->name . ')' : ''),
            'quantity'  => (int) $l->quantity,
        ])->values();

        $parts = $lines->take(2)->map(fn ($l) => $l['name'] . ' ×' . $l['quantity'])->all();
        $summary = implode(', ', $parts);
        if ($lines->count() > 2) {
            $summary .= ' +' . ($lines->count() - 2) . ' more';
        }

        return [
            'id'           => $d->id,
            'transferNo'   => $d->transfer_no,
            'status'       => $d->status,
            'from'         => ['id' => $d->from_location_id, 'name' => $d->fromLocation?->name],
            'to'           => ['id' => $d->to_location_id, 'name' => $d->toLocation?->name],
            'reason'       => $d->reason,
            'sentWith'     => $d->sent_with,
            'note'         => $d->note,
            'units'        => (int) $d->units,
            'itemsSummary' => $summary,
            'lines'        => $lines->all(),
            'createdAt'    => $d->created_at?->toIso8601String(),
            'createdBy'    => $d->created_by_name,
            'canCancel'    => $d->status === 'completed' && !$d->reversal_of_id,
            'isReversal'   => (bool) $d->reversal_of_id,
            'reversedBy'   => $d->reversed_by_no,
        ];
    }
}
