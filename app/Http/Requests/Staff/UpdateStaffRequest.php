<?php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        StaffRequestRules::normalizeInput($this);
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('auth_company_id');

        return StaffRequestRules::rules($companyId, (int) $this->route('id'), false);
    }

    public function messages(): array
    {
        return StaffRequestRules::messages();
    }

    public function withValidator($validator): void
    {
        $companyId = (int) $this->attributes->get('auth_company_id');
        $existing = Staff::where('company_id', $companyId)->find((int) $this->route('id'));
        $validator->after(fn ($v) => StaffRequestRules::crossChecks($v, $this, $companyId, $existing));
    }
}
