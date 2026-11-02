<?php

namespace App\Http\Requests\Marketing;

class UpdateCampaignLinkRequest extends CampaignLinkFormRequest
{
    public function rules(): array
    {
        return $this->baseRules(true);
    }
}
