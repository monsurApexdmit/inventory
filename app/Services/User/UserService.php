<?php

namespace App\Services\User;

use App\DTOs\User\UserDTO;
use App\DTOs\User\UserMapper;
use App\Repositories\Contracts\IUserRepository;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserService
{
    private readonly UserMapper $mapper;

    public function __construct(private readonly IUserRepository $userRepository)
    {
        $this->mapper = new UserMapper();
    }

    public function list(): array
    {
        $users = $this->userRepository->findAll();

        return array_map(fn ($user) => $this->mapper->toDTO($user), $users);
    }

    public function get(int $id): UserDTO
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw new HttpException(404, 'User not found');
        }

        return $this->mapper->toDTO($user);
    }

    public function create(array $data): UserDTO
    {
        $user = $this->userRepository->create($data);

        return $this->mapper->toDTO($user);
    }

    public function update(int $id, array $data): UserDTO
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw new HttpException(404, 'User not found');
        }

        $this->userRepository->update($id, $data);

        return $this->get($id);
    }

    public function delete(int $id): void
    {
        $user = $this->userRepository->findById($id);

        if (!$user) {
            throw new HttpException(404, 'User not found');
        }

        $this->userRepository->delete($id);
    }
}
