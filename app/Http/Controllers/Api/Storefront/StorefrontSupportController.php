<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StorefrontSupportController extends Controller
{
    public function __construct(private readonly SupportTicketService $service) {}

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
            'message'  => 'required|string|max:5000',
            'category' => 'sometimes|in:order,product,payment,shipping,general',
            'priority' => 'sometimes|in:low,medium,high',
        ]);

        $ticket = $this->service->create($customer->company_id, [
            'subject'        => $request->subject,
            'message'        => $request->message,
            'category'       => $request->category ?? 'general',
            'priority'       => $request->priority ?? 'medium',
            'customer_name'  => $customer->name,
            'customer_email' => $customer->email,
        ], $customer->id);

        return response()->json(['success' => true, 'data' => $ticket], 201);
    }

    // POST /api/store/support/tickets/{id}/reply
    public function reply(Request $request, int $id): JsonResponse
    {
        $customer = $request->attributes->get('storefront_customer');
        $request->validate(['body' => 'required|string|max:5000']);

        try {
            $ticket = $this->service->reply(
                id:         $id,
                companyId:  $customer->company_id,
                body:       $request->body,
                senderType: 'customer',
                customerId: $customer->id,
                senderName: $customer->name,
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
