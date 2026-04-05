<?php

namespace App\Repositories\Eloquent;

use App\Models\Permission;
use App\Repositories\Contracts\IPermissionRepository;

class PermissionRepository implements IPermissionRepository
{
    public function __construct(private readonly Permission $model)
    {
    }

    public function findByNames(array $names): array
    {
        return $this->model
            ->whereIn('name', $names)
            ->get()
            ->toArray();
    }
}
