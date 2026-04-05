<?php

namespace App\Http\Requests\Team;

use Illuminate\Foundation\Http\FormRequest;

class InviteTeamMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'fullName' => 'nullable|string|max:255',
            'roleId' => 'nullable|integer|exists:staff_roles,id',
        ];
    }
}
