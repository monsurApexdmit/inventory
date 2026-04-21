<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\ShippingAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StorefrontCustomerController extends Controller
{
    // GET /api/store/profile
    public function show(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        return response()->json([
            'success' => true,
            'data'    => $this->formatCustomer($customer),
        ]);
    }

    // PUT /api/store/profile
    public function update(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $request->validate([
            'name'     => 'sometimes|string|max:255',
            'phone'    => 'sometimes|nullable|string|max:50',
            'address'  => 'sometimes|nullable|string',
            'city'     => 'sometimes|nullable|string|max:100',
            'state'    => 'sometimes|nullable|string|max:100',
            'zip_code' => 'sometimes|nullable|string|max:20',
            'country'  => 'sometimes|nullable|string|max:100',
            'password' => 'sometimes|string|min:6',
        ]);

        $updateData = $request->only(['name', 'phone', 'address', 'city', 'state', 'zip_code', 'country']);

        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $customer->update($updateData);

        return response()->json([
            'success' => true,
            'data'    => $this->formatCustomer($customer->fresh()),
        ]);
    }

    // GET /api/store/addresses
    public function addresses(Request $request): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $addresses = ShippingAddress::where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->orderByDesc('is_default')
            ->get()
            ->map(fn($a) => $this->formatAddress($a));

        return response()->json(['success' => true, 'data' => $addresses]);
    }

    // POST /api/store/addresses
    public function addAddress(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $request->validate([
            'full_name'     => 'required|string|max:255',
            'phone'         => 'nullable|string|max:50',
            'email'         => 'nullable|email|max:255',
            'address_line1' => 'required|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city'          => 'nullable|string|max:100',
            'state'         => 'nullable|string|max:100',
            'postal_code'   => 'nullable|string|max:20',
            'country'       => 'nullable|string|max:100',
            'address_type'  => 'nullable|string|max:50',
            'is_default'    => 'boolean',
        ]);

        // If setting as default, unset existing default
        if ($request->boolean('is_default')) {
            ShippingAddress::where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->update(['is_default' => false]);
        }

        $address = ShippingAddress::create([
            'company_id'    => $customer->company_id,
            'customer_id'   => $customer->id,
            'full_name'     => $request->full_name,
            'phone'         => $request->phone,
            'email'         => $request->email,
            'address_line1' => $request->address_line1,
            'address_line2' => $request->address_line2,
            'city'          => $request->city,
            'state'         => $request->state,
            'postal_code'   => $request->postal_code,
            'country'       => $request->country,
            'address_type'  => $request->address_type ?? 'home',
            'is_default'    => $request->boolean('is_default'),
        ]);

        return response()->json(['success' => true, 'data' => $this->formatAddress($address)], 201);
    }

    // PUT /api/store/addresses/{id}
    public function updateAddress(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $address = ShippingAddress::where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->find($id);

        if (!$address) {
            return response()->json(['success' => false, 'message' => 'Address not found'], 404);
        }

        $request->validate([
            'full_name'     => 'sometimes|string|max:255',
            'phone'         => 'sometimes|nullable|string|max:50',
            'address_line1' => 'sometimes|string|max:255',
            'address_line2' => 'sometimes|nullable|string|max:255',
            'city'          => 'sometimes|nullable|string|max:100',
            'state'         => 'sometimes|nullable|string|max:100',
            'postal_code'   => 'sometimes|nullable|string|max:20',
            'country'       => 'sometimes|nullable|string|max:100',
            'address_type'  => 'sometimes|nullable|string|max:50',
            'is_default'    => 'boolean',
        ]);

        if ($request->boolean('is_default')) {
            ShippingAddress::where('company_id', $customer->company_id)
                ->where('customer_id', $customer->id)
                ->update(['is_default' => false]);
        }

        $address->update($request->only([
            'full_name', 'phone', 'email', 'address_line1', 'address_line2',
            'city', 'state', 'postal_code', 'country', 'address_type', 'is_default',
        ]));

        return response()->json(['success' => true, 'data' => $this->formatAddress($address->fresh())]);
    }

    // DELETE /api/store/addresses/{id}
    public function deleteAddress(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $address = ShippingAddress::where('company_id', $customer->company_id)
            ->where('customer_id', $customer->id)
            ->find($id);

        if (!$address) {
            return response()->json(['success' => false, 'message' => 'Address not found'], 404);
        }

        $address->delete();

        return response()->json(['success' => true, 'message' => 'Address deleted successfully']);
    }

    private function formatCustomer($customer): array
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
            'created_at' => $customer->created_at,
        ];
    }

    private function formatAddress(ShippingAddress $address): array
    {
        return [
            'id'            => $address->id,
            'full_name'     => $address->full_name,
            'phone'         => $address->phone,
            'email'         => $address->email,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
            'city'          => $address->city,
            'state'         => $address->state,
            'postal_code'   => $address->postal_code,
            'country'       => $address->country,
            'address_type'  => $address->address_type,
            'is_default'    => $address->is_default,
        ];
    }
}
