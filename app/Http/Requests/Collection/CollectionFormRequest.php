<?php

namespace App\Http\Requests\Collection;

use App\Models\Collection;
use App\Services\Collection\CollectionRuleEngine;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Shared base for collection requests:
 *  - accepts JSON or multipart (rules / productIds may arrive as JSON strings, booleans as "true"/"false")
 *  - 422 responses carry the first readable error as `message` plus every error under `errors`
 *  - rules are validated + normalised by CollectionRuleEngine (needs the company for category/brand checks)
 */
abstract class CollectionFormRequest extends FormRequest
{
    private array $normalizedRules = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['rules', 'productIds', 'ids'] as $key) {
            $v = $this->input($key);
            if (is_string($v) && $v !== '') {
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    $merge[$key] = $decoded;
                }
            }
        }
        foreach (['isPublished', 'showOnHomepage'] as $key) {
            $v = $this->input($key);
            if (is_string($v)) {
                $lower = strtolower(trim($v));
                if (in_array($lower, ['true', 'on', 'yes'], true)) {
                    $merge[$key] = true;
                } elseif (in_array($lower, ['false', 'off', 'no', ''], true)) {
                    $merge[$key] = false;
                }
            }
        }
        foreach (['type', 'match', 'productSort'] as $key) {
            $v = $this->input($key);
            if (is_string($v)) {
                $merge[$key] = strtolower(trim($v));
            }
        }
        if ($merge) {
            $this->merge($merge);
        }
    }

    protected function imageRule(): \Closure
    {
        return function ($attr, $value, $fail) {
            if ($value instanceof \Illuminate\Http\UploadedFile) {
                if (!$value->isValid() || !in_array(strtolower($value->getClientOriginalExtension()), ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) || $value->getSize() > 5 * 1024 * 1024) {
                    $fail('The image must be a jpg, png, webp or gif file up to 5MB.');
                }
            } elseif (!is_string($value) || strlen($value) > 255) {
                $fail('The image must be a jpg, png, webp or gif file up to 5MB, or a URL up to 255 characters.');
            }
        };
    }

    public function messages(): array
    {
        $sorts = implode(', ', Collection::SORTS);

        return [
            'name.required' => 'Collection name is required.',
            'name.string' => 'Collection name must be text.',
            'name.max' => 'Collection name may not be longer than 150 characters.',
            'slug.max' => 'The slug may not be longer than 191 characters.',
            'description.max' => 'The description may not be longer than 5000 characters.',
            'type.required' => 'Choose a collection type (manual or automatic).',
            'type.in' => 'Type must be manual or automatic.',
            'match.in' => 'Match must be all or any.',
            'productSort.in' => "Product sort must be one of: {$sorts}.",
            'isPublished.boolean' => 'Published must be true or false.',
            'showOnHomepage.boolean' => 'Show on homepage must be true or false.',
            'seoTitle.max' => 'SEO title may not be longer than 255 characters.',
            'seoDescription.max' => 'SEO description may not be longer than 500 characters.',
            'productIds.array' => 'Products must be a list of product ids.',
            'productIds.max' => 'A collection can hold at most 1000 hand-picked products.',
            'productIds.*.integer' => 'Each product id must be a number.',
            'productIds.*.min' => 'Each product id must be a number.',
            'limit.integer' => 'Limit must be a number.',
            'limit.min' => 'Limit must be at least 1.',
            'limit.max' => 'Limit may not be greater than :max.',
            'ids.required' => 'Send the collection ids in their new order.',
            'ids.array' => 'Ids must be a list of collection ids.',
            'ids.min' => 'Send the collection ids in their new order.',
            'ids.*.integer' => 'Each collection id must be a number.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if (!$this->has('rules')) {
                return;
            }
            $companyId = (int) $this->attributes->get('auth_company_id');
            [$rules, $errors] = app(CollectionRuleEngine::class)->normalize($this->input('rules'), $companyId);
            foreach ($errors as $key => $messages) {
                foreach ($messages as $m) {
                    $v->errors()->add($key, $m);
                }
            }
            $this->normalizedRules = $rules;
        });
    }

    /** Validated data with rules replaced by their normalised form. */
    public function payload(): array
    {
        $data = $this->validated();
        if ($this->has('rules')) {
            $data['rules'] = $this->normalizedRules;
        }
        if ($this->has('image') || $this->hasFile('image')) {
            $data['image'] = $this->file('image') ?? $this->input('image');
        }

        return $data;
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
