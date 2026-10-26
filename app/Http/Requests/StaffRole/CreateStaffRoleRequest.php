<?php

namespace App\Http\Requests\StaffRole;

use Illuminate\Foundation\Http\FormRequest;

class CreateStaffRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'permissions' => 'nullable|array',
            'permissions.*.permissionId' => 'required_with:permissions|integer|exists:permissions,id',
            'permissions.*.read' => 'nullable|boolean',
            'permissions.*.write' => 'nullable|boolean',
            'permissions.*.create' => 'nullable|boolean',
            'permissions.*.edit' => 'nullable|boolean',
            'description' => 'nullable|string|max:255',
            'permissions.*.delete' => 'nullable|boolean',
        ];
    }
}
