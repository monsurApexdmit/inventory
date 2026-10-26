<?php

namespace App\Http\Controllers\Api\Notification;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Services\Notification\NotificationAlertService;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly NotificationService $notificationService, private readonly NotificationAlertService $alerts)
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
            'read_only'   => $request->query('status') === 'read',
            'sort'        => $request->query('sort') === 'oldest' ? 'oldest' : 'newest',
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
                'counts'       => $result['counts'],
            ],
        ]);
    }

    /** GET /notifications/alerts: live alerts computed from data, minus this user's dismissals. */
    public function alerts(Request $request): JsonResponse
    {
        $result = $this->alerts->list((int) $request->attributes->get('auth_company_id'), (int) $request->attributes->get('auth_user_id'));

        return $this->success($result, 'Alerts retrieved');
    }

    /** POST /notifications/alerts/dismiss  body: { key } */
    public function dismissAlert(Request $request): JsonResponse
    {
        $key = $request->validate(['key' => 'required|string|max:100'])['key'];
        $this->alerts->dismiss((int) $request->attributes->get('auth_company_id'), (int) $request->attributes->get('auth_user_id'), $key);

        return $this->success(null, 'Alert dismissed');
    }

    /** POST /notifications/alerts/restore  body: { key? }: bring back dismissed alerts. */
    public function restoreAlerts(Request $request): JsonResponse
    {
        $key = $request->validate(['key' => 'nullable|string|max:100'])['key'] ?? null;
        $this->alerts->restore((int) $request->attributes->get('auth_company_id'), (int) $request->attributes->get('auth_user_id'), $key);

        return $this->success(null, 'Alerts restored');
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
        if ($request->boolean('all')) {
            $this->notificationService->deleteAll($companyId);
            return $this->success(null, 'Notifications deleted');
        }

        $ids = $request->input('ids', []);

        if (empty($ids) || !is_array($ids)) {
            return $this->error('ids must be a non-empty array', 422);
        }

        $this->notificationService->bulkDelete($companyId, $ids);

        return $this->success(null, 'Notifications deleted');
    }
}
