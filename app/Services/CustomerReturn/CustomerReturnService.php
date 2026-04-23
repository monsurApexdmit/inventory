<?php

namespace App\Services\CustomerReturn;

use App\DTOs\CustomerReturn\CustomerReturnDTO;
use App\DTOs\CustomerReturn\CustomerReturnMapper;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\VariantInventory;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Repositories\Contracts\ICustomerReturnRepository;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerReturnService
{
    private readonly CustomerReturnMapper $mapper;

    public function __construct(
        private readonly ICustomerReturnRepository $repository,
        private readonly NotificationService $notificationService,
    )
    {
        $this->mapper = new CustomerReturnMapper();
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

    public function get(int $id, int $companyId): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        return $this->mapper->toDTO($return);
    }

    public function getByCustomer(int $customerId, int $companyId, array $filters): array
    {
        $paginated = $this->repository->findByCustomer($customerId, $companyId, $filters);
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

    public function create(int $companyId, array $data): CustomerReturnDTO
    {
        $dbData = $this->mapInputToDb($data);
        $dbData['company_id'] = $companyId;

        // Generate return number if not provided
        if (!isset($dbData['return_number']) || empty($dbData['return_number'])) {
            $dbData['return_number'] = 'RET-' . (int)(microtime(true) * 10000);
        }

        // Set request date if not provided
        if (!isset($dbData['request_date']) || empty($dbData['request_date'])) {
            $dbData['request_date'] = now();
        }

        $items = $data['items'] ?? [];
        $returnId = 0;

        DB::transaction(function () use (&$dbData, $items, &$returnId) {
            $return = CustomerReturn::create($dbData);
            $returnId = $return->id;

            // Create return items and auto-fill names
            foreach ($items as $item) {
                // Auto-fill product name if productId provided but name missing
                $productName = $item['productName'] ?? null;
                if (!$productName && isset($item['productId'])) {
                    $product = Product::find($item['productId']);
                    if ($product) {
                        $productName = $product->name;
                    }
                }

                // Auto-fill variant name if variantId provided but name missing
                $variantName = $item['variantName'] ?? null;
                if (!$variantName && isset($item['variantId'])) {
                    $variant = ProductVariant::find($item['variantId']);
                    if ($variant) {
                        $variantName = $variant->name;
                    }
                }

                $itemData = [
                    'return_id' => $return->id,
                    'product_id' => $item['productId'] ?? null,
                    'product_name' => $productName ?? $item['productName'],
                    'variant_id' => $item['variantId'] ?? null,
                    'variant_name' => $variantName,
                    'quantity' => $item['quantity'] ?? 1,
                    'price' => $item['price'] ?? 0,
                    'reason' => $item['reason'],
                ];

                CustomerReturnItem::create($itemData);
            }
        });

        $createdReturn = $this->get($returnId, $companyId);

        $this->notificationService->notifyReturnRequested(
            $companyId,
            $dbData['return_number'] ?? "RET-{$returnId}",
            $dbData['customer_name'] ?? 'Customer'
        );

        return $createdReturn;
    }

    public function update(int $id, int $companyId, array $data): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        $dbData = $this->mapInputToDb($data);

        $this->repository->update($id, $dbData);

        return $this->get($id, $companyId);
    }

    public function approve(int $id, int $companyId): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        if ($return->status !== 'pending') {
            throw new HttpException(400, 'Only pending returns can be approved');
        }

        DB::transaction(function () use ($return) {
            // Restock inventory - load fresh items from DB
            $items = \App\Models\CustomerReturnItem::where('return_id', $return->id)->get();
            foreach ($items as $item) {
                if ($item->variant_id) {
                    $variant = ProductVariant::find($item->variant_id);
                    if ($variant) {
                        // Create or update variant inventory at location_id=1
                        $inventory = VariantInventory::firstOrCreate(
                            ['variant_id' => $item->variant_id, 'location_id' => 1],
                            ['quantity' => 0]
                        );
                        $inventory->increment('quantity', $item->quantity);
                    }
                } elseif ($item->product_id) {
                    $product = Product::find($item->product_id);
                    if ($product) {
                        $product->increment('stock', $item->quantity);
                    }
                }
            }

            // Update return status
            $return->update([
                'status' => 'approved',
                'processed_date' => now(),
            ]);
        });

        return $this->get($id, $companyId);
    }

    public function reject(int $id, int $companyId, array $data = []): CustomerReturnDTO
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
        }

        if ($return->status !== 'pending') {
            throw new HttpException(400, 'Only pending returns can be rejected');
        }

        $updateData = [
            'status' => 'rejected',
            'processed_date' => now(),
        ];

        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }

        $this->repository->update($id, $updateData);

        return $this->get($id, $companyId);
    }

    public function delete(int $id, int $companyId): void
    {
        $return = $this->repository->findByIdAndCompany($id, $companyId);

        if (!$return) {
            throw new HttpException(404, 'Customer return not found');
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
        if (isset($data['customerId'])) {
            $dbData['customer_id'] = $data['customerId'];
        }
        if (isset($data['customerName'])) {
            $dbData['customer_name'] = $data['customerName'];
        }
        if (isset($data['orderId'])) {
            $dbData['order_id'] = $data['orderId'];
        }
        if (isset($data['orderNumber'])) {
            $dbData['order_number'] = $data['orderNumber'];
        }
        if (isset($data['totalAmount'])) {
            $dbData['total_amount'] = $data['totalAmount'];
        }
        if (isset($data['status'])) {
            $dbData['status'] = $data['status'];
        }
        if (isset($data['requestDate'])) {
            $dbData['request_date'] = $data['requestDate'];
        }
        if (isset($data['refundMethod'])) {
            $dbData['refund_method'] = $data['refundMethod'];
        }
        if (isset($data['notes'])) {
            $dbData['notes'] = $data['notes'];
        }
        if (isset($data['processedBy'])) {
            $dbData['processed_by'] = $data['processedBy'];
        }

        return $dbData;
    }
}
