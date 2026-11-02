<?php

namespace App\Http\Requests\Marketing;

class StoreCampaignLinkRequest extends CampaignLinkFormRequest
{
    public function rules(): array
    {
        return $this->baseRules(false);
    }
}
