<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StorefrontSupportController extends Controller
{
    private const SUPPORT_ATTACHMENT_RULES = [
        'attachments' => 'sometimes|array|max:5',
        'attachments.*' => 'file|max:10240|mimes:jpg,jpeg,png,webp,gif,pdf,txt,csv,xls,xlsx,doc,docx,webm,mp3,mp4,m4a,wav,ogg',
    ];

    public function __construct(private readonly SupportTicketService $service) {}

    // POST /api/store/contact
    public function contact(Request $request): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        if ($companyId <= 0) {
            return response()->json(['success' => false, 'message' => 'company_id is required'], 400);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'nullable|string|max:5000|required_without:attachments',
            'category' => 'sometimes|in:order,product,payment,shipping,general',
            'priority' => 'sometimes|in:low,medium,high',
        ] + self::SUPPORT_ATTACHMENT_RULES);

        $result = $this->service->createGuestContact($companyId, [
            'subject' => $request->subject,
            'message' => $request->message,
            'category' => $request->category ?? 'general',
            'priority' => $request->priority ?? 'medium',
            'customer_name' => $request->name,
            'customer_email' => $request->email,
            'attachments' => $request->file('attachments', []),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => $result['ticket'],
            'meta' => [
                'guestAccessToken' => $result['guestAccessToken'],
            ],
        ], 201);
    }

    // GET /api/store/support/guest/{ticketNumber}
    public function showGuest(Request $request, string $ticketNumber): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $token = (string) $request->query('token', '');

        if ($companyId <= 0 || $token === '') {
            return response()->json(['success' => false, 'message' => 'company_id and token are required'], 400);
        }

        try {
            $ticket = $this->service->showForGuest($ticketNumber, $token, $companyId);
            return response()->json(['success' => true, 'data' => $ticket]);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // POST /api/store/support/guest/{ticketNumber}/reply
    public function replyGuest(Request $request, string $ticketNumber): JsonResponse
    {
        $companyId = (int) $request->query('company_id');
        $token = (string) $request->query('token', '');

        if ($companyId <= 0 || $token === '') {
            return response()->json(['success' => false, 'message' => 'company_id and token are required'], 400);
        }

        $request->validate([
            'body' => 'nullable|string|max:5000|required_without:attachments',
        ] + self::SUPPORT_ATTACHMENT_RULES);

        try {
            $ticket = $this->service->replyForGuest($ticketNumber, $token, $companyId, $request->input('body'), $request->file('attachments', []));
            return response()->json(['success' => true, 'data' => $ticket]);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // GET /api/store/support/tickets
    public function index(Request $request): JsonResponse
    {
        $customer  = $request->attributes->get('storefront_customer');
        $filters   = $request->only(['status', 'per_page']);
        $result    = $this->service->listForCustomer($customer->id, $customer->company_id, $filters);

        return response()->json([
            'success' => true,
            'data'    => $result['data'],
            'meta'    => [
                'total'       => $result['total'],
                'perPage'     => $result['per_page'],
                'currentPage' => $result['current_page'],
                'lastPage'    => $result['last_page'],
            ],
        ]);
    }

    // GET /api/store/support/tickets/{id}
    public function show(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        try {
            $ticket = $this->service->showForCustomer($id, $customer->id, $customer->company_id);
            return response()->json(['success' => true, 'data' => $ticket]);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    // POST /api/store/support/tickets
    public function store(Request $request): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');

        $request->validate([
            'subject'  => 'required|string|max:255',
            'message'  => 'nullable|string|max:5000|required_without:attachments',
            'category' => 'sometimes|in:order,product,payment,shipping,general',
            'priority' => 'sometimes|in:low,medium,high',
        ] + self::SUPPORT_ATTACHMENT_RULES);

        $ticket = $this->service->create($customer->company_id, [
            'subject'        => $request->subject,
            'message'        => $request->message,
            'category'       => $request->category ?? 'general',
            'priority'       => $request->priority ?? 'medium',
            'customer_name'  => $customer->name,
            'customer_email' => $customer->email,
            'attachments'    => $request->file('attachments', []),
        ], $customer->id);

        return response()->json(['success' => true, 'data' => $ticket], 201);
    }

    // POST /api/store/support/tickets/{id}/reply
    public function reply(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');
        $request->validate([
            'body' => 'nullable|string|max:5000|required_without:attachments',
        ] + self::SUPPORT_ATTACHMENT_RULES);

        try {
            $ticket = $this->service->reply(
                id:         $id,
                companyId:  $customer->company_id,
                body:       $request->input('body'),
                senderType: 'customer',
                customerId: $customer->id,
                senderName: $customer->name,
                attachments: $request->file('attachments', []),
            );

            // Verify ticket belongs to this customer
            if ($ticket['customerId'] !== $customer->id) {
                return response()->json(['success' => false, 'message' => 'Not found'], 404);
            }

            return response()->json(['success' => true, 'data' => $ticket]);
        } catch (HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        }
    }
}
