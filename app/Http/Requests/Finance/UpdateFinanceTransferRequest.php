<?php

namespace App\Http\Requests\Finance;

class UpdateFinanceTransferRequest extends StoreFinanceTransferRequest
{
    protected bool $partial = true;
}
