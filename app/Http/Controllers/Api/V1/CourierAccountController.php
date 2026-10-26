<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CourierAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourierAccountController extends Controller
{
    use ApiResponse;

    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        return $this->success(
            CourierAccount::where('company_id', $companyId)->orderBy('provider')->get()
                ->map(fn (CourierAccount $c) => $this->format($c))->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $data = $request->validate([
            'provider'  => ['required', 'string', 'max:100', Rule::unique('courier_accounts')->where('company_id', $companyId)],
            'accountId' => 'nullable|string|max:150',
            'apiKey'    => 'nullable|string|max:500',
            'isActive'  => 'boolean',
        ]);

        $c = CourierAccount::create([
            'company_id' => $companyId,
            'provider'   => $data['provider'],
            'account_id' => $data['accountId'] ?? null,
            'api_key'    => $data['apiKey'] ?? null,
            'is_active'  => $data['isActive'] ?? true,
        ]);

        return $this->success($this->format($c), 'Courier account added', 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $c = CourierAccount::where('company_id', $companyId)->findOrFail($id);

        $data = $request->validate([
            'accountId' => 'nullable|string|max:150',
            'apiKey'    => 'nullable|string|max:500',
            'isActive'  => 'boolean',
        ]);

        $patch = [];
        if (array_key_exists('accountId', $data)) $patch['account_id'] = $data['accountId'];
        // Blank key means "keep the stored key".
        if (!empty($data['apiKey'])) $patch['api_key'] = $data['apiKey'];
        if (array_key_exists('isActive', $data)) $patch['is_active'] = $data['isActive'];
        $c->update($patch);

        return $this->success($this->format($c->fresh()), 'Courier account updated');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        CourierAccount::where('company_id', $companyId)->findOrFail($id)->delete();

        return $this->success(null, 'Courier account removed');
    }

    private function format(CourierAccount $c): array
    {
        $key = (string) ($c->api_key ?? '');

        return [
            'id'        => $c->id,
            'provider'  => $c->provider,
            'accountId' => $c->account_id,
            'apiKey'    => $key === '' ? null : str_repeat('•', 8) . substr($key, -4),
            'hasKey'    => $key !== '',
            'isActive'  => $c->is_active,
        ];
    }
}
