<?php

namespace App\Http\Requests\Finance;

use App\Services\Finance\FinancePeriod;

/** Shared query validation for finance lists and reports (period, paging, ids, direction). */
class FinanceListRequest extends FinanceFormRequest
{
    public function rules(): array
    {
        return [
            'preset' => ['sometimes', 'nullable', 'string', 'in:' . implode(',', array_keys(FinancePeriod::PRESETS))],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'asOf' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'accountId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'productId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'variantId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'locationId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'direction' => ['sometimes', 'nullable', 'string', 'in:in,out'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:30'],
            'compare' => ['sometimes', 'nullable', 'string', 'in:previous,none'],
            'format' => ['sometimes', 'nullable', 'string', 'in:json,csv'],
            'channel' => ['sometimes', 'nullable', 'string', 'in:all,pos,online,manual,storefront'],
            'cutoverDate' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'preset.in' => 'Unknown period preset.',
            'from.date_format' => 'From date must be a valid date (YYYY-MM-DD).',
            'to.date_format' => 'To date must be a valid date (YYYY-MM-DD).',
            'asOf.date_format' => 'The as-of date must be a valid date (YYYY-MM-DD).',
            'page.integer' => 'Page must be a number.',
            'page.min' => 'Page must be at least 1.',
            'perPage.integer' => 'Per page must be a number.',
            'perPage.min' => 'Per page must be at least 1.',
            'perPage.max' => 'Per page may not be greater than 100.',
            'per_page.max' => 'Per page may not be greater than 100.',
            'limit.max' => 'Limit may not be greater than 50.',
            'accountId.integer' => 'Account must be a number.',
            'productId.integer' => 'Product must be a number.',
            'variantId.integer' => 'Variant must be a number.',
            'locationId.integer' => 'Location must be a number.',
            'direction.in' => 'Direction must be in or out.',
            'search.max' => 'Search may not be longer than 100 characters.',
            'compare.in' => 'Compare must be previous or none.',
            'format.in' => 'Format must be json or csv.',
            'channel.in' => 'Unknown channel. Use all, pos, online or manual.',
            'cutoverDate.date_format' => 'Cutover date must be a valid date (YYYY-MM-DD).',
        ];
    }

    /** Raw query (validated keys + list filters like sourceType/type/method/bucket/payment/categoryId/includeInactive). */
    public function filters(): array
    {
        return $this->query();
    }

    public function wantsCsv(): bool
    {
        return strtolower((string) $this->query('format')) === 'csv';
    }
}
