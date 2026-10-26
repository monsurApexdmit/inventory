<?php

namespace App\Http\Requests\Staff;

use App\Models\Location;
use App\Models\Staff;
use App\Models\StaffRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Shared validation for create/update staff (camelCase input, snake_case aliases tolerated). */
class StaffRequestRules
{
    /** Map aliases onto the canonical camelCase keys. */
    public static function normalizeInput(FormRequest $r): void
    {
        $alias = [
            'phone' => 'contact',
            'joining_date' => 'joiningDate',
            'bank_account' => 'bankAccount',
            'account' => 'bankAccount',
            'payment_method' => 'paymentMethod',
            'roleId' => 'staffRoleId',
            'staff_role_id' => 'staffRoleId',
            'emergency_contact' => 'emergencyContact',
            'login_method' => 'loginMethod',
            'location_ids' => 'locationIds',
        ];
        $merge = [];
        foreach ($alias as $from => $to) {
            if ($r->has($from) && !$r->has($to)) {
                $merge[$to] = $r->input($from);
            }
        }
        // empty strings from forms mean "not provided"
        foreach (['email', 'contact', 'nid', 'emergencyContact', 'bankAccount', 'joiningDate', 'pin'] as $k) {
            $v = $merge[$k] ?? $r->input($k);
            if ($r->has($k) || array_key_exists($k, $merge)) {
                if (is_string($v) && trim($v) === '') {
                    $merge[$k] = null;
                }
            }
        }
        if ($r->has('email') && is_string($r->input('email'))) {
            $merge['email'] = trim($r->input('email')) === '' ? null : trim($r->input('email'));
        }
        if ($merge) {
            $r->merge($merge);
        }
    }

    public static function messages(): array
    {
        return ['contact.regex' => 'Enter a valid phone number (digits, optional +, 6-20 characters).'];
    }

    public static function rules(int $companyId, ?int $staffId, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes|required';

        return [
            'name' => "$req|string|max:255",
            'email' => ['nullable', 'email', 'max:255', Rule::unique('staff', 'email')->where('company_id', $companyId)->ignore($staffId)],
            'contact' => [$creating ? 'required' : 'sometimes|required', 'string', 'max:255', 'regex:/^\+?[0-9][0-9\s\-()]{5,19}$/'],
            'joiningDate' => 'nullable|string|max:255',
            'role' => 'nullable|string|max:255',
            'staffRoleId' => ['nullable', 'integer', Rule::exists('staff_roles', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'status' => ['nullable', 'string', Rule::in(CreateStaffRequest::STATUSES)],
            'published' => 'nullable|boolean',
            'avatar' => 'nullable|string',
            'password' => 'nullable|string|min:8',
            'salary' => 'nullable|numeric|min:0',
            'bankAccount' => 'nullable|string|max:255',
            'paymentMethod' => 'nullable|string|max:60',
            'nid' => 'nullable|string|max:255',
            'emergencyContact' => 'nullable|string|max:255',
            'loginMethod' => ['nullable', 'string', Rule::in(['email', 'pin'])],
            'pin' => ['nullable', 'regex:/^\d{4}$/'],
            'locationIds' => 'nullable|array',
            'locationIds.*' => 'integer',
        ];
    }

    public static function crossChecks($validator, FormRequest $r, int $companyId, ?Staff $existing = null): void
    {
        $method = $r->input('loginMethod') ?: ($existing?->login_method ?: 'email');
        $email = $r->has('email') ? $r->input('email') : $existing?->email;

        if ($method === 'email' && empty($email)) {
            $validator->errors()->add('email', 'Email is required for an email login.');
        }
        if ($method === 'pin') {
            $hasPin = $r->filled('pin') || !empty($existing?->pin_hash);
            if (!$hasPin) {
                $validator->errors()->add('pin', 'POS PIN must be exactly 4 digits.');
            }
        }

        if ($r->filled('contact')) {
            $digits = preg_replace('/\D+/', '', (string) $r->input('contact'));
            $dup = Staff::where('company_id', $companyId)
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->whereNotNull('contact')->pluck('contact')
                ->contains(fn ($c) => preg_replace('/\D+/', '', (string) $c) === $digits);
            if ($dup) {
                $validator->errors()->add('contact', 'This phone number is already used by another team member.');
            }
        }

        $ids = $r->input('locationIds');
        if (is_array($ids) && $ids) {
            $valid = Location::where('company_id', $companyId)->whereIn('id', $ids)->count();
            if ($valid !== count(array_unique($ids))) {
                $validator->errors()->add('locationIds', 'One or more locations do not belong to this company.');
            }
        }
    }
}
