<?php

namespace App\Http\Requests\Marketing;

class CampaignLinkStatusRequest extends CampaignLinkFormRequest
{
    public function rules(): array
    {
        return ['isActive' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['isActive.required' => 'Send isActive true or false.', 'isActive.boolean' => 'Active must be true or false.'];
    }
}
