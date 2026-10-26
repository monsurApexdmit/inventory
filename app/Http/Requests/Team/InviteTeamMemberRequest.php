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
            'fullName' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'roleId' => 'required|integer|exists:staff_roles,id',
        ];
    }
}
