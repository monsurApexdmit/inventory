<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'username' => "nullable|string|unique:users,username,{$userId}|max:255",
            'email' => "nullable|email|unique:users,email,{$userId}|max:255",
            'password' => 'nullable|string|min:8',
            'roleId' => 'nullable|integer|exists:roles,id',
            'address' => 'nullable|string|max:255',
        ];
    }
}
