<?php

namespace App\DTOs\Auth;

use App\DTOs\BaseDTO;

/**
 * DTO for Register Response
 */
class RegisterResponseDTO extends BaseDTO
{
    public function __construct(
        public readonly bool $success,
        public readonly string $message,
        public readonly ?array $user = null,
        public readonly ?array $tokens = null,
    ) {}

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'message' => $this->message,
            'user' => $this->user,
            'tokens' => $this->tokens,
        ];
    }
}
