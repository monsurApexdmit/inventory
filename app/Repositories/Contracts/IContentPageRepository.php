<?php

namespace App\Repositories\Contracts;

use App\Models\ContentPage;
use Illuminate\Database\Eloquent\Collection;

interface IContentPageRepository
{
    public function findByCompany(int $companyId): Collection;

    public function findForCompany(int $id, int $companyId): ContentPage;

    public function create(array $data): ContentPage;

    public function update(ContentPage $page, array $data): ContentPage;

    public function delete(ContentPage $page): void;
}
