<?php

namespace App\Http\Requests\Setting;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentSettingsRequest extends FormRequest
{
    public const METHODS = [
        // slug => [label, has account fields]
        'cash' => ['Cash', false],
        'bkash' => ['bKash', true],
        'nagad' => ['Nagad', true],
        'card' => ['Card', true],
        'bank' => ['Bank transfer', true],
        'cod' => ['Cash on delivery', false],
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $slugs = implode(',', array_keys(self::METHODS));
        $rules = [
            'default' => ['sometimes', 'string', 'in:' . $slugs],
            'methods' => ['sometimes', 'array'],
            'cod_limit' => ['sometimes', 'numeric', 'min:0'],
            'cod_fee' => ['sometimes', 'numeric', 'min:0'],
            'cod_phone_verify' => ['sometimes', 'in:new,always,never'],
        ];
        foreach (array_keys(self::METHODS) as $slug) {
            $rules["methods.$slug"] = ['sometimes', 'array'];
            $rules["methods.$slug.enabled"] = ['sometimes', 'boolean'];
            $rules["methods.$slug.test"] = ['sometimes', 'boolean'];
            $rules["methods.$slug.channels"] = ['sometimes', 'array'];
            $rules["methods.$slug.channels.*"] = ['string', 'in:pos,online'];
            $rules["methods.$slug.fields"] = ['sometimes', 'nullable', 'array'];
            foreach (['account', 'accountName', 'bankName'] as $f) {
                $rules["methods.$slug.fields.$f"] = ['nullable', 'string', 'max:255'];
            }
        }
        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $methods = $this->input('methods');
            if (!is_array($methods)) {
                return;
            }
            $default = $this->input('default');
            $enabled = 0;
            foreach ($methods as $slug => $m) {
                if (!isset(self::METHODS[$slug])) {
                    $v->errors()->add("methods.$slug", "Unknown payment method \"$slug\".");
                    continue;
                }
                if (!filter_var($m['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }
                $enabled++;
                if (array_key_exists('channels', $m) && (!is_array($m['channels']) || count($m['channels']) === 0)) {
                    $v->errors()->add("methods.$slug.channels", 'Pick at least one channel (POS or online) for an enabled method.');
                }
                [$label, $needsAccount] = self::METHODS[$slug];
                if ($needsAccount && trim((string) ($m['fields']['account'] ?? '')) === '') {
                    $v->errors()->add("methods.$slug.fields.account", "$label needs an account number or ID.");
                }
            }
            if ($enabled === 0) {
                $v->errors()->add('methods', 'Enable at least one payment method.');
            }
            if ($default === null || !filter_var($methods[$default]['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $v->errors()->add('default', 'The default method must be turned on.');
            }
        });
    }
}
