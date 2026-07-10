<?php

namespace App\DTOs;

use ArrayAccess;
use JsonSerializable;

/**
 * Base DTO class for all data transfer objects
 * Provides common functionality for converting DTOs to arrays and JSON
 */
abstract class BaseDTO implements ArrayAccess, JsonSerializable
{
    /**
     * Convert DTO to array representation
     * Override in child classes to customize field mapping
     */
    abstract public function toArray(): array;

    /**
     * Get the object as JSON serializable array
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Array Access Implementation
     */
    public function offsetExists(mixed $offset): bool
    {
        return isset($this->{$offset});
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->{$offset} ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->{$offset} = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->{$offset});
    }

    /**
     * Magic getter for easy property access
     */
    public function __get(string $name): mixed
    {
        return $this->{$name} ?? null;
    }
}
