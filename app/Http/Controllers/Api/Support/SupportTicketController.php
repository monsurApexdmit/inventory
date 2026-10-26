<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportTicketController extends Controller
{
    use ApiResponse;

    private const SUPPORT_ATTACHMENT_RULES = [
        'attachments' => 'sometimes|array|max:5',
        'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,xls,xlsx,doc,docx,webm,mp3,mp4,m4a,wav,ogg',
    ];

    public function __construct(private readonly SupportTicketService $service) {}

    /** Tenant comes from the verified token; only a super admin (no company of their own) may pick one via ?company_id. */
    private function companyId(Request $request): int
    {
        if ($request->attributes->get('auth_is_super_admin')) {
            return (int) $request->query('company_id');
        }

        return (int) $request->attributes->get('auth_company_id');
    }

    // GET /api/support/tickets
    public function index(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        $filters   = $request->only(['status', 'priority', 'category', 'search', 'per_page', 'unread']);

        $result = $this->service->list($companyId, $filters);

        return $this->success($result['data'], 'Tickets retrieved', 200, [
            'total'       => $result['total'],
            'perPage'     => $result['per_page'],
            'currentPage' => $result['current_page'],
            'lastPage'    => $result['last_page'],
        ]);
    }

    // POST /api/support/tickets  (staff creates a ticket on behalf of a customer)
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name'  => 'required|string|max:150',
            'subject'        => 'required|string|max:255',
            'category'       => 'nullable|in:order,product,payment,shipping,general',
            'priority'       => 'nullable|in:low,medium,high',
            'description'    => 'nullable|string|max:5000',
            'customer_id'    => 'nullable|integer',
            'customer_email' => 'nullable|email|max:255',
        ]);

        try {
            $companyId = $this->companyId($request);
            $ticket = $this->service->createByStaff($companyId, $data);
            return $this->success($ticket, 'Ticket created', 201);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    // GET /api/support/tickets/stats
    public function stats(Request $request): JsonResponse
    {
        $companyId = $this->companyId($request);
        return $this->success($this->service->stats($companyId), 'Stats retrieved');
    }

    // GET /api/support/tickets/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $companyId = $this->companyId($request);
            $ticket    = $this->service->open($id, (int) $companyId);
            return $this->success($ticket, 'Ticket retrieved');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    // POST /api/support/tickets/{id}/reply
    public function reply(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'body' => 'nullable|string|max:5000|required_without:attachments',
        ] + self::SUPPORT_ATTACHMENT_RULES);

        try {
            $companyId = $this->companyId($request);
            $staffName = $request->user()?->full_name ?? 'Support Team';

            $ticket = $this->service->reply(
                id:         $id,
                companyId:  $companyId,
                body:       $request->input('body'),
                senderType: 'staff',
                customerId: null,
                senderName: $staffName,
                attachments: $request->file('attachments', []),
            );
            return $this->success($ticket, 'Reply sent');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    // PATCH /api/support/tickets/{id}/status
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:open,in_progress,resolved,closed']);

        try {
            $companyId = $this->companyId($request);
            $ticket    = $this->service->updateStatus($id, $companyId, $request->status);
            return $this->success($ticket, 'Status updated');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    // PATCH /api/support/tickets/{id}/priority
    public function updatePriority(Request $request, int $id): JsonResponse
    {
        $request->validate(['priority' => 'required|in:low,medium,high']);

        try {
            $companyId = $this->companyId($request);
            $ticket    = $this->service->updatePriority($id, $companyId, $request->priority);
            return $this->success($ticket, 'Priority updated');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }

    // DELETE /api/support/tickets/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $companyId = $this->companyId($request);
            $this->service->delete($id, $companyId);
            return $this->success(null, 'Ticket deleted');
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getStatusCode());
        }
    }
}
