<?php

namespace App\Http\Controllers\Api\Tailor;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\TailorDorji;
use App\Services\Tailor\TailorAssignmentBoardService;
use App\Services\Tailor\TailorDorjiOverviewService;
use App\Services\Tailor\TailorMeasurementSetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dorjis, Assignments and Measurements screens.
 */
class TailorWorkshopController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TailorDorjiOverviewService $dorjis,
        private readonly TailorAssignmentBoardService $board,
        private readonly TailorMeasurementSetService $sets,
    ) {}

    private function cid(Request $r): int { return (int) $r->attributes->get('auth_company_id'); }
    private function uid(Request $r): int { return (int) $r->attributes->get('auth_user_id', 0); }

    private function domain(\DomainException $e): JsonResponse
    {
        $code = in_array($e->getCode(), [409, 422], true) ? $e->getCode() : 422;
        return $this->error($e->getMessage(), $code, $code === 409 ? ['capacity' => true] : null);
    }

    // ─── Dorjis ──────────────────────────────────────────────────────────────

    private const DORJI_RULES = [
        'address' => 'nullable|string', 'speciality' => 'nullable|array', 'speciality.*' => 'string|max:60',
        'commission_type' => 'nullable|in:fixed,percentage,salary', 'commission_value' => 'nullable|numeric|min:0',
        'status' => 'nullable|in:active,on_leave,inactive', 'notes' => 'nullable|string',
        'max_pieces' => 'nullable|integer|min:0',
    ];

    public function dorjiOverview(Request $r): JsonResponse
    {
        $d = $this->dorjis->overview($this->cid($r), $r->query());
        return $this->success($d['rows'], 'Dorjis retrieved', 200, ['pagination' => $d['pagination'], 'stats' => $d['stats']]);
    }

    public function dorjiStore(Request $r): JsonResponse
    {
        $v = $r->validate(array_merge(self::DORJI_RULES, ['name' => 'required|string|max:255', 'phone' => 'required|string|max:30']));
        $cid = $this->cid($r);
        if (TailorDorji::where('company_id', $cid)->whereRaw('lower(name) = ?', [mb_strtolower($v['name'])])->where('phone', $v['phone'])->exists()) {
            return $this->error('This dorji already exists.', 422);
        }
        $d = TailorDorji::create(array_merge($v, ['company_id' => $cid]));
        return $this->success($this->dorjis->show($cid, $d), 'Dorji created', 201);
    }

    public function dorjiUpdate(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(array_merge(self::DORJI_RULES, ['name' => 'sometimes|string|max:255', 'phone' => 'sometimes|string|max:30']));
        $cid = $this->cid($r);
        $d = TailorDorji::where('company_id', $cid)->findOrFail($id);
        $name = $v['name'] ?? $d->name; $phone = $v['phone'] ?? $d->phone;
        if (TailorDorji::where('company_id', $cid)->where('id', '!=', $id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->where('phone', $phone)->exists()) {
            return $this->error('This dorji already exists.', 422);
        }
        $d->update($v);
        return $this->success($this->dorjis->show($cid, $d->fresh()), 'Dorji updated');
    }

    public function dorjiDestroy(Request $r, int $id): JsonResponse
    {
        $cid = $this->cid($r);
        $d = TailorDorji::where('company_id', $cid)->findOrFail($id);
        if ($msg = $this->dorjis->deleteBlocker($cid, $d)) return $this->error($msg, 422);
        $d->delete();
        return $this->success(null, 'Dorji deleted');
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function assignmentBoard(Request $r): JsonResponse
    {
        $d = $this->board->board($this->cid($r), $r->query());
        return $this->success($d['rows'], 'Assignments retrieved', 200, ['pagination' => $d['pagination'], 'stats' => $d['stats']]);
    }

    public function assignmentOptions(Request $r): JsonResponse
    {
        return $this->success($this->board->options($this->cid($r)), 'Assignment options retrieved');
    }

    public function assignmentStore(Request $r): JsonResponse
    {
        $v = $r->validate([
            'order_id' => 'required|integer', 'dorji_id' => 'required|integer',
            'assigned_date' => 'required|date', 'expected_completion' => 'nullable|date|after_or_equal:assigned_date',
            'task' => 'nullable|string|max:255', 'pieces' => 'nullable|integer|min:1', 'rate_per_piece' => 'nullable|numeric|min:0',
            'dorji_charge' => 'nullable|numeric|min:0',
            'work_status' => 'nullable|in:assigned,in_progress,completed,returned',
            'admin_notes' => 'nullable|string', 'force' => 'nullable|boolean',
        ]);
        $force = (bool) ($v['force'] ?? false); unset($v['force']);
        try {
            return $this->success($this->board->format($this->board->create($this->cid($r), $v, $force)), 'Assignment created', 201);
        } catch (\DomainException $e) {
            return $this->domain($e);
        }
    }

    public function assignmentUpdate(Request $r, int $id): JsonResponse
    {
        $v = $r->validate([
            'dorji_id' => 'nullable|integer', 'expected_completion' => 'nullable|date',
            'task' => 'nullable|string|max:255', 'pieces' => 'nullable|integer|min:1', 'rate_per_piece' => 'nullable|numeric|min:0',
            'dorji_charge' => 'nullable|numeric|min:0',
            'work_status' => 'nullable|in:assigned,in_progress,completed,returned,paid',
            'admin_notes' => 'nullable|string',
        ]);
        $v = array_filter($v, fn($x, $k) => $x !== null || in_array($k, ['expected_completion', 'admin_notes'], true), ARRAY_FILTER_USE_BOTH);
        try {
            [$a, $notices] = $this->board->update($this->cid($r), $id, $v, $this->uid($r));
            return $this->success($this->board->format($a), 'Assignment updated', 200, $notices);
        } catch (\DomainException $e) {
            return $this->domain($e);
        }
    }

    public function assignmentDestroy(Request $r, int $id): JsonResponse
    {
        try {
            $this->board->delete($this->cid($r), $id);
            return $this->success(null, 'Assignment deleted');
        } catch (\DomainException $e) {
            return $this->domain($e);
        }
    }

    // ─── Measurements ────────────────────────────────────────────────────────

    private const M_RULES = [
        'product_type' => 'string|max:100', 'label' => 'nullable|string|max:120',
        'unit' => 'nullable|in:in,cm', 'fit_preference' => 'nullable|in:Regular,Slim,Loose',
        'values' => 'nullable|array', 'values.*' => 'nullable|numeric|min:0',
        'custom_fields' => 'nullable|array', 'notes' => 'nullable|string',
        'chest' => 'nullable|numeric', 'waist' => 'nullable|numeric', 'hip' => 'nullable|numeric', 'shoulder' => 'nullable|numeric',
        'sleeve' => 'nullable|numeric', 'length' => 'nullable|numeric', 'neck' => 'nullable|numeric', 'bottom_length' => 'nullable|numeric',
        'inseam' => 'nullable|numeric', 'pajama_waist' => 'nullable|numeric', 'pajama_length' => 'nullable|numeric',
    ];

    public function measurementCustomers(Request $r): JsonResponse
    {
        $d = $this->sets->customers($this->cid($r), $r->query());
        return $this->success($d['rows'], 'Customers retrieved', 200, ['pagination' => $d['pagination']]);
    }

    public function measurementSets(Request $r, int $customerId): JsonResponse
    {
        return $this->success($this->sets->setsForCustomer($this->cid($r), $customerId), 'Measurement sets retrieved');
    }

    public function measurementStore(Request $r): JsonResponse
    {
        $rules = self::M_RULES;
        $rules['product_type'] = 'required|string|max:100';
        $v = $r->validate(array_merge($rules, ['customer_id' => 'required|integer', 'measured_at' => 'required|date|before_or_equal:today']));
        $m = $this->sets->create($this->cid($r), $v);
        return $this->success($this->sets->format($m), 'Measurement saved', 201);
    }

    public function measurementUpdate(Request $r, int $id): JsonResponse
    {
        $v = $r->validate(array_merge(self::M_RULES, ['measured_at' => 'sometimes|date|before_or_equal:today']));
        return $this->success($this->sets->format($this->sets->update($this->cid($r), $id, $v)), 'Measurement updated');
    }

    public function measurementDestroy(Request $r, int $id): JsonResponse
    {
        $this->sets->delete($this->cid($r), $id);
        return $this->success(null, 'Measurement deleted');
    }
}
