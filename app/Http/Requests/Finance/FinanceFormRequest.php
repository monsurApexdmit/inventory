<?php

namespace App\Http\Requests\Finance;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base for finance requests: 422 responses carry the first readable error as `message`
 * plus every error under `errors`; booleans may arrive as "true"/"false" strings.
 */
abstract class FinanceFormRequest extends FormRequest
{
    /** Boolean fields to coerce from strings. */
    protected array $booleans = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach ($this->booleans as $key) {
            $v = $this->input($key);
            if (is_string($v)) {
                $l = strtolower(trim($v));
                if (in_array($l, ['true', '1', 'on', 'yes'], true)) {
                    $merge[$key] = true;
                } elseif (in_array($l, ['false', '0', 'off', 'no', ''], true)) {
                    $merge[$key] = false;
                }
            }
        }
        foreach (['kind', 'direction', 'type'] as $key) {
            if (is_string($this->input($key))) {
                $merge[$key] = strtolower(trim($this->input($key)));
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    /** Payment/transfer dates may be up to one day ahead (browser vs server timezone). */
    protected function notFuture(): \Closure
    {
        return function ($attr, $value, $fail) {
            try {
                if (\Carbon\Carbon::parse($value)->startOfDay()->gt(now()->addDay()->startOfDay())) {
                    $fail('Date cannot be in the future.');
                }
            } catch (\Throwable) {
                // the date rule reports it
            }
        };
    }

    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $errors->first() ?: 'Validation failed.',
            'errors' => $errors->toArray(),
        ], 422));
    }
}
