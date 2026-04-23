<?php

use App\Models\Customer;
use App\Models\SaasUser;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('support.company.{companyId}', function (mixed $user, int $companyId): bool {
    return $user instanceof SaasUser && (int) $user->company_id === $companyId;
});

Broadcast::channel('support.ticket.{ticketId}', function (mixed $user, int $ticketId): bool {
    $ticket = SupportTicket::query()->select(['id', 'company_id', 'customer_id'])->find($ticketId);

    if (!$ticket) {
        return false;
    }

    if ($user instanceof SaasUser) {
        return (int) $user->company_id === (int) $ticket->company_id;
    }

    if ($user instanceof Customer) {
        return (int) $user->company_id === (int) $ticket->company_id
            && (int) $user->id === (int) $ticket->customer_id;
    }

    return false;
});
