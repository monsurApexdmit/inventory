<?php

namespace App\Repositories\Contracts;

use App\Models\SupportTicket;
use App\Models\SupportMessage;
use Illuminate\Pagination\LengthAwarePaginator;

interface ISupportTicketRepository
{
    public function findByCompany(int $companyId, array $filters): LengthAwarePaginator;

    public function findById(int $id, int $companyId): ?SupportTicket;

    public function findByNumber(string $ticketNumber, int $companyId): ?SupportTicket;

    public function findByNumberAndGuestToken(string $ticketNumber, int $companyId, string $guestAccessToken): ?SupportTicket;

    public function findByCustomer(int $customerId, int $companyId, array $filters): LengthAwarePaginator;

    public function create(array $data): SupportTicket;

    public function updateStatus(int $id, int $companyId, string $status): bool;

    public function updatePriority(int $id, int $companyId, string $priority): bool;

    public function addMessage(int $ticketId, ?string $body, string $senderType, ?int $customerId, ?string $senderName): SupportMessage;

    public function countByStatus(int $companyId): array;

    public function delete(int $id, int $companyId): bool;
}
