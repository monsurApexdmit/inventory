<?php

namespace App\Repositories\Contracts;

interface IPermissionRepository
{
    public function findByNames(array $names): array;
}
