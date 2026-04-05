<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseMapper;
use Illuminate\Database\Eloquent\Model;

/**
 * Mapper for converting auth response to AuthResponseDTO
 */
class AuthResponseMapper extends BaseMapper
{
    /**
     * Convert auth response array to DTO
     */
    public function toDTO(Model $model): AuthResponseDTO
    {
        $data = is_array($model) ? $model : $model->toArray();

        return new AuthResponseDTO(
            accessToken: $data['access_token'] ?? $data['accessToken'] ?? '',
            tokenType: $data['token_type'] ?? $data['tokenType'] ?? 'Bearer',
            expiresIn: (int) ($data['expires_in'] ?? $data['expiresIn'] ?? 3600),
            refreshToken: $data['refresh_token'] ?? $data['refreshToken'] ?? null,
            user: $data['user'] ?? null,
        );
    }
}
