<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fullName' => 'sometimes|required|string|max:255',
            'email'    => 'sometimes|email|max:255',
            'phone'    => 'nullable|string|max:50',
            'roleId'   => 'nullable|integer|exists:staff_roles,id',
        ];
    }
}
