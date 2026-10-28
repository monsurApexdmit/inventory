<?php

namespace App\Http\Requests\Finance;

class UpdateFinanceEntryRequest extends StoreFinanceEntryRequest
{
    protected bool $partial = true;
}
