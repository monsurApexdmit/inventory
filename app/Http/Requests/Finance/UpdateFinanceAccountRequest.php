<?php

namespace App\Http\Requests\Finance;

class UpdateFinanceAccountRequest extends StoreFinanceAccountRequest
{
    protected bool $partial = true;
}
