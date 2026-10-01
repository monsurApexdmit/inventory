<?php

namespace App\Services\Payment;

use App\DTOs\Payment\DuePaymentDTO;
use App\DTOs\Payment\DuePaymentMapper;
use App\Models\PurchaseOrder;
use App\Models\Sell;
use App\Repositories\Contracts\IDuePaymentRepository;
use App\Repositories\Contracts\IPurchaseOrderRepository;
use App\Repositories\Contracts\ISellRepository;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DuePaymentService
{
    private readonly DuePaymentMapper $mapper;

    /**
     * Whitelist of payable types this service is allowed to record payments against.
     */
    private const PAYABLE_MAP = [
        'sells' => Sell::class,
        'purchase-orders' => PurchaseOrder::class,
    ];

    public function __construct(
        private readonly IDuePaymentRepository $repository,
        private readonly ISellRepository $sellRepository,
        private readonly IPurchaseOrderRepository $purchaseOrderRepository,
    ) {
        $this->mapper = new DuePaymentMapper();
    }

    public function recordPayment(string $payableType, int $payableId, array $data, int $companyId, ?int $recordedBy = null): DuePaymentDTO
    {
        if (!array_key_exists($payableType, self::PAYABLE_MAP)) {
            throw new HttpException(422, 'Unsupported payable type: ' . $payableType);
        }

        $modelClass = self::PAYABLE_MAP[$payableType];
        $direction = $modelClass === Sell::class ? 'in' : 'out';

        $payable = $modelClass === Sell::class
            ? $this->sellRepository->findByIdAndCompany($payableId, $companyId)
            : $this->purchaseOrderRepository->findById($payableId, $companyId);

        if (!$payable) {
            throw new HttpException(404, ucfirst(rtrim($payableType, 's')) . ' not found');
        }

        $totalField = $modelClass === Sell::class ? 'amount' : 'total_amount';
        $amount = (float) $data['amount'];
        $newTotalPaid = (float) $payable->paid_amount + $amount;

        if ($newTotalPaid > (float) $payable->{$totalField}) {
            throw new HttpException(422, 'Payment amount exceeds the outstanding due amount');
        }

        $duePayment = DB::transaction(function () use ($payable, $modelClass, $direction, $data, $amount, $companyId, $recordedBy) {
            $payment = $this->repository->create([
                'company_id' => $companyId,
                'payable_type' => $modelClass,
                'payable_id' => $payable->id,
                'direction' => $direction,
                'amount' => $amount,
                'payment_method' => $data['payment_method'] ?? 'cash',
                'payment_date' => $data['payment_date'],
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $recordedBy,
            ]);

            $payable->recalculate();

            return $payment;
        });

        return $this->mapper->toDTO($duePayment);
    }
}
