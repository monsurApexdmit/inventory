<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Traits\ApiResponse;
use App\Services\User\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UserService $userService)
    {
    }

    public function index(): JsonResponse
    {
        $dtos = $this->userService->list();
        $data = array_map(fn ($dto) => $dto->toArray(), $dtos);

        return $this->success($data);
    }

    public function show(int $id): JsonResponse
    {
        $dto = $this->userService->get($id);

        return $this->success($dto->toArray());
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        $dto = $this->userService->create($request->validated());

        return $this->success(
            $dto->toArray(),
            'User created successfully',
            201
        );
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $dto = $this->userService->update($id, $request->validated());

        return $this->success($dto->toArray());
    }

    public function destroy(int $id): Response
    {
        $this->userService->delete($id);

        return response()->noContent();
    }
}
