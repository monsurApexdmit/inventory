<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

/**
 * DTO for Auth Response
 */
class AuthResponseDTO extends BaseDTO
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly int $expiresIn,
        public readonly ?string $refreshToken = null,
        public readonly ?array $user = null,
    ) {}

    public function toArray(): array
    {
        return [
            'accessToken' => $this->accessToken,
            'tokenType' => $this->tokenType,
            'expiresIn' => $this->expiresIn,
            'refreshToken' => $this->refreshToken,
            'user' => $this->user,
        ];
    }
}
