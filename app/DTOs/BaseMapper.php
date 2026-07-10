<?php

namespace App\DTOs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Base Mapper class for converting models to DTOs
 * Provides common mapping functionality
 */
abstract class BaseMapper
{
    /**
     * Convert single model to DTO
     * Child classes must implement this
     */
    abstract public function toDTO(Model $model): BaseDTO;

    /**
     * Convert collection of models to array of DTOs
     */
    public function toDTOCollection(Collection|array $models): array
    {
        $dtos = [];

        foreach ($models as $model) {
            $dtos[] = $this->toDTO($model);
        }

        return $dtos;
    }

    /**
     * Convert to array of DTO arrays
     */
    public function toArrayCollection(Collection|array $models): array
    {
        return array_map(
            fn($model) => $this->toDTO($model)->toArray(),
            $models instanceof Collection ? $models->all() : $models
        );
    }

    /**
     * Helper: Format timestamp to ISO8601
     */
    protected function formatTimestamp($value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \DateTime
            ? $value->toIso8601String()
            : $value;
    }

    /**
     * Helper: Format nested relation
     */
    protected function formatRelation(Model|array|null $relation): ?array
    {
        if ($relation === null) {
            return null;
        }

        if ($relation instanceof Model) {
            return $relation->toArray();
        }

        return $relation;
    }

    /**
     * Helper: Format nested collection
     */
    protected function formatCollectionRelation(Collection|array|null $relations): ?array
    {
        if ($relations === null || (is_array($relations) && empty($relations))) {
            return null;
        }

        $items = [];
        foreach ($relations as $relation) {
            if ($relation instanceof Model) {
                $items[] = $relation->toArray();
            } elseif (is_array($relation)) {
                $items[] = $relation;
            }
        }

        return empty($items) ? null : $items;
    }
}
