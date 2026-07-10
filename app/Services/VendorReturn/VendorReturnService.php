<?php

namespace App\Services\VendorReturn;

use App\DTOs\VendorReturn\VendorReturnDTO;
use App\DTOs\VendorReturn\VendorReturnMapper;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VendorReturn;
use App\Models\VendorReturnItem;
use App\Repositories\Contracts\IVendorReturnRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VendorReturnService
{
    private readonly VendorReturnMapper $mapper;

    public function __construct(private readonly IVendorReturnRepository $repository)
    {
        $this->mapper = new VendorReturnMapper();
    }

    public function list(int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCompany($companyId, $filters);
        $data = array_map(fn ($return) => $this->mapper->toDTO($return), $paginated->items());
        return [
            'data' => $data,
            'total' => $paginated->total(),
            'per_page' => $paginated->perPage(),
            'current_page' => $paginated->currentPage(),
            'last_page' => $paginated->lastPage(),
        ];
    }

    public function get(int $id, int $companyId): VendorReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        return $this->mapper->toDTO($return);
    }

    public function getByVendor(int $vendorId, int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByVendor($vendorId, $companyId, $filters);
        $data = array_map(fn ($return) => $this->mapper->toDTO($return), $paginated->items());
        return [
            'data' => $data,
            'pagination' => [
                'total' => $paginated->total(),
                'perPage' => $paginated->perPage(),
                'currentPage' => $paginated->currentPage(),
                'lastPage' => $paginated->lastPage(),
            ]
        ];
    }

    public function create(int $companyId, array $data): VendorReturnDTO
    {
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;

        // Generate return number if not provided
        if (!isset($dbData['return_number']) || empty($dbData['return_number'])) {
            $dbData['return_number'] = 'VRT-' . (int)(microtime(true) * 10000);
        }

        // Set return date if not provided
        if (!isset($dbData['return_date']) || empty($dbData['return_date'])) {
            $dbData['return_date'] = now();
        }

        $items = $data['items'] ?? [];
        $returnId = 0;

        DB::transaction(function () use (&$dbData, $items, &$returnId) {
            $return = VendorReturn::create($dbData);
            $returnId = $return->id;

            // Create return items and deduct inventory
            foreach ($items as $item) {
                $itemData = [
                    'return_id' => $return->id,
                    'product_id' => $item['productId'] ?? null,
                    'product_name' => $item['productName'],
                    'variant_id' => $item['variantId'] ?? null,
                    'variant_name' => $item['variantName'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unitPrice'] ?? 0,
                    'total_price' => $item['totalPrice'] ?? 0,
                    'unit_cost' => $item['unitCost'] ?? 0,
                    'reason' => $item['reason'],
                ];

                VendorReturnItem::create($itemData);

                // Deduct inventory
                if (isset($item['variantId']) && $item['variantId']) {
                    $variant = ProductVariant::find($item['variantId']);
                    if ($variant) {
                        $variant->decrement('stock', $item['quantity'] ?? 1);
                    }
                } elseif (isset($item['productId']) && $item['productId']) {
                    $product = Product::find($item['productId']);
                    if ($product) {
                        if ($product->stock < ($item['quantity'] ?? 1)) {
                            throw new HttpException(400, 'Insufficient stock for product: ' . $item['productName']);
                        }
                        $product->decrement('stock', $item['quantity'] ?? 1);
                    }
                }
            }
        });

        return $this->get($returnId, $companyId);
    }

    public function update(int $id, int $companyId, array $data): VendorReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        $dbData = $this->mapInputToDb($data);

        // Auto-set completed_date when status becomes completed
        if (isset($data['status']) && $data['status'] === 'completed' && $return->status !== 'completed') {
            $dbData['completed_date'] = now();
        }

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function updateStatus(int $id, int $companyId, array $data): VendorReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        $updateData = ['status' => $data['status']];

        // Auto-set completed_date when status becomes completed
        if ($data['status'] === 'completed' && $return->status !== 'completed') {
            $updateData['completed_date'] = now();
        }

        $this->repository->update($id, $updateData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Vendor return not found');
        }

        $this->repository->delete($id);
    }

    public function getStats(int $companyId): array
    {
        return $this->repository->getStats($companyId);
    }

    private function mapInputToDb(array $data): array
    {
        $dbData = [];

        if (isset($data['returnNumber'])) {
            $dbData['return_number'] = $data['returnNumber'];
        }
        if (isset($data['vendorId'])) {
            $dbData['vendor_id'] = $data['vendorId'];
        }
        if (isset($data['vendorName'])) {
            $dbData['vendor_name'] = $data['vendorName'];
        }
        if (isset($data['totalAmount'])) {
            $dbData['total_amount'] = $data['totalAmount'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['returnDate'])) {
            $dbData['return_date'] = $data['returnDate'];
        }
        if (isset($data['creditType'])) {
            $dbData['credit_type'] = $data['creditType'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['createdBy'])) {
            $dbData['created_by'] = $data['createdBy'];
        }

        return $dbData;
    }
}
