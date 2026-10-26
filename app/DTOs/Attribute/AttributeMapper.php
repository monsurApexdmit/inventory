<?php

namespace App\DTOs\Attribute;

use App\DTOs\BaseMapper;
use App\Models\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting Attribute model to AttributeDTO
 */
class AttributeMapper extends BaseMapper
{
    /**
     * Convert Attribute model to DTO
     */
    public function toDTO(Model $model): AttributeDTO
    {
        if (!$model instanceof Attribute) {
            throw new \InvalidArgumentException('Model must be instance of Attribute');
        }

        [$values, $valueItems] = $this->parseValues($model->values);

        return new AttributeDTO(
            id: $model->id,
            companyId: $model->company_id,
            name: $model->name,
            displayName: $model->display_name,
            optionType: $model->option_type,
            values: $values,
            description: $model->description,
            isRequired: (bool) $model->is_required,
            status: (bool) $model->status,
            sortOrder: (int) $model->sort_order,
            createdAt: $this->formatTimestamp($model->created_at),
            updatedAt: $this->formatTimestamp($model->updated_at),
            valueItems: $valueItems,
            usedInProducts: (int) ($model->products_count ?? $model->products()->count()),
        );
    }

    /**
     * Stored `values` is either a legacy comma-separated string or a JSON array of
     * strings / {label,color} objects. Returns [values-as-stored-shape, normalized items].
     */
    private function parseValues(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [$raw, []];
        }

        $decoded = str_starts_with(ltrim($raw), '[') ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $items = [];
            foreach ($decoded as $v) {
                if (is_array($v)) {
                    $label = (string) ($v['label'] ?? '');
                    $color = $v['color'] ?? $v['c'] ?? null;
                } else {
                    $label = (string) $v;
                    $color = null;
                }
                if ($label !== '') {
                    $items[] = ['label' => $label, 'color' => $color];
                }
            }

            return [$decoded, $items];
        }

        $items = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '') {
                $items[] = ['label' => $part, 'color' => null];
            }
        }

        return [$raw, $items];
    }
}
