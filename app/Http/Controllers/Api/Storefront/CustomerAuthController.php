<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class CustomerAuthController extends Controller
{
    // POST /api/store/customer/register
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer|exists:companies,id',
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255',
            'password'   => 'required|string|min:6',
            'phone'      => 'nullable|string|max:50',
        ]);

        $exists = Customer::where('company_id', $request->company_id)
            ->where('email', $request->email)
            ->exists();

        if ($exists) {
            return response()->json(['success' => false, 'message' => 'Email already registered'], 422);
        }

        $customer = Customer::create([
            'company_id' => $request->company_id,
            'name'       => $request->name,
            'email'      => $request->email,
            'phone'      => $request->phone,
            'password'   => Hash::make($request->password),
            'status'     => 'active',
        ]);

        $token = $this->generateToken($customer);

        return response()->json([
            'success'  => true,
            'token'    => $token,
            'customer' => $this->formatCustomer($customer),
        ], 201);
    }

    // POST /api/store/customer/login
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'company_id' => 'required|integer',
            'email'      => 'required|email',
            'password'   => 'required|string',
        ]);

        $customer = Customer::where('company_id', $request->company_id)
            ->where('email', $request->email)
            ->where('status', 'active')
            ->first();

        if (!$customer || !$this->passwordMatches($request->password, $customer)) {
            return response()->json(['success' => false, 'message' => 'Invalid credentials'], 401);
        }

        $token = $this->generateToken($customer);

        return response()->json([
            'success'  => true,
            'token'    => $token,
            'customer' => $this->formatCustomer($customer),
        ]);
    }

    private function generateToken(Customer $customer): string
    {
        $payload = base64_encode("{$customer->id}|{$customer->company_id}|" . time());
        $sig     = hash_hmac('sha256', $payload, config('app.key'));
        return "{$payload}.{$sig}";
    }

    private function passwordMatches(string $plainPassword, Customer $customer): bool
    {
        $hashedPassword = $customer->password;

        if (!$hashedPassword) {
            return false;
        }

        try {
            return Hash::check($plainPassword, $hashedPassword);
        } catch (RuntimeException) {
            // Accept legacy bcrypt variants like $2a$ without crashing login.
            $matches = password_verify($plainPassword, $hashedPassword);

            if ($matches && Hash::needsRehash($hashedPassword)) {
                $customer->forceFill([
                    'password' => Hash::make($plainPassword),
                ])->save();
            }

            return $matches;
        }
    }

    private function formatCustomer(Customer $customer): array
    {
        return [
            'id'         => $customer->id,
            'name'       => $customer->name,
            'email'      => $customer->email,
            'phone'      => $customer->phone,
            'address'    => $customer->address,
            'city'       => $customer->city,
            'state'      => $customer->state,
            'zip_code'   => $customer->zip_code,
            'country'    => $customer->country,
            'company_id' => $customer->company_id,
        ];
    }
}
