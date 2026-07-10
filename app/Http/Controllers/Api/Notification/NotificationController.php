<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    /**
     * GET /notifications
     * Returns: { success, message, data: NotificationResponse[], meta: { total, perPage, currentPage, lastPage, unreadCount } }
     */
    public function index(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');

        $filters = [
            'unread_only' => $request->query('status') === 'unread',
            'type'        => $request->query('type'),
            'per_page'    => (int) $request->query('limit', $request->query('per_page', 20)),
            'page'        => (int) $request->query('page', 1),
        ];

        $result = $this->notificationService->list($companyId, $filters);

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully',
            'data'    => $result['data'],
            'meta'    => [
                'total'        => $result['total'],
                'perPage'      => $result['per_page'],
                'currentPage'  => $result['current_page'],
                'lastPage'     => $result['last_page'],
                'unreadCount'  => $result['unreadCount'],
            ],
        ]);
    }

    /**
     * GET /notifications/unread-count
     * Returns: { success, message, data: { count: number } }
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $count = $this->notificationService->countUnread($companyId);

        return response()->json([
            'success' => true,
            'message' => 'Unread count retrieved',
            'data'    => ['count' => $count],
        ]);
    }

    /**
     * PATCH /notifications/{id}/read
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->notificationService->markAsRead($id, $companyId);

        return $this->success(null, 'Notification marked as read');
    }

    /**
     * PATCH /notifications/{id}/unread
     */
    public function markAsUnread(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->notificationService->markAsUnread($id, $companyId);

        return $this->success(null, 'Notification marked as unread');
    }

    /**
     * PATCH /notifications/read-all  (frontend uses PATCH)
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->notificationService->markAllAsRead($companyId);

        return $this->success(null, 'All notifications marked as read');
    }

    /**
     * DELETE /notifications/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $this->notificationService->delete($id, $companyId);

        return $this->success(null, 'Notification deleted');
    }

    /**
     * DELETE /notifications/bulk  — body: { ids: number[] }
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('auth_company_id');
        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return $this->error('ids must be a non-empty array', 422);
        }

        $this->notificationService->bulkDelete($companyId, $ids);

        return $this->success(null, 'Notifications deleted');
    }
}
