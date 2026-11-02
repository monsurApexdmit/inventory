<?php

namespace App\Http\Controllers\Api\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\FinanceListRequest;
use App\Http\Requests\Finance\StoreFinanceEntryRequest;
use App\Http\Requests\Finance\StoreFinanceTransferRequest;
use App\Http\Requests\Finance\UpdateFinanceEntryRequest;
use App\Http\Requests\Finance\UpdateFinanceTransferRequest;
use App\Http\Traits\ApiResponse;
use App\Services\Finance\FinanceLedgerService;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Transactions (ledger), manual entries and transfers. */
class FinanceLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinanceLedgerService $service)
    {
    }

    private function companyId(Request $request): int
    {
        return (int) $request->attributes->get('auth_company_id');
    }

    private function userId(Request $request): ?int
    {
        $id = $request->attributes->get('auth_user_id');

        return $id ? (int) $id : null;
    }

    public function transactions(FinanceListRequest $request): Response
    {
        $cid = $this->companyId($request);
        if ($request->wantsCsv()) {
            $res = $this->service->transactions($cid, $request->filters(), true);

            return Csv::download('transactions-' . now()->format('Y-m-d') . '.csv', [
                'Date' => 'date', 'Type' => 'sourceLabel', 'Direction' => 'direction', 'Gross' => 'gross', 'Fee' => 'fee', 'Net' => 'net',
                'Currency' => 'currency', 'Account' => 'accountName', 'Method' => 'method', 'Channel' => 'channel', 'Party' => 'party',
                'Reference' => 'reference', 'Category' => 'category', 'Note' => 'note', 'Running balance' => 'runningBalance', 'Id' => 'id',
            ], $res['csv']);
        }
        $res = $this->service->transactions($cid, $request->filters());

        return $this->success($res['data'], 'Success', 200, $res['meta']);
    }

    // ─── Entries ────────────────────────────────────────────────────────────

    public function showEntry(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->getEntry($id, $this->companyId($request))->toArray());
    }

    public function storeEntry(StoreFinanceEntryRequest $request): JsonResponse
    {
        $dto = $this->service->createEntry($this->companyId($request), $request->validated(), $this->userId($request));

        return $this->success($dto->toArray(), 'Entry added', 201);
    }

    public function updateEntry(UpdateFinanceEntryRequest $request, int $id): JsonResponse
    {
        return $this->success($this->service->updateEntry($id, $this->companyId($request), $request->validated())->toArray(), 'Entry updated');
    }

    public function destroyEntry(Request $request, int $id): JsonResponse
    {
        $this->service->deleteEntry($id, $this->companyId($request));

        return $this->success(null, 'Entry deleted');
    }

    // ─── Transfers ──────────────────────────────────────────────────────────

    public function transfers(FinanceListRequest $request): JsonResponse
    {
        $res = $this->service->listTransfers($this->companyId($request), $request->filters());

        return $this->success($res['data'], 'Success', 200, $res['meta']);
    }

    public function showTransfer(Request $request, int $id): JsonResponse
    {
        return $this->success($this->service->getTransfer($id, $this->companyId($request))->toArray());
    }

    public function storeTransfer(StoreFinanceTransferRequest $request): JsonResponse
    {
        $dto = $this->service->createTransfer($this->companyId($request), $request->validated(), $this->userId($request));

        return $this->success($dto->toArray(), 'Transfer recorded', 201);
    }

    public function updateTransfer(UpdateFinanceTransferRequest $request, int $id): JsonResponse
    {
        return $this->success($this->service->updateTransfer($id, $this->companyId($request), $request->validated())->toArray(), 'Transfer updated');
    }

    public function destroyTransfer(Request $request, int $id): JsonResponse
    {
        $this->service->deleteTransfer($id, $this->companyId($request));

        return $this->success(null, 'Transfer deleted');
    }
}
