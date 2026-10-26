<?php

namespace App\Http\Requests\Staff;

use App\Models\Location;
use App\Models\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStaffRequest extends FormRequest
{
    public const STATUSES = ['Active', 'Invited', 'On leave', 'Inactive'];

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

        return StaffRequestRules::rules($companyId, null, true);
    }

    public function messages(): array
    {
        return StaffRequestRules::messages();
    }

    public function withValidator($validator): void
    {
        $companyId = (int) $this->attributes->get('auth_company_id');
        $validator->after(fn ($v) => StaffRequestRules::crossChecks($v, $this, $companyId));
    }
}
