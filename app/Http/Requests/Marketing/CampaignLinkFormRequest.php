<?php

namespace App\Http\Requests\Marketing;

use App\Models\CampaignLink;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Shared rules / messages for creating and updating campaign links (422 → first readable error as `message`). */
abstract class CampaignLinkFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['channel', 'targetType'] as $k) {
            if (is_string($this->input($k))) {
                $merge[$k] = strtolower(trim($this->input($k)));
            }
        }
        if ($this->input('targetType') === 'quick-order' || $this->input('targetType') === 'quickOrder') {
            $merge['targetType'] = 'quick_order';
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    protected function baseRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:150'],
            'channel' => [$req, 'string', 'in:' . implode(',', CampaignLink::CHANNELS)],
            'targetType' => [$req, 'string', 'in:' . implode(',', CampaignLink::TARGET_TYPES)],
            'targetId' => ['nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{4,20}$/'],
            'utmSource' => ['nullable', 'string', 'max:100'],
            'utmMedium' => ['nullable', 'string', 'max:100'],
            'utmCampaign' => ['nullable', 'string', 'max:100'],
            'utmContent' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Give the link a name.',
            'name.max' => 'The name may not be longer than 150 characters.',
            'channel.required' => 'Choose a channel.',
            'channel.in' => 'Channel must be one of: ' . implode(', ', CampaignLink::CHANNELS),
            'targetType.required' => 'Choose what the link opens.',
            'targetType.in' => 'Target must be one of: ' . implode(', ', CampaignLink::TARGET_TYPES),
            'targetId.integer' => 'Choose a product.',
            'targetId.min' => 'Choose a product.',
            'code.regex' => 'The code may only contain letters, numbers, - and _ (4-20 characters).',
            'utmSource.max' => 'UTM values may not be longer than 100 characters.',
            'utmMedium.max' => 'UTM values may not be longer than 100 characters.',
            'utmCampaign.max' => 'UTM values may not be longer than 100 characters.',
            'utmContent.max' => 'UTM values may not be longer than 100 characters.',
            'notes.max' => 'Notes may not be longer than 2000 characters.',
            'isActive.boolean' => 'Active must be true or false.',
        ];
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
