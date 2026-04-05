<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SaasSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'companyName'   => ['required', 'string', 'max:255'],
            'ownerFullName' => ['required', 'string', 'max:255'],
            'email'         => ['required', 'email', 'max:255'],
            'phone'         => ['required', 'string', 'max:50'],
            'password'      => ['required', 'string', 'min:8'],
            'businessType'  => ['nullable', 'string', 'max:100'],
            'website'       => ['nullable', 'url', 'max:255'],
            'country'       => ['nullable', 'string', 'max:100'],
        ];
    }
}
