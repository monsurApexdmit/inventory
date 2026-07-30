<?php

namespace App\Repositories\Eloquent;

use App\Models\ContentPage;
use App\Repositories\Contracts\IContentPageRepository;
use Illuminate\Database\Eloquent\Collection;

class ContentPageRepository implements IContentPageRepository
{
    public function __construct(private readonly ContentPage $model)
    {
    }

    public function findByCompany(int $companyId): Collection
    {
        return $this->model
            ->where('company_id', $companyId)
            ->orderByDesc('updated_at')
            ->get();
    }

    public function findForCompany(int $id, int $companyId): ContentPage
    {
        return $this->model
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function create(array $data): ContentPage
    {
        return $this->model->create($data);
    }

    public function update(ContentPage $page, array $data): ContentPage
    {
        $page->update($data);

        return $page->fresh();
    }

    public function delete(ContentPage $page): void
    {
        $page->delete();
    }
}
