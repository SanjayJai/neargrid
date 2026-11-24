<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Domains\Notifications\Services\CustomerNotificationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notifications\ListCustomerNotificationsRequest;
use App\Http\Resources\Api\V1\Notifications\CustomerNotificationResource;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CustomerNotificationController extends Controller
{
    public function __construct(
        private readonly CustomerNotificationService $notificationService,
    ) {}

    public function index(ListCustomerNotificationsRequest $request): JsonResponse
    {
        $notifications = $this->notificationService->getCustomerNotifications(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::paginated(
            $notifications,
            CustomerNotificationResource::collection($notifications->items())->resolve($request)
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = $this->notificationService->getUnreadCount($request->user());

        return ApiResponse::success([
            'unread_count' => $count,
        ]);
    }

    public function markAsRead(Request $request, string $notification): JsonResponse
    {
        $updated = $this->notificationService->markAsRead($request->user(), $notification);

        return ApiResponse::success(
            (new CustomerNotificationResource($updated))->resolve($request),
            ['message' => 'Notification marked as read.']
        );
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $count = $this->notificationService->markAllAsRead($request->user());

        return ApiResponse::success(
            ['marked_count' => $count],
            ['message' => 'All unread notifications marked as read.']
        );
    }

    public function destroy(Request $request, string $notification): JsonResponse
    {
        $this->notificationService->softDelete($request->user(), $notification);

        return ApiResponse::success(
            null,
            ['message' => 'Notification deleted successfully.']
        );
    }
}
