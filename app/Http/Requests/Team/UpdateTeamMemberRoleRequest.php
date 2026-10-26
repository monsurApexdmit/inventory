<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'role' => 'nullable|string|in:owner,admin,staff|required_without:roleId',
            'roleId' => 'nullable|integer|exists:staff_roles,id|required_without:role',
        ];
    }
}
