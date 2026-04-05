<?php

namespace App\Services\Auth;

use App\Repositories\Contracts\IUserRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LegacyAuthService
{
    public function __construct(
        private readonly IUserRepository $userRepository,
        private readonly JwtService $jwtService,
    ) {}

    public function login(string $email, string $password): array
    {
        $user = $this->userRepository->findByEmail($email);

        if (!$user || !$user->verifyPassword($password)) {
            throw new HttpException(401, 'Invalid email or password.');
        }

        $issued = $this->jwtService->issueLegacyToken($user);

        return [
            'message' => 'Login successful',
            'token'   => $issued['token'],
            'expires' => $issued['expires'],
        ];
    }

    public function logout(string $token): void
    {
        $this->jwtService->blacklist($token);
    }
}
