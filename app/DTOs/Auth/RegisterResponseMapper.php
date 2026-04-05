<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseMapper;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting register response to RegisterResponseDTO
 */
class RegisterResponseMapper extends BaseMapper
{
    /**
     * Convert register response array to DTO
     */
    public function toDTO(Model $model): RegisterResponseDTO
    {
        $data = is_array($model) ? $model : $model->toArray();

        return new RegisterResponseDTO(
            success: (bool) ($data['success'] ?? true),
            message: $data['message'] ?? 'Registration successful',
            user: $data['user'] ?? null,
            tokens: $data['tokens'] ?? null,
        );
    }
}
