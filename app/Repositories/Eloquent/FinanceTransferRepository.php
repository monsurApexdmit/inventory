<?php

namespace App\Repositories\Eloquent;

use App\Models\FinanceTransfer;
use App\Repositories\Contracts\IFinanceTransferRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class FinanceTransferRepository implements IFinanceTransferRepository
{
    public function __construct(private readonly FinanceTransfer $model)
    {
    }

    private function filtered(int $companyId, array $f): Builder
    {
        return $this->model->newQuery()->where('company_id', $companyId)
            ->when(!empty($f['from']), fn ($q) => $q->where('date', '>=', $f['from']))
            ->when(!empty($f['to']), fn ($q) => $q->where('date', '<=', $f['to']))
            ->when(!empty($f['accountId']), fn ($q) => $q->where(fn ($w) => $w->where('from_account_id', (int) $f['accountId'])->orWhere('to_account_id', (int) $f['accountId'])));
    }

    public function paginateByCompany(int $companyId, array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['perPage'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        return $this->filtered($companyId, $filters)->with(['fromAccount', 'toAccount'])
            ->orderByDesc('date')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function totals(int $companyId, array $filters): array
    {
        $r = $this->filtered($companyId, $filters)->selectRaw('COALESCE(SUM(amount), 0) AS amt, COUNT(*) AS n')->first();

        return ['amount' => round((float) $r->amt, 2), 'count' => (int) $r->n];
    }

    public function findByIdAndCompany(int $id, int $companyId): ?FinanceTransfer
    {
        return $this->model->newQuery()->with(['fromAccount', 'toAccount'])->where('company_id', $companyId)->find($id);
    }

    public function create(array $data): FinanceTransfer
    {
        return $this->model->newQuery()->create($data)->load(['fromAccount', 'toAccount']);
    }

    public function update(FinanceTransfer $transfer, array $data): FinanceTransfer
    {
        $transfer->fill($data)->save();

        return $transfer->fresh(['fromAccount', 'toAccount']);
    }

    public function delete(FinanceTransfer $transfer): void
    {
        $transfer->delete();
    }
}
