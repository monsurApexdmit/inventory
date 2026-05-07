<?php

use App\Models\Customer;
use App\Models\SaasUser;
use App\Models\Staff;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

if (!function_exists('resolveUserCompanyId')) {
    function resolveUserCompanyId(mixed $user): ?int
    {
        if ($user instanceof SaasUser) {
            return (int) $user->company_id;
        }
        if ($user instanceof User) {
            $staff = Staff::where('user_id', $user->id)->first();
            return $staff ? (int) $staff->company_id : null;
        }
        return null;
    }
}

Broadcast::channel('support.company.{companyId}', function (mixed $user, int $companyId): bool {
    return resolveUserCompanyId($user) === $companyId;
});

Broadcast::channel('notifications.company.{companyId}', function (mixed $user, int $companyId): bool {
    return resolveUserCompanyId($user) === $companyId;
});

Broadcast::channel('support.ticket.{ticketId}', function (mixed $user, int $ticketId): bool {
    $ticket = SupportTicket::query()->select(['id', 'company_id', 'customer_id'])->find($ticketId);

    if (!$ticket) {
        return false;
    }

    $userCompanyId = resolveUserCompanyId($user);
    if ($userCompanyId !== null) {
        return $userCompanyId === (int) $ticket->company_id;
    }

    if ($user instanceof Customer) {
        return (int) $user->company_id === (int) $ticket->company_id
            && (int) $user->id === (int) $ticket->customer_id;
    }

    return false;
});
